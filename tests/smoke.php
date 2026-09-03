<?php
/**
 * Smoke test Free: ładuje wtyczkę na atrapach funkcji WordPressa z każdym modułem włączonym
 * i sprawdza brak fatal errorów, literówek w nazwach metod i zarejestrowane hooki.
 * Uruchom: php tests/smoke.php
 */
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
$wp_version = '6.9';
$GLOBALS['ewps_test_options'] = array();
$GLOBALS['ewps_hooks']        = array();

function add_action( $h, $cb, $p = 10, $a = 1 )  { $GLOBALS['ewps_hooks'][ $h ][] = $cb; return true; }
function add_filter( $h, $cb, $p = 10, $a = 1 )  { $GLOBALS['ewps_hooks'][ $h ][] = $cb; return true; }
function remove_action( $h, $cb, $p = 10 )       { return true; }
function remove_filter( $h, $cb, $p = 10 )       { return true; }
function apply_filters( $h, $v )                 { return $v; }
function do_action( $h )                         {}
function add_shortcode( $t, $cb )                {}
function register_activation_hook( $f, $cb )     {}
function register_deactivation_hook( $f, $cb )   {}
function get_option( $k, $d = false )            { return array_key_exists( $k, $GLOBALS['ewps_test_options'] ) ? $GLOBALS['ewps_test_options'][ $k ] : $d; }
function update_option( $k, $v, $a = null )      { $GLOBALS['ewps_test_options'][ $k ] = $v; return true; }
function delete_site_transient( $k )             { return true; }
function wp_parse_args( $a, $d = array() )       { return array_merge( $d, is_array( $a ) ? $a : array() ); }
function plugin_dir_path( $f )                   { return dirname( $f ) . '/'; }
function plugin_dir_url( $f )                    { return 'http://example.test/wp-content/plugins/essa-wp-suite/'; }
function plugin_basename( $f )                   { return 'essa-wp-suite/essa-wp-suite.php'; }
function is_admin()                              { return true; }
function is_multisite()                          { return false; }
function __( $s, $d = null )                     { return $s; }
function _n( $s, $p, $n, $d = null )             { return $n == 1 ? $s : $p; }
function esc_html__( $s, $d = null )             { return $s; }
function esc_html( $s )                          { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s )                          { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s, $p = null )                { return $s; }
function untrailingslashit( $s )                 { return rtrim( $s, '/' ); }
function trailingslashit( $s )                   { return rtrim( $s, '/' ) . '/'; }
function sanitize_title( $s )                    { return strtolower( preg_replace( '/[^a-z0-9\-]+/i', '-', trim( (string) $s ) ) ); }
function sanitize_key( $s )                      { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function sanitize_text_field( $s )               { return trim( strip_tags( (string) $s ) ); }
function wp_unslash( $s )                        { return $s; }
function home_url( $p = '', $s = null )          { return 'http://example.test' . $p; }
function admin_url( $p = '' )                    { return 'http://example.test/wp-admin/' . $p; }
function add_query_arg( $a, $u = '' )            { return $u . '?' . http_build_query( is_array( $a ) ? $a : array() ); }
function wp_parse_url( $u, $c = -1 )             { return parse_url( $u, $c ); }
function wp_json_encode( $v )                    { return json_encode( $v ); }
function sanitize_html_class( $s )               { return preg_replace( '/[^A-Za-z0-9_-]/', '', $s ); }
function __return_true()                         { return true; }
function __return_false()                        { return false; }
function __return_empty_string()                 { return ''; }

$modules = array(
    'ewps_enc_settings'     => 'enc_enabled',
    'eee_dc_settings'       => 'dc_enabled',
    'ewps_xmlrpc_settings'  => 'xmlrpc_enabled',
    'ewps_maint_settings'   => 'maint_enabled',
    'ewps_hard_settings'    => 'hard_enabled',
    'ewps_notices_settings' => 'notices_enabled',
);
foreach ( $modules as $opt => $key ) $GLOBALS['ewps_test_options'][ $opt ] = array( $key => '1' );

require __DIR__ . '/../essa-wp-suite/essa-wp-suite.php';

$fails = 0;
function check( $name, $cond ) { global $fails; echo ( $cond ? '  OK   ' : '  FAIL ' ) . $name . "\n"; if ( ! $cond ) $fails++; }

$hooks = $GLOBALS['ewps_hooks'];
check( 'klasa główna istnieje',                class_exists( 'ESSA_WP_Suite' ) );
check( 'ESSA_Suite_Utils załadowane',          class_exists( 'ESSA_Suite_Utils' ) );
check( 'ESSA_License załadowane',              class_exists( 'ESSA_License' ) );
check( 'encoder: filtr the_content',           isset( $hooks['the_content'] ) );
check( 'comments: plugins_loaded',             isset( $hooks['plugins_loaded'] ) );
check( 'xmlrpc: xmlrpc_enabled',               isset( $hooks['xmlrpc_enabled'] ) );
check( 'maintenance: template_redirect',       isset( $hooks['template_redirect'] ) );
check( 'hardening: send_headers',              isset( $hooks['send_headers'] ) );
check( 'notices: admin_footer',                isset( $hooks['admin_footer'] ) );
check( 'updater: transient aktualizacji',      isset( $hooks['pre_set_site_transient_update_plugins'] ) );
check( 'updater: plugins_api',                 isset( $hooks['plugins_api'] ) );
check( 'license: admin_init',                  isset( $hooks['admin_init'] ) );

$suite = ESSA_WP_Suite::get_instance();
$reg   = $suite->get_modules();
check( 'rejestr: 13 modułów (6 free + 7 katalog Pro)', count( $reg ) === 13 );
$locked = array_filter( $reg, function( $m ) { return ! empty( $m['locked'] ); } );
check( 'rejestr: 7 zablokowanych bez Pro',     count( $locked ) === 7 );
check( 'rejestr: encoder ma group/page',        ! empty( $reg['encoder']['group'] ) );
check( 'pro_catalog: 7 pozycji',                count( ESSA_WP_Suite::pro_catalog() ) === 7 );
check( 'licencja: brak klucza → nieważna',      ! ESSA_License::get_instance()->is_valid() );
check( 'licencja: domena bez www',              ESSA_License::domain() === 'example.test' );

foreach ( array(
    array( 'ESSA_Updater', 'force_check_link' ),
    array( 'ESSA_License', 'render_tab' ),
    array( 'ESSA_License', 'install_pro' ),
    array( 'ESSA_Disable_Comments', 'delete_comments_tool_html' ),
    array( 'ESSA_WP_Suite', 'render_settings_form' ),
    array( 'ESSA_WP_Suite', 'sidebar_tip' ),
) as $cb ) {
    check( 'istnieje ' . implode( '::', $cb ), method_exists( $cb[0], $cb[1] ) );
}

$bad = array();
foreach ( $hooks as $h => $cbs ) foreach ( $cbs as $cb ) if ( ! is_callable( $cb ) ) $bad[] = $h;
check( 'wszystkie callbacki hooków wywoływalne' . ( $bad ? ' (' . implode( ', ', array_unique( $bad ) ) . ')' : '' ), empty( $bad ) );

check( 'ip_in_list: CIDR',            ESSA_Suite_Utils::ip_in_list( '192.168.1.77', "10.0.0.0/8\n192.168.1.0/24" ) );
check( 'ip_in_list: poza CIDR',       ! ESSA_Suite_Utils::ip_in_list( '192.168.2.1', '192.168.1.0/24' ) );
$_SERVER['REMOTE_ADDR'] = '9.9.9.9'; $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.1.1.1, 2.2.2.2';
check( 'client_ip bez proxy = REMOTE_ADDR', ESSA_Suite_Utils::client_ip( false ) === '9.9.9.9' );
check( 'client_ip z proxy = pierwszy XFF',  ESSA_Suite_Utils::client_ip( true ) === '1.1.1.1' );
$_SERVER['REQUEST_URI'] = '/blog/login-tips/';
check( 'request_path nie myli /blog/login-tips z /login', ESSA_Suite_Utils::request_path() !== 'login' );

echo "\n" . ( $fails ? "$fails FAIL" : 'ALL OK' ) . "\n";
exit( $fails ? 1 : 0 );
