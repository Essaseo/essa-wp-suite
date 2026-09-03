<?php
/**
 * ESSA WP Suite — klient licencji Pro.
 *
 * Trzyma klucz i stan licencji w opcji `ewps_license`, rozmawia z serwerem
 * licencji (domyślnie https://lic.essaseo.pl, nadpisanie: EWPS_LIC_URL),
 * potrafi pobrać i zainstalować wtyczkę Pro jednym kliknięciem.
 *
 * Stan sprawdzany ponownie co 12 h w adminie. Gdy serwer nie odpowiada,
 * ostatni dobry stan obowiązuje jeszcze 14 dni (grace), potem Pro się wyłącza.
 *
 * PHP 7.2+
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'ESSA_License' ) ) :

class ESSA_License {

    private static $instance = null;

    const OPTION       = 'ewps_license';
    const DEFAULT_URL  = 'https://lic.essaseo.pl';
    const RECHECK      = 12 * HOUR_IN_SECONDS;
    const GRACE        = 14 * DAY_IN_SECONDS;
    const PRO_SLUG     = 'essa-wp-suite-pro';
    const PRO_BASENAME = 'essa-wp-suite-pro/essa-wp-suite-pro.php';

    private $data = array();

    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $stored = get_option( self::OPTION, array() );
        $this->data = wp_parse_args( is_array( $stored ) ? $stored : array(), array(
            'key'        => '',
            'status'     => '',      // active | expired | revoked | invalid | not_activated | ''
            'expires'    => '',
            'sites_max'  => 0,
            'sites_used' => 0,
            'domain'     => '',
            'checked'    => 0,       // ostatnia UDANA rozmowa z serwerem
            'last_try'   => 0,
            'message'    => '',
            'latest'     => '',
        ) );
        if ( defined( 'EWPS_LICENSE_KEY' ) && EWPS_LICENSE_KEY && '' === $this->data['key'] ) {
            $this->data['key'] = strtoupper( EWPS_LICENSE_KEY );
        }
        add_action( 'admin_init', array( $this, 'maybe_recheck' ), 5 );
        add_action( 'admin_init', array( $this, 'handle_actions' ), 6 );
    }

    // ─── Stan ────────────────────────────────────────────────────────────────

    public static function server_url() {
        $url = defined( 'EWPS_LIC_URL' ) ? EWPS_LIC_URL : self::DEFAULT_URL;
        return untrailingslashit( apply_filters( 'ewps_license_server_url', $url ) );
    }

    public static function shop_url() {
        return apply_filters( 'ewps_shop_url', 'https://essaseo.pl/produkt/essa-wp-suite-pro/' );
    }

    public static function domain() {
        $host = wp_parse_url( home_url(), PHP_URL_HOST );
        $host = strtolower( (string) $host );
        return preg_replace( '/^www\./', '', $host );
    }

    public function get( $k ) {
        return isset( $this->data[ $k ] ) ? $this->data[ $k ] : '';
    }

    public function key() {
        return (string) $this->data['key'];
    }

    public function masked_key() {
        $k = $this->key();
        return strlen( $k ) === 24 ? substr( $k, 0, 9 ) . '-****-****-' . substr( $k, -4 ) : $k;
    }

    /** Czy Pro ma prawo działać teraz. */
    public function is_valid() {
        if ( '' === $this->key() || 'active' !== $this->data['status'] ) return false;
        if ( $this->data['expires'] && strtotime( $this->data['expires'] ) < time() ) return false;
        // Grace: ostatni udany check nie starszy niż 14 dni.
        return ( time() - (int) $this->data['checked'] ) < self::GRACE;
    }

    public function status_label() {
        if ( '' === $this->key() ) return __( 'Brak klucza', 'essa-wp-suite' );
        $map = array(
            'active'        => __( 'Aktywna', 'essa-wp-suite' ),
            'expired'       => __( 'Wygasła', 'essa-wp-suite' ),
            'revoked'       => __( 'Unieważniona', 'essa-wp-suite' ),
            'invalid'       => __( 'Nieprawidłowy klucz', 'essa-wp-suite' ),
            'not_activated' => __( 'Nieaktywowana na tej domenie', 'essa-wp-suite' ),
            'limit'         => __( 'Limit stron wyczerpany', 'essa-wp-suite' ),
        );
        $s = isset( $map[ $this->data['status'] ] ) ? $map[ $this->data['status'] ] : __( 'Nieznany', 'essa-wp-suite' );
        if ( 'active' === $this->data['status'] && ! $this->is_valid() ) $s = __( 'Aktywna, ale serwer licencji nie odpowiada od 14 dni', 'essa-wp-suite' );
        return $s;
    }

    private function save() {
        update_option( self::OPTION, $this->data, false );
    }

    // ─── Rozmowa z serwerem ──────────────────────────────────────────────────

    private function request( $endpoint, $body = array(), $method = 'POST' ) {
        $url  = self::server_url() . $endpoint;
        $args = array(
            'timeout' => 15,
            'headers' => array( 'Accept' => 'application/json', 'Content-Type' => 'application/json' ),
            'user-agent' => 'ESSA-WP-Suite/' . EWPS_VERSION . '; ' . home_url(),
        );
        if ( 'POST' === $method ) {
            $args['body'] = wp_json_encode( $body );
            $res = wp_remote_post( $url, $args );
        } else {
            $res = wp_remote_get( add_query_arg( $body, $url ), $args );
        }
        if ( is_wp_error( $res ) ) {
            return array( 'ok' => false, 'error' => 'network', 'message' => $res->get_error_message(), '_http' => 0 );
        }
        $json = json_decode( wp_remote_retrieve_body( $res ), true );
        if ( ! is_array( $json ) ) {
            return array( 'ok' => false, 'error' => 'bad_response', 'message' => __( 'Serwer licencji zwrócił niezrozumiałą odpowiedź.', 'essa-wp-suite' ), '_http' => (int) wp_remote_retrieve_response_code( $res ) );
        }
        $json['_http'] = (int) wp_remote_retrieve_response_code( $res );
        return $json;
    }

    private function apply_response( $r ) {
        $this->data['last_try'] = time();
        if ( ! empty( $r['license'] ) && is_array( $r['license'] ) ) {
            $l = $r['license'];
            $this->data['status']     = isset( $l['status'] ) ? $l['status'] : $this->data['status'];
            $this->data['expires']    = isset( $l['expires'] ) ? (string) $l['expires'] : '';
            $this->data['sites_max']  = isset( $l['sites_max'] ) ? (int) $l['sites_max'] : 0;
            $this->data['sites_used'] = isset( $l['sites_used'] ) ? (int) $l['sites_used'] : 0;
        }
        if ( ! empty( $r['latest_version'] ) ) $this->data['latest'] = (string) $r['latest_version'];

        if ( ! empty( $r['ok'] ) ) {
            $this->data['checked'] = time();
            $this->data['domain']  = self::domain();
            $this->data['message'] = '';
        } else {
            $err = isset( $r['error'] ) ? $r['error'] : 'error';
            if ( in_array( $err, array( 'invalid_key', 'wrong_product' ), true ) ) $this->data['status'] = 'invalid';
            elseif ( 'limit_reached' === $err ) $this->data['status'] = 'limit';
            elseif ( in_array( $err, array( 'expired', 'revoked', 'not_activated' ), true ) ) $this->data['status'] = $err;
            // network / server_error / rate_limited → stan bez zmian (grace)
            $this->data['message'] = isset( $r['message'] ) ? (string) $r['message'] : $err;
        }
        $this->save();
        return ! empty( $r['ok'] );
    }

    public function activate( $key ) {
        $key = strtoupper( trim( (string) $key ) );
        if ( ! preg_match( '/^EWPS(-[A-Z0-9]{4}){4}$/', $key ) ) {
            $this->data['message'] = __( 'Klucz ma format EWPS-XXXX-XXXX-XXXX-XXXX.', 'essa-wp-suite' );
            $this->save();
            return false;
        }
        $this->data['key'] = $key;
        $r = $this->request( '/v1/activate', array( 'key' => $key, 'domain' => self::domain(), 'version' => EWPS_VERSION ) );
        $ok = $this->apply_response( $r );
        do_action( 'ewps_license_changed', $ok, $this->data );
        delete_site_transient( 'update_plugins' );
        return $ok;
    }

    public function deactivate() {
        if ( '' !== $this->key() ) {
            $this->request( '/v1/deactivate', array( 'key' => $this->key(), 'domain' => self::domain() ) );
        }
        $this->data = array_merge( $this->data, array( 'key' => '', 'status' => '', 'expires' => '', 'sites_max' => 0, 'sites_used' => 0, 'checked' => 0, 'message' => '', 'latest' => '' ) );
        $this->save();
        do_action( 'ewps_license_changed', false, $this->data );
        delete_site_transient( 'update_plugins' );
        return true;
    }

    public function check( $force = false ) {
        if ( '' === $this->key() ) return false;
        if ( ! $force && ( time() - (int) $this->data['last_try'] ) < self::RECHECK ) return $this->is_valid();
        $r = $this->request( '/v1/check', array( 'key' => $this->key(), 'domain' => self::domain(), 'version' => EWPS_VERSION ) );
        $this->apply_response( $r );
        return $this->is_valid();
    }

    public function maybe_recheck() {
        if ( '' === $this->key() ) return;
        if ( self::domain() !== $this->data['domain'] && $this->data['domain'] ) {
            // Strona przeniesiona na inną domenę (np. staging → prod) — aktywuj ponownie.
            $this->activate( $this->key() );
            return;
        }
        $this->check();
    }

    // ─── Pro: pobranie i instalacja ──────────────────────────────────────────

    public static function pro_installed() {
        return file_exists( WP_PLUGIN_DIR . '/' . self::PRO_BASENAME );
    }

    public static function pro_active() {
        return class_exists( 'ESSA_WP_Suite_Pro' );
    }

    public function pro_package_url() {
        return add_query_arg( array( 'key' => $this->key(), 'domain' => self::domain() ), self::server_url() . '/v1/download' );
    }

    /** Instaluje (lub nadpisuje) wtyczkę Pro z serwera licencji i aktywuje ją. Zwraca true|WP_Error. */
    public function install_pro() {
        if ( ! $this->is_valid() ) return new WP_Error( 'ewps_no_license', __( 'Najpierw aktywuj ważny klucz licencji.', 'essa-wp-suite' ) );
        if ( ! current_user_can( 'install_plugins' ) ) return new WP_Error( 'ewps_cap', __( 'Brak uprawnień do instalacji wtyczek.', 'essa-wp-suite' ) );

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $skin     = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader( $skin );
        add_filter( 'upgrader_package_options', function( $options ) {
            $options['clear_destination'] = true;
            $options['abort_if_destination_exists'] = false;
            return $options;
        } );
        $result = $upgrader->install( $this->pro_package_url() );
        if ( is_wp_error( $result ) ) return $result;
        if ( ! $result ) {
            $errors = $skin->get_upgrade_messages();
            return new WP_Error( 'ewps_install_failed', implode( ' ', array_map( 'wp_strip_all_tags', (array) $errors ) ) ?: __( 'Instalacja nie powiodła się.', 'essa-wp-suite' ) );
        }
        $activated = activate_plugin( self::PRO_BASENAME );
        return is_wp_error( $activated ) ? $activated : true;
    }

    // ─── Akcje z zakładki „Licencja” ─────────────────────────────────────────

    public function handle_actions() {
        if ( empty( $_POST['ewps_license_action'] ) || ! current_user_can( 'manage_options' ) ) return;
        check_admin_referer( 'ewps_license', 'ewps_license_nonce' );
        $action = sanitize_key( $_POST['ewps_license_action'] );
        $msg    = 'ok';

        if ( 'activate' === $action ) {
            $msg = $this->activate( wp_unslash( $_POST['ewps_license_key'] ?? '' ) ) ? 'activated' : 'error';
        } elseif ( 'deactivate' === $action ) {
            $this->deactivate();
            $msg = 'deactivated';
        } elseif ( 'check' === $action ) {
            $msg = $this->check( true ) ? 'valid' : 'error';
        } elseif ( 'install_pro' === $action ) {
            $r   = $this->install_pro();
            $msg = is_wp_error( $r ) ? 'install_error' : 'installed';
            if ( is_wp_error( $r ) ) { $this->data['message'] = $r->get_error_message(); $this->save(); }
        }
        wp_safe_redirect( ESSA_Suite_Utils::tab_url( 'license', array( 'lic' => $msg ) ) );
        exit;
    }

    // ─── Widok zakładki ──────────────────────────────────────────────────────

    public function render_tab() {
        $notices = array(
            'activated'     => array( 'success', __( 'Licencja aktywowana na tej domenie.', 'essa-wp-suite' ) ),
            'deactivated'   => array( 'info',    __( 'Licencja zwolniona — możesz jej użyć na innej stronie.', 'essa-wp-suite' ) ),
            'valid'         => array( 'success', __( 'Licencja sprawdzona: ważna.', 'essa-wp-suite' ) ),
            'installed'     => array( 'success', __( 'ESSA WP Suite Pro zainstalowane i aktywowane. Moduły Pro są już na dashboardzie.', 'essa-wp-suite' ) ),
            'error'         => array( 'error',   $this->data['message'] ?: __( 'Nie udało się.', 'essa-wp-suite' ) ),
            'install_error' => array( 'error',   $this->data['message'] ?: __( 'Instalacja Pro nie powiodła się.', 'essa-wp-suite' ) ),
        );
        if ( ! empty( $_GET['lic'] ) && isset( $notices[ $_GET['lic'] ] ) ) {
            $n = $notices[ sanitize_key( $_GET['lic'] ) ];
            printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $n[0] ), esc_html( $n[1] ) );
        }
        $has_key = '' !== $this->key();
        $valid   = $this->is_valid();
        ?>
        <div class="ewps-layout">
            <div class="ewps-main">
                <form method="post">
                    <?php wp_nonce_field( 'ewps_license', 'ewps_license_nonce' ); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Klucz licencji Pro', 'essa-wp-suite' ); ?></th>
                            <td>
                                <?php if ( $has_key ) : ?>
                                    <code style="font-size:14px"><?php echo esc_html( $this->masked_key() ); ?></code>
                                    <input type="hidden" name="ewps_license_key" value="<?php echo esc_attr( $this->key() ); ?>">
                                <?php else : ?>
                                    <input type="text" name="ewps_license_key" class="regular-text code" placeholder="EWPS-XXXX-XXXX-XXXX-XXXX" autocomplete="off">
                                <?php endif; ?>
                                <?php if ( defined( 'EWPS_LICENSE_KEY' ) ) : ?><p class="description"><?php esc_html_e( 'Klucz zdefiniowany w wp-config.php (EWPS_LICENSE_KEY).', 'essa-wp-suite' ); ?></p><?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Stan', 'essa-wp-suite' ); ?></th>
                            <td>
                                <strong class="<?php echo $valid ? 'ewps-status--on' : 'ewps-status--off'; ?>"><?php echo esc_html( $this->status_label() ); ?></strong>
                                <?php if ( $this->data['expires'] ) : ?><br><span class="description"><?php printf( esc_html__( 'Ważna do: %s', 'essa-wp-suite' ), esc_html( date_i18n( get_option( 'date_format' ), strtotime( $this->data['expires'] ) ) ) ); ?></span><?php endif; ?>
                                <?php if ( $this->data['sites_max'] ) : ?><br><span class="description"><?php printf( esc_html__( 'Strony: %1$d z %2$d', 'essa-wp-suite' ), (int) $this->data['sites_used'], (int) $this->data['sites_max'] ); ?></span><?php endif; ?>
                                <?php if ( $this->data['message'] && ! $valid ) : ?><br><span class="description" style="color:#c00"><?php echo esc_html( $this->data['message'] ); ?></span><?php endif; ?>
                                <?php if ( $this->data['checked'] ) : ?><br><span class="description"><?php printf( esc_html__( 'Ostatnie sprawdzenie: %s', 'essa-wp-suite' ), esc_html( human_time_diff( (int) $this->data['checked'] ) . ' ' . __( 'temu', 'essa-wp-suite' ) ) ); ?></span><?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Domena', 'essa-wp-suite' ); ?></th>
                            <td><code><?php echo esc_html( self::domain() ); ?></code></td>
                        </tr>
                    </table>
                    <p>
                        <?php if ( ! $has_key ) : ?>
                            <button type="submit" name="ewps_license_action" value="activate" class="button button-primary"><?php esc_html_e( 'Aktywuj licencję', 'essa-wp-suite' ); ?></button>
                        <?php else : ?>
                            <button type="submit" name="ewps_license_action" value="check" class="button"><?php esc_html_e( 'Sprawdź ponownie', 'essa-wp-suite' ); ?></button>
                            <?php if ( ! $valid && 'not_activated' === $this->data['status'] || 'limit' === $this->data['status'] ) : ?>
                                <button type="submit" name="ewps_license_action" value="activate" class="button button-primary"><?php esc_html_e( 'Aktywuj na tej domenie', 'essa-wp-suite' ); ?></button>
                            <?php endif; ?>
                            <button type="submit" name="ewps_license_action" value="deactivate" class="button" onclick="return confirm('<?php echo esc_js( __( 'Zwolnić licencję na tej stronie? Moduły Pro przestaną działać.', 'essa-wp-suite' ) ); ?>')"><?php esc_html_e( 'Dezaktywuj / zmień klucz', 'essa-wp-suite' ); ?></button>
                        <?php endif; ?>
                    </p>
                    <?php if ( $valid && ! self::pro_active() ) : ?>
                        <div class="ewps-card" style="margin-top:16px">
                            <h3>🚀 <?php esc_html_e( 'Zainstaluj ESSA WP Suite Pro', 'essa-wp-suite' ); ?></h3>
                            <p><?php echo self::pro_installed()
                                ? esc_html__( 'Wtyczka Pro jest na serwerze, ale nieaktywna. Kliknij, żeby ją aktywować (lub nadpisać najnowszą wersją).', 'essa-wp-suite' )
                                : esc_html__( 'Licencja jest ważna. Jednym kliknięciem pobierzesz paczkę Pro z serwera licencji i aktywujesz moduły.', 'essa-wp-suite' ); ?></p>
                            <button type="submit" name="ewps_license_action" value="install_pro" class="button button-primary"><?php esc_html_e( 'Pobierz i aktywuj Pro', 'essa-wp-suite' ); ?></button>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
            <div class="ewps-sidebar">
                <div class="ewps-card">
                    <h3>🏷️ <?php esc_html_e( 'Moduły Pro', 'essa-wp-suite' ); ?></h3>
                    <ul>
                        <?php foreach ( ESSA_WP_Suite::pro_catalog() as $mod ) : ?>
                            <li><?php echo esc_html( $mod['icon'] . ' ' . $mod['label'] ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ( ! $valid ) : ?>
                    <p><a href="<?php echo esc_url( self::shop_url() ); ?>" class="button button-primary" target="_blank" rel="noopener"><?php esc_html_e( 'Kup licencję Pro →', 'essa-wp-suite' ); ?></a></p>
                    <?php endif; ?>
                </div>
                <div class="ewps-card ewps-card--tip"><p><?php esc_html_e( 'Jeden klucz = liczba stron z licencji. Strony testowe (localhost, *.local, *.test, staging) nie zużywają miejsc. Przeniesienie strony na nową domenę aktywuje klucz ponownie automatycznie.', 'essa-wp-suite' ); ?></p></div>
                <div class="ewps-card"><p class="description"><?php printf( esc_html__( 'Serwer licencji: %s', 'essa-wp-suite' ), '<code>' . esc_html( self::server_url() ) . '</code>' ); ?></p></div>
            </div>
        </div>
        <?php
    }
}

endif;
