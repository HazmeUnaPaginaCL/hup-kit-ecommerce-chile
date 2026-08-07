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
        // Solo verificar la zona en el admin — nunca en requests del frontend
        add_action( 'admin_init', [ __CLASS__, 'ensure_global_zone' ] );
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

    public static function get_zone_for_city( $city ) {
        if ( ! $city ) return null;
        $index = self::get_zones_index();
        $key   = self::normalize_city_key( $city );
        return $index[ $key ] ?? null;
    }

    private static function get_zones_index() {
        if ( is_array( self::$zones_index_cache ) ) {
            return self::$zones_index_cache;
        }
        $index = [];
        foreach ( self::get_zones() as $zone ) {
            foreach ( (array) ( $zone['comunas'] ?? [] ) as $comuna ) {
                $key = self::normalize_city_key( $comuna );
                if ( $key !== '' ) {
                    $index[ $key ] = $zone;
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
        $cost_value     = floatval( $posted['cost_value'] ?? 0 );
        $cost_free_from = floatval( $posted['cost_free_from'] ?? 0 );
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
       OBTENER ZONAS (AJAX)
    ========================================================= */
    public function ajax_get_zones() {
        check_ajax_referer( 'hupkec_admin', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die();

        $zones = array_values( self::get_zones() );
        wp_send_json_success( $zones );
    }
}
