<?php
/**
 * ESSA Email Encoder — rdzeń logiki (bez zależności od WP).
 * Używany przez wtyczkę i przez testy (tests/test-encoder.php).
 */

if ( ! class_exists( 'ESSA_Email_Encoder_Core' ) ) :

class ESSA_Email_Encoder_Core {

    /** Email w tekście (nie wewnątrz nazwy atrybutu ani w środku słowa). */
    const REGEXP = '/(?<=[>\s\(\[]|^)([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})/m';

    /** Bloki, których nie wolno ruszać: kod JS, style, formularze, JSON-LD, pola tekstowe. */
    const SKIP_BLOCKS = '/<(script|style|textarea|pre|code)\b[^>]*>.*?<\/\1\s*>/is';

    /** @var string decimal|hex|mixed */
    private $method;

    /** @var callable Źródło losowości (podmieniane w testach) */
    private $rand_fn;

    public function __construct( $method = 'mixed', $rand_fn = null ) {
        $this->method  = in_array( $method, array( 'decimal', 'hex', 'mixed' ), true ) ? $method : 'mixed';
        $this->rand_fn = is_callable( $rand_fn ) ? $rand_fn : 'rand';
    }

    // ── Publiczne API ─────────────────────────

    /**
     * Koduje cały blok HTML: emaile w treści i w href="mailto:...".
     * Fragmenty <script>, <style>, <textarea>, <pre>, <code> zostają nietknięte.
     *
     * @param  mixed $str
     * @return mixed
     */
    public function encode_emails( $str ) {
        if ( ! is_string( $str ) || false === strpos( $str, '@' ) ) {
            return $str;
        }

        if ( ! preg_match_all( self::SKIP_BLOCKS, $str, $blocks, PREG_OFFSET_CAPTURE ) ) {
            return $this->encode_fragment( $str );
        }

        // Składamy wynik po offsetach: tekst między blokami kodujemy, same bloki przepisujemy bez zmian.
        $out = '';
        $pos = 0;
        foreach ( $blocks[0] as $block ) {
            $out .= $this->encode_fragment( substr( $str, $pos, $block[1] - $pos ) );
            $out .= $block[0];
            $pos  = $block[1] + strlen( $block[0] );
        }
        $out .= $this->encode_fragment( substr( $str, $pos ) );
        return $out;
    }

    private function encode_fragment( $str ) {
        if ( '' === $str || false === strpos( $str, '@' ) ) return $str;

        $str = preg_replace_callback(
            '/href=(["\'])mailto:([^"\'>\s]+)\1/i',
            array( $this, 'cb_mailto_attr' ),
            $str
        );

        return preg_replace_callback( self::REGEXP, array( $this, 'cb_plain_email' ), $str );
    }

    /**
     * Koduje każdy znak (punkt kodowy Unicode, nie bajt) do encji HTML.
     * Dzięki temu „Zadzwoń” nie rozpada się na bajty UTF-8.
     *
     * @param  string $str
     * @return string
     */
    public function encode_str( $str ) {
        if ( ! is_string( $str ) || '' === $str ) {
            return '';
        }

        $chars = preg_split( '//u', $str, -1, PREG_SPLIT_NO_EMPTY );
        if ( false === $chars ) {
            // Nieprawidłowe UTF-8 — koduj bajtami, żeby nic nie zgubić.
            $chars = str_split( $str );
        }

        $out = '';
        foreach ( $chars as $ch ) {
            $out .= $this->encode_char( self::ord_utf8( $ch ) );
        }
        return $out;
    }

    // ── Wewnętrzne ────────────────────────────

    /** Punkt kodowy znaku UTF-8 bez mbstring. */
    public static function ord_utf8( $ch ) {
        $len = strlen( $ch );
        $b0  = ord( $ch[0] );
        if ( $len === 1 ) return $b0;
        if ( $len === 2 ) return ( ( $b0 & 0x1F ) << 6 )  | ( ord( $ch[1] ) & 0x3F );
        if ( $len === 3 ) return ( ( $b0 & 0x0F ) << 12 ) | ( ( ord( $ch[1] ) & 0x3F ) << 6 ) | ( ord( $ch[2] ) & 0x3F );
        return ( ( $b0 & 0x07 ) << 18 ) | ( ( ord( $ch[1] ) & 0x3F ) << 12 ) | ( ( ord( $ch[2] ) & 0x3F ) << 6 ) | ( ord( $ch[3] ) & 0x3F );
    }

    private function encode_char( $ord ) {
        switch ( $this->method ) {
            case 'decimal':
                return '&#' . $ord . ';';
            case 'hex':
                return '&#x' . dechex( $ord ) . ';';
            case 'mixed':
            default:
                $fn = $this->rand_fn;
                return $fn( 0, 1 )
                    ? '&#' . $ord . ';'
                    : '&#x' . dechex( $ord ) . ';';
        }
    }

    private function cb_plain_email( $m ) {
        return $this->encode_str( $m[1] );
    }

    private function cb_mailto_attr( $m ) {
        return 'href=' . $m[1] . 'mailto:' . $this->encode_str( $m[2] ) . $m[1];
    }
}

endif;
