<?php
/**
 * Generuje languages/essa-wp-suite.pot z wywołań __(), _e(), esc_html__(), esc_html_e(), esc_attr__(), _n().
 * Uruchom: php narzedzia/make-pot.php
 */
$root = realpath( __DIR__ . '/../essa-wp-suite' );
$out  = $root . '/languages/essa-wp-suite.pot';

$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );
$strings = array(); // msgid => [ 'plural' => ..., 'refs' => [] ]

$re_single = '/\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e|_x)\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1\s*,/s';
$re_plural = '/\b_n\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1\s*,\s*([\'"])((?:\\\\.|(?!\3).)*)\3\s*,/s';

foreach ( $files as $f ) {
    if ( $f->getExtension() !== 'php' ) continue;
    $src = file_get_contents( $f->getPathname() );
    $rel = str_replace( $root . DIRECTORY_SEPARATOR, '', $f->getPathname() );
    $rel = str_replace( '\\', '/', $rel );

    if ( preg_match_all( $re_single, $src, $m, PREG_OFFSET_CAPTURE ) ) {
        foreach ( $m[2] as $i => $hit ) {
            $id   = stripcslashes( $hit[0] );
            $line = substr_count( substr( $src, 0, $hit[1] ), "\n" ) + 1;
            $strings[ $id ]['refs'][] = "$rel:$line";
        }
    }
    if ( preg_match_all( $re_plural, $src, $m, PREG_OFFSET_CAPTURE ) ) {
        foreach ( $m[2] as $i => $hit ) {
            $id   = stripcslashes( $hit[0] );
            $line = substr_count( substr( $src, 0, $hit[1] ), "\n" ) + 1;
            $strings[ $id ]['plural'] = stripcslashes( $m[4][ $i ][0] );
            $strings[ $id ]['refs'][] = "$rel:$line";
        }
    }
}

ksort( $strings, SORT_STRING );

function po_escape( $s ) {
    return '"' . str_replace( array( '\\', '"', "\n" ), array( '\\\\', '\\"', '\\n"' . "\n\"" ), $s ) . '"';
}

$pot  = "# ESSA WP Suite — szablon tłumaczeń\n";
$pot .= "msgid \"\"\nmsgstr \"\"\n";
$pot .= "\"Project-Id-Version: ESSA WP Suite\\n\"\n";
$pot .= "\"POT-Creation-Date: " . gmdate( 'Y-m-d H:i+0000' ) . "\\n\"\n";
$pot .= "\"MIME-Version: 1.0\\n\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\"Content-Transfer-Encoding: 8bit\\n\"\n";
$pot .= "\"Plural-Forms: nplurals=3; plural=(n==1 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);\\n\"\n";
$pot .= "\"X-Domain: essa-wp-suite\\n\"\n\n";

foreach ( $strings as $id => $data ) {
    foreach ( array_unique( $data['refs'] ) as $ref ) $pot .= "#: $ref\n";
    $pot .= 'msgid ' . po_escape( $id ) . "\n";
    if ( isset( $data['plural'] ) ) {
        $pot .= 'msgid_plural ' . po_escape( $data['plural'] ) . "\n";
        $pot .= "msgstr[0] \"\"\nmsgstr[1] \"\"\nmsgstr[2] \"\"\n\n";
    } else {
        $pot .= "msgstr \"\"\n\n";
    }
}

file_put_contents( $out, $pot );
echo count( $strings ) . " stringów → $out\n";
