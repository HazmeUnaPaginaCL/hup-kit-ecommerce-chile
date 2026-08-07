/**
 * HUP - Kit Ecommerce Chile — Admin JS v1.1.0
 * Sistema oficial HUP (HazmeUnaPagina.cl)
 * - HTML5 Drag & Drop nativo
 * - Sistema de toasts HUP
 * - Estados loading en botones
 * - Debounce en búsquedas
 */

(function($) {
    'use strict';

    // ═══════════════════════════════════════════════════════════
    // CAPA DE EXTENSIÓN (wp.hooks)
    // ═══════════════════════════════════════════════════════════
    /**
     * Envoltorio sobre wp.hooks.
     *
     * El panel es una aplicación de jQuery que construye el modal de campos y el
     * editor de zonas desde JS. Sin estos puntos de enganche, un addon podría
     * añadir pestañas pero no un solo control dentro de lo que ya existe.
     *
     * Se envuelve en vez de llamar a wp.hooks directamente por dos razones: si la
     * dependencia no cargara, el panel sigue funcionando en vez de morir con un
     * TypeError; y un filtro de terceros que devuelva basura no puede corromper
     * el objeto que el plugin estaba a punto de guardar.
     */
    const Hooks = {
        doAction: function(name) {
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
        applyErrorFilter: function(name, value) {
            if (!window.wp || !wp.hooks) return '';
            const result = wp.hooks.applyFilters.apply(null, arguments);
            return typeof result === 'string' ? result : '';
        }
    };

    // ═══════════════════════════════════════════════════════════
    // SISTEMA DE TOASTS HUP
    // ═══════════════════════════════════════════════════════════
    const HUPToast = {
        show: function(message, type) {
            // Buscar toast existente
            let $toast = $('.hupkec-toast');
            
            if (!$toast.length) {
                // Crear toast si no existe
                $toast = $('<div class="hupkec-toast" role="status" aria-live="polite" aria-atomic="true"></div>').appendTo('body');
            }
            
            // Limpiar clases anteriores
            $toast.removeClass('hupkec-toast-show hupkec-toast-success hupkec-toast-error');
            
            // Aplicar tipo
            if (type === 'success') {
                $toast.addClass('hupkec-toast-success');
            } else if (type === 'error') {
                $toast.addClass('hupkec-toast-error');
            }
            
            // Mostrar mensaje
            $toast.text(message).addClass('hupkec-toast-show');
            
            // Auto-ocultar después de 3 segundos
            setTimeout(function() {
                $toast.removeClass('hupkec-toast-show');
            }, 3000);
        },
        
        success: function(msg) {
            this.show(msg, 'success');
        },
        
        error: function(msg) {
            this.show(msg, 'error');
        },

        info: function(msg) {
            this.show(msg, 'info');
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
        
        setLoading: function($btn, loading) {
            if (loading) {
                $btn.addClass('is-loading').prop('disabled', true);
            } else {
                $btn.removeClass('is-loading').prop('disabled', false);
            }
        }
    };

    // ═══════════════════════════════════════════════════════════
    // TABS NAVIGATION
    // ═══════════════════════════════════════════════════════════
    $('.hupkec-tab-btn').on('click', function() {
        const tab = $(this).data('tab');
        $('.hupkec-tab-btn').removeClass('is-active');
        $(this).addClass('is-active');
        $('.hupkec-panel').removeClass('is-active');
        $('#tab-' + tab).addClass('is-active');
    });

    // ═══════════════════════════════════════════════════════════
    // MÓDULOS (INICIO)
    // ═══════════════════════════════════════════════════════════
    $('.hupkec-module-checkbox').on('change', function() {
        const tab = $(this).data('tab');
        const active = $(this).is(':checked') ? 1 : 0;
        const modulesPayload = {
            comunas: $('#hupkec-mod-comunas').is(':checked') ? 1 : 0,
            shipping: $('#hupkec-mod-shipping').is(':checked') ? 1 : 0,
            checkout: $('#hupkec-mod-checkout').is(':checked') ? 1 : 0
        };

        $.post(hupkecAdmin.ajaxUrl, {
            action: 'hupkec_save_modules',
            nonce: hupkecAdmin.nonce,
            comunas: modulesPayload.comunas,
            shipping: modulesPayload.shipping,
            checkout: modulesPayload.checkout
        }, function(res) {
            if (res.success) {
                HUPToast.success('Módulo actualizado correctamente');
                
                // Mostrar/ocultar tab correspondiente
                const $tabBtn = $('.hupkec-tab-btn[data-tab="' + tab + '"]');
                if (active) {
                    $tabBtn.removeClass('hupkec-hidden');
                } else {
                    $tabBtn.addClass('hupkec-hidden');
                    // Si estamos en esa tab, volver a inicio
                    if ($tabBtn.hasClass('is-active')) {
                        $('.hupkec-tab-btn[data-tab="inicio"]').trigger('click');
                    }
                }
            } else {
                HUPToast.error('Error al actualizar el módulo');
            }
        }).fail(function() {
            HUPToast.error('Error de conexión');
        });
    });

    // ═══════════════════════════════════════════════════════════
    // COMUNAS - BÚSQUEDA GLOBAL
    // ═══════════════════════════════════════════════════════════
    const ComunasManager = {
        init: function() {
            this.setupSearch();
            this.setupRegionToggles();
            this.setupBulkActions();
            this.setupSaveButton();
            this.setupViewSelected();
            this.setupAddSector();
        },

        setupSearch: function() {
            const $search = $('#hupkec-comunas-search');
            if (!$search.length) return;

            const searchComunas = Util.debounce(function() {
                const query = $search.val().toLowerCase().trim();
                
                if (!query) {
                    $('.hupkec-comuna-item').show();
                    $('.hupkec-region-box').show();
                    return;
                }

                $('.hupkec-region-box').each(function() {
                    const $box = $(this);
                    let hasMatch = false;

                    $box.find('.hupkec-comuna-item').each(function() {
                        const $item = $(this);
                        const text = $item.text().toLowerCase();
                        
                        if (text.indexOf(query) !== -1) {
                            $item.show();
                            hasMatch = true;
                        } else {
                            $item.hide();
                        }
                    });

                    if (hasMatch) {
                        $box.show();
                    } else {
                        $box.hide();
                    }
                });
            }, 300);

            $search.on('input', searchComunas);
        },

        setupRegionToggles: function() {
            $('.hupkec-region-checkbox').on('change', function() {
                const $toggle = $(this);
                const $box = $toggle.closest('.hupkec-region-box');
                const active = $toggle.is(':checked');

                $box.find('.hupkec-comuna-item input').prop('checked', active);
            });

            // Sincronizar toggle de región con estado de comunas
            $(document).on('change', '.hupkec-comuna-item input', function() {
                const $box = $(this).closest('.hupkec-region-box');
                const total = $box.find('.hupkec-comuna-item input').length;
                const checked = $box.find('.hupkec-comuna-item input:checked').length;
                
                $box.find('.hupkec-region-checkbox').prop('checked', total > 0 && checked > 0);
            });
        },

        setupBulkActions: function() {
            $('#hupkec-select-all-filtered').on('click', function() {
                $('.hupkec-comuna-item:visible input').prop('checked', true);
                $('.hupkec-region-box').each(function() {
                    const $box = $(this);
                    const total = $box.find('.hupkec-comuna-item input').length;
                    const checked = $box.find('.hupkec-comuna-item input:checked').length;
                    $box.find('.hupkec-region-checkbox').prop('checked', total > 0 && checked > 0);
                });
            });

            $('#hupkec-deselect-all-filtered').on('click', function() {
                $('.hupkec-comuna-item:visible input').prop('checked', false);
                $('.hupkec-region-box').each(function() {
                    const $box = $(this);
                    const total = $box.find('.hupkec-comuna-item input').length;
                    const checked = $box.find('.hupkec-comuna-item input:checked').length;
                    $box.find('.hupkec-region-checkbox').prop('checked', total > 0 && checked > 0);
                });
            });

            $('#hupkec-invert-all').on('click', function() {
                $('.hupkec-comuna-item:visible input').each(function() {
                    $(this).prop('checked', !$(this).is(':checked'));
                });
                $('.hupkec-region-box').each(function() {
                    const $box = $(this);
                    const total = $box.find('.hupkec-comuna-item input').length;
                    const checked = $box.find('.hupkec-comuna-item input:checked').length;
                    $box.find('.hupkec-region-checkbox').prop('checked', total > 0 && checked > 0);
                });
            });

            // Botones por región individual
            $(document).on('click', '.hupkec-sel-all-region', function() {
                const region = $(this).data('region');
                const $box = $('.hupkec-region-box[data-region="' + region + '"]');
                $box.find('.hupkec-comuna-item input').prop('checked', true);
                $box.find('.hupkec-region-checkbox').prop('checked', true);
            });

            $(document).on('click', '.hupkec-desel-all-region', function() {
                const region = $(this).data('region');
                const $box = $('.hupkec-region-box[data-region="' + region + '"]');
                $box.find('.hupkec-comuna-item input').prop('checked', false);
                $box.find('.hupkec-region-checkbox').prop('checked', false);
            });

            $(document).on('click', '.hupkec-invert-region', function() {
                const region = $(this).data('region');
                const $box = $('.hupkec-region-box[data-region="' + region + '"]');
                $box.find('.hupkec-comuna-item input').each(function() {
                    $(this).prop('checked', !$(this).is(':checked'));
                });
                const total = $box.find('.hupkec-comuna-item input').length;
                const checked = $box.find('.hupkec-comuna-item input:checked').length;
                $box.find('.hupkec-region-checkbox').prop('checked', total > 0 && checked > 0);
            });
        },

        setupSaveButton: function() {
            $('#hupkec-save-comunas-btn').on('click', function() {
                const $btn = $(this);
                Util.setLoading($btn, true);

                const data = {
                    action: 'hupkec_save_comunas',
                    nonce: hupkecAdmin.nonce,
                    enabled_regions: [],
                    enabled_comunas: {},
                    custom_sectors: {}
                };

                $('.hupkec-region-box').each(function() {
                    const $box = $(this);
                    const region = $box.data('region');
                    const $comunas = $box.find('.hupkec-comuna-item input:checked');
                    const $regionToggle = $box.find('.hupkec-region-checkbox');
                    
                    // Contar sectores personalizados
                    const sectors = [];
                    $box.find('.hupkec-sectors-wrapper input[type="text"]').each(function() {
                        const name = $(this).val().trim();
                        if (name) {
                            sectors.push({ name: name });
                        }
                    });
                    data.custom_sectors[region] = sectors;

                    const toggleChecked = $regionToggle.is(':checked');
                    const hasComunas = $comunas.length > 0;
                    const hasSectors = sectors.length > 0;
                    const shouldEnable = toggleChecked || hasComunas || hasSectors;

                    // Activar región si: toggle checked, tiene comunas, o tiene sectores
                    if (shouldEnable) {
                        data.enabled_regions.push(region);
                        data.enabled_comunas[region] = [];
                        
                        $comunas.each(function() {
                            data.enabled_comunas[region].push($(this).val());
                        });
                    }
                });

                $.post(hupkecAdmin.ajaxUrl, data, function(res) {
                    Util.setLoading($btn, false);
                    if (res.success) {
                        HUPToast.success('Comunas guardadas correctamente');
                        // active_comunas incluye los sectores personalizados; usarlo
                        // asegura que la pestaña de Zonas los vea sin recargar la página
                        if (res.data && res.data.active_comunas) {
                            hupkecAdmin.activeComunas = res.data.active_comunas;
                        }
                    } else {
                        HUPToast.error('Error al guardar las comunas');
                    }
                }).fail(function() {
                    Util.setLoading($btn, false);
                    HUPToast.error('Error de conexión');
                });
            });
        },

        setupViewSelected: function() {
            $('#hupkec-view-selected-btn').on('click', function() {
                const $btn = $(this);
                const isFiltering = $btn.hasClass('active');
                
                if (isFiltering) {
                    // Mostrar todas
                    $('.hupkec-comuna-item').show();
                    $('.hupkec-region-box').show();
                    $btn.removeClass('active').text('👁 Ver seleccionadas');
                } else {
                    // Mostrar solo seleccionadas
                    $('.hupkec-comuna-item').each(function() {
                        const isChecked = $(this).find('input').is(':checked');
                        $(this).toggle(isChecked);
                    });
                    
                    // Ocultar regiones sin comunas seleccionadas
                    $('.hupkec-region-box').each(function() {
                        const hasSelected = $(this).find('.hupkec-comuna-item input:checked').length > 0;
                        $(this).toggle(hasSelected);
                    });
                    
                    $btn.addClass('active').text('👁 Ver todas');
                    HUPToast.info('Mostrando solo comunas seleccionadas');
                }
            });
        },

        setupAddSector: function() {
            $(document).on('click', '.hupkec-add-sector', function() {
                const $btn = $(this);
                const $input = $btn.closest('.hupkec-sector-add-row').find('.hupkec-new-sector');
                const name = $input.val().trim();
                
                if (!name) {
                    HUPToast.error('Ingresa un nombre para el sector');
                    return;
                }
                
                // Crear elemento de sector (usar .val() para evitar XSS)
                const $sectorInput = $('<input>').attr({type: 'text', readonly: true}).val(name);
                const $sectorBtn = $('<button>').attr({type: 'button', 'class': 'hupkec-remove-sector'}).text('×');
                const $sector = $('<div class="hupkec-sector-item"></div>').append($sectorInput).append($sectorBtn);
                
                // Agregar a la lista
                const $wrapper = $btn.closest('.hupkec-region-box').find('.hupkec-sectors-wrapper');
                $wrapper.append($sector);
                
                // Ocultar mensaje de "Sin sectores personalizados"
                $btn.closest('.hupkec-region-footer').find('.hupkec-sectors-empty-hint').hide();
                
                // Marcar el checkbox de la región automáticamente
                $btn.closest('.hupkec-region-box').find('.hupkec-region-checkbox').prop('checked', true);
                
                // Limpiar input
                $input.val('');
                HUPToast.success('Sector agregado');
            });
            
            // Eliminar sector
            $(document).on('click', '.hupkec-remove-sector', function() {
                const $sector = $(this).closest('.hupkec-sector-item');
                const $footer = $sector.closest('.hupkec-region-footer');
                $sector.remove();
                
                // Mostrar mensaje si no quedan sectores
                const remainingSectors = $footer.find('.hupkec-sector-item').length;
                if (remainingSectors === 0) {
                    $footer.find('.hupkec-sectors-empty-hint').show();
                }
            });
        }
    };

    // ═══════════════════════════════════════════════════════════
    // FIELDS EDITOR - HTML5 DRAG & DROP NATIVO
    // ═══════════════════════════════════════════════════════════
    const FieldsEditor = {
        currentSection: 'billing',
        draggedElement: null,
        
        init: function() {
            this.setupSubtabs();
            this.render('billing');
            this.setupButtons();
            this.setupModal();
            this.setupBillingModule();
        },

        setupSubtabs: function() {
            const self = this;
            $('.hupkec-fields-subtab').on('click', function() {
                const section = $(this).data('section');
                $('.hupkec-fields-subtab').removeClass('active');
                $(this).addClass('active');
                self.currentSection = section;
                self.render(section);
            });
        },

        render: function(section) {
            const fields = hupkecAdmin.fields[section] || [];
            const $container = $('#hupkec-fields-editor');
            $container.empty();

            fields.forEach((field, index) => {
                const $item = this.createFieldItem(field, section, index);
                $container.append($item);
            });

            this.setupDragAndDrop();
        },

        typeLabel: function(type) {
            const map = {
                text: 'Texto', number: 'Número', textarea: 'Texto largo',
                select: 'Lista', radio: 'Opción única', checkbox: 'Casilla',
                tel: 'Teléfono', email: 'Email',
                country: 'País', state: 'Región', city: 'Comuna'
            };
            return map[type] || type || 'Texto';
        },

        createFieldItem: function(field, section, index) {
            const badge = field.custom ? '<span class="hupkec-badge-hup">HUP</span>' : '';
            const locked = field.locked ? ' (bloqueado)' : '';
            const typeLabel = this.typeLabel(field.type);
            const reqMark = field.required ? '<span class="hupkec-field-req">obligatorio</span>' : '';

            const $item = $(`
                <div class="hupkec-field-item" draggable="${!field.locked}" data-section="${section}" data-index="${index}">
                    <span class="hupkec-field-drag">${field.locked ? '🔒' : '☰'}</span>
                    <div class="hupkec-field-info">
                        <div class="hupkec-field-name">${field.label} ${badge}${locked}</div>
                        <div class="hupkec-field-meta"><span class="hupkec-field-type">${typeLabel}</span> · ${field.key} ${reqMark}</div>
                    </div>
                    <label class="hupkec-toggle-sm">
                        <input type="checkbox" ${field.enabled ? 'checked' : ''} ${field.locked ? 'disabled' : ''}>
                        <span class="hupkec-ts-track"></span>
                        <span class="hupkec-ts-thumb"></span>
                    </label>
                    ${!field.locked ? '<button type="button" class="hupkec-btn-micro hupkec-btn-edit-field" title="Editar">✏️</button>' : ''}
                    ${(!field.locked && field.custom) ? '<button type="button" class="hupkec-btn-micro hupkec-btn-del-field" title="Eliminar">🗑️</button>' : ''}
                </div>
            `);

            // Toggle enabled
            $item.find('.hupkec-toggle-sm input').on('change', function() {
                hupkecAdmin.fields[section][index].enabled = $(this).is(':checked');
            });

            // Edit button
            $item.find('.hupkec-btn-edit-field').on('click', () => {
                this.openModal(section, index);
            });

            // Delete button (solo campos personalizados)
            $item.find('.hupkec-btn-del-field').on('click', () => {
                this.deleteField(section, index);
            });

            return $item;
        },

        deleteField: function(section, index) {
            const field = hupkecAdmin.fields[section][index];
            if (!field) return;
            if (!confirm('¿Eliminar el campo "' + (field.label || field.key) + '"? Recuerda Guardar los cambios después.')) return;
            hupkecAdmin.fields[section].splice(index, 1);
            this.render(section);
            HUPToast.success('Campo eliminado (recuerda Guardar)');
        },

        setupDragAndDrop: function() {
            const self = this;
            const items = document.querySelectorAll('.hupkec-field-item[draggable="true"]');

            items.forEach(item => {
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
                    
                    if (self.draggedElement !== this) {
                        const container = this.parentNode;
                        const draggingIndex = Array.from(container.children).indexOf(self.draggedElement);
                        const currentIndex = Array.from(container.children).indexOf(this);
                        
                        if (draggingIndex < currentIndex) {
                            this.parentNode.insertBefore(self.draggedElement, this.nextSibling);
                        } else {
                            this.parentNode.insertBefore(self.draggedElement, this);
                        }
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
                const index = $(this).data('index');
                newOrder.push(hupkecAdmin.fields[section][index]);
            });

            hupkecAdmin.fields[section] = newOrder;
            this.render(section);
        },

        setupButtons: function() {
            const self = this;

            $('#hupkec-save-fields').on('click', function() {
                const $btn = $(this);
                Util.setLoading($btn, true);

                $.post(hupkecAdmin.ajaxUrl, {
                    action: 'hupkec_save_fields',
                    nonce: hupkecAdmin.nonce,
                    fields: JSON.stringify(hupkecAdmin.fields)
                }, function(res) {
                    Util.setLoading($btn, false);
                    if (res.success) {
                        HUPToast.success('Campos guardados correctamente');
                    } else {
                        HUPToast.error('Error al guardar los campos');
                    }
                }).fail(function() {
                    Util.setLoading($btn, false);
                    HUPToast.error('Error de conexión');
                });
            });

            $('#hupkec-reset-fields').on('click', function() {
                if (!confirm('¿Estás seguro de restaurar los campos por defecto? Se perderán los cambios no guardados.')) return;

                const $btn = $(this);
                Util.setLoading($btn, true);

                $.post(hupkecAdmin.ajaxUrl, {
                    action: 'hupkec_reset_fields',
                    nonce: hupkecAdmin.nonce
                }, function(res) {
                    Util.setLoading($btn, false);
                    if (res.success && res.data) {
                        hupkecAdmin.fields = res.data;
                        self.render(self.currentSection);
                        HUPToast.success('Campos restaurados por defecto');
                    } else {
                        HUPToast.error('Error al restaurar los campos');
                    }
                }).fail(function() {
                    Util.setLoading($btn, false);
                    HUPToast.error('Error de conexión');
                });
            });

            $('#hupkec-add-field').on('click', function() {
                self.openModal(self.currentSection, -1);
            });
        },

        setupModal: function() {
            const self = this;

            $('#hupkec-close-field-modal, #hupkec-cancel-field').on('click', function() {
                $('#hupkec-field-modal').hide();
            });

            $('#hupkec-save-field').on('click', function() {
                self.saveField();
            });

            $('#hf-type').on('change', function() {
                const type = $(this).val();
                const hasOptions = (type === 'select' || type === 'radio');
                const noPlaceholder = (type === 'radio' || type === 'checkbox');
                $('#hf-options-group').toggle(hasOptions);
                $('#hf-placeholder-group').toggle(!noPlaceholder);
                $('#hf-default-group').toggle(type !== 'checkbox');
            });
        },

        openModal: function(section, index) {
            const isNew = index === -1;
            const field = isNew ? {
                type: 'text',
                label: '',
                placeholder: '',
                width: 'wide',
                required: false
            } : hupkecAdmin.fields[section][index];

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
            $('#hf-width').val(field.width);
            $('#hf-required').prop('checked', field.required);

            // Ausente = visible, igual que en PHP (hupkec_field_shows_in)
            $('#hf-show-email').prop('checked', field.show_in_email !== false);
            $('#hf-show-order').prop('checked', field.show_in_order !== false);
            // Solo los campos personalizados se renderizan en emails/pedido
            $('#hf-visibility-group').toggle(isNew || !!field.custom);

            $('#hf-type-group').toggle(isNew);
            // Setear el tipo al final dispara el change que ajusta la visibilidad
            $('#hf-type').val(field.type).trigger('change');

            // El modal ya está poblado con los datos del campo. Aquí un addon
            // rellena sus propios controles dentro de #hupkec-field-modal-extra.
            Hooks.doAction('hupkec.fieldModal.opened', field, isNew, section);

            $('#hupkec-field-modal').show();
        },

        saveField: function() {
            const section = $('#hf-section').val();
            const index = parseInt($('#hf-index').val());
            const isNew = index === -1;
            const existing = isNew ? null : hupkecAdmin.fields[section][index];
            const type = $('#hf-type').val();

            // Parsear opciones "valor|Etiqueta" (una por línea) para select/radio
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
                width: $('#hf-width').val(),
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
                return;
            }
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
                hupkecAdmin.fields[section][index] = { ...existing, ...field };
            }

            this.render(section);
            $('#hupkec-field-modal').hide();
            HUPToast.success(isNew ? 'Campo agregado' : 'Campo actualizado');
        },

        setupBillingModule: function() {
            $('#hupkec-billing-module-toggle').on('change', function() {
                const active = $(this).is(':checked');

                $.post(hupkecAdmin.ajaxUrl, {
                    action: 'hupkec_save_billing_module',
                    nonce: hupkecAdmin.nonce,
                    active: active ? 1 : 0
                }, function(res) {
                    if (res.success) {
                        $('#hupkec-billing-module-preview').toggleClass('hupkec-hidden', !active);
                        HUPToast.success('Módulo de facturación ' + (active ? 'activado' : 'desactivado'));
                    } else {
                        HUPToast.error('Error al actualizar el módulo');
                    }
                }).fail(function() {
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
            this.zones = Array.isArray(hupkecAdmin.zones) ? hupkecAdmin.zones : [];
            this.render();
            this.setupButtons();
            this.setupForm();
        },
        
        refreshZones: function() {
            const self = this;
            $.post(hupkecAdmin.ajaxUrl, {
                action: 'hupkec_get_zones',
                nonce: hupkecAdmin.nonce
            }, function(res) {
                if (res.success && res.data) {
                    self.zones = res.data;
                    self.render();
                }
            });
        },
        
        render: function() {
            const $list = $('#hupkec-zones-list');
            $list.empty();
            
            if (!this.zones || this.zones.length === 0) {
                $list.html('<div class="hupkec-empty-state">No hay zonas de despacho creadas. Crea tu primera zona para empezar.</div>');
                return;
            }
            
            this.zones.forEach((zone, index) => {
                const $card = this.createZoneCard(zone, index);
                $list.append($card);
            });
        },
        
        createZoneCard: function(zone, index) {
            const comunasText = zone.comunas && zone.comunas.length > 0 
                ? zone.comunas.join(', ') 
                : 'Sin comunas';
            
            const costText = this.getCostText(zone);

            const $card = $('<div class="hupkec-zone-card"></div>');
            $card.css('border-left', '4px solid ' + (zone.color || '#378ADD'));
            const $header = $('<div class="hupkec-zone-card-header"></div>').appendTo($card);
            $('<h4></h4>').text(zone.name || '').appendTo($header);
            const $actions = $('<div class="hupkec-zone-card-actions"></div>').appendTo($header);
            $('<button class="hupkec-btn-micro hupkec-edit-zone" type="button">✏️ Editar</button>').attr('data-index', index).appendTo($actions);
            $('<button class="hupkec-btn-micro hupkec-delete-zone" type="button">🗑️ Eliminar</button>').attr('data-index', index).appendTo($actions);
            const $body = $('<div class="hupkec-zone-card-body"></div>').appendTo($card);
            $('<div></div>').append($('<strong>Costo:</strong>')).append(document.createTextNode(' ' + costText)).appendTo($body);
            $('<div></div>')
                .append($('<strong></strong>').text('Comunas (' + (zone.comunas ? zone.comunas.length : 0) + '):'))
                .append(document.createTextNode(' ' + comunasText))
                .appendTo($body);
            return $card;
        },
        
        getCostText: function(zone) {
            if (!zone.cost_type) return 'No configurado';

            switch(zone.cost_type) {
                case 'free': return 'Gratis';
                case 'fixed':
                    var txt = '$' + (zone.cost_value || 0);
                    if (zone.cost_free_from && parseFloat(zone.cost_free_from) > 0) {
                        txt += ' (gratis desde $' + zone.cost_free_from + ')';
                    }
                    return txt;
                case 'cod': return 'Por pagar (contra reembolso)';
                default:
                    // Tipo registrado por un addon: rotular con su etiqueta del
                    // desplegable en vez de "No configurado". El costo real lo
                    // calcula el addon en el checkout con hupkec_zone_cost.
                    var costTypes = (typeof hupkecAdmin !== 'undefined' && hupkecAdmin.costTypes) || {};
                    return costTypes[zone.cost_type] || 'No configurado';
            }
        },
        
        setupButtons: function() {
            const self = this;
            
            // Botón nueva zona
            $('#hupkec-new-zone-btn').on('click', function() {
                self.openForm(null);
            });
            
            // Botón recrear zona
            $('#hupkec-recreate-zone-btn').on('click', function() {
                const $btn = $(this);
                Util.setLoading($btn, true);
                
                $.post(hupkecAdmin.ajaxUrl, {
                    action: 'hupkec_recreate_zone',
                    nonce: hupkecAdmin.nonce
                }, function(res) {
                    Util.setLoading($btn, false);
                    const $msg = $('#hupkec-recreate-msg');
                    
                    if (res.success) {
                        $msg.removeClass('hupkec-msg-error').addClass('hupkec-msg-ok');
                        $msg.text((res.data && res.data.msg) ? res.data.msg : 'Zona verificada correctamente');
                    } else {
                        $msg.removeClass('hupkec-msg-ok').addClass('hupkec-msg-error');
                        $msg.text(res.data || 'Error al verificar la zona');
                    }
                    
                    $msg.fadeIn();
                    setTimeout(() => $msg.fadeOut(), 5000);
                });
            });
            
            // Editar zona (delegado)
            $('#hupkec-zones-list').on('click', '.hupkec-edit-zone', function() {
                const index = $(this).data('index');
                self.openForm(index);
            });
            
            // Eliminar zona (delegado)
            $('#hupkec-zones-list').on('click', '.hupkec-delete-zone', function() {
                if (!confirm('¿Eliminar esta zona de despacho?')) return;
                
                const index = $(this).data('index');
                const zone = self.zones[index];
                
                $.post(hupkecAdmin.ajaxUrl, {
                    action: 'hupkec_delete_zone',
                    nonce: hupkecAdmin.nonce,
                    zone_id: zone.id
                }, function(res) {
                    if (res.success) {
                        HUPToast.success('Zona eliminada');
                        self.refreshZones();
                    } else {
                        HUPToast.error('Error al eliminar la zona');
                    }
                });
            });
        },
        
        setupForm: function() {
            const self = this;
            
            // Cancelar
            $('#hupkec-cancel-zone, #hupkec-cancel-zone-2').on('click', function() {
                $('#hupkec-zone-form').hide();
                self.currentZone = null;
            });
            
            // Cambio de tipo de costo
            $('#hz-cost-type').on('change', function() {
                const type = $(this).val();
                $('#hz-cost-value-row').toggle(type === 'fixed');
                $('#hz-free-from-row').toggle(type === 'fixed');
            });
            
            // Guardar zona
            $('#hupkec-save-zone').on('click', function() {
                self.saveZone();
            });
            
            // Búsqueda de comunas en formulario
            $('#hz-comunas-search').on('input', Util.debounce(function() {
                const query = $(this).val().toLowerCase();
                $('.hz-comuna-chk').each(function() {
                    const text = $(this).text().toLowerCase();
                    $(this).toggle(text.indexOf(query) !== -1);
                });
            }, 200));
            
            // Seleccionar/deseleccionar por región
            $(document).on('click', '.hz-sel-region', function() {
                const region = $(this).data('region');
                $('.hz-region-group[data-region-code="' + region + '"] .hz-comuna-input').prop('checked', true).trigger('change');
            });
            $(document).on('click', '.hz-desel-region', function() {
                const region = $(this).data('region');
                $('.hz-region-group[data-region-code="' + region + '"] .hz-comuna-input').prop('checked', false).trigger('change');
            });

            // Invertir selección en zona
            $(document).on('click', '#hz-invert-zone', function() {
                $('.hz-comuna-input').each(function() {
                    $(this).prop('checked', !$(this).is(':checked')).trigger('change');
                });
            });

            // Seleccionar todas las comunas
            $(document).on('click', '#hz-sel-all', function() {
                $('.hz-comuna-input').prop('checked', true).trigger('change');
            });

            // Deseleccionar todas las comunas
            $(document).on('click', '#hz-desel-all', function() {
                $('.hz-comuna-input').prop('checked', false).trigger('change');
            });
        },
        
        buildComunasSelector: function() {
            const $selector = $('#hz-comunas-selector');
            $selector.empty();

            const activeComunas = hupkecAdmin.activeComunas || {};
            const regions = hupkecAdmin.regions || {};

            let hasAny = false;
            Object.keys(regions).forEach(function(code) {
                const rc = activeComunas[code];
                if (!rc || !rc.length) return;
                hasAny = true;

                const $group = $('<div class="hz-region-group"></div>').attr('data-region-code', code);
                const $label = $('<div class="hz-region-label"></div>').text(regions[code]);
                const $actions = $('<div class="hz-region-actions"></div>');
                $('<button type="button" class="hupkec-btn-micro hz-sel-region">Sel. todas</button>').attr('data-region', code).appendTo($actions);
                $('<button type="button" class="hupkec-btn-micro hz-desel-region">Desel. todas</button>').attr('data-region', code).appendTo($actions);
                $label.append($actions);
                $group.append($label);

                const $comunasDiv = $('<div class="hz-region-comunas"></div>');
                rc.forEach(function(c) {
                    const $chk = $('<label class="hz-comuna-chk"></label>').attr('data-comuna', c.toLowerCase());
                    $('<input type="checkbox" class="hz-comuna-input">').attr('value', c).appendTo($chk);
                    $chk.append(document.createTextNode(c));
                    $comunasDiv.append($chk);
                });
                $group.append($comunasDiv);
                $selector.append($group);
            });

            if (!hasAny) {
                $selector.html('<p style="color:#666;font-size:12px;padding:8px 0">No hay comunas activas. Ve a la pestaña <strong>Comunas</strong> y guarda tu selección.</p>');
            }
        },

        openForm: function(index) {
            const isNew = index === null;
            this.currentZone = isNew ? null : this.zones[index];

            // Regenerar selector de comunas con datos actuales en memoria
            this.buildComunasSelector();
            
            // Limpiar campos del formulario (no es un <form>, es un div)
            $('#hz-name').val('');
            $('#hz-color').val('#378ADD');
            $('#hz-cost-type').val('free').trigger('change');
            $('#hz-cost-value').val('');
            $('#hz-free-from').val('');
            $('#hz-cost-value-row, #hz-free-from-row').hide();
            $('.hz-comuna-input').prop('checked', false);
            
            if (isNew) {
                $('#hupkec-zone-form-title').text('Nueva zona de despacho');
                $('#hupkec-zone-id').val('');
            } else {
                $('#hupkec-zone-form-title').text('Editar zona de despacho');
                const zone = this.currentZone;
                
                $('#hupkec-zone-id').val(zone.id || '');
                $('#hz-name').val(zone.name || '');
                $('#hz-color').val(zone.color || '#378ADD');
                $('#hz-cost-type').val(zone.cost_type || 'fixed').trigger('change');
                $('#hz-cost-value').val(zone.cost_value || '');
                $('#hz-free-from').val(zone.cost_free_from || '');

                // Marcar comunas seleccionadas
                if (zone.comunas && zone.comunas.length > 0) {
                    zone.comunas.forEach(comuna => {
                        $(`.hz-comuna-input[value="${comuna}"]`).prop('checked', true);
                    });
                }
                
                this.updateSelectedCount();
            }

            // El formulario ya está poblado. Aquí un addon rellena sus controles
            // dentro de #hupkec-zone-form-extra. En zona nueva, currentZone es null.
            Hooks.doAction('hupkec.zoneForm.opened', this.currentZone, isNew);

            $('#hupkec-zone-form').fadeIn();
        },
        
        saveZone: function() {
            const name = $('#hz-name').val().trim();
            
            if (!name) {
                HUPToast.error('El nombre de la zona es obligatorio');
                return;
            }
            
            const selectedComunas = [];
            $('.hz-comuna-input:checked').each(function() {
                selectedComunas.push($(this).val());
            });
            
            if (selectedComunas.length === 0) {
                HUPToast.error('Debes seleccionar al menos una comuna');
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
            
            $.post(hupkecAdmin.ajaxUrl, data, (res) => {
                Util.setLoading($btn, false);
                if (res.success) {
                    HUPToast.success('Zona guardada correctamente');
                    $('#hupkec-zone-form').hide();
                    this.refreshZones();
                } else {
                    HUPToast.error(res.data || 'Error al guardar la zona');
                }
            }).fail(() => {
                Util.setLoading($btn, false);
                HUPToast.error('Error de conexión');
            });
        },
        
        updateSelectedCount: function() {
            const count = $('.hz-comuna-input:checked').length;
            $('#hz-selected-count').text(count);
        }
    };

    // Actualizar contador al seleccionar comunas
    $(document).on('change', '.hz-comuna-input', function() {
        ZonasManager.updateSelectedCount();
    });

    // ═══════════════════════════════════════════════════════════
    // INICIALIZACIÓN
    // ═══════════════════════════════════════════════════════════
    $(document).ready(function() {
        ComunasManager.init();
        FieldsEditor.init();
        ZonasManager.init();

        // Exponer para debugging
        window.HUPToast = HUPToast;

        // El panel está montado y sus manejadores registrados. Es el momento en
        // que un addon inyecta su HTML en las anclas #hupkec-field-modal-extra y
        // #hupkec-zone-form-extra, antes de que se abra ningún modal.
        Hooks.doAction('hupkec.admin.ready');
    });

})(jQuery);
