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
        $states['CL'] = HUPKEC_Settings::get_regions();
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
        //
        // Rate-limit: máximo 5 solicitudes por IP en ventana de 60 segundos.
        // Usa contador incremental en lugar del flag booleano anterior (que solo
        // bloqueaba 5 segundos y permitía 12 solicitudes por minuto).
        $ip       = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
        $rate_key = 'hupkec_nonce_rate_' . md5( $ip );
        $hits     = (int) get_transient( $rate_key );

        if ( $hits >= 5 ) {
            wp_send_json_error( 'Demasiadas solicitudes', 429 );
        }

        set_transient( $rate_key, $hits + 1, 60 );

        wp_send_json_success( [ 'nonce' => wp_create_nonce( 'hupkec_checkout' ) ] );
    }

    /* =========================================================
       AJAX — COMUNAS
    ========================================================= */
    public function ajax_get_comunas() {
        // Rate-limit: máximo 30 solicitudes por IP en 60 segundos.
        // El nonce ya limita el acceso legítimo, pero esto protege contra
        // scripts que roten nonces obtenidos del endpoint de refresh.
        $ip       = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
        $rate_key = 'hupkec_comunas_rate_' . md5( $ip );
        $hits     = (int) get_transient( $rate_key );
        if ( $hits >= 30 ) {
            wp_send_json_error( 'Demasiadas solicitudes', 429 );
        }
        set_transient( $rate_key, $hits + 1, 60 );

        // Verificar nonce igual que en ajax_get_comunas_empresa
        if ( ! check_ajax_referer( 'hupkec_checkout', 'nonce', false ) ) {
            wp_send_json_error( 'Nonce inválido', 403 );
        }

        $region  = sanitize_text_field( wp_unslash( $_POST['region'] ?? '' ) );
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
            $field_enabled = false;
            foreach ( hupkec_get_fields( $section ) as $f ) {
                if ( $f['key'] === $key && ! empty( $f['enabled'] ) ) {
                    $field_enabled = true;
                    break;
                }
            }
            if ( ! $field_enabled ) continue;

            $rut = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
            if ( ! hupkec_is_valid_rut( $rut ) ) {
                wc_add_notice( sprintf( 'RUT inválido. Revisar el rut ingresado (%s).', $key === 'billing_rut' ? 'Facturación' : 'Envío' ), 'error' );
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }

    /* =========================================================
       ASSETS
    ========================================================= */
    public function enqueue_assets() {
        $checkout_page_id = wc_get_page_id( 'checkout' );
        if ( ! is_checkout() && ! ( $checkout_page_id > 0 && is_page( $checkout_page_id ) ) ) {
            return;
        }

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
            'rut_fields'      => [ 'billing_rut', 'shipping_rut' ],
            'rut_error'       => 'RUT inválido. Formato esperado: 12.345.678-9',
        ] );

        wp_enqueue_style(
            'hupkec-style',
            HUPKEC_URL . 'assets/css/hupkec-checkout.css',
            [],
            HUPKEC_VERSION
        );

        // Cuando el módulo de checkout está activo, el país se muestra fijo como
        // "Chile" (ver HUPKEC_Checkout::render_country_static) y hay que ocultar el
        // select nativo de WooCommerce. Se hace con wp_add_inline_style —no con un
        // <style> incrustado en el HTML— para cumplir las normas de WordPress.org.
        // Solo se oculta la sección cuyo campo país esté habilitado, replicando la
        // misma condición que usa render_country_static.
        if ( hupkec_module_active( 'checkout' ) ) {
            $hide = [];
            foreach ( [ 'billing', 'shipping' ] as $section ) {
                foreach ( hupkec_get_fields( $section ) as $f ) {
                    if ( ( $f['type'] ?? '' ) === 'country' && ! empty( $f['enabled'] ) ) {
                        $hide[] = '#' . $section . '_country_field';
                        break;
                    }
                }
            }
            if ( $hide ) {
                wp_add_inline_style( 'hupkec-style', implode( ',', $hide ) . '{display:none!important;}' );
            }
        }
    }
}
