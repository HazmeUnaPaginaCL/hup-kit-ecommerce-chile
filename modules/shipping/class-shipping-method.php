<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class HUPKEC_Shipping_Method extends WC_Shipping_Method {

    public function __construct( $instance_id = 0 ) {
        $this->id                 = 'hupkec_shipping';
        $this->instance_id        = absint( $instance_id );
        $this->method_title       = 'HUP - Kit Ecommerce Chile';
        $this->method_description = 'Calcula el costo de envío según la comuna elegida, basado en las zonas configuradas en HUP - Kit Ecommerce Chile.';
        $this->supports           = [ 'shipping-zones', 'instance-settings' ];
        $this->enabled            = 'yes';
        parent::__construct( $instance_id );
        $this->init();
    }

    public function init() {
        $this->init_form_fields();
        $this->init_settings();
        $this->title = $this->get_option( 'title', 'Envío' );
        add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
    }

    public function init_form_fields() {
        $fields = [
            'title' => [
                'title'    => 'Título',
                'type'     => 'text',
                'default'  => 'Envío',
                'desc_tip' => 'Nombre que ve el cliente en el checkout.',
            ],
        ];

        /**
         * Ajustes del método de envío, en la pantalla de zonas de WooCommerce.
         *
         * @since 1.1.0
         * @param array $fields
         */
        $filtered = apply_filters( 'hupkec_shipping_method_form_fields', $fields );
        if ( is_array( $filtered ) ) {
            // 'title' se repone: sin él el método aparecería sin nombre en el checkout.
            $fields = array_merge( $filtered, $fields );
        }

        $this->form_fields = $fields;
    }

    public function calculate_shipping( $package = [] ) {
        // Respetar el toggle del módulo: si el comerciante apagó "Zonas de despacho"
        // no debemos seguir ofreciendo tarifas, aunque la instancia siga persistida
        // en la zona de WooCommerce.
        if ( ! hupkec_module_active( 'shipping' ) ) return;

        $city = $this->get_customer_city( $package );

        if ( ! $city ) return;

        $zone = HUPKEC_Shipping::get_zone_for_city( $city );
        if ( ! $zone ) return;

        $cost = $this->get_zone_cost( $zone, $package );
        if ( $cost === false ) return;

        // Si el costo es 0, mostrar "Envío Gratis" en el label
        $label = $zone['name'];
        if ( $cost == 0 ) {
            $label .= ' — Envío Gratis';
        }

        $args = [
            'id'      => $this->get_rate_id(),
            'label'   => $label,
            'cost'    => $cost,
            'taxes'   => false,
            'package' => $package,
        ];

        /**
         * Argumentos de la tarifa antes de registrarla.
         *
         * Permite ajustar la etiqueta o adjuntar meta_data que un addon pueda
         * leer luego en 'woocommerce_after_shipping_rate' —el hook nativo de
         * WooCommerce— para pintar bajo la tarifa el tiempo de entrega o los
         * días de despacho. No se añade un hook propio equivalente porque el de
         * WooCommerce ya cubre ese punto y duplicarlo solo confundiría.
         *
         * El id no es filtrable: WooCommerce lo usa como clave de la tarifa
         * elegida y cambiarlo dejaría huérfana la selección del cliente.
         *
         * @since 1.1.0
         * @param array $args    Argumentos para add_rate().
         * @param array $zone    Zona que coincidió.
         * @param array $package Paquete de envío de WooCommerce.
         */
        $filtered = apply_filters( 'hupkec_shipping_rate_args', $args, $zone, $package );
        if ( is_array( $filtered ) ) {
            $filtered['id']   = $args['id'];
            $filtered['cost'] = $args['cost'];
            $args             = $filtered;
        }

        $this->add_rate( $args );
    }

    /**
     * Obtiene la ciudad del cliente desde múltiples fuentes.
     * En WC 10.6+ el campo city no llega directo — hay que parsearlo de post_data.
     */
    private function get_customer_city( $package ) {

        // 1. Desde el package destination (más directo)
        if ( ! empty( $package['destination']['city'] ) ) {
            return sanitize_text_field( $package['destination']['city'] );
        }

        // Estos datos llegan durante el cálculo de envío de WooCommerce
        // (update_order_review); WC gestiona el flujo y no expone un nonce propio
        // que podamos verificar aquí. Solo leemos la comuna y la sanitizamos por campo.
        // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        // 2. Parsear el post_data completo que WC envía en update_order_review
        $post_data_str = isset( $_POST['post_data'] ) ? wp_unslash( $_POST['post_data'] ) : '';
        if ( $post_data_str ) {
            $post_data = [];
            wp_parse_str( $post_data_str, $post_data );

            $ship_diff = ! empty( $post_data['ship_to_different_address'] );

            if ( $ship_diff && ! empty( $post_data['shipping_city'] ) ) {
                return sanitize_text_field( $post_data['shipping_city'] );
            }
            if ( ! empty( $post_data['billing_city'] ) ) {
                return sanitize_text_field( $post_data['billing_city'] );
            }
        }

        // 3. Desde los campos directos del POST
        $ship_diff = ! empty( $_POST['ship_to_different_address'] );
        if ( $ship_diff && ! empty( $_POST['shipping_city'] ) ) {
            return sanitize_text_field( wp_unslash( $_POST['shipping_city'] ) );
        }
        if ( ! empty( $_POST['billing_city'] ) ) {
            return sanitize_text_field( wp_unslash( $_POST['billing_city'] ) );
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        // 4. Desde WC()->customer como último recurso
        if ( WC()->customer ) {
            $city = WC()->customer->get_shipping_city() ?: WC()->customer->get_billing_city();
            if ( $city ) return sanitize_text_field( $city );
        }

        return '';
    }

    private function get_zone_cost( $zone, $package ) {
        $type      = $zone['cost_type']      ?? 'free';
        $value     = floatval( $zone['cost_value']      ?? 0 );
        $free_from = floatval( $zone['cost_free_from']  ?? 0 );

        if ( $type === 'free' ) {
            $cost = 0;
        } elseif ( $type === 'cod' ) {
            $cost = 0; // Por pagar (contra reembolso) - costo 0
        } elseif ( $type === 'fixed' ) {
            // Envío gratis si el subtotal del pedido supera el umbral configurado
            $cost = ( $free_from > 0 && $this->get_package_subtotal( $package ) >= $free_from )
                ? 0
                : $value;
        } else {
            // Tipo desconocido para el plugin base: probablemente lo registró un
            // addon con 'hupkec_zone_cost_types'. Sin filtro que lo resuelva, el
            // envío sale gratis, que es el fallo menos dañino de los dos posibles.
            $cost = 0;
        }

        /**
         * Costo calculado de una zona.
         *
         * Es el punto donde un addon implementa tipos de costo propios: fórmulas
         * con cantidad y peso, recargos por clase de envío, tramos.
         *
         * Devolver false cancela la tarifa: la zona coincide pero no se ofrece
         * envío. Cualquier otro valor no numérico se ignora y se conserva el
         * costo del plugin, para que un addon roto no deje la tienda cobrando
         * cifras absurdas.
         *
         * @since 1.1.0
         * @param float|int|false $cost    Costo calculado.
         * @param array           $zone    Zona que coincidió.
         * @param array           $package Paquete de envío de WooCommerce.
         * @param string          $type    Tipo de costo configurado.
         */
        $filtered = apply_filters( 'hupkec_zone_cost', $cost, $zone, $package, $type );

        if ( $filtered === false )  return false;
        // max( 0, ... ) devolvería el entero 0 al recortar, y este filtro debe dar
        // siempre float para no alternar de tipo según el valor que reciba.
        if ( is_numeric( $filtered ) ) return max( 0.0, (float) $filtered );

        return $cost;
    }

    /**
     * Subtotal del paquete para comparar contra el umbral de envío gratis.
     *
     * En tiendas chilenas los precios suelen incluir IVA. Comparar solo contra
     * line_total (neto, sin impuestos) hacía que el cliente viera $50.000 en el
     * carrito mientras el umbral evaluaba $42.017, y el envío gratis nunca se
     * activaba. Cuando la tienda muestra precios con IVA incluido sumamos los
     * impuestos para comparar contra lo mismo que ve el cliente.
     */
    private function get_package_subtotal( $package ) {
        $contents = $package['contents'] ?? [];
        if ( ! is_array( $contents ) || ! $contents ) {
            return 0.0;
        }

        $subtotal = array_sum( array_column( $contents, 'line_total' ) );

        if ( wc_prices_include_tax() ) {
            $subtotal += array_sum( array_column( $contents, 'line_tax' ) );
        }

        return (float) $subtotal;
    }
}
