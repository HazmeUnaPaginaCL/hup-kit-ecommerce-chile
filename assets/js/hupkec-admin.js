/**
 * HUP - Kit Ecommerce Chile — Admin JS 1.2.0
 *
 * Interfaz sobre el sistema de diseño HUP (hup-ui/). De hup-admin.js se usan
 * HUP.toast(), los modales ([data-hup-close]) y las filas repetibles
 * ([data-hup-add] / [data-hup-remove]); aquí va solo la lógica del plugin.
 *
 * Compatibilidad con el addon Pro: se conservan las clases y los IDs que el
 * Pro usa para engancharse (.hupkec-tab-btn, .hupkec-panel, .hupkec-module-*,
 * #hz-cost-type, #hf-type, #hf-options…) y las acciones/filtros de wp.hooks.
 */

(function($) {
    'use strict';

    // ═══════════════════════════════════════════════════════════
    // CAPA DE EXTENSIÓN (wp.hooks)
    // ═══════════════════════════════════════════════════════════
    /**
     * Envoltorio sobre wp.hooks.
     *
     * El panel construye el modal de campos y el editor de zonas desde JS. Sin
     * estos puntos de enganche, un addon podría añadir pestañas pero no un solo
     * control dentro de lo que ya existe.
     *
     * Se envuelve en vez de llamar a wp.hooks directamente por dos razones: si la
     * dependencia no cargara, el panel sigue funcionando en vez de morir con un
     * TypeError; y un filtro de terceros que devuelva basura no puede corromper
     * el objeto que el plugin estaba a punto de guardar.
     */
    const Hooks = {
        doAction: function() {
            if (!window.wp || !wp.hooks) return;
            wp.hooks.doAction.apply(null, arguments);
        },

        /**
         * Aplica un filtro exigiendo que el resultado siga siendo un objeto.
         * Si un addon devuelve null, un string o nada, se conserva el valor original.
         */
        applyObjectFilters: function(name, value) {
            if (!window.wp || !wp.hooks) return value;
            const result = wp.hooks.applyFilters.apply(null, arguments);
            if (!result || typeof result !== 'object' || Array.isArray(result)) return value;
            return result;
        },

        /**
         * Aplica un filtro que debe devolver un mensaje de error, o cadena vacía.
         * Sirve para que un addon bloquee el guardado con su propia validación.
         */
        applyErrorFilter: function() {
            if (!window.wp || !wp.hooks) return '';
            const result = wp.hooks.applyFilters.apply(null, arguments);
            return typeof result === 'string' ? result : '';
        }
    };

    // ═══════════════════════════════════════════════════════════
    // AVISOS (toasts de hup-ui)
    // ═══════════════════════════════════════════════════════════
    const HUPToast = {
        success: function(msg) { this.show(msg); },
        info:    function(msg) { this.show(msg); },
        error:   function(msg) { this.show(msg, 'error'); },
        show: function(msg, type) {
            if (window.HUP && typeof window.HUP.toast === 'function') {
                window.HUP.toast(String(msg), type);
            }
        }
    };

    // ═══════════════════════════════════════════════════════════
    // UTILIDADES
    // ═══════════════════════════════════════════════════════════
    const Util = {
        debounce: function(func, wait) {
            let timeout;
            return function(...args) {
                clearTimeout(timeout);
                timeout = setTimeout(() => func.apply(this, args), wait);
            };
        },

        /** Botón en proceso: deshabilitado y con spinner de hup-ui. */
        setLoading: function($btn, loading) {
            if (loading) {
                $btn.prop('disabled', true).attr('aria-busy', 'true');
                if (!$btn.find('.hup-spinner').length) $btn.prepend('<span class="hup-spinner" aria-hidden="true"></span>');
                $btn.find('.dashicons').attr('hidden', true);
            } else {
                $btn.prop('disabled', false).removeAttr('aria-busy');
                $btn.find('.hup-spinner').remove();
                $btn.find('.dashicons').removeAttr('hidden');
            }
        },

        /** Texto plano escapado para insertar en HTML. */
        esc: function(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        },

        /** Minúsculas sin tildes, para buscar "nunoa" y encontrar "Ñuñoa". */
        fold: function(s) {
            return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
        },

        /** Monto en formato chileno: 50000 → $50.000. */
        money: function(n) {
            const v = Math.round(parseFloat(n) || 0);
            return '$' + v.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        },

        post: function(data) {
            return $.post(hupkecAdmin.ajaxUrl, $.extend({ nonce: hupkecAdmin.nonce }, data));
        }
    };

    // ═══════════════════════════════════════════════════════════
    // NAVEGACIÓN ENTRE SECCIONES
    // ═══════════════════════════════════════════════════════════
    /**
     * Cada sección tiene su URL (?page=hupkec-settings&tab=zonas) y el servidor
     * abre la que corresponda. Además se cambia sin recargar: todos los paneles
     * están impresos y se alterna .is-active, el mismo mecanismo que usa el
     * addon Pro para abrir sus pestañas.
     */
    const Nav = {
        open: function(tab, push) {
            const $btn = $('.hupkec-tab-btn[data-tab="' + tab + '"]');
            const $panel = $('#tab-' + tab);
            if (!$btn.length || !$panel.length || $btn.hasClass('hupkec-hidden')) return false;

            $('.hupkec-tab-btn').removeClass('is-active').removeAttr('aria-current');
            $btn.addClass('is-active').attr('aria-current', 'page');
            $('.hupkec-panel').removeClass('is-active');
            $panel.addClass('is-active');

            if (push && window.history && window.history.pushState) {
                window.history.pushState({ hupkecTab: tab }, '', $btn.attr('href'));
            }
            return true;
        }
    };

    $(document).on('click', '.hupkec-tab-btn, .hupkec-goto', function(e) {
        // Ctrl/Cmd + clic: dejar que el navegador abra la pestaña nueva.
        if (e.ctrlKey || e.metaKey || e.shiftKey) return;
        if (Nav.open($(this).data('tab'), true)) e.preventDefault();
    });

    $(window).on('popstate', function(e) {
        const state = e.originalEvent && e.originalEvent.state;
        Nav.open(state && state.hupkecTab ? state.hupkecTab : 'inicio', false);
    });

    // ═══════════════════════════════════════════════════════════
    // MÓDULOS (INICIO)
    // ═══════════════════════════════════════════════════════════
    $(document).on('change', '.hupkec-module-checkbox', function() {
        const $input = $(this);
        const tab = $input.data('tab');
        const active = $input.is(':checked');

        // Se envían los interruptores que viven en hupkec_modules. El servidor
        // ignora las claves que no reconoce: los módulos de un addon con opción
        // propia los guarda el propio addon.
        const payload = { action: 'hupkec_save_modules' };
        $('.hupkec-module-checkbox').each(function() {
            payload[$(this).data('module')] = $(this).is(':checked') ? 1 : 0;
        });

        Util.post(payload).done(function(res) {
            if (!res || !res.success) {
                $input.prop('checked', !active);
                HUPToast.error('No se pudo actualizar el módulo');
                return;
            }
            HUPToast.success(active ? 'Módulo activado' : 'Módulo desactivado');

            if (!tab) return;
            const $tabBtn = $('.hupkec-tab-btn[data-tab="' + tab + '"]');
            $tabBtn.toggleClass('hupkec-hidden', !active);
            if (!active && $tabBtn.hasClass('is-active')) Nav.open('inicio', true);
        }).fail(function() {
            $input.prop('checked', !active);
            HUPToast.error('Error de conexión');
        });
    });

    // ═══════════════════════════════════════════════════════════
    // COMUNAS
    // ═══════════════════════════════════════════════════════════
    const ComunasManager = {
        onlyChecked: false,

        init: function() {
            if (!$('#hupkec-comunas-form').length) return;
            const self = this;

            $('#hupkec-comunas-search').on('input', Util.debounce(function() { self.filter(); }, 200));

            // Marcar/desmarcar la región entera desde su casilla.
            $(document).on('change', '.hupkec-region-checkbox', function() {
                const $box = $(this).closest('.hupkec-region-box');
                $box.find('.hupkec-comuna-checkbox').prop('checked', $(this).is(':checked'));
                self.sync($box);
            });

            $(document).on('change', '.hupkec-comuna-checkbox', function() {
                self.sync($(this).closest('.hupkec-region-box'));
            });

            // Acciones por región.
            $(document).on('click', '.hupkec-sel-all-region, .hupkec-desel-all-region, .hupkec-invert-region', function() {
                const $box = $(this).closest('.hupkec-region-box');
                const $inputs = $box.find('.hupkec-comuna-checkbox');
                if ($(this).hasClass('hupkec-invert-region')) {
                    $inputs.each(function() { this.checked = !this.checked; });
                } else {
                    $inputs.prop('checked', $(this).hasClass('hupkec-sel-all-region'));
                }
                self.sync($box);
            });

            // Acciones globales sobre lo visible (respetan la búsqueda).
            $('#hupkec-select-all-filtered, #hupkec-deselect-all-filtered, #hupkec-invert-all').on('click', function() {
                const id = this.id;
                $('.hupkec-comuna-item:visible .hupkec-comuna-checkbox').each(function() {
                    this.checked = id === 'hupkec-invert-all' ? !this.checked : id === 'hupkec-select-all-filtered';
                });
                $('.hupkec-region-box').each(function() { self.sync($(this)); });
            });

            $('#hupkec-view-selected-btn').on('click', function() {
                self.onlyChecked = !self.onlyChecked;
                $(this).attr('aria-pressed', self.onlyChecked ? 'true' : 'false')
                    .text(self.onlyChecked ? 'Ver todas' : 'Ver marcadas');
                self.filter();
            });

            // Un sector nuevo activa su región.
            $(document).on('input', '.hupkec-sector-input', function() {
                if ($(this).val().trim()) $(this).closest('.hupkec-region-box').find('.hupkec-region-checkbox').prop('checked', true);
            });

            $('#hupkec-comunas-form').on('submit', function(e) {
                e.preventDefault();
                self.save($('#hupkec-save-comunas-btn'));
            });
        },

        /** Contador, casilla de región y aviso de región vacía. */
        sync: function($box) {
            const $inputs = $box.find('.hupkec-comuna-checkbox');
            const checked = $inputs.filter(':checked').length;
            const sectors = $box.find('.hupkec-sector-input').filter(function() { return $(this).val().trim() !== ''; }).length;
            const $region = $box.find('.hupkec-region-checkbox');

            $box.find('.hupkec-region-count').text(checked + '/' + $inputs.length);
            if (checked > 0) $region.prop('checked', true);
            if (checked === 0 && sectors === 0) $region.prop('checked', false);

            const empty = $region.is(':checked') && checked === 0 && sectors === 0;
            $box.toggleClass('is-empty', empty);
            $box.find('.hupkec-region-warn').prop('hidden', !empty);
        },

        filter: function() {
            const query = Util.fold($('#hupkec-comunas-search').val().trim());
            const only = this.onlyChecked;

            $('.hupkec-region-box').each(function() {
                const $box = $(this);
                let visible = 0;
                $box.find('.hupkec-comuna-item').each(function() {
                    const $item = $(this);
                    const matchText = !query || Util.fold($item.text()).indexOf(query) !== -1;
                    const matchChk = !only || $item.find('input').is(':checked');
                    const show = matchText && matchChk;
                    $item.prop('hidden', !show);
                    if (show) visible++;
                });
                $box.prop('hidden', (query || only) && visible === 0);
            });
        },

        save: function($btn) {
            const data = {
                action: 'hupkec_save_comunas',
                enabled_regions: [],
                enabled_comunas: {},
                custom_sectors: {}
            };

            $('.hupkec-region-box').each(function() {
                const $box = $(this);
                const region = $box.data('region');
                const $comunas = $box.find('.hupkec-comuna-checkbox:checked');

                const sectors = [];
                $box.find('.hupkec-sector-input').each(function() {
                    const name = $(this).val().trim();
                    if (name) sectors.push({ name: name });
                });
                data.custom_sectors[region] = sectors;

                // Activar región si: casilla marcada, tiene comunas o tiene sectores.
                if ($box.find('.hupkec-region-checkbox').is(':checked') || $comunas.length || sectors.length) {
                    data.enabled_regions.push(region);
                    data.enabled_comunas[region] = $comunas.map(function() { return this.value; }).get();
                }
            });

            Util.setLoading($btn, true);
            Util.post(data).done(function(res) {
                if (res && res.success) {
                    HUPToast.success('Comunas guardadas');
                    // active_comunas incluye los sectores personalizados: así
                    // Despacho los ve sin recargar la página.
                    if (res.data && res.data.active_comunas) {
                        hupkecAdmin.activeComunas = res.data.active_comunas;
                        DiagTool.fillComunas();
                    }
                } else {
                    HUPToast.error('No se pudieron guardar las comunas');
                }
            }).fail(function() {
                HUPToast.error('Error de conexión');
            }).always(function() {
                Util.setLoading($btn, false);
            });
        }
    };

    // ═══════════════════════════════════════════════════════════
    // EDITOR DE CAMPOS (arrastre HTML5 nativo)
    // ═══════════════════════════════════════════════════════════
    const FieldsEditor = {
        currentSection: 'billing',
        draggedElement: null,

        init: function() {
            if (!$('#hupkec-fields-editor').length) return;
            this.setupSubtabs();
            this.render('billing');
            this.setupButtons();
            this.setupModal();
            this.setupBillingModule();
        },

        setupSubtabs: function() {
            const self = this;
            $('.hupkec-fields-subtab').on('click', function(e) {
                e.preventDefault();
                $('.hupkec-fields-subtab').removeClass('is-active').attr('aria-selected', 'false');
                $(this).addClass('is-active').attr('aria-selected', 'true');
                self.currentSection = $(this).data('section');
                self.render(self.currentSection);
            });
        },

        render: function(section) {
            const fields = hupkecAdmin.fields[section] || [];
            const $container = $('#hupkec-fields-editor').empty();

            fields.forEach((field, index) => {
                $container.append(this.createFieldItem(field, section, index));
            });

            this.setupDragAndDrop();
        },

        typeLabel: function(type) {
            const map = {
                text: 'Texto', number: 'Número', textarea: 'Texto largo',
                select: 'Lista', radio: 'Opción única', checkbox: 'Casilla',
                tel: 'Teléfono', email: 'Correo',
                country: 'País', state: 'Región', city: 'Comuna'
            };
            return map[type] || (hupkecAdmin.fieldTypes || {})[type] || type || 'Texto';
        },

        createFieldItem: function(field, section, index) {
            const esc = Util.esc;
            const locked = !!field.locked;
            const meta = [esc(this.typeLabel(field.type)), '<code class="hup-tag">' + esc(field.key) + '</code>'];
            if (field.required) meta.push('Obligatorio');
            if (locked) meta.push('Fijo');

            // Los campos RUT son los chilenos que trae el plugin; el resto de los
            // personalizados los creó el comerciante.
            let badge = '';
            if (/_rut$/.test(field.key || '')) badge = ' <span class="hup-badge hup-badge--version">Chile</span>';
            else if (field.custom) badge = ' <span class="hup-badge">Personalizado</span>';

            const $item = $(
                '<li class="hupkec-field-item' + (locked ? ' is-locked' : '') + (field.enabled ? '' : ' is-off') + '"' +
                    ' draggable="' + (!locked) + '" data-index="' + index + '">' +
                    '<span class="dashicons ' + (locked ? 'dashicons-lock' : 'dashicons-menu') + ' hup-handle" aria-hidden="true"></span>' +
                    '<div class="hup-row__text">' +
                        '<div class="hup-row__title">' + esc(field.label || field.key) + badge + '</div>' +
                        '<div class="hup-row__desc">' + meta.join(' · ') + '</div>' +
                    '</div>' +
                    '<label class="hup-toggle"><input type="checkbox" aria-label="Mostrar ' + esc(field.label || field.key) + '"' +
                        (field.enabled ? ' checked' : '') + (locked ? ' disabled' : '') + '><span class="hup-toggle__track"></span></label>' +
                    (!locked ? '<button type="button" class="hup-btn hup-btn--icon hupkec-btn-edit-field" aria-label="Editar ' + esc(field.label || field.key) + '"><span class="dashicons dashicons-edit" aria-hidden="true"></span></button>' : '') +
                    ((!locked && field.custom) ? '<button type="button" class="hup-btn hup-btn--icon hup-btn--danger-outline hupkec-btn-del-field" aria-label="Eliminar ' + esc(field.label || field.key) + '"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>' : '') +
                '</li>'
            );

            $item.find('.hup-toggle input').on('change', function() {
                hupkecAdmin.fields[section][index].enabled = $(this).is(':checked');
                $item.toggleClass('is-off', !$(this).is(':checked'));
            });
            $item.find('.hupkec-btn-edit-field').on('click', () => this.openModal(section, index));
            $item.find('.hupkec-btn-del-field').on('click', () => this.deleteField(section, index));

            return $item;
        },

        deleteField: function(section, index) {
            const field = hupkecAdmin.fields[section][index];
            if (!field) return;
            if (!confirm('¿Eliminar el campo «' + (field.label || field.key) + '»? Recuerda guardar los campos después.')) return;
            hupkecAdmin.fields[section].splice(index, 1);
            this.render(section);
            HUPToast.success('Campo eliminado. Recuerda guardar.');
        },

        setupDragAndDrop: function() {
            const self = this;
            document.querySelectorAll('#hupkec-fields-editor .hupkec-field-item[draggable="true"]').forEach(item => {
                item.addEventListener('dragstart', function(e) {
                    self.draggedElement = this;
                    this.classList.add('is-dragging');
                    e.dataTransfer.effectAllowed = 'move';
                });
                item.addEventListener('dragend', function() {
                    this.classList.remove('is-dragging');
                });
                item.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    if (!self.draggedElement || self.draggedElement === this) return;
                    const siblings = Array.from(this.parentNode.children);
                    if (siblings.indexOf(self.draggedElement) < siblings.indexOf(this)) {
                        this.parentNode.insertBefore(self.draggedElement, this.nextSibling);
                    } else {
                        this.parentNode.insertBefore(self.draggedElement, this);
                    }
                });
                item.addEventListener('drop', function(e) {
                    e.preventDefault();
                    self.updateFieldsOrder();
                });
            });
        },

        updateFieldsOrder: function() {
            const section = this.currentSection;
            const newOrder = [];
            $('#hupkec-fields-editor .hupkec-field-item').each(function() {
                newOrder.push(hupkecAdmin.fields[section][$(this).data('index')]);
            });
            hupkecAdmin.fields[section] = newOrder;
            this.render(section);
        },

        setupButtons: function() {
            const self = this;

            $('#hupkec-save-fields').on('click', function() {
                const $btn = $(this);
                Util.setLoading($btn, true);
                Util.post({ action: 'hupkec_save_fields', fields: JSON.stringify(hupkecAdmin.fields) })
                    .done(function(res) {
                        if (res && res.success) HUPToast.success('Campos guardados');
                        else HUPToast.error('No se pudieron guardar los campos');
                    })
                    .fail(function() { HUPToast.error('Error de conexión'); })
                    .always(function() { Util.setLoading($btn, false); });
            });

            $('#hupkec-reset-fields').on('click', function() {
                if (!confirm('¿Restaurar los campos originales? Se pierden el orden, los cambios y los campos personalizados.')) return;
                const $btn = $(this);
                Util.setLoading($btn, true);
                Util.post({ action: 'hupkec_reset_fields' })
                    .done(function(res) {
                        if (res && res.success && res.data) {
                            hupkecAdmin.fields = res.data;
                            self.render(self.currentSection);
                            HUPToast.success('Campos restaurados');
                        } else {
                            HUPToast.error('No se pudieron restaurar los campos');
                        }
                    })
                    .fail(function() { HUPToast.error('Error de conexión'); })
                    .always(function() { Util.setLoading($btn, false); });
            });

            $('#hupkec-add-field').on('click', function() {
                self.openModal(self.currentSection, -1);
            });
        },

        setupModal: function() {
            const self = this;

            $('#hupkec-save-field').on('click', function() { self.saveField(); });

            // Los grupos se muestran con .toggle() (style.display) y no con
            // [hidden]: el addon Pro los muestra igual al añadir sus tipos.
            $('#hf-type').on('change', function() {
                const type = $(this).val();
                $('#hf-options-group').toggle(type === 'select' || type === 'radio');
                $('#hf-placeholder-group').toggle(type !== 'radio' && type !== 'checkbox');
                $('#hf-default-group').toggle(type !== 'checkbox');
            });
        },

        openModal: function(section, index) {
            const isNew = index === -1;
            const field = isNew
                ? { type: 'text', label: '', placeholder: '', width: 'wide', required: false }
                : hupkecAdmin.fields[section][index];

            const optsText = Array.isArray(field.options)
                ? field.options.map(o => o.value + '|' + o.label).join('\n')
                : '';

            $('#hupkec-field-modal-title').text(isNew ? 'Agregar campo personalizado' : 'Editar campo');
            $('#hf-section').val(section);
            $('#hf-index').val(index);
            $('#hf-label').val(field.label);
            $('#hf-placeholder').val(field.placeholder || '');
            $('#hf-default').val(field.default || '');
            $('#hf-cssclass').val(field.cssclass || '');
            $('#hf-options').val(optsText);
            $('input[name="hf-width"]').filter('[value="' + (field.width || 'wide') + '"]').prop('checked', true);
            $('#hf-required').prop('checked', !!field.required);

            // Ausente = visible, igual que en PHP (hupkec_field_shows_in)
            $('#hf-show-email').prop('checked', field.show_in_email !== false);
            $('#hf-show-order').prop('checked', field.show_in_order !== false);
            // Solo los campos personalizados se muestran en correos y pedido
            $('#hf-visibility-group').toggle(isNew || !!field.custom);

            $('#hf-type-group').toggle(isNew);
            // Setear el tipo al final dispara el change que ajusta la visibilidad
            $('#hf-type').val(field.type).trigger('change');

            // El modal ya está poblado. Aquí un addon rellena sus propios controles
            // dentro de #hupkec-field-modal-extra.
            Hooks.doAction('hupkec.fieldModal.opened', field, isNew, section);

            $('#hupkec-field-modal').prop('hidden', false);
            $('#hf-label').trigger('focus');
        },

        saveField: function() {
            const section = $('#hf-section').val();
            const index = parseInt($('#hf-index').val(), 10);
            const isNew = index === -1;
            const existing = isNew ? null : hupkecAdmin.fields[section][index];
            const type = $('#hf-type').val();

            // Opciones "valor|Etiqueta" (una por línea) para select/radio
            const options = [];
            if (type === 'select' || type === 'radio') {
                $('#hf-options').val().split('\n').forEach(line => {
                    line = line.trim();
                    if (!line) return;
                    const parts = line.split('|');
                    const value = parts[0].trim();
                    const label = (parts[1] || parts[0]).trim();
                    if (value) options.push({ value: value, label: label });
                });
            }

            let field = {
                key: isNew ? 'custom_' + Date.now() : existing.key,
                type: type,
                label: $('#hf-label').val().trim(),
                placeholder: $('#hf-placeholder').val().trim(),
                default: $('#hf-default').val().trim(),
                cssclass: $('#hf-cssclass').val().trim(),
                options: options,
                width: $('input[name="hf-width"]:checked').val() || 'wide',
                required: $('#hf-required').is(':checked'),
                show_in_email: $('#hf-show-email').is(':checked'),
                show_in_order: $('#hf-show-order').is(':checked'),
                // Conservar estado original al editar; los nuevos son siempre custom/activos
                enabled: isNew ? true : existing.enabled,
                custom: isNew ? true : !!existing.custom,
                locked: isNew ? false : !!existing.locked
            };

            // Un addon añade aquí sus propiedades leyendo los controles que inyectó.
            // El esquema del campo debe aceptarlas también en PHP (hupkec_field_schema),
            // o se descartarían al guardar.
            field = Hooks.applyObjectFilters('hupkec.fieldModal.collect', field, isNew, section);

            if (!field.label) {
                HUPToast.error('El campo necesita una etiqueta');
                $('#hf-label').addClass('hup-input--error').trigger('focus');
                return;
            }
            $('#hf-label').removeClass('hup-input--error');
            if ((type === 'select' || type === 'radio') && options.length === 0) {
                HUPToast.error('Este tipo de campo necesita al menos una opción');
                return;
            }

            // Validación de terceros: devolver un mensaje no vacío cancela el guardado.
            const errorExterno = Hooks.applyErrorFilter('hupkec.fieldModal.validationError', '', field, isNew, section);
            if (errorExterno) {
                HUPToast.error(errorExterno);
                return;
            }

            if (isNew) {
                hupkecAdmin.fields[section].push(field);
            } else {
                hupkecAdmin.fields[section][index] = $.extend({}, existing, field);
            }

            this.render(section);
            $('#hupkec-field-modal').prop('hidden', true);
            HUPToast.success(isNew ? 'Campo agregado. Recuerda guardar.' : 'Campo actualizado. Recuerda guardar.');
        },

        setupBillingModule: function() {
            $('#hupkec-billing-module-toggle').on('change', function() {
                const $input = $(this);
                const active = $input.is(':checked');
                Util.post({ action: 'hupkec_save_billing_module', active: active ? 1 : 0 })
                    .done(function(res) {
                        if (res && res.success) {
                            $('#hupkec-billing-module-preview').prop('hidden', !active);
                            HUPToast.success('Facturación para empresas ' + (active ? 'activada' : 'desactivada'));
                        } else {
                            $input.prop('checked', !active);
                            HUPToast.error('No se pudo actualizar el módulo');
                        }
                    })
                    .fail(function() {
                        $input.prop('checked', !active);
                        HUPToast.error('Error de conexión');
                    });
            });
        }
    };

    // ═══════════════════════════════════════════════════════════
    // ZONAS DE DESPACHO
    // ═══════════════════════════════════════════════════════════
    const ZonasManager = {
        zones: [],
        currentZone: null,

        init: function() {
            if (!$('#hupkec-zones-list').length) return;
            this.zones = Array.isArray(hupkecAdmin.zones) ? hupkecAdmin.zones : [];
            this.render();
            this.setupButtons();
            this.setupForm();
        },

        refreshZones: function() {
            Util.post({ action: 'hupkec_get_zones' }).done((res) => {
                if (res && res.success && res.data) {
                    this.zones = res.data;
                    hupkecAdmin.zones = res.data;
                    this.render();
                }
            });
        },

        /** Regiones que toca una zona, a partir de las comunas activas. */
        regionsOf: function(zone) {
            const active = hupkecAdmin.activeComunas || {};
            const set = {};
            (zone.comunas || []).forEach(function(c) {
                Object.keys(active).forEach(function(code) {
                    if ((active[code] || []).indexOf(c) !== -1) set[code] = true;
                });
            });
            return Object.keys(set).length;
        },

        render: function() {
            const $list = $('#hupkec-zones-list').empty();
            const esc = Util.esc;

            if (!this.zones || this.zones.length === 0) {
                $list.html(
                    '<div class="hup-empty">' +
                        '<div class="hup-empty__icon"><span class="dashicons dashicons-car" aria-hidden="true"></span></div>' +
                        '<div class="hup-empty__title">Todavía no hay zonas de despacho</div>' +
                        '<p>Sin zonas, el checkout no ofrece envío. Crea la primera y asígnale comunas y un costo.</p>' +
                        '<button type="button" class="hup-btn hupkec-new-zone"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>Crear zona</button>' +
                    '</div>'
                );
                return;
            }

            let rows = '';
            this.zones.forEach((zone, index) => {
                const n = zone.comunas ? zone.comunas.length : 0;
                const r = this.regionsOf(zone);
                const color = /^#[0-9a-f]{3,8}$/i.test(zone.color || '') ? zone.color : '#378ADD';
                rows +=
                    '<tr>' +
                        '<td><span class="hup-inline"><span class="hup-dot" style="--hup-dot:' + color + '"></span><strong>' + esc(zone.name) + '</strong></span></td>' +
                        '<td>' + this.costBadge(zone) + '</td>' +
                        '<td>' + n + ' comuna' + (n === 1 ? '' : 's') + (r ? ' · ' + r + ' región' + (r === 1 ? '' : 'es') : '') + '</td>' +
                        '<td class="is-num"><span class="hup-inline">' +
                            '<button type="button" class="hup-btn hup-btn--icon hupkec-edit-zone" data-index="' + index + '" aria-label="Editar ' + esc(zone.name) + '"><span class="dashicons dashicons-edit" aria-hidden="true"></span></button>' +
                            '<button type="button" class="hup-btn hup-btn--icon hup-btn--danger-outline hupkec-delete-zone" data-index="' + index + '" aria-label="Eliminar ' + esc(zone.name) + '"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>' +
                        '</span></td>' +
                    '</tr>';
            });

            $list.html(
                '<table class="hup-table">' +
                    '<thead><tr><th scope="col">Zona</th><th scope="col">Costo</th><th scope="col">Cobertura</th><th scope="col" class="is-num"><span class="screen-reader-text">Acciones</span></th></tr></thead>' +
                    '<tbody>' + rows + '</tbody>' +
                '</table>'
            );
        },

        costBadge: function(zone) {
            const esc = Util.esc;
            switch (zone.cost_type) {
                case 'free':
                    return '<span class="hup-badge hup-badge--success">Gratis</span>';
                case 'fixed': {
                    let txt = '<span class="hup-badge">' + Util.money(zone.cost_value) + '</span>';
                    if (parseFloat(zone.cost_free_from) > 0) {
                        txt += ' <span class="hup-hint">gratis desde ' + Util.money(zone.cost_free_from) + '</span>';
                    }
                    return txt;
                }
                case 'cod':
                    return '<span class="hup-badge hup-badge--warning">Por pagar</span>';
                default: {
                    // Tipo registrado por un addon: rotular con su etiqueta del
                    // desplegable. El costo real lo calcula el addon con hupkec_zone_cost.
                    const types = hupkecAdmin.costTypes || {};
                    return '<span class="hup-badge">' + esc(types[zone.cost_type] || 'Sin configurar') + '</span>';
                }
            }
        },

        setupButtons: function() {
            const self = this;

            $(document).on('click', '#hupkec-new-zone-btn, .hupkec-new-zone', function() {
                self.openForm(null);
            });

            $('#hupkec-recreate-zone-btn').on('click', function() {
                const $btn = $(this);
                const $msg = $('#hupkec-recreate-msg');
                Util.setLoading($btn, true);
                Util.post({ action: 'hupkec_recreate_zone' }).done(function(res) {
                    const ok = res && res.success;
                    $msg.toggleClass('hup-notice--success', ok).toggleClass('hup-notice--error', !ok);
                    $msg.find('.dashicons').attr('class', 'dashicons ' + (ok ? 'dashicons-yes-alt' : 'dashicons-dismiss'));
                    $msg.find('p').text(ok ? ((res.data && res.data.msg) || 'Zona verificada.') : ((res && res.data) || 'No se pudo verificar la zona.'));
                    $msg.prop('hidden', false);
                    if (ok) {
                        $('#hupkec-wc-zone-badge').attr('class', 'hup-badge hup-badge--success hup-badge--dot').text('Activa');
                    }
                }).fail(function() {
                    HUPToast.error('Error de conexión');
                }).always(function() {
                    Util.setLoading($btn, false);
                });
            });

            $('#hupkec-zones-list').on('click', '.hupkec-edit-zone', function() {
                self.openForm($(this).data('index'));
            });

            $('#hupkec-zones-list').on('click', '.hupkec-delete-zone', function() {
                const zone = self.zones[$(this).data('index')];
                if (!zone || !confirm('¿Eliminar la zona «' + zone.name + '»? Sus comunas quedarán sin despacho.')) return;

                Util.post({ action: 'hupkec_delete_zone', zone_id: zone.id }).done(function(res) {
                    if (res && res.success) {
                        HUPToast.success('Zona eliminada');
                        self.refreshZones();
                    } else {
                        HUPToast.error('No se pudo eliminar la zona');
                    }
                }).fail(function() { HUPToast.error('Error de conexión'); });
            });
        },

        setupForm: function() {
            const self = this;

            $('#hupkec-cancel-zone').on('click', function() { self.currentZone = null; });

            // Filas de monto con .toggle() (style.display): el addon Pro las
            // muestra igual para sus propios tipos de costo.
            $('#hz-cost-type').on('change', function() {
                const type = $(this).val();
                $('#hz-cost-value-row').toggle(type === 'fixed');
                $('#hz-free-from-row').toggle(type === 'fixed');
            });

            $('#hupkec-save-zone').on('click', function() { self.saveZone(); });

            $('#hz-comunas-search').on('input', Util.debounce(function() {
                const query = Util.fold($(this).val().trim());
                $('#hz-comunas-selector .hz-region-group').each(function() {
                    let visible = 0;
                    $(this).find('.hz-comuna-chk').each(function() {
                        const show = !query || Util.fold($(this).text()).indexOf(query) !== -1;
                        $(this).prop('hidden', !show);
                        if (show) visible++;
                    });
                    $(this).prop('hidden', visible === 0);
                });
            }, 200));

            $(document).on('click', '.hz-sel-region, .hz-desel-region', function() {
                $(this).closest('.hz-region-group').find('.hz-comuna-input')
                    .prop('checked', $(this).hasClass('hz-sel-region'));
                self.updateSelectedCount();
            });

            $('#hz-sel-all, #hz-desel-all, #hz-invert-zone').on('click', function() {
                const id = this.id;
                $('#hz-comunas-selector .hz-comuna-chk:not([hidden]) .hz-comuna-input').each(function() {
                    this.checked = id === 'hz-invert-zone' ? !this.checked : id === 'hz-sel-all';
                });
                self.updateSelectedCount();
            });

            $(document).on('change', '.hz-comuna-input', function() { self.updateSelectedCount(); });
        },

        /**
         * Comunas que ya están en OTRA zona: una comuna solo puede pertenecer a
         * una, y si se repite gana la última guardada, así que se avisa.
         */
        takenBy: function() {
            const map = {};
            const current = this.currentZone ? this.currentZone.id : null;
            this.zones.forEach(function(z) {
                if (z.id === current) return;
                (z.comunas || []).forEach(function(c) { map[c] = z.name; });
            });
            return map;
        },

        buildComunasSelector: function() {
            const $selector = $('#hz-comunas-selector').empty();
            const active = hupkecAdmin.activeComunas || {};
            const regions = hupkecAdmin.regions || {};
            const taken = this.takenBy();
            const esc = Util.esc;
            let html = '';

            Object.keys(regions).forEach(function(code) {
                const list = active[code];
                if (!list || !list.length) return;
                html +=
                    '<div class="hup-checkgroup hz-region-group" data-region-code="' + esc(code) + '">' +
                        '<div class="hup-checkgroup__head">' + esc(regions[code]) +
                            '<div class="hup-checkgroup__actions">' +
                                '<button type="button" class="hup-btn hup-btn--ghost hup-btn--sm hz-sel-region">Todas</button>' +
                                '<button type="button" class="hup-btn hup-btn--ghost hup-btn--sm hz-desel-region">Ninguna</button>' +
                            '</div>' +
                        '</div>' +
                        '<div class="hup-checkgroup__body hup-checkgroup__body--scroll">' +
                            list.map(function(c) {
                                const other = taken[c] ? ' <span class="hup-hint">(en ' + esc(taken[c]) + ')</span>' : '';
                                return '<label class="hup-check hz-comuna-chk"><input type="checkbox" class="hz-comuna-input" value="' + esc(c) + '"> ' + esc(c) + other + '</label>';
                            }).join('') +
                        '</div>' +
                    '</div>';
            });

            if (!html) {
                html = '<div class="hup-notice hup-notice--warning"><span class="dashicons dashicons-warning" aria-hidden="true"></span><p>No hay comunas activas. Ve a <strong>Comunas</strong> y guarda tu selección.</p></div>';
            }
            $selector.html(html);
        },

        openForm: function(index) {
            const isNew = index === null;
            this.currentZone = isNew ? null : this.zones[index];

            this.buildComunasSelector();
            $('#hz-comunas-search').val('');

            $('#hz-name').val('').removeClass('hup-input--error');
            $('#hz-color').val('#378ADD');
            $('#hz-cost-type').val('free').trigger('change');
            $('#hz-cost-value').val('');
            $('#hz-free-from').val('');

            if (isNew) {
                $('#hupkec-zone-form-title').text('Nueva zona de despacho');
                $('#hupkec-zone-id').val('');
            } else {
                const zone = this.currentZone;
                $('#hupkec-zone-form-title').text('Editar zona de despacho');
                $('#hupkec-zone-id').val(zone.id || '');
                $('#hz-name').val(zone.name || '');
                $('#hz-color').val(zone.color || '#378ADD');
                $('#hz-cost-type').val(zone.cost_type || 'fixed').trigger('change');
                $('#hz-cost-value').val(zone.cost_value || '');
                $('#hz-free-from').val(zone.cost_free_from || '');

                // Por valor y no con un selector [value="…"]: una comuna con
                // comilla (O'Higgins) rompía el selector y quedaba sin marcar.
                const chosen = {};
                (zone.comunas || []).forEach(function(c) { chosen[c] = true; });
                $('.hz-comuna-input').each(function() { this.checked = !!chosen[this.value]; });
            }
            this.updateSelectedCount();

            // El formulario ya está poblado. Aquí un addon rellena sus controles
            // dentro de #hupkec-zone-form-extra. En zona nueva, currentZone es null.
            Hooks.doAction('hupkec.zoneForm.opened', this.currentZone, isNew);

            $('#hupkec-zone-modal').prop('hidden', false);
            $('#hz-name').trigger('focus');
        },

        saveZone: function() {
            const name = $('#hz-name').val().trim();
            if (!name) {
                $('#hz-name').addClass('hup-input--error').trigger('focus');
                HUPToast.error('La zona necesita un nombre');
                return;
            }
            $('#hz-name').removeClass('hup-input--error');

            const selectedComunas = $('.hz-comuna-input:checked').map(function() { return this.value; }).get();
            if (selectedComunas.length === 0) {
                HUPToast.error('Elige al menos una comuna');
                return;
            }

            let data = {
                action: 'hupkec_save_zone',
                nonce: hupkecAdmin.nonce,
                zone_id: $('#hupkec-zone-id').val(),
                name: name,
                color: $('#hz-color').val(),
                cost_type: $('#hz-cost-type').val(),
                cost_value: $('#hz-cost-value').val(),
                cost_free_from: $('#hz-free-from').val(),
                comunas: selectedComunas
            };

            // Un addon añade aquí sus datos de zona (fórmula, costos por clase,
            // información de entrega). En PHP los recoge con hupkec_zone_save_data.
            const isNew = !data.zone_id;
            data = Hooks.applyObjectFilters('hupkec.zoneForm.collect', data, isNew);

            // La acción y el nonce se reponen pase lo que pase: pisados por error
            // dejarían el guardado de zonas roto sin ningún mensaje útil.
            data.action = 'hupkec_save_zone';
            data.nonce  = hupkecAdmin.nonce;

            const errorExterno = Hooks.applyErrorFilter('hupkec.zoneForm.validationError', '', data, isNew);
            if (errorExterno) {
                HUPToast.error(errorExterno);
                return;
            }

            const $btn = $('#hupkec-save-zone');
            Util.setLoading($btn, true);
            $.post(hupkecAdmin.ajaxUrl, data).done((res) => {
                if (res && res.success) {
                    HUPToast.success('Zona guardada');
                    $('#hupkec-zone-modal').prop('hidden', true);
                    this.currentZone = null;
                    this.refreshZones();
                } else {
                    HUPToast.error((res && res.data) || 'No se pudo guardar la zona');
                }
            }).fail(() => {
                HUPToast.error('Error de conexión');
            }).always(() => {
                Util.setLoading($btn, false);
            });
        },

        updateSelectedCount: function() {
            $('#hz-selected-count').text($('.hz-comuna-input:checked').length);
        }
    };

    // ═══════════════════════════════════════════════════════════
    // PROBAR UNA COMUNA (diagnóstico de envío)
    // ═══════════════════════════════════════════════════════════
    const DiagTool = {
        init: function() {
            if (!$('#hupkec-test-comuna').length) return;
            this.fillComunas();
            $('#hupkec-test-btn').on('click', () => this.run($('#hupkec-test-btn')));
        },

        fillComunas: function() {
            const $sel = $('#hupkec-test-comuna');
            if (!$sel.length) return;
            const prev = $sel.val();
            const active = hupkecAdmin.activeComunas || {};
            const regions = hupkecAdmin.regions || {};
            $sel.empty().append($('<option>').val('').text('Elige una comuna…'));
            Object.keys(regions).forEach(function(code) {
                const list = active[code];
                if (!list || !list.length) return;
                const $group = $('<optgroup>').attr('label', regions[code]);
                list.forEach(function(c) { $group.append($('<option>').val(c).text(c)); });
                $sel.append($group);
            });
            if (prev) $sel.val(prev);
        },

        run: function($btn) {
            const comuna = $('#hupkec-test-comuna').val();
            if (!comuna) {
                HUPToast.error('Elige una comuna para probar');
                return;
            }
            const esc = Util.esc;
            const $out = $('#hupkec-test-result');
            Util.setLoading($btn, true);

            Util.post({ action: 'hupkec_test_comuna', comuna: comuna, subtotal: $('#hupkec-test-subtotal').val() || 0 })
                .done(function(res) {
                    if (!res || !res.success) {
                        HUPToast.error((res && res.data) || 'No se pudo probar la comuna');
                        return;
                    }
                    const checks = res.data.checks || [];
                    const allOk = checks.every(function(c) { return c.ok; });
                    let html = '';

                    if (res.data.result && allOk) {
                        html += '<div class="hup-notice hup-notice--success"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><p>' +
                            'El cliente de <strong>' + esc(comuna) + '</strong> verá «' + esc(res.data.result.zone) + '»: <strong>' + esc(res.data.result.cost) + '</strong>.</p></div>';
                    } else {
                        html += '<div class="hup-notice hup-notice--error"><span class="dashicons dashicons-dismiss" aria-hidden="true"></span><p>' +
                            'El cliente de <strong>' + esc(comuna) + '</strong> no verá opciones de envío. Revisa lo marcado en rojo.</p></div>';
                    }

                    html += '<ul class="hup-checklist">' + checks.map(function(c) {
                        return '<li class="' + (c.ok ? 'is-ok' : 'is-fail') + '"><span>' + esc(c.label) +
                            (c.detail ? '<small>' + esc(c.detail) + '</small>' : '') + '</span></li>';
                    }).join('') + '</ul>';

                    $out.html(html).prop('hidden', false);
                })
                .fail(function() { HUPToast.error('Error de conexión'); })
                .always(function() { Util.setLoading($btn, false); });
        }
    };

    // ═══════════════════════════════════════════════════════════
    // INICIALIZACIÓN
    // ═══════════════════════════════════════════════════════════
    $(function() {
        ComunasManager.init();
        FieldsEditor.init();
        ZonasManager.init();
        DiagTool.init();

        // Escape cierra el modal abierto (los de hup-ui cierran con clic fuera
        // o con [data-hup-close]).
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') $('.hupkec-panel .hup-modal:not([hidden])').prop('hidden', true);
        });

        // Expuesto para addons y depuración.
        window.HUPToast = HUPToast;

        // El panel está montado y sus manejadores registrados. Es el momento en
        // que un addon inyecta su HTML en las anclas #hupkec-field-modal-extra y
        // #hupkec-zone-form-extra, antes de que se abra ningún modal.
        Hooks.doAction('hupkec.admin.ready');
    });

})(jQuery);
