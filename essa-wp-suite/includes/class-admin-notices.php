<?php
/**
 * ESSA WP Suite — Admin Notices Manager
 *
 * Chowa powiadomienia wtyczek (info / success / opcjonalnie warning) do panelu
 * pod ikoną 🔔 w pasku admina. Błędy (.notice-error) i klasy z whitelisty
 * zostają na miejscu. Nic nie jest przechwytywane po stronie PHP — to gwarantuje,
 * że żaden komunikat nie zginie, gdy JS się nie załaduje.
 *
 * PHP 7.2+
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'ESSA_Admin_Notices' ) ) :

class ESSA_Admin_Notices {

    private static $instance = null;

    protected $defaults = array(
        'notices_enabled'       => '0',
        'notices_hide_info'     => '1',   // .notice-info
        'notices_hide_success'  => '1',   // .notice-success
        'notices_hide_warning'  => '0',   // .notice-warning (domyślnie widoczne)
        'notices_keep_errors'   => '1',   // .notice-error zawsze widoczne
        'notices_block_popups'  => '1',   // CSS blokada overlayów wtyczek
        'notices_whitelist'     => '',    // klasy CSS, które zawsze przepuszczamy
    );

    protected $opts = array();

    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $this->opts = wp_parse_args( get_option( 'ewps_notices_settings', array() ), $this->defaults );
        if ( ! $this->opt( 'notices_enabled' ) ) return;
        if ( ! is_admin() ) return;
        $this->init();
    }

    private function opt( $k ) {
        return isset( $this->opts[ $k ] ) ? $this->opts[ $k ] : ( isset( $this->defaults[ $k ] ) ? $this->defaults[ $k ] : '' );
    }

    private function init() {
        add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_button' ), 999 );
        add_action( 'admin_head',     array( $this, 'output_css' ) );
        add_action( 'admin_footer',   array( $this, 'output_js' ) );
    }

    // ─── Admin bar ───────────────────────────────────────────────────────────

    public function add_admin_bar_button( $wp_admin_bar ) {
        if ( ! is_admin() ) return;
        $wp_admin_bar->add_node( array(
            'id'    => 'ewps-notices-toggle',
            'title' => '<span class="ewps-notices-icon">🔔</span> <span class="ewps-notices-count" style="display:none">0</span>',
            'href'  => '#',
            'meta'  => array(
                'title' => __( 'Hidden plugin notices', 'essa-wp-suite' ),
                'class' => 'ewps-notices-bar-item',
            ),
        ) );
    }

    private function get_whitelist_classes() {
        $raw  = $this->opt( 'notices_whitelist' );
        $list = preg_split( '/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );
        return array_values( array_filter( array_map( 'sanitize_html_class', $list ) ) );
    }

    /** Selektory notices, które chowamy (bez whitelisty i bez błędów). */
    private function hidden_selectors() {
        $wl_not = '';
        foreach ( $this->get_whitelist_classes() as $cls ) $wl_not .= ':not(.' . $cls . ')';
        if ( $this->opt( 'notices_keep_errors' ) ) $wl_not .= ':not(.notice-error)';

        $sel = array();
        if ( $this->opt( 'notices_hide_info' ) )    $sel[] = '.notice.notice-info' . $wl_not;
        if ( $this->opt( 'notices_hide_success' ) ) $sel[] = '.notice.notice-success' . $wl_not;
        if ( $this->opt( 'notices_hide_warning' ) ) $sel[] = '.notice.notice-warning' . $wl_not;
        return $sel;
    }

    // ─── CSS ─────────────────────────────────────────────────────────────────

    public function output_css() {
        $selectors = $this->hidden_selectors();
        ?>
        <style id="ewps-notices-css">
        #ewps-notices-panel{display:none;position:fixed;top:46px;right:0;width:480px;max-width:95vw;max-height:80vh;overflow-y:auto;background:#1e1e2e;border:1px solid #313244;border-radius:0 0 0 10px;box-shadow:-4px 8px 32px rgba(0,0,0,.5);z-index:99999;padding:0}
        #ewps-notices-panel.is-open{display:block;animation:ewpsSlideIn .2s ease-out}
        @keyframes ewpsSlideIn{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
        #ewps-notices-panel-header{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid #313244;background:#181825;position:sticky;top:0;z-index:1}
        #ewps-notices-panel-header strong{color:#cdd6f4;font-size:13px}
        #ewps-notices-close{background:none;border:none;color:#6c7086;cursor:pointer;font-size:18px;line-height:1;padding:0 4px}
        #ewps-notices-close:hover{color:#cdd6f4}
        #ewps-notices-list{padding:12px 14px}
        #ewps-notices-list .notice,#ewps-notices-list .updated,#ewps-notices-list .update-nag{display:block!important;margin:0 0 10px!important;border-radius:6px!important;font-size:13px!important;background:#24273a!important;border-left-color:#89b4fa!important;color:#cdd6f4!important}
        #ewps-notices-list .notice-info{border-left-color:#89b4fa!important}
        #ewps-notices-list .notice-success{border-left-color:#a6e3a1!important}
        #ewps-notices-list .notice-warning{border-left-color:#f9e2af!important}
        #ewps-notices-list .notice p,#ewps-notices-list .notice-dismiss{color:inherit!important}
        #ewps-notices-list .notice a{color:#89dceb!important}
        #ewps-notices-list p.ewps-empty{color:#6c7086;font-size:13px;text-align:center;padding:20px;margin:0}
        #wp-admin-bar-ewps-notices-toggle .ewps-notices-count{display:inline-block;background:#f38ba8;color:#1e1e2e;border-radius:10px;padding:0 6px;font-size:11px;font-weight:700;min-width:18px;text-align:center;line-height:18px;vertical-align:middle;margin-left:4px}
        #wp-admin-bar-ewps-notices-toggle > a{display:flex!important;align-items:center;gap:4px}
        <?php if ( ! empty( $selectors ) ) : ?>
        /* Chowane od razu (bez migotania); JS przenosi je do panelu. Poza panelem wtyczki i poza edytorem blokowym. */
        body:not(.block-editor-page) #wpbody-content <?php echo implode( ', body:not(.block-editor-page) #wpbody-content ', $selectors ); // phpcs:ignore WordPress.Security.EscapeOutput ?>{display:none!important}
        .ewps-wrap .notice{display:block!important}
        <?php endif; ?>
        <?php if ( $this->opt( 'notices_block_popups' ) ) : ?>
        /* Overlaye promocyjne popularnych wtyczek */
        .elementor-nps-survey-dialog,#e-addons-promotion-widget,
        .woocommerce-marketplace-banner,.woocommerce-marketing-coupon-notice,
        #wpseo-dismiss-about,.yoast-alert-portal,
        .aioseo-notification-alerts,
        .rank-math-notice-setup-wizard,#rank-math-review-notice,
        .nf-promo,.wpcf7-admin-promo,
        .notice[class*="review-notice"],.notice[class*="rate-us"],.notice[class*="rating-notice"],
        .notice[class*="-upsell"],.notice[class*="-premium"],.notice[class*="-upgrade"]{display:none!important}
        <?php endif; ?>
        </style>
        <?php
    }

    // ─── JS ──────────────────────────────────────────────────────────────────

    public function output_js() {
        $cfg = array(
            'hideInfo'    => (bool) $this->opt( 'notices_hide_info' ),
            'hideSuccess' => (bool) $this->opt( 'notices_hide_success' ),
            'hideWarning' => (bool) $this->opt( 'notices_hide_warning' ),
            'keepErrors'  => (bool) $this->opt( 'notices_keep_errors' ),
            'whitelist'   => $this->get_whitelist_classes(),
            'title'       => __( 'Plugin notices', 'essa-wp-suite' ),
            'close'       => __( 'Close', 'essa-wp-suite' ),
            'empty'       => __( 'No hidden notices.', 'essa-wp-suite' ),
        );
        ?>
        <script id="ewps-notices-js">
        (function () {
            var cfg = <?php echo wp_json_encode( $cfg ); ?>;
            if (document.body.classList.contains('block-editor-page')) { return; }

            function shouldHide(el) {
                if (!el || !el.classList) { return false; }
                if (el.closest('#ewps-notices-panel') || el.closest('.ewps-wrap')) { return false; }
                for (var i = 0; i < cfg.whitelist.length; i++) {
                    if (el.classList.contains(cfg.whitelist[i])) { return false; }
                }
                if (cfg.keepErrors && el.classList.contains('notice-error')) { return false; }
                if (cfg.hideInfo    && el.classList.contains('notice-info'))    { return true; }
                if (cfg.hideSuccess && el.classList.contains('notice-success')) { return true; }
                if (cfg.hideWarning && el.classList.contains('notice-warning')) { return true; }
                return false;
            }

            function buildPanel() {
                var panel = document.createElement('div');
                panel.id  = 'ewps-notices-panel';
                var header = document.createElement('div');
                header.id = 'ewps-notices-panel-header';
                var strong = document.createElement('strong');
                strong.textContent = '🔔 ' + cfg.title;
                var close = document.createElement('button');
                close.id = 'ewps-notices-close';
                close.type = 'button';
                close.title = cfg.close;
                close.textContent = '✕';
                header.appendChild(strong);
                header.appendChild(close);
                var list = document.createElement('div');
                list.id = 'ewps-notices-list';
                panel.appendChild(header);
                panel.appendChild(list);
                document.body.appendChild(panel);

                close.addEventListener('click', function () { panel.classList.remove('is-open'); });
                document.addEventListener('click', function (e) {
                    var bar = document.getElementById('wp-admin-bar-ewps-notices-toggle');
                    if (!panel.contains(e.target) && bar && !bar.contains(e.target)) { panel.classList.remove('is-open'); }
                });
                return list;
            }

            function moveNotices(list) {
                var count = 0;
                var notices = document.querySelectorAll('#wpbody-content .notice');
                Array.prototype.forEach.call(notices, function (el) {
                    if (!shouldHide(el)) { return; }
                    var dismiss = el.querySelector('.notice-dismiss');
                    if (dismiss) { dismiss.parentNode.removeChild(dismiss); }
                    el.classList.add('ewps-moved');
                    list.appendChild(el); // przenosimy oryginał, nie klon — linki i formularze w notice działają
                    count++;
                });
                if (count === 0 && !list.children.length) {
                    var p = document.createElement('p');
                    p.className = 'ewps-empty';
                    p.textContent = cfg.empty;
                    list.appendChild(p);
                }
                var badge = document.querySelector('#wp-admin-bar-ewps-notices-toggle .ewps-notices-count');
                if (badge) {
                    badge.textContent = count;
                    badge.style.display = count > 0 ? 'inline-block' : 'none';
                }
            }

            function bindToggle() {
                var barItem = document.getElementById('wp-admin-bar-ewps-notices-toggle');
                var panel   = document.getElementById('ewps-notices-panel');
                if (!barItem || !panel) { return; }
                var link = barItem.querySelector('a');
                if (!link) { return; }
                link.addEventListener('click', function (e) {
                    e.preventDefault();
                    panel.classList.toggle('is-open');
                });
            }

            function init() {
                var list = buildPanel();
                moveNotices(list);
                bindToggle();
            }

            if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
        })();
        </script>
        <?php
    }

    // ─── Settings ────────────────────────────────────────────────────────────

    public function register_settings() {
        register_setting( 'ewps_notices_settings_group', 'ewps_notices_settings', array( $this, 'sanitize' ) );
        add_settings_section( 'ewps_notices_main', '', '__return_false', 'ewps-notices-settings' );

        $fields = array(
            'notices_enabled'      => array( __( 'Enable module', 'essa-wp-suite' ),                                     'field_checkbox' ),
            'notices_hide_info'    => array( __( 'Hide info notices (blue)', 'essa-wp-suite' ),  'field_checkbox' ),
            'notices_hide_success' => array( __( 'Hide success notices (green)', 'essa-wp-suite' ),         'field_checkbox' ),
            'notices_hide_warning' => array( __( 'Hide warnings (yellow)', 'essa-wp-suite' ),                     'field_checkbox' ),
            'notices_keep_errors'  => array( __( 'Always show errors (red)', 'essa-wp-suite' ),                'field_checkbox' ),
            'notices_block_popups' => array( __( 'Block plugin pop-ups and overlays (CSS)', 'essa-wp-suite' ),         'field_checkbox' ),
            'notices_whitelist'    => array( __( 'CSS class whitelist (always visible)', 'essa-wp-suite' ),            'field_textarea' ),
        );

        foreach ( $fields as $id => $data ) {
            add_settings_field( 'ewps_' . $id, $data[0], array( $this, $data[1] ), 'ewps-notices-settings', 'ewps_notices_main', array( 'id' => $id ) );
        }
    }

    public function sanitize( $in ) {
        $in = is_array( $in ) ? $in : array();
        $c  = array();
        foreach ( array( 'notices_enabled', 'notices_hide_info', 'notices_hide_success', 'notices_hide_warning', 'notices_keep_errors', 'notices_block_popups' ) as $k ) {
            $c[ $k ] = ! empty( $in[ $k ] ) ? '1' : '0';
        }
        $c['notices_whitelist'] = sanitize_textarea_field( $in['notices_whitelist'] ?? '' );
        return $c;
    }

    public function field_checkbox( $a ) {
        $id = $a['id'];
        printf( '<input type="checkbox" id="ewps_%1$s" name="ewps_notices_settings[%1$s]" value="1" %2$s>',
            esc_attr( $id ), checked( '1', $this->opt( $id ), false ) );
    }

    public function field_textarea( $a ) {
        $id = $a['id'];
        printf(
            '<textarea id="ewps_%s" name="ewps_notices_settings[%s]" rows="3" class="regular-text">%s</textarea>
             <p class="description">%s</p>',
            esc_attr( $id ), esc_attr( $id ), esc_textarea( $this->opt( $id ) ),
            esc_html__( 'For example "woocommerce-message, tribe-notice" — notices with these classes always stay visible.', 'essa-wp-suite' )
        );
    }
}

endif;
