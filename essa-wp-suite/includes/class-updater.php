<?php
/**
 * ESSA WP Suite — aktualizacje z GitHub Releases.
 *
 * WordPress pyta o nową wersję jak każdą inną wtyczkę (Kokpit → Aktualizacje),
 * a źródłem jest najnowsze wydanie w repozytorium GitHub. Paczka ZIP pochodzi
 * z assetu wydania (buduje go GitHub Actions); gdy assetu brak, z zipballa
 * repozytorium — wtedy katalog jest przemianowywany na "essa-wp-suite".
 *
 * Repo prywatne: define( 'EWPS_GITHUB_TOKEN', 'ghp_...' ); w wp-config.php.
 * Inne repo:     define( 'EWPS_GITHUB_REPO', 'uzytkownik/nazwa' );
 *
 * PHP 7.2+
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'ESSA_Updater' ) ) :

class ESSA_Updater {

    private static $instance = null;

    const DEFAULT_REPO = 'Essaseo/essa-wp-suite';
    const CACHE_KEY    = 'ewps_github_release';
    const CACHE_TTL    = 6 * HOUR_IN_SECONDS;
    const SLUG         = 'essa-wp-suite';

    private $repo;

    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $this->repo = defined( 'EWPS_GITHUB_REPO' ) ? EWPS_GITHUB_REPO : self::DEFAULT_REPO;

        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
        add_filter( 'plugins_api',                            array( $this, 'plugin_info' ), 20, 3 );
        add_filter( 'upgrader_source_selection',              array( $this, 'fix_source_dir' ), 10, 4 );
        add_filter( 'http_request_args',                      array( $this, 'auth_headers' ), 10, 2 );
        add_action( 'upgrader_process_complete',              array( $this, 'clear_cache_after_update' ), 10, 2 );
        add_action( 'admin_init',                             array( $this, 'handle_force_check' ) );
    }

    public function repo_url() {
        return 'https://github.com/' . $this->repo;
    }

    private function token() {
        return defined( 'EWPS_GITHUB_TOKEN' ) ? (string) EWPS_GITHUB_TOKEN : '';
    }

    // ─── GitHub API ──────────────────────────────────────────────────────────

    /**
     * Najnowsze wydanie: [ version, package, url, body, published, requires_wp, requires_php ] albo null.
     * Wynik (także brak wydania) trzymany 6 h w transiencie — żeby nie odpytywać API przy każdym wejściu do admina.
     */
    public function get_release( $force = false ) {
        $cached = $force ? false : get_site_transient( self::CACHE_KEY );
        if ( is_array( $cached ) ) return empty( $cached ) ? null : $cached;

        $release = $this->fetch_release();
        set_site_transient( self::CACHE_KEY, is_array( $release ) ? $release : array(), self::CACHE_TTL );
        return $release;
    }

    private function fetch_release() {
        $headers = array(
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'ESSA-WP-Suite/' . EWPS_VERSION . '; ' . home_url(),
        );
        if ( $this->token() ) $headers['Authorization'] = 'Bearer ' . $this->token();

        $res = wp_remote_get( 'https://api.github.com/repos/' . $this->repo . '/releases/latest', array(
            'timeout' => 10,
            'headers' => $headers,
        ) );
        if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) return null;

        $data = json_decode( wp_remote_retrieve_body( $res ), true );
        if ( empty( $data['tag_name'] ) || ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) return null;

        $version = ltrim( (string) $data['tag_name'], 'vV' );
        if ( ! preg_match( '/^\d+\.\d+(\.\d+)?/', $version ) ) return null;

        // Paczka: asset *.zip z wydania (zbudowany przez CI); w ostateczności zipball repo.
        $package = '';
        foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
            $name = (string) ( $asset['name'] ?? '' );
            if ( substr( $name, -4 ) === '.zip' && strpos( $name, self::SLUG ) === 0 ) {
                // Repo prywatne: pobieranie assetu idzie przez API z nagłówkiem octet-stream (auth_headers)
                $package = $this->token() ? (string) $asset['url'] : (string) $asset['browser_download_url'];
                break;
            }
        }
        if ( '' === $package ) $package = (string) ( $data['zipball_url'] ?? '' );
        if ( '' === $package ) return null;

        return array(
            'version'   => $version,
            'package'   => $package,
            'url'       => (string) ( $data['html_url'] ?? $this->repo_url() ),
            'body'      => (string) ( $data['body'] ?? '' ),
            'published' => (string) ( $data['published_at'] ?? '' ),
        );
    }

    // ─── WordPress: transient aktualizacji ───────────────────────────────────

    public function inject_update( $transient ) {
        if ( ! is_object( $transient ) ) $transient = new stdClass();

        $release = $this->get_release();
        $basename = EWPS_BASENAME;

        if ( $release && version_compare( $release['version'], EWPS_VERSION, '>' ) ) {
            $item = (object) array(
                'id'            => 'github.com/' . $this->repo,
                'slug'          => self::SLUG,
                'plugin'        => $basename,
                'new_version'   => $release['version'],
                'url'           => $this->repo_url(),
                'package'       => $release['package'],
                'icons'         => array(),
                'banners'       => array(),
                'banners_rtl'   => array(),
                'tested'        => '',
                'requires_php'  => EWPS_MIN_PHP,
                'compatibility' => new stdClass(),
            );
            if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) $transient->response = array();
            $transient->response[ $basename ] = $item;
            if ( isset( $transient->no_update[ $basename ] ) ) unset( $transient->no_update[ $basename ] );
        } else {
            // Wpis w no_update pozwala WP pokazać przełącznik auto-aktualizacji na liście wtyczek.
            if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) $transient->no_update = array();
            $transient->no_update[ $basename ] = (object) array(
                'id'            => 'github.com/' . $this->repo,
                'slug'          => self::SLUG,
                'plugin'        => $basename,
                'new_version'   => EWPS_VERSION,
                'url'           => $this->repo_url(),
                'package'       => '',
                'icons'         => array(),
                'banners'       => array(),
                'banners_rtl'   => array(),
                'tested'        => '',
                'requires_php'  => EWPS_MIN_PHP,
                'compatibility' => new stdClass(),
            );
        }
        return $transient;
    }

    // ─── WordPress: okno "Zobacz szczegóły wersji" ───────────────────────────

    public function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) return $result;

        $release = $this->get_release();
        if ( ! $release ) return $result;

        $changelog = $release['body'] !== '' ? wp_kses_post( wpautop( esc_html( $release['body'] ) ) ) : '<p>—</p>';

        return (object) array(
            'name'           => 'ESSA WP Suite',
            'slug'           => self::SLUG,
            'version'        => $release['version'],
            'author'         => '<a href="https://essaseo.pl">ESSA SEO</a>',
            'homepage'       => $this->repo_url(),
            'download_link'  => $release['package'],
            'requires'       => EWPS_MIN_WP,
            'requires_php'   => EWPS_MIN_PHP,
            'last_updated'   => $release['published'],
            'sections'       => array(
                'description' => '<p>' . esc_html__( 'Zestaw narzędzi administracyjnych WordPress od ESSA SEO: Email Encoder, Disable Comments, Login Security, Disable XML-RPC, Maintenance Mode, WP Hardening, Admin Notices, SMTP, DB Cleaner, Admin Cleaner, Auto Updates, White Label, Activity Log.', 'essa-wp-suite' ) . '</p>',
                'changelog'   => $changelog,
            ),
        );
    }

    // ─── Katalog w paczce ────────────────────────────────────────────────────

    /**
     * Zipball GitHuba rozpakowuje się do "Essaseo-essa-wp-suite-<hash>/". WordPress
     * zainstalowałby to jako nową wtyczkę obok starej — przemianowujemy na "essa-wp-suite".
     */
    public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
        if ( empty( $hook_extra['plugin'] ) || EWPS_BASENAME !== $hook_extra['plugin'] ) return $source;
        if ( is_wp_error( $source ) ) return $source;

        global $wp_filesystem;
        $current = basename( untrailingslashit( $source ) );
        if ( self::SLUG === $current ) return $source;

        $new = trailingslashit( $remote_source ) . self::SLUG . '/';
        if ( $wp_filesystem && $wp_filesystem->move( $source, $new, true ) ) return $new;
        return $source;
    }

    // ─── Nagłówki do API (repo prywatne) ─────────────────────────────────────

    public function auth_headers( $args, $url ) {
        if ( ! $this->token() ) return $args;
        if ( strpos( $url, 'https://api.github.com/repos/' . $this->repo . '/' ) !== 0 ) return $args;
        if ( ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) $args['headers'] = array();
        $args['headers']['Authorization'] = 'Bearer ' . $this->token();
        if ( strpos( $url, '/releases/assets/' ) !== false ) $args['headers']['Accept'] = 'application/octet-stream';
        return $args;
    }

    // ─── Cache ───────────────────────────────────────────────────────────────

    public function clear_cache() {
        delete_site_transient( self::CACHE_KEY );
        delete_site_transient( 'update_plugins' );
    }

    public function clear_cache_after_update( $upgrader, $hook_extra ) {
        if ( ! empty( $hook_extra['type'] ) && 'plugin' === $hook_extra['type'] ) {
            delete_site_transient( self::CACHE_KEY );
        }
    }

    /** ?ewps_check_update=1 z nonce (link na liście wtyczek) → świeże zapytanie do GitHuba. */
    public function handle_force_check() {
        if ( empty( $_GET['ewps_check_update'] ) || ! current_user_can( 'update_plugins' ) ) return;
        check_admin_referer( 'ewps_check_update' );
        $this->clear_cache();
        $release = $this->get_release( true );
        $msg = $release
            ? ( version_compare( $release['version'], EWPS_VERSION, '>' ) ? 'available' : 'latest' )
            : 'error';
        wp_safe_redirect( add_query_arg( array( 'ewps_update' => $msg ), self_admin_url( 'plugins.php' ) ) );
        exit;
    }

    public function force_check_link() {
        return wp_nonce_url( self_admin_url( 'plugins.php?ewps_check_update=1' ), 'ewps_check_update' );
    }

    public function admin_notice() {
        if ( empty( $_GET['ewps_update'] ) ) return;
        $status = sanitize_key( $_GET['ewps_update'] );
        $texts  = array(
            'available' => array( 'notice-warning', __( 'Jest nowa wersja ESSA WP Suite — aktualizuj z listy wtyczek.', 'essa-wp-suite' ) ),
            'latest'    => array( 'notice-success', sprintf( __( 'ESSA WP Suite %s to najnowsza wersja.', 'essa-wp-suite' ), EWPS_VERSION ) ),
            'error'     => array( 'notice-error',   __( 'Nie udało się połączyć z GitHubem albo repozytorium nie ma jeszcze wydania.', 'essa-wp-suite' ) ),
        );
        if ( ! isset( $texts[ $status ] ) ) return;
        printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr( $texts[ $status ][0] ), esc_html( $texts[ $status ][1] ) );
    }
}

endif;
