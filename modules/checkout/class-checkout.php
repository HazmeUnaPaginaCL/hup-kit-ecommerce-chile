<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class HUPKEC_Checkout {

    public function __construct() {
        /*
         * ESTRATEGIA ANTI-LOOP:
         * - En locale_default y locale_base: ELIMINAR propiedades con unset()
         *   en vez de sobreescribir — WC no detecta cambio, no recalcula.
         * - NUNCA filtrar woocommerce_get_country_locale (causa loop infinito).
         * - El control real de campos va en billing_fields / shipping_fields.
         * - País se renderiza como texto estático vía woocommerce_checkout_fields.
         */
        add_filter( 'woocommerce_get_country_locale_default', [ $this, 'strip_locale_overrides' ], 9999 );
        add_filter( 'woocommerce_get_country_locale_base',    [ $this, 'strip_locale_overrides' ], 9999 );

        add_filter( 'woocommerce_billing_fields',  [ $this, 'apply_billing_fields'  ], 9999, 2 );
        add_filter( 'woocommerce_shipping_fields', [ $this, 'apply_shipping_fields' ], 9999, 2 );
        add_filter( 'woocommerce_checkout_fields', [ $this, 'apply_checkout_fields' ], 9999 );

        // País estático en el checkout
        add_action( 'woocommerce_before_checkout_billing_form',  [ $this, 'render_country_static_billing'  ] );
        add_action( 'woocommerce_before_checkout_shipping_form', [ $this, 'render_country_static_shipping' ] );

        add_action( 'woocommerce_checkout_process',           [ $this, 'validate_custom_fields' ] );
        // Hook actualizado — woocommerce_checkout_create_order reemplaza a
        // woocommerce_checkout_update_order_meta (deprecado en WC 9.0)
        add_action( 'woocommerce_checkout_create_order', [ $this, 'save_custom_fields' ], 10, 2 );
        add_filter( 'woocommerce_email_order_meta_fields',    [ $this, 'add_fields_to_email' ], 10, 3 );
        add_action( 'woocommerce_admin_order_data_after_billing_address', [ $this, 'display_in_order' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_order_screen_style' ] );

        // Mostrar campos personalizados en la página de confirmación del pedido
        add_action( 'woocommerce_thankyou', [ $this, 'display_on_thankyou' ], 15 );

        add_action( 'wp_ajax_hupkec_save_fields',  [ $this, 'ajax_save_fields'  ] );
        add_action( 'wp_ajax_hupkec_reset_fields', [ $this, 'ajax_reset_fields' ] );
    }

    /* =========================================================
       STRIP LOCALE — solo unset, nunca sobreescribir
       Elimina label/class/placeholder del locale para que
       nuestros filtros de billing_fields tengan la última palabra
    ========================================================= */
    public function strip_locale_overrides( $fields ) {
        if ( ! is_array( $fields ) ) return $fields;
        foreach ( $fields as $key => $props ) {
            if ( ! is_array( $props ) ) continue;
            unset( $fields[ $key ]['label'] );
            unset( $fields[ $key ]['placeholder'] );
            unset( $fields[ $key ]['class'] );
            unset( $fields[ $key ]['priority'] );
        }
        return $fields;
    }

    /* =========================================================
       PAÍS ESTÁTICO — ocultar el campo select y mostrar texto
    ========================================================= */
    public function render_country_static_billing() {
        $this->render_country_static( 'billing' );
    }
    public function render_country_static_shipping() {
        $this->render_country_static( 'shipping' );
    }

    private function render_country_static( $section ) {
        // Solo ocultar el select de país si el módulo de checkout está activo
        if ( ! hupkec_module_active( 'checkout' ) ) return;

        $fields = hupkec_get_fields( $section );
        foreach ( $fields as $f ) {
            if ( $f['type'] !== 'country' || empty( $f['enabled'] ) ) continue;
            ?>
            <p class="form-row form-row-wide hupkec-country-static">
                <label><?php echo esc_html( $f['label'] ); ?> <abbr class="required" title="obligatorio">*</abbr></label>
                <span class="hupkec-country-value">Chile</span>
            </p>
            <?php
            // El select de país nativo de WooCommerce se oculta con una regla CSS
            // encolada en HUPKEC_Frontend::enqueue_assets (wp_add_inline_style), no
            // con un <style> incrustado aquí — así lo exigen las normas de
            // WordPress.org sobre encolado de estilos.
            break;
        }
    }

    /* =========================================================
       APLICAR CAMPOS AL CHECKOUT
       Estrategia: partir del array ORIGINAL de WC, solo
       sobreescribir label, class, priority, placeholder.
       Nunca reconstruir desde cero.
    ========================================================= */
    public function apply_billing_fields( $wc_fields, $country ) {
        return $this->apply_fields( 'billing', $wc_fields );
    }

    public function apply_shipping_fields( $wc_fields, $country ) {
        return $this->apply_fields( 'shipping', $wc_fields );
    }

    private function apply_fields( $section, $wc_fields ) {
        $config = hupkec_get_fields( $section );
        if ( empty( $config ) ) return $wc_fields;

        $result   = [];
        $priority = 10;

        foreach ( $config as $field ) {
            $key  = $field['key'];
            $type = $field['type'];

            // País: si el módulo checkout está activo, eliminamos el campo nativo
            // y mostramos el estático mediante render_country_static_billing/shipping
            if ( $type === 'country' ) {
                if ( hupkec_module_active( 'checkout' ) && ! empty( $field['enabled'] ) ) {
                    // No incluir el campo nativo - se mostrará el estático
                    $priority += 10;
                    continue;
                }
                if ( isset( $wc_fields[ $key ] ) ) {
                    $wc_fields[ $key ]['priority'] = $priority;
                    $result[ $key ] = $wc_fields[ $key ];
                }
                $priority += 10;
                continue;
            }

            // Campos desactivados, u ocultos por lógica condicional: fuera
            if ( empty( $field['enabled'] ) || ! $this->field_is_visible( $field, $section, 'render' ) ) {
                $priority += 10;
                continue;
            }

            $width   = hupkec_width_class( $field['width'] ?? 'wide' );
            $classes = [ $width ];
            if ( ! empty( $field['cssclass'] ) ) {
                $classes[] = $field['cssclass'];
            }

            // Campo nativo de WC que ya existe
            if ( isset( $wc_fields[ $key ] ) ) {
                $base                = $wc_fields[ $key ];
                $base['label']       = $field['label'];
                $base['placeholder'] = $field['placeholder'] ?? '';
                $base['required']    = ! empty( $field['required'] );
                $base['class']       = $classes;
                $base['priority']    = $priority;
                if ( ( $field['default'] ?? '' ) !== '' ) {
                    $base['default'] = $field['default'];
                }
                $result[ $key ]      = $base;

            // Campo personalizado HUP (billing_rut, select, checkbox, etc.)
            } else {
                // WooCommerce renderiza estos tipos nativamente. El caso default
                // de woocommerce_form_field genera <input type="{$type}"> para
                // text/number/tel/email; y maneja textarea/select/radio/checkbox.
                $allowed_types = [ 'tel', 'email', 'textarea', 'number', 'select', 'radio', 'checkbox' ];
                $wc_type       = in_array( $type, $allowed_types, true ) ? $type : 'text';

                $args = [
                    'type'     => $wc_type,
                    'label'    => $field['label'],
                    'required' => ! empty( $field['required'] ),
                    'class'    => $classes,
                    'priority' => $priority,
                    'custom'   => true,
                ];

                // Placeholder solo para campos de entrada de texto
                if ( in_array( $wc_type, [ 'text', 'number', 'tel', 'email', 'textarea' ], true ) ) {
                    $args['placeholder'] = $field['placeholder'] ?? '';
                }

                // Opciones para select / radio
                if ( in_array( $wc_type, hupkec_field_types_with_options(), true ) ) {
                    $options = [];
                    if ( $wc_type === 'select' ) {
                        // Primera opción vacía como placeholder de la lista
                        $options[''] = ( $field['placeholder'] ?? '' ) !== '' ? $field['placeholder'] : '—';
                    }
                    foreach ( (array) ( $field['options'] ?? [] ) as $opt ) {
                        $ov = (string) ( $opt['value'] ?? '' );
                        if ( $ov === '' ) continue;
                        $options[ $ov ] = (string) ( $opt['label'] ?? $ov );
                    }
                    $args['options'] = $options;
                }

                // Valor por defecto
                if ( ( $field['default'] ?? '' ) !== '' ) {
                    $args['default'] = $field['default'];
                }

                /**
                 * Argumentos que recibe woocommerce_form_field() para un campo
                 * personalizado.
                 *
                 * Es lo que permite que un tipo nuevo se pinte de verdad: sin
                 * esto, todo lo que no sea un tipo nativo de WooCommerce se
                 * renderiza como un input de texto.
                 *
                 * @since 1.1.0
                 * @param array  $args    Argumentos para woocommerce_form_field().
                 * @param array  $field   Configuración del campo.
                 * @param string $section billing, shipping o additional.
                 */
                $filtered_args = apply_filters( 'hupkec_field_render_args', $args, $field, $section );
                if ( is_array( $filtered_args ) && ! empty( $filtered_args['type'] ) ) {
                    $args = $filtered_args;
                }

                $result[ $key ] = $args;
            }

            $priority += 10;
        }

        // Seguridad: si algún campo esencial de WC quedó fuera, lo agregamos al final
        $essential = [ "{$section}_country", "{$section}_first_name", "{$section}_last_name",
                       "{$section}_address_1", "{$section}_state", "{$section}_city",
                       "{$section}_phone" ];
        // billing_email solo existe en el formulario de facturación, no en el de envío
        if ( $section === 'billing' ) {
            $essential[] = 'billing_email';
        }
        foreach ( $essential as $ek ) {
            if ( ! isset( $result[ $ek ] ) && isset( $wc_fields[ $ek ] ) ) {
                $result[ $ek ] = $wc_fields[ $ek ];
            }
        }

        return $result;
    }

    /* =========================================================
       CHECKOUT FIELDS — para campos adicionales (order_comments)
    ========================================================= */
    public function apply_checkout_fields( $fields ) {
        $config = hupkec_get_fields( 'additional' );
        if ( empty( $config ) ) return $fields;

        foreach ( $config as $field ) {
            $key = $field['key'];
            if ( empty( $field['enabled'] ) ) {
                unset( $fields['order'][ $key ] );
                continue;
            }
            if ( isset( $fields['order'][ $key ] ) ) {
                $fields['order'][ $key ]['label']       = $field['label'];
                $fields['order'][ $key ]['placeholder'] = $field['placeholder'] ?? '';
                $fields['order'][ $key ]['required']    = ! empty( $field['required'] );
            }
        }

        return $fields;
    }

    /* =========================================================
       VALIDAR Y GUARDAR CAMPOS PERSONALIZADOS
    ========================================================= */
    public function validate_custom_fields() {
        // Se ejecuta en woocommerce_checkout_process: WooCommerce ya verificó el nonce.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        foreach ( [ 'billing', 'shipping' ] as $section ) {
            if ( $section === 'shipping' && empty( $_POST['ship_to_different_address'] ) ) continue;
            foreach ( hupkec_get_fields( $section ) as $field ) {
                if ( empty( $field['enabled'] ) || empty( $field['custom'] ) ) continue;

                // Un campo oculto por lógica condicional no se exige ni se valida:
                // el cliente no llegó a verlo.
                if ( ! $this->field_is_visible( $field, $section, 'validate' ) ) continue;

                // Sanitizado en save_custom_fields(); aquí solo se comprueba presencia
                // y se pasa al filtro de validación, que recibe el valor sin tratar.
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                $value = isset( $_POST[ $field['key'] ] ) ? wp_unslash( $_POST[ $field['key'] ] ) : '';

                if ( ! empty( $field['required'] ) && empty( $_POST[ $field['key'] ] ) ) {
                    wc_add_notice( sprintf( 'El campo "%s" es obligatorio.', $field['label'] ), 'error' );
                    continue;
                }

                /**
                 * Validación adicional de un campo del checkout.
                 *
                 * Devolver un mensaje no vacío lo muestra como error y bloquea el
                 * pedido. Es la contraparte en servidor de
                 * 'hupkec.fieldModal.validationError': sin ella, cualquier regla
                 * se salta desde las herramientas del navegador.
                 *
                 * @since 1.1.0
                 * @param string $error   Mensaje de error; vacío si todo bien.
                 * @param array  $field   Configuración del campo.
                 * @param mixed  $value   Valor recibido, sin sanear.
                 * @param string $section billing o shipping.
                 */
                $error = apply_filters( 'hupkec_field_validation_error', '', $field, $value, $section );
                if ( is_string( $error ) && $error !== '' ) {
                    wc_add_notice( $error, 'error' );
                }
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }

    /* =========================================================
       GUARDAR CAMPOS PERSONALIZADOS EN EL PEDIDO (HPOS compatible)
       NOTA: woocommerce_checkout_create_order se ejecuta DURANTE la
       creación del pedido. NO llamar $order->save() aquí; WooCommerce
       lo hace después de que todos los hooks completen.
    ========================================================= */
    public function save_custom_fields( $order, $data = [] ) {
        if ( ! $order instanceof WC_Abstract_Order ) return;

        // woocommerce_checkout_create_order: WooCommerce ya verificó el nonce del checkout.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        foreach ( [ 'billing', 'shipping' ] as $section ) {
            // No guardar envío si "enviar a dirección diferente" no está marcado
            if ( $section === 'shipping' && empty( $_POST['ship_to_different_address'] ) ) continue;

            foreach ( hupkec_get_fields( $section ) as $field ) {
                // Solo guardar campos personalizados habilitados
                if ( empty( $field['enabled'] ) || empty( $field['custom'] ) ) continue;

                $key  = $field['key'];
                $type = $field['type'] ?? 'text';

                // Checkbox no enviado = no marcado
                if ( ! isset( $_POST[ $key ] ) ) {
                    if ( $type === 'checkbox' ) {
                        $order->update_meta_data( '_' . $key, '0' );
                    }
                    continue;
                }

                // Sanitizado por tipo en sanitize_field_value() (textarea/select/checkbox/etc.).
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                $raw = wp_unslash( $_POST[ $key ] );
                $val = $this->sanitize_field_value( $type, $raw, $field );
                $order->update_meta_data( '_' . $key, $val );
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        // NO llamar $order->save() — WooCommerce lo hace tras este hook
    }

    /* =========================================================
       SANITIZACIÓN POR TIPO DE CAMPO
       Cada tipo se sanitiza con la función apropiada; select/radio
       además se validan contra su lista de opciones permitidas.
    ========================================================= */
    private function sanitize_field_value( $type, $raw, $field ) {
        switch ( $type ) {
            case 'textarea':
                $value = sanitize_textarea_field( $raw );
                break;

            case 'checkbox':
                $value = ! empty( $raw ) ? '1' : '0';
                break;

            case 'select':
            case 'radio':
                $val     = sanitize_text_field( $raw );
                $allowed = array_map(
                    static function( $o ) { return (string) ( $o['value'] ?? '' ); },
                    (array) ( $field['options'] ?? [] )
                );
                $value = in_array( (string) $val, $allowed, true ) ? $val : '';
                break;

            default:
                $value = sanitize_text_field( $raw );
        }

        /**
         * Valor saneado de un campo antes de guardarlo en el pedido.
         *
         * Un tipo nuevo cae en el 'default' y se trata como texto plano. Este
         * filtro permite saneárselo a medida (una fecha, un adjunto). El valor
         * que llega ya pasó por la sanitización del plugin: reemplazarlo por el
         * crudo sin tratar sería reintroducir el problema que esto evita.
         *
         * @since 1.1.0
         * @param string $value Valor ya saneado.
         * @param string $type  Tipo del campo.
         * @param mixed  $raw   Valor crudo, sin sanear.
         * @param array  $field Configuración del campo.
         */
        $value = apply_filters( 'hupkec_field_sanitize_value', $value, $type, $raw, $field );

        // El meta del pedido tiene que ser escalar: un filtro que devuelva un
        // array o un objeto rompería el guardado con un error poco descifrable.
        return is_scalar( $value ) ? $value : '';
    }

    /**
     * ¿Este campo está visible ahora mismo?
     *
     * Siempre true en el plugin base. Existe para que la lógica condicional del
     * addon se evalúe también en PHP: un campo escondido solo con JavaScript
     * sigue siendo obligatorio en el servidor, así que bloquearía el pedido de
     * un cliente que nunca llegó a verlo. Y al revés, un campo mostrado solo por
     * JS podría saltarse su validación.
     *
     * @since 1.1.0
     * @param array  $field   Configuración del campo.
     * @param string $section billing, shipping o additional.
     * @param string $context 'render' o 'validate'.
     * @return bool
     */
    private function field_is_visible( $field, $section, $context ) {
        /**
         * Visibilidad de un campo del checkout.
         *
         * @since 1.1.0
         * @param bool   $visible
         * @param array  $field
         * @param string $section
         * @param string $context
         */
        return (bool) apply_filters( 'hupkec_field_is_visible', true, $field, $section, $context );
    }

    /* =========================================================
       EMAIL — incluir campos personalizados
    ========================================================= */
    public function add_fields_to_email( $fields, $sent_to_admin, $order ) {
        $seen = [];

        foreach ( [ 'billing', 'shipping' ] as $section ) {
            foreach ( hupkec_get_fields( $section ) as $field ) {
                if ( empty( $field['enabled'] ) || empty( $field['custom'] ) ) continue;
                if ( ! hupkec_field_shows_in( $field, 'email' ) ) continue;
                $meta_key = '_' . $field['key'];
                $val      = $order->get_meta( $meta_key );
                if ( ! $val ) continue;

                // Deduplicar: si billing_rut y shipping_rut tienen el mismo valor, solo incluir una vez
                $dedup = $field['label'] . '||' . $val;
                if ( isset( $seen[ $dedup ] ) ) continue;
                $seen[ $dedup ] = true;

                $fields[ ltrim( $meta_key, '_' ) ] = [
                    'label' => $field['label'],
                    'value' => hupkec_field_display_value( $field, $val ),
                ];
            }
        }
        return $fields;
    }

    /* =========================================================
       MOSTRAR EN ADMIN
    ========================================================= */

    /**
     * Estilo de la caja "Campos adicionales del checkout" en la ficha del pedido.
     *
     * Va encolado —handle propio sin archivo + wp_add_inline_style— en vez de
     * atributos style en el HTML, por coherencia con las normas de WordPress.org.
     * Solo se carga en las dos pantallas de pedidos: la clásica (shop_order) y
     * la de HPOS (woocommerce_page_wc-orders).
     */
    public function enqueue_order_screen_style() {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || ! in_array( $screen->id, [ 'shop_order', 'woocommerce_page_wc-orders' ], true ) ) {
            return;
        }

        wp_register_style( 'hupkec-order-fields', false, [], HUPKEC_VERSION );
        wp_enqueue_style( 'hupkec-order-fields' );
        wp_add_inline_style(
            'hupkec-order-fields',
            '.hupkec-order-fields{margin-top:8px;padding:8px;background:#f9f9f9;border-left:3px solid #aaa;}'
        );
    }

    public function display_in_order( $order ) {
        $custom_fields = [];
        $seen          = []; // para deduplicar label+valor idénticos (ej: billing_rut = shipping_rut)

        foreach ( [ 'billing', 'shipping' ] as $section ) {
            foreach ( hupkec_get_fields( $section ) as $field ) {
                if ( empty( $field['enabled'] ) || empty( $field['custom'] ) ) continue;
                if ( ! hupkec_field_shows_in( $field, 'order' ) ) continue;
                $val = $order->get_meta( '_' . $field['key'] );
                if ( ! $val ) continue;

                $dedup = $field['label'] . '||' . $val;
                if ( isset( $seen[ $dedup ] ) ) continue; // mismo label + mismo valor → mostrar solo una vez
                $seen[ $dedup ] = true;

                $custom_fields[] = [ 'label' => $field['label'], 'value' => hupkec_field_display_value( $field, $val ) ];
            }
        }
        if ( empty( $custom_fields ) ) return;

        echo '<div class="hupkec-order-fields">';
        echo '<strong>Campos adicionales del checkout:</strong>';
        foreach ( $custom_fields as $cf ) {
            echo '<br><small><strong>' . esc_html( $cf['label'] ) . ':</strong> ' . esc_html( $cf['value'] ) . '</small>';
        }
        echo '</div>';
    }

    /* =========================================================
       PÁGINA DE CONFIRMACIÓN — "Gracias por tu pedido"
       Muestra campos personalizados (RUT, etc.) con deduplicación.
    ========================================================= */
    public function display_on_thankyou( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $custom_fields = [];
        $seen          = [];

        foreach ( [ 'billing', 'shipping' ] as $section ) {
            foreach ( hupkec_get_fields( $section ) as $field ) {
                if ( empty( $field['enabled'] ) || empty( $field['custom'] ) ) continue;
                if ( ! hupkec_field_shows_in( $field, 'order' ) ) continue;
                $val = $order->get_meta( '_' . $field['key'] );
                if ( ! $val ) continue;

                $dedup = $field['label'] . '||' . $val;
                if ( isset( $seen[ $dedup ] ) ) continue;
                $seen[ $dedup ] = true;

                $custom_fields[] = [ 'label' => $field['label'], 'value' => hupkec_field_display_value( $field, $val ) ];
            }
        }
        if ( empty( $custom_fields ) ) return;

        // Sin atributos style: las reglas de .hupkec-thankyou-fields viven en
        // hupkec-checkout.css, que ya está encolado en esta página (el endpoint
        // order-received forma parte de la página de checkout).
        echo '<section class="woocommerce-order-details hupkec-thankyou-fields">';
        echo '<h2 class="woocommerce-order-details__title">Información adicional</h2>';
        echo '<table class="woocommerce-table woocommerce-table--order-details shop_table order_details">';
        echo '<tbody>';
        foreach ( $custom_fields as $cf ) {
            echo '<tr>';
            echo '<th scope="row">' . esc_html( $cf['label'] ) . ':</th>';
            echo '<td>' . esc_html( $cf['value'] ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</section>';
    }

    /* =========================================================
       AJAX
    ========================================================= */
    public function ajax_save_fields() {
        check_ajax_referer( 'hupkec_admin', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die();

        // JSON decodificado; se valida que sea array y cada campo se sanitiza abajo.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $decoded = json_decode( wp_unslash( $_POST['fields'] ?? '' ), true );
        if ( ! is_array( $decoded ) ) { wp_send_json_error( 'Datos inválidos.' ); }

        // Tipos permitidos al guardar: los nativos de WooCommerce, más los tipos
        // personalizados registrados (hupkec_field_types). Sin esto, un tipo que
        // un addon añade al desplegable se degradaría a 'text' al guardar, en
        // silencio y sin que el comerciante entienda por qué.
        $native_types  = [ 'text', 'number', 'textarea', 'tel', 'email', 'select', 'radio', 'checkbox', 'country', 'state', 'city' ];
        $allowed_types = array_values( array_unique( array_merge(
            $native_types,
            array_keys( hupkec_custom_field_types() )
        ) ) );

        /**
         * Tipos de campo aceptados al guardar la configuración del checkout.
         *
         * @since 1.1.0
         * @param string[] $allowed_types
         */
        $filtered_types = apply_filters( 'hupkec_field_allowed_types', $allowed_types );
        if ( is_array( $filtered_types ) ) {
            $filtered_types = array_filter( array_map( 'sanitize_key', $filtered_types ) );
            // Los nativos no se pueden retirar: dejarían de guardarse los campos
            // propios del checkout, que son la base del plugin.
            $allowed_types = array_values( array_unique( array_merge( $native_types, $filtered_types ) ) );
        }

        $clean = [];
        foreach ( [ 'billing', 'shipping', 'additional' ] as $sec ) {
            $clean[ $sec ] = [];
            foreach ( ( $decoded[ $sec ] ?? [] ) as $f ) {
                $ftype = sanitize_text_field( $f['type'] ?? 'text' );
                if ( ! in_array( $ftype, $allowed_types, true ) ) $ftype = 'text';

                // Clase CSS: sanitizar cada token por separado
                $cssclass = '';
                if ( ! empty( $f['cssclass'] ) ) {
                    $parts    = preg_split( '/\s+/', trim( (string) $f['cssclass'] ) );
                    $parts    = array_filter( array_map( 'sanitize_html_class', $parts ) );
                    $cssclass = implode( ' ', $parts );
                }

                // Opciones (solo select/radio)
                $options = [];
                if ( in_array( $ftype, hupkec_field_types_with_options(), true ) && ! empty( $f['options'] ) && is_array( $f['options'] ) ) {
                    foreach ( $f['options'] as $opt ) {
                        $ov = sanitize_text_field( $opt['value'] ?? '' );
                        $ol = sanitize_text_field( $opt['label'] ?? '' );
                        if ( $ov === '' && $ol === '' ) continue;
                        if ( $ov === '' ) $ov = $ol;
                        if ( $ol === '' ) $ol = $ov;
                        $options[] = [ 'value' => $ov, 'label' => $ol ];
                    }
                }

                $field = [
                    'key'           => sanitize_key( $f['key'] ?? '' ),
                    'type'          => $ftype,
                    'label'         => sanitize_text_field( $f['label'] ?? '' ),
                    'placeholder'   => sanitize_text_field( $f['placeholder'] ?? '' ),
                    'default'       => sanitize_text_field( $f['default'] ?? '' ),
                    'cssclass'      => $cssclass,
                    'options'       => $options,
                    'required'      => ! empty( $f['required'] ),
                    'enabled'       => ! empty( $f['enabled'] ),
                    'width'         => in_array( $f['width'] ?? '', [ 'wide','first','last' ], true ) ? $f['width'] : 'wide',
                    'custom'        => ! empty( $f['custom'] ),
                    'locked'        => ! empty( $f['locked'] ),
                    // Ausente = visible, para que un campo guardado antes de existir
                    // estos interruptores no desaparezca de golpe.
                    'show_in_email' => hupkec_field_shows_in( $f, 'email' ),
                    'show_in_order' => hupkec_field_shows_in( $f, 'order' ),
                ];

                /**
                 * Esquema del campo antes de guardarlo.
                 *
                 * Hasta la 1.0.0 este array era cerrado: cualquier propiedad que
                 * no estuviera aquí se descartaba al guardar, de modo que un addon
                 * no podía persistir nada propio (reglas condicionales, validaciones)
                 * por mucho que las recogiera en el JS del modal.
                 *
                 * El addon SUMA claves; las del plugin se reponen después del
                 * filtro, y es responsabilidad del addon sanear las suyas: lo que
                 * llega en $raw viene del navegador sin tratar.
                 *
                 * @since 1.1.0
                 * @param array  $field Campo ya saneado por el plugin.
                 * @param array  $raw   Campo tal como llegó del modal, sin sanear.
                 * @param string $sec   Sección: billing, shipping o additional.
                 */
                $extended = apply_filters( 'hupkec_field_schema', $field, $f, $sec );
                if ( is_array( $extended ) ) {
                    $field = array_merge( $extended, $field );
                }

                $clean[ $sec ][] = $field;
            }
        }
        update_option( 'hupkec_fields', $clean , false );
        wp_send_json_success();
    }

    public function ajax_reset_fields() {
        check_ajax_referer( 'hupkec_admin', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die();
        $d = hupkec_default_fields();
        update_option( 'hupkec_fields', $d , false );
        wp_send_json_success( $d );
    }
}

