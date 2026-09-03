<?php
/**
 * E2E na żywym WP (po wdrożeniu Free 2.0.0):
 *   EWPS_KEY=EWPS-... wp eval-file ~/tmp/ewps/ewps_e2e.php
 * 1. aktywacja klucza na serwerze licencji, 2. instalacja Pro z serwera, 3. boot Pro, 4. render zakładek,
 * 5. zamówienie testowe WooCommerce → klucz z mostu, 6. widoczność aktualizacji Pro.
 */
error_reporting( E_ALL );
$errors = array();
set_error_handler( function ( $no, $str, $file, $line ) use ( &$errors ) { $errors[] = "$str @ " . basename( $file ) . ":$line"; return true; } );
$key = getenv( 'EWPS_KEY' );
if ( ! $key ) { echo "Brak EWPS_KEY\n"; exit( 1 ); }

wp_set_current_user( 1 );
if ( ! defined( 'WP_ADMIN' ) ) define( 'WP_ADMIN', true );
require_once ABSPATH . 'wp-admin/includes/admin.php';

echo "Free " . EWPS_VERSION . ", serwer: " . ESSA_License::server_url() . ", domena: " . ESSA_License::domain() . "\n";
$lic = ESSA_License::get_instance();

// 1. aktywacja
$ok = $lic->activate( $key );
echo "1. aktywacja: " . ( $ok ? 'OK' : 'BŁĄD' ) . " — " . $lic->status_label() . " (" . $lic->get( 'message' ) . "), strony " . $lic->get( 'sites_used' ) . "/" . $lic->get( 'sites_max' ) . ", ważna do " . $lic->get( 'expires' ) . "\n";
if ( ! $ok ) exit( 1 );

// 2. instalacja Pro
$r = $lic->install_pro();
echo "2. install_pro: " . ( is_wp_error( $r ) ? 'BŁĄD ' . $r->get_error_message() : 'OK' ) . "\n";
echo "   plik: " . ( ESSA_License::pro_installed() ? 'jest' : 'BRAK' ) . ", aktywna: " . ( is_plugin_active( ESSA_License::PRO_BASENAME ) ? 'tak' : 'nie' ) . "\n";

// 3. boot Pro w tym procesie (wtyczka została aktywowana po załadowaniu WP)
if ( ! class_exists( 'ESSA_WP_Suite_Pro' ) && file_exists( WP_PLUGIN_DIR . '/' . ESSA_License::PRO_BASENAME ) ) {
    require_once WP_PLUGIN_DIR . '/' . ESSA_License::PRO_BASENAME;
    ESSA_WP_Suite_Pro::get_instance()->boot();
}
$booted = class_exists( 'ESSA_WP_Suite_Pro' ) && ESSA_WP_Suite_Pro::get_instance()->is_booted();
echo "3. Pro uruchomione: " . ( $booted ? 'TAK (' . EWPS_PRO_VERSION . ')' : 'NIE' ) . "\n";

// 4. zakładki
$suite = ESSA_WP_Suite::get_instance();
$suite->maybe_upgrade();
if ( $booted ) ESSA_WP_Suite_Pro::get_instance()->maybe_upgrade();
$suite->admin_init();
do_action( 'admin_menu' );
$modules = $suite->get_modules();
$locked  = array_filter( $modules, function( $m ) { return ! empty( $m['locked'] ); } );
echo "4. moduły: " . count( $modules ) . ", zablokowane: " . count( $locked ) . "\n";
foreach ( array( 'dashboard', 'license', 'login', 'activity', 'smtp' ) as $tab ) {
    $_GET['tab'] = $tab; $b = count( $errors ); ob_start(); $suite->render_settings_page(); $html = ob_get_clean();
    $new = array_slice( $errors, $b );
    printf( "   %-10s %6d B %s%s\n", $tab, strlen( $html ), $new ? 'BŁĘDY: ' . implode( ' | ', $new ) : 'ok', strpos( $html, 'Wymaga Pro' ) !== false ? ' (widać "Wymaga Pro"!)' : '' );
}
echo "   log aktywności: " . ( ESSA_Activity_Log::table_exists() ? ESSA_Activity_Log::get_instance()->get_total_count() . ' wpisów' : 'BRAK TABELI' ) . ", cron logów: " . ( wp_next_scheduled( 'ewps_daily_log_cleanup' ) ? 'zaplanowany' : 'brak' ) . "\n";

// 5. zamówienie testowe WooCommerce
$product_id = 15030;
if ( function_exists( 'wc_create_order' ) && get_post( $product_id ) ) {
    $order = wc_create_order( array( 'customer_id' => 0 ) );
    $order->add_product( wc_get_product( $product_id ), 1 );
    $order->set_billing_email( 'test-licencja@essaseo.pl' );
    $order->set_billing_first_name( 'Test' );
    $order->set_billing_last_name( 'Licencji' );
    $order->set_payment_method( 'bacs' );
    $order->calculate_totals();
    $order->update_status( 'completed', 'E2E test ESSA WP Suite Pro' );
    $order = wc_get_order( $order->get_id() );
    $issued = ESSA_License_Bridge::order_keys( $order );
    echo "5. zamówienie #" . $order->get_order_number() . " (" . $order->get_total() . " " . $order->get_currency() . "): klucz z mostu: " . ( $issued ? $issued[0]['key'] : 'BRAK' ) . "\n";
    $notes = wc_get_order_notes( array( 'order_id' => $order->get_id(), 'type' => 'internal' ) );
    foreach ( $notes as $n ) if ( strpos( $n->content, 'ESSA License' ) !== false ) echo "   notatka: " . $n->content . "\n";
    // mail HTML — czy klucz wchodzi do szablonu
    ob_start(); ESSA_License_Bridge::email_box( $order, false, false, null ); $mail = ob_get_clean();
    echo "   klucz w mailu: " . ( $issued && strpos( $mail, $issued[0]['key'] ) !== false ? 'tak' : 'NIE' ) . "\n";
    echo "   ORDER_ID=" . $order->get_id() . " TEST_KEY=" . ( $issued ? $issued[0]['key'] : '' ) . "\n";
} else {
    echo "5. WooCommerce/produkt niedostępny\n";
}

// 6. aktualizacje Pro
if ( $booted ) {
    $rel = ESSA_Pro_Updater::get_instance()->get_release( true );
    echo "6. serwer zgłasza Pro: " . ( $rel ? $rel['version'] . ", paczka: " . ( $rel['package'] ? 'jest' : 'BRAK' ) : 'brak wydania' ) . "\n";
    delete_site_transient( 'update_plugins' );
    wp_update_plugins();
    $t = get_site_transient( 'update_plugins' );
    echo "   WP: aktualizacja Pro " . ( isset( $t->response[ EWPS_PRO_BASENAME ] ) ? 'DOSTĘPNA → ' . $t->response[ EWPS_PRO_BASENAME ]->new_version : 'brak (aktualna)' ) . ", Free " . ( isset( $t->response[ EWPS_BASENAME ] ) ? 'DOSTĘPNA → ' . $t->response[ EWPS_BASENAME ]->new_version : 'brak (aktualna)' ) . "\n";
}

echo $errors ? "\nOSTRZEŻENIA PHP: " . count( $errors ) . "\n" . implode( "\n", array_slice( $errors, 0, 10 ) ) . "\n" : "\nZERO ostrzeżeń PHP\n";
