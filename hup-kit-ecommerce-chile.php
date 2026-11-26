<?php
/**
 * Plugin Name: HUP - Kit Ecommerce Chile
 * Description: Solución completa para tiendas WooCommerce en Chile: selector de regiones/comunas, validación de RUT, zonas de despacho, checkout personalizado y solicitud de datos de facturación para empresas.
 * Version: 1.4.0
 * Author: HazmeUnaPagina.cl
 * Author URI: https://www.hazmeunapagina.cl
 * Text Domain: hup-kit-ecommerce-chile
 * Requires at least: 6.0
 * Requires PHP: 8.2
 * Requires Plugins: woocommerce
 * WC requires at least: 7.0
 * WC tested up to: 11.3
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'HUPKEC_VERSION',  '1.4.0' );
define( 'HUPKEC_PATH',     plugin_dir_path( __FILE__ ) );
define( 'HUPKEC_URL',      plugin_dir_url( __FILE__ ) );
define( 'HUPKEC_BASENAME', plugin_basename( __FILE__ ) );

// Declarar compatibilidad con HPOS (High Performance Order Storage) de WooCommerce 8.2+
// y declarar explícitamente que NO soportamos el checkout en bloques: nuestros campos
// cuelgan de filtros del checkout clásico (woocommerce_form_field_args, woocommerce_billing_fields)
// que el checkout en bloques no ejecuta. WooCommerce avisará al comerciante y le ofrecerá
// volver al checkout por shortcode.
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'cart_checkout_blocks',
            __FILE__,
            false
        );
    }
} );

require_once HUPKEC_PATH . 'core/class-modules.php';
require_once HUPKEC_PATH . 'core/helpers.php';
require_once HUPKEC_PATH . 'core/class-loader.php';

register_activation_hook( __FILE__, 'hupkec_activate' );
function hupkec_activate() {
    if ( ! get_option( 'hupkec_settings' ) ) {
        update_option( 'hupkec_settings', [] );
    }
    if ( ! get_option( 'hupkec_modules' ) ) {
        // Los valores iniciales salen del registro, que es la única fuente de
        // verdad. Los módulos con opción propia —facturación— quedan fuera solos.
        update_option( 'hupkec_modules', HUPKEC_Modules::defaults() );
    }
    if ( ! get_option( 'hupkec_fields' ) ) {
        update_option( 'hupkec_fields', hupkec_default_fields() );
    }

    // Crear la zona de envío en la activación para que la instalación quede
    // funcional de inmediato, sin depender de que alguien visite el admin.
    //
    // La activación no pasa por 'plugins_loaded': WordPress incluye este archivo
    // y dispara el hook en el acto, cuando 'plugins_loaded' ya se disparó para
    // los plugins que estaban activos. Por eso los módulos todavía no se han
    // cargado y hay que incluir la clase a mano, en vez de confiar en un
    // class_exists() que aquí siempre daría false.
    if ( class_exists( 'WC_Shipping_Zones' ) ) {
        require_once HUPKEC_PATH . 'modules/shipping/class-shipping.php';
        delete_transient( 'hupkec_zone_verified' );
        HUPKEC_Shipping::ensure_global_zone();
    }
}

/**
 * Arranque del plugin.
 *
 * Se instancia en 'plugins_loaded' y no al incluir el archivo: esa es la ventana
 * que necesita un addon para registrar módulos con 'hupkec_modules_registry'
 * desde el cuerpo de su propio archivo principal, que se ejecuta antes.
 *
 * @since 1.1.0
 * @return void
 */
function hupkec_init() {
    // WooCommerce es dependencia obligatoria. Si no está activo (lo desactivaron
    // con el plugin ya activo, o está roto), no cargar NADA del plugin: sus
    // clases llaman a funciones de WooCommerce (wc_get_page_id, wc_add_notice,
    // WC_Shipping_Zones…) que provocarían un error fatal. Solo se muestra un aviso.
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'hupkec_wc_missing_notice' );
        return;
    }

    new HUPKEC_Loader();

    /**
     * El plugin está cargado y sus hooks registrados.
     *
     * @since 1.1.0
     */
    do_action( 'hupkec_loaded' );
}
add_action( 'plugins_loaded', 'hupkec_init', 10 );

/**
 * Aviso cuando falta WooCommerce.
 *
 * Se muestra en el admin (no en el frontend) y solo a quien puede gestionar
 * plugins. Es el único admin_notice del plugin fuera de su propia página, y está
 * permitido: WordPress.org lo espera para dependencias no satisfechas.
 *
 * @return void
 */
function hupkec_wc_missing_notice() {
    if ( ! current_user_can( 'activate_plugins' ) ) {
        return;
    }
    echo '<div class="notice notice-error"><p>';
    echo esc_html__( 'HUP - Kit Ecommerce Chile necesita que WooCommerce esté instalado y activo para funcionar.', 'hup-kit-ecommerce-chile' );
    echo '</p></div>';
}
