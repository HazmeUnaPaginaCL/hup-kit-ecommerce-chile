/**
 * HUP - Kit Ecommerce Chile — Checkout JS v2.8.1
 * Selector de regiones/comunas + RUT + Módulo de Facturación
 */
jQuery(function ($) {
    'use strict';

    var D          = window.hupkec_data || {};
    var ajaxUrl    = D.ajaxUrl       || '';
    var nonce      = D.nonce         || '';
    var selectCity = D.select_city   || 'Selecciona una comuna...';
    var selectReg  = D.select_region || 'Selecciona una región primero';
    var zoneCache  = {};
    var updateTimer = null;

    // Última comuna que se le comunicó a WooCommerce, por sección.
    // Es lo que corta el bucle: repoblar el select tras un updated_checkout
    // dispara un 'change' con el mismo valor, y ese no hay que reenviarlo.
    var lastSentCity = { billing: null, shipping: null };

    var KEY_B_CITY   = 'hupkec_billing_city';
    var KEY_B_REGION = 'hupkec_billing_region';
    var KEY_S_CITY   = 'hupkec_shipping_city';
    var KEY_S_REGION = 'hupkec_shipping_region';

    function store(k,v){try{sessionStorage.setItem(k,v||'');}catch(e){}}
    function read(k){try{return sessionStorage.getItem(k)||'';}catch(e){return '';}}
    function clear(k){try{sessionStorage.removeItem(k);}catch(e){}}
    function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
    function showCheckoutNotice(message, type) {
        var cls = type === 'error' ? 'woocommerce-error' : 'woocommerce-message';
        var $existing = $('.hupkec-checkout-notice');
        if ($existing.length) {
            $existing.remove();
        }
        var $target = $('.woocommerce-notices-wrapper').first();
        if (!$target.length) {
            $target = $('form.checkout').first();
        }
        if (!$target.length) return;
        $('<ul class="' + cls + ' hupkec-checkout-notice" role="alert"><li>' + esc(message) + '</li></ul>').prependTo($target);
    }

    /* ======================================================
       MÁSCARA Y VALIDACIÓN RUT
    ====================================================== */
    function formatRut(val) {
        val = val.replace(/[^0-9kK]/g, '');
        if (!val) return '';
        if (val.length === 1) return val;
        var dv   = val.slice(-1).toUpperCase();
        var body = val.slice(0, -1);
        body = body.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        return body + '-' + dv;
    }

    function validateRut(val) {
        val = val.replace(/[\.\-\s]/g, '').toLowerCase();
        if (val.length < 2) return false;
        var dv   = val.slice(-1);
        var body = val.slice(0, -1);
        if (!/^\d+$/.test(body)) return false;
        if (!/^[\dk]$/.test(dv))  return false;
        var sum = 0, factor = 2;
        for (var i = body.length - 1; i >= 0; i--) {
            sum    += parseInt(body[i]) * factor;
            factor  = (factor === 7) ? 2 : factor + 1;
        }
        var rem   = sum % 11;
        var check = (rem === 1) ? 'k' : (rem === 0) ? '0' : String(11 - rem);
        return dv === check;
    }

    function showRutError($input, msg) {
        $input.closest('.form-row').addClass('woocommerce-invalid');
        $input.siblings('.hupkec-rut-error').remove();
        $input.after('<span class="hupkec-rut-error">' + esc(msg) + '</span>');
    }
    function clearRutError($input) {
        $input.closest('.form-row').removeClass('woocommerce-invalid');
        $input.siblings('.hupkec-rut-error').remove();
    }

    /* RUT principal (billing/shipping) */
    $(document).on('input', '#billing_rut, #shipping_rut', function () {
        var pos       = this.selectionStart;
        var prev      = $(this).val();
        var formatted = formatRut($(this).val());
        $(this).val(formatted);
        var diff = formatted.length - prev.length;
        try { this.setSelectionRange(pos + diff, pos + diff); } catch(e){}
        clearRutError($(this));
    });

    $(document).on('blur', '#billing_rut, #shipping_rut', function () {
        var val = $(this).val();
        if (!val) return;
        if (!validateRut(val)) showRutError($(this), 'RUT inválido. Revisar el rut ingresado');
        else clearRutError($(this));
    });

    $(document).on('checkout_place_order', function () {
        var valid = true;
        $('#billing_rut, #shipping_rut').each(function () {
            if (!$(this).is(':visible') || !$(this).val()) return;
            if (!validateRut($(this).val())) {
                showRutError($(this), 'RUT inválido. Revisar el rut ingresado');
                valid = false;
            }
        });
        return valid;
    });

    /* ======================================================
       MÓDULO DE FACTURACIÓN
       Este bloque está en el nivel raíz del jQuery wrapper.
    ====================================================== */
    if (typeof window.hupkec_billing_data !== 'undefined') {
        var BD         = window.hupkec_billing_data;
        var bZoneCache = {};

        /**
         * Inicializa el estado visual del bloque de facturación.
         * Se llama en la carga inicial y tras cada updated_checkout.
         */
        function initBillingModule() {
            var $checkbox = $('#hupkec_needs_invoice');
            var $fields   = $('#hupkec-billing-fields');

            if (!$checkbox.length) return;

            if ($checkbox.is(':checked')) {
                $fields.show().addClass('hupkec-billing-open');
            } else {
                $fields.hide().removeClass('hupkec-billing-open');
            }

            // Pre-cargar comunas de empresa guardadas.
            // La región ya viene seleccionada desde PHP con selected(),
            // pero las comunas son dinámicas (AJAX). Hay que disparar
            // el change de la región para cargarlas y seleccionar la guardada.
            var $regionSel = $('#billing_region_empresa');
            if ($regionSel.length && $regionSel.val()) {
                // Región ya tiene valor (del PHP) → disparar AJAX de comunas
                // saved_comuna se consumirá dentro de fillEmpresaComunas
                $regionSel.trigger('change');
            } else if ($regionSel.length && BD.saved_region && !$regionSel.val()) {
                // Región sin valor → setear y disparar
                $regionSel.val(BD.saved_region).trigger('change');
            }
        }

        /* Toggle checkbox */
        $(document).on('change', '#hupkec_needs_invoice', function () {
            if ($(this).is(':checked')) {
                $('#hupkec-billing-fields').slideDown(300).addClass('hupkec-billing-open');
            } else {
                $('#hupkec-billing-fields').slideUp(300).removeClass('hupkec-billing-open');
            }
        });

        /* Re-init tras actualización AJAX de WooCommerce */
        $(document.body).on('updated_checkout', function () {
            // Pequeño delay para que WC termine de re-renderizar el DOM
            setTimeout(function () {
                initBillingModule();
                // Si el bloque de factura está abierto y la lista de comunas
                // quedó vacía (updated_checkout borra selects dinámicos),
                // recargarla
                if ($('#hupkec_needs_invoice').is(':checked')) {
                    var $comunaSel = $('#billing_comuna_empresa');
                    var $regionSel = $('#billing_region_empresa');
                    if ($regionSel.val() && $comunaSel.find('option').length <= 1) {
                        $regionSel.trigger('change');
                    }
                }
            }, 200);
        });

        /* Máscara RUT empresa */
        $(document).on('input', '.hupkec-rut-empresa', function () {
            var pos  = this.selectionStart;
            var prev = $(this).val();
            var fmt  = formatRut($(this).val());
            $(this).val(fmt);
            var diff = fmt.length - prev.length;
            try { this.setSelectionRange(pos + diff, pos + diff); } catch(e){}
            $('#hupkec-rut-empresa-error').hide();
            $(this).closest('.form-row').removeClass('woocommerce-invalid');
        });

        $(document).on('blur', '.hupkec-rut-empresa', function () {
            var val = $(this).val();
            if (!val) return;
            if (!validateRut(val)) {
                $(this).closest('.form-row').addClass('woocommerce-invalid');
                $('#hupkec-rut-empresa-error').text(BD.rut_error).show();
            } else {
                $(this).closest('.form-row').removeClass('woocommerce-invalid');
                $('#hupkec-rut-empresa-error').hide();
            }
        });

        /* Comunas empresa (AJAX) */
        $(document).on('change', '#billing_region_empresa', function () {
            var region = $(this).val();
            var $city  = $('#billing_comuna_empresa');

            $city.empty().prop('disabled', true);

            if (!region) {
                $city.append($('<option>').val('').text(BD.select_region));
                return;
            }

            if (bZoneCache[region]) {
                fillEmpresaComunas($city, bZoneCache[region]);
                if (BD.saved_comuna) { $city.val(BD.saved_comuna); BD.saved_comuna = ''; }
                return;
            }

            $.post(BD.ajaxUrl, {
                action: 'hupkec_get_comunas_empresa',
                nonce:  BD.nonce,
                region: region
            }, function(res) {
                if (res.success) {
                    bZoneCache[region] = res.data;
                    fillEmpresaComunas($city, res.data);
                    if (BD.saved_comuna) { $city.val(BD.saved_comuna); BD.saved_comuna = ''; }
                }
            });
        });

        function fillEmpresaComunas($sel, comunas) {
            $sel.empty();
            $sel.append($('<option>').val('').text(BD.select_comuna));
            $.each(comunas, function(_, c) { $sel.append($('<option>').val(c).text(c)); });
            $sel.prop('disabled', false);
        }

        /* Botón copiar datos del comprador */
        $(document).on('click', '#hupkec-copy-billing-data', function () {
            var region    = ($('#billing_state').val()    || '').toString();
            var comuna    = ($('#billing_city').val()     || '').toString();
            var direccion = ($('#billing_address_1').val() || '').toString();

            if (!region && !comuna && !direccion) {
                showCheckoutNotice('Completa primero los datos de dirección del comprador.', 'error');
                return;
            }

            if (direccion) $('#billing_direccion_empresa').val(direccion);

            if (region) {
                $('#billing_region_empresa').val(region).trigger('change');
                var attempts = 0;
                var waitForComunas = setInterval(function () {
                    var $opt = $('#billing_comuna_empresa option[value="' + comuna + '"]');
                    if ($opt.length) {
                        $('#billing_comuna_empresa').val(comuna);
                        clearInterval(waitForComunas);
                    } else if (++attempts > 40) {
                        clearInterval(waitForComunas);
                    }
                }, 100);
            }

            var $btn = $(this);
            $btn.text('✓ Copiado').addClass('hupkec-btn-copied');
            setTimeout(function () {
                $btn.text('📋 Copiar datos del comprador').removeClass('hupkec-btn-copied');
            }, 2000);
        });

        /* Validación al enviar pedido */
        $(document).on('checkout_place_order', function () {
            if (!$('#hupkec_needs_invoice').is(':checked')) return true;
            var valid = true;
            var rut = $('.hupkec-rut-empresa').val();
            if (rut && !validateRut(rut)) {
                $('.hupkec-rut-empresa').closest('.form-row').addClass('woocommerce-invalid');
                $('#hupkec-rut-empresa-error').text(BD.rut_error).show();
                valid = false;
            }
            return valid;
        });

        /* Init en carga de página */
        setTimeout(initBillingModule, 200);
    }

    /* ======================================================
       SELECTOR COMUNAS
    ====================================================== */
    function triggerSelect2($el) { $el.trigger('change').trigger('change.select2'); }

    function applyFromCache(stateSelector, citySelector, keyCity) {
        var $state = $(stateSelector);
        var $city  = $(citySelector);
        if (!$state.length || !$city.length) return;
        var region = ($state.val() || '').toString();
        if (!region || !zoneCache[region]) return;
        var preferred = ($city.val() || read(keyCity) || '').toString();
        fillComunas($city, zoneCache[region].comunas, preferred);
    }

    function fillComunas($city, comunas, preferred) {
        $city.empty();
        if (!comunas || !comunas.length) {
            $city.append($('<option>').val('').text(selectReg)).prop('disabled', true);
            triggerSelect2($city);
            return;
        }
        $city.append($('<option>').val('').text(selectCity));
        $.each(comunas, function (_, c) { $city.append($('<option>').val(c).text(c)); });
        $city.prop('disabled', false);

        if (preferred && comunas.indexOf(preferred) !== -1) {
            $city.val(preferred);
        } else {
            $city.val('');
        }
        triggerSelect2($city);
    }

    function loadComunas(stateSelector, citySelector, keyCity) {
        var $state = $(stateSelector);
        var $city  = $(citySelector);
        if (!$state.length || !$city.length) return;

        var region = ($state.val() || '').toString();
        if (!region) {
            $city.empty().append($('<option>').val('').text(selectReg)).prop('disabled', true);
            triggerSelect2($city);
            return;
        }

        if (zoneCache[region]) {
            var preferred = ($city.val() || read(keyCity) || '').toString();
            fillComunas($city, zoneCache[region].comunas, preferred);
            return;
        }

        $.post(ajaxUrl, { action:'hupkec_get_comunas', nonce:nonce, region:region })
            .done(function(res) {
                if (res.success) {
                    zoneCache[region] = res.data;
                    var preferred = ($city.val() || read(keyCity) || '').toString();
                    var $currentCity   = $(citySelector);
                    var currentRegion  = ($(stateSelector).val() || '').toString();
                    if (currentRegion === region && $currentCity.length) {
                        fillComunas($currentCity, res.data.comunas, preferred);
                    }
                }
            })
            .fail(function() {
                showCheckoutNotice('No fue posible cargar las comunas. Intenta nuevamente.', 'error');
            });
    }

    /* ======================================================
       EVENTOS COMUNAS
    ====================================================== */
    /**
     * Avisa a WooCommerce de que cambió la comuna, para que recalcule el envío.
     *
     * Solo se avisa cuando el valor difiere del último enviado. Esa comparación
     * hace dos cosas a la vez: corta el bucle 'updated_checkout' → repoblar el
     * select → 'change' → 'update_checkout' (el valor repuesto es el mismo, así
     * que no se reenvía), y deja pasar SIEMPRE un cambio real del cliente.
     *
     * Antes había en su lugar un cerrojo de 3 segundos. Cualquier cambio de
     * comuna dentro de esa ventana se descartaba entero: se guardaba el valor
     * pero no se recalculaba el envío, y el checkout se quedaba mostrando la
     * información de la comuna anterior. Se notaba sobre todo al pasar de una
     * comuna sin zona a una con zona, porque el aviso de "sin envío disponible"
     * permanecía en pantalla.
     *
     * @param {string} which 'billing' o 'shipping'.
     * @param {string} val   Comuna seleccionada.
     */
    function notifyCityChange(which, val) {
        if (val === lastSentCity[which]) return;
        lastSentCity[which] = val;

        // Sin comuna no hay nada que calcular; el valor queda registrado igual
        // para que al volver a elegirla sí se detecte como cambio.
        if (!val) return;

        clearTimeout(updateTimer);
        updateTimer = setTimeout(function () {
            $(document.body).trigger('update_checkout');
        }, 300);
    }

    $(document.body).on('change', '#billing_city', function () {
        var val = ($(this).val()||'').toString();
        store(KEY_B_CITY, val);
        notifyCityChange('billing', val);
    });

    $(document.body).on('change', '#shipping_city', function () {
        var val = ($(this).val()||'').toString();
        store(KEY_S_CITY, val);
        notifyCityChange('shipping', val);
    });

    $(document.body).on('change', '#billing_state', function () {
        store(KEY_B_REGION, ($(this).val()||'').toString());
        clear(KEY_B_CITY);
        loadComunas('#billing_state', '#billing_city', KEY_B_CITY);
    });

    $(document.body).on('change', '#shipping_state', function () {
        store(KEY_S_REGION, ($(this).val()||'').toString());
        clear(KEY_S_CITY);
        loadComunas('#shipping_state', '#shipping_city', KEY_S_CITY);
    });

    $(document.body).on('updated_checkout', function () {
        // Ya no hace falta ningún cerrojo: si applyFromCache repone el mismo
        // valor, notifyCityChange() lo reconoce como no-cambio y no reenvía.
        setTimeout(function () {
            applyFromCache('#billing_state',  '#billing_city',  KEY_B_CITY);
            applyFromCache('#shipping_state', '#shipping_city', KEY_S_CITY);
        }, 100);
    });

    $(document.body).on('change', '#ship-to-different-address-checkbox', function () {
        setTimeout(function () {
            loadComunas('#shipping_state', '#shipping_city', KEY_S_CITY);
        }, 250);
    });

    /* ======================================================
       REFRESH NONCE
    ====================================================== */
    function refreshNonce() {
        $.post(ajaxUrl, { action: 'hupkec_refresh_nonce' }, function(res) {
            if (res.success && res.data.nonce) nonce = res.data.nonce;
        });
    }
    setInterval(refreshNonce, 6 * 60 * 60 * 1000);

    /* Init comunas */
    setTimeout(function () {
        loadComunas('#billing_state',  '#billing_city',  KEY_B_CITY);
        loadComunas('#shipping_state', '#shipping_city', KEY_S_CITY);
    }, 200);
});
