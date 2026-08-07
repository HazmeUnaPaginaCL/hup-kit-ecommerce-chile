<?php
/**
 * Plugin Name: HUPKEC — Prueba de humo de la API de extensión
 * Description: Ejercita los hooks de HUP - Kit Ecommerce Chile SIN tocar el plugin base: registra un módulo, una pestaña, un tipo de campo y un tipo de costo. Si todo esto aparece y funciona, la API de extensión (Fase B) está lista para el addon Pro.
 * Version: 1.0.0
 * Author: HazmeUnaPagina.cl
 *
 * Cómo usar:
 *   Copiar este archivo a wp-content/mu-plugins/ (crear la carpeta si no existe).
 *   Los mu-plugins se activan solos. Para desactivarlo, borrar el archivo.
 *
 * Qué verificar en el navegador, en el panel del Kit:
 *   1. Pestaña Inicio → aparece el interruptor "Demo API (prueba de humo)".
 *   2. Menú lateral    → aparece la pestaña "Demo API" con un resumen.
 *   3. Cabecera        → aparece un aviso azul confirmando que el addon cargó.
 *   4. Campos → Agregar campo → el desplegable de tipo incluye "Fecha (demo)".
 *      Al agregar uno, aparece como selector de fecha nativo en el checkout.
 *   5. Zonas → Nueva zona → el desplegable de costo incluye "Recargo demo (+$990)".
 *      Al elegirlo, la tarjeta de la zona lo rotula y el checkout cobra $990.
 *   6. Al final de la pestaña Zonas → aparece una tarjeta inyectada por el addon.
 *
 * Este archivo es documentación viva: es el esqueleto mínimo del que partirá el
 * addon Pro. No forma parte del plugin distribuido.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ─────────────────────────────────────────────────────────────
   1. MÓDULO — hupkec_modules_registry
   Añade un interruptor propio en la pestaña de Inicio.
───────────────────────────────────────────────────────────── */
add_filter( 'hupkec_modules_registry', function ( array $modules ) : array {
    $modules['demo_api'] = [
        'label'       => 'Demo API (prueba de humo)',
        'desc'        => 'Módulo de ejemplo registrado por un addon externo. No hace nada; solo demuestra que hupkec_modules_registry funciona.',
        'default'     => 0,
        'toggle'      => true,
        'tab'         => 'demo_api',
        // Sin file/class: este módulo no carga ninguna clase, solo aporta el
        // interruptor. Un módulo real declararía 'file' y 'class'.
    ];
    return $modules;
} );

/* ─────────────────────────────────────────────────────────────
   2. PESTAÑA — hupkec_admin_tabs
   Añade una pestaña propia al panel.
───────────────────────────────────────────────────────────── */
add_filter( 'hupkec_admin_tabs', function ( array $tabs ) : array {
    $tabs['demo_api'] = [
        'label'    => 'Demo API',
        'icon'     => '🧪',
        'module'   => 'demo_api', // se muestra solo si el módulo está activo
        'order'    => 90,
        'callback' => 'hupkec_smoke_render_tab',
    ];
    return $tabs;
} );

function hupkec_smoke_render_tab() {
    echo '<div class="hupkec-card">';
    echo '<h3>Prueba de humo de la API</h3>';
    echo '<p>Si estás viendo esta pestaña, estos hooks funcionan:</p>';
    echo '<ul style="line-height:1.8">';
    echo '<li><code>hupkec_modules_registry</code> — el interruptor de este módulo en Inicio</li>';
    echo '<li><code>hupkec_admin_tabs</code> — esta pestaña</li>';
    echo '<li><code>hupkec_field_types</code> — el tipo «Fecha (demo)» en el editor de campos</li>';
    echo '<li><code>hupkec_zone_cost_types</code> — el tipo «Recargo demo» en el editor de zonas</li>';
    echo '<li><code>hupkec_after_tab_zonas</code> — la tarjeta al final de la pestaña de Zonas</li>';
    echo '<li><code>hupkec_admin_header_after</code> — el aviso azul de la cabecera</li>';
    echo '</ul>';
    echo '</div>';
}

/* ─────────────────────────────────────────────────────────────
   3. AVISO EN CABECERA — hupkec_admin_header_after
───────────────────────────────────────────────────────────── */
add_action( 'hupkec_admin_header_after', function () {
    echo '<div class="hupkec-notice" style="background:var(--hupkec-primary-light);border-left:4px solid var(--hupkec-primary);padding:12px 16px;margin:16px 0;border-radius:var(--hupkec-radius)">';
    echo '🧪 <strong>Addon de prueba de humo activo.</strong> La API de extensión responde.';
    echo '</div>';
} );

/* ─────────────────────────────────────────────────────────────
   4. SECCIÓN DENTRO DE UNA PESTAÑA EXISTENTE — hupkec_after_tab_zonas
───────────────────────────────────────────────────────────── */
add_action( 'hupkec_after_tab_zonas', function () {
    echo '<div class="hupkec-card" style="border:1px dashed var(--hupkec-accent)">';
    echo '<h3>🧪 Sección inyectada por el addon</h3>';
    echo '<p>Este bloque lo añadió un addon externo <em>dentro</em> de la pestaña de Zonas, sin reemplazar su contenido. Es el mecanismo con el que el Pro sumará controles a las pestañas que ya existen.</p>';
    echo '</div>';
} );

/* ─────────────────────────────────────────────────────────────
   5. TIPO DE CAMPO — hupkec_field_types + render + sanitize + display
   Añade un tipo «fecha» de ejemplo al editor del checkout.

   Se elige 'date' a propósito: es uno de los tipos que woocommerce_form_field()
   pinta de forma nativa (<input type="date">). Un tipo que WooCommerce NO conoce
   —como 'color'— no aparecería en el checkout aunque el filtro cambie sus args,
   porque el render final lo hace WooCommerce. Para esos casos hay que engancharse
   además a 'woocommerce_form_field_{tipo}'. Ver docs/hooks.md.
───────────────────────────────────────────────────────────── */
add_filter( 'hupkec_field_types', function ( array $types ) : array {
    $types['date_demo'] = 'Fecha (demo)';
    return $types;
} );

// Pintar como <input type="date"> en el checkout (WooCommerce lo soporta).
add_filter( 'hupkec_field_render_args', function ( array $args, array $field ) : array {
    if ( ( $field['type'] ?? '' ) === 'date_demo' ) {
        $args['type'] = 'date';
    }
    return $args;
}, 10, 2 );

// Sanear: solo aceptar una fecha AAAA-MM-DD válida.
add_filter( 'hupkec_field_sanitize_value', function ( $value, string $type, $raw ) {
    if ( $type === 'date_demo' ) {
        $d = DateTime::createFromFormat( 'Y-m-d', (string) $raw );
        return ( $d && $d->format( 'Y-m-d' ) === (string) $raw ) ? (string) $raw : '';
    }
    return $value;
}, 10, 3 );

// Mostrar en el pedido con formato chileno.
add_filter( 'hupkec_field_display_value', function ( string $display, array $field, $value ) : string {
    if ( ( $field['type'] ?? '' ) === 'date_demo' && $value ) {
        return date_i18n( 'd/m/Y', strtotime( (string) $value ) );
    }
    return $display;
}, 10, 3 );

/* ─────────────────────────────────────────────────────────────
   6. TIPO DE COSTO DE ZONA — hupkec_zone_cost_types + hupkec_zone_cost
   Añade un tipo de costo que suma un recargo fijo de $990.
───────────────────────────────────────────────────────────── */
add_filter( 'hupkec_zone_cost_types', function ( array $types ) : array {
    $types['demo_recargo'] = 'Recargo demo (+$990)';
    return $types;
} );

add_filter( 'hupkec_zone_cost', function ( $cost, array $zone, array $package, string $type ) {
    if ( $type === 'demo_recargo' ) {
        // Costo real que devuelve el addon: un fijo de 990.
        return 990;
    }
    return $cost;
}, 10, 4 );
