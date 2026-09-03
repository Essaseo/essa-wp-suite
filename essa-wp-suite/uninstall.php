<?php
/**
 * ESSA WP Suite (Free) — usunięcie danych przy odinstalowaniu.
 * Opcje modułów Pro zostawiamy — należą do wtyczki Pro i jej uninstall.php.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

function ewps_uninstall_site() {
    $options = array(
        'ewps_version',
        'ewps_enc_settings',
        'eee_dc_settings',
        'ewps_xmlrpc_settings',
        'ewps_maint_settings',
        'ewps_hard_settings',
        'ewps_notices_settings',
        'ewps_license',
        // opcje starszych wersji
        'ewps_dc_settings',
        'eee_settings',
    );
    foreach ( $options as $opt ) delete_option( $opt );
    delete_site_transient( 'ewps_github_release' );
}

if ( is_multisite() ) {
    foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $ewps_site_id ) {
        switch_to_blog( $ewps_site_id );
        ewps_uninstall_site();
        restore_current_blog();
    }
} else {
    ewps_uninstall_site();
}
