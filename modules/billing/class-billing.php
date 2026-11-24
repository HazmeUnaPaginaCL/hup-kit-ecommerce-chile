<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * HUPKEC_Billing — Módulo de facturación chilena.
 * Agrega bloque "¿Necesitas factura?" en el checkout con campos de empresa.
 * Compatible con WooCommerce nativo, Elementor Pro y Bricks Builder.
 */
class HUPKEC_Billing {

    /**
     * Evita doble render aunque page builders recreen la instancia.
     * Static: el valor persiste a lo largo de toda la request.
     */
    private static $block_rendered = false;

    /** Campos de la factura, en el formulario, el pedido y el perfil del cliente. */
    const FIELDS = [
        'billing_company_invoice',
        'billing_rut_empresa',
        'billing_giro_empresa',
        'billing_region_empresa',
        'billing_comuna_empresa',
        'billing_direccion_empresa',
    ];

    public function __construct() {
        // Resetear el guard de doble render al inicio de cada carga del form
        add_action( 'woocommerce_before_checkout_form', [ $this, 'reset_block_guard' ], 1 );

        // Limpiar sesión de factura en cargas frescas (no en submit AJAX de WC)
        add_action( 'woocommerce_before_checkout_form', [ $this, 'maybe_clear_invoice_session' ], 5 );

        // Hook principal — WooCommerce nativo y la mayoría de temas
        add_action( 'woocommerce_after_checkout_billing_form', [ $this, 'render_billing_block' ], 20 );

        // Hook de respaldo — Elementor Pro Checkout Widget y algunos temas custom
        // Se dispara después de toda la sección de datos del cliente
        add_action( 'woocommerce_checkout_after_customer_details', [ $this, 'render_billing_block' ], 20 );

        // Validar campos si el cliente marcó "necesito factura"
        add_action( 'woocommerce_checkout_process', [ $this, 'validate_billing_fields' ] );

        // Persistir estado del checkbox en sesión para sobrevivir recargas AJAX de WC
        add_action( 'woocommerce_checkout_process', [ $this, 'persist_invoice_session' ], 1 );

        // Guardar metadatos en el pedido (HPOS compatible).
        // woocommerce_checkout_create_order se dispara DURANTE la creación;
        // NO llamar $order->save() aquí.
        add_action( 'woocommerce_checkout_create_order', [ $this, 'save_billing_fields' ], 20, 2 );

        // Guardar user meta DESPUÉS de que el pedido haya sido persistido en BD.
        add_action( 'woocommerce_checkout_order_created', [ $this, 'save_user_meta_after_order' ], 20 );

        // Mostrar en el detalle del pedido en admin
        add_action( 'woocommerce_admin_order_data_after_billing_address', [ $this, 'display_in_order' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_order_screen_style' ] );

        // Mostrar en emails
        add_filter( 'woocommerce_email_order_meta_fields', [ $this, 'add_to_email' ], 10, 3 );

        // AJAX para comunas de empresa
        add_action( 'wp_ajax_hupkec_get_comunas_empresa',        [ $this, 'ajax_get_comunas_empresa' ] );
        add_action( 'wp_ajax_nopriv_hupkec_get_comunas_empresa', [ $this, 'ajax_get_comunas_empresa' ] );

        // Assets — también en el reset de AJAX checkout de WooCommerce
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // Mostrar datos de facturación en la página de confirmación del pedido
        add_action( 'woocommerce_thankyou', [ $this, 'display_on_thankyou' ], 20 );
    }

    /* =========================================================
       SESIÓN — persistir estado del checkbox entre recargas AJAX
       Se ejecuta al inicio de woocommerce_checkout_process (prioridad 1)
       para que el valor esté disponible cuando WC recarga el checkout
       tras un error de validación.
    ========================================================= */
    public function maybe_clear_invoice_session() {
        // Solo limpiar en cargas de página normales (no AJAX de WooCommerce)
        if ( wp_doing_ajax() ) return;
        if ( ! WC()->session ) return;
        WC()->session->set( 'hupkec_needs_invoice', '0' );
    }

    public function reset_block_guard() {
        self::$block_rendered = false;
    }

    public function persist_invoice_session() {
        if ( ! WC()->session ) return;
        // woocommerce_checkout_process: WooCommerce ya verificó el nonce del checkout.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $value = ! empty( $_POST['hupkec_needs_invoice'] ) ? '1' : '0';
        WC()->session->set( 'hupkec_needs_invoice', $value );
    }

    /* =========================================================
       RENDER BLOQUE EN CHECKOUT
    ========================================================= */
    public function render_billing_block() {
        // Guard: renderizar solo una vez aunque se disparen dos hooks
        if ( self::$block_rendered ) return;
        self::$block_rendered = true;

        // Repuebla el formulario tras un error de validación del checkout, donde
        // WooCommerce ya verificó el nonce (woocommerce-process-checkout-nonce).
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $regions = HUPKEC_Settings::get_regions();

        // Prioridad: POST (submit con error) > sesión AJAX (recarga de validación)
        // El checkbox NUNCA aparece marcado por defecto — solo se marca si:
        // 1. El cliente lo marcó y hubo error de validación (POST o sesión activa del mismo submit)
        // 2. El cliente lo marcó en un submit previo fallido (sesión de la misma sesión de checkout)
        // NO se pre-marca desde user meta — el cliente decide explícitamente cada vez.
        $is_post = isset( $_POST['woocommerce-process-checkout-nonce'] );
        $needs   = false;

        if ( $is_post ) {
            // Submit del formulario con error de validación: respetar lo que eligió
            $needs = ! empty( $_POST['hupkec_needs_invoice'] );
        } elseif ( WC()->session && WC()->session->get( 'hupkec_needs_invoice' ) === '1' ) {
            // Recarga AJAX de WooCommerce (ej: cambio de método de envío): mantener estado
            $needs = true;
        }
        // Nota: intencionalmente NO se lee user meta aquí para no pre-marcar por defecto

        // Datos de la última factura del cliente. La casilla sigue sin marcar.
        $saved_data = [];
        $user_id    = get_current_user_id();
        if ( ! $is_post && $user_id && get_user_meta( $user_id, 'hupkec_needs_invoice', true ) === '1' ) {
            foreach ( self::FIELDS as $key ) {
                $saved_data[ $key ] = (string) get_user_meta( $user_id, $key, true );
            }
        }

        // Helper: valor del campo (POST tiene prioridad, luego user meta guardado)
        $val = function( $key ) use ( $is_post, $saved_data ) {
            if ( $is_post ) return sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) );
            return $saved_data[ $key ] ?? '';
        };

        $region       = hupkec_normalize_region( $val( 'billing_region_empresa' ) );
        $comuna       = $val( 'billing_comuna_empresa' );
        $all_comunas  = HUPKEC_Settings::get_comunas();
        $comunas      = isset( $regions[ $region ] ) ? ( $all_comunas[ $region ] ?? [] ) : [];
        ?>
        <div id="hupkec-billing-module" class="hupkec-billing-module">

            <!-- Checkbox trigger -->
            <div class="hupkec-billing-trigger">
                <label class="hupkec-billing-check-label" for="hupkec_needs_invoice">
                    <input type="checkbox" id="hupkec_needs_invoice" name="hupkec_needs_invoice" value="1"
                           class="hupkec-billing-checkbox" <?php checked( $needs ); ?>>
                    <span class="hupkec-billing-check-text"><?php esc_html_e( '¿Necesitas factura?', 'hup-kit-ecommerce-chile' ); ?></span>
                </label>
            </div>

            <!-- Bloque de campos de facturación -->
            <div id="hupkec-billing-fields" class="hupkec-billing-fields <?php echo $needs ? 'hupkec-billing-open' : ''; ?>">
                <div class="hupkec-billing-fields-inner">

                    <div class="hupkec-billing-header">
                        <strong><?php esc_html_e( 'Datos de facturación', 'hup-kit-ecommerce-chile' ); ?></strong>
                        <button type="button" id="hupkec-copy-billing-data" class="hupkec-btn hupkec-btn-copy-billing">
                            <?php esc_html_e( 'Copiar datos del comprador', 'hup-kit-ecommerce-chile' ); ?>
                        </button>
                    </div>

                    <div class="hupkec-billing-grid">
                        <!-- Nombre empresa -->
                        <div class="hupkec-billing-field-row form-row form-row-wide">
                            <label for="billing_company_invoice"><?php esc_html_e( 'Nombre de la empresa', 'hup-kit-ecommerce-chile' ); ?> <abbr class="required" title="requerido">*</abbr></label>
                            <input type="text" id="billing_company_invoice" name="billing_company_invoice"
                                   class="input-text"
                                   value="<?php echo esc_attr( $val('billing_company_invoice') ); ?>"
                                   placeholder="<?php esc_attr_e( 'Razón social de la empresa', 'hup-kit-ecommerce-chile' ); ?>">
                        </div>

                        <!-- RUT empresa -->
                        <div class="hupkec-billing-field-row form-row form-row-wide">
                            <label for="billing_rut_empresa"><?php esc_html_e( 'RUT de la empresa', 'hup-kit-ecommerce-chile' ); ?> <abbr class="required" title="requerido">*</abbr></label>
                            <input type="text" id="billing_rut_empresa" name="billing_rut_empresa"
                                   class="input-text hupkec-rut-empresa"
                                   value="<?php echo esc_attr( $val('billing_rut_empresa') ); ?>"
                                   placeholder="<?php esc_attr_e( 'Ej: 76.543.210-K', 'hup-kit-ecommerce-chile' ); ?>" maxlength="12">
                            <span class="hupkec-rut-error" id="hupkec-rut-empresa-error" style="display:none"></span>
                        </div>

                        <!-- Giro empresa -->
                        <div class="hupkec-billing-field-row form-row form-row-wide">
                            <label for="billing_giro_empresa"><?php esc_html_e( 'Giro de la empresa', 'hup-kit-ecommerce-chile' ); ?> <abbr class="required" title="requerido">*</abbr></label>
                            <input type="text" id="billing_giro_empresa" name="billing_giro_empresa"
                                   class="input-text"
                                   value="<?php echo esc_attr( $val('billing_giro_empresa') ); ?>"
                                   placeholder="<?php esc_attr_e( 'Ej: Comercio al por menor', 'hup-kit-ecommerce-chile' ); ?>">
                        </div>

                        <!-- Región empresa -->
                        <div class="hupkec-billing-field-row form-row form-row-wide">
                            <label for="billing_region_empresa"><?php esc_html_e( 'Región de la empresa', 'hup-kit-ecommerce-chile' ); ?> <abbr class="required" title="requerido">*</abbr></label>
                            <select id="billing_region_empresa" name="billing_region_empresa" class="select input-text">
                                <option value=""><?php esc_html_e( 'Selecciona una región...', 'hup-kit-ecommerce-chile' ); ?></option>
                                <?php foreach ( $regions as $code => $name ): ?>
                                <option value="<?php echo esc_attr($code); ?>"
                                    <?php selected( $region, $code ); ?>>
                                    <?php echo esc_html($name); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Comuna empresa (se rellena al cambiar la región) -->
                        <div class="hupkec-billing-field-row form-row form-row-wide">
                            <label for="billing_comuna_empresa"><?php esc_html_e( 'Comuna de la empresa', 'hup-kit-ecommerce-chile' ); ?> <abbr class="required" title="requerido">*</abbr></label>
                            <select id="billing_comuna_empresa" name="billing_comuna_empresa" class="select input-text" <?php disabled( empty( $comunas ) ); ?>>
                                <option value=""><?php echo esc_html( $comunas ? __( 'Selecciona una comuna...', 'hup-kit-ecommerce-chile' ) : __( 'Selecciona una región primero', 'hup-kit-ecommerce-chile' ) ); ?></option>
                                <?php foreach ( $comunas as $name ): ?>
                                <option value="<?php echo esc_attr( $name ); ?>" <?php selected( $comuna, $name ); ?>><?php echo esc_html( $name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Dirección empresa -->
                        <div class="hupkec-billing-field-row form-row form-row-wide">
                            <label for="billing_direccion_empresa"><?php esc_html_e( 'Dirección de la empresa', 'hup-kit-ecommerce-chile' ); ?> <abbr class="required" title="requerido">*</abbr></label>
                            <input type="text" id="billing_direccion_empresa" name="billing_direccion_empresa"
                                   class="input-text"
                                   value="<?php echo esc_attr( $val('billing_direccion_empresa') ); ?>"
                                   placeholder="<?php esc_attr_e( 'Nombre de la calle y número', 'hup-kit-ecommerce-chile' ); ?>">
                        </div>
                    </div>

                </div><!-- /.hupkec-billing-fields-inner -->
            </div><!-- /#hupkec-billing-fields -->
        </div><!-- /#hupkec-billing-module -->
        <?php
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }

    /* =========================================================
       AJAX — COMUNAS DE EMPRESA
    ========================================================= */
    public function ajax_get_comunas_empresa() {
        // Solo respaldo: desde la 1.3.1 las comunas viajan con la página.
        if ( hupkec_rate_limited( 'comunas_emp', 300 ) ) {
            wp_send_json_error( 'Demasiadas solicitudes', 429 );
        }

        // Verificar nonce igual que el AJAX de comunas principal
        if ( ! check_ajax_referer( 'hupkec_checkout', 'nonce', false ) ) {
            wp_send_json_error( 'Nonce inválido', 403 );
        }

        $region = hupkec_normalize_region( sanitize_text_field( wp_unslash( $_POST['region'] ?? '' ) ) );
        if ( ! $region ) wp_send_json_error( 'Sin región', 400 );

        $all     = HUPKEC_Settings::get_comunas();
        $comunas = $all[ $region ] ?? [];

        wp_send_json_success( array_values( $comunas ) );
    }

    /* =========================================================
       VALIDACIÓN
    ========================================================= */
    public function validate_billing_fields() {
        // woocommerce_checkout_process: WooCommerce ya verificó el nonce del checkout.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        if ( empty( $_POST['hupkec_needs_invoice'] ) ) return;

        $campos = [
            'billing_company_invoice' => 'Nombre de la empresa',
            'billing_rut_empresa'     => 'RUT de la empresa',
            'billing_giro_empresa'    => 'Giro de la empresa',
            'billing_region_empresa'  => 'Región de la empresa',
            'billing_comuna_empresa'  => 'Comuna de la empresa',
            'billing_direccion_empresa' => 'Dirección de la empresa',
        ];

        foreach ( $campos as $key => $label ) {
            if ( empty( sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) ) ) ) {
                wc_add_notice( sprintf( 'El campo <strong>%s</strong> es obligatorio para emitir factura.', $label ), 'error' );
            }
        }

        // Validar RUT empresa con Módulo 11
        if ( ! empty( $_POST['billing_rut_empresa'] ) ) {
            if ( ! hupkec_is_valid_rut( sanitize_text_field( wp_unslash( $_POST['billing_rut_empresa'] ) ) ) ) {
                wc_add_notice( 'El <strong>RUT de la empresa</strong> ingresado no es válido. Revisa el RUT ingresado.', 'error' );
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }

    /* =========================================================
       GUARDAR EN EL PEDIDO (HPOS compatible)
       NOTA: Este hook se ejecuta DURANTE la creación del pedido.
       Solo actualizar metadatos — NO llamar $order->save().
       WooCommerce persiste el pedido después de que todos los hooks terminen.
    ========================================================= */
    public function save_billing_fields( $order, $data = [] ) {
        if ( ! $order instanceof WC_Abstract_Order ) return;

        // woocommerce_checkout_create_order: WooCommerce ya verificó el nonce del checkout.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $needs_invoice = ! empty( $_POST['hupkec_needs_invoice'] );
        $order->update_meta_data( '_hupkec_needs_invoice', $needs_invoice ? '1' : '0' );

        if ( ! $needs_invoice ) {
            return; // NO $order->save()
        }

        foreach ( self::FIELDS as $key ) {
            $val = sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) );
            $order->update_meta_data( '_' . $key, $val );
        }

        // Sincronizar con billing_company nativo de WooCommerce.
        // Gateways chilenos (Transbank, Webpay, Khipu) y otros plugins
        // leen este campo para emitir boleta/factura correctamente.
        $company_name = sanitize_text_field( wp_unslash( $_POST['billing_company_invoice'] ?? '' ) );
        if ( $company_name ) {
            $order->set_billing_company( $company_name );
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        // NO $order->save() — WooCommerce lo hace después de este hook
    }

    /* =========================================================
       GUARDAR USER META DESPUÉS DE QUE EL PEDIDO ESTÉ EN BD
       woocommerce_checkout_order_created se dispara DESPUÉS de que
       el pedido ha sido guardado, por lo que es seguro usar $order->get_meta().
    ========================================================= */
    public function save_user_meta_after_order( $order ) {
        if ( $order->get_meta( '_hupkec_needs_invoice' ) !== '1' ) return;

        $user_id = $order->get_customer_id();
        if ( ! $user_id ) return;

        foreach ( self::FIELDS as $key ) {
            $val = $order->get_meta( '_' . $key );
            if ( $val !== '' ) {
                update_user_meta( $user_id, $key, $val );
            }
        }
        update_user_meta( $user_id, 'hupkec_needs_invoice', '1' );

        // Sincronizar billing_company nativo para coherencia del perfil
        $company = $order->get_meta( '_billing_company_invoice' );
        if ( $company ) update_user_meta( $user_id, 'billing_company', $company );
    }

    /* =========================================================
       MOSTRAR EN ADMIN
    ========================================================= */

    /**
     * Estilo de la caja "Datos de facturación" en la ficha del pedido.
     *
     * Encolado con handle propio + wp_add_inline_style en vez de atributos
     * style en el HTML, por coherencia con las normas de WordPress.org. Solo
     * en las pantallas de pedidos: la clásica (shop_order) y la de HPOS
     * (woocommerce_page_wc-orders).
     */
    public function enqueue_order_screen_style() {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || ! in_array( $screen->id, [ 'shop_order', 'woocommerce_page_wc-orders' ], true ) ) {
            return;
        }

        wp_register_style( 'hupkec-order-billing', false, [], HUPKEC_VERSION );
        wp_enqueue_style( 'hupkec-order-billing' );
        wp_add_inline_style(
            'hupkec-order-billing',
            '.hupkec-order-billing{margin-top:12px;padding:8px;background:#f8fbfe;border-left:3px solid #378ADD;}'
        );
    }

    public function display_in_order( $order ) {
        $needs = $order->get_meta( '_hupkec_needs_invoice' );
        if ( $needs !== '1' ) return;


        echo '<div class="hupkec-order-billing">';
        echo '<strong>Datos de facturación:</strong><br>';

        $campos = [
            '_billing_company_invoice'   => 'Empresa',
            '_billing_rut_empresa'       => 'RUT empresa',
            '_billing_giro_empresa'      => 'Giro',
            '_billing_region_empresa'    => 'Región',
            '_billing_comuna_empresa'    => 'Comuna',
            '_billing_direccion_empresa' => 'Dirección',
        ];

        foreach ( $campos as $meta_key => $label ) {
            $val = $order->get_meta( $meta_key );
            if ( ! $val ) continue;

            // Resolver código de región a nombre legible (arregla pedidos guardados con código)
            if ( $meta_key === '_billing_region_empresa' ) {
                $val = hupkec_region_name( $val );
            }

            echo '<br><small><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $val ) . '</small>';
        }
        echo '</div>';
    }

    /* =========================================================
       EMAIL
    ========================================================= */
    public function add_to_email( $fields, $sent_to_admin, $order ) {
        if ( $order->get_meta( '_hupkec_needs_invoice' ) !== '1' ) return $fields;


        $campos = [
            '_billing_company_invoice'   => 'Empresa',
            '_billing_rut_empresa'       => 'RUT empresa',
            '_billing_giro_empresa'      => 'Giro',
            '_billing_region_empresa'    => 'Región empresa',
            '_billing_comuna_empresa'    => 'Comuna empresa',
            '_billing_direccion_empresa' => 'Dirección empresa',
        ];

        foreach ( $campos as $meta_key => $label ) {
            $val = $order->get_meta( $meta_key );
            if ( ! $val ) continue;

            // Resolver código de región a nombre legible
            if ( $meta_key === '_billing_region_empresa' ) {
                $val = hupkec_region_name( $val );
            }

            $fields[ ltrim( $meta_key, '_' ) ] = [ 'label' => $label, 'value' => $val ];
        }

        return $fields;
    }

    /* =========================================================
       PÁGINA DE CONFIRMACIÓN — "Gracias por tu pedido"
       Compatible con WP nativo, Elementor, Bricks y cualquier
       constructor que use el shortcode/bloque estándar de WC.
    ========================================================= */
    public function display_on_thankyou( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_meta( '_hupkec_needs_invoice' ) !== '1' ) return;


        $campos = [
            '_billing_company_invoice'   => 'Empresa',
            '_billing_rut_empresa'       => 'RUT empresa',
            '_billing_giro_empresa'      => 'Giro',
            '_billing_region_empresa'    => 'Región',
            '_billing_comuna_empresa'    => 'Comuna',
            '_billing_direccion_empresa' => 'Dirección',
        ];

        // Sin atributos style: las reglas de .hupkec-thankyou-billing viven en
        // hupkec-checkout.css, encolado en esta página por el frontend core.
        echo '<section class="woocommerce-order-details hupkec-thankyou-billing">';
        echo '<h2 class="woocommerce-order-details__title">Datos de facturación</h2>';
        echo '<table class="woocommerce-table woocommerce-table--order-details shop_table order_details">';
        echo '<tbody>';

        foreach ( $campos as $meta_key => $label ) {
            $val = $order->get_meta( $meta_key );
            if ( ! $val ) continue;

            if ( $meta_key === '_billing_region_empresa' ) {
                $val = hupkec_region_name( $val );
            }

            echo '<tr>';
            echo '<th scope="row">' . esc_html( $label ) . ':</th>';
            echo '<td>' . esc_html( $val ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</section>';
    }

    /* =========================================================
       ASSETS
    ========================================================= */
    public function enqueue_assets() {
        // is_checkout() puede devolver false en algunos page builders;
        // verificamos también por ID de página de WooCommerce
        $checkout_page_id = wc_get_page_id( 'checkout' );
        if ( ! is_checkout() && ! ( $checkout_page_id > 0 && is_page( $checkout_page_id ) ) ) {
            return;
        }

        // Localizar datos de facturación en el script hupkec-checkout
        // (que ya se carga desde class-frontend.php)
        wp_localize_script( 'hupkec-checkout', 'hupkec_billing_data', [
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'hupkec_checkout' ),
            'select_region' => 'Selecciona una región primero',
            'select_comuna' => 'Selecciona una comuna...',
            'rut_error'     => 'RUT inválido. Revisa el RUT ingresado',
        ] );
    }
}
