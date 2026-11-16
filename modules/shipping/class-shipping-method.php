<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class HUPKEC_Shipping_Method extends WC_Shipping_Method {

    public function __construct( $instance_id = 0 ) {
        $this->id                 = 'hupkec_shipping';
        $this->instance_id        = absint( $instance_id );
        $this->method_title       = 'HUP - Kit Ecommerce Chile';
        $this->method_description = 'Calcula el costo de envío según la comuna elegida, basado en las zonas configuradas en HUP - Kit Ecommerce Chile.';
        $this->supports           = [ 'shipping-zones', 'instance-settings', 'instance-settings-modal' ];
        $this->enabled            = 'yes';
        parent::__construct( $instance_id );
        $this->init();
    }

    public function init() {
        $this->init_form_fields();
        $this->init_settings();
        $this->title = $this->get_option( 'title', 'Envío' );
        // Los ajustes por instancia los guarda WooCommerce desde el modal de la
        // zona; esta acción cubre la pantalla de ajustes globales del método.
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

        // El método declara 'instance-settings': WooCommerce pinta y guarda en el
        // modal de la zona los instance_form_fields. Con solo form_fields (globales)
        // el modal "Editar" del método salía vacío y el título no se podía cambiar.
        $this->instance_form_fields = $fields;
        $this->form_fields          = $fields;
    }

    public function calculate_shipping( $package = [] ) {
        // Respetar el toggle del módulo: si el comerciante apagó "Zonas de despacho"
        // no debemos seguir ofreciendo tarifas, aunque la instancia siga persistida
        // en la zona de WooCommerce.
        if ( ! hupkec_module_active( 'shipping' ) ) return;

        $city = $this->get_customer_city( $package );

        if ( ! $city ) {
            $this->log_unresolved_city( $package );
            return;
        }

        // Una tarifa por cada zona que incluye la comuna: si el comerciante creó
        // una zona por courier, el cliente elige entre todas. Hasta la 1.2.0 se
        // ofrecía solo la última zona guardada.
        $rates = [];
        foreach ( HUPKEC_Shipping::get_zones_for_city( $city ) as $zone ) {
            $cost = $this->get_zone_cost( $zone, $package );
            if ( $cost === false ) continue;
            $rates[] = [ 'zone' => $zone, 'amount' => $cost ];
        }

        // Más barata primero: WooCommerce preselecciona la primera tarifa.
        foreach ( HUPKEC_Shipping::sort_by_amount( $rates ) as $rate ) {
            $this->add_zone_rate( $rate['zone'], $rate['amount'], $package );
        }
    }

    /**
     * Registra la tarifa de una zona.
     *
     * @since 1.3.0 (antes vivía dentro de calculate_shipping)
     * @param array $zone    Zona que coincidió.
     * @param float $cost    Costo ya calculado.
     * @param array $package Paquete de envío de WooCommerce.
     * @return void
     */
    private function add_zone_rate( $zone, $cost, $package ) {
        // "Por pagar" también cuesta 0 en el carrito, pero NO es gratis: el
        // cliente paga al recibir. Etiquetarlo "Envío gratis" era engañoso.
        $label = $zone['name'];
        if ( ( $zone['cost_type'] ?? '' ) === 'cod' ) {
            $label .= ' — ' . __( 'Por pagar al recibir', 'hup-kit-ecommerce-chile' );
        } elseif ( (float) $cost === 0.0 ) {
            $label .= ' — ' . __( 'Envío gratis', 'hup-kit-ecommerce-chile' );
        }

        $args = [
            'id'      => $this->get_zone_rate_id( $zone ),
            'label'   => $label,
            'cost'    => $cost,
            'taxes'   => false,
            'package' => $package,
        ];

        /**
         * Argumentos de la tarifa antes de registrarla.
         *
         * Se aplica una vez por cada zona que incluye la comuna del cliente.
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
     * Id de la tarifa de una zona: hupkec_shipping:{instancia}:{zona}.
     *
     * Cada zona necesita un id propio; con uno compartido WooCommerce se queda
     * con una sola tarifa. El id de la zona no cambia al editarla, así que la
     * elección del cliente se conserva aunque el comerciante cambie el costo.
     *
     * @since 1.3.0
     * @param array $zone Zona.
     * @return string
     */
    private function get_zone_rate_id( $zone ) {
        $suffix = sanitize_key( (string) ( $zone['id'] ?? '' ) );
        if ( $suffix === '' ) {
            $suffix = substr( md5( (string) ( $zone['name'] ?? '' ) ), 0, 12 );
        }
        return $this->get_rate_id( $suffix );
    }

    /**
     * Comuna del cliente.
     *
     * Desde la 1.1.2 HUPKEC_Shipping::prepare_packages() la deja en el destino
     * del paquete ANTES de que WooCommerce lo hashee: así cambiar de comuna
     * cambia el hash e invalida las tarifas guardadas en la sesión. Leer la
     * comuna solo del formulario hacía que WooCommerce reutilizara el resultado
     * anterior. La lectura del formulario queda como respaldo, por si otro
     * plugin reemplaza los paquetes.
     */
    private function get_customer_city( $package ) {
        if ( ! empty( $package['destination']['city'] ) ) {
            return sanitize_text_field( $package['destination']['city'] );
        }
        return HUPKEC_Shipping::resolve_city_from_request();
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

    /**
     * Registra, SOLO cuando había datos de dirección pero no se pudo resolver la
     * comuna, qué información llegó. No vuelca valores (datos personales del
     * cliente): solo nombres de campos, para diagnosticar en
     * WooCommerce → Estado → Registros. Si no hay dirección todavía (ej. página
     * del carrito) no registra nada: no es un fallo.
     *
     * @param array $package Paquete de envío de WooCommerce.
     * @return void
     */
    private function log_unresolved_city( $package ) {
        if ( ! function_exists( 'wc_get_logger' ) ) return;

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $address_present = isset( $_POST['post_data'] )
            || ! empty( $_POST['shipping_city'] )
            || ! empty( $_POST['billing_city'] );
        if ( ! $address_present ) return;

        $post_keys = array_map( 'sanitize_key', array_keys( $_POST ) );
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        wc_get_logger()->warning(
            sprintf(
                'HUPKEC: había datos de dirección pero no se pudo resolver la comuna para el envío. package_city=%s, post_keys=%s',
                empty( $package['destination']['city'] ) ? 'no' : 'si',
                implode( ',', $post_keys )
            ),
            [ 'source' => 'hup-kit-ecommerce-chile' ]
        );
    }
}
