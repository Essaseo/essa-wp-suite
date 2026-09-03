<?php
/**
 * ESSA WP Suite — WP Hardening
 *
 * Ukrywa informacje o WordPressie które ułatwiają atakującym
 * rozpoznanie wersji i celowanie w znane luki:
 *  - Usuwa ?ver=X z URL skryptów/styli
 *  - Usuwa <meta name="generator"> z <head>
 *  - Usuwa wersję WP z RSS
 *  - Usuwa RSD i wlwmanifest linki
 *  - Usuwa emoji scripts/styles (opcjonalnie)
 *  - Usuwa shortlink z head
 *  - Usuwa adjacent_posts linki z head
 *  - Wyłącza xmlrpc_link (jeśli nie wyłączono już w module XMLRPC)
 *  - Dodaje security headers (X-Frame-Options, X-Content-Type-Options, etc.)
 *
 * PHP 7.2+
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'ESSA_WP_Hardening' ) ) :

class ESSA_WP_Hardening {

    private static $instance = null;

    protected $defaults = array(
        'hard_enabled'           => '0',
        'hard_remove_version'    => '1',   // ?ver=X z URL assets
        'hard_remove_generator'  => '1',   // <meta name="generator">
        'hard_remove_rss_ver'    => '1',   // wersja WP z RSS
        'hard_remove_head_links' => '1',   // RSD, wlwmanifest, shortlink, adjacent
        'hard_disable_emoji'     => '1',   // emoji scripts
        'hard_security_headers'  => '1',   // X-Frame-Options, etc.
        'hard_remove_wp_embed'   => '1',   // wp-embed.min.js
    );

    protected $opts = array();

    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $this->opts = wp_parse_args( get_option( 'ewps_hard_settings', array() ), $this->defaults );
        if ( ! $this->opt( 'hard_enabled' ) ) return;
        $this->init();
    }

    private function opt( $k ) {
        return isset( $this->opts[ $k ] ) ? $this->opts[ $k ] : ( isset( $this->defaults[ $k ] ) ? $this->defaults[ $k ] : '' );
    }

    private function init() {
        if ( $this->opt( 'hard_remove_version' ) ) {
            add_filter( 'style_loader_src',  array( $this, 'remove_version_from_url' ), 9999 );
            add_filter( 'script_loader_src', array( $this, 'remove_version_from_url' ), 9999 );
        }

        if ( $this->opt( 'hard_remove_generator' ) ) {
            remove_action( 'wp_head', 'wp_generator' );
            add_filter( 'the_generator', '__return_empty_string' );
        }

        if ( $this->opt( 'hard_remove_rss_ver' ) ) {
            add_filter( 'the_generator', '__return_empty_string' );
        }

        if ( $this->opt( 'hard_remove_head_links' ) ) {
            remove_action( 'wp_head', 'rsd_link' );
            remove_action( 'wp_head', 'wlwmanifest_link' );
            remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
            remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head', 10 );
            remove_action( 'wp_head', 'feed_links_extra', 3 );
        }

        if ( $this->opt( 'hard_disable_emoji' ) ) {
            remove_action( 'wp_head',             'print_emoji_detection_script', 7 );
            remove_action( 'wp_print_styles',     'print_emoji_styles' );
            remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
            remove_action( 'admin_print_styles',  'print_emoji_styles' );
            remove_filter( 'the_content_feed',    'wp_staticize_emoji' );
            remove_filter( 'comment_text_rss',    'wp_staticize_emoji' );
            remove_filter( 'wp_mail',             'wp_staticize_emoji_for_email' );
            add_filter( 'tiny_mce_plugins',       array( $this, 'disable_emoji_tinymce' ) );
            add_filter( 'wp_resource_hints',      array( $this, 'disable_emoji_dns_prefetch' ), 10, 2 );
        }

        if ( $this->opt( 'hard_remove_wp_embed' ) ) {
            add_action( 'wp_footer', array( $this, 'dequeue_wp_embed' ) );
        }

        if ( $this->opt( 'hard_security_headers' ) ) {
            add_action( 'send_headers', array( $this, 'send_security_headers' ) );
            add_action( 'login_init',   array( $this, 'send_security_headers' ) );
        }
    }

    public function remove_version_from_url( $src ) {
        if ( ! is_string( $src ) ) return $src;
        // Usuń ?ver=X i &ver=X i &amp;ver=X
        $src = preg_replace( '/(\?|&|&amp;)ver=[^&\s"\']+/', '', $src );
        return $src;
    }

    public function disable_emoji_tinymce( $plugins ) {
        return is_array( $plugins ) ? array_diff( $plugins, array( 'wpemoji' ) ) : $plugins;
    }

    public function disable_emoji_dns_prefetch( $urls, $relation_type ) {
        if ( 'dns-prefetch' !== $relation_type ) return $urls;
        return array_filter( $urls, function( $url ) {
            return strpos( $url, 'twimg.com' ) === false && strpos( $url, 'emoji' ) === false;
        } );
    }

    public function dequeue_wp_embed() {
        wp_deregister_script( 'wp-embed' );
    }

    public function send_security_headers() {
        if ( headers_sent() ) return;
        // X-XSS-Protection celowo pominięte: przeglądarki go porzuciły, a w starych
        // wersjach włączony filtr sam bywał wektorem ataku.
        $headers = apply_filters( 'ewps_security_headers', array(
            'X-Frame-Options'        => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy'        => 'strict-origin-when-cross-origin',
            'Permissions-Policy'     => 'geolocation=(), microphone=(), camera=()',
        ) );
        foreach ( $headers as $name => $value ) {
            if ( '' !== $value ) header( $name . ': ' . $value );
        }
    }

    // ─── Public getters (do testów) ──────────────────────────────────────────

    public function get_clean_url_result( $src ) {
        return $this->remove_version_from_url( $src );
    }

    // ─── Settings ────────────────────────────────────────────────────────────

    public function register_settings() {
        register_setting( 'ewps_hard_settings_group', 'ewps_hard_settings', array( $this, 'sanitize' ) );
        add_settings_section( 'ewps_hard_main', '', '__return_false', 'ewps-hard-settings' );

        $fields = array(
            'hard_enabled'           => __( 'Włącz moduł', 'essa-wp-suite' ),
            'hard_remove_version'    => __( 'Usuń ?ver=X z URL skryptów i styli', 'essa-wp-suite' ),
            'hard_remove_generator'  => __( 'Usuń &lt;meta name="generator"&gt;', 'essa-wp-suite' ),
            'hard_remove_rss_ver'    => __( 'Usuń wersję WP z RSS', 'essa-wp-suite' ),
            'hard_remove_head_links' => __( 'Usuń RSD, wlwmanifest, shortlink, adjacent z &lt;head&gt;', 'essa-wp-suite' ),
            'hard_disable_emoji'     => __( 'Wyłącz skrypty emoji WP (szybszy frontend)', 'essa-wp-suite' ),
            'hard_remove_wp_embed'   => __( 'Usuń wp-embed.min.js', 'essa-wp-suite' ),
            'hard_security_headers'  => __( 'Dodaj Security Headers (X-Frame-Options, nosniff, …)', 'essa-wp-suite' ),
        );

        foreach ( $fields as $id => $label ) {
            add_settings_field( 'ewps_' . $id, $label, array( $this, 'field_checkbox' ), 'ewps-hard-settings', 'ewps_hard_main', array( 'id' => $id ) );
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
        printf( '<input type="checkbox" id="ewps_%1$s" name="ewps_hard_settings[%1$s]" value="1" %2$s>',
            esc_attr( $id ), checked( '1', $this->opt( $id ), false ) );
    }
}

endif;
