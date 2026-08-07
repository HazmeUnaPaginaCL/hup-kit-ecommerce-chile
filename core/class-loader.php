<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class HUPKEC_Loader {

    public function __construct() {
        // Cargar datos territoriales
        require_once HUPKEC_PATH . 'assets/data/regiones-comunas.php';
        
        // Core siempre carga
        require_once HUPKEC_PATH . 'core/class-settings.php';
        require_once HUPKEC_PATH . 'core/class-frontend.php';
        
        // Admin siempre carga (para configuración base)
        require_once HUPKEC_PATH . 'admin/class-admin.php';

        // Instanciar core
        new HUPKEC_Settings();
        new HUPKEC_Frontend();
        new HUPKEC_Admin();

        // Módulos según configuración
        $this->load_modules();

        // WC_Shipping_Method solo está disponible después de woocommerce_shipping_init
        add_action( 'woocommerce_shipping_init', [ $this, 'load_shipping_method' ] );

        // AJAX para guardar módulos
        add_action( 'wp_ajax_hupkec_save_modules', [ $this, 'ajax_save_modules' ] );
    }

    /**
     * Carga los módulos según configuración.
     *
     * Qué archivo incluir, qué clase instanciar y si el interruptor manda o no,
     * está declarado en HUPKEC_Modules. Aquí solo se dispara el proceso.
     */
    private function load_modules() {
        HUPKEC_Modules::load();
    }

    /**
     * Carga la clase del método de envío solo cuando WooCommerce
     * ya tiene WC_Shipping_Method disponible.
     */
    public function load_shipping_method() {
        require_once HUPKEC_PATH . 'modules/shipping/class-shipping-method.php';
    }

    /**
     * AJAX: Guardar estado de módulos
     */
    public function ajax_save_modules() {
        check_ajax_referer( 'hupkec_admin', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die();

        $saved = hupkec_get_modules();
        $posted = wp_unslash( $_POST );
        $modules = [];

        // Solo los módulos que comparten la opción hupkec_modules. Los que tienen
        // opción propia —'billing'— se guardan por su cuenta: incluirlos aquí
        // generaba una segunda fuente de verdad que el cargador nunca leía.
        foreach ( HUPKEC_Modules::shared_keys() as $key ) {
            if ( array_key_exists( $key, $posted ) ) {
                $modules[ $key ] = ! empty( $posted[ $key ] ) ? 1 : 0;
            } else {
                $modules[ $key ] = ! empty( $saved[ $key ] ) ? 1 : 0;
            }
        }
        
        update_option( 'hupkec_modules', $modules );

        if ( function_exists( 'wc_get_logger' ) ) {
            $user   = wp_get_current_user();
            $active = implode( ', ', array_keys( array_filter( $modules ) ) ) ?: 'ninguno';
            wc_get_logger()->info(
                sprintf( 'HUPKEC: Módulos actualizados por "%s". Activos: %s', $user->user_login, $active ),
                [ 'source' => 'hup-kit-ecommerce-chile' ]
            );
        }

        wp_send_json_success( $modules );
    }
}
