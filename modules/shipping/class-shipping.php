<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class HUPKEC_Shipping {
    private static $zones_index_cache = null;

    public function __construct() {
        // Registrar el método de envío personalizado
        add_filter( 'woocommerce_shipping_methods', [ $this, 'register_method' ] );
        add_action( 'wp_ajax_hupkec_save_zone',        [ $this, 'ajax_save_zone'   ] );
        add_action( 'wp_ajax_hupkec_delete_zone',      [ $this, 'ajax_delete_zone' ] );
        add_action( 'wp_ajax_hupkec_get_zones',        [ $this, 'ajax_get_zones'   ] );
        add_action( 'wp_ajax_hupkec_test_comuna',      [ $this, 'ajax_test_comuna' ] );
        // Solo verificar la zona en el admin — nunca en requests del frontend
        add_action( 'admin_init', [ __CLASS__, 'ensure_global_zone' ] );

        // Completar el paquete de envío ANTES de que WooCommerce lo hashee.
        add_filter( 'woocommerce_cart_shipping_packages', [ __CLASS__, 'prepare_packages' ], 20 );
    }

    /* =========================================================
       CACHÉ DE TARIFAS DE WOOCOMMERCE

       WooCommerce guarda las tarifas en la sesión del cliente y solo las
       recalcula si cambia el hash del paquete (productos + dirección + versión
       de caché de envíos). Todo lo que influye en nuestro cálculo tiene que
       estar DENTRO del paquete o invalidar esa caché; si no, el cliente sigue
       viendo el resultado anterior —típicamente "no hay métodos de envío"— y
       solo el modo de depuración de WooCommerce, que se salta la caché, lo
       hace aparecer.
    ========================================================= */

    /**
     * Invalida las tarifas de envío guardadas en todas las sesiones.
     *
     * Se llama al cambiar cualquier ajuste que altere el cálculo: zonas,
     * módulos, comunas, campos del checkout y actualizaciones del plugin.
     *
     * @since 1.1.2
     * @return void
     */
    public static function flush_rates_cache() {
        if ( class_exists( 'WC_Cache_Helper' ) ) {
            WC_Cache_Helper::get_transient_version( 'shipping', true );
        }
    }

    /**
     * Completa cada paquete con lo que necesita nuestro cálculo.
     *
     * - País vacío → Chile. Un checkout personalizado que no envía el país
     *   dejaba al cliente en "Resto del mundo", sin métodos de envío.
     * - País fijo por el editor de campos → siempre Chile.
     * - Comuna vacía en el destino → se resuelve del formulario, para que el
     *   cambio de comuna cambie el hash y WooCommerce recalcule.
     * - 'hupkec' resume el estado del módulo: encenderlo o apagarlo cambia el
     *   hash aunque la caché de WooCommerce no se haya invalidado.
     *
     * @since 1.1.2
     * @param array $packages Paquetes del carrito.
     * @return array
     */
    public static function prepare_packages( $packages ) {
        if ( ! is_array( $packages ) ) return $packages;

        $active = hupkec_module_active( 'shipping' );
        $locked = hupkec_country_locked();

        foreach ( $packages as $i => $package ) {
            if ( ! is_array( $package ) ) continue;
            $dest = $package['destination'] ?? [];

            if ( $locked || ( $active && empty( $dest['country'] ) ) ) {
                $dest['country'] = 'CL';
            }
            if ( ( $dest['country'] ?? '' ) === 'CL' ) {
                if ( ! empty( $dest['state'] ) ) {
                    $dest['state'] = hupkec_normalize_region( $dest['state'] );
                }
                if ( $active && empty( $dest['city'] ) ) {
                    $dest['city'] = self::resolve_city_from_request();
                }
            }

            $packages[ $i ]['destination'] = $dest;
            $packages[ $i ]['hupkec']      = $active ? 'on' : 'off';
        }
        return $packages;
    }

    /**
     * Comuna del cliente cuando el destino del paquete no la trae.
     *
     *   - En 'update_order_review' WooCommerce envía el formulario serializado
     *     en $_POST['post_data']; en el submit final (?wc-ajax=checkout) los
     *     campos llegan sueltos.
     *   - Según "Enviar a una dirección diferente", la comuna real puede venir
     *     en shipping_city o en billing_city.
     *
     * @since 1.1.2 (antes vivía en HUPKEC_Shipping_Method::get_customer_city)
     * @return string Comuna saneada, o '' si no hay.
     */
    public static function resolve_city_from_request() {
        // Estos datos llegan durante el cálculo de envío de WooCommerce, que
        // gestiona su propio flujo y nonce. Solo leemos la comuna y la sanitizamos.
        // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $post_data_str = isset( $_POST['post_data'] ) ? wp_unslash( $_POST['post_data'] ) : '';
        if ( $post_data_str && is_string( $post_data_str ) ) {
            $post_data = [];
            wp_parse_str( $post_data_str, $post_data );
            $city = self::pick_city( $post_data );
            if ( $city !== '' ) return $city;
        }

        $city = self::pick_city( wp_unslash( $_POST ) );
        if ( $city !== '' ) return $city;
        // phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if ( function_exists( 'WC' ) && WC()->customer ) {
            $city = WC()->customer->get_shipping_city() ?: WC()->customer->get_billing_city();
            if ( $city ) return sanitize_text_field( $city );
        }
        return '';
    }

    /**
     * Elige la comuna de un conjunto de campos (shipping_city / billing_city).
     *
     * Prioriza shipping_city si el cliente eligió enviar a otra dirección, pero
     * SIEMPRE cae al otro campo si el preferido viene vacío. El valor "0" (o
     * "false"/"no") del checkbox se trata como desactivado.
     *
     * @param array $data Campos ya des-slasheados.
     * @return string Comuna saneada, o '' si no hay.
     */
    public static function pick_city( $data ) {
        if ( ! is_array( $data ) ) return '';

        $flag      = $data['ship_to_different_address'] ?? '';
        $ship_diff = ! empty( $flag ) && ! in_array( (string) $flag, [ '0', 'false', 'no' ], true );

        $order = $ship_diff ? [ 'shipping_city', 'billing_city' ] : [ 'billing_city', 'shipping_city' ];
        foreach ( $order as $field ) {
            if ( ! empty( $data[ $field ] ) && is_string( $data[ $field ] ) ) {
                return sanitize_text_field( $data[ $field ] );
            }
        }
        return '';
    }

    /* =========================================================
       REGISTRAR MÉTODO
    ========================================================= */
    public function register_method( $methods ) {
        $methods['hupkec_shipping'] = 'HUPKEC_Shipping_Method';
        return $methods;
    }

    /**
     * Asegurar que exista una zona global "HUP - Kit Ecommerce Chile" en WooCommerce
     * con nuestro método registrado y con Chile como ubicación.
     *
     * La ubicación es imprescindible: WooCommerce solo evalúa zonas que tengan filas
     * en woocommerce_shipping_zone_locations. Una zona sin ubicaciones nunca coincide
     * con ningún paquete y el método de envío jamás aparece en el checkout.
     */
    public static function ensure_global_zone() {
        if ( ! class_exists( 'WC_Shipping_Zones' ) ) return;

        // Usar transient para no hacer query en cada pageview del admin
        if ( get_transient( 'hupkec_zone_verified' ) ) return;

        $zone = self::locate_global_zone();

        if ( $zone ) {
            update_option( 'hupkec_wc_global_zone_created', $zone->get_id() );
            self::repair_zone( $zone );
        } else {
            self::create_global_zone();
        }

        set_transient( 'hupkec_zone_verified', 1, HOUR_IN_SECONDS );
    }

    /**
     * Localiza la zona: primero por el ID guardado, y solo como respaldo por nombre.
     * Buscar por nombre era frágil — si el comerciante renombraba la zona, el plugin
     * la daba por inexistente y creaba una duplicada.
     */
    private static function locate_global_zone() {
        $saved_id = (int) get_option( 'hupkec_wc_global_zone_created', 0 );

        if ( $saved_id > 0 ) {
            $zone = WC_Shipping_Zones::get_zone( $saved_id );
            if ( $zone && $zone->get_id() ) {
                return $zone;
            }
        }

        foreach ( WC_Shipping_Zones::get_zones() as $z ) {
            if ( strpos( $z['zone_name'], 'HUP - Kit Ecommerce Chile' ) !== false ) {
                return WC_Shipping_Zones::get_zone( $z['zone_id'] );
            }
        }

        return null;
    }

    /**
     * Repara una zona existente a la que le falte la ubicación o el método.
     */
    private static function repair_zone( $zone ) {
        $changed = false;

        if ( ! $zone->get_zone_locations() ) {
            $zone->add_location( 'CL', 'country' );
            $changed = true;
        }

        $has_method = false;
        foreach ( $zone->get_shipping_methods( false ) as $method ) {
            if ( $method->id === 'hupkec_shipping' ) {
                $has_method = true;
                break;
            }
        }
        if ( ! $has_method ) {
            $zone->add_shipping_method( 'hupkec_shipping' );
            $changed = true;
        }

        if ( $changed ) {
            $zone->save();
            WC_Cache_Helper::get_transient_version( 'shipping', true );
        }
    }

    public static function create_global_zone() {
        if ( ! class_exists( 'WC_Shipping_Zones' ) ) return false;

        $zone = new WC_Shipping_Zone();
        $zone->set_zone_name( 'HUP - Kit Ecommerce Chile (NO BORRAR)' );
        $zone->set_zone_order( 0 );
        $zone->add_location( 'CL', 'country' );
        $zone_id = $zone->save();

        // Agregar método de envío (WooCommerce lo habilita automáticamente)
        $zone->add_shipping_method( 'hupkec_shipping' );

        if ( $zone_id ) {
            update_option( 'hupkec_wc_global_zone_created', $zone_id );
        }
        WC_Cache_Helper::get_transient_version( 'shipping', true );
        delete_transient( 'hupkec_zone_verified' );

        return $zone_id;
    }

    public static function zone_exists() {
        if ( ! class_exists( 'WC_Shipping_Zones' ) ) return false;
        return (bool) self::locate_global_zone();
    }

    /* =========================================================
       LECTURA DE ZONAS HUP
    ========================================================= */
    public static function get_zones() {
        $z = get_option( 'hupkec_zones', [] );
        return is_array( $z ) ? $z : [];
    }

    /**
     * Todas las zonas que incluyen la comuna, en el orden en que se guardaron.
     *
     * Una comuna puede estar en varias zonas (p. ej. una por courier: Starken,
     * Blue, Chilexpress) y el cliente elige entre ellas en el checkout. Hasta la
     * 1.2.0 el índice guardaba una sola zona por comuna y la última pisaba a las
     * anteriores, así que el checkout ofrecía solo una opción.
     *
     * @since 1.3.0
     * @param string $city Comuna.
     * @return array[] Zonas (vacío si la comuna no está en ninguna).
     */
    public static function get_zones_for_city( $city ) {
        if ( ! $city ) return [];
        $index = self::get_zones_index();
        $key   = self::normalize_city_key( $city );
        return isset( $index[ $key ] ) ? array_values( $index[ $key ] ) : [];
    }

    /**
     * Primera zona que incluye la comuna.
     *
     * Se conserva para el código que solo necesita saber si la comuna tiene
     * despacho. Para calcular tarifas usa get_zones_for_city().
     *
     * @param string $city Comuna.
     * @return array|null
     */
    public static function get_zone_for_city( $city ) {
        $zones = self::get_zones_for_city( $city );
        return $zones ? $zones[0] : null;
    }

    /**
     * Índice comuna normalizada → [ id de zona => zona ].
     *
     * Indexar por id evita repetir una zona si su lista trae la misma comuna dos
     * veces (p. ej. con y sin tilde).
     */
    private static function get_zones_index() {
        if ( is_array( self::$zones_index_cache ) ) {
            return self::$zones_index_cache;
        }
        $index = [];
        foreach ( self::get_zones() as $zone_key => $zone ) {
            if ( ! is_array( $zone ) ) continue;
            $zone_id = (string) ( $zone['id'] ?? $zone_key );
            foreach ( (array) ( $zone['comunas'] ?? [] ) as $comuna ) {
                $key = self::normalize_city_key( $comuna );
                if ( $key !== '' ) {
                    $index[ $key ][ $zone_id ] = $zone;
                }
            }
        }
        self::$zones_index_cache = $index;
        return $index;
    }

    private static function normalize_city_key( $city ) {
        $city = is_string( $city ) ? $city : '';
        $city = function_exists( 'mb_strtolower' ) ? mb_strtolower( $city ) : strtolower( $city );
        $city = trim( $city );
        if ( $city === '' ) {
            return '';
        }
        if ( function_exists( 'iconv' ) ) {
            $converted = @iconv( 'UTF-8', 'ASCII//TRANSLIT', $city );
            if ( $converted !== false ) {
                $city = $converted;
            }
        }
        return preg_replace( '/\s+/', ' ', $city );
    }

    /* =========================================================
       GUARDAR ZONA (AJAX)
    ========================================================= */
    /**
     * Tipos de costo disponibles para una zona.
     *
     * @since 1.1.0
     * @return array<string,string> Clave => etiqueta visible.
     */
    public static function get_cost_types() {
        $types = [
            'free'  => 'Gratis',
            'fixed' => 'Monto fijo',
            'cod'   => 'Por pagar (contra reembolso)',
        ];

        /**
         * Tipos de costo de zona.
         *
         * Registrar uno aquí lo hace aparecer en el desplegable del editor y lo
         * acepta al guardar. Para que además calcule algo hace falta engancharse
         * a 'hupkec_zone_cost'.
         *
         * @since 1.1.0
         * @param array<string,string> $types
         */
        $filtered = apply_filters( 'hupkec_zone_cost_types', $types );
        if ( ! is_array( $filtered ) ) return $types;

        $clean = [];
        foreach ( $filtered as $key => $label ) {
            $key = sanitize_key( $key );
            if ( $key === '' || ! is_scalar( $label ) ) continue;
            $clean[ $key ] = sanitize_text_field( (string) $label );
        }

        // Los tipos propios no se pueden retirar: las zonas ya guardadas con
        // ellos se degradarían a "gratis" y la tienda dejaría de cobrar envío.
        return array_merge( $clean, $types );
    }

    public function ajax_save_zone() {
        check_ajax_referer( 'hupkec_admin', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die();

        $posted         = wp_unslash( $_POST );
        $zone_id        = sanitize_text_field( $posted['zone_id'] ?? '' );
        $name           = trim( sanitize_text_field( $posted['name'] ?? '' ) );
        $comunas        = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $posted['comunas'] ?? [] ) ) ) );
        $cost_type      = sanitize_text_field( $posted['cost_type'] ?? 'free' );
        $cost_value     = max( 0.0, floatval( $posted['cost_value'] ?? 0 ) );
        $cost_free_from = max( 0.0, floatval( $posted['cost_free_from'] ?? 0 ) );
        $color          = sanitize_hex_color( $posted['color'] ?? '#378ADD' ) ?: '#378ADD';

        if ( ! array_key_exists( $cost_type, self::get_cost_types() ) ) {
            $cost_type = 'free';
        }

        if ( ! $name || empty( $comunas ) ) {
            wp_send_json_error( 'Nombre y comunas son obligatorios.' );
        }

        $zones = self::get_zones();
        if ( ! $zone_id ) $zone_id = 'hupkec_' . uniqid();

        $zone = [
            'id'             => $zone_id,
            'name'           => $name,
            'comunas'        => $comunas,
            'cost_type'      => $cost_type,
            'cost_value'     => $cost_value,
            'cost_free_from' => $cost_free_from,
            'color'          => $color,
        ];

        /**
         * Datos de la zona antes de guardarla.
         *
         * El array era cerrado, así que un addon no podía persistir nada propio
         * —fórmula de costo, recargos por clase de envío, tiempo de entrega—
         * por mucho que lo recogiera en el JS del editor de zonas.
         *
         * El addon SUMA claves; las del plugin se reponen tras el filtro. Lo que
         * llega en $posted viene del navegador sin sanear: es responsabilidad del
         * addon tratarlo.
         *
         * @since 1.1.0
         * @param array $zone   Zona ya saneada por el plugin.
         * @param array $posted Datos crudos del formulario.
         */
        $extended = apply_filters( 'hupkec_zone_save_data', $zone, $posted );
        if ( is_array( $extended ) ) {
            $zone = array_merge( $extended, $zone );
        }

        $zones[ $zone_id ] = $zone;

        update_option( 'hupkec_zones', $zones , false );
        self::$zones_index_cache = null;
        WC_Cache_Helper::get_transient_version( 'shipping', true );

        if ( function_exists( 'wc_get_logger' ) ) {
            $user = wp_get_current_user();
            wc_get_logger()->info(
                sprintf( 'HUPKEC: Zona guardada por "%s". ID: %s, Nombre: %s', $user->user_login, $zone_id, $name ),
                [ 'source' => 'hup-kit-ecommerce-chile' ]
            );
        }

        wp_send_json_success( $zones[ $zone_id ] );
    }

    /* =========================================================
       ELIMINAR ZONA (AJAX)
    ========================================================= */
    public function ajax_delete_zone() {
        check_ajax_referer( 'hupkec_admin', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die();

        $zone_id = sanitize_text_field( wp_unslash( $_POST['zone_id'] ?? '' ) );
        $zones   = self::get_zones();
        if ( isset( $zones[ $zone_id ] ) ) {
            $deleted_name = $zones[ $zone_id ]['name'] ?? $zone_id;
            unset( $zones[ $zone_id ] );
            update_option( 'hupkec_zones', $zones , false );
            self::$zones_index_cache = null;
            WC_Cache_Helper::get_transient_version( 'shipping', true );

            if ( function_exists( 'wc_get_logger' ) ) {
                $user = wp_get_current_user();
                wc_get_logger()->info(
                    sprintf( 'HUPKEC: Zona eliminada por "%s". ID: %s, Nombre: %s', $user->user_login, $zone_id, $deleted_name ),
                    [ 'source' => 'hup-kit-ecommerce-chile' ]
                );
            }
        }
        wp_send_json_success();
    }

    /* =========================================================
       PROBAR COMUNA (AJAX) — diagnóstico desde el panel
    ========================================================= */
    /**
     * Simula el cálculo de envío para una comuna y revisa la configuración que
     * WooCommerce necesita para ofrecerlo. Es lo primero que hay que mirar
     * cuando una tienda reporta "no hay métodos de envío".
     *
     * @since 1.1.2
     */
    public function ajax_test_comuna() {
        check_ajax_referer( 'hupkec_admin', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die();

        $city     = sanitize_text_field( wp_unslash( $_POST['comuna'] ?? '' ) );
        $subtotal = max( 0.0, floatval( $_POST['subtotal'] ?? 0 ) );
        if ( $city === '' ) wp_send_json_error( 'Elige una comuna.' );

        $checks = [];
        $add    = function ( $ok, $label, $detail = '' ) use ( &$checks ) {
            $checks[] = [ 'ok' => (bool) $ok, 'label' => $label, 'detail' => $detail ];
        };

        $active = hupkec_module_active( 'shipping' );
        $add( $active, 'Módulo "Zonas de despacho" activo', $active ? '' : 'Actívalo en Inicio. Apagado, el método no ofrece tarifas.' );

        $ship_countries = function_exists( 'WC' ) ? WC()->countries->get_shipping_countries() : [];
        $ships_cl       = isset( $ship_countries['CL'] );
        $add( $ships_cl, 'Chile está entre los países de envío', $ships_cl ? '' : 'Revisa WooCommerce → Ajustes → General → Ubicaciones de envío.' );

        // Un producto de ejemplo: las fórmulas de los addons necesitan contenido.
        $product = new WC_Product_Simple();
        $product->set_price( $subtotal );
        $package = [
            'contents'        => [
                'hupkec_test' => [
                    'quantity'          => 1,
                    'data'              => $product,
                    'line_total'        => $subtotal,
                    'line_subtotal'     => $subtotal,
                    'line_tax'          => 0,
                    'line_subtotal_tax' => 0,
                ],
            ],
            'contents_cost'   => $subtotal,
            'applied_coupons' => [],
            'user'            => [ 'ID' => get_current_user_id() ],
            'destination'     => [ 'country' => 'CL', 'state' => '', 'postcode' => '', 'city' => $city, 'address' => '', 'address_1' => '', 'address_2' => '' ],
        ];
        $wc_zone   = WC_Shipping_Zones::get_zone_matching_package( $package );
        $our_zone  = self::locate_global_zone();
        $zone_ok   = $our_zone && $wc_zone->get_id() === $our_zone->get_id();
        $add(
            $zone_ok,
            'WooCommerce asigna Chile a la zona del plugin',
            $zone_ok ? $wc_zone->get_zone_name() : sprintf( 'Coincide con "%s". Una zona de WooCommerce más arriba en la lista puede estar capturando a Chile.', $wc_zone->get_zone_name() )
        );

        $method_ok = false;
        foreach ( $wc_zone->get_shipping_methods( true ) as $m ) {
            if ( $m->id === 'hupkec_shipping' ) { $method_ok = true; break; }
        }
        $add( $method_ok, 'El método de envío del plugin está habilitado en esa zona', $method_ok ? '' : 'Usa "Verificar / Recrear zona de envío".' );

        $blocks = function_exists( 'has_block' ) && wc_get_page_id( 'checkout' ) > 0
            && has_block( 'woocommerce/checkout', wc_get_page_id( 'checkout' ) );
        $add( ! $blocks, 'El checkout es el clásico (no de bloques)', $blocks ? 'El checkout de bloques no es compatible. Usa el shortcode [woocommerce_checkout].' : '' );

        $zones = self::get_zones_for_city( $city );
        $add(
            (bool) $zones,
            'La comuna pertenece a una zona de despacho',
            $zones ? implode( ', ', wp_list_pluck( $zones, 'name' ) ) : 'Agrégala a una zona.'
        );

        // Una opción por zona, con el mismo cálculo y orden que el checkout.
        $types   = self::get_cost_types();
        WC()->shipping(); // Asegura que la clase del método esté cargada.
        $method  = new HUPKEC_Shipping_Method();
        $results = [];
        foreach ( $zones as $zone ) {
            $type = $zone['cost_type'] ?? 'free';
            $cost = $method->get_zone_cost( $zone, $package );
            if ( $cost === false ) continue;

            // Texto plano: el panel lo escapa al pintarlo. wc_price() codifica
            // el símbolo ($ → &#36;), así que hay que decodificarlo.
            $price = $cost > 0 ? html_entity_decode( wp_strip_all_tags( wc_price( $cost ) ), ENT_QUOTES, 'UTF-8' ) : '';
            if ( $type === 'cod' ) {
                $price = $price !== '' ? 'Por pagar al recibir + ' . $price : 'Por pagar al recibir';
            }
            $results[] = [
                'zone'   => $zone['name'],
                'type'   => $types[ $type ] ?? $type,
                'cost'   => $price !== '' ? $price : 'Gratis',
                'amount' => (float) $cost,
            ];
        }
        if ( $zones && ! $results ) {
            $add( false, 'La zona ofrece una tarifa para este carrito', 'El cálculo de costo la canceló para este carrito.' );
        } elseif ( $results && has_filter( 'hupkec_zone_cost' ) ) {
            $add( true, 'Costo calculado con un producto de ejemplo', 'Sin peso ni clase de envío: con fórmulas o recargos por clase, el costo real puede cambiar.' );
        }
        $results = self::sort_by_amount( $results );

        wp_send_json_success( [ 'checks' => $checks, 'results' => $results ] );
    }

    /**
     * Ordena por 'amount' de menor a mayor. En los empates conserva el orden
     * de guardado: usort es estable desde PHP 8.0.
     *
     * WooCommerce preselecciona la primera tarifa, así que la más barata queda
     * elegida por defecto.
     *
     * @since 1.3.0
     * @param array[] $items Elementos con clave 'amount'.
     * @return array[]
     */
    public static function sort_by_amount( $items ) {
        usort( $items, function ( $a, $b ) {
            return (float) $a['amount'] <=> (float) $b['amount'];
        } );
        return $items;
    }

    /* =========================================================
       OBTENER ZONAS (AJAX)
    ========================================================= */
    public function ajax_get_zones() {
        check_ajax_referer( 'hupkec_admin', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die();

        $zones = array_values( self::get_zones() );
        wp_send_json_success( $zones );
    }
}
