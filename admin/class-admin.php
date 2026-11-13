<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class HUPKEC_Admin {

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
        $configure = '<a href="' . admin_url( 'admin.php?page=hupkec-settings' ) . '">' . __( 'Configurar', 'hup-kit-ecommerce-chile' ) . '</a>';
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
        
        // CSS y JS del admin (sin jQuery UI).
        // 'wp-hooks' es la librería de hooks de WordPress: da al panel el mismo
        // sistema de acciones y filtros que PHP, para que un addon pueda inyectar
        // controles dentro del modal de campos y del editor de zonas.
        wp_enqueue_style(  'hupkec-admin-style', HUPKEC_URL . 'assets/css/hupkec-admin.css',  [], HUPKEC_VERSION );
        wp_enqueue_script( 'hupkec-admin-js',    HUPKEC_URL . 'assets/js/hupkec-admin.js', [ 'jquery', 'wp-hooks' ], HUPKEC_VERSION, true );

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
     *         'icon'     => '📦',       // emoji del menú lateral
     *         'module'   => 'shipping', // módulo que la habilita; vacío = siempre visible
     *         'callback' => callable,   // pinta el contenido del panel
     *         'order'    => 50,         // menor = más arriba
     *     ]
     *
     * @since 1.1.0
     * @return array
     */
    private function get_tabs() {
        $base = [
            'inicio' => [
                'label' => 'Inicio', 'icon' => '🏠', 'module' => '',
                'callback' => [ $this, 'tab_inicio' ], 'order' => 10, 'core' => true,
            ],
            'comunas' => [
                'label' => 'Comunas', 'icon' => '📍', 'module' => 'comunas',
                'callback' => [ $this, 'tab_comunas' ], 'order' => 20, 'core' => true,
            ],
            'zonas' => [
                'label' => 'Zonas de despacho', 'icon' => '🚚', 'module' => 'shipping',
                'callback' => [ $this, 'tab_zonas' ], 'order' => 30, 'core' => true,
            ],
            'campos' => [
                'label' => 'Campos del checkout', 'icon' => '📝', 'module' => 'checkout',
                'callback' => [ $this, 'tab_campos' ], 'order' => 40, 'core' => true,
            ],
        ];

        /**
         * Pestañas del panel de administración.
         *
         * Para añadir una, usa un 'order' mayor que 40 y da una 'callback' que
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
                'icon'     => isset( $tab['icon'] ) ? sanitize_text_field( (string) $tab['icon'] ) : '',
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

        return $clean;
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

    public function render_page() {
        $tabs = $this->get_tabs();
        ?>
        <div class="hupkec-wrap">
            <!-- Header HUP Style -->
            <div class="hupkec-header">
                <div class="hupkec-header__logo">
                    <div class="hupkec-header__icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M3 3H5L5.4 5M5.4 5H21L19 12H8.5M5.4 5L8.5 12M8.5 12L6.5 17H19M8.5 12H17.5M11 20.5C11 21.3284 10.3284 22 9.5 22C8.67157 22 8 21.3284 8 20.5C8 19.6716 8.67157 19 9.5 19C10.3284 19 11 19.6716 11 20.5ZM19 20.5C19 21.3284 18.3284 22 17.5 22C16.6716 22 16 21.3284 16 20.5C16 19.6716 16.6716 19 17.5 19C18.3284 19 19 19.6716 19 20.5Z" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <circle cx="18" cy="6" r="4" fill="#FFD700"/>
                            <path d="M18 4L18.5 5.5H20L18.75 6.5L19.25 8L18 7L16.75 8L17.25 6.5L16 5.5H17.5L18 4Z" fill="#D52B1E"/>
                        </svg>
                    </div>
                    <div>
                        <h1 class="hupkec-header__title">HUP - Kit Ecommerce Chile <span class="hupkec-header__version">v<?php echo esc_html( HUPKEC_VERSION ); ?></span></h1>
                        <p class="hupkec-header__desc">Reúne en un solo plugin lo esencial para vender en Chile: selector dinámico de regiones y comunas, zonas de despacho con costo real, editor de campos del checkout con validación de RUT y datos de facturación para empresas. ¿Necesitas más? La <a href="<?php echo esc_url( $this->pro_url() ); ?>" target="_blank" rel="noopener">versión Pro</a> suma números de seguimiento, etiquetas PDF para tus paquetes y opciones avanzadas de envío y campos. ¿Dudas o sugerencias? Escríbenos a <a href="<?php echo esc_url( 'mailto:contacto@hazmeunapagina.cl' ); ?>">contacto@hazmeunapagina.cl</a> — Desarrollado por <a href="<?php echo esc_url( 'https://www.hazmeunapagina.cl' ); ?>" target="_blank" rel="noopener">HazmeUnaPagina.cl</a></p>
                    </div>
                </div>
            </div>

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

            <!-- Layout Grid: Sidebar + Content -->
            <div class="hupkec-layout">
                <!-- Sidebar -->
                <div class="hupkec-sidebar">
                    <?php $primera = true; foreach ( $tabs as $key => $tab ) : ?>
                        <button class="hupkec-tab-btn<?php echo $primera ? ' is-active' : ''; ?><?php echo $this->tab_is_visible( $tab ) ? '' : ' hupkec-hidden'; ?>" data-tab="<?php echo esc_attr( $key ); ?>">
                            <span class="hupkec-tab-btn__icon"><?php echo esc_html( $tab['icon'] ); ?></span>
                            <span><?php echo esc_html( $tab['label'] ); ?></span>
                        </button>
                    <?php $primera = false; endforeach; ?>
                    <div class="hupkec-sidebar-footer">
                        Hecho con ❤️ por HUP
                    </div>
                </div>

                <!-- Content -->
                <div class="hupkec-content">
                    <?php $primera = true; foreach ( $tabs as $key => $tab ) : ?>
                        <div class="hupkec-panel<?php echo $primera ? ' is-active' : ''; ?>" id="tab-<?php echo esc_attr( $key ); ?>">
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
                        </div>
                    <?php $primera = false; endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /* ── INICIO ─────────────────────────────────────────── */
    private function tab_inicio() {
        ?>
        <div class="hupkec-info-box">El selector de regiones y comunas está activo en el checkout. Activa los módulos adicionales según lo que necesites.</div>
        <div class="hupkec-card">
            <h3>Módulos opcionales</h3>
            <div class="hupkec-modules-list">
                <?php
                // Los interruptores salen del registro de módulos, así que un addon
                // que registre el suyo aparece aquí sin tocar esta plantilla.
                $registry = HUPKEC_Modules::registry();
                foreach ( $registry as $key => $mod ) {
                    if ( empty( $mod['toggle'] ) ) continue;
                    $this->toggle( $key, $mod, HUPKEC_Modules::is_active( $key ) );
                }

                // Teasers de módulos de la versión Pro. Se muestran SOLO si el Pro
                // no está instalado: cuando lo está, registra estos mismos módulos
                // (por su clave) en el registro de arriba con su interruptor real,
                // y aquí se omiten para no duplicarlos.
                foreach ( $this->pro_modules() as $key => $mod ) {
                    if ( isset( $registry[ $key ] ) ) continue;
                    $this->pro_teaser( $mod );
                }
                ?>
            </div>
        </div>
        <?php $this->status_card(); ?>
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
        return 'https://www.hazmeunapagina.cl/';
    }

    /**
     * Módulos de la versión Pro que se muestran como teaser en Inicio.
     *
     * Las claves ('tracking', 'labels') y los textos coinciden con los que el
     * plugin Pro registra vía 'hupkec_modules_registry'. Así, al instalar el
     * Pro, el módulo aparece exactamente en el mismo lugar pero con su
     * interruptor real, y este teaser deja de mostrarse (ver el de-dupe por
     * clave en tab_inicio). No aportan ninguna funcionalidad: son solo UI.
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
        <div class="hupkec-module-row hupkec-module-row--pro">
            <div class="hupkec-module-info">
                <div class="hupkec-module-label">
                    <?php echo esc_html( $mod['label'] ); ?>
                    <span class="hupkec-pro-tag">PRO</span>
                </div>
                <div class="hupkec-module-desc"><?php echo esc_html( $mod['desc'] ); ?></div>
            </div>
            <a class="hupkec-pro-cta" href="<?php echo esc_url( $this->pro_url() ); ?>" target="_blank" rel="noopener">Consigue la versión Pro</a>
        </div>
        <?php
    }

    /**
     * Un interruptor de módulo.
     *
     * @param string $key    Clave del módulo.
     * @param array  $mod    Entrada del registro.
     * @param bool   $active Estado actual.
     */
    private function toggle( $key, $mod, $active ) {
        ?>
        <div class="hupkec-module-row">
            <div class="hupkec-module-info">
                <div class="hupkec-module-label"><?php echo esc_html( $mod['label'] ); ?></div>
                <div class="hupkec-module-desc"><?php echo esc_html( $mod['desc'] ); ?></div>
            </div>
            <label class="hupkec-toggle" for="hupkec-mod-<?php echo esc_attr($key); ?>">
                <input type="checkbox" id="hupkec-mod-<?php echo esc_attr($key); ?>"
                       class="hupkec-module-checkbox" data-module="<?php echo esc_attr($key); ?>"
                       data-tab="<?php echo esc_attr( $mod['tab'] ); ?>" <?php checked($active); ?>>
                <span class="hupkec-toggle-track"></span><span class="hupkec-toggle-thumb"></span>
            </label>
        </div>
        <?php
    }

    private function status_card() {
        $settings = HUPKEC_Settings::get_settings();
        $regions  = hupkec_get_regions_base();
        $comunas  = hupkec_get_comunas_base();
        $total_c  = array_sum( array_map('count', $comunas) );
        $active_r = $settings['enabled_regions'] ?? array_keys($regions);
        $active_c = 0;
        foreach ($active_r as $rc) $active_c += count($settings['enabled_comunas'][$rc] ?? ($comunas[$rc] ?? []));
        $zones    = HUPKEC_Shipping::get_zones();
        $in_zone  = ! empty($zones) ? array_merge(...array_column(array_values($zones),'comunas')) : [];
        $all_act  = [];
        foreach ($active_r as $rc) $all_act = array_merge($all_act, $settings['enabled_comunas'][$rc] ?? ($comunas[$rc] ?? []));
        $sin_zona = count(array_diff($all_act, $in_zone));
        ?>
        <div class="hupkec-card">
            <h3>Estado actual</h3>
            <table class="hupkec-status-table">
                <tr><td>Regiones activas</td><td><?php echo count($active_r).' de '.count($regions); ?></td></tr>
                <tr><td>Comunas activas</td><td><?php echo esc_html( "$active_c de $total_c" ); ?></td></tr>
                <tr><td>Zonas de despacho</td><td><?php echo count($zones).' zona'.(count($zones)!==1?'s':'').' creada'.(count($zones)!==1?'s':''); ?></td></tr>
                <?php if ($sin_zona > 0): ?><tr class="hupkec-warn-row"><td>Comunas sin zona asignada</td><td><?php echo esc_html( $sin_zona ); ?> comunas</td></tr><?php endif; ?>
            </table>
        </div>
        <?php
    }

    /* ── COMUNAS ─────────────────────────────────────────── */
    private function tab_comunas() {
        $regions  = hupkec_get_regions_base();
        $comunas  = hupkec_get_comunas_base();
        $settings = HUPKEC_Settings::get_settings();
        $enabled_r= $settings['enabled_regions'] ?? array_keys($regions);
        ?>
        <p class="hupkec-tab-desc">Activa las regiones y comunas donde despachas. Las comunas desactivadas no aparecerán en el checkout.</p>
        <div class="hupkec-search-bar">
            <input type="text" id="hupkec-comunas-search" class="hupkec-search-input" placeholder="Buscar comuna... (filtra en todas las regiones)">
            <div class="hupkec-search-actions">
                <button type="button" class="hupkec-btn" id="hupkec-select-all-filtered">Seleccionar todas</button>
                <button type="button" class="hupkec-btn" id="hupkec-deselect-all-filtered">Deseleccionar todas</button>
                <button type="button" class="hupkec-btn hupkec-btn-invert" id="hupkec-invert-all">↔ Invertir selección global</button>
                <button type="button" class="hupkec-btn hupkec-btn-view-selected" id="hupkec-view-selected-btn">👁 Ver seleccionadas</button>
            </div>
        </div>
        <form id="hupkec-comunas-form" data-ajax="1">
            <div class="hupkec-regions-grid">
                <?php foreach ($regions as $code => $name):
                    $is_active       = in_array($code, $enabled_r, true);
                    $enabled_comunas = $settings['enabled_comunas'][$code] ?? $comunas[$code];
                    $custom_sectors  = $settings['custom_sectors'][$code] ?? [];
                    // Región activa sin comunas ni sectores → no aparecerá en el checkout
                    $has_empty_warn  = $is_active && empty( $enabled_comunas ) && empty( $custom_sectors );
                ?>
                <div class="hupkec-region-box<?php echo $has_empty_warn ? ' hupkec-region-box--empty-warn' : ''; ?>" data-region="<?php echo esc_attr($code); ?>">
                    <div class="hupkec-region-header">
                        <label class="hupkec-region-title">
                            <input type="checkbox" class="hupkec-region-checkbox" data-region="<?php echo esc_attr($code); ?>"
                                   name="hupkec_settings[enabled_regions][]" value="<?php echo esc_attr($code); ?>" <?php checked($is_active); ?>>
                            <strong><?php echo esc_html($name); ?></strong>
                        </label>
                        <div class="hupkec-region-actions">
                            <button type="button" class="hupkec-btn-micro hupkec-sel-all-region" data-region="<?php echo esc_attr($code); ?>" title="Seleccionar todas">✓</button>
                            <button type="button" class="hupkec-btn-micro hupkec-desel-all-region" data-region="<?php echo esc_attr($code); ?>" title="Deseleccionar todas">✗</button>
                            <button type="button" class="hupkec-btn-micro hupkec-invert-region" data-region="<?php echo esc_attr($code); ?>" title="Invertir selección">↔</button>
                            <span class="hupkec-region-count" id="hupkec-count-<?php echo esc_attr($code); ?>"><?php echo count($enabled_comunas).'/'.count($comunas[$code]); ?></span>
                        </div>
                    </div>
                    <div class="hupkec-comunas-grid">
                        <?php
                        $display_comunas = $comunas[$code];
                        usort($display_comunas, function($a, $b) {
                            return strcmp(
                                iconv('UTF-8','ASCII//TRANSLIT', mb_strtolower($a)),
                                iconv('UTF-8','ASCII//TRANSLIT', mb_strtolower($b))
                            );
                        });
                        foreach ($display_comunas as $comuna): ?>
                        <label class="hupkec-comuna-item" data-comuna="<?php echo esc_attr(mb_strtolower($comuna)); ?>">
                            <input type="checkbox" class="hupkec-comuna-checkbox hupkec-comuna-<?php echo esc_attr($code); ?>"
                                   name="hupkec_settings[enabled_comunas][<?php echo esc_attr($code); ?>][]"
                                   value="<?php echo esc_attr($comuna); ?>" <?php checked(in_array($comuna,$enabled_comunas,true)); ?>>
                            <?php echo esc_html($comuna); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="hupkec-region-footer">
                        <div class="hupkec-footer-label">
                            Sectores personalizados
                            <span class="hupkec-footer-hint">(aparecen en la misma lista de comunas en el checkout)</span>
                        </div>
                        <div class="hupkec-sectors-wrapper" data-region="<?php echo esc_attr($code); ?>">
                            <?php foreach ($custom_sectors as $idx => $sector): ?>
                            <div class="hupkec-sector-item">
                                <span class="hupkec-sector-icon">⊙</span>
                                <input type="text"
                                       name="hupkec_settings[custom_sectors][<?php echo esc_attr($code); ?>][<?php echo esc_attr($idx); ?>][name]"
                                       value="<?php echo esc_attr($sector['name']); ?>"
                                       data-sector-name="<?php echo esc_attr($sector['name']); ?>"
                                       placeholder="Nombre del sector">
                                <button type="button" class="hupkec-remove-sector" title="Eliminar sector">✕</button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="hupkec-sector-add-row">
                            <input type="text" class="hupkec-new-sector hupkec-input" placeholder="Ej: Ciudad Satélite, Parque Industrial...">
                            <button type="button" class="hupkec-btn hupkec-btn-add-sector hupkec-add-sector" data-region="<?php echo esc_attr($code); ?>">
                                + Agregar sector
                            </button>
                        </div>
                        <?php if ( empty($custom_sectors) ): ?>
                        <p class="hupkec-sectors-empty-hint">Sin sectores personalizados. Puedes agregar zonas que no aparecen en la lista de comunas.</p>
                        <?php endif; ?>
                    </div>
                    <?php if ( $has_empty_warn ): ?>
                    <p class="hupkec-region-empty-warn">⚠ Región activa sin comunas seleccionadas — no aparecerá en el checkout hasta que actives al menos una comuna.</p>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="hupkec-form-submit-row">
                <button type="button" id="hupkec-save-comunas-btn" class="hupkec-btn-primary">Guardar cambios</button>
            </div>
        </form>
        <?php
    }

    /* ── ZONAS ─────────────────────────────────────────── */
    private function tab_zonas() {
        ?>
        <div class="hupkec-zone-system-notice">
            <div class="hupkec-zone-system-icon">⚙️</div>
            <div class="hupkec-zone-system-text">
                <strong>Este módulo crea automáticamente una zona de envío en WooCommerce → Ajustes → Envío.</strong>
                Esa zona es necesaria para que los costos de despacho funcionen correctamente en el checkout — no debe eliminarse.
                Si accidentalmente la borraste, usa el botón para recrearla.
            </div>
            <div class="hupkec-zone-actions-row">
                <button type="button" class="hupkec-btn hupkec-btn-recreate" id="hupkec-recreate-zone-btn">
                    🔄 Verificar / Recrear zona de envío
                </button>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=shipping' ) ); ?>" target="_blank" class="hupkec-btn hupkec-btn-link">
                    → Ver zonas de envío
                </a>
            </div>
        </div>
        <div id="hupkec-recreate-msg" class="hupkec-recreate-msg" style="display:none;"></div>

        <p class="hupkec-tab-desc">Agrupa comunas activas y asígnales un costo de envío.</p>

        <div id="hupkec-zones-list"></div>
        <button type="button" class="hupkec-btn-primary" id="hupkec-new-zone-btn">+ Nueva zona de despacho</button>

        <div id="hupkec-zone-form" class="hupkec-zone-form-wrap" style="display:none;">
            <div class="hupkec-zone-form-header">
                <h3 id="hupkec-zone-form-title">Nueva zona de despacho</h3>
                <button type="button" class="hupkec-btn" id="hupkec-cancel-zone">Cancelar</button>
            </div>
            <input type="hidden" id="hupkec-zone-id">
            <div class="hupkec-zone-form-grid">
                <div class="hupkec-zone-form-left">
                    <div class="hupkec-field-group"><label class="hupkec-field-label">Nombre *</label><input type="text" id="hz-name" class="hupkec-input" placeholder="Ej: Zona Express RM"></div>
                    <div class="hupkec-field-group"><label class="hupkec-field-label">Color</label><input type="color" id="hz-color" value="#378ADD" class="hupkec-color-input"></div>
                    <div class="hupkec-field-group">
                        <label class="hupkec-field-label">Costo de envío</label>
                        <select id="hz-cost-type" class="hupkec-input">
                            <?php foreach ( HUPKEC_Shipping::get_cost_types() as $ct_key => $ct_label ) : ?>
                            <option value="<?php echo esc_attr( $ct_key ); ?>"><?php echo esc_html( $ct_label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="hupkec-field-group" id="hz-cost-value-row" style="display:none;"><label class="hupkec-field-label">Monto ($)</label><input type="number" id="hz-cost-value" class="hupkec-input" min="0"></div>
                    <div class="hupkec-field-group" id="hz-free-from-row" style="display:none;"><label class="hupkec-field-label">Envío gratis desde ($) <small style="font-weight:400;color:#666">— opcional, 0 = sin umbral</small></label><input type="number" id="hz-free-from" class="hupkec-input" min="0" placeholder="Ej: 50000"></div>
                    <!-- Ancla de extensión: un addon inyecta aquí sus controles de
                         zona (fórmulas, costos por clase, información de entrega)
                         escuchando la acción JS 'hupkec.admin.ready'. Ver docs/hooks.md. -->
                    <div id="hupkec-zone-form-extra"></div>
                </div>
                <div class="hupkec-zone-form-right">
                    <div class="hupkec-field-group">
                        <label class="hupkec-field-label">Comunas incluidas en esta zona</label>
                        <div class="hupkec-zone-comunas-toolbar">
                            <input type="text" id="hz-comunas-search" class="hupkec-input hupkec-search-sm" placeholder="Buscar comuna...">
                            <button type="button" class="hupkec-btn hupkec-btn-sm" id="hz-sel-all">✓ Sel. todas</button>
                            <button type="button" class="hupkec-btn hupkec-btn-sm" id="hz-desel-all">✗ Desel. todas</button>
                            <button type="button" class="hupkec-btn hupkec-btn-sm hupkec-btn-invert" id="hz-invert-zone">↔ Invertir</button>
                        </div>
                        <div id="hz-comunas-selector" class="hupkec-comunas-zone-selector">
                            <!-- Generado dinámicamente por ZonasManager.buildComunasSelector() en hupkec-admin.js -->
                        </div>
                        <div class="hupkec-zone-selected-count"><span id="hz-selected-count">0</span> comunas seleccionadas</div>
                    </div>
                </div>
            </div>
            <div class="hupkec-zone-form-footer">
                <button type="button" class="hupkec-btn" id="hupkec-cancel-zone-2">Cancelar</button>
                <button type="button" class="hupkec-btn-primary" id="hupkec-save-zone">Guardar zona</button>
            </div>
        </div>
        <?php
    }

    /* ── CAMPOS ─────────────────────────────────────────── */
    private function tab_campos() {
        $field_types = hupkec_custom_field_types();
        ?>
        <p class="hupkec-tab-desc">Arrastra para reordenar, activa/desactiva y personaliza los campos. <span class="hupkec-badge-hup">HUP</span> son campos chilenos del plugin. El campo País es fijo.</p>
        <div class="hupkec-fields-subtabs">
            <button class="hupkec-fields-subtab active" data-section="billing">Campos de facturación</button>
            <button class="hupkec-fields-subtab" data-section="shipping">Campos de envío</button>
            <button class="hupkec-fields-subtab" data-section="additional">Campos adicionales</button>
        </div>
        <div id="hupkec-fields-editor"></div>
        <div class="hupkec-fields-actions">
            <button type="button" class="hupkec-btn" id="hupkec-reset-fields">↺ Restaurar por defecto</button>
            <button type="button" class="hupkec-btn" id="hupkec-add-field">+ Agregar campo personalizado</button>
            <button type="button" class="hupkec-btn-primary" id="hupkec-save-fields">Guardar cambios</button>
        </div>

        <!-- Módulo de facturación -->
        <div class="hupkec-card hupkec-billing-module-card" style="margin-top:24px">
            <div class="hupkec-billing-module-header">
                <div>
                    <h3 style="margin:0 0 4px">Módulo de Facturación</h3>
                    <p class="hupkec-tab-desc" style="margin:0">Al activarlo, aparece un checkbox <strong>"¿Necesitas factura?"</strong> en el checkout. Si el cliente lo marca, se despliegan los campos de empresa necesarios para emitir factura en Chile.</p>
                </div>
                <label class="hupkec-toggle" for="hupkec-billing-module-toggle">
                    <input type="checkbox" id="hupkec-billing-module-toggle" <?php checked( hupkec_billing_module_active() ); ?>>
                    <span class="hupkec-toggle-track"></span><span class="hupkec-toggle-thumb"></span>
                </label>
            </div>
            <div id="hupkec-billing-module-preview" class="hupkec-billing-preview <?php echo hupkec_billing_module_active() ? '' : 'hupkec-hidden'; ?>">
                <div class="hupkec-bp-label">Campos que se mostrarán al cliente cuando marque <strong>"¿Necesitas factura?"</strong>:</div>
                <div class="hupkec-bp-fields">
                    <span class="hupkec-bp-field">Nombre de la empresa</span>
                    <span class="hupkec-bp-field">RUT de la empresa <span class="hupkec-badge-hup">RUT</span></span>
                    <span class="hupkec-bp-field">Giro de la empresa</span>
                    <span class="hupkec-bp-field">Región de la empresa</span>
                    <span class="hupkec-bp-field">Comuna de la empresa</span>
                    <span class="hupkec-bp-field">Dirección de la empresa</span>
                </div>
                <p style="font-size:12px;color:#999;margin:8px 0 0">El cliente puede copiar su región, comuna y dirección principal con un clic.</p>
            </div>
        </div>

        <!-- Modal editar campo -->
        <div id="hupkec-field-modal" class="hupkec-modal" style="display:none;">
            <div class="hupkec-modal-box hupkec-modal-sm">
                <div class="hupkec-modal-header">
                    <h3 id="hupkec-field-modal-title">Editar campo</h3>
                    <button type="button" class="hupkec-modal-close" id="hupkec-close-field-modal">×</button>
                </div>
                <div class="hupkec-modal-body">
                    <input type="hidden" id="hf-section">
                    <input type="hidden" id="hf-index">
                    <div class="hupkec-field-group" id="hf-type-group">
                        <label class="hupkec-field-label">Tipo de campo</label>
                        <select id="hf-type" class="hupkec-input">
                            <?php foreach ($field_types as $val => $label): ?>
                            <option value="<?php echo esc_attr($val); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="hupkec-field-group">
                        <label class="hupkec-field-label">Etiqueta (label)</label>
                        <input type="text" id="hf-label" class="hupkec-input">
                    </div>
                    <div class="hupkec-field-group" id="hf-placeholder-group">
                        <label class="hupkec-field-label">Placeholder</label>
                        <input type="text" id="hf-placeholder" class="hupkec-input">
                    </div>
                    <div class="hupkec-field-group" id="hf-options-group" style="display:none;">
                        <label class="hupkec-field-label">Opciones (una por línea, formato: valor|Etiqueta)</label>
                        <textarea id="hf-options" class="hupkec-input" rows="5" placeholder="opcion1|Opción 1&#10;opcion2|Opción 2"></textarea>
                    </div>
                    <div class="hupkec-field-group" id="hf-default-group">
                        <label class="hupkec-field-label">Valor por defecto <small style="font-weight:400;color:#666">— opcional</small></label>
                        <input type="text" id="hf-default" class="hupkec-input">
                    </div>
                    <div class="hupkec-field-group">
                        <label class="hupkec-field-label">Clase CSS <small style="font-weight:400;color:#666">— opcional, para estilos del tema</small></label>
                        <input type="text" id="hf-cssclass" class="hupkec-input" placeholder="mi-clase otra-clase">
                    </div>
                    <div class="hupkec-field-group">
                        <label class="hupkec-field-label">Ancho</label>
                        <select id="hf-width" class="hupkec-input">
                            <option value="wide">100% — ancho completo</option>
                            <option value="first">50% — lado izquierdo</option>
                            <option value="last">50% — lado derecho</option>
                        </select>
                    </div>
                    <div class="hupkec-field-group">
                        <label><input type="checkbox" id="hf-required"> Campo obligatorio</label>
                    </div>
                    <div class="hupkec-field-group" id="hf-visibility-group">
                        <label class="hupkec-field-label">¿Dónde se muestra el dato?</label>
                        <label><input type="checkbox" id="hf-show-email" checked> Mostrar en los emails del pedido</label><br>
                        <label><input type="checkbox" id="hf-show-order" checked> Mostrar en la ficha del pedido y en la página de gracias</label>
                        <p class="description" style="margin:6px 0 0">Aplica solo a los campos personalizados. Útil para datos internos que no quieres que vea el cliente.</p>
                    </div>
                    <!-- Ancla de extensión: un addon inyecta aquí sus secciones
                         (lógica condicional, reglas de validación) escuchando la
                         acción JS 'hupkec.admin.ready'. Ver docs/hooks.md. -->
                    <div id="hupkec-field-modal-extra"></div>
                </div>
                <div class="hupkec-modal-footer">
                    <button type="button" class="hupkec-btn" id="hupkec-cancel-field">Cancelar</button>
                    <button type="button" class="hupkec-btn-primary" id="hupkec-save-field">Aplicar</button>
                </div>
            </div>
        </div>
        <?php
    }

}
