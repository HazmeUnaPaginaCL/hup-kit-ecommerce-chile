<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class HUPKEC_Settings {

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
