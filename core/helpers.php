<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Funciones helper para HUP - Kit Ecommerce Chile
 */

/**
 * Estado de los interruptores guardados en hupkec_modules.
 *
 * Fachada de HUPKEC_Modules, que desde la 1.1.0 es la única fuente de verdad.
 * Se mantiene la función porque es la que usa el resto del plugin.
 *
 * @return array<string,int>
 */
function hupkec_get_modules() {
    return HUPKEC_Modules::states();
}

/**
 * ¿Está activo este módulo?
 *
 * @param string $module Clave del módulo.
 * @return bool
 */
function hupkec_module_active( $module ) {
    return HUPKEC_Modules::is_active( $module );
}

function hupkec_default_fields() {
    return [
        'billing' => [
            [ 'key' => 'billing_country',    'type' => 'country', 'label' => 'País / Región',                   'placeholder' => '',                                         'required' => true,  'enabled' => true,  'width' => 'wide',  'custom' => false, 'locked' => true  ],
            [ 'key' => 'billing_first_name', 'type' => 'text',    'label' => 'Nombre',                          'placeholder' => '',                                         'required' => true,  'enabled' => true,  'width' => 'first', 'custom' => false, 'locked' => false ],
            [ 'key' => 'billing_last_name',  'type' => 'text',    'label' => 'Apellidos',                       'placeholder' => '',                                         'required' => true,  'enabled' => true,  'width' => 'last',  'custom' => false, 'locked' => false ],
            [ 'key' => 'billing_rut',        'type' => 'text',    'label' => 'RUT / DNI',                       'placeholder' => 'Ej: 12.345.678-9',                         'required' => true,  'enabled' => true,  'width' => 'wide',  'custom' => true,  'locked' => false ],
            [ 'key' => 'billing_address_1',  'type' => 'text',    'label' => 'Dirección',                       'placeholder' => 'Nombre de la calle y número',              'required' => true,  'enabled' => true,  'width' => 'wide',  'custom' => false, 'locked' => false ],
            [ 'key' => 'billing_address_2',  'type' => 'text',    'label' => 'Apartamento, habitación, etc.',   'placeholder' => 'Apartamento, habitación, etc. (opcional)', 'required' => false, 'enabled' => true,  'width' => 'wide',  'custom' => false, 'locked' => false ],
            [ 'key' => 'billing_state',      'type' => 'state',   'label' => 'Región',                          'placeholder' => '',                                         'required' => true,  'enabled' => true,  'width' => 'wide',  'custom' => false, 'locked' => false ],
            [ 'key' => 'billing_city',       'type' => 'city',    'label' => 'Comuna',                          'placeholder' => '',                                         'required' => true,  'enabled' => true,  'width' => 'wide',  'custom' => false, 'locked' => false ],
            [ 'key' => 'billing_phone',      'type' => 'tel',     'label' => 'Teléfono',                        'placeholder' => '',                                         'required' => true,  'enabled' => true,  'width' => 'wide',  'custom' => false, 'locked' => false ],
            [ 'key' => 'billing_email',      'type' => 'email',   'label' => 'Correo electrónico',              'placeholder' => '',                                         'required' => true,  'enabled' => true,  'width' => 'wide',  'custom' => false, 'locked' => false ],
        ],
        'shipping' => [
            [ 'key' => 'shipping_country',    'type' => 'country', 'label' => 'País / Región',                  'placeholder' => '',                                         'required' => true,  'enabled' => true,  'width' => 'wide',  'custom' => false, 'locked' => true  ],
            [ 'key' => 'shipping_first_name', 'type' => 'text',    'label' => 'Nombre',                         'placeholder' => '',                                         'required' => true,  'enabled' => true,  'width' => 'first', 'custom' => false, 'locked' => false ],
            [ 'key' => 'shipping_last_name',  'type' => 'text',    'label' => 'Apellidos',                      'placeholder' => '',                                         'required' => true,  'enabled' => true,  'width' => 'last',  'custom' => false, 'locked' => false ],
            [ 'key' => 'shipping_rut',        'type' => 'text',    'label' => 'RUT / DNI',                      'placeholder' => 'Ej: 12.345.678-9',                         'required' => true,  'enabled' => true,  'width' => 'wide',  'custom' => true,  'locked' => false ],
            [ 'key' => 'shipping_address_1',  'type' => 'text',    'label' => 'Dirección',                      'placeholder' => 'Nombre de la calle y número',              'required' => true,  'enabled' => true,  'width' => 'wide',  'custom' => false, 'locked' => false ],
            [ 'key' => 'shipping_address_2',  'type' => 'text',    'label' => 'Apartamento, habitación, etc.',  'placeholder' => 'Apartamento, habitación, etc. (opcional)', 'required' => false, 'enabled' => true,  'width' => 'wide',  'custom' => false, 'locked' => false ],
            [ 'key' => 'shipping_state',      'type' => 'state',   'label' => 'Región',                         'placeholder' => '',                                         'required' => true,  'enabled' => true,  'width' => 'wide',  'custom' => false, 'locked' => false ],
            [ 'key' => 'shipping_city',       'type' => 'city',    'label' => 'Comuna',                         'placeholder' => '',                                         'required' => true,  'enabled' => true,  'width' => 'wide',  'custom' => false, 'locked' => false ],
            [ 'key' => 'shipping_phone',      'type' => 'tel',     'label' => 'Teléfono',                       'placeholder' => '',                                         'required' => true,  'enabled' => true,  'width' => 'wide',  'custom' => false, 'locked' => false ],
        ],
        'additional' => [
            [ 'key' => 'order_comments', 'type' => 'textarea', 'label' => 'Notas del pedido', 'placeholder' => 'Notas sobre tu pedido, por ejemplo, notas especiales para la entrega.', 'required' => false, 'enabled' => true, 'width' => 'wide', 'custom' => false, 'locked' => false ],
        ],
    ];
}

function hupkec_get_fields( $section = null ) {
    // Memoizado por request: se invoca en bucles (validación, guardado, email,
    // render admin/thankyou). Las escrituras (ajax_save_fields/reset) terminan
    // con wp_send_json y mueren, por lo que el caché nunca queda obsoleto.
    static $cache = null;
    if ( $cache === null ) {
        $saved    = get_option( 'hupkec_fields', [] );
        $defaults = hupkec_default_fields();
        if ( empty( $saved ) || ! is_array( $saved ) ) $saved = $defaults;
        foreach ( $defaults as $sec => $flds ) {
            if ( ! isset( $saved[$sec] ) ) $saved[$sec] = $flds;
        }
        $cache = $saved;
    }
    return $section ? ( $cache[$section] ?? [] ) : $cache;
}

function hupkec_width_class( $width ) {
    if ( $width === 'first' ) return 'form-row-first';
    if ( $width === 'last' )  return 'form-row-last';
    return 'form-row-wide';
}

/**
 * Tipos de campo personalizados disponibles en el editor del checkout.
 *
 * @return array<string,string> Clave del tipo => etiqueta visible.
 */
function hupkec_custom_field_types() {
    $types = [
        'text'     => 'Texto libre',
        'number'   => 'Número',
        'textarea' => 'Texto largo',
        'select'   => 'Lista desplegable',
        'radio'    => 'Opción única (radio)',
        'checkbox' => 'Casilla (sí/no)',
    ];

    /**
     * Tipos de campo disponibles.
     *
     * Registrar un tipo aquí lo hace aparecer en el desplegable del modal y lo
     * acepta al guardar. Para que además se renderice y se sanitice bien hacen
     * falta 'hupkec_field_render_args' y 'hupkec_field_sanitize_value'.
     *
     * @since 1.1.0
     * @param array<string,string> $types
     */
    return hupkec_sanitize_type_map( apply_filters( 'hupkec_field_types', $types ), $types );
}

/**
 * Tipos que requieren un set de opciones (valor/etiqueta).
 *
 * @return string[]
 */
function hupkec_field_types_with_options() {
    $types = [ 'select', 'radio' ];

    /**
     * Tipos que se guardan con una lista de opciones.
     *
     * @since 1.1.0
     * @param string[] $types
     */
    $filtered = apply_filters( 'hupkec_field_types_with_options', $types );
    if ( ! is_array( $filtered ) ) return $types;

    $clean = array_filter( array_map( 'sanitize_key', $filtered ) );
    // 'select' y 'radio' no se pueden quitar: el editor los ofrece siempre y sin
    // sus opciones quedarían como listas vacías.
    return array_values( array_unique( array_merge( $types, $clean ) ) );
}

/**
 * Sanea un mapa de tipo => etiqueta devuelto por un filtro.
 *
 * Los tipos propios del plugin se reponen siempre: si un addon los elimina,
 * el editor se quedaría sin los tipos básicos y los campos ya guardados con
 * ellos se degradarían a texto al volver a guardar.
 *
 * @param mixed $types Lo devuelto por el filtro.
 * @param array $base  Tipos originales.
 * @return array<string,string>
 */
function hupkec_sanitize_type_map( $types, $base ) {
    if ( ! is_array( $types ) ) return $base;

    $clean = [];
    foreach ( $types as $key => $label ) {
        $key = sanitize_key( $key );
        if ( $key === '' || ! is_scalar( $label ) ) continue;
        $clean[ $key ] = sanitize_text_field( (string) $label );
    }

    return array_merge( $clean, $base );
}

/**
 * ¿Debe mostrarse este campo en el email o en el pedido?
 *
 * La clave ausente equivale a "sí": así un campo guardado antes de que existieran
 * estos interruptores sigue comportándose igual y no desaparece sin aviso.
 *
 * @param array  $field Configuración del campo.
 * @param string $where 'email' (correos del pedido) u 'order' (ficha de pedido y página de gracias).
 * @return bool
 */
function hupkec_field_shows_in( $field, $where ) {
    $key = ( $where === 'email' ) ? 'show_in_email' : 'show_in_order';
    return ! array_key_exists( $key, (array) $field ) || ! empty( $field[ $key ] );
}

/**
 * Devuelve el valor legible de un campo para mostrar en pedidos/emails.
 * - select/radio: traduce el valor guardado a su etiqueta.
 * - checkbox: 'Sí' / 'No'.
 * - resto: el valor tal cual.
 *
 * @param array  $field Configuración del campo.
 * @param string $value Valor guardado en el pedido.
 * @return string
 */
function hupkec_field_display_value( $field, $value ) {
    $type = $field['type'] ?? 'text';

    if ( in_array( $type, hupkec_field_types_with_options(), true ) && ! empty( $field['options'] ) ) {
        foreach ( (array) $field['options'] as $opt ) {
            if ( (string) ( $opt['value'] ?? '' ) === (string) $value ) {
                return (string) ( $opt['label'] ?? $value );
            }
        }
    }

    if ( $type === 'checkbox' ) {
        return ( (string) $value === '1' ) ? 'Sí' : 'No';
    }

    /**
     * Valor legible de un campo en pedidos y correos.
     *
     * Es donde un tipo nuevo —fecha, hora, archivo— da formato a lo que guardó.
     * Debe devolver un escalar; cualquier otra cosa se descarta.
     *
     * @since 1.1.0
     * @param string $display Valor a mostrar.
     * @param array  $field   Configuración del campo.
     * @param string $value   Valor crudo guardado en el pedido.
     */
    $display = apply_filters( 'hupkec_field_display_value', (string) $value, $field, $value );

    return is_scalar( $display ) ? (string) $display : (string) $value;
}

/**
 * ¿La página actual muestra un formulario de dirección de WooCommerce?
 *
 * Además del checkout, «Mi cuenta → Direcciones» pinta la comuna como lista, así
 * que necesita el mismo script. is_checkout() puede devolver false en algunos
 * constructores: por eso se compara también el ID de la página.
 *
 * @since 1.3.1
 * @return bool
 */
function hupkec_is_address_page() {
    if ( is_checkout() || is_account_page() ) return true;
    $checkout_page_id = wc_get_page_id( 'checkout' );
    return $checkout_page_id > 0 && is_page( $checkout_page_id );
}

/**
 * Límite de consultas por IP y minuto.
 *
 * La ventana es el minuto en curso: el contador empieza de cero en cada minuto
 * aunque el tráfico sea constante. Detrás de un proxy muchos clientes comparten
 * IP, por eso los topes son amplios.
 *
 * @since 1.3.1
 * @param string $bucket Nombre de la consulta.
 * @param int    $max    Máximo por minuto.
 * @return bool true si ya se alcanzó el máximo.
 */
function hupkec_rate_limited( $bucket, $max ) {
    $ip   = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
    $key  = 'hupkec_rl_' . $bucket . '_' . md5( $ip . '|' . floor( time() / MINUTE_IN_SECONDS ) );
    $hits = (int) get_transient( $key );

    if ( $hits >= $max ) return true;

    set_transient( $key, $hits + 1, 2 * MINUTE_IN_SECONDS );
    return false;
}

/**
 * ¿Está activo el módulo de facturación?
 *
 * Tiene opción propia (hupkec_billing_module) en vez de vivir en hupkec_modules.
 * El registro ya sabe resolverlo, así que esto es un atajo por legibilidad.
 *
 * @return bool
 */
function hupkec_billing_module_active() {
    return HUPKEC_Modules::is_active( 'billing' );
}

/**
 * Valida RUT chileno con algoritmo Módulo 11.
 * Acepta formatos: 12345678-9 · 12.345.678-9 · 123456789
 * Función global para evitar duplicar la lógica en cada clase.
 *
 * @param  string $rut  RUT en cualquier formato.
 * @return bool         true si es válido.
 */
function hupkec_is_valid_rut( $rut ) {
    // PHP 8.1+: pasar null a trim()/strtolower() emite deprecation notice.
    // Forzamos string para que la función sea null-safe ante cualquier llamador.
    $rut = preg_replace( '/[\.\-\s]/', '', strtolower( trim( (string) $rut ) ) );
    if ( strlen( $rut ) < 2 ) return false;

    $dv   = substr( $rut, -1 );
    $body = substr( $rut, 0, -1 );

    if ( ! ctype_digit( $body ) )              return false;
    if ( ! ctype_digit( $dv ) && $dv !== 'k' ) return false;

    $sum = 0; $factor = 2;
    for ( $i = strlen( $body ) - 1; $i >= 0; $i-- ) {
        $sum   += (int) $body[ $i ] * $factor;
        $factor = $factor === 7 ? 2 : $factor + 1;
    }
    $rem   = $sum % 11;
    if ( $rem === 1 ) {
        $check = 'k';
    } elseif ( $rem === 0 ) {
        $check = '0';
    } else {
        $check = (string) ( 11 - $rem );
    }
    return $dv === $check;
}

/**
 * Convierte un código de región antiguo al formato de WooCommerce.
 *
 * Hasta la 1.1.1 el plugin usaba códigos propios de dos letras ('AT', 'RM');
 * WooCommerce usa 'CL-AT', 'CL-RM'. Esa diferencia rompía las zonas nativas de
 * WooCommerce por región y la compatibilidad con plugins de courier. Los
 * pedidos y clientes antiguos conservan el código corto en la base de datos:
 * esta función los traduce al leerlos, sin reescribir nada.
 *
 * @since 1.1.2
 * @param string $code Código de región, antiguo o nuevo.
 * @return string Código en formato 'CL-XX', o el valor original si no es una región chilena conocida.
 */
function hupkec_normalize_region( $code ) {
    $code = is_string( $code ) ? strtoupper( trim( $code ) ) : '';
    if ( preg_match( '/^[A-Z]{2}$/', $code ) ) {
        $regions = hupkec_get_regions_base();
        if ( isset( $regions[ 'CL-' . $code ] ) ) {
            return 'CL-' . $code;
        }
    }
    return $code;
}

/**
 * Nombre legible de una región, aceptando códigos antiguos y nuevos.
 *
 * @since 1.1.2
 * @param string $code Código de región.
 * @return string Nombre de la región, o el código tal cual si no se reconoce.
 */
function hupkec_region_name( $code ) {
    $regions = hupkec_get_regions_base();
    $norm    = hupkec_normalize_region( (string) $code );
    return $regions[ $norm ] ?? (string) $code;
}

/**
 * ¿El plugin fija el país de esta sección del checkout en Chile?
 *
 * Ocurre cuando el editor de campos está activo y el campo país está
 * habilitado: el cliente no elige país, la tienda despacha solo en Chile.
 *
 * @since 1.1.2
 * @param string $section 'billing' o 'shipping'. Vacío = cualquiera de las dos.
 * @return bool
 */
function hupkec_country_locked( $section = '' ) {
    if ( ! hupkec_module_active( 'checkout' ) ) return false;

    $sections = $section ? [ $section ] : [ 'billing', 'shipping' ];
    foreach ( $sections as $sec ) {
        foreach ( hupkec_get_fields( $sec ) as $f ) {
            if ( ( $f['type'] ?? '' ) === 'country' && ! empty( $f['enabled'] ) ) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Migraciones de datos guardados entre versiones.
 *
 * Se ejecuta en cada carga, pero solo hace trabajo cuando la versión de datos
 * guardada es menor que la actual: una lectura de opción autocargada y nada más.
 *
 * Versión 2 (1.1.2): códigos de región 'AT' → 'CL-AT' en hupkec_settings.
 *
 * @since 1.1.2
 * @return void
 */
function hupkec_maybe_upgrade_data() {
    // Cada actualización del plugin invalida las tarifas de envío guardadas en
    // las sesiones: el cálculo puede haber cambiado y un cliente con el carrito
    // abierto no debe quedarse con el resultado de la versión anterior.
    if ( get_option( 'hupkec_version' ) !== HUPKEC_VERSION ) {
        if ( class_exists( 'WC_Cache_Helper' ) ) {
            WC_Cache_Helper::get_transient_version( 'shipping', true );
        }
        update_option( 'hupkec_version', HUPKEC_VERSION );
    }

    $current = 2;
    $saved   = (int) get_option( 'hupkec_data_version', 1 );
    if ( $saved >= $current ) return;

    if ( $saved < 2 ) {
        $settings = get_option( 'hupkec_settings', [] );
        if ( is_array( $settings ) && $settings ) {
            if ( ! empty( $settings['enabled_regions'] ) && is_array( $settings['enabled_regions'] ) ) {
                $settings['enabled_regions'] = array_values( array_unique( array_map( 'hupkec_normalize_region', $settings['enabled_regions'] ) ) );
            }
            foreach ( [ 'enabled_comunas', 'custom_sectors' ] as $map ) {
                if ( empty( $settings[ $map ] ) || ! is_array( $settings[ $map ] ) ) continue;
                $migrated = [];
                foreach ( $settings[ $map ] as $code => $value ) {
                    $migrated[ hupkec_normalize_region( (string) $code ) ] = $value;
                }
                $settings[ $map ] = $migrated;
            }
            update_option( 'hupkec_settings', $settings );
        }
    }

    update_option( 'hupkec_data_version', $current );

    if ( function_exists( 'wc_get_logger' ) ) {
        wc_get_logger()->info(
            sprintf( 'HUPKEC: datos migrados de la versión %d a la %d (códigos de región CL-XX).', $saved, $current ),
            [ 'source' => 'hup-kit-ecommerce-chile' ]
        );
    }
}
