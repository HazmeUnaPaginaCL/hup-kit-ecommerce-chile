<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class HUPKEC_Settings {

    public function __construct() {
        // Pedidos y clientes guardados antes de la 1.1.2 tienen la región con el
        // código corto ('AT'). Se traduce al leerla —direcciones formateadas,
        // correos, etiquetas, checkout precargado— sin reescribir la base de datos.
        foreach ( [ 'order', 'customer' ] as $object ) {
            foreach ( [ 'billing', 'shipping' ] as $section ) {
                add_filter( "woocommerce_{$object}_get_{$section}_state", [ __CLASS__, 'normalize_legacy_state' ], 10, 2 );
            }
        }
    }

    /**
     * Traduce un código de región antiguo, solo si la dirección es de Chile.
     *
     * @since 1.1.2
     * @param string        $state  Región guardada.
     * @param WC_Data|mixed $object Pedido o cliente.
     * @return string
     */
    public static function normalize_legacy_state( $state, $object = null ) {
        if ( ! is_string( $state ) || strlen( $state ) !== 2 ) return $state;
        if ( ! is_object( $object ) ) return $state;

        $filter  = current_filter();
        $section = strpos( $filter, '_shipping_state' ) !== false ? 'shipping' : 'billing';
        $getter  = "get_{$section}_country";
        if ( ! method_exists( $object, $getter ) || $object->{$getter}() !== 'CL' ) return $state;

        return hupkec_normalize_region( $state );
    }

    public static function get_regions() {
        $all = hupkec_get_regions_base();
        $settings = get_option( 'hupkec_settings', [] );
        $enabled  = $settings['enabled_regions'] ?? [];
        if ( empty( $enabled ) ) return $all;
        return array_intersect_key( $all, array_flip( $enabled ) );
    }

    public static function get_comunas() {
        $all      = hupkec_get_comunas_base();
        $settings = get_option( 'hupkec_settings', [] );
        $custom   = $settings['custom_sectors'] ?? [];

        $enabled_regions = $settings['enabled_regions'] ?? [];
        $enabled_comunas = $settings['enabled_comunas'] ?? [];

        // Filtro apagado, o sin regiones configuradas todavía: se ofrecen todas las
        // comunas oficiales. Los sectores personalizados se conservan igualmente —
        // son lugares donde el comerciante despacha y no debe perderlos sin aviso.
        if ( ! hupkec_module_active( 'comunas' ) || empty( $enabled_regions ) ) {
            return self::with_sectors( $all, $custom );
        }

        $filtered = [];
        foreach ( $enabled_regions as $reg ) {
            if ( isset( $enabled_comunas[ $reg ] ) ) {
                // Array vacío = región activa sin comunas marcadas (intencional)
                $filtered[ $reg ] = empty( $enabled_comunas[ $reg ] )
                    ? []
                    : array_intersect( $all[ $reg ] ?? [], $enabled_comunas[ $reg ] );
            } else {
                // Sin configuración para esta región, mostrar todas por defecto
                $filtered[ $reg ] = $all[ $reg ] ?? [];
            }
        }

        return self::with_sectors( $filtered, $custom );
    }

    /**
     * Añade los sectores personalizados a las listas por región.
     *
     * array_intersect conserva las claves originales, por eso se reindexa antes.
     * Un sector puede llamarse igual que una comuna oficial: de ahí el array_unique.
     */
    private static function with_sectors( $lists, $custom_sectors ) {
        foreach ( $lists as $reg => $comunas ) {
            $comunas = array_values( (array) $comunas );

            foreach ( (array) ( $custom_sectors[ $reg ] ?? [] ) as $sector ) {
                if ( ! empty( $sector['name'] ) ) {
                    $comunas[] = $sector['name'];
                }
            }

            $lists[ $reg ] = array_values( array_unique( $comunas ) );
        }
        return $lists;
    }

    public static function get_custom_sectors( $region_code ) {
        $settings = get_option( 'hupkec_settings', [] );
        $sectors  = $settings['custom_sectors'][ $region_code ] ?? [];
        return is_array( $sectors ) ? $sectors : [];
    }

    public static function get_settings() {
        return get_option( 'hupkec_settings', [] );
    }
}
