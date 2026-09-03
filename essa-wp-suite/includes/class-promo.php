<?php
/**
 * ESSA WP Suite — promocja usług ESSA SEO w panelu wtyczki.
 *
 * Treść przychodzi z serwera licencji (GET {lic}/v1/promo → promo.json, edytowalny na VPS
 * bez wydawania nowej wersji wtyczki), cache 12 h w transiencie; przy braku połączenia
 * działa wersja wbudowana. White Label (Pro) chowa całą promocję przez filtr `ewps_hide_branding`.
 *
 * Gdzie widać:
 *  - dashboard: sekcja „Potrzebujesz więcej niż wtyczki?” z kartami usług + „Moduł na życzenie”
 *  - zakładki modułów: mała karta w sidebarze
 *  - jednorazowe powiadomienie po aktywacji (z przyciskiem „Nie pokazuj więcej”)
 *
 * PHP 7.2+
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'ESSA_Promo' ) ) :

class ESSA_Promo {

    private static $instance = null;

    const CACHE_KEY = 'ewps_promo';
    const CACHE_TTL = 12 * HOUR_IN_SECONDS;
    const DISMISS   = 'ewps_welcome_dismissed';

    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_notices', array( $this, 'welcome_notice' ) );
        add_action( 'admin_init',    array( $this, 'handle_dismiss' ) );
    }

    public static function hidden() {
        return (bool) apply_filters( 'ewps_hide_branding', false );
    }

    /** Wbudowana treść — używana, gdy serwer nie odpowiada. */
    public static function defaults() {
        return array(
            'headline' => __( 'Need more than a plugin?', 'essa-wp-suite' ),
            'intro'    => __( 'ESSA WP Suite is the toolkit we run on client sites. We can also rank, build or advertise the site itself.', 'essa-wp-suite' ),
            'services' => array(
                array(
                    'icon'  => '📈',
                    'title' => __( 'Search engine optimisation', 'essa-wp-suite' ),
                    'text'  => __( 'Free audit and action plan, quote within 24 h. Ongoing work from PLN 1,500 net per month, three-month contract, then one month\'s notice.', 'essa-wp-suite' ),
                    'url'   => 'https://essaseo.pl/pozycjonowanie-stron-internetowych/',
                    'cta'   => __( 'See the offer', 'essa-wp-suite' ),
                ),
                array(
                    'icon'  => '📍',
                    'title' => __( 'Google Business Profile', 'essa-wp-suite' ),
                    'text'  => __( 'Ranking in Google Maps: visibility measured on a grid of points across the city, reviews, photos, posts. From PLN 599 per month.', 'essa-wp-suite' ),
                    'url'   => 'https://essaseo.pl/pozycjonowanie-wizytowki-google-seo-dla-google-maps/',
                    'cta'   => __( 'See the offer', 'essa-wp-suite' ),
                ),
                array(
                    'icon'  => '🎯',
                    'title' => __( 'Google Ads and Meta Ads', 'essa-wp-suite' ),
                    'text'  => __( 'Campaigns with the budget paid straight to Google and Meta. Flat fee from PLN 500 plus a percentage of the budget, two creatives to start and a new one every month.', 'essa-wp-suite' ),
                    'url'   => 'https://essaseo.pl/google-ads-meta-ads/',
                    'cta'   => __( 'See the offer', 'essa-wp-suite' ),
                ),
                array(
                    'icon'  => '🧱',
                    'title' => __( 'Websites', 'essa-wp-suite' ),
                    'text'  => __( 'WordPress and Elementor, fast and SEO-ready from day one. With this plugin on board.', 'essa-wp-suite' ),
                    'url'   => 'https://essaseo.pl/tworzenie-stron-internetowych/',
                    'cta'   => __( 'See the offer', 'essa-wp-suite' ),
                ),
            ),
            'custom'   => array(
                'title' => __( 'Custom module', 'essa-wp-suite' ),
                'text'  => __( 'Missing a feature? We build ESSA WP Suite modules to order: integrations, automations, custom reports. Tell us what you need and we will quote it within 24 h.', 'essa-wp-suite' ),
                'url'   => 'https://essaseo.pl/kontakt/?temat=modul-essa-wp-suite',
                'cta'   => __( 'Order a module', 'essa-wp-suite' ),
            ),
            'sidebar'  => array(
                'title' => __( 'ESSA SEO', 'essa-wp-suite' ),
                'text'  => __( 'SEO, Google Business Profile, Google Ads, websites. Free consultation and a quote within 24 h.', 'essa-wp-suite' ),
                'url'   => 'https://essaseo.pl/kontakt/?temat=wtyczka',
                'cta'   => __( 'Free consultation', 'essa-wp-suite' ),
            ),
            'blog'     => array(
                'title' => __( 'ESSA SEO blog', 'essa-wp-suite' ),
                'url'   => 'https://essaseo.pl/blog/',
            ),
        );
    }

    /** Treść z serwera z cache; klucze nieznane serwerowi dobierane z wbudowanej wersji. */
    public function items() {
        $cached = get_site_transient( self::CACHE_KEY );
        if ( ! is_array( $cached ) ) {
            $cached = array();
            $res = wp_remote_get( ESSA_License::server_url() . '/v1/promo', array(
                'timeout'    => 6,
                'headers'    => array( 'Accept' => 'application/json' ),
                'user-agent' => 'ESSA-WP-Suite/' . EWPS_VERSION . '; ' . home_url(),
            ) );
            if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
                $json = json_decode( wp_remote_retrieve_body( $res ), true );
                if ( is_array( $json ) && ! empty( $json['services'] ) ) $cached = $json;
            }
            set_site_transient( self::CACHE_KEY, $cached, self::CACHE_TTL );
        }
        $items = array_merge( self::defaults(), array_filter( $cached, function( $v ) { return ! empty( $v ); } ) );
        return apply_filters( 'ewps_promo_items', $items );
    }

    private static function url( $u ) {
        return esc_url( add_query_arg( array( 'utm_source' => 'essa-wp-suite', 'utm_medium' => 'plugin', 'utm_campaign' => 'admin' ), $u ) );
    }

    // ─── Dashboard ───────────────────────────────────────────────────────────

    public function render_dashboard_section() {
        if ( self::hidden() ) return;
        $p = $this->items();
        ?>
        <div class="ewps-promo">
            <div class="ewps-promo__head">
                <h2><?php echo esc_html( $p['headline'] ); ?></h2>
                <p><?php echo esc_html( $p['intro'] ); ?></p>
            </div>
            <div class="ewps-promo__grid">
                <?php foreach ( (array) $p['services'] as $s ) : ?>
                <a class="ewps-promo__card" href="<?php echo self::url( $s['url'] ); ?>" target="_blank" rel="noopener">
                    <span class="ewps-promo__icon"><?php echo esc_html( $s['icon'] ); ?></span>
                    <strong><?php echo esc_html( $s['title'] ); ?></strong>
                    <span class="ewps-promo__text"><?php echo esc_html( $s['text'] ); ?></span>
                    <span class="ewps-promo__cta"><?php echo esc_html( $s['cta'] ); ?> →</span>
                </a>
                <?php endforeach; ?>
                <a class="ewps-promo__card ewps-promo__card--custom" href="<?php echo self::url( $p['custom']['url'] ); ?>" target="_blank" rel="noopener">
                    <span class="ewps-promo__icon">🧩</span>
                    <strong><?php echo esc_html( $p['custom']['title'] ); ?></strong>
                    <span class="ewps-promo__text"><?php echo esc_html( $p['custom']['text'] ); ?></span>
                    <span class="ewps-promo__cta"><?php echo esc_html( $p['custom']['cta'] ); ?> →</span>
                </a>
            </div>
            <p class="ewps-promo__foot">
                <a href="<?php echo self::url( $p['blog']['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $p['blog']['title'] ); ?></a>
                · <a href="<?php echo self::url( 'https://essaseo.pl/' ); ?>" target="_blank" rel="noopener">essaseo.pl</a>
            </p>
        </div>
        <?php
    }

    // ─── Sidebar zakładek ────────────────────────────────────────────────────

    public function render_sidebar_card() {
        if ( self::hidden() ) return;
        $s = $this->items();
        $s = $s['sidebar'];
        ?>
        <div class="ewps-card ewps-card--promo">
            <h3>⚡ <?php echo esc_html( $s['title'] ); ?></h3>
            <p><?php echo esc_html( $s['text'] ); ?></p>
            <p><a class="button button-primary" href="<?php echo self::url( $s['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $s['cta'] ); ?></a></p>
        </div>
        <?php
    }

    // ─── Powiadomienie powitalne (raz) ───────────────────────────────────────

    public function welcome_notice() {
        if ( self::hidden() || get_option( self::DISMISS ) || ! current_user_can( 'manage_options' ) ) return;
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'dashboard', 'toplevel_page_ewps-settings' ), true ) ) return;
        $dismiss = wp_nonce_url( add_query_arg( 'ewps_dismiss_welcome', '1' ), 'ewps_dismiss_welcome' );
        ?>
        <div class="notice notice-info ewps-welcome">
            <p><strong>ESSA WP Suite</strong> — <?php esc_html_e( 'thanks for installing! You switch modules on from the plugin dashboard. Need SEO, advertising or a module built to order?', 'essa-wp-suite' ); ?>
                <a href="<?php echo esc_url( ESSA_Suite_Utils::tab_url() ); ?>"><?php esc_html_e( 'Dashboard', 'essa-wp-suite' ); ?></a> ·
                <a href="<?php echo self::url( 'https://essaseo.pl/kontakt/?temat=wtyczka' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Free consultation with ESSA SEO', 'essa-wp-suite' ); ?></a> ·
                <a href="<?php echo esc_url( $dismiss ); ?>"><?php esc_html_e( 'Do not show again', 'essa-wp-suite' ); ?></a>
            </p>
        </div>
        <?php
    }

    public function handle_dismiss() {
        if ( empty( $_GET['ewps_dismiss_welcome'] ) || ! current_user_can( 'manage_options' ) ) return;
        check_admin_referer( 'ewps_dismiss_welcome' );
        update_option( self::DISMISS, time(), false );
        wp_safe_redirect( remove_query_arg( array( 'ewps_dismiss_welcome', '_wpnonce' ) ) );
        exit;
    }
}

endif;
