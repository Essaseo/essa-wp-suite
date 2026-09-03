<?php
/**
 * Test na żywym WP przez WP-CLI, bez aktywacji:
 *   wp --skip-plugins=essa-wp-suite eval-file ~/tmp/ewps/ewps_live_test.php
 * Ładuje NOWĄ wersję z ~/tmp/ewps/essa-wp-suite/, rejestruje ustawienia,
 * renderuje każdą zakładkę panelu i przepuszcza treść przez Email Encoder.
 */
error_reporting( E_ALL );
ini_set( 'display_errors', 1 );
$errors = array();
set_error_handler( function ( $no, $str, $file, $line ) use ( &$errors ) {
    $errors[] = "$str @ " . basename( $file ) . ":$line";
    return true;
} );

$dir = getenv( 'HOME' ) . '/tmp/ewps/essa-wp-suite/';
$pro = getenv( 'HOME' ) . '/tmp/ewps/essa-wp-suite-pro/essa-wp-suite-pro.php';
// Udawana ważna licencja — bez zapisu do bazy (pre_option)
add_filter( 'pre_option_ewps_license', function() { return array( 'key' => 'EWPS-TEST-TEST-TEST-TEST', 'status' => 'active', 'expires' => '2099-01-01', 'checked' => time(), 'last_try' => time(), 'domain' => 'essaseo.pl' ); } );
require_once $dir . 'essa-wp-suite.php';
if ( file_exists( $pro ) ) { require_once $pro; ESSA_WP_Suite_Pro::get_instance()->boot(); }
echo "PHP " . PHP_VERSION . ", WP " . get_bloginfo( 'version' ) . ", EWPS " . EWPS_VERSION . "\n";

wp_set_current_user( 1 );
if ( ! defined( 'WP_ADMIN' ) ) define( 'WP_ADMIN', true );
require_once ABSPATH . 'wp-admin/includes/admin.php';

$suite = ESSA_WP_Suite::get_instance();
$suite->maybe_upgrade();
$suite->admin_init();
do_action( 'admin_menu' );
echo "admin_init OK, tabela logów: " . ( ESSA_Activity_Log::table_exists() ? 'jest' : 'BRAK' ) . "\n";

$tabs = array( 'dashboard', 'license', 'encoder', 'comments', 'login', 'xmlrpc', 'maintenance', 'hardening', 'notices', 'smtp', 'db', 'admin_cleaner', 'auto_updates', 'white_label', 'activity' );
foreach ( $tabs as $tab ) {
    $_GET['tab'] = $tab;
    $before = count( $errors );
    ob_start();
    $suite->render_settings_page();
    $html = ob_get_clean();
    $new  = array_slice( $errors, $before );
    printf( "  %-14s %6d B  %s\n", $tab, strlen( $html ), $new ? 'BŁĘDY: ' . implode( ' | ', $new ) : 'ok' );
}

// Encoder na żywo (filtr the_content) — czy zakodowany, czy script nietknięty
$in  = '<p>Napisz: biuro@essaseo.pl</p><script type="application/ld+json">{"email":"x@y.pl"}</script>';
$out = apply_filters( 'the_content', $in );
echo "encoder: " . ( strpos( $out, 'biuro@' ) === false ? 'zakodowany' : 'NIE zakodowany' )
   . ", script: " . ( strpos( $out, '"x@y.pl"' ) !== false ? 'nietknięty' : 'USZKODZONY' ) . "\n";

// Sanitizery na pustym wejściu (WP potrafi wywołać z null)
foreach ( array_filter( array(
    array( 'ESSA_Login_Security', 'sanitize' ), array( 'ESSA_SMTP', 'sanitize' ), array( 'ESSA_DB_Cleaner', 'sanitize' ),
    array( 'ESSA_Maintenance', 'sanitize' ), array( 'ESSA_Auto_Updates', 'sanitize' ), array( 'ESSA_White_Label', 'sanitize' ),
    array( 'ESSA_Activity_Log', 'sanitize' ), array( 'ESSA_Admin_Notices', 'sanitize' ), array( 'ESSA_Admin_Cleaner', 'sanitize' ),
    array( 'ESSA_Disable_Comments', 'sanitize' ), array( 'ESSA_WP_Hardening', 'sanitize' ), array( 'ESSA_Disable_XMLRPC', 'sanitize' ),
), function( $cb ) { return class_exists( $cb[0] ); } ) as $cb ) {
    $before = count( $errors );
    $r = call_user_func( array( $cb[0]::get_instance(), $cb[1] ), null );
    $new = array_slice( $errors, $before );
    echo "  sanitize " . $cb[0] . ": " . ( is_array( $r ) ? count( $r ) . ' kluczy' : 'NIE-TABLICA' ) . ( $new ? ' BŁĘDY: ' . implode( ' | ', $new ) : '' ) . "\n";
}

echo $errors ? "\nRAZEM BŁĘDÓW/OSTRZEŻEŃ: " . count( $errors ) . "\n" : "\nZERO ostrzeżeń PHP\n";
