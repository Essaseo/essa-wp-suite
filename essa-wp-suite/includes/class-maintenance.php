<?php
/**
 * ESSA WP Suite — Maintenance Mode
 *
 * Funkcje:
 *  - Strona techniczna dla niezalogowanych (lub wszystkich poza adminem)
 *  - Własny tytuł i treść (HTML)
 *  - Whitelist IP
 *  - Poprawny nagłówek HTTP 503 + Retry-After
 *
 * PHP 7.2+
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'ESSA_Maintenance' ) ) :

class ESSA_Maintenance {

    private static $instance = null;

    protected $defaults = array(
        'maint_enabled'      => '0',
        'maint_title'        => 'Serwis techniczny',
        'maint_message'      => '<p>Strona jest chwilowo niedostępna z powodu prac technicznych. Zapraszamy wkrótce!</p>',
        'maint_logo_url'     => '',
        'maint_bg_image_url' => '',
        'maint_bg_color'     => '#0d0c1d',
        'maint_text_color'   => '#e2e2f0',
        'maint_accent_color' => '#2CFFC0',
        'maint_custom_html'  => '',
        'maint_custom_css'   => '',
        'maint_retry'        => '3600',
        'maint_whitelist'    => '',
        'maint_bypass_role'  => 'administrator',
        'maint_trust_proxy'  => '0',
    );

    protected $opts = array();

    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $this->opts = wp_parse_args( get_option( 'ewps_maint_settings', array() ), $this->defaults );

        // Podgląd dla admina działa zawsze — także przy wyłączonym module.
        add_action( 'template_redirect', array( $this, 'maybe_preview' ), 0 );

        if ( ! $this->opt( 'maint_enabled' ) ) return;
        add_action( 'template_redirect', array( $this, 'maybe_show_maintenance' ), 1 );
        // Ostrzeżenie w pasku admina, żeby nikt nie zapomniał wyłączyć.
        add_action( 'admin_bar_menu', array( $this, 'admin_bar_warning' ), 100 );
    }

    private function opt( $k ) {
        return isset( $this->opts[ $k ] ) ? $this->opts[ $k ] : ( isset( $this->defaults[ $k ] ) ? $this->defaults[ $k ] : '' );
    }

    private function get_client_ip() {
        return ESSA_Suite_Utils::client_ip( (bool) $this->opt( 'maint_trust_proxy' ) );
    }

    public function admin_bar_warning( $wp_admin_bar ) {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $wp_admin_bar->add_node( array(
            'id'    => 'ewps-maintenance-on',
            'title' => '🚧 ' . __( 'Tryb serwisowy WŁĄCZONY', 'essa-wp-suite' ),
            'href'  => ESSA_Suite_Utils::tab_url( 'maintenance' ),
            'meta'  => array( 'style' => 'background:#f97316;color:#000;font-weight:700' ),
        ) );
    }

    public function maybe_preview() {
        if ( isset( $_GET['ewps_preview_maintenance'] ) && current_user_can( 'manage_options' ) ) {
            $this->render_maintenance_page( true );
        }
    }

    public function maybe_show_maintenance() {
        if ( is_admin() ) return;
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) return;
        if ( defined( 'DOING_CRON' ) && DOING_CRON ) return;
        if ( defined( 'WP_CLI' ) && WP_CLI ) return;
        if ( current_user_can( 'manage_options' ) ) return;

        if ( ESSA_Suite_Utils::ip_in_list( $this->get_client_ip(), $this->opt( 'maint_whitelist' ) ) ) return;

        $bypass_role = $this->opt( 'maint_bypass_role' );
        if ( is_user_logged_in() && $bypass_role && current_user_can( $bypass_role ) ) return;

        /** Pozwala innym wtyczkom wyłączyć stronę serwisową dla konkretnego żądania (np. webhook). */
        if ( ! apply_filters( 'ewps_maintenance_show', true ) ) return;

        $this->render_maintenance_page();
    }

    public function render_maintenance_page( $preview = false ) {
        $title       = $this->opt( 'maint_title' );
        $message     = $this->opt( 'maint_message' );
        $logo        = $this->opt( 'maint_logo_url' );
        $bg_image    = $this->opt( 'maint_bg_image_url' );
        $bg_color    = $this->opt( 'maint_bg_color' ) ?: '#0d0c1d';
        $text_color  = $this->opt( 'maint_text_color' ) ?: '#e2e2f0';
        $accent      = $this->opt( 'maint_accent_color' ) ?: '#2CFFC0';
        $custom_html = $this->opt( 'maint_custom_html' );
        $custom_css  = $this->opt( 'maint_custom_css' );
        $retry       = (int) $this->opt( 'maint_retry' );

        if ( ! $preview ) {
            status_header( 503 );
            header( 'Retry-After: ' . $retry );
        }
        header( 'Content-Type: text/html; charset=utf-8' );
        header( 'X-Robots-Tag: noindex' );
        nocache_headers();

        $bg_style = $bg_image
            ? 'background: url(' . esc_url( $bg_image ) . ') center/cover no-repeat; background-color: ' . esc_attr( $bg_color ) . ';'
            : 'background: ' . esc_attr( $bg_color ) . ';';

        $safe_title   = esc_html( $title );
        $safe_message = wp_kses_post( $message );
        $safe_ch      = wp_kses_post( $custom_html );
        $blog         = esc_html( get_bloginfo( 'name' ) );
        $preview_bar  = $preview ? '<div style="position:fixed;top:0;left:0;right:0;background:#f59e0b;color:#000;text-align:center;padding:8px;font-size:13px;font-weight:700;z-index:99999">⚠️ PODGLĄD TRYBU SERWISOWEGO — użytkownicy widzą tę stronę</div>' : '';

        echo '<!DOCTYPE html>
<html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>' . $safe_title . ' — ' . $blog . '</title>
<style>
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;' . $bg_style . 'color:' . esc_attr( $text_color ) . ';display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}
  .ewps-maint{max-width:520px;width:100%;text-align:center}
  .ewps-maint__logo{max-width:200px;max-height:80px;margin:0 auto 28px;display:block}
  .ewps-maint__icon{font-size:64px;margin-bottom:24px;display:block;animation:pulse 2s ease-in-out infinite}
  @keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}
  .ewps-maint__accent{height:3px;width:60px;background:' . esc_attr( $accent ) . ';border-radius:2px;margin:0 auto 28px}
  h1{font-size:32px;font-weight:800;letter-spacing:-0.5px;margin-bottom:14px;color:' . esc_attr( $text_color ) . '}
  .ewps-maint__msg{font-size:16px;line-height:1.7;opacity:.75;margin-bottom:32px}
  .ewps-maint__badge{display:inline-block;background:' . esc_attr( $accent ) . ';color:#0d0c1d;font-size:12px;font-weight:700;padding:5px 16px;border-radius:20px;letter-spacing:.5px}
  .ewps-maint__custom{margin-top:28px}
  ' . wp_strip_all_tags( $custom_css ) . '
</style>
</head>
<body>
' . $preview_bar . '
<div class="ewps-maint">
  ' . ( $logo ? '<img src="' . esc_url( $logo ) . '" alt="' . $blog . '" class="ewps-maint__logo">' : '<span class="ewps-maint__icon">🔧</span>' ) . '
  <div class="ewps-maint__accent"></div>
  <h1>' . $safe_title . '</h1>
  <div class="ewps-maint__msg">' . $safe_message . '</div>
  <span class="ewps-maint__badge">503 Service Unavailable</span>
  ' . ( $safe_ch ? '<div class="ewps-maint__custom">' . $safe_ch . '</div>' : '' ) . '
</div>
</body>
</html>';
        exit;
    }

    public function register_settings() {
        register_setting( 'ewps_maint_settings_group', 'ewps_maint_settings', array( $this, 'sanitize' ) );
        add_settings_section( 'ewps_maint_main', '', '__return_false', 'ewps-maint-settings' );

        $fields = array(
            'maint_enabled'      => array( __( 'Włącz tryb serwisowy', 'essa-wp-suite' ),           'field_checkbox' ),
            'maint_title'        => array( __( 'Tytuł strony', 'essa-wp-suite' ),                    'field_text' ),
            'maint_message'      => array( __( 'Treść komunikatu (HTML dozwolony)', 'essa-wp-suite' ),'field_editor' ),
            'maint_logo_url'     => array( __( 'URL logo (zamiast emoji 🔧)', 'essa-wp-suite' ),     'field_text_logo' ),
            'maint_bg_image_url' => array( __( 'URL zdjęcia tła', 'essa-wp-suite' ),                 'field_text_bg' ),
            'maint_bg_color'     => array( __( 'Kolor tła', 'essa-wp-suite' ),                       'field_color' ),
            'maint_text_color'   => array( __( 'Kolor tekstu', 'essa-wp-suite' ),                    'field_color' ),
            'maint_accent_color' => array( __( 'Kolor akcentu (badge, pasek)', 'essa-wp-suite' ),    'field_color' ),
            'maint_custom_html'  => array( __( 'Dodatkowy HTML (np. social linki)', 'essa-wp-suite' ),'field_html' ),
            'maint_custom_css'   => array( __( 'Własne CSS', 'essa-wp-suite' ),                      'field_css' ),
            'maint_retry'        => array( __( 'Retry-After (sekundy)', 'essa-wp-suite' ),            'field_number' ),
            'maint_whitelist'    => array( __( 'Whitelist IP', 'essa-wp-suite' ),                     'field_whitelist' ),
            'maint_trust_proxy'  => array( __( 'Strona jest za proxy / Cloudflare', 'essa-wp-suite' ), 'field_proxy' ),
            'maint_bypass_role'  => array( __( 'Rola pomijająca serwis', 'essa-wp-suite' ),           'field_role' ),
        );

        foreach ( $fields as $id => $data ) {
            add_settings_field( 'ewps_' . $id, $data[0], array( $this, $data[1] ), 'ewps-maint-settings', 'ewps_maint_main', array( 'id' => $id ) );
        }
    }

    public function sanitize( $in ) {
        $in = is_array( $in ) ? $in : array();
        $c  = array();
        $c['maint_enabled']      = ! empty( $in['maint_enabled'] ) ? '1' : '0';
        $c['maint_trust_proxy']  = ! empty( $in['maint_trust_proxy'] ) ? '1' : '0';
        $c['maint_title']        = sanitize_text_field( $in['maint_title'] ?? 'Serwis techniczny' );
        $c['maint_message']      = wp_kses_post( $in['maint_message'] ?? '' );
        $c['maint_logo_url']     = esc_url_raw( $in['maint_logo_url'] ?? '' );
        $c['maint_bg_image_url'] = esc_url_raw( $in['maint_bg_image_url'] ?? '' );
        $c['maint_bg_color']     = sanitize_hex_color( $in['maint_bg_color'] ?? '#0d0c1d' ) ?: '#0d0c1d';
        $c['maint_text_color']   = sanitize_hex_color( $in['maint_text_color'] ?? '#e2e2f0' ) ?: '#e2e2f0';
        $c['maint_accent_color'] = sanitize_hex_color( $in['maint_accent_color'] ?? '#2CFFC0' ) ?: '#2CFFC0';
        $c['maint_custom_html']  = wp_kses_post( $in['maint_custom_html'] ?? '' );
        $c['maint_custom_css']   = wp_strip_all_tags( $in['maint_custom_css'] ?? '' );
        $c['maint_retry']        = max( 60, absint( $in['maint_retry'] ?? 3600 ) );
        $c['maint_whitelist']    = sanitize_textarea_field( $in['maint_whitelist'] ?? '' );
        $role = sanitize_key( $in['maint_bypass_role'] ?? 'administrator' );
        $c['maint_bypass_role']  = array_key_exists( $role, wp_roles()->get_names() ) ? $role : 'administrator';
        return $c;
    }

    public function field_checkbox( $a ) {
        $id = $a['id'];
        printf( '<input type="checkbox" name="ewps_maint_settings[%1$s]" value="1" %2$s>', esc_attr( $id ), checked( '1', $this->opt( $id ), false ) );
    }
    public function field_proxy( $a ) {
        $this->field_checkbox( $a );
        echo '<p class="description">' . esc_html__( 'Włącz tylko za Cloudflare / reverse proxy — wtedy whitelist IP porównuje adres z nagłówków proxy.', 'essa-wp-suite' ) . '</p>';
    }
    public function field_text( $a ) {
        $id = $a['id'];
        printf( '<input type="text" name="ewps_maint_settings[%1$s]" value="%2$s" class="regular-text">', esc_attr( $id ), esc_attr( $this->opt( $id ) ) );
    }
    public function field_text_logo( $a ) {
        printf( '<input type="url" name="ewps_maint_settings[maint_logo_url]" value="%s" class="large-text" placeholder="https://"><p class="description">%s</p>',
            esc_attr( $this->opt( 'maint_logo_url' ) ),
            esc_html__( 'Zostaw puste aby wyświetlić emoji 🔧. Zalecany rozmiar: max 200×80px.', 'essa-wp-suite' ) );
    }
    public function field_text_bg( $a ) {
        printf( '<input type="url" name="ewps_maint_settings[maint_bg_image_url]" value="%s" class="large-text" placeholder="https://"><p class="description">%s</p>',
            esc_attr( $this->opt( 'maint_bg_image_url' ) ),
            esc_html__( 'Pełnoekranowe zdjęcie tła. Zostaw puste aby użyć koloru tła.', 'essa-wp-suite' ) );
    }
    public function field_color( $a ) {
        $id = $a['id'];
        printf( '<input type="color" name="ewps_maint_settings[%1$s]" value="%2$s" style="width:60px;height:36px;padding:2px">', esc_attr( $id ), esc_attr( $this->opt( $id ) ) );
    }
    public function field_editor( $a ) {
        $id      = $a['id'];
        $content = $this->opt( $id );
        if ( function_exists( 'wp_editor' ) ) {
            wp_editor( $content, 'ewps_maint_message', array(
                'textarea_name' => 'ewps_maint_settings[maint_message]',
                'media_buttons' => false,
                'teeny'         => true,
                'textarea_rows' => 6,
            ) );
        } else {
            printf( '<textarea name="ewps_maint_settings[%1$s]" rows="6" class="large-text">%2$s</textarea>', esc_attr( $id ), esc_textarea( $content ) );
        }
    }
    public function field_html( $a ) {
        $id = $a['id'];
        printf( '<textarea name="ewps_maint_settings[%1$s]" rows="4" class="large-text" placeholder="&lt;a href=&quot;https://facebook.com&quot;&gt;Facebook&lt;/a&gt;">%2$s</textarea><p class="description">%3$s</p>',
            esc_attr( $id ), esc_textarea( $this->opt( $id ) ),
            esc_html__( 'Dozwolone: a, p, span, strong, em, br, ul, li i atrybuty href/class/target.', 'essa-wp-suite' ) );
    }
    public function field_css( $a ) {
        $id = $a['id'];
        printf( '<textarea name="ewps_maint_settings[%1$s]" rows="5" class="large-text code">%2$s</textarea>', esc_attr( $id ), esc_textarea( $this->opt( $id ) ) );
    }
    public function field_number( $a ) {
        $id = $a['id'];
        printf( '<input type="number" name="ewps_maint_settings[%1$s]" value="%2$s" min="60" style="width:90px"> <span class="description">min. 60s</span>', esc_attr( $id ), esc_attr( $this->opt( $id ) ) );
    }
    public function field_whitelist( $a ) {
        $id = $a['id'];
        printf( '<textarea name="ewps_maint_settings[%1$s]" rows="3" class="regular-text">%2$s</textarea><p class="description">%3$s</p>',
            esc_attr( $id ), esc_textarea( $this->opt( $id ) ),
            esc_html__( 'Np. 127.0.0.1, 192.168.1.0/24 — te IP zawsze widzą normalną stronę. Oddzielaj przecinkami lub nowymi liniami.', 'essa-wp-suite' ) );
    }
    public function field_role( $a ) {
        $id      = $a['id'];
        $current = $this->opt( $id );
        $roles   = wp_roles()->get_names();
        echo '<select name="ewps_maint_settings[' . esc_attr( $id ) . ']">';
        foreach ( $roles as $slug => $name ) {
            printf( '<option value="%s" %s>%s</option>', esc_attr( $slug ), selected( $current, $slug, false ), esc_html( translate_user_role( $name ) ) );
        }
        echo '</select><p class="description">' . esc_html__( 'Ta rola i wyższe zawsze widzą normalną stronę.', 'essa-wp-suite' ) . '</p>';
    }
}
endif;
