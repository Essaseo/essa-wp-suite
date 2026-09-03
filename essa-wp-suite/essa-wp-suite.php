<?php
/**
 * Plugin Name:       ESSA WP Suite
 * Plugin URI:        https://essaseo.pl/essa-wp-suite/
 * Description:       Zestaw narzędzi administracyjnych WordPress od ESSA SEO: Email Encoder, Disable Comments, Disable XML-RPC, WP Hardening, Admin Notices, Maintenance Mode. Wersja Pro dodaje Login Security, SMTP, DB Cleaner, Admin Cleaner, Auto Updates, White Label i Activity Log.
 * Version:           2.0.0
 * Author:            ESSA SEO Digital Agency
 * Author URI:        https://essaseo.pl
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       essa-wp-suite
 * Domain Path:       /languages
 * Requires at least: 5.6
 * Requires PHP:      7.2
 * Update URI:        https://github.com/Essaseo/essa-wp-suite
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'EWPS_VERSION',     '2.0.0' );
define( 'EWPS_MIN_PHP',     '7.2' );
define( 'EWPS_MIN_WP',      '5.6' );
define( 'EWPS_PLUGIN_FILE', __FILE__ );
define( 'EWPS_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'EWPS_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'EWPS_BASENAME',    plugin_basename( __FILE__ ) );

// ─── Kontrola wymagań ────────────────────────────────────────────────────────

function ewps_requirements_ok() {
    global $wp_version;
    return version_compare( PHP_VERSION, EWPS_MIN_PHP, '>=' )
        && version_compare( $wp_version, EWPS_MIN_WP, '>=' );
}

function ewps_requirements_notice() {
    global $wp_version;
    echo '<div class="notice notice-error"><p><strong>ESSA WP Suite</strong>: '
        . sprintf(
            /* translators: 1: min PHP, 2: current PHP, 3: min WP, 4: current WP */
            esc_html__( 'wymagane PHP %1$s (masz %2$s) i WordPress %3$s (masz %4$s). Moduły nie zostały załadowane.', 'essa-wp-suite' ),
            esc_html( EWPS_MIN_PHP ), esc_html( PHP_VERSION ), esc_html( EWPS_MIN_WP ), esc_html( $wp_version )
        )
        . '</p></div>';
}

if ( ! ewps_requirements_ok() ) {
    add_action( 'admin_notices', 'ewps_requirements_notice' );
    return;
}

// ─── Moduły Free ─────────────────────────────────────────────────────────────

require_once EWPS_PLUGIN_DIR . 'includes/class-utils.php';
require_once EWPS_PLUGIN_DIR . 'includes/class-encoder-core.php';
require_once EWPS_PLUGIN_DIR . 'includes/class-disable-comments.php';
require_once EWPS_PLUGIN_DIR . 'includes/class-disable-xmlrpc.php';
require_once EWPS_PLUGIN_DIR . 'includes/class-maintenance.php';
require_once EWPS_PLUGIN_DIR . 'includes/class-wp-hardening.php';
require_once EWPS_PLUGIN_DIR . 'includes/class-admin-notices.php';
require_once EWPS_PLUGIN_DIR . 'includes/class-license.php';
require_once EWPS_PLUGIN_DIR . 'includes/class-updater.php';

// ─── Klasa główna ────────────────────────────────────────────────────────────

class ESSA_WP_Suite {

    private static $instance = null;

    private $defaults = array(
        'enc_enabled'        => '1',
        'enc_encode_method'  => 'mixed',
        'enc_filter_priority'=> '100',
        'enc_filter_content' => '1',
        'enc_filter_excerpt' => '1',
        'enc_filter_comments'=> '1',
        'enc_filter_widgets' => '1',
        'enc_filter_menus'   => '1',
        'enc_filter_author'  => '1',
    );

    private $options = array();

    /** @var ESSA_Email_Encoder_Core */
    private $encoder_core;

    /** Cache rejestru modułów (Free + Pro + zablokowane). */
    private $modules_cache = null;

    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $this->options = wp_parse_args( get_option( 'ewps_enc_settings', array() ), $this->defaults );
        $this->encoder_core = new ESSA_Email_Encoder_Core( $this->opt( 'enc_encode_method' ) );

        ESSA_Disable_Comments::get_instance();
        ESSA_Disable_XMLRPC::get_instance();
        ESSA_Maintenance::get_instance();
        ESSA_WP_Hardening::get_instance();
        ESSA_Admin_Notices::get_instance();
        ESSA_License::get_instance();
        ESSA_Updater::get_instance();

        add_action( 'plugins_loaded',        array( $this, 'load_textdomain' ) );
        add_action( 'admin_menu',            array( $this, 'admin_menu' ) );
        add_action( 'admin_init',            array( $this, 'maybe_upgrade' ), 1 );
        add_action( 'admin_init',            array( $this, 'admin_init' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
        add_action( 'admin_notices',         array( ESSA_Updater::get_instance(), 'admin_notice' ) );
        add_filter( 'submenu_file',          array( $this, 'highlight_submenu' ), 10, 2 );
        add_filter( 'plugin_action_links_' . EWPS_BASENAME, array( $this, 'action_links' ) );

        if ( $this->opt( 'enc_enabled' ) ) {
            $this->register_encoder_filters();
        }
        add_shortcode( 'encode', array( $this, 'shortcode_encode' ) );

        add_action( 'wp_ajax_ewps_test_encode',   array( $this, 'ajax_test_encode' ) );
        add_action( 'wp_ajax_ewps_toggle_module', array( $this, 'ajax_toggle_module' ) );

        register_activation_hook( EWPS_PLUGIN_FILE, array( $this, 'on_activate' ) );
        register_deactivation_hook( EWPS_PLUGIN_FILE, array( $this, 'on_deactivate' ) );
    }

    private function opt( $k ) {
        return isset( $this->options[ $k ] ) ? $this->options[ $k ] : ( isset( $this->defaults[ $k ] ) ? $this->defaults[ $k ] : '' );
    }

    // ─── Email Encoder ───────────────────────────────────────────────────────

    private function register_encoder_filters() {
        $p = (int) $this->opt( 'enc_filter_priority' );
        if ( $this->opt( 'enc_filter_content' ) )  add_filter( 'the_content', array( $this, 'encode_emails' ), $p );
        if ( $this->opt( 'enc_filter_excerpt' ) ) {
            add_filter( 'the_excerpt',     array( $this, 'encode_emails' ), $p );
            add_filter( 'get_the_excerpt', array( $this, 'encode_emails' ), $p );
        }
        if ( $this->opt( 'enc_filter_comments' ) ) add_filter( 'comment_text', array( $this, 'encode_emails' ), $p );
        if ( $this->opt( 'enc_filter_widgets' ) ) {
            add_filter( 'widget_text',          array( $this, 'encode_emails' ), $p );
            add_filter( 'widget_block_content', array( $this, 'encode_emails' ), $p );
        }
        if ( $this->opt( 'enc_filter_menus' ) )  add_filter( 'wp_nav_menu_items', array( $this, 'encode_emails' ), $p );
        if ( $this->opt( 'enc_filter_author' ) ) add_filter( 'the_author', array( $this, 'encode_emails' ), $p );
        add_filter( 'term_description', array( $this, 'encode_emails' ), $p );
    }

    public function encode_emails( $str ) {
        if ( function_exists( 'is_feed' ) && is_feed() ) return $str;
        return $this->encoder_core->encode_emails( $str );
    }

    public function shortcode_encode( $atts, $content = '' ) {
        $atts    = shortcode_atts( array( 'link' => '', 'class' => '' ), $atts, 'encode' );
        $content = wp_kses_post( $content );
        $encoded = $this->encoder_core->encode_str( $content );
        if ( ! empty( $atts['link'] ) ) {
            $safe_link = esc_url( $atts['link'], array( 'http', 'https', 'mailto', 'tel', 'sms' ) );
            if ( '' === $safe_link ) return $encoded;
            $href  = $this->encoder_core->encode_str( $safe_link );
            $class = ! empty( $atts['class'] ) ? ' class="' . esc_attr( $atts['class'] ) . '"' : '';
            return '<a href="' . $href . '"' . $class . '>' . $encoded . '</a>';
        }
        return $encoded;
    }

    // ─── Rejestr modułów ─────────────────────────────────────────────────────

    /**
     * Katalog modułów Pro — tylko opis, bez kodu. Free pokazuje je jako zablokowane
     * karty, dopóki wtyczka Pro nie zarejestruje ich przez filtr `ewps_pro_modules`.
     */
    public static function pro_catalog() {
        return array(
            'login' => array(
                'id' => 'login', 'icon' => '🔒', 'label' => 'Login Security', 'tab' => 'login', 'color' => '#f59e0b',
                'description' => __( 'Własny adres logowania, ukrycie /wp-login.php i limit prób z blokadą IP.', 'essa-wp-suite' ),
                'features'    => array( __( 'Własny slug logowania', 'essa-wp-suite' ), __( 'Ukrycie /wp-login.php', 'essa-wp-suite' ), __( 'Limit prób + blokada IP', 'essa-wp-suite' ) ),
                'option_key'  => 'ewps_ls_settings', 'toggle_key' => 'ls_enabled',
            ),
            'smtp' => array(
                'id' => 'smtp', 'icon' => '📧', 'label' => 'SMTP Mailer', 'tab' => 'smtp', 'color' => '#0ea5e9',
                'description' => __( 'Wysyła wp_mail() przez prawdziwy serwer SMTP, żeby maile nie trafiały do spamu.', 'essa-wp-suite' ),
                'features'    => array( __( 'TLS / SSL / brak', 'essa-wp-suite' ), __( 'Własny nadawca From', 'essa-wp-suite' ), __( 'Presety Gmail, OVH, Brevo', 'essa-wp-suite' ) ),
                'option_key'  => 'ewps_smtp_settings', 'toggle_key' => 'smtp_enabled',
            ),
            'db' => array(
                'id' => 'db', 'icon' => '🗑️', 'label' => 'DB Cleaner', 'tab' => 'db', 'color' => '#10b981',
                'description' => __( 'Czyści bazę: rewizje, wygasłe transjenty, auto-drafty, spam, osierocone meta.', 'essa-wp-suite' ),
                'features'    => array( __( 'Czyszczenie jednym kliknięciem', 'essa-wp-suite' ), __( 'Limit rewizji na post', 'essa-wp-suite' ), __( 'Auto-cleanup co tydzień', 'essa-wp-suite' ) ),
                'option_key'  => 'ewps_db_settings', 'toggle_key' => 'db_enabled',
            ),
            'admin_cleaner' => array(
                'id' => 'admin_cleaner', 'icon' => '🧹', 'label' => 'Admin Cleaner', 'tab' => 'admin_cleaner', 'color' => '#f59e0b',
                'description' => __( 'Upraszcza panel: ukrywa zbędne menu, widżety kokpitu i elementy UI.', 'essa-wp-suite' ),
                'features'    => array( __( 'Ukryj pozycje menu', 'essa-wp-suite' ), __( 'Ukryj widżety kokpitu', 'essa-wp-suite' ), __( 'Własna stopka admina', 'essa-wp-suite' ) ),
                'option_key'  => 'ewps_ac_settings', 'toggle_key' => 'ac_enabled',
            ),
            'auto_updates' => array(
                'id' => 'auto_updates', 'icon' => '🔄', 'label' => 'Auto Updates', 'tab' => 'auto_updates', 'color' => '#6366f1',
                'description' => __( 'Kontrola automatycznych aktualizacji WP core, wtyczek i motywów.', 'essa-wp-suite' ),
                'features'    => array( __( 'Core major/minor osobno', 'essa-wp-suite' ), __( 'Wtyczki i motywy: wymuś / zablokuj / jak w WP', 'essa-wp-suite' ), __( 'Email po aktualizacji', 'essa-wp-suite' ) ),
                'option_key'  => 'ewps_au_settings', 'toggle_key' => 'au_enabled',
            ),
            'white_label' => array(
                'id' => 'white_label', 'icon' => '🏷️', 'label' => 'White Label', 'tab' => 'white_label', 'color' => '#ec4899',
                'description' => __( 'Rebranding panelu: własne logo logowania, kolory, nazwa, stopka.', 'essa-wp-suite' ),
                'features'    => array( __( 'Logo na stronie logowania', 'essa-wp-suite' ), __( 'Kolory i CSS logowania', 'essa-wp-suite' ), __( 'Ukryj branding WordPress', 'essa-wp-suite' ) ),
                'option_key'  => 'ewps_wl_settings', 'toggle_key' => 'wl_enabled',
            ),
            'activity' => array(
                'id' => 'activity', 'icon' => '📋', 'label' => 'Activity Log', 'tab' => 'activity', 'color' => '#64748b',
                'description' => __( 'Log akcji użytkowników: kto, co i kiedy zmienił, zalogował się, zainstalował.', 'essa-wp-suite' ),
                'features'    => array( __( 'Logowania, edycje, wtyczki, ustawienia', 'essa-wp-suite' ), __( 'Eksport do CSV', 'essa-wp-suite' ), __( 'Konfigurowalny czas przechowywania', 'essa-wp-suite' ) ),
                'option_key'  => 'ewps_log_settings', 'toggle_key' => 'log_enabled',
            ),
        );
    }

    private function free_modules() {
        $self = $this;
        return array(
            'encoder' => array(
                'id' => 'encoder', 'icon' => '✉', 'label' => 'Email Encoder', 'tab' => 'encoder', 'color' => '#2CFFC0',
                'description' => __( 'Koduje adresy email do encji HTML. Boty spamowe ich nie odczytają, przeglądarka wyświetla normalnie. Działa bez JS.', 'essa-wp-suite' ),
                'features'    => array( __( 'Kodowanie decimal/hex/mixed', 'essa-wp-suite' ), __( 'Hooki: treść, zajawki, widżety, menu', 'essa-wp-suite' ), __( 'Shortcode [encode]', 'essa-wp-suite' ) ),
                'option_key'  => 'ewps_enc_settings', 'toggle_key' => 'enc_enabled',
                'group' => 'ewps_enc_settings_group', 'page' => 'ewps-enc-settings',
                'sidebar' => function() { ?>
                    <div class="ewps-card"><h3><?php esc_html_e( 'Shortcode', 'essa-wp-suite' ); ?></h3><pre>[encode]email@domena.pl[/encode]
[encode link="mailto:email@domena.pl"]Napisz do nas[/encode]
[encode link="tel:+48123456789"]+48 123 456 789[/encode]</pre></div>
                    <div class="ewps-card ewps-card--test">
                        <h3><?php esc_html_e( 'Test kodowania', 'essa-wp-suite' ); ?></h3>
                        <input type="text" id="ewps-test-input" placeholder="email@domena.pl" class="regular-text">
                        <button type="button" id="ewps-test-btn" class="button"><?php esc_html_e( 'Koduj', 'essa-wp-suite' ); ?></button>
                        <pre id="ewps-test-output" style="display:none;margin-top:10px;white-space:pre-wrap;word-break:break-all"></pre>
                    </div>
                <?php },
            ),
            'comments' => array(
                'id' => 'comments', 'icon' => '💬', 'label' => 'Disable Comments', 'tab' => 'comments', 'color' => '#722BF5',
                'description' => __( 'Wyłącza komentarze na całej stronie lub per typ postu. Czyści UI admina.', 'essa-wp-suite' ),
                'features'    => array( __( 'Globalnie lub per typ postu', 'essa-wp-suite' ), __( 'Usuwa menu i widżety admina', 'essa-wp-suite' ), __( 'Blokuje REST API i XML-RPC', 'essa-wp-suite' ) ),
                'option_key'  => 'eee_dc_settings', 'toggle_key' => 'dc_enabled',
                'group' => 'eee_dc_settings_group', 'page' => 'eee-dc-settings',
                'sidebar' => array( ESSA_Disable_Comments::get_instance(), 'delete_comments_tool_html' ),
            ),
            'xmlrpc' => array(
                'id' => 'xmlrpc', 'icon' => '⛔', 'label' => 'Disable XML-RPC', 'tab' => 'xmlrpc', 'color' => '#ef4444',
                'description' => __( 'Całkowite wyłączenie XML-RPC. Blokuje brute-force przez xmlrpc.php.', 'essa-wp-suite' ),
                'features'    => array( __( 'Filtr xmlrpc_enabled → false', 'essa-wp-suite' ), __( 'Blokada endpointu 403', 'essa-wp-suite' ), __( 'Usuwa X-Pingback, RSD, wlwmanifest', 'essa-wp-suite' ) ),
                'option_key'  => 'ewps_xmlrpc_settings', 'toggle_key' => 'xmlrpc_enabled',
                'group' => 'ewps_xmlrpc_settings_group', 'page' => 'ewps-xmlrpc-settings',
                'sidebar' => function() use ( $self ) { $self->sidebar_tip( __( 'Jetpack i aplikacja mobilna WordPress wymagają XML-RPC. Sprawdź przed wyłączeniem.', 'essa-wp-suite' ) ); },
            ),
            'maintenance' => array(
                'id' => 'maintenance', 'icon' => '🚧', 'label' => 'Maintenance Mode', 'tab' => 'maintenance', 'color' => '#f97316',
                'description' => __( 'Strona serwisowa 503 dla odwiedzających. Admini widzą normalną stronę.', 'essa-wp-suite' ),
                'features'    => array( __( 'Własny tytuł i treść HTML', 'essa-wp-suite' ), __( 'Whitelist IP', 'essa-wp-suite' ), __( 'Nagłówek 503 + Retry-After', 'essa-wp-suite' ) ),
                'option_key'  => 'ewps_maint_settings', 'toggle_key' => 'maint_enabled',
                'group' => 'ewps_maint_settings_group', 'page' => 'ewps-maint-settings',
                'sidebar' => function() use ( $self ) {
                    $self->sidebar_tip( __( 'Pamiętaj o wyłączeniu trybu serwisowego po zakończeniu prac!', 'essa-wp-suite' ) );
                    echo '<div class="ewps-card"><h3>' . esc_html__( 'Podgląd', 'essa-wp-suite' ) . '</h3><p><a href="' . esc_url( home_url( '/?ewps_preview_maintenance=1' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Zobacz stronę serwisową →', 'essa-wp-suite' ) . '</a></p><p class="description">' . esc_html__( 'Podgląd działa także przy wyłączonym module. Zapisz ustawienia przed podglądem.', 'essa-wp-suite' ) . '</p></div>';
                },
            ),
            'hardening' => array(
                'id' => 'hardening', 'icon' => '🛡️', 'label' => 'WP Hardening', 'tab' => 'hardening', 'color' => '#3b82f6',
                'description' => __( 'Ukrywa wersję WP, wyłącza emoji, dodaje nagłówki bezpieczeństwa.', 'essa-wp-suite' ),
                'features'    => array( __( 'Usuwa ?ver= z URL assetów', 'essa-wp-suite' ), __( 'Usuwa meta generator', 'essa-wp-suite' ), __( 'X-Frame-Options, nosniff, Referrer-Policy', 'essa-wp-suite' ) ),
                'option_key'  => 'ewps_hard_settings', 'toggle_key' => 'hard_enabled',
                'group' => 'ewps_hard_settings_group', 'page' => 'ewps-hard-settings',
                'sidebar' => function() use ( $self ) { $self->sidebar_tip( __( 'Usunięcie ?ver= z adresów assetów wyłącza odświeżanie cache przeglądarki po aktualizacjach. Przy problemach ze stylami po update wyłącz tę opcję.', 'essa-wp-suite' ) ); },
            ),
            'notices' => array(
                'id' => 'notices', 'icon' => '🔕', 'label' => 'Admin Notices', 'tab' => 'notices', 'color' => '#8b5cf6',
                'description' => __( 'Chowa powiadomienia wtyczek do panelu w pasku admina. Błędy krytyczne zawsze widoczne.', 'essa-wp-suite' ),
                'features'    => array( __( 'Panel notices w admin barze', 'essa-wp-suite' ), __( 'Blokada popupów wtyczek', 'essa-wp-suite' ), __( 'Whitelist — wybrane zawsze widoczne', 'essa-wp-suite' ) ),
                'option_key'  => 'ewps_notices_settings', 'toggle_key' => 'notices_enabled',
                'group' => 'ewps_notices_settings_group', 'page' => 'ewps-notices-settings',
                'sidebar' => function() use ( $self ) { $self->sidebar_tip( __( 'Whitelist: klasy CSS powiadomień, które mają być zawsze widoczne (np. woocommerce-message).', 'essa-wp-suite' ) ); },
            ),
        );
    }

    /**
     * Pełny rejestr: Free + moduły zarejestrowane przez Pro + katalog Pro jako zablokowane.
     * Moduł Pro rejestruje się przez filtr `ewps_pro_modules` z tymi samymi kluczami co katalog
     * plus `group`/`page`/`sidebar` albo własne `render`.
     */
    public function get_modules() {
        if ( null !== $this->modules_cache ) return $this->modules_cache;

        $modules = $this->free_modules();
        $pro     = apply_filters( 'ewps_pro_modules', array() );
        $pro     = is_array( $pro ) ? $pro : array();

        foreach ( self::pro_catalog() as $id => $meta ) {
            if ( isset( $pro[ $id ] ) && is_array( $pro[ $id ] ) ) {
                $modules[ $id ] = array_merge( $meta, $pro[ $id ], array( 'pro' => true, 'locked' => false ) );
            } else {
                $modules[ $id ] = array_merge( $meta, array( 'pro' => true, 'locked' => true ) );
            }
        }
        // Moduły Pro spoza katalogu (przyszłe)
        foreach ( $pro as $id => $meta ) {
            if ( ! isset( $modules[ $id ] ) && is_array( $meta ) && ! empty( $meta['tab'] ) ) {
                $modules[ $id ] = array_merge( array( 'id' => $id, 'icon' => '⭐', 'label' => $id, 'color' => '#64748b', 'description' => '', 'features' => array(), 'option_key' => '', 'toggle_key' => '' ), $meta, array( 'pro' => true, 'locked' => false ) );
            }
        }
        $this->modules_cache = $modules;
        return $modules;
    }

    private function is_module_active( $module ) {
        if ( ! empty( $module['locked'] ) || empty( $module['option_key'] ) ) return false;
        $opts = get_option( $module['option_key'], array() );
        return is_array( $opts ) && ! empty( $opts[ $module['toggle_key'] ] );
    }

    // ─── Admin: menu ─────────────────────────────────────────────────────────

    public function menu_label() {
        return (string) apply_filters( 'ewps_menu_label', 'ESSA WP Suite' );
    }

    public function admin_menu() {
        $cap = (string) apply_filters( 'ewps_menu_capability', 'manage_options' );
        if ( ! current_user_can( $cap ) ) return;

        $icon  = 'data:image/svg+xml;base64,' . base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="white" d="M10 1L3 4v5c0 4.418 3.134 8.223 7 9 3.866-.777 7-4.582 7-9V4L10 1zm-1 11.414l-2.707-2.707 1.414-1.414L9 9.586l3.293-3.293 1.414 1.414L9 12.414z"/></svg>'
        );
        $label = $this->menu_label();

        add_menu_page( $label, $label, 'manage_options', 'ewps-settings', array( $this, 'render_settings_page' ), $icon, 65 );
        add_submenu_page( 'ewps-settings', $label, '⚡ ' . __( 'Dashboard', 'essa-wp-suite' ), 'manage_options', 'ewps-settings', array( $this, 'render_settings_page' ) );

        foreach ( $this->get_modules() as $mod ) {
            $title = $mod['icon'] . ' ' . $mod['label'] . ( ! empty( $mod['locked'] ) ? ' <span class="ewps-menu-pro">PRO</span>' : '' );
            add_submenu_page( 'ewps-settings', $mod['label'], $title, 'manage_options', 'admin.php?page=ewps-settings&tab=' . $mod['tab'] );
        }
        add_submenu_page( 'ewps-settings', __( 'Licencja Pro', 'essa-wp-suite' ), '🔑 ' . __( 'Licencja Pro', 'essa-wp-suite' ), 'manage_options', 'admin.php?page=ewps-settings&tab=license' );
    }

    public function highlight_submenu( $submenu_file, $parent_file ) {
        if ( 'ewps-settings' !== $parent_file ) return $submenu_file;
        $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : '';
        if ( $tab && 'dashboard' !== $tab ) return 'admin.php?page=ewps-settings&tab=' . $tab;
        return $submenu_file;
    }

    public function action_links( $links ) {
        array_unshift( $links, '<a href="' . esc_url( ESSA_Suite_Utils::tab_url() ) . '">' . esc_html__( 'Ustawienia', 'essa-wp-suite' ) . '</a>' );
        if ( current_user_can( 'update_plugins' ) ) {
            $links[] = '<a href="' . esc_url( ESSA_Updater::get_instance()->force_check_link() ) . '">' . esc_html__( 'Sprawdź aktualizacje', 'essa-wp-suite' ) . '</a>';
        }
        if ( ! ESSA_License::get_instance()->is_valid() ) {
            $links[] = '<a href="' . esc_url( ESSA_License::shop_url() ) . '" target="_blank" rel="noopener" style="color:#d63638;font-weight:600">' . esc_html__( 'Kup Pro', 'essa-wp-suite' ) . '</a>';
        }
        return $links;
    }

    // ─── Admin: ustawienia ───────────────────────────────────────────────────

    public function admin_init() {
        register_setting( 'ewps_enc_settings_group', 'ewps_enc_settings', array( $this, 'sanitize_enc' ) );
        add_settings_section( 'ewps_enc_general', __( 'Ochrona emaili', 'essa-wp-suite' ), '__return_false', 'ewps-enc-settings' );
        add_settings_section( 'ewps_enc_filters', __( 'Co filtrować?', 'essa-wp-suite' ), '__return_false', 'ewps-enc-settings' );

        $enc_general = array(
            'enc_enabled'         => array( __( 'Włącz Email Encoder', 'essa-wp-suite' ), 'enc_field_checkbox' ),
            'enc_encode_method'   => array( __( 'Metoda kodowania', 'essa-wp-suite' ),     'enc_field_method' ),
            'enc_filter_priority' => array( __( 'Priorytet filtrów', 'essa-wp-suite' ),    'enc_field_priority' ),
        );
        foreach ( $enc_general as $id => $data ) {
            add_settings_field( 'ewps_' . $id, $data[0], array( $this, $data[1] ), 'ewps-enc-settings', 'ewps_enc_general', array( 'id' => $id ) );
        }
        $enc_filters = array(
            'enc_filter_content'  => __( 'Treść postów/stron', 'essa-wp-suite' ),
            'enc_filter_excerpt'  => __( 'Zajawki', 'essa-wp-suite' ),
            'enc_filter_comments' => __( 'Komentarze', 'essa-wp-suite' ),
            'enc_filter_widgets'  => __( 'Widżety tekstowe', 'essa-wp-suite' ),
            'enc_filter_menus'    => __( 'Menu nawigacyjne', 'essa-wp-suite' ),
            'enc_filter_author'   => __( 'Pole autora', 'essa-wp-suite' ),
        );
        foreach ( $enc_filters as $id => $label ) {
            add_settings_field( 'ewps_' . $id, $label, array( $this, 'enc_field_checkbox' ), 'ewps-enc-settings', 'ewps_enc_filters', array( 'id' => $id ) );
        }

        ESSA_Disable_Comments::get_instance()->register_settings();
        ESSA_Disable_Comments::get_instance()->handle_delete_action();
        ESSA_Disable_XMLRPC::get_instance()->register_settings();
        ESSA_Maintenance::get_instance()->register_settings();
        ESSA_WP_Hardening::get_instance()->register_settings();
        ESSA_Admin_Notices::get_instance()->register_settings();

        /** Pro rejestruje tu swoje ustawienia i obsługuje akcje POST. */
        do_action( 'ewps_admin_init' );
    }

    public function maybe_upgrade() {
        $stored = get_option( 'ewps_version', '' );
        if ( EWPS_VERSION === $stored ) return;
        update_option( 'ewps_version', EWPS_VERSION, false );
        do_action( 'ewps_upgraded', $stored, EWPS_VERSION );
    }

    public function sanitize_enc( $in ) {
        $in = is_array( $in ) ? $in : array();
        $c  = array();
        $c['enc_enabled']         = ! empty( $in['enc_enabled'] ) ? '1' : '0';
        $c['enc_filter_priority'] = max( 1, absint( $in['enc_filter_priority'] ?? 100 ) );
        $method                   = $in['enc_encode_method'] ?? 'mixed';
        $c['enc_encode_method']   = in_array( $method, array( 'decimal', 'hex', 'mixed' ), true ) ? $method : 'mixed';
        foreach ( array( 'enc_filter_content', 'enc_filter_excerpt', 'enc_filter_comments', 'enc_filter_widgets', 'enc_filter_menus', 'enc_filter_author' ) as $k ) {
            $c[ $k ] = ! empty( $in[ $k ] ) ? '1' : '0';
        }
        return $c;
    }

    public function enc_field_checkbox( $a ) {
        $id = $a['id'];
        printf( '<input type="checkbox" id="ewps_%1$s" name="ewps_enc_settings[%1$s]" value="1" %2$s>', esc_attr( $id ), checked( '1', $this->opt( $id ), false ) );
    }

    public function enc_field_method( $a ) {
        $val  = $this->opt( 'enc_encode_method' );
        $opts = array(
            'mixed'   => __( 'Mieszany decimal+hex (zalecany)', 'essa-wp-suite' ),
            'decimal' => __( 'Decimal (&#65;)', 'essa-wp-suite' ),
            'hex'     => __( 'Hex (&#x41;)', 'essa-wp-suite' ),
        );
        echo '<select name="ewps_enc_settings[enc_encode_method]" id="ewps_enc_encode_method">';
        foreach ( $opts as $k => $l ) printf( '<option value="%s" %s>%s</option>', esc_attr( $k ), selected( $val, $k, false ), esc_html( $l ) );
        echo '</select>';
    }

    public function enc_field_priority( $a ) {
        printf( '<input type="number" name="ewps_enc_settings[enc_filter_priority]" value="%s" min="1" max="9999" style="width:80px"><p class="description">%s</p>',
            esc_attr( $this->opt( 'enc_filter_priority' ) ),
            esc_html__( 'Domyślnie 100. Zwiększ, jeśli inne wtyczki nadpisują treść po kodowaniu.', 'essa-wp-suite' ) );
    }

    // ─── AJAX ────────────────────────────────────────────────────────────────

    public function ajax_test_encode() {
        check_ajax_referer( 'ewps_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();
        $email = sanitize_text_field( wp_unslash( $_POST['email'] ?? '' ) );
        wp_send_json_success( array( 'encoded' => $this->encoder_core->encode_str( $email ) ) );
    }

    public function ajax_toggle_module() {
        check_ajax_referer( 'ewps_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( __( 'Brak uprawnień.', 'essa-wp-suite' ) );

        $module_id = sanitize_key( $_POST['module'] ?? '' );
        $modules   = $this->get_modules();
        if ( ! isset( $modules[ $module_id ] ) ) wp_send_json_error( __( 'Nieznany moduł.', 'essa-wp-suite' ) );
        $meta = $modules[ $module_id ];
        if ( ! empty( $meta['locked'] ) ) wp_send_json_error( __( 'Ten moduł jest w wersji Pro.', 'essa-wp-suite' ) );

        $opts = get_option( $meta['option_key'], array() );
        $opts = is_array( $opts ) ? $opts : array();
        $new  = empty( $opts[ $meta['toggle_key'] ] ) ? '1' : '0';
        $opts[ $meta['toggle_key'] ] = $new;
        update_option( $meta['option_key'], $opts );

        wp_send_json_success( array( 'active' => '1' === $new, 'module' => $module_id ) );
    }

    // ─── Assets ──────────────────────────────────────────────────────────────

    public function admin_assets( $hook ) {
        if ( 'toplevel_page_ewps-settings' !== $hook ) return;
        wp_enqueue_style( 'ewps-admin', EWPS_PLUGIN_URL . 'admin/admin.css', array(), EWPS_VERSION );
        wp_enqueue_script( 'ewps-admin', EWPS_PLUGIN_URL . 'admin/admin.js', array( 'jquery' ), EWPS_VERSION, true );
        wp_localize_script( 'ewps-admin', 'EWPS', array(
            'nonce'    => wp_create_nonce( 'ewps_ajax' ),
            'ajax'     => admin_url( 'admin-ajax.php' ),
            'base_url' => ESSA_Suite_Utils::tab_url(),
            'i18n'     => array(
                'saving'      => __( 'Zapisywanie…', 'essa-wp-suite' ),
                'active'      => __( 'Aktywny', 'essa-wp-suite' ),
                'inactive'    => __( 'Nieaktywny', 'essa-wp-suite' ),
                'error'       => __( 'Błąd. Spróbuj ponownie.', 'essa-wp-suite' ),
                'activeOf'    => __( 'aktywnych', 'essa-wp-suite' ),
                'maintOn'     => __( 'Maintenance Mode jest aktywny! Odwiedzający widzą stronę techniczną.', 'essa-wp-suite' ),
                'encode'      => __( 'Koduj', 'essa-wp-suite' ),
                'sending'     => __( 'Wysyłanie…', 'essa-wp-suite' ),
                'sendTest'    => __( 'Wyślij test', 'essa-wp-suite' ),
                'sentTo'      => __( 'Email wysłany do:', 'essa-wp-suite' ),
                'errorPrefix' => __( 'Błąd:', 'essa-wp-suite' ),
                'unknown'     => __( 'nieznany', 'essa-wp-suite' ),
                'ajaxError'   => __( 'Błąd połączenia AJAX.', 'essa-wp-suite' ),
                'confirmClean'=> __( 'Na pewno wyczyścić: %s? Operacja jest nieodwracalna.', 'essa-wp-suite' ),
                'clean'       => __( 'Wyczyść', 'essa-wp-suite' ),
            ),
        ) );
    }

    // ─── Strona ustawień ─────────────────────────────────────────────────────

    private function render_page_header( $module_label = '' ) {
        $hide_brand    = (bool) apply_filters( 'ewps_hide_branding', false );
        $dashboard_url = ESSA_Suite_Utils::tab_url();
        $modules       = $this->get_modules();
        $unlocked      = array_filter( $modules, function( $m ) { return empty( $m['locked'] ); } );
        $active_count  = count( array_filter( $unlocked, array( $this, 'is_module_active' ) ) );
        $license       = ESSA_License::get_instance();
        ?>
        <div class="ewps-header">
            <div class="ewps-header__logo">
                <span class="ewps-logo-mark">⚡</span>
                <div>
                    <h1 class="ewps-title"><?php echo esc_html( $this->menu_label() ); ?></h1>
                    <?php if ( ! $hide_brand ) : ?>
                    <span class="ewps-subtitle">by <a href="https://essaseo.pl" target="_blank" rel="noopener">ESSA SEO</a></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="ewps-header__right">
                <?php if ( $module_label ) : ?>
                    <a href="<?php echo esc_url( $dashboard_url ); ?>" class="ewps-back-btn">← <?php esc_html_e( 'Dashboard', 'essa-wp-suite' ); ?></a>
                    <span class="ewps-breadcrumb"><?php echo esc_html( $module_label ); ?></span>
                <?php else : ?>
                    <span class="ewps-modules-badge"><?php echo (int) $active_count; ?>/<?php echo (int) count( $unlocked ); ?> <?php esc_html_e( 'aktywnych', 'essa-wp-suite' ); ?></span>
                <?php endif; ?>
                <a href="<?php echo esc_url( ESSA_Suite_Utils::tab_url( 'license' ) ); ?>" class="ewps-license-badge <?php echo $license->is_valid() ? 'ewps-status--on' : 'ewps-status--off'; ?>"><?php echo $license->is_valid() ? esc_html__( 'PRO', 'essa-wp-suite' ) : esc_html__( 'FREE', 'essa-wp-suite' ); ?></a>
                <span class="ewps-version">v<?php echo esc_html( EWPS_VERSION ); ?><?php if ( defined( 'EWPS_PRO_VERSION' ) ) echo ' / Pro ' . esc_html( EWPS_PRO_VERSION ); ?></span>
            </div>
        </div>
        <?php
    }

    /** Standardowa zakładka: formularz Settings API + sidebar. */
    private function render_module_form( $group, $page, $sidebar_cb = null ) {
        ?>
        <div class="ewps-layout">
            <div class="ewps-main">
                <form method="post" action="options.php">
                    <?php settings_fields( $group ); do_settings_sections( $page ); submit_button( __( 'Zapisz', 'essa-wp-suite' ) ); ?>
                </form>
            </div>
            <div class="ewps-sidebar"><?php if ( is_callable( $sidebar_cb ) ) call_user_func( $sidebar_cb ); ?></div>
        </div>
        <?php
    }

    private function render_locked( $mod ) {
        ?>
        <div class="ewps-layout">
            <div class="ewps-main">
                <div class="ewps-card ewps-card--locked">
                    <h2><?php echo esc_html( $mod['icon'] . ' ' . $mod['label'] ); ?> <span class="ewps-menu-pro">PRO</span></h2>
                    <p><?php echo esc_html( $mod['description'] ); ?></p>
                    <ul>
                        <?php foreach ( (array) $mod['features'] as $f ) : ?><li>✓ <?php echo esc_html( $f ); ?></li><?php endforeach; ?>
                    </ul>
                    <p>
                        <a href="<?php echo esc_url( ESSA_License::shop_url() ); ?>" class="button button-primary" target="_blank" rel="noopener"><?php esc_html_e( 'Kup ESSA WP Suite Pro →', 'essa-wp-suite' ); ?></a>
                        <a href="<?php echo esc_url( ESSA_Suite_Utils::tab_url( 'license' ) ); ?>" class="button"><?php esc_html_e( 'Mam już klucz', 'essa-wp-suite' ); ?></a>
                    </p>
                </div>
            </div>
            <div class="ewps-sidebar"><?php $this->sidebar_tip( __( 'Wszystkie moduły Pro odblokowuje jeden klucz licencji. Po aktywacji wtyczka Pro instaluje się jednym kliknięciem z zakładki Licencja.', 'essa-wp-suite' ) ); ?></div>
        </div>
        <?php
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $active  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard';
        $base    = ESSA_Suite_Utils::tab_url();
        $modules = $this->get_modules();
        $current = null;
        foreach ( $modules as $mod ) {
            if ( $mod['tab'] === $active ) $current = $mod;
        }
        $label = $current ? $current['icon'] . ' ' . $current['label'] : ( 'license' === $active ? '🔑 ' . __( 'Licencja Pro', 'essa-wp-suite' ) : '' );
        $maint_active = $this->is_module_active( $modules['maintenance'] );
        $license = ESSA_License::get_instance();
        ?>
        <div class="wrap ewps-wrap">
            <?php $this->render_page_header( $label ); ?>

            <?php if ( isset( $_GET['unlocked'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'IP zostało odblokowane.', 'essa-wp-suite' ); ?></p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['dc_deleted'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php printf( esc_html__( 'Usunięto komentarzy: %d.', 'essa-wp-suite' ), (int) $_GET['dc_deleted'] ); ?></p></div>
            <?php endif; ?>
            <?php if ( $maint_active && 'dashboard' === $active ) : ?>
            <div class="notice notice-warning ewps-maint-warning"><p>🚧 <strong><?php esc_html_e( 'Maintenance Mode jest aktywny!', 'essa-wp-suite' ); ?></strong> <?php esc_html_e( 'Odwiedzający widzą stronę techniczną.', 'essa-wp-suite' ); ?></p></div>
            <?php endif; ?>
            <?php if ( 'dashboard' === $active && $license->key() && ! $license->is_valid() ) : ?>
            <div class="notice notice-error"><p><strong><?php esc_html_e( 'Licencja Pro nieaktywna:', 'essa-wp-suite' ); ?></strong> <?php echo esc_html( $license->status_label() ); ?>. <a href="<?php echo esc_url( ESSA_Suite_Utils::tab_url( 'license' ) ); ?>"><?php esc_html_e( 'Sprawdź licencję →', 'essa-wp-suite' ); ?></a></p></div>
            <?php endif; ?>
            <?php if ( 'dashboard' !== $active && 'license' !== $active ) settings_errors(); ?>

            <div class="ewps-content ewps-content--<?php echo esc_attr( 'dashboard' === $active ? 'dashboard' : 'module' ); ?>">

            <?php if ( 'dashboard' === $active ) : ?>
                <div class="ewps-dashboard">
                    <div class="ewps-module-grid">
                    <?php foreach ( $modules as $mod ) :
                        $locked    = ! empty( $mod['locked'] );
                        $is_active = $this->is_module_active( $mod );
                        $tab_url   = add_query_arg( 'tab', $mod['tab'], $base );
                    ?>
                        <div class="ewps-module-card <?php echo $locked ? 'ewps-module-card--locked' : ( $is_active ? 'ewps-module-card--active' : 'ewps-module-card--inactive' ); ?>"
                             data-module="<?php echo esc_attr( $mod['id'] ); ?>" style="--mod-color: <?php echo esc_attr( $mod['color'] ); ?>">
                            <div class="ewps-module-card__header">
                                <span class="ewps-module-card__icon"><?php echo esc_html( $mod['icon'] ); ?></span>
                                <div class="ewps-module-card__meta">
                                    <strong class="ewps-module-card__name"><?php echo esc_html( $mod['label'] ); ?><?php if ( ! empty( $mod['pro'] ) ) echo ' <span class="ewps-menu-pro">PRO</span>'; ?></strong>
                                    <span class="ewps-module-card__status <?php echo $locked ? 'ewps-status--locked' : ( $is_active ? 'ewps-status--on' : 'ewps-status--off' ); ?>">
                                        <?php echo $locked ? esc_html__( 'Wymaga Pro', 'essa-wp-suite' ) : ( $is_active ? esc_html__( 'Aktywny', 'essa-wp-suite' ) : esc_html__( 'Nieaktywny', 'essa-wp-suite' ) ); ?>
                                    </span>
                                </div>
                                <?php if ( $locked ) : ?>
                                    <span class="ewps-lock" title="<?php esc_attr_e( 'Moduł Pro', 'essa-wp-suite' ); ?>">🔒</span>
                                <?php else : ?>
                                <button type="button" class="ewps-toggle <?php echo $is_active ? 'ewps-toggle--on' : ''; ?>" data-module="<?php echo esc_attr( $mod['id'] ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Włącz/wyłącz: %s', 'essa-wp-suite' ), $mod['label'] ) ); ?>">
                                    <span class="ewps-toggle__knob"></span>
                                </button>
                                <?php endif; ?>
                            </div>
                            <p class="ewps-module-card__desc"><?php echo esc_html( $mod['description'] ); ?></p>
                            <ul class="ewps-module-card__features">
                                <?php foreach ( (array) $mod['features'] as $feat ) : ?><li><?php echo esc_html( $feat ); ?></li><?php endforeach; ?>
                            </ul>
                            <a href="<?php echo esc_url( $tab_url ); ?>" class="ewps-module-card__link"><?php echo $locked ? esc_html__( 'Zobacz →', 'essa-wp-suite' ) : esc_html__( 'Konfiguruj →', 'essa-wp-suite' ); ?></a>
                        </div>
                    <?php endforeach; ?>
                    </div>

                    <div class="ewps-dashboard__footer">
                        <div class="ewps-quick-stats">
                            <?php
                            $unlocked = array_filter( $modules, function( $m ) { return empty( $m['locked'] ); } );
                            $active_n = count( array_filter( $unlocked, array( $this, 'is_module_active' ) ) );
                            ?>
                            <div class="ewps-stat"><span class="ewps-stat__num" id="ewps-stat-active"><?php echo (int) $active_n; ?></span><span class="ewps-stat__label"><?php esc_html_e( 'Aktywne', 'essa-wp-suite' ); ?></span></div>
                            <div class="ewps-stat"><span class="ewps-stat__num" id="ewps-stat-inactive"><?php echo (int) ( count( $unlocked ) - $active_n ); ?></span><span class="ewps-stat__label"><?php esc_html_e( 'Nieaktywne', 'essa-wp-suite' ); ?></span></div>
                            <div class="ewps-stat"><span class="ewps-stat__num"><?php echo (int) ( count( $modules ) - count( $unlocked ) ); ?></span><span class="ewps-stat__label"><?php esc_html_e( 'Do odblokowania', 'essa-wp-suite' ); ?></span></div>
                            <div class="ewps-stat"><span class="ewps-stat__num"><?php echo esc_html( EWPS_VERSION ); ?></span><span class="ewps-stat__label"><?php esc_html_e( 'Wersja', 'essa-wp-suite' ); ?></span></div>
                        </div>
                        <?php if ( ! $license->is_valid() ) : ?>
                        <p class="ewps-upsell"><a href="<?php echo esc_url( ESSA_License::shop_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Odblokuj 7 modułów Pro jednym kluczem →', 'essa-wp-suite' ); ?></a></p>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif ( 'license' === $active ) : ?>
                <?php $license->render_tab(); ?>

            <?php elseif ( $current && ! empty( $current['locked'] ) ) : ?>
                <?php $this->render_locked( $current ); ?>

            <?php elseif ( $current && ! empty( $current['render'] ) && is_callable( $current['render'] ) ) : ?>
                <?php call_user_func( $current['render'] ); ?>

            <?php elseif ( $current && ! empty( $current['group'] ) ) : ?>
                <?php $this->render_module_form( $current['group'], $current['page'], isset( $current['sidebar'] ) ? $current['sidebar'] : null ); ?>

            <?php else : ?>
                <p><?php esc_html_e( 'Nieznana zakładka.', 'essa-wp-suite' ); ?> <a href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'Wróć do dashboardu', 'essa-wp-suite' ); ?></a></p>
            <?php endif; ?>

            </div>
        </div>
        <?php
    }

    public function sidebar_tip( $text ) {
        echo '<div class="ewps-card ewps-card--tip"><p>' . esc_html( $text ) . '</p></div>';
    }

    /** Pomocnik dla Pro: standardowy formularz zakładki. */
    public function render_settings_form( $group, $page, $sidebar_cb = null ) {
        $this->render_module_form( $group, $page, $sidebar_cb );
    }

    // ─── Aktywacja / dezaktywacja ────────────────────────────────────────────

    public function on_activate() {
        if ( false === get_option( 'ewps_enc_settings' ) ) update_option( 'ewps_enc_settings', $this->defaults );
        update_option( 'ewps_version', EWPS_VERSION, false );
    }

    public function on_deactivate() {}

    public function load_textdomain() {
        load_plugin_textdomain( 'essa-wp-suite', false, dirname( EWPS_BASENAME ) . '/languages' );
    }
}

ESSA_WP_Suite::get_instance();
