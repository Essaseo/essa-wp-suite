<?php
/**
 * Testy rdzenia Email Encodera — bez WordPressa.
 * Uruchom: php tests/test-encoder.php
 */
require_once __DIR__ . '/../essa-wp-suite/includes/class-encoder-core.php';

$fails = 0;
$total = 0;

function check( $name, $cond ) {
    global $fails, $total;
    $total++;
    if ( $cond ) {
        echo "  OK   $name\n";
    } else {
        $fails++;
        echo "  FAIL $name\n";
    }
}

$dec = new ESSA_Email_Encoder_Core( 'decimal' );
$hex = new ESSA_Email_Encoder_Core( 'hex' );
$mix = new ESSA_Email_Encoder_Core( 'mixed', function () { return 1; } ); // deterministycznie: zawsze decimal

echo "encode_str\n";
check( 'decimal ASCII',        $dec->encode_str( 'a@b.pl' ) === '&#97;&#64;&#98;&#46;&#112;&#108;' );
check( 'hex ASCII',            $hex->encode_str( 'ab' ) === '&#x61;&#x62;' );
check( 'mixed z rand=1 = dec', $mix->encode_str( 'a' ) === '&#97;' );
check( 'UTF-8: ó jako jeden punkt kodowy', $dec->encode_str( 'ó' ) === '&#243;' );
check( 'UTF-8: „Zadzwoń” nie rozpada się na bajty', $dec->encode_str( 'Zadzwoń' ) === '&#90;&#97;&#100;&#122;&#119;&#111;&#324;' );
check( 'UTF-8: emoji (4 bajty)', $hex->encode_str( '😀' ) === '&#x1f600;' );
check( 'pusty string → pusty',  $dec->encode_str( '' ) === '' );
check( 'nie-string → pusty',    $dec->encode_str( null ) === '' );

echo "encode_emails\n";
check( 'brak @ → bez zmian',    $dec->encode_emails( 'brak maila' ) === 'brak maila' );
check( 'nie-string przechodzi', $dec->encode_emails( 42 ) === 42 );
check( 'goły email w tekście',  $dec->encode_emails( 'Napisz: a@b.pl dziś' ) === 'Napisz: ' . $dec->encode_str( 'a@b.pl' ) . ' dziś' );
check( 'email po >',            $dec->encode_emails( '<p>a@b.pl</p>' ) === '<p>' . $dec->encode_str( 'a@b.pl' ) . '</p>' );
check( 'email w nawiasie',      strpos( $dec->encode_emails( '(a@b.pl)' ), '&#97;&#64;' ) === 0 + 1 );
check( 'mailto: w href',        $dec->encode_emails( '<a href="mailto:a@b.pl">x</a>' ) === '<a href="mailto:' . $dec->encode_str( 'a@b.pl' ) . '">x</a>' );
check( 'mailto: w apostrofach', $dec->encode_emails( "<a href='mailto:a@b.pl'>x</a>" ) === "<a href='mailto:" . $dec->encode_str( 'a@b.pl' ) . "'>x</a>" );
check( 'href + tekst linku',    $dec->encode_emails( '<a href="mailto:a@b.pl">a@b.pl</a>' ) === '<a href="mailto:' . $dec->encode_str( 'a@b.pl' ) . '">' . $dec->encode_str( 'a@b.pl' ) . '</a>' );

$script = '<p>a@b.pl</p><script type="application/ld+json">{"email":"x@y.pl"}</script><p>c@d.pl</p>';
$out    = $dec->encode_emails( $script );
check( '<script> nietknięty',   strpos( $out, '{"email":"x@y.pl"}' ) !== false );
check( 'tekst przed <script> zakodowany', strpos( $out, $dec->encode_str( 'a@b.pl' ) ) !== false );
check( 'tekst po <script> zakodowany',    strpos( $out, $dec->encode_str( 'c@d.pl' ) ) !== false );
check( '<style> nietknięty',    $dec->encode_emails( '<style>/* a@b.pl */</style>' ) === '<style>/* a@b.pl */</style>' );
check( '<pre> nietknięty',      $dec->encode_emails( '<pre>a@b.pl</pre>' ) === '<pre>a@b.pl</pre>' );
check( '<code> nietknięty',     $dec->encode_emails( 'x <code>a@b.pl</code> y' ) === 'x <code>a@b.pl</code> y' );
check( '<textarea> nietknięty', $dec->encode_emails( '<textarea>a@b.pl</textarea>' ) === '<textarea>a@b.pl</textarea>' );
check( 'wiele bloków',          $dec->encode_emails( '<code>a@b.pl</code> q@w.pl <code>c@d.pl</code>' ) === '<code>a@b.pl</code> ' . $dec->encode_str( 'q@w.pl' ) . ' <code>c@d.pl</code>' );
check( 'atrybut value="a@b.pl" nietknięty', $dec->encode_emails( '<input value="a@b.pl">' ) === '<input value="a@b.pl">' );
check( 'idempotentne (encje nie mają @)', $dec->encode_emails( $dec->encode_emails( 'a@b.pl' ) ) === $dec->encode_emails( 'a@b.pl' ) );

echo "ord_utf8\n";
check( 'A = 65',       ESSA_Email_Encoder_Core::ord_utf8( 'A' ) === 65 );
check( 'ł = 322',      ESSA_Email_Encoder_Core::ord_utf8( 'ł' ) === 322 );
check( '€ = 8364',     ESSA_Email_Encoder_Core::ord_utf8( '€' ) === 8364 );
check( '😀 = 128512',  ESSA_Email_Encoder_Core::ord_utf8( '😀' ) === 128512 );

echo "\n" . ( $total - $fails ) . "/$total OK" . ( $fails ? " — $fails FAIL" : '' ) . "\n";
exit( $fails ? 1 : 0 );
