<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

// Eliminar zona de envío de WooCommerce si está disponible
$hupkec_zone_id = get_option( 'hupkec_wc_global_zone_created' );
if ( $hupkec_zone_id && class_exists( 'WC_Shipping_Zone' ) ) {
    // WooCommerce no siempre está completamente inicializado durante la
    // desinstalación (sus data stores pueden no estar cargados aún). Si crear o
    // borrar la zona falla, NO debe abortar la desinstalación con un error fatal
    // —el "error crítico" que ve el usuario al pulsar "Borrar"—: la zona huérfana
    // es inofensiva y no impide desinstalar el resto.
    try {
        $hupkec_zone = new WC_Shipping_Zone( $hupkec_zone_id );
        $hupkec_zone->delete();
    } catch ( \Throwable $hupkec_e ) {
        // Silencioso: WooCommerce no disponible para borrar la zona al desinstalar.
    }
}

$hupkec_options = array(
    'hupkec_settings',
    'hupkec_modules',
    'hupkec_fields',
    'hupkec_zones',
    'hupkec_wc_global_zone_created',
    'hupkec_billing_module',
);

foreach ( $hupkec_options as $hupkec_opt ) {
    delete_option( $hupkec_opt );
}

delete_transient( 'hupkec_zone_verified' );
