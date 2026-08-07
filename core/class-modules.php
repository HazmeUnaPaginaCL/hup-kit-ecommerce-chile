<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Registro de módulos de HUP - Kit Ecommerce Chile.
 *
 * Hasta la 1.0.0 los módulos estaban declarados en tres sitios que había que
 * mantener sincronizados a mano: los valores por defecto de la activación, los de
 * hupkec_get_modules() y la lista literal del guardado por AJAX. Añadir un módulo
 * obligaba a tocar los tres, y olvidarse de uno no daba error: solo comportamiento
 * inconsistente entre lo que se guardaba y lo que se cargaba.
 *
 * Ahora hay una sola fuente de verdad, y además es filtrable.
 *
 * Forma de cada entrada del registro:
 *
 *     'clave' => [
 *         'label'       => 'Nombre visible',
 *         'desc'        => 'Qué hace, en una frase',
 *         'default'     => 0,      // estado inicial (0 u 1)
 *         'option'      => '',     // opción propia; vacío = vive en hupkec_modules
 *         'core'        => false,  // true = del plugin, no se puede quitar por filtro
 *         'always_load' => false,  // true = carga aunque el interruptor esté apagado
 *         'toggle'      => true,   // true = sale en los interruptores de Inicio
 *         'tab'         => '',     // pestaña que abre el interruptor al encenderse
 *         'file'        => '',     // archivo a incluir
 *         'class'       => '',     // clase a instanciar tras incluir el archivo
 *         'loader'      => null,   // callable alternativo, en vez de file + class
 *     ]
 *
 * @since 1.1.0
 */
class HUPKEC_Modules {

    /** Opción donde viven los interruptores de los módulos sin opción propia. */
    const OPTION = 'hupkec_modules';

    /** Registro ya filtrado y saneado. Se calcula una sola vez por request. */
    private static $registry = null;

    /** Estado de los interruptores, memoizado por request. */
    private static $states = null;

    /** Claves ya cargadas, para no instanciar dos veces. */
    private static $loaded = [];

    /**
     * Módulos que trae el plugin. Es la base sobre la que actúa el filtro.
     *
     * 'comunas' no declara archivo ni clase: el selector Región→Comuna vive en
     * core/class-frontend.php y core/class-settings.php, y siempre está cargado.
     * Su interruptor solo decide si el listado se filtra a las comunas elegidas.
     *
     * @return array
     */
    private static function base_registry() {
        return [
            'comunas' => [
                'label'       => 'Filtrar comunas donde despacho',
                'desc'        => 'Limita el selector del checkout a las comunas que elijas, en vez de mostrar las 346 del país. Con el filtro apagado se ofrecen todas. Puedes agregar sectores personalizados por región.',
                'default'     => 1,
                'option'      => '',
                'core'        => true,
                'always_load' => false,
                'toggle'      => true,
                'tab'         => 'comunas',
                'file'        => '',
                'class'       => '',
                'loader'      => null,
            ],
            'shipping' => [
                'label'   => 'Zonas de despacho',
                'desc'    => 'Agrupa las comunas activas en zonas y asígnales un costo de envío. El costo se suma automáticamente al total del carrito.',
                'default' => 0,
                'option'  => '',
                'core'    => true,
                // Se carga siempre: el panel de administración necesita sus hooks
                // AJAX (crear, guardar y borrar zonas) aunque el módulo esté apagado.
                // Quien respeta el interruptor es el propio método de envío, en
                // HUPKEC_Shipping_Method::calculate_shipping().
                'always_load' => true,
                'toggle'      => true,
                'tab'         => 'zonas',
                'file'        => HUPKEC_PATH . 'modules/shipping/class-shipping.php',
                'class'       => 'HUPKEC_Shipping',
                'loader'      => null,
            ],
            'checkout' => [
                'label'       => 'Editor de campos del checkout',
                'desc'        => 'Reordena, activa/desactiva y personaliza los campos del formulario de pago. Incluye el campo RUT/DNI por defecto, adaptado al ecommerce chileno.',
                'default'     => 0,
                'option'      => '',
                'core'        => true,
                'always_load' => false,
                'toggle'      => true,
                'tab'         => 'campos',
                'file'        => HUPKEC_PATH . 'modules/checkout/class-checkout.php',
                'class'       => 'HUPKEC_Checkout',
                'loader'      => null,
            ],
            'billing' => [
                'label'   => 'Datos de facturación para empresas',
                'desc'    => 'Añade al checkout una casilla "¿Necesitas factura?" con los datos de la empresa.',
                'default' => 0,
                // Opción propia, deliberadamente fuera de hupkec_modules: tener el
                // interruptor en dos sitios generaba dos fuentes de verdad y el
                // cargador solo leía una.
                'option'      => 'hupkec_billing_module',
                'core'        => true,
                'always_load' => false,
                // No sale entre los interruptores de Inicio: tiene su propia
                // tarjeta con vista previa dentro de la pestaña de campos.
                'toggle'      => false,
                'tab'         => 'campos',
                'file'        => HUPKEC_PATH . 'modules/billing/class-billing.php',
                'class'       => 'HUPKEC_Billing',
                'loader'      => null,
            ],
        ];
    }

    /**
     * Devuelve el registro completo, ya filtrado.
     *
     * El filtro se aplica una sola vez por request. Un addon debe registrarse en
     * el cuerpo de su archivo principal, no dentro de un hook tardío, porque el
     * cargador consulta el registro en 'plugins_loaded' con prioridad 10.
     *
     * @since 1.1.0
     * @param array $registry Módulos indexados por clave.
     */
    public static function registry() {
        if ( self::$registry === null ) {
            $base = self::base_registry();
            self::$registry = self::sanitize( apply_filters( 'hupkec_modules_registry', $base ), $base );
        }
        return self::$registry;
    }

    /**
     * Sanea lo que devuelve el filtro.
     *
     * Un addon con un error de tipeo no debe poder dejar la tienda sin checkout.
     * Por eso los módulos propios del plugin se restauran si el filtro los quita,
     * y sus rutas y clases no son sobreescribibles: lo único que un addon puede
     * cambiar de un módulo propio es la etiqueta y el estado inicial.
     *
     * @param mixed $registry Lo devuelto por el filtro.
     * @param array $base     Registro original del plugin.
     * @return array
     */
    private static function sanitize( $registry, $base ) {
        if ( ! is_array( $registry ) ) $registry = [];

        $clean = [];
        foreach ( $registry as $key => $mod ) {
            $key = sanitize_key( $key );
            if ( $key === '' || ! is_array( $mod ) ) continue;

            $clean[ $key ] = [
                'label'       => isset( $mod['label'] ) ? sanitize_text_field( (string) $mod['label'] ) : $key,
                'desc'        => isset( $mod['desc'] ) ? sanitize_text_field( (string) $mod['desc'] ) : '',
                'default'     => ! empty( $mod['default'] ) ? 1 : 0,
                'option'      => ! empty( $mod['option'] ) ? sanitize_key( (string) $mod['option'] ) : '',
                'core'        => ! empty( $mod['core'] ),
                'always_load' => ! empty( $mod['always_load'] ),
                // Ausente equivale a sí: un addon que registra un módulo espera
                // que su interruptor aparezca sin tener que pedirlo.
                'toggle'      => ! array_key_exists( 'toggle', $mod ) || ! empty( $mod['toggle'] ),
                'tab'         => ! empty( $mod['tab'] ) ? sanitize_key( (string) $mod['tab'] ) : '',
                'file'        => ( isset( $mod['file'] ) && is_string( $mod['file'] ) ) ? $mod['file'] : '',
                'class'       => ( isset( $mod['class'] ) && is_string( $mod['class'] ) ) ? $mod['class'] : '',
                'loader'      => ( isset( $mod['loader'] ) && is_callable( $mod['loader'] ) ) ? $mod['loader'] : null,
            ];
        }

        // Reconstruir en el orden del plugin, con los propios blindados delante.
        $result = [];
        foreach ( $base as $key => $mod ) {
            if ( isset( $clean[ $key ] ) ) {
                // De un módulo propio solo se acepta lo cosmético y el estado inicial.
                $mod['label']   = $clean[ $key ]['label'];
                $mod['desc']    = $clean[ $key ]['desc'] !== '' ? $clean[ $key ]['desc'] : $mod['desc'];
                $mod['default'] = $clean[ $key ]['default'];
            }
            $result[ $key ] = $mod;
            unset( $clean[ $key ] );
        }

        // Y detrás, los módulos que haya aportado el filtro.
        foreach ( $clean as $key => $mod ) {
            $mod['core'] = false;
            $result[ $key ] = $mod;
        }

        return $result;
    }

    /**
     * Datos de un módulo concreto, o null si no está registrado.
     *
     * @param string $key
     * @return array|null
     */
    public static function get( $key ) {
        $registry = self::registry();
        return $registry[ $key ] ?? null;
    }

    /**
     * Claves de los módulos que comparten la opción hupkec_modules,
     * es decir, los que no tienen 'option' propia.
     *
     * @return string[]
     */
    public static function shared_keys() {
        $keys = [];
        foreach ( self::registry() as $key => $mod ) {
            if ( empty( $mod['option'] ) ) $keys[] = $key;
        }
        return $keys;
    }

    /**
     * Valores por defecto de los módulos que viven en hupkec_modules.
     *
     * @return array<string,int>
     */
    public static function defaults() {
        $defaults = [];
        foreach ( self::registry() as $key => $mod ) {
            if ( empty( $mod['option'] ) ) $defaults[ $key ] = (int) $mod['default'];
        }
        return $defaults;
    }

    /**
     * Estado de los interruptores guardados en hupkec_modules.
     *
     * Memoizado por request: se consulta muchas veces. Las escrituras terminan en
     * wp_send_json y matan el proceso, así que la caché nunca queda obsoleta.
     *
     * @return array<string,int>
     */
    public static function states() {
        if ( self::$states === null ) {
            $saved = get_option( self::OPTION, [] );
            self::$states = wp_parse_args(
                is_array( $saved ) ? $saved : [],
                self::defaults()
            );
        }
        return self::$states;
    }

    /**
     * ¿Está activo este módulo?
     *
     * Resuelve por sí solo dónde vive el interruptor: en la opción propia del
     * módulo si la declara, o en hupkec_modules si no.
     *
     * @param string $key
     * @return bool
     */
    public static function is_active( $key ) {
        $mod = self::get( $key );
        if ( ! $mod ) return false;

        if ( ! empty( $mod['option'] ) ) {
            return (bool) get_option( $mod['option'], (int) $mod['default'] );
        }

        $states = self::states();
        return ! empty( $states[ $key ] );
    }

    /**
     * Incluye e instancia los módulos que corresponda.
     *
     * @return void
     */
    public static function load() {
        foreach ( self::registry() as $key => $mod ) {
            if ( empty( $mod['file'] ) && empty( $mod['loader'] ) ) continue;
            if ( empty( $mod['always_load'] ) && ! self::is_active( $key ) ) continue;
            self::load_one( $key, $mod );
        }

        /**
         * Todos los módulos activos ya están instanciados y con sus hooks puestos.
         *
         * @since 1.1.0
         */
        do_action( 'hupkec_modules_loaded' );
    }

    /**
     * Carga un módulo. Tolera que el archivo o la clase no existan: un addon roto
     * debe fallar solo él, no llevarse por delante el resto del plugin.
     *
     * @param string $key
     * @param array  $mod
     * @return void
     */
    private static function load_one( $key, $mod ) {
        if ( isset( self::$loaded[ $key ] ) ) return;
        self::$loaded[ $key ] = true;

        if ( is_callable( $mod['loader'] ) ) {
            call_user_func( $mod['loader'], $key, $mod );
            return;
        }

        if ( ! file_exists( $mod['file'] ) ) return;
        require_once $mod['file'];

        if ( $mod['class'] !== '' && class_exists( $mod['class'] ) ) {
            new $mod['class']();
        }
    }

    /**
     * Olvida el estado memoizado. Solo lo necesitan las pruebas y el guardado por
     * AJAX si alguna vez dejara de terminar en wp_send_json.
     *
     * @return void
     */
    public static function flush() {
        self::$states = null;
    }
}
