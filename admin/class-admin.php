<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class HUPKEC_Admin {

    /** Versión de hup-ui/ copiada en el plugin (ver hup-ui/CHANGELOG.md). */
    const UI_VERSION = '1.4.0';

    /** Color propio del plugin: SOLO el ícono del header (regla de hup-ui). Azul bandera de Chile. */
    const COLOR = '#0039a6';

    /** Slug de la página. */
    const PAGE = 'hupkec-settings';

    public function __construct() {
        // Prioridad 99: WooCommerce registra sus propios submenús hasta la 70
        // (Extensiones). Registrando después, el nuestro queda al final del listado.
        add_action( 'admin_menu',            [ $this, 'add_menu' ], 99 );
        add_action( 'admin_init',            [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_hupkec_save_comunas',        [ $this, 'ajax_save_comunas' ] );
        add_action( 'wp_ajax_hupkec_recreate_zone',        [ $this, 'ajax_recreate_zone' ] );
        add_action( 'wp_ajax_hupkec_get_active_comunas',    [ $this, 'ajax_get_active_comunas' ] );
        add_action( 'wp_ajax_hupkec_save_billing_module',   [ $this, 'ajax_save_billing_module' ] );
        add_filter( 'plugin_action_links_' . HUPKEC_BASENAME, [ $this, 'add_plugin_action_links' ] );
    }

    public function add_plugin_action_links( $links ) {
        $configure = '<a href="' . esc_url( admin_url( 'admin.php?page=hupkec-settings' ) ) . '">' . esc_html__( 'Configuración', 'hup-kit-ecommerce-chile' ) . '</a>';
        array_unshift( $links, $configure );
        return $links;
    }

    public function add_menu() {
        add_submenu_page( 'woocommerce', 'HUP - Kit Ecommerce Chile', 'HUP - Kit Ecommerce Chile', 'manage_woocommerce', 'hupkec-settings', [ $this, 'render_page' ] );
    }

    public function register_settings() {
        register_setting( 'hupkec_group', 'hupkec_settings', [
            'sanitize_callback' => [ $this, 'sanitize_settings_option' ],
        ] );
    }

    public function sanitize_settings_option( $input ) {
        if ( ! is_array( $input ) ) {
            return [];
        }
        $clean = [];
        $regions = array_keys( hupkec_get_regions_base() );

        $enabled_regions = array_map( 'sanitize_text_field', (array) ( $input['enabled_regions'] ?? [] ) );
        $clean['enabled_regions'] = array_values( array_intersect( $regions, $enabled_regions ) );

        $clean['enabled_comunas'] = [];
        foreach ( $regions as $code ) {
            $values = array_map( 'sanitize_text_field', (array) ( $input['enabled_comunas'][ $code ] ?? [] ) );
            $clean['enabled_comunas'][ $code ] = array_values( array_unique( $values ) );
        }

        $clean['custom_sectors'] = [];
        foreach ( $regions as $code ) {
            $sectors = [];
            foreach ( (array) ( $input['custom_sectors'][ $code ] ?? [] ) as $sector ) {
                $name = sanitize_text_field( $sector['name'] ?? '' );
                if ( $name ) {
                    $sectors[] = [ 'name' => $name ];
                }
            }
            if ( ! empty( $sectors ) ) {
                $clean['custom_sectors'][ $code ] = $sectors;
            }
        }

        return $clean;
    }

    public function enqueue_assets( $hook ) {
        if ( $hook !== 'woocommerce_page_hupkec-settings' ) return;

        // Sistema de diseño HUP (hup-ui/): el mismo en todos los plugins HUP.
        // Handles con el slug del plugin para que dos plugins HUP activos, con
        // versiones distintas de hup-ui, no se pisen.
        $slug   = 'hup-kit-ecommerce-chile';
        $tokens = 'hup-ui-tokens-' . $slug;
        $ui     = 'hup-ui-' . $slug;
        wp_enqueue_style( 'dashicons' );
        wp_enqueue_style( $tokens, HUPKEC_URL . 'hup-ui/tokens.css', [], self::UI_VERSION );
        wp_enqueue_style( $ui, HUPKEC_URL . 'hup-ui/hup-admin.css', [ $tokens ], self::UI_VERSION );
        wp_enqueue_script( $ui, HUPKEC_URL . 'hup-ui/hup-admin.js', [], self::UI_VERSION, true );

        // Lo propio del plugin: solo los ganchos de compatibilidad y el arrastre
        // de campos; el aspecto lo da hup-ui.
        // 'wp-hooks' es la librería de hooks de WordPress: da al panel el mismo
        // sistema de acciones y filtros que PHP, para que un addon pueda inyectar
        // controles dentro del modal de campos y del editor de zonas.
        wp_enqueue_style(  'hupkec-admin-style', HUPKEC_URL . 'assets/css/hupkec-admin.css', [ $ui ], HUPKEC_VERSION );
        wp_enqueue_script( 'hupkec-admin-js',    HUPKEC_URL . 'assets/js/hupkec-admin.js', [ 'jquery', 'wp-hooks', $ui ], HUPKEC_VERSION, true );

        $data = [
            'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
            'nonce'           => wp_create_nonce( 'hupkec_admin' ),
            'modules'         => hupkec_get_modules(),
            'fields'          => hupkec_get_fields(),
            'fieldTypes'      => hupkec_custom_field_types(),
            'costTypes'       => HUPKEC_Shipping::get_cost_types(),
            'zones'           => array_values( HUPKEC_Shipping::get_zones() ),
            'comunas'         => hupkec_get_comunas_base(),
            'activeComunas'   => HUPKEC_Settings::get_comunas(),
            'regions'         => hupkec_get_regions_base(),
            'settings'        => HUPKEC_Settings::get_settings(),
            'billingModule'   => hupkec_billing_module_active() ? 1 : 0,
            'version'         => HUPKEC_VERSION,
            'pageUrl'         => admin_url( 'admin.php?page=' . self::PAGE ),
        ];

        /**
         * Datos que el panel recibe en el objeto JS hupkecAdmin.
         *
         * Un addon que añada controles suele necesitar datos propios aquí
         * (opciones, etiquetas, estado). Las claves del plugin no se pueden
         * sustituir: se restauran después del filtro.
         *
         * @since 1.1.0
         * @param array $data
         */
        $filtered = apply_filters( 'hupkec_admin_localized_data', $data );
        if ( is_array( $filtered ) ) {
            // El addon suma, no reemplaza: 'nonce' y 'ajaxUrl' mal pisados dejarían
            // el panel entero sin guardar nada.
            $data = array_merge( $filtered, $data );
        }

        wp_localize_script( 'hupkec-admin-js', 'hupkecAdmin', $data );
    }

    public function ajax_save_billing_module() {
        check_ajax_referer( 'hupkec_admin', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die();
        $active = ! empty( $_POST['active'] ) ? 1 : 0;
        update_option( 'hupkec_billing_module', $active, false );
        wp_send_json_success( [ 'active' => $active ] );
    }

    public function ajax_get_active_comunas() {
        check_ajax_referer( 'hupkec_admin', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die();
        // Devolver siempre el estado actual de la BD — no el caché del HTML
        wp_send_json_success( HUPKEC_Settings::get_comunas() );
    }

    public function ajax_recreate_zone() {
        check_ajax_referer( 'hupkec_admin', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die();

        $existed = HUPKEC_Shipping::zone_exists();

        // Forzar la verificación completa: si la zona existe pero le falta la
        // ubicación de Chile o el método de envío, ensure_global_zone() la repara;
        // si no existe, la crea desde cero.
        delete_transient( 'hupkec_zone_verified' );
        HUPKEC_Shipping::ensure_global_zone();
        HUPKEC_Shipping::flush_rates_cache();

        if ( ! HUPKEC_Shipping::zone_exists() ) {
            wp_send_json_error( 'No se pudo crear la zona. Asegúrate de que WooCommerce está activo.' );
        }

        if ( $existed ) {
            wp_send_json_success( [ 'status' => 'exists', 'msg' => 'Zona de envío verificada correctamente en WooCommerce.' ] );
        }

        wp_send_json_success( [ 'status' => 'created', 'msg' => 'Zona de envío creada correctamente en WooCommerce.' ] );
    }

    public function ajax_save_comunas() {
        check_ajax_referer( 'hupkec_admin', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die();

        $raw_settings = get_option( 'hupkec_settings', [] );
        if ( ! is_array( $raw_settings ) ) $raw_settings = [];

        $all_regions   = array_keys( hupkec_get_regions_base() );
        $all_comunas   = hupkec_get_comunas_base();

        // Regiones activas enviadas
        $enabled_regions = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['enabled_regions'] ?? [] ) );

        // Comunas activas por región
        $enabled_comunas = [];
        foreach ( $all_regions as $code ) {
            // Sanitizado con sanitize_text_field en la línea siguiente.
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $sent = (array) wp_unslash( $_POST['enabled_comunas'][ $code ] ?? [] );
            $sent = array_map( 'sanitize_text_field', $sent );

            // Intersectar solo con las comunas oficiales de esa región.
            // Los sectores personalizados NO se guardan aquí: viven en 'custom_sectors'
            // y HUPKEC_Settings::get_comunas() los añade a la lista final. Incluirlos
            // en ambos sitios los duplicaba en el <select> del checkout.
            $valid = $all_comunas[ $code ] ?? [];

            $enabled_comunas[ $code ] = array_values( array_intersect( $valid, $sent ) );
        }

        // Procesar sectores personalizados ANTES de filtrar regiones.
        // wp_unslash recursivo; cada 'name' se sanitiza con sanitize_text_field en el bucle.
        $custom_sectors = [];
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $raw_sectors = (array) wp_unslash( $_POST['custom_sectors'] ?? [] );
        foreach ( $all_regions as $code ) {
            $sectors = $raw_sectors[ $code ] ?? [];
            $clean   = [];
            foreach ( (array) $sectors as $sector ) {
                $name = sanitize_text_field( $sector['name'] ?? '' );
                if ( $name ) $clean[] = [ 'name' => $name ];
            }
            if ( ! empty( $clean ) ) {
                $custom_sectors[ $code ] = $clean;
            }
        }

        // Filtrar regiones: mantener si tiene comunas O sectores personalizados
        foreach ( $enabled_regions as $idx => $code ) {
            $has_comunas  = ! empty( $enabled_comunas[ $code ] );
            $has_sectors  = ! empty( $custom_sectors[ $code ] );
            
            if ( ! $has_comunas && ! $has_sectors ) {
                unset( $enabled_regions[ $idx ] );
            }
        }
        $enabled_regions = array_values( $enabled_regions );

        $raw_settings['enabled_regions'] = array_values( $enabled_regions );
        $raw_settings['enabled_comunas'] = $enabled_comunas;
        $raw_settings['custom_sectors']  = $custom_sectors;

        // Pasar por el mismo sanitizador del Settings API para garantizar
        // que ambos caminos de escritura (form nativo y AJAX) aplican la misma
        // lógica de sanitización y no divergen en el futuro.
        $clean_settings = $this->sanitize_settings_option( $raw_settings );
        update_option( 'hupkec_settings', $clean_settings, false );
        // Las comunas activas cambian lo que el cliente puede elegir: invalidar
        // las tarifas guardadas en las sesiones.
        if ( class_exists( 'HUPKEC_Shipping' ) ) HUPKEC_Shipping::flush_rates_cache();

        // Limpiar caché de envío
        if ( class_exists( 'WC_Cache_Helper' ) ) {
            WC_Cache_Helper::get_transient_version( 'shipping', true );
        }

        if ( function_exists( 'wc_get_logger' ) ) {
            $user = wp_get_current_user();
            wc_get_logger()->info(
                sprintf( 'HUPKEC: Comunas actualizadas por "%s". Regiones activas: %s', $user->user_login, implode( ', ', $clean_settings['enabled_regions'] ) ?: 'ninguna' ),
                [ 'source' => 'hup-kit-ecommerce-chile' ]
            );
        }

        wp_send_json_success( [
            'enabled_regions' => $clean_settings['enabled_regions'],
            'enabled_comunas' => $clean_settings['enabled_comunas'],
            // Lista final que consume el selector de comunas de las zonas: las
            // oficiales activas MÁS los sectores personalizados. 'enabled_comunas'
            // por sí solo no sirve ahí, porque los sectores viven aparte.
            'active_comunas'  => HUPKEC_Settings::get_comunas(),
        ] );
    }

    /**
     * Pestañas del panel, ya filtradas, saneadas y ordenadas.
     *
     * Forma de cada entrada:
     *
     *     'clave' => [
     *         'label'    => 'Nombre visible',
     *         'icon'     => 'dashicons-car', // clase de Dashicons (los emojis se traducen a uno)
     *         'desc'     => 'Una frase',     // opcional: subtítulo de la sección
     *         'module'   => 'shipping',      // módulo que la habilita; vacío = siempre visible
     *         'callback' => callable,        // pinta el contenido del panel
     *         'order'    => 50,              // menor = más arriba
     *     ]
     *
     * @since 1.1.0
     * @since 1.2.0 'icon' acepta una clase de Dashicons y se añade 'desc'.
     * @return array
     */
    private function get_tabs() {
        $base = [
            'inicio' => [
                'label' => 'Inicio', 'icon' => 'dashicons-dashboard', 'module' => '',
                'desc'  => 'Enciende los módulos que necesitas y revisa el estado de tu tienda.',
                'callback' => [ $this, 'tab_inicio' ], 'order' => 10, 'core' => true,
            ],
            'comunas' => [
                'label' => 'Comunas', 'icon' => 'dashicons-location', 'module' => 'comunas',
                'desc'  => 'Elige las regiones y comunas donde despachas.',
                'callback' => [ $this, 'tab_comunas' ], 'order' => 20, 'core' => true,
            ],
            'zonas' => [
                'label' => 'Despacho', 'icon' => 'dashicons-car', 'module' => 'shipping',
                'desc'  => 'Agrupa comunas en zonas y define cuánto cobras por despachar a cada una.',
                'callback' => [ $this, 'tab_zonas' ], 'order' => 30, 'core' => true,
            ],
            'campos' => [
                'label' => 'Campos', 'icon' => 'dashicons-forms', 'module' => 'checkout',
                'desc'  => 'Ordena y personaliza el formulario de pago.',
                'callback' => [ $this, 'tab_campos' ], 'order' => 40, 'core' => true,
            ],
            'guia' => [
                'label' => 'Guía', 'icon' => 'dashicons-book', 'module' => '',
                'desc'  => 'Cómo configurar el plugin y resolver los problemas más comunes.',
                'callback' => [ $this, 'tab_guia' ], 'order' => 90, 'core' => true,
            ],
            // Siempre la última, tras un separador (sanitize_tabs() la deja al final).
            'otros' => [
                'label' => 'Otros plugins HUP', 'icon' => 'dashicons-admin-plugins', 'module' => '',
                'desc'  => 'Más plugins gratuitos de HUP para tu sitio WordPress.',
                'callback' => [ $this, 'tab_otros' ], 'order' => 1000, 'core' => true,
            ],
        ];

        /**
         * Pestañas del panel de administración.
         *
         * Para añadir una, usa un 'order' entre 41 y 89 y da una 'callback' que
         * imprima el contenido. Las pestañas propias del plugin no se pueden
         * eliminar ni reasignar su callback; sí cambiar etiqueta, icono y orden.
         *
         * @since 1.1.0
         * @param array $tabs Pestañas indexadas por clave.
         */
        $tabs = apply_filters( 'hupkec_admin_tabs', $base );

        return $this->sanitize_tabs( $tabs, $base );
    }

    /**
     * Sanea lo que devuelve el filtro de pestañas.
     *
     * Una pestaña de addon sin callback válida dejaría un panel vacío y un botón
     * que no lleva a ninguna parte, así que se descarta. Las propias del plugin
     * se restauran si el filtro las quita.
     *
     * Ojo: los callbacks de las pestañas propias son métodos privados, y is_callable()
     * solo los reconoce desde dentro de la clase. Si este método se sacara a una clase
     * aparte, todas las pestañas del plugin se descartarían en silencio.
     *
     * @param mixed $tabs Lo devuelto por el filtro.
     * @param array $base Pestañas originales.
     * @return array
     */
    private function sanitize_tabs( $tabs, $base ) {
        if ( ! is_array( $tabs ) ) $tabs = [];

        $clean = [];
        foreach ( $tabs as $key => $tab ) {
            $key = sanitize_key( $key );
            if ( $key === '' || ! is_array( $tab ) ) continue;
            if ( ! isset( $tab['callback'] ) || ! is_callable( $tab['callback'] ) ) continue;

            $clean[ $key ] = [
                'label'    => isset( $tab['label'] ) ? sanitize_text_field( (string) $tab['label'] ) : $key,
                'icon'     => $this->tab_icon( $tab['icon'] ?? '' ),
                'desc'     => isset( $tab['desc'] ) ? sanitize_text_field( (string) $tab['desc'] ) : '',
                'module'   => ! empty( $tab['module'] ) ? sanitize_key( (string) $tab['module'] ) : '',
                'callback' => $tab['callback'],
                'order'    => isset( $tab['order'] ) ? (int) $tab['order'] : 50,
                'core'     => false,
            ];
        }

        // De una pestaña propia solo se acepta lo cosmético y el orden: la callback
        // y el módulo que la habilita se restauran siempre.
        foreach ( $base as $key => $tab ) {
            if ( isset( $clean[ $key ] ) ) {
                $tab['label'] = $clean[ $key ]['label'];
                $tab['icon']  = $clean[ $key ]['icon'];
                $tab['order'] = $clean[ $key ]['order'];
            }
            $clean[ $key ] = $tab;
        }

        uasort( $clean, function( $a, $b ) {
            return $a['order'] <=> $b['order'];
        } );

        // «Otros plugins HUP» va última aunque un addon use un orden mayor.
        $otros = $clean['otros'];
        unset( $clean['otros'] );
        $clean['otros'] = $otros;

        return $clean;
    }

    /**
     * Clase de Dashicons para el ícono de una pestaña.
     *
     * Hasta la 1.1.x el menú usaba emojis; el sistema de diseño HUP exige
     * Dashicons. Los emojis que ya registran los addons se traducen a su
     * equivalente para que no queden pestañas sin ícono.
     *
     * @since 1.2.0
     * @param mixed $icon Lo que trae la pestaña.
     * @return string Clase 'dashicons-…'.
     */
    private function tab_icon( $icon ) {
        $icon = is_string( $icon ) ? trim( $icon ) : '';
        if ( preg_match( '/^dashicons-[a-z0-9-]+$/', $icon ) ) return $icon;

        $map = [
            '🏠' => 'dashicons-dashboard',
            '📍' => 'dashicons-location',
            '🚚' => 'dashicons-car',
            '📝' => 'dashicons-forms',
            '🔑' => 'dashicons-lock',
            '🧩' => 'dashicons-feedback',
            '🏷'  => 'dashicons-tag',
            '➗' => 'dashicons-calculator',
            '📦' => 'dashicons-location-alt',
        ];
        // Quitar el selector de variación (U+FE0F) que traen algunos emojis.
        $icon = str_replace( "\u{FE0F}", '', $icon );
        return $map[ $icon ] ?? 'dashicons-admin-generic';
    }

    /**
     * ¿Debe verse el botón de esta pestaña?
     *
     * Una pestaña atada a un módulo apagado se oculta, pero su panel se imprime
     * igual: el JS lo muestra en cuanto se enciende el interruptor, sin recargar.
     *
     * @param array $tab
     * @return bool
     */
    private function tab_is_visible( $tab ) {
        return empty( $tab['module'] ) || HUPKEC_Modules::is_active( $tab['module'] );
    }

    /**
     * Pestaña abierta: la de ?tab= si existe y está visible; si no, Inicio.
     *
     * @param array $tabs
     * @return string
     */
    private function current_tab( $tabs ) {
        // Solo decide qué pestaña se muestra; no procesa ningún dato.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
        if ( $tab && isset( $tabs[ $tab ] ) && $this->tab_is_visible( $tabs[ $tab ] ) ) {
            return $tab;
        }
        return 'inicio';
    }

    /**
     * URL de una pestaña del panel.
     *
     * @param string $tab
     * @return string
     */
    private function tab_url( $tab ) {
        return add_query_arg( [ 'page' => self::PAGE, 'tab' => $tab ], admin_url( 'admin.php' ) );
    }

    /** Tooltip (i) junto a un label. */
    private function tip( $text ) {
        printf(
            '<span class="hup-tip" tabindex="0" data-tip="%s"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span></span>',
            esc_attr( $text )
        );
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) return;

        $tabs    = $this->get_tabs();
        $current = $this->current_tab( $tabs );
        ?>
        <div class="wrap hupkec-wrap">
            <h1 class="screen-reader-text">HUP - Kit Ecommerce Chile</h1>

            <div class="hup-app" style="--hup-plugin:<?php echo esc_attr( self::COLOR ); ?>">
                <header class="hup-header">
                    <div class="hup-header__icon"><span class="dashicons dashicons-cart" aria-hidden="true"></span></div>
                    <div class="hup-header__text">
                        <h2 class="hup-header__title">HUP - Kit Ecommerce Chile <span class="hup-badge hup-badge--version">v<?php echo esc_html( HUPKEC_VERSION ); ?></span></h2>
                        <p class="hup-header__desc">Regiones, comunas, despacho por comuna, RUT y facturación para tu tienda WooCommerce en Chile.</p>
                    </div>
                    <div class="hup-header__support"><span class="dashicons dashicons-email-alt" aria-hidden="true"></span>¿Dudas o soporte? <a href="<?php echo esc_url( 'mailto:contacto@hazmeunapagina.cl' ); ?>">contacto@hazmeunapagina.cl</a></div>
                </header>

                <?php
                /**
                 * Justo debajo de la cabecera del panel, antes del layout.
                 *
                 * Es donde un addon pondría un aviso propio.
                 *
                 * @since 1.1.0
                 */
                do_action( 'hupkec_admin_header_after' );
                ?>

                <div class="hup-layout">
                    <nav class="hup-nav" aria-label="Secciones">
                        <ul class="hup-nav__list">
                            <?php foreach ( $tabs as $key => $tab ) :
                                $classes = 'hup-nav__item hupkec-tab-btn';
                                if ( $key === $current ) $classes .= ' is-active';
                                if ( ! $this->tab_is_visible( $tab ) ) $classes .= ' hupkec-hidden';
                                ?>
                                <?php if ( 'otros' === $key ) : ?><li class="hup-nav__sep" role="separator"></li><?php endif; ?>
                                <li><a class="<?php echo esc_attr( $classes ); ?>" data-tab="<?php echo esc_attr( $key ); ?>" href="<?php echo esc_url( $this->tab_url( $key ) ); ?>"<?php echo $key === $current ? ' aria-current="page"' : ''; ?>>
                                    <span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>" aria-hidden="true"></span><?php echo esc_html( $tab['label'] ); ?>
                                </a></li>
                            <?php endforeach; ?>
                        </ul>
                        <div class="hup-nav__footer">Hecho por <a href="https://hazmeunapagina.cl" target="_blank" rel="noopener noreferrer"><strong>HUP</strong></a> <span aria-hidden="true">💻</span><br>Desde Quilpué para <a href="https://wordpress.org/plugins/search/hup/" target="_blank" rel="noopener noreferrer"><strong>WordPress</strong></a></div>
                    </nav>

                    <main class="hup-main">
                        <?php foreach ( $tabs as $key => $tab ) : ?>
                            <section class="hupkec-panel<?php echo $key === $current ? ' is-active' : ''; ?>" id="tab-<?php echo esc_attr( $key ); ?>">
                                <?php if ( ! empty( $tab['desc'] ) ) : ?>
                                    <div class="hup-page-head">
                                        <h2><?php echo esc_html( $tab['label'] ); ?></h2>
                                        <p><?php echo esc_html( $tab['desc'] ); ?></p>
                                    </div>
                                <?php endif; ?>
                                <?php
                                call_user_func( $tab['callback'] );

                                /**
                                 * Al final del panel de una pestaña concreta.
                                 *
                                 * Permite añadir secciones dentro de una pestaña existente
                                 * sin sustituir su contenido. Por ejemplo:
                                 * hupkec_after_tab_zonas, hupkec_after_tab_campos.
                                 *
                                 * @since 1.1.0
                                 */
                                do_action( "hupkec_after_tab_{$key}" );
                                ?>
                            </section>
                        <?php endforeach; ?>
                    </main>
                </div>
            </div>
        </div>
        <?php
    }

    /* ── INICIO ─────────────────────────────────────────── */
    private function tab_inicio() {
        $registry = HUPKEC_Modules::registry();
        $teasers  = array_diff_key( $this->pro_modules(), $registry );
        ?>
        <div class="hup-grid hup-grid--aside">
            <div class="hup-card">
                <div class="hup-card__head">
                    <div>
                        <h3 class="hup-card__title">Módulos</h3>
                        <p class="hup-card__desc">El selector de regiones y comunas está siempre activo. Enciende solo lo que uses.</p>
                    </div>
                </div>
                <div class="hup-card__body">
                    <div class="hup-rows">
                        <?php
                        // Los interruptores salen del registro de módulos, así que un addon
                        // que registre el suyo aparece aquí sin tocar esta plantilla.
                        foreach ( $registry as $key => $mod ) {
                            if ( empty( $mod['toggle'] ) ) continue;
                            $this->toggle( $key, $mod, HUPKEC_Modules::is_active( $key ) );
                        }

                        // Cada ficha promocional se muestra hasta que su clave aparece
                        // en el registro de módulos con el interruptor correspondiente.
                        foreach ( $teasers as $mod ) {
                            $this->pro_teaser( $mod );
                        }
                        ?>
                    </div>
                    <?php if ( $teasers ) : ?>
                        <p class="hup-hint">Los módulos marcados <span class="hup-badge hup-badge--pro">PRO</span> vienen en la <a href="<?php echo esc_url( $this->pro_url() ); ?>" target="_blank" rel="noopener">versión Pro</a>, junto con opciones avanzadas de envío y campos.</p>
                    <?php endif; ?>
                </div>
            </div>
            <?php $this->status_card(); ?>
        </div>
        <?php
    }

    /**
     * URL de la página de la versión Pro.
     *
     * Centralizada en un solo punto: cambiar aquí si la landing del Pro tiene
     * otra dirección.
     *
     * @return string
     */
    private function pro_url() {
        return 'https://www.hazmeunapagina.cl/web/plugins/';
    }

    /* ── OTROS PLUGINS HUP ──────────────────────────────── */
    private function tab_otros() {
        $render = include HUPKEC_PATH . 'hup-ui/otros-plugins.php';
        if ( is_callable( $render ) ) {
            $render( 'hup-kit-ecommerce-chile' );
        }
    }

    /**
     * Módulos de la versión Pro que se muestran como teaser en Inicio.
     *
     * Las claves coinciden con las que el Pro registra vía
     * 'hupkec_modules_registry'. tab_inicio() muestra una ficha promocional
     * mientras su módulo no esté registrado.
     *
     * @return array<string,array{label:string,desc:string}>
     */
    private function pro_modules() {
        return [
            'tracking' => [
                'label' => 'Números de seguimiento',
                'desc'  => 'Agrega el número de seguimiento a cada pedido y lo envía al cliente, en el email de pedido completado, como enlace directo al rastreo de la empresa de transporte.',
            ],
            'labels' => [
                'label' => 'Etiquetas PDF',
                'desc'  => 'Arma e imprime una etiqueta en PDF con los datos del pedido y del cliente para pegar en el paquete. El diseño se configura con un constructor visual y es global para todos los pedidos.',
            ],
            'product_images' => [
                'label' => 'Carga de imágenes',
                'desc'  => 'Completa la imagen principal de los productos desde una cuadrícula de pendientes.',
            ],
        ];
    }

    /**
     * Fila de un módulo Pro no instalado: mismo formato que un módulo real,
     * pero sin interruptor. En su lugar, un enlace para conseguir el Pro.
     *
     * @param array $mod Entrada de pro_modules().
     */
    private function pro_teaser( $mod ) {
        ?>
        <div class="hup-row hupkec-module-row hupkec-module-row--pro">
            <div class="hup-row__text">
                <div class="hup-row__title hupkec-module-label"><?php echo esc_html( $mod['label'] ); ?> <span class="hup-badge hup-badge--pro">PRO</span></div>
                <div class="hup-row__desc"><?php echo esc_html( $mod['desc'] ); ?></div>
            </div>
            <a class="hup-btn hup-btn--secondary hup-btn--sm" href="<?php echo esc_url( $this->pro_url() ); ?>" target="_blank" rel="noopener">Ver versión Pro</a>
        </div>
        <?php
    }

    /**
     * Un interruptor de módulo.
     *
     * Las clases hupkec-module-row / -label / -checkbox no dan estilo: son los
     * ganchos que usa el addon Pro para marcar sus módulos. No quitarlas.
     *
     * @param string $key    Clave del módulo.
     * @param array  $mod    Entrada del registro.
     * @param bool   $active Estado actual.
     */
    private function toggle( $key, $mod, $active ) {
        $id = 'hupkec-mod-' . $key;
        ?>
        <div class="hup-row hupkec-module-row">
            <div class="hup-row__text">
                <div class="hup-row__title hupkec-module-label" id="<?php echo esc_attr( $id ); ?>-label"><?php echo esc_html( $mod['label'] ); ?></div>
                <div class="hup-row__desc"><?php echo esc_html( $mod['desc'] ); ?></div>
            </div>
            <label class="hup-toggle" for="<?php echo esc_attr( $id ); ?>">
                <input type="checkbox" id="<?php echo esc_attr( $id ); ?>"
                       class="hupkec-module-checkbox" data-module="<?php echo esc_attr( $key ); ?>"
                       data-tab="<?php echo esc_attr( $mod['tab'] ); ?>"
                       aria-labelledby="<?php echo esc_attr( $id ); ?>-label" <?php checked( $active ); ?>>
                <span class="hup-toggle__track"></span>
            </label>
        </div>
        <?php
    }

    /**
     * Resumen del estado de la tienda.
     *
     * Cuenta lo que de verdad ve el cliente (HUPKEC_Settings::get_comunas(),
     * con el filtro de comunas y los sectores aplicados), no lo guardado en
     * bruto: con el filtro apagado se ofrecen las 346 aunque haya una selección.
     */
    private function status_card() {
        $regions  = hupkec_get_regions_base();
        $offered  = HUPKEC_Settings::get_comunas();
        $active_r = count( array_filter( $offered ) );
        $active_c = array_sum( array_map( 'count', $offered ) );
        $shipping = hupkec_module_active( 'shipping' );
        $zones    = HUPKEC_Shipping::get_zones();

        $sin_zona = 0;
        if ( $shipping ) {
            foreach ( $offered as $list ) {
                foreach ( $list as $comuna ) {
                    if ( ! HUPKEC_Shipping::get_zone_for_city( $comuna ) ) $sin_zona++;
                }
            }
        }
        ?>
        <div class="hup-card">
            <div class="hup-card__head">
                <div>
                    <h3 class="hup-card__title">Estado de la tienda</h3>
                    <p class="hup-card__desc">Lo que ve el cliente en el checkout.</p>
                </div>
            </div>
            <div class="hup-card__body">
                <dl class="hup-kv">
                    <dt>Regiones</dt>
                    <dd><?php echo esc_html( sprintf( '%d de %d', $active_r, count( $regions ) ) ); ?></dd>
                    <dt>Comunas</dt>
                    <dd><?php echo esc_html( number_format_i18n( $active_c ) ); ?></dd>
                    <?php if ( $shipping ) : ?>
                        <dt>Zonas de despacho</dt>
                        <dd><?php echo esc_html( number_format_i18n( count( $zones ) ) ); ?></dd>
                        <dt>Comunas sin zona</dt>
                        <dd>
                            <?php if ( $sin_zona > 0 ) : ?>
                                <span class="hup-badge hup-badge--warning hup-badge--dot"><?php echo esc_html( number_format_i18n( $sin_zona ) ); ?></span>
                            <?php else : ?>
                                <span class="hup-badge hup-badge--success hup-badge--dot">Ninguna</span>
                            <?php endif; ?>
                        </dd>
                        <dt>Zona en WooCommerce</dt>
                        <dd>
                            <?php if ( HUPKEC_Shipping::zone_exists() ) : ?>
                                <span class="hup-badge hup-badge--success hup-badge--dot">Activa</span>
                            <?php else : ?>
                                <span class="hup-badge hup-badge--danger hup-badge--dot">Falta</span>
                            <?php endif; ?>
                        </dd>
                    <?php endif; ?>
                </dl>
                <?php if ( $shipping && $sin_zona > 0 ) : ?>
                    <p class="hup-hint">Las comunas sin zona no muestran envío en el checkout. <a href="<?php echo esc_url( $this->tab_url( 'zonas' ) ); ?>" class="hupkec-goto" data-tab="zonas">Asígnalas en Despacho</a>.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /* ── COMUNAS ─────────────────────────────────────────── */
    private function tab_comunas() {
        $regions  = hupkec_get_regions_base();
        $comunas  = hupkec_get_comunas_base();
        $settings = HUPKEC_Settings::get_settings();
        $enabled_r= $settings['enabled_regions'] ?? array_keys( $regions );
        ?>
        <form id="hupkec-comunas-form" class="hup-card" novalidate>
            <div class="hup-card__head">
                <div>
                    <h3 class="hup-card__title">Regiones y comunas</h3>
                    <p class="hup-card__desc">Lo que desmarques no aparecerá en el selector de comunas del checkout.</p>
                </div>
            </div>
            <div class="hup-card__body">
                <div class="hup-toolbar">
                    <div class="hup-search">
                        <span class="dashicons dashicons-search" aria-hidden="true"></span>
                        <input type="search" id="hupkec-comunas-search" class="hup-input" placeholder="Buscar comuna en todas las regiones…" aria-label="Buscar comuna">
                    </div>
                    <div class="hup-btn-group" role="group" aria-label="Acciones sobre las comunas visibles">
                        <button type="button" id="hupkec-select-all-filtered">Marcar visibles</button>
                        <button type="button" id="hupkec-deselect-all-filtered">Desmarcar visibles</button>
                        <button type="button" id="hupkec-invert-all">Invertir</button>
                        <button type="button" id="hupkec-view-selected-btn" aria-pressed="false">Ver marcadas</button>
                    </div>
                </div>

                <div class="hup-stack">
                <?php foreach ( $regions as $code => $name ) :
                    $is_active       = in_array( $code, $enabled_r, true );
                    $enabled_comunas = $settings['enabled_comunas'][ $code ] ?? $comunas[ $code ];
                    $custom_sectors  = $settings['custom_sectors'][ $code ] ?? [];
                    // Región activa sin comunas ni sectores → no aparecerá en el checkout
                    $has_empty_warn  = $is_active && empty( $enabled_comunas ) && empty( $custom_sectors );

                    $display_comunas = $comunas[ $code ];
                    usort( $display_comunas, function( $a, $b ) {
                        return strcmp( remove_accents( mb_strtolower( $a ) ), remove_accents( mb_strtolower( $b ) ) );
                    } );
                    ?>
                    <div class="hup-checkgroup hupkec-region-box<?php echo $has_empty_warn ? ' is-empty' : ''; ?>" data-region="<?php echo esc_attr( $code ); ?>">
                        <div class="hup-checkgroup__head">
                            <label class="hup-check">
                                <input type="checkbox" class="hupkec-region-checkbox" data-region="<?php echo esc_attr( $code ); ?>"
                                       value="<?php echo esc_attr( $code ); ?>" <?php checked( $is_active ); ?>>
                                <?php echo esc_html( $name ); ?>
                            </label>
                            <span class="hup-badge hup-badge--warning hupkec-region-warn"<?php echo $has_empty_warn ? '' : ' hidden'; ?>>Sin comunas: no aparece en el checkout</span>
                            <div class="hup-checkgroup__actions">
                                <button type="button" class="hup-btn hup-btn--ghost hup-btn--sm hupkec-sel-all-region" data-region="<?php echo esc_attr( $code ); ?>">Todas</button>
                                <button type="button" class="hup-btn hup-btn--ghost hup-btn--sm hupkec-desel-all-region" data-region="<?php echo esc_attr( $code ); ?>">Ninguna</button>
                                <button type="button" class="hup-btn hup-btn--ghost hup-btn--sm hupkec-invert-region" data-region="<?php echo esc_attr( $code ); ?>">Invertir</button>
                            </div>
                            <span class="hup-count hupkec-region-count"><?php echo esc_html( count( array_intersect( $comunas[ $code ], $enabled_comunas ) ) . '/' . count( $comunas[ $code ] ) ); ?></span>
                        </div>
                        <div class="hup-checkgroup__body hup-checkgroup__body--scroll">
                            <?php foreach ( $display_comunas as $comuna ) : ?>
                                <label class="hup-check hupkec-comuna-item">
                                    <input type="checkbox" class="hupkec-comuna-checkbox" value="<?php echo esc_attr( $comuna ); ?>" <?php checked( in_array( $comuna, $enabled_comunas, true ) ); ?>>
                                    <?php echo esc_html( $comuna ); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="hup-checkgroup__foot">
                            <span class="hup-group-label">Sectores personalizados <?php $this->tip( 'Lugares que no son comunas oficiales (un condominio, un parque industrial). Aparecen en la misma lista de comunas del checkout.' ); ?></span>
                            <div class="hup-repeater hupkec-sectors-wrapper" data-hup-repeater data-region="<?php echo esc_attr( $code ); ?>" style="--hup-cols: minmax(0, 1fr) 34px">
                                <template>
                                    <div class="hup-repeater__row">
                                        <input type="text" class="hup-input hupkec-sector-input" placeholder="Ej: Chicureo, Parque Industrial…" aria-label="Nombre del sector">
                                        <button type="button" class="hup-btn hup-btn--icon hup-btn--danger-outline" data-hup-remove aria-label="Quitar sector"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
                                    </div>
                                </template>
                                <?php foreach ( $custom_sectors as $sector ) : ?>
                                    <div class="hup-repeater__row">
                                        <input type="text" class="hup-input hupkec-sector-input" value="<?php echo esc_attr( $sector['name'] ); ?>" aria-label="Nombre del sector">
                                        <button type="button" class="hup-btn hup-btn--icon hup-btn--danger-outline" data-hup-remove aria-label="Quitar sector"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
                                    </div>
                                <?php endforeach; ?>
                                <div><button type="button" class="hup-btn hup-btn--add hup-btn--sm" data-hup-add><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>Agregar sector</button></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
            <div class="hup-card__foot">
                <button type="submit" id="hupkec-save-comunas-btn" class="hup-btn"><span class="dashicons dashicons-yes" aria-hidden="true"></span>Guardar comunas</button>
            </div>
        </form>
        <?php
    }

    /* ── DESPACHO (ZONAS) ─────────────────────────────────── */
    private function tab_zonas() {
        $zone_ok = HUPKEC_Shipping::zone_exists();
        ?>
        <div class="hup-grid hup-grid--aside">
            <div class="hup-card">
                <div class="hup-card__head">
                    <div>
                        <h3 class="hup-card__title">Zonas de despacho</h3>
                        <p class="hup-card__desc">Si una comuna está en varias zonas, el cliente elige entre ellas en el checkout.</p>
                    </div>
                    <button type="button" class="hup-btn" id="hupkec-new-zone-btn"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>Nueva zona</button>
                </div>
                <div class="hup-card__body">
                    <div id="hupkec-zones-list"></div>
                </div>
            </div>

            <div class="hup-stack">
                <div class="hup-card">
                    <div class="hup-card__head">
                        <div>
                            <h3 class="hup-card__title">Zona en WooCommerce</h3>
                            <p class="hup-card__desc">El cálculo vive en la zona «HUP - Kit Ecommerce Chile (NO BORRAR)». No la elimines.</p>
                        </div>
                        <?php if ( $zone_ok ) : ?>
                            <span class="hup-badge hup-badge--success hup-badge--dot" id="hupkec-wc-zone-badge">Activa</span>
                        <?php else : ?>
                            <span class="hup-badge hup-badge--danger hup-badge--dot" id="hupkec-wc-zone-badge">Falta</span>
                        <?php endif; ?>
                    </div>
                    <div class="hup-card__body">
                        <div class="hup-notice" id="hupkec-recreate-msg" role="status" hidden>
                            <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><p></p>
                        </div>
                        <div class="hup-inline">
                            <button type="button" class="hup-btn hup-btn--secondary hup-btn--sm" id="hupkec-recreate-zone-btn"><span class="dashicons dashicons-update" aria-hidden="true"></span>Verificar o recrear</button>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=shipping' ) ); ?>" target="_blank" rel="noopener" class="hup-btn hup-btn--ghost hup-btn--sm">Ver zonas de WooCommerce</a>
                        </div>
                    </div>
                </div>

                <div class="hup-card">
                    <div class="hup-card__head">
                        <div>
                            <h3 class="hup-card__title">Probar una comuna</h3>
                            <p class="hup-card__desc">Simula el despacho y revisa la configuración que WooCommerce necesita.</p>
                        </div>
                    </div>
                    <div class="hup-card__body">
                        <div class="hup-field hup-field--stacked">
                            <label class="hup-label" for="hupkec-test-comuna">Comuna</label>
                            <div class="hup-control"><select id="hupkec-test-comuna" class="hup-select"></select></div>
                        </div>
                        <div class="hup-field hup-field--stacked">
                            <label class="hup-label" for="hupkec-test-subtotal">Subtotal del carrito <?php $this->tip( 'Para probar el umbral de envío gratis de las zonas con monto fijo.' ); ?></label>
                            <div class="hup-control">
                                <div class="hup-input-group"><span class="hup-input-group__addon">$</span><input type="number" id="hupkec-test-subtotal" class="hup-input" min="0" step="1" placeholder="0"></div>
                            </div>
                        </div>
                        <div><button type="button" class="hup-btn hup-btn--secondary" id="hupkec-test-btn"><span class="dashicons dashicons-search" aria-hidden="true"></span>Probar</button></div>
                        <div id="hupkec-test-result" class="hup-stack" aria-live="polite" hidden></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="hup-modal" id="hupkec-zone-modal" hidden>
            <div class="hup-modal__box hup-modal__box--wide" role="dialog" aria-modal="true" aria-labelledby="hupkec-zone-form-title">
                <div class="hup-card__head">
                    <h3 class="hup-card__title" id="hupkec-zone-form-title">Nueva zona de despacho</h3>
                    <button type="button" class="hup-btn hup-btn--icon" data-hup-close aria-label="Cerrar"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
                </div>
                <div class="hup-card__body hup-modal__scroll">
                    <input type="hidden" id="hupkec-zone-id">
                    <div class="hup-field">
                        <label class="hup-label" for="hz-name">Nombre</label>
                        <div class="hup-control"><input type="text" id="hz-name" class="hup-input" placeholder="Ej: Santiago centro" required></div>
                    </div>
                    <div class="hup-field">
                        <label class="hup-label" for="hz-color">Color <?php $this->tip( 'Solo para distinguir la zona en este panel.' ); ?></label>
                        <div class="hup-control"><input type="color" id="hz-color" value="#378ADD" class="hup-input hup-input--sm"></div>
                    </div>
                    <div class="hup-field">
                        <label class="hup-label" for="hz-cost-type">Costo de envío <?php $this->tip( '«Por pagar» no cobra en la tienda: el cliente paga el despacho al recibir.' ); ?></label>
                        <div class="hup-control">
                            <select id="hz-cost-type" class="hup-select">
                                <?php foreach ( HUPKEC_Shipping::get_cost_types() as $ct_key => $ct_label ) : ?>
                                    <option value="<?php echo esc_attr( $ct_key ); ?>"><?php echo esc_html( $ct_label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <?php // display en línea, no [hidden]: el addon Pro muestra estas filas con style.display. ?>
                    <div class="hup-field" id="hz-cost-value-row" style="display:none">
                        <label class="hup-label" for="hz-cost-value">Monto</label>
                        <div class="hup-control">
                            <div class="hup-input-group"><span class="hup-input-group__addon">$</span><input type="number" id="hz-cost-value" class="hup-input" min="0" step="1"></div>
                        </div>
                    </div>
                    <div class="hup-field" id="hz-free-from-row" style="display:none">
                        <label class="hup-label" for="hz-free-from">Gratis desde <?php $this->tip( 'Si el subtotal del carrito llega a este monto, el despacho sale gratis. Déjalo vacío para no usar umbral.' ); ?></label>
                        <div class="hup-control">
                            <div class="hup-input-group"><span class="hup-input-group__addon">$</span><input type="number" id="hz-free-from" class="hup-input" min="0" step="1" placeholder="Ej: 50000"></div>
                        </div>
                    </div>
                    <!-- Ancla de extensión: un addon inyecta aquí sus controles de
                         zona (fórmulas, costos por clase, información de entrega)
                         escuchando la acción JS 'hupkec.admin.ready'. Ver docs/hooks.md. -->
                    <div id="hupkec-zone-form-extra"></div>

                    <div class="hup-field hup-field--stacked">
                        <span class="hup-label">Comunas de la zona</span>
                        <div class="hup-control">
                            <div class="hup-toolbar">
                                <div class="hup-search">
                                    <span class="dashicons dashicons-search" aria-hidden="true"></span>
                                    <input type="search" id="hz-comunas-search" class="hup-input" placeholder="Buscar comuna…" aria-label="Buscar comuna">
                                </div>
                                <div class="hup-btn-group" role="group" aria-label="Acciones sobre las comunas">
                                    <button type="button" id="hz-sel-all">Todas</button>
                                    <button type="button" id="hz-desel-all">Ninguna</button>
                                    <button type="button" id="hz-invert-zone">Invertir</button>
                                </div>
                            </div>
                            <div id="hz-comunas-selector" class="hup-stack"></div>
                            <p class="hup-hint"><strong id="hz-selected-count">0</strong> comunas seleccionadas</p>
                        </div>
                    </div>
                </div>
                <div class="hup-card__foot">
                    <button type="button" class="hup-btn" id="hupkec-save-zone"><span class="dashicons dashicons-yes" aria-hidden="true"></span>Guardar zona</button>
                    <button type="button" class="hup-btn hup-btn--ghost" id="hupkec-cancel-zone" data-hup-close>Cancelar</button>
                </div>
            </div>
        </div>
        <?php
    }

    /* ── CAMPOS ─────────────────────────────────────────── */
    private function tab_campos() {
        $field_types = hupkec_custom_field_types();
        $billing_on  = hupkec_billing_module_active();
        ?>
        <div class="hup-card">
            <div class="hup-card__head">
                <div>
                    <h3 class="hup-card__title">Campos del checkout</h3>
                    <p class="hup-card__desc">Arrastra para ordenar, enciende o apaga y edita cada campo. El país es fijo: Chile.</p>
                </div>
            </div>
            <div class="hup-card__body">
                <div class="hup-tabs" role="tablist" aria-label="Sección del formulario">
                    <a href="#" role="tab" class="hupkec-fields-subtab is-active" data-section="billing" aria-selected="true">Facturación</a>
                    <a href="#" role="tab" class="hupkec-fields-subtab" data-section="shipping" aria-selected="false">Envío</a>
                    <a href="#" role="tab" class="hupkec-fields-subtab" data-section="additional" aria-selected="false">Adicionales</a>
                </div>
                <ul id="hupkec-fields-editor" class="hup-sortable"></ul>
                <div><button type="button" class="hup-btn hup-btn--add" id="hupkec-add-field"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>Agregar campo personalizado</button></div>
            </div>
            <div class="hup-card__foot">
                <button type="button" class="hup-btn" id="hupkec-save-fields"><span class="dashicons dashicons-yes" aria-hidden="true"></span>Guardar campos</button>
            </div>
        </div>

        <div class="hup-card">
            <div class="hup-card__head">
                <div>
                    <h3 class="hup-card__title" id="hupkec-billing-module-title">Datos de facturación para empresas</h3>
                    <p class="hup-card__desc">Agrega al checkout la casilla «¿Necesitas factura?». Si el cliente la marca, se piden los datos de su empresa.</p>
                </div>
                <label class="hup-toggle" for="hupkec-billing-module-toggle">
                    <input type="checkbox" id="hupkec-billing-module-toggle" aria-labelledby="hupkec-billing-module-title" <?php checked( $billing_on ); ?>>
                    <span class="hup-toggle__track"></span>
                </label>
            </div>
            <div class="hup-card__body" id="hupkec-billing-module-preview"<?php echo $billing_on ? '' : ' hidden'; ?>>
                <div class="hup-chips">
                    <?php foreach ( [ 'Razón social', 'RUT de la empresa', 'Giro', 'Región', 'Comuna', 'Dirección' ] as $bf ) : ?>
                        <span class="hup-badge"><?php echo esc_html( $bf ); ?></span>
                    <?php endforeach; ?>
                </div>
                <p class="hup-hint">El cliente puede copiar su región, comuna y dirección con un clic. El RUT de la empresa se valida igual que el del comprador.</p>
            </div>
        </div>

        <div class="hup-card hup-card--danger">
            <div class="hup-card__head">
                <div>
                    <h3 class="hup-card__title">Restaurar campos</h3>
                    <p class="hup-card__desc">Vuelve al formulario original del plugin. Se pierden el orden, los cambios y los campos personalizados.</p>
                </div>
            </div>
            <div class="hup-card__foot">
                <button type="button" class="hup-btn hup-btn--danger-outline" id="hupkec-reset-fields"><span class="dashicons dashicons-image-rotate" aria-hidden="true"></span>Restaurar campos</button>
            </div>
        </div>

        <div class="hup-modal" id="hupkec-field-modal" hidden>
            <div class="hup-modal__box hup-modal__box--wide" role="dialog" aria-modal="true" aria-labelledby="hupkec-field-modal-title">
                <div class="hup-card__head">
                    <h3 class="hup-card__title" id="hupkec-field-modal-title">Editar campo</h3>
                    <button type="button" class="hup-btn hup-btn--icon" id="hupkec-close-field-modal" data-hup-close aria-label="Cerrar"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
                </div>
                <div class="hup-card__body hup-modal__scroll">
                    <input type="hidden" id="hf-section">
                    <input type="hidden" id="hf-index">
                    <div class="hup-field" id="hf-type-group">
                        <label class="hup-label" for="hf-type">Tipo de campo</label>
                        <div class="hup-control">
                            <select id="hf-type" class="hup-select">
                                <?php foreach ( $field_types as $val => $label ) : ?>
                                    <option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="hup-field">
                        <label class="hup-label" for="hf-label">Etiqueta</label>
                        <div class="hup-control"><input type="text" id="hf-label" class="hup-input" required></div>
                    </div>
                    <div class="hup-field" id="hf-placeholder-group">
                        <label class="hup-label" for="hf-placeholder">Texto de ejemplo <?php $this->tip( 'El texto gris que se ve dentro del campo vacío (placeholder).' ); ?></label>
                        <div class="hup-control"><input type="text" id="hf-placeholder" class="hup-input"></div>
                    </div>
                    <div class="hup-field" id="hf-options-group" style="display:none">
                        <label class="hup-label" for="hf-options">Opciones <?php $this->tip( 'Una por línea, con el formato valor|Etiqueta. Si omites la etiqueta, se usa el valor.' ); ?></label>
                        <div class="hup-control"><textarea id="hf-options" class="hup-input hup-input--mono" rows="5" placeholder="retiro|Retiro en tienda&#10;despacho|Despacho a domicilio"></textarea></div>
                    </div>
                    <div class="hup-field" id="hf-default-group">
                        <label class="hup-label" for="hf-default">Valor por defecto</label>
                        <div class="hup-control"><input type="text" id="hf-default" class="hup-input"></div>
                    </div>
                    <div class="hup-field">
                        <span class="hup-label">Ancho</span>
                        <div class="hup-control">
                            <div class="hup-seg" role="radiogroup" aria-label="Ancho del campo">
                                <label><input type="radio" name="hf-width" value="wide" checked><span>Completo</span></label>
                                <label><input type="radio" name="hf-width" value="first"><span>Mitad izquierda</span></label>
                                <label><input type="radio" name="hf-width" value="last"><span>Mitad derecha</span></label>
                            </div>
                        </div>
                    </div>
                    <div class="hup-field">
                        <span class="hup-label">Obligatorio</span>
                        <div class="hup-control"><label class="hup-toggle" for="hf-required"><input type="checkbox" id="hf-required"><span class="hup-toggle__track"></span> El cliente debe completarlo</label></div>
                    </div>
                    <div class="hup-field" id="hf-visibility-group">
                        <span class="hup-label">Dónde se muestra <?php $this->tip( 'Solo para campos personalizados. Desmárcalo para datos internos que el cliente no debe ver.' ); ?></span>
                        <div class="hup-control">
                            <label class="hup-check"><input type="checkbox" id="hf-show-email" checked> En los correos del pedido</label>
                            <label class="hup-check"><input type="checkbox" id="hf-show-order" checked> En la ficha del pedido y la página de gracias</label>
                        </div>
                    </div>
                    <div class="hup-field">
                        <label class="hup-label" for="hf-cssclass">Clase CSS <?php $this->tip( 'Opcional, para dar estilos desde el tema.' ); ?></label>
                        <div class="hup-control"><input type="text" id="hf-cssclass" class="hup-input hup-input--mono" placeholder="mi-clase otra-clase"></div>
                    </div>
                    <!-- Ancla de extensión: un addon inyecta aquí sus secciones
                         (lógica condicional, reglas de validación) escuchando la
                         acción JS 'hupkec.admin.ready'. Ver docs/hooks.md. -->
                    <div id="hupkec-field-modal-extra"></div>
                </div>
                <div class="hup-card__foot">
                    <button type="button" class="hup-btn" id="hupkec-save-field"><span class="dashicons dashicons-yes" aria-hidden="true"></span>Aplicar</button>
                    <button type="button" class="hup-btn hup-btn--ghost" id="hupkec-cancel-field" data-hup-close>Cancelar</button>
                </div>
            </div>
        </div>
        <?php
    }

    /* ── GUÍA ─────────────────────────────────────────── */
    private function tab_guia() {
        ?>
        <div class="hup-grid hup-grid--aside">
            <div class="hup-stack">
                <div class="hup-card">
                    <div class="hup-card__head">
                        <div>
                            <h3 class="hup-card__title">Configuración en cuatro pasos</h3>
                            <p class="hup-card__desc">Lo mínimo para empezar a vender con despacho por comuna.</p>
                        </div>
                    </div>
                    <div class="hup-card__body">
                        <ol class="hup-steps">
                            <li><div class="hup-steps__title">Elige dónde despachas</div><p class="hup-hint">En <strong>Comunas</strong>, deja marcadas solo las regiones y comunas que atiendes. Con el filtro apagado se ofrecen las 346 comunas del país.</p></li>
                            <li><div class="hup-steps__title">Crea tus zonas de despacho</div><p class="hup-hint">Enciende <strong>Zonas de despacho</strong> en Inicio y, en <strong>Despacho</strong>, agrupa comunas con un costo: gratis, monto fijo (con umbral de envío gratis) o por pagar al recibir.</p></li>
                            <li><div class="hup-steps__title">Ajusta el formulario de pago</div><p class="hup-hint">Con el <strong>Editor de campos</strong> encendido, el RUT se valida en el navegador y en el servidor, y el país queda fijo en Chile.</p></li>
                            <li><div class="hup-steps__title">Prueba una comuna</div><p class="hup-hint">En <strong>Despacho → Probar una comuna</strong> ves qué zona y qué costo recibe el cliente, y si falta algo en WooCommerce.</p></li>
                        </ol>
                    </div>
                </div>

                <div class="hup-card">
                    <div class="hup-card__head">
                        <div><h3 class="hup-card__title">Preguntas frecuentes</h3></div>
                    </div>
                    <div class="hup-card__body">
                        <details class="hup-accordion">
                            <summary>El checkout dice que no hay métodos de envío</summary>
                            <ul>
                                <li>Usa <strong>Probar una comuna</strong> en Despacho: te dice qué falta.</li>
                                <li>Revisa que la comuna esté en alguna zona y que exista la zona «HUP - Kit Ecommerce Chile (NO BORRAR)» en WooCommerce → Ajustes → Envío.</li>
                                <li>WooCommerce guarda las tarifas en la sesión del cliente. Si cambiaste algo y no se refleja, cambia la comuna en el checkout o vacía el carrito.</li>
                            </ul>
                        </details>
                        <details class="hup-accordion">
                            <summary>¿Funciona con el checkout de bloques?</summary>
                            <p>No. El plugin usa el checkout clásico de WooCommerce. Si tu página de pago usa el bloque «Finalizar compra», reemplázalo por el shortcode <code>[woocommerce_checkout]</code>. Funciona con Bricks, Elementor y temas que usan el checkout clásico.</p>
                        </details>
                        <details class="hup-accordion">
                            <summary>¿Qué significa «Por pagar»?</summary>
                            <p>La tienda no cobra el despacho: el cliente lo paga al recibir, directamente a la empresa de transporte. En el checkout se muestra como «Por pagar al recibir».</p>
                        </details>
                        <details class="hup-accordion">
                            <summary>¿Cómo se valida el RUT?</summary>
                            <p>Con el dígito verificador (módulo 11), mientras el cliente escribe y otra vez en el servidor al confirmar el pedido. Acepta 12.345.678-9, 12345678-9 y 123456789.</p>
                        </details>
                        <details class="hup-accordion">
                            <summary>¿Qué es un sector personalizado?</summary>
                            <p>Un lugar donde despachas que no es una comuna oficial, como un condominio o un parque industrial. Se agrega por región en Comunas y aparece en la misma lista del checkout.</p>
                        </details>
                    </div>
                </div>
            </div>

            <div class="hup-card">
                <div class="hup-card__head">
                    <div>
                        <h3 class="hup-card__title">Soporte</h3>
                        <p class="hup-card__desc">Te respondemos por correo.</p>
                    </div>
                </div>
                <div class="hup-card__body">
                    <dl class="hup-kv">
                        <dt>Correo</dt>
                        <dd><a href="<?php echo esc_url( 'mailto:contacto@hazmeunapagina.cl' ); ?>">contacto@hazmeunapagina.cl</a></dd>
                        <dt>Sitio</dt>
                        <dd><a href="<?php echo esc_url( 'https://www.hazmeunapagina.cl' ); ?>" target="_blank" rel="noopener">hazmeunapagina.cl</a></dd>
                        <dt>Versión</dt>
                        <dd><?php echo esc_html( HUPKEC_VERSION ); ?></dd>
                    </dl>
                </div>
            </div>
        </div>
        <?php
    }

}
