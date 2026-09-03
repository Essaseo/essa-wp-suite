<?php
/**
 * ESSA WP Suite — funkcje pomocnicze wspólne dla modułów.
 *
 * PHP 7.2+
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'ESSA_Suite_Utils' ) ) :

class ESSA_Suite_Utils {

    /**
     * Adres IP klienta.
     *
     * Nagłówki proxy (Cloudflare, X-Forwarded-For, X-Real-IP) są brane pod uwagę
     * TYLKO gdy $trust_proxy = true. Każdy może wysłać dowolny nagłówek HTTP,
     * więc bez proxy przed serwerem ufanie im pozwala ominąć blokady IP.
     *
     * @param  bool $trust_proxy
     * @return string
     */
    public static function client_ip( $trust_proxy = false ) {
        $keys = $trust_proxy
            ? array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' )
            : array( 'REMOTE_ADDR' );

        foreach ( $keys as $k ) {
            if ( empty( $_SERVER[ $k ] ) ) continue;
            $raw   = sanitize_text_field( wp_unslash( $_SERVER[ $k ] ) );
            $parts = explode( ',', $raw );
            $ip    = trim( $parts[0] );
            if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) return $ip;
        }
        return '0.0.0.0';
    }

    /**
     * Czy IP jest na liście (lista: IP oddzielone przecinkami, spacjami lub nowymi liniami;
     * obsługuje też zakresy CIDR IPv4, np. 192.168.1.0/24).
     */
    public static function ip_in_list( $ip, $list ) {
        $items = preg_split( '/[\s,;]+/', (string) $list, -1, PREG_SPLIT_NO_EMPTY );
        foreach ( $items as $item ) {
            $item = trim( $item );
            if ( $item === $ip ) return true;
            if ( strpos( $item, '/' ) !== false && self::ipv4_in_cidr( $ip, $item ) ) return true;
        }
        return false;
    }

    private static function ipv4_in_cidr( $ip, $cidr ) {
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) return false;
        list( $subnet, $bits ) = array_pad( explode( '/', $cidr, 2 ), 2, 32 );
        if ( ! filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) return false;
        $bits = (int) $bits;
        if ( $bits < 0 || $bits > 32 ) return false;
        $mask = $bits === 0 ? 0 : ( -1 << ( 32 - $bits ) ) & 0xFFFFFFFF;
        return ( ip2long( $ip ) & $mask ) === ( ip2long( $subnet ) & $mask );
    }

    /**
     * Ścieżka bieżącego żądania względem katalogu instalacji WP,
     * bez query stringu i bez ukośników na końcach. Np. "login" albo "blog/wpis".
     */
    public static function request_path() {
        $uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
        $path = (string) wp_parse_url( $uri, PHP_URL_PATH );
        $path = rawurldecode( $path );

        $home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
        $home_path = trim( $home_path, '/' );
        $path      = trim( $path, '/' );

        if ( $home_path !== '' && strpos( $path, $home_path ) === 0 ) {
            $path = trim( substr( $path, strlen( $home_path ) ), '/' );
        }
        return $path;
    }

    /** URL zakładki w panelu wtyczki. */
    public static function tab_url( $tab = '', $extra = array() ) {
        $args = array( 'page' => 'ewps-settings' );
        if ( $tab ) $args['tab'] = $tab;
        $args = array_merge( $args, $extra );
        return add_query_arg( $args, admin_url( 'admin.php' ) );
    }
}

endif;
