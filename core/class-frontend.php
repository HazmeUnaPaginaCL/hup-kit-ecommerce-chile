<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class HUPKEC_Frontend {

    public function __construct() {
        add_filter( 'woocommerce_states',          [ $this, 'filter_regions' ] );
        add_filter( 'woocommerce_form_field_args', [ $this, 'force_city_select' ], 9999, 3 );
        add_action( 'wp_enqueue_scripts',          [ $this, 'enqueue_assets' ] );

        // AJAX comunas
        add_action( 'wp_ajax_hupkec_get_comunas',        [ $this, 'ajax_get_comunas' ] );
        add_action( 'wp_ajax_nopriv_hupkec_get_comunas', [ $this, 'ajax_get_comunas' ] );

        // AJAX refresh nonce (para sesiones largas > 12h)
        add_action( 'wp_ajax_hupkec_refresh_nonce',        [ $this, 'ajax_refresh_nonce' ] );
        add_action( 'wp_ajax_nopriv_hupkec_refresh_nonce', [ $this, 'ajax_refresh_nonce' ] );

        // Validación RUT en PHP
        add_action( 'woocommerce_checkout_process', [ $this, 'validate_rut' ] );
    }

    public function filter_regions( $states ) {
        // En las pantallas del admin (dirección de la tienda, zonas de envío de
        // WooCommerce, edición de pedidos) se ofrecen todas las regiones: el
        // filtro de regiones activas es solo para lo que ve el cliente.
        $states['CL'] = ( is_admin() && ! wp_doing_ajax() )
            ? hupkec_get_regions_base()
            : HUPKEC_Settings::get_regions();
        return $states;
    }

    public function force_city_select( $args, $key, $value ) {
        if ( in_array( $key, [ 'billing_city', 'shipping_city' ], true ) ) {
            $args['type']        = 'select';
            $args['class']       = [ 'form-row-wide' ];
            // Sin update_totals_on_change aquí: el JS dispara update_checkout manualmente
            // solo cuando el usuario elige una comuna — evita el loop al cargar la página
            $args['input_class'] = [ 'hupkec-comuna' ];
            $args['options']     = [ '' => __( 'Selecciona una región primero', 'hup-kit-ecommerce-chile' ) ];
            // La comuna guardada del cliente va como opción: el JS la conserva
            // al cargar la lista de su región.
            if ( is_string( $value ) && $value !== '' ) {
                $args['options'][ $value ] = $value;
            }
        }
        return $args;
    }

    /* =========================================================
       AJAX — REFRESH NONCE
    ========================================================= */
    public function ajax_refresh_nonce() {
        // Sin check_ajax_referer intencional: endpoint público para guests con sesiones
        // largas (> 12h). El nonce solo protege los endpoints de comunas, no da acceso
        // a datos sensibles.
        if ( hupkec_rate_limited( 'nonce', 60 ) ) {
            wp_send_json_error( 'Demasiadas solicitudes', 429 );
        }

        wp_send_json_success( [ 'nonce' => wp_create_nonce( 'hupkec_checkout' ) ] );
    }

    /* =========================================================
       AJAX — COMUNAS
    ========================================================= */
    public function ajax_get_comunas() {
        // Solo respaldo: desde la 1.3.1 las comunas viajan con la página.
        if ( hupkec_rate_limited( 'comunas', 300 ) ) {
            wp_send_json_error( 'Demasiadas solicitudes', 429 );
        }

        // Verificar nonce igual que en ajax_get_comunas_empresa
        if ( ! check_ajax_referer( 'hupkec_checkout', 'nonce', false ) ) {
            wp_send_json_error( 'Nonce inválido', 403 );
        }

        // Normalizar: un navegador con la página cacheada de antes de la 1.1.2
        // todavía puede enviar el código corto ('AT').
        $region  = hupkec_normalize_region( sanitize_text_field( wp_unslash( $_POST['region'] ?? '' ) ) );
        if ( ! $region ) wp_send_json_error( 'Sin región' );

        $all     = HUPKEC_Settings::get_comunas();
        $comunas = $all[ $region ] ?? [];

        wp_send_json_success( [
            'comunas' => array_values( $comunas ),
        ] );
    }

    /* =========================================================
       VALIDACIÓN RUT (servidor)
    ========================================================= */
    public function validate_rut() {
        if ( ! hupkec_module_active( 'checkout' ) ) return;

        // Se ejecuta en woocommerce_checkout_process, donde WooCommerce ya verificó
        // el nonce del checkout antes de disparar el hook.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        foreach ( [ 'billing_rut', 'shipping_rut' ] as $key ) {
            $section = strpos( $key, 'billing_' ) === 0 ? 'billing' : 'shipping';
            if ( $key === 'shipping_rut' && empty( $_POST['ship_to_different_address'] ) ) continue;
            if ( empty( $_POST[ $key ] ) ) continue;

            // Solo validar si el campo está habilitado en la configuración de campos
            if ( ! in_array( $key, self::enabled_rut_fields(), true ) ) continue;

            $rut = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
            if ( ! hupkec_is_valid_rut( $rut ) ) {
                // Con 'id', WooCommerce enlaza el aviso a su campo y lo marca en el formulario.
                wc_add_notice( sprintf( 'RUT inválido. Revisa el RUT ingresado (%s).', $key === 'billing_rut' ? 'Facturación' : 'Envío' ), 'error', [ 'id' => $key ] );
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }

    /**
     * Campos RUT que este plugin muestra y valida.
     *
     * @since 1.3.1
     * @return string[]
     */
    private static function enabled_rut_fields() {
        if ( ! hupkec_module_active( 'checkout' ) ) return [];

        $keys = [];
        foreach ( [ 'billing', 'shipping' ] as $section ) {
            foreach ( hupkec_get_fields( $section ) as $f ) {
                if ( $f['key'] === $section . '_rut' && ! empty( $f['enabled'] ) ) {
                    $keys[] = $f['key'];
                }
            }
        }
        return $keys;
    }

    /* =========================================================
       ASSETS
    ========================================================= */
    public function enqueue_assets() {
        if ( ! hupkec_is_address_page() ) return;

        wp_enqueue_script(
            'hupkec-checkout',
            HUPKEC_URL . 'assets/js/hupkec-checkout.js',
            [ 'jquery' ],
            HUPKEC_VERSION,
            true
        );

        wp_localize_script( 'hupkec-checkout', 'hupkec_data', [
            'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
            'nonce'           => wp_create_nonce( 'hupkec_checkout' ),
            'select_city'     => __( 'Selecciona una comuna...', 'hup-kit-ecommerce-chile' ),
            'select_region'   => __( 'Selecciona una región primero', 'hup-kit-ecommerce-chile' ),
            'shipping_active' => hupkec_module_active( 'shipping' ) ? 1 : 0,
            'rut_fields'      => self::enabled_rut_fields(),
            'rut_error'       => 'RUT inválido. Revisa el RUT ingresado',
            'comunas'         => HUPKEC_Settings::get_comunas(),
        ] );

        wp_enqueue_style(
            'hupkec-style',
            HUPKEC_URL . 'assets/css/hupkec-checkout.css',
            [],
            HUPKEC_VERSION
        );
        // El país fijo en Chile ya no se oculta por CSS: lo pinta WooCommerce
        // como país único (ver HUPKEC_Checkout::lock_country_args).
    }
}
