<?php
/**
 * ESSA Disable Comments — moduł wyłączania komentarzy.
 *
 * Funkcje:
 *  - Wyłącz komentarze globalnie lub per typ postu
 *  - Ukryj cały interfejs komentarzy w adminie
 *  - Usuń X-Pingback, wyłącz pingbacki
 *  - Wyłącz feedy komentarzy
 *  - Wyłącz komentarze przez REST API i XML-RPC
 *  - Narzędzie do usuwania komentarzy z bazy
 *
 * PHP 7.2+, brak zewnętrznych zależności.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'ESSA_Disable_Comments' ) ) :

class ESSA_Disable_Comments {

    /** @var ESSA_Disable_Comments */
    private static $instance = null;

    /** Domyślne ustawienia */
    protected $defaults = array(
        'dc_enabled'          => '0',           // włącz moduł
        'dc_scope'            => 'all',          // all | selected
        'dc_post_types'       => array(),        // lista typów gdy scope=selected
        'dc_disable_rest'     => '0',            // blokada REST API
        'dc_disable_xmlrpc'   => '0',            // blokada XML-RPC
        'dc_hide_admin_ui'    => '1',            // ukryj UI admina
        'dc_remove_feeds'     => '1',            // usuń feedy
    );

    /** @var array */
    protected $opts = array();

    /** @var array Typy postów wspierające komentarze */
    private $post_types = array();

    // ── Singleton ─────────────────────────────

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->opts = wp_parse_args(
            get_option( 'eee_dc_settings', array() ),
            $this->defaults
        );

        if ( ! $this->opt( 'dc_enabled' ) ) {
            return;
        }

        // Frontend + backend hooks
        add_action( 'plugins_loaded', array( $this, 'setup' ), 9 );
    }

    public function setup() {
        $this->load_post_types();

        // ── Wyłącz komentarze ─────────────────

        add_filter( 'comments_open',          array( $this, 'filter_comments_status' ), 20, 2 );
        add_filter( 'pings_open',             array( $this, 'filter_comments_status' ), 20, 2 );
        add_filter( 'comments_array',         array( $this, 'filter_comments_array' ),  20, 2 );

        // Usuń template komentarzy
        add_filter( 'comments_template',      array( $this, 'empty_comments_template' ), 20 );

        // ── Pingbacki / X-Pingback ────────────

        add_filter( 'xmlrpc_methods',         array( $this, 'filter_xmlrpc_methods' ) );
        add_filter( 'wp_headers',             array( $this, 'remove_x_pingback' ) );
        add_action( 'pre_ping',               array( $this, 'disable_pings' ) );

        // ── REST API ──────────────────────────

        if ( $this->opt( 'dc_disable_rest' ) ) {
            add_filter( 'rest_endpoints',     array( $this, 'filter_rest_endpoints' ) );
            add_filter( 'rest_pre_insert_comment', array( $this, 'block_rest_comment_insert' ), 10, 2 );
        }

        // ── XML-RPC komentarze ────────────────

        if ( $this->opt( 'dc_disable_xmlrpc' ) ) {
            add_filter( 'xmlrpc_methods', array( $this, 'filter_xmlrpc_comment_methods' ) );
        }

        // ── Feedy komentarzy ──────────────────

        if ( $this->opt( 'dc_remove_feeds' ) ) {
            add_action( 'template_redirect', array( $this, 'redirect_comment_feeds' ) );
            remove_action( 'wp_head', 'feed_links_extra', 3 );
        }

        // ── Admin UI ──────────────────────────

        if ( is_admin() && $this->opt( 'dc_hide_admin_ui' ) ) {
            add_action( 'admin_init',          array( $this, 'admin_init' ) );
            add_action( 'admin_menu',          array( $this, 'hide_admin_menus' ), 9999 );
            add_action( 'admin_bar_menu',      array( $this, 'hide_admin_bar_items' ), 9999 );
            add_action( 'wp_dashboard_setup',  array( $this, 'hide_dashboard_widgets' ) );
            add_filter( 'wp_count_comments',   array( $this, 'filter_comment_count' ) );
        }

        // Ukryj komentarze w nagłówku strony admina
        add_action( 'wp_head', array( $this, 'hide_existing_comments_css' ) );
    }

    // ── Helpers ───────────────────────────────

    private function opt( $key ) {
        return isset( $this->opts[ $key ] ) ? $this->opts[ $key ] : ( isset( $this->defaults[ $key ] ) ? $this->defaults[ $key ] : '' );
    }

    private function load_post_types() {
        $this->post_types = get_post_types( array( 'public' => true ), 'objects' );
        // Odfiltruj typy bez wsparcia dla komentarzy
        foreach ( $this->post_types as $slug => $obj ) {
            if ( ! post_type_supports( $slug, 'comments' ) ) {
                unset( $this->post_types[ $slug ] );
            }
        }
    }

    /**
     * Czy dany post/typ postu jest objęty wyłączeniem?
     */
    private function is_disabled( $post_id = null ) {
        $scope = $this->opt( 'dc_scope' );

        if ( 'all' === $scope ) {
            return true;
        }

        if ( $post_id ) {
            $post_type = get_post_type( $post_id );
            $selected  = (array) $this->opt( 'dc_post_types' );
            return in_array( $post_type, $selected, true );
        }

        return false;
    }

    // ── Filtry komentarzy ─────────────────────

    public function filter_comments_status( $open, $post_id ) {
        return $this->is_disabled( $post_id ) ? false : $open;
    }

    public function filter_comments_array( $comments, $post_id ) {
        return $this->is_disabled( $post_id ) ? array() : $comments;
    }

    public function empty_comments_template( $template ) {
        if ( 'all' !== $this->opt( 'dc_scope' ) ) {
            global $post;
            if ( ! $post || ! $this->is_disabled( $post->ID ) ) {
                return $template;
            }
        }
        // Zwróć pusty template
        return EWPS_PLUGIN_DIR . 'includes/empty-comments.php';
    }

    // ── Pingbacki ─────────────────────────────

    public function filter_xmlrpc_methods( $methods ) {
        unset( $methods['pingback.ping'] );
        unset( $methods['pingback.extensions.getPingbacks'] );
        return $methods;
    }

    public function remove_x_pingback( $headers ) {
        unset( $headers['X-Pingback'] );
        return $headers;
    }

    public function disable_pings( &$links ) {
        $links = array();
    }

    // ── REST API ──────────────────────────────

    public function filter_rest_endpoints( $endpoints ) {
        $comment_endpoints = array(
            '/wp/v2/comments',
            '/wp/v2/comments/(?P<id>[\d]+)',
        );
        foreach ( $comment_endpoints as $ep ) {
            if ( isset( $endpoints[ $ep ] ) ) {
                unset( $endpoints[ $ep ] );
            }
        }
        return $endpoints;
    }

    public function block_rest_comment_insert( $prepared_comment, $request ) {
        return new WP_Error(
            'rest_comment_disabled',
            __( 'Comments are disabled on this site.', 'essa-wp-suite' ),
            array( 'status' => 403 )
        );
    }

    // ── XML-RPC ───────────────────────────────

    public function filter_xmlrpc_comment_methods( $methods ) {
        $comment_methods = array(
            'wp.getComment', 'wp.getComments', 'wp.deleteComment',
            'wp.editComment', 'wp.newComment', 'wp.getCommentStatusList',
        );
        foreach ( $comment_methods as $method ) {
            unset( $methods[ $method ] );
        }
        return $methods;
    }

    // ── Feedy komentarzy ──────────────────────

    public function redirect_comment_feeds() {
        if ( is_comment_feed() ) {
            wp_redirect( home_url(), 302 );
            exit;
        }
    }

    // ── Admin UI ──────────────────────────────

    public function admin_init() {
        // Blokada dostępu do strony "edit-comments.php" gdy wszystko wyłączone
        global $pagenow;
        if ( 'all' === $this->opt( 'dc_scope' ) ) {
            if ( in_array( $pagenow, array( 'comment.php', 'edit-comments.php' ), true ) ) {
                wp_die(
                    esc_html__( 'Comments are disabled by ESSA WP Suite.', 'essa-wp-suite' ),
                    '',
                    array( 'response' => 403, 'back_link' => true )
                );
            }
        }

        // Usuń "Dyskusja" z menu ustawień jeśli globalne wyłączenie
        if ( 'all' === $this->opt( 'dc_scope' ) ) {
            remove_submenu_page( 'options-general.php', 'options-discussion.php' );
        }
    }

    public function hide_admin_menus() {
        remove_menu_page( 'edit-comments.php' );
        remove_submenu_page( 'options-general.php', 'options-discussion.php' );
    }

    public function hide_admin_bar_items( $wp_admin_bar ) {
        $wp_admin_bar->remove_node( 'comments' );
    }

    public function hide_dashboard_widgets() {
        remove_meta_box( 'dashboard_recent_comments', 'dashboard', 'normal' );
    }

    public function filter_comment_count( $stats ) {
        $empty = (object) array(
            'approved'            => 0,
            'moderated'           => 0,
            'awaiting_moderation' => 0,
            'spam'                => 0,
            'trash'               => 0,
            'post-trashed'        => 0,
            'total_comments'      => 0,
            'all'                 => 0,
        );
        return $this->is_disabled() ? $empty : $stats;
    }

    public function hide_existing_comments_css() {
        if ( 'all' === $this->opt( 'dc_scope' ) ) {
            echo '<style>.commentlist,.comment-respond,.comments-area{display:none!important}</style>' . "\n";
        }
    }

    // ── Strona ustawień — sekcje ──────────────

    public function register_settings() {
        register_setting( 'eee_dc_settings_group', 'eee_dc_settings', array( $this, 'sanitize' ) );

        add_settings_section( 'eee_dc_main', '', '__return_false', 'eee-dc-settings' );

        $fields = array(
            'dc_enabled'        => array( __( 'Enable module', 'essa-wp-suite' ),                   'field_checkbox' ),
            'dc_scope'          => array( __( 'Scope', 'essa-wp-suite' ),              'field_scope' ),
            'dc_post_types'     => array( __( 'Post types (when "Selected")', 'essa-wp-suite' ),    'field_post_types' ),
            'dc_disable_rest'   => array( __( 'Block comments via REST API', 'essa-wp-suite' ), 'field_checkbox' ),
            'dc_disable_xmlrpc' => array( __( 'Block comments via XML-RPC', 'essa-wp-suite' ), 'field_checkbox' ),
            'dc_hide_admin_ui'  => array( __( 'Hide the comment interface in the admin', 'essa-wp-suite' ),   'field_checkbox' ),
            'dc_remove_feeds'   => array( __( 'Disable comment feeds', 'essa-wp-suite' ),          'field_checkbox' ),
        );

        foreach ( $fields as $id => $data ) {
            add_settings_field(
                'eee_' . $id,
                $data[0],
                array( $this, $data[1] ),
                'eee-dc-settings',
                'eee_dc_main',
                array( 'id' => $id )
            );
        }
    }

    public function sanitize( $input ) {
        $input = is_array( $input ) ? $input : array();
        $clean = array();
        $bools = array( 'dc_enabled', 'dc_disable_rest', 'dc_disable_xmlrpc', 'dc_hide_admin_ui', 'dc_remove_feeds' );

        foreach ( $bools as $k ) {
            $clean[ $k ] = ! empty( $input[ $k ] ) ? '1' : '0';
        }

        $scope             = $input['dc_scope'] ?? 'all';
        $clean['dc_scope'] = in_array( $scope, array( 'all', 'selected' ), true ) ? $scope : 'all';

        $all_types = get_post_types( array( 'public' => true ) );
        $selected  = isset( $input['dc_post_types'] ) && is_array( $input['dc_post_types'] )
            ? $input['dc_post_types']
            : array();
        $clean['dc_post_types'] = array_values( array_intersect( $selected, $all_types ) );

        return $clean;
    }

    // ── Pola formularza ───────────────────────

    public function field_checkbox( $args ) {
        $id  = $args['id'];
        $val = $this->opt( $id );
        printf(
            '<input type="checkbox" id="eee_%1$s" name="eee_dc_settings[%1$s]" value="1" %2$s>',
            esc_attr( $id ),
            checked( '1', $val, false )
        );
    }

    public function field_scope( $args ) {
        $val = $this->opt( 'dc_scope' );
        $options = array(
            'all'      => __( 'All post types', 'essa-wp-suite' ),
            'selected' => __( 'Selected post types', 'essa-wp-suite' ),
        );
        echo '<select name="eee_dc_settings[dc_scope]" id="eee_dc_scope">';
        foreach ( $options as $k => $label ) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr( $k ),
                selected( $val, $k, false ),
                esc_html( $label )
            );
        }
        echo '</select>';
    }

    public function field_post_types( $args ) {
        $post_types = get_post_types( array( 'public' => true ), 'objects' );
        $selected   = (array) $this->opt( 'dc_post_types' );

        echo '<div class="eee-checkboxes">';
        foreach ( $post_types as $slug => $obj ) {
            if ( ! post_type_supports( $slug, 'comments' ) ) {
                continue;
            }
            printf(
                '<label><input type="checkbox" name="eee_dc_settings[dc_post_types][]" value="%1$s" %2$s> %3$s <code>%1$s</code></label><br>',
                esc_attr( $slug ),
                checked( in_array( $slug, $selected, true ), true, false ),
                esc_html( $obj->label )
            );
        }
        echo '</div>';
        echo '<p class="description">' . esc_html__( 'Only used when scope = "Selected post types".', 'essa-wp-suite' ) . '</p>';
    }

    // ── Narzędzie: usuń komentarze ────────────

    public function delete_comments_tool_html() {
        global $wpdb;

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments}" );
        $spam  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'" );
        $trash = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'" );
        $pend  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = '0'" );
        $appr  = $total - $spam - $trash - $pend;
        ?>
        <div class="eee-card eee-card--danger">
            <h3>🗑️ <?php esc_html_e( 'Delete comments from the database', 'essa-wp-suite' ); ?></h3>
            <table class="eee-stats-table">
                <tr><td><?php esc_html_e( 'Approved', 'essa-wp-suite' ); ?></td><td><strong><?php echo (int) $appr; ?></strong></td></tr>
                <tr><td><?php esc_html_e( 'Pending', 'essa-wp-suite' ); ?></td><td><strong><?php echo (int) $pend; ?></strong></td></tr>
                <tr><td><?php esc_html_e( 'Spam', 'essa-wp-suite' ); ?></td><td><strong><?php echo (int) $spam; ?></strong></td></tr>
                <tr><td><?php esc_html_e( 'Trash', 'essa-wp-suite' ); ?></td><td><strong><?php echo (int) $trash; ?></strong></td></tr>
                <tr class="eee-total"><td><?php esc_html_e( 'Total', 'essa-wp-suite' ); ?></td><td><strong><?php echo (int) $total; ?></strong></td></tr>
            </table>

            <?php if ( $total > 0 ) : ?>
            <form method="post" action="" id="eee-delete-comments-form">
                <?php wp_nonce_field( 'eee_delete_comments', 'eee_dc_nonce' ); ?>
                <p>
                    <label><?php esc_html_e( 'Delete:', 'essa-wp-suite' ); ?></label>
                    <select name="eee_delete_type">
                        <option value="all"><?php esc_html_e( 'All', 'essa-wp-suite' ); ?></option>
                        <option value="spam"><?php esc_html_e( 'Spam only', 'essa-wp-suite' ); ?></option>
                        <option value="trash"><?php esc_html_e( 'Trash only', 'essa-wp-suite' ); ?></option>
                        <option value="pending"><?php esc_html_e( 'Pending only', 'essa-wp-suite' ); ?></option>
                    </select>
                </p>
                <p class="eee-danger-warning">
                    ⚠️ <?php esc_html_e( 'This cannot be undone!', 'essa-wp-suite' ); ?>
                </p>
                <button type="submit" name="eee_action" value="delete_comments"
                        class="button button-secondary eee-btn-danger"
                        onclick="return confirm('<?php echo esc_js( __( 'Are you sure? This cannot be undone!', 'essa-wp-suite' ) ); ?>')">
                    <?php esc_html_e( 'Delete comments', 'essa-wp-suite' ); ?>
                </button>
            </form>
            <?php else : ?>
            <p class="description"><?php esc_html_e( 'No comments in the database.', 'essa-wp-suite' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Obsługa akcji usuwania komentarzy (wywoływana z admin_init).
     */
    public function handle_delete_action() {
        if (
            ! isset( $_POST['eee_action'] ) ||
            'delete_comments' !== $_POST['eee_action'] ||
            ! current_user_can( 'manage_options' )
        ) {
            return;
        }

        check_admin_referer( 'eee_delete_comments', 'eee_dc_nonce' );

        global $wpdb;

        $type   = sanitize_key( $_POST['eee_delete_type'] ?? 'all' );
        $where  = '';

        switch ( $type ) {
            case 'spam':
                $where = "WHERE comment_approved = 'spam'";
                break;
            case 'trash':
                $where = "WHERE comment_approved = 'trash'";
                break;
            case 'pending':
                $where = "WHERE comment_approved = '0'";
                break;
            case 'all':
            default:
                $where = '';
                break;
        }

        // Pobierz IDs
        $ids = $wpdb->get_col( "SELECT comment_ID FROM {$wpdb->comments} {$where}" );

        if ( ! empty( $ids ) ) {
            // Usuń metadane
            $ids_in = implode( ',', array_map( 'intval', $ids ) );
            $wpdb->query( "DELETE FROM {$wpdb->commentmeta} WHERE comment_id IN ({$ids_in})" );

            // Usuń komentarze
            $wpdb->query( "DELETE FROM {$wpdb->comments} {$where}" );

            // Przelicz liczniki postów
            $post_ids = $wpdb->get_col( "SELECT DISTINCT comment_post_ID FROM {$wpdb->comments}" );
            foreach ( $post_ids as $pid ) {
                wp_update_comment_count( (int) $pid );
            }
        }

        wp_safe_redirect( ESSA_Suite_Utils::tab_url( 'comments', array( 'dc_deleted' => count( $ids ) ) ) );
        exit;
    }
}

endif;
