<?php
/**
 * ESSA WP Suite — Disable XML-RPC
 *
 * Funkcje:
 *  - Całkowite wyłączenie XML-RPC (filtr xmlrpc_enabled)
 *  - Usunięcie nagłówka X-Pingback
 *  - Usunięcie linku RSD i wlwmanifest z <head>
 *  - Blokada endpointu /xmlrpc.php na poziomie PHP (404)
 *
 * PHP 7.2+
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'ESSA_Disable_XMLRPC' ) ) :

class ESSA_Disable_XMLRPC {

    private static $instance = null;

    protected $defaults = array(
        'xmlrpc_enabled'        => '0',    // '0' = wyłącz XML-RPC
        'xmlrpc_remove_header'  => '1',    // usuń X-Pingback
        'xmlrpc_remove_links'   => '1',    // usuń RSD + wlwmanifest z head
        'xmlrpc_block_endpoint' => '1',    // blokuj /xmlrpc.php → 403
    );

    protected $opts = array();

    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $this->opts = wp_parse_args( get_option( 'ewps_xmlrpc_settings', array() ), $this->defaults );
        if ( ! $this->opt( 'xmlrpc_enabled' ) ) return;
        $this->init();
    }

    private function opt( $k ) {
        return isset( $this->opts[ $k ] ) ? $this->opts[ $k ] : ( isset( $this->defaults[ $k ] ) ? $this->defaults[ $k ] : '' );
    }

    private function init() {
        // Wyłącz XML-RPC całkowicie
        add_filter( 'xmlrpc_enabled', '__return_false' );

        // Blokuj endpoint na poziomie PHP (zanim WP go załaduje)
        if ( $this->opt( 'xmlrpc_block_endpoint' ) ) {
            add_action( 'init', array( $this, 'block_xmlrpc_endpoint' ), 1 );
        }

        // Usuń X-Pingback
        if ( $this->opt( 'xmlrpc_remove_header' ) ) {
            add_filter( 'wp_headers', array( $this, 'remove_x_pingback' ) );
        }

        // Usuń linki z <head>
        if ( $this->opt( 'xmlrpc_remove_links' ) ) {
            remove_action( 'wp_head', 'rsd_link' );
            remove_action( 'wp_head', 'wlwmanifest_link' );
        }

        // Wyłącz pingbacki
        add_filter( 'xmlrpc_methods', array( $this, 'remove_pingback_methods' ) );
    }

    public function block_xmlrpc_endpoint() {
        if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
            status_header( 403 );
            nocache_headers();
            header( 'Content-Type: text/plain; charset=utf-8' );
            exit( 'XML-RPC is disabled on this site.' );
        }
    }

    public function remove_x_pingback( $headers ) {
        unset( $headers['X-Pingback'] );
        return $headers;
    }

    public function remove_pingback_methods( $methods ) {
        unset( $methods['pingback.ping'] );
        unset( $methods['pingback.extensions.getPingbacks'] );
        return $methods;
    }

    // ─── Settings ────────────────────────────────────────────────────────────

    public function register_settings() {
        register_setting( 'ewps_xmlrpc_settings_group', 'ewps_xmlrpc_settings', array( $this, 'sanitize' ) );
        add_settings_section( 'ewps_xmlrpc_main', '', '__return_false', 'ewps-xmlrpc-settings' );

        $fields = array(
            'xmlrpc_enabled'        => __( 'Enable module (disable XML-RPC)', 'essa-wp-suite' ),
            'xmlrpc_remove_header'  => __( 'Remove the X-Pingback header', 'essa-wp-suite' ),
            'xmlrpc_remove_links'   => __( 'Remove RSD and wlwmanifest from &lt;head&gt;', 'essa-wp-suite' ),
            'xmlrpc_block_endpoint' => __( 'Block /xmlrpc.php (return 403)', 'essa-wp-suite' ),
        );

        foreach ( $fields as $id => $label ) {
            add_settings_field( 'ewps_' . $id, $label, array( $this, 'field_checkbox' ), 'ewps-xmlrpc-settings', 'ewps_xmlrpc_main', array( 'id' => $id ) );
        }
    }

    public function sanitize( $in ) {
        $in = is_array( $in ) ? $in : array();
        $c  = array();
        foreach ( array_keys( $this->defaults ) as $k ) {
            $c[ $k ] = ! empty( $in[ $k ] ) ? '1' : '0';
        }
        return $c;
    }

    public function field_checkbox( $a ) {
        $id = $a['id'];
        printf( '<input type="checkbox" id="ewps_%1$s" name="ewps_xmlrpc_settings[%1$s]" value="1" %2$s>',
            esc_attr( $id ), checked( '1', $this->opt( $id ), false ) );
    }
}

endif;
