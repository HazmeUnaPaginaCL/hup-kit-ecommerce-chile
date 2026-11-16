# API de extensión — HUP - Kit Ecommerce Chile

Referencia de los hooks que expone el plugin para que un addon amplíe su
funcionalidad sin modificar el código base. Disponibles desde la **1.1.0**.

El plugin base funciona por sí solo: todos estos hooks tienen un comportamiento
por defecto idéntico al que tenían antes de existir. Un addon **suma**; nunca
necesita —ni puede— reemplazar la lógica del plugin.

## Índice

- [Cómo se engancha un addon](#cómo-se-engancha-un-addon)
- [Ciclo de vida](#ciclo-de-vida)
- [Módulos](#módulos)
- [Panel de administración (PHP)](#panel-de-administración-php)
- [Campos del checkout](#campos-del-checkout)
- [Zonas y envío](#zonas-y-envío)
- [Capa JavaScript del panel](#capa-javascript-del-panel)
- [Principio de blindaje](#principio-de-blindaje)

---

## Cómo se engancha un addon

El cargador del plugin se instancia en `plugins_loaded` con prioridad 10. Los
filtros que definen módulos, pestañas y tipos deben registrarse **antes** de ese
momento, es decir, en el cuerpo del archivo principal del addon (que se ejecuta
al incluirse el plugin, antes de `plugins_loaded`), o en `plugins_loaded` con
prioridad menor que 10.

```php
// hup-kit-ecommerce-chile-pro.php
add_filter( 'hupkec_modules_registry', 'miaddon_registrar_modulo' );
add_filter( 'hupkec_admin_tabs',       'miaddon_registrar_tab' );
```

Los filtros que se aplican durante una petición concreta —guardar un campo,
calcular un envío, renderizar el checkout— pueden registrarse en cualquier
momento previo a esa petición.

---

## Ciclo de vida

### `hupkec_loaded` — acción

Se dispara cuando el plugin terminó de cargarse y registrar sus hooks.

```php
do_action( 'hupkec_loaded' );
```

### `hupkec_modules_loaded` — acción

Se dispara cuando todos los módulos activos ya se instanciaron. Útil para un
addon que necesita que las clases de los módulos existan antes de operar.

```php
do_action( 'hupkec_modules_loaded' );
```

---

## Módulos

### `hupkec_modules_registry` — filtro

Registra un módulo nuevo, con su interruptor en la pestaña de Inicio y su carga
condicionada a ese interruptor.

```php
add_filter( 'hupkec_modules_registry', function ( array $modules ) : array {
    $modules['etiquetas'] = [
        'label'       => 'Etiquetas PDF',
        'desc'        => 'Genera etiquetas de envío en PDF.',
        'default'     => 0,          // 0 = apagado al instalar
        'option'      => '',          // '' = vive en hupkec_modules; o una opción propia
        'always_load' => false,       // true = cargar aunque el interruptor esté apagado
        'toggle'      => true,        // true = mostrar interruptor en Inicio
        'tab'         => 'etiquetas', // pestaña que abre al encenderse
        'file'        => __DIR__ . '/modules/labels/class-labels.php',
        'class'       => 'MIADDON_Labels', // se instancia si el módulo carga
        // Alternativa a file + class:
        // 'loader'   => function ( $key, $mod ) { /* carga a medida */ },
    ];
    return $modules;
} );
```

Comprobar si un módulo está activo, desde cualquier punto:

```php
if ( hupkec_module_active( 'etiquetas' ) ) { /* ... */ }
```

---

## Panel de administración (PHP)

### `hupkec_admin_tabs` — filtro

Registra una pestaña en el panel. La `callback` imprime el contenido del panel.

```php
add_filter( 'hupkec_admin_tabs', function ( array $tabs ) : array {
    $tabs['licencia'] = [
        'label'    => 'Licencia',
        'icon'     => 'dashicons-lock',   // clase de Dashicons (desde 1.2.0)
        'desc'     => 'Activa tu licencia para recibir actualizaciones.', // opcional: subtítulo
        'module'   => '',                 // módulo que la habilita; '' = siempre visible
        'callback' => 'miaddon_tab_licencia', // imprime el HTML del panel
        'order'    => 60,                 // menor = más arriba; propias 10-40 y Guía 90
    ];
    return $tabs;
} );
```

Desde la 1.2.0 el panel usa el sistema de diseño HUP (`hup-ui/`): el contenido de
la pestaña debe usar sus clases `.hup-*` (`.hup-card`, `.hup-field`, `.hup-btn`…).
El catálogo con el HTML de cada componente está en `hup-ui/componentes.html`.

- **Ícono:** solo Dashicons. Los emojis que registraban las pestañas antes de la
  1.2.0 se traducen a un Dashicon equivalente para no romper addons existentes.
- **`desc`:** si la pestaña lo trae, el panel imprime la cabecera de sección
  (título + frase). Sin `desc`, la pestaña imprime su propia cabecera.
- **URL:** cada pestaña se abre con `admin.php?page=hupkec-settings&tab={clave}`.

Ganchos de compatibilidad que se conservan en el marcado (no dan estilo):
`.hupkec-tab-btn[data-tab]` en el menú, `.hupkec-panel#tab-{clave}` (`.is-active`
el visible), `.hupkec-hidden` para ocultar un botón, y en Inicio
`.hupkec-module-row` › `.hupkec-module-label` + `.hupkec-module-checkbox[data-module]`.
Los avisos se muestran con `HUP.toast( mensaje )` o `HUP.toast( mensaje, 'error' )`.

### `hupkec_after_tab_{clave}` — acción

Añade secciones **dentro** de una pestaña existente, sin reemplazar su contenido.
`{clave}` es la clave de la pestaña: `inicio`, `comunas`, `zonas`, `campos`, `guia`.

```php
// Añade un bloque al final de la pestaña Despacho.
add_action( 'hupkec_after_tab_zonas', function () {
    echo '<div class="hup-card"><div class="hup-card__body">…</div></div>';
} );
```

### `hupkec_admin_header_after` — acción

Justo debajo de la cabecera del panel, antes del layout. Pensado para avisos
del addon (por ejemplo, estado de licencia).

```php
add_action( 'hupkec_admin_header_after', function () {
    echo '<div class="hupkec-notice">…</div>';
} );
```

### `hupkec_admin_localized_data` — filtro

Añade datos al objeto JavaScript `hupkecAdmin`, disponible en el panel. Útil para
pasar al JS del addon sus propias opciones o etiquetas.

```php
add_filter( 'hupkec_admin_localized_data', function ( array $data ) : array {
    $data['miAddon'] = [ 'opciones' => get_option( 'miaddon_opts', [] ) ];
    return $data;
} );
```

Las claves del plugin (`nonce`, `ajaxUrl`, `fields`, `zones`…) no se pueden
sobrescribir: se reponen después del filtro.

---

## Campos del checkout

### `hupkec_field_types` — filtro

Registra un tipo de campo nuevo en el desplegable del editor.

```php
add_filter( 'hupkec_field_types', function ( array $types ) : array {
    $types['date'] = 'Fecha';
    return $types;
} );
```

Registrar el tipo lo hace aparecer y lo acepta al guardar. Para que se **pinte**
y se **sanitice** bien hacen falta además `hupkec_field_render_args` y
`hupkec_field_sanitize_value`.

### `hupkec_field_types_with_options` — filtro

Declara que un tipo se guarda con una lista de opciones (como `select` y `radio`).

```php
add_filter( 'hupkec_field_types_with_options', function ( array $types ) : array {
    $types[] = 'multiselect';
    return $types;
} );
```

### `hupkec_field_allowed_types` — filtro

Lista blanca de tipos aceptados al guardar. Si un tipo no está aquí (ni entre los
nativos), al guardar se degrada a `text`. Normalmente basta con `hupkec_field_types`;
este filtro es para tipos que no deben aparecer en el desplegable pero sí guardarse.

```php
add_filter( 'hupkec_field_allowed_types', function ( array $types ) : array {
    $types[] = 'date';
    return $types;
} );
```

### `hupkec_field_schema` — filtro

**El más importante para persistir datos propios.** El esquema del campo se
guarda como un array; cualquier clave que no esté en él se descarta. Este filtro
permite añadir claves propias (reglas condicionales, validaciones) que sobreviven
al guardado.

```php
add_filter( 'hupkec_field_schema', function ( array $field, array $raw, string $section ) : array {
    // $raw viene del navegador SIN sanear: es tu responsabilidad tratarlo.
    if ( isset( $raw['conditional_rules'] ) ) {
        $field['conditional_rules'] = miaddon_sanear_reglas( $raw['conditional_rules'] );
    }
    return $field;
}, 10, 3 );
```

### `hupkec_field_render_args` — filtro

Argumentos que recibe `woocommerce_form_field()` al pintar un campo personalizado.
Es lo que permite que un tipo nuevo se renderice de verdad en el checkout.

```php
add_filter( 'hupkec_field_render_args', function ( array $args, array $field, string $section ) : array {
    if ( ( $field['type'] ?? '' ) === 'date' ) {
        $args['type'] = 'date'; // input HTML5 de fecha
    }
    return $args;
}, 10, 3 );
```

**Importante:** el render final lo hace WooCommerce, no el plugin. `woocommerce_form_field()`
solo pinta estos tipos: `text`, `password`, `date`, `datetime-local`, `month`, `week`,
`time`, `number`, `email`, `url`, `tel`, `hidden`, `textarea`, `checkbox`, `select`,
`radio`, `country`, `state`. Poner en `$args['type']` un valor fuera de esa lista
(por ejemplo `color` o `file`) hará que el campo **no aparezca**, aunque el filtro
se ejecute. Para un tipo que WooCommerce no conoce, hay que renderizarlo con el
hook nativo `woocommerce_form_field_{tipo}`:

```php
add_filter( 'woocommerce_form_field_color', function ( $field, $key, $args, $value ) {
    return '<input type="color" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
}, 10, 4 );
```

### `hupkec_field_sanitize_value` — filtro

Sanea el valor de un tipo nuevo antes de guardarlo en el pedido. El valor que
llega ya pasó por la sanitización del plugin (texto plano por defecto). Debe
devolver un escalar; cualquier otra cosa se descarta.

```php
add_filter( 'hupkec_field_sanitize_value', function ( $value, string $type, $raw, array $field ) {
    if ( $type === 'date' ) {
        $d = DateTime::createFromFormat( 'Y-m-d', (string) $raw );
        return $d ? $d->format( 'Y-m-d' ) : '';
    }
    return $value;
}, 10, 4 );
```

### `hupkec_field_display_value` — filtro

Da formato legible al valor de un campo en la ficha del pedido y en los correos.

```php
add_filter( 'hupkec_field_display_value', function ( string $display, array $field, $value ) : string {
    if ( ( $field['type'] ?? '' ) === 'date' && $value ) {
        return date_i18n( 'd/m/Y', strtotime( (string) $value ) );
    }
    return $display;
}, 10, 3 );
```

### `hupkec_field_is_visible` — filtro

Decide si un campo se muestra y se valida. Siempre `true` en el plugin base.
Es la contraparte en **servidor** de la lógica condicional del navegador: un
campo escondido solo con JavaScript sigue siendo obligatorio en PHP y bloquearía
el pedido de un cliente que nunca llegó a verlo.

`$context` vale `'render'` (al pintar el formulario) o `'validate'` (al procesar).

```php
add_filter( 'hupkec_field_is_visible', function ( bool $visible, array $field, string $section, string $context ) : bool {
    // Ejemplo: ocultar 'rut_empresa' salvo que el pedido sea con factura.
    if ( ( $field['key'] ?? '' ) === 'rut_empresa' ) {
        return miaddon_pide_factura();
    }
    return $visible;
}, 10, 4 );
```

### `hupkec_field_validation_error` — filtro

Validación en servidor de un campo. Devolver un mensaje no vacío lo muestra como
error y bloquea el pedido. Es la contraparte de
`hupkec.fieldModal.validationError`: sin ella, cualquier regla se salta desde las
herramientas del navegador.

```php
add_filter( 'hupkec_field_validation_error', function ( string $error, array $field, $value, string $section ) : string {
    if ( ( $field['type'] ?? '' ) === 'tel_cl' && ! preg_match( '/^\+?56\d{9}$/', (string) $value ) ) {
        return sprintf( 'El campo "%s" no es un teléfono chileno válido.', $field['label'] );
    }
    return $error;
}, 10, 4 );
```

---

## Zonas y envío

### `hupkec_zone_cost_types` — filtro

Registra un tipo de costo nuevo en el desplegable del editor de zonas.

```php
add_filter( 'hupkec_zone_cost_types', function ( array $types ) : array {
    $types['formula'] = 'Fórmula';
    return $types;
} );
```

### `hupkec_zone_save_data` — filtro

Persiste datos propios en la zona al guardarla. Mismo patrón que
`hupkec_field_schema`: el array de la zona era cerrado.

```php
add_filter( 'hupkec_zone_save_data', function ( array $zone, array $posted ) : array {
    // $posted viene del navegador SIN sanear.
    if ( isset( $posted['formula'] ) ) {
        $zone['formula'] = sanitize_text_field( $posted['formula'] );
    }
    return $zone;
}, 10, 2 );
```

### `hupkec_zone_cost` — filtro

Calcula el costo de una zona. Es donde un addon implementa sus tipos de costo:
fórmulas, recargos por clase de envío, tramos.

Desde la 1.3.0 una comuna puede estar en varias zonas (p. ej. una por courier) y
el checkout ofrece una tarifa por cada una: el filtro se aplica **una vez por
zona** que incluye la comuna del cliente.

Devolver `false` cancela la tarifa (la zona coincide pero no se ofrece envío).
Cualquier valor no numérico se ignora y se conserva el costo del plugin. Los
negativos se recortan a cero.

```php
add_filter( 'hupkec_zone_cost', function ( $cost, array $zone, array $package, string $type ) {
    if ( $type === 'formula' && ! empty( $zone['formula'] ) ) {
        return miaddon_evaluar_formula( $zone['formula'], $package );
    }
    return $cost;
}, 10, 4 );
```

### `hupkec_shipping_rate_args` — filtro

Ajusta la tarifa antes de registrarla. Permite cambiar la etiqueta o adjuntar
`meta_data` que el addon lea luego en el hook nativo
`woocommerce_after_shipping_rate` (para pintar tiempo de entrega, por ejemplo).

El `id` y el `cost` no se pueden alterar aquí: el `id` es la clave de la tarifa
elegida por el cliente, y el `cost` se ajusta antes, en `hupkec_zone_cost`.

Se aplica una vez por cada zona que incluye la comuna. Desde la 1.3.0 el `id`
tiene la forma `hupkec_shipping:{instancia}:{id de zona}` (antes
`hupkec_shipping:{instancia}`); para saber de qué zona viene una tarifa usa
`$zone['id']`, no el `id` de la tarifa. Las tarifas se registran de la más
barata a la más cara.

```php
add_filter( 'hupkec_shipping_rate_args', function ( array $args, array $zone, array $package ) : array {
    $args['meta_data'] = [ 'zona_hupkec' => $zone['id'] ];
    return $args;
}, 10, 3 );
```

### `hupkec_shipping_method_form_fields` — filtro

Ajustes del método de envío, en la pantalla de zonas de WooCommerce.

```php
add_filter( 'hupkec_shipping_method_form_fields', function ( array $fields ) : array {
    $fields['mi_ajuste'] = [ 'title' => '…', 'type' => 'text' ];
    return $fields;
} );
```

---

## Capa JavaScript del panel

El panel construye el modal de campos y el editor de zonas desde JavaScript, así
que los controles nuevos se inyectan por `wp.hooks`. El addon debe declarar
`wp-hooks` como dependencia de su script.

Hay dos anclas vacías en el HTML donde inyectar el marcado:
`#hupkec-field-modal-extra` (dentro del modal de campos) y
`#hupkec-zone-form-extra` (dentro del editor de zonas).

### `hupkec.admin.ready` — acción

El panel está montado. Momento de inyectar el HTML en las anclas.

```js
wp.hooks.addAction( 'hupkec.admin.ready', 'miaddon', function () {
    document.getElementById( 'hupkec-field-modal-extra' ).innerHTML = miHtmlDeControles;
} );
```

### `hupkec.fieldModal.opened` — acción

El modal de un campo se abrió y está poblado. Momento de rellenar los controles
propios con los datos del campo.

```js
// (field, isNew, section)
wp.hooks.addAction( 'hupkec.fieldModal.opened', 'miaddon', function ( field, isNew, section ) {
    document.getElementById( 'mi-control' ).value = field.conditional_rules || '';
} );
```

### `hupkec.fieldModal.collect` — filtro

Antes de guardar el campo. Añade tus propiedades leyendo tus controles. Deben
aceptarse también en PHP con `hupkec_field_schema`, o se descartan al guardar.

```js
// (field, isNew, section) → field
wp.hooks.addFilter( 'hupkec.fieldModal.collect', 'miaddon', function ( field, isNew, section ) {
    field.conditional_rules = document.getElementById( 'mi-control' ).value;
    return field;
} );
```

### `hupkec.fieldModal.validationError` — filtro

Validación en el navegador antes de guardar. Devolver un mensaje no vacío cancela
el guardado y lo muestra. (Es solo la comodidad de UI: la validación real vive en
`hupkec_field_validation_error`, en servidor.)

```js
// (error, field, isNew, section) → error
wp.hooks.addFilter( 'hupkec.fieldModal.validationError', 'miaddon', function ( error, field ) {
    return field.conditional_rules === 'ROTA' ? 'La regla no es válida.' : error;
} );
```

### `hupkec.zoneForm.opened` / `.collect` / `.validationError`

Idénticos a los del modal de campos, para el editor de zonas.
`opened` recibe `(zone, isNew)` — `zone` es `null` en zona nueva.
`collect` recibe y devuelve el objeto de datos que se envía por AJAX.

```js
wp.hooks.addAction( 'hupkec.zoneForm.opened', 'miaddon', function ( zone, isNew ) { /* … */ } );

wp.hooks.addFilter( 'hupkec.zoneForm.collect', 'miaddon', function ( data, isNew ) {
    data.formula = document.getElementById( 'mi-formula' ).value;
    return data; // recogido en PHP con hupkec_zone_save_data
} );
```

---

## Principio de blindaje

Todos los filtros del plugin siguen la misma regla: **un addon suma, nunca
reemplaza**.

- Las claves y elementos propios del plugin (módulos, pestañas, tipos nativos,
  claves de esquema, `id`/`cost` de una tarifa, `nonce`/`ajaxUrl`) se reponen
  después de cada filtro. Un addon no puede eliminarlos ni sobrescribirlos.
- Un filtro que devuelva un tipo inesperado (basura, `null`, un array donde se
  espera un escalar) se ignora y se conserva el valor del plugin.
- Un módulo cuyo archivo no exista se salta sin detener la carga del resto.

La consecuencia práctica: un addon roto falla solo él. No puede dejar la tienda
sin checkout, sin poder guardar, ni cobrando importes incorrectos.
