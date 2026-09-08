/**
 * PARSI Checkout / Cart City Dropdown
 *
 * WooCommerce already renders Iran's provinces in its native State field, so we
 * progressively enhance the existing City field into a dropdown driven by the
 * selected State — on both the classic checkout (#billing_state -> #billing_city)
 * and the cart-page shipping calculator (#calc_shipping_state -> #calc_shipping_city).
 *
 * The chosen city NAME is written into the native city input (so the order keeps
 * a readable city) and the city UUID into a hidden field (billing_city_id /
 * calc_shipping_city_id) that the server threads into the shipping calculation.
 * If a province can't be matched to the dataset, the native text input is left
 * in place and the name is resolved server-side as a fallback.
 *
 * @package PARSI
 */
(function ($) {
    'use strict';

    if (typeof parsiCheckoutLocations === 'undefined' || !parsiCheckoutLocations.provinces) {
        return;
    }

    var i18n = parsiCheckoutLocations.i18n || { chooseCity: 'انتخاب شهر...' };

    // Build a normalized-province-name -> cities lookup once.
    var provByNorm = {};
    parsiCheckoutLocations.provinces.forEach(function (p) {
        provByNorm[p.norm] = p.cities || [];
    });

    // Mirror PARSI_Locations::normalize_name() so JS and PHP agree.
    function norm(s) {
        return (s == null ? '' : String(s))
            .replace(/[يى]/g, 'ی')
            .replace(/ك/g, 'ک')
            .replace(/‌/g, ' ')
            .replace(/\s+/g, ' ')
            .trim()
            .toLowerCase();
    }

    var checkoutCtx = {
        state: '#billing_state',
        city: '#billing_city',
        nativeWrap: '#billing_city_field',
        selectId: 'parsi_billing_city_select',
        hiddenSel: '#billing_city_id',
        injectHidden: false,
        triggerUpdate: true
    };

    var cartCtx = {
        state: '#calc_shipping_state',
        city: '#calc_shipping_city',
        nativeWrap: '#calc_shipping_city_field',
        selectId: 'parsi_calc_city_select',
        hiddenSel: '#calc_shipping_city_id',
        hiddenName: 'calc_shipping_city_id',
        form: 'form.woocommerce-shipping-calculator',
        injectHidden: true,
        triggerUpdate: false
    };

    function option(value, text) {
        return $('<option></option>').attr('value', value).text(text);
    }

    function provinceName(ctx) {
        var $state = $(ctx.state);
        if (!$state.length) {
            return '';
        }
        if ($state.is('select')) {
            return $state.find('option:selected').text();
        }
        return $state.val();
    }

    function buildIfNeeded(ctx) {
        // Hidden UUID field.
        if (!$(ctx.hiddenSel).length && ctx.injectHidden && $(ctx.form).length) {
            $(ctx.form).append(
                $('<input type="hidden" />').attr('name', ctx.hiddenName).attr('id', ctx.hiddenSel.substring(1))
            );
        }
        // City <select> row, inserted right after the native city field.
        if (!$('#' + ctx.selectId).length && $(ctx.nativeWrap).length) {
            var $row = $('<p class="form-row parsi-city-row"></p>').attr('id', ctx.selectId + '_row');
            var $label = $(ctx.nativeWrap).find('label').first().clone();
            if ($label.length) {
                $row.append($label);
            }
            $row.append($('<select></select>').attr('id', ctx.selectId).addClass('parsi-city-select'));
            $(ctx.nativeWrap).after($row);
        }
    }

    function showNativeCity(ctx, show) {
        if (show) {
            $(ctx.nativeWrap).show();
            $('#' + ctx.selectId + '_row').hide();
        } else {
            $(ctx.nativeWrap).hide();
            $('#' + ctx.selectId + '_row').show();
        }
    }

    function syncFromSelect(ctx, doTrigger) {
        var $sel = $('#' + ctx.selectId);
        if (!$sel.length) {
            return;
        }
        var id = $sel.val() || '';
        var name = id ? $sel.find('option:selected').text() : '';
        $(ctx.city).val(name);
        $(ctx.hiddenSel).val(id);
        if (doTrigger && ctx.triggerUpdate) {
            $(document.body).trigger('update_checkout');
        }
    }

    function populate(ctx) {
        if (!$(ctx.city).length || !$(ctx.state).length) {
            return;
        }
        buildIfNeeded(ctx);

        var cities = provByNorm[norm(provinceName(ctx))];
        if (!cities || !cities.length) {
            // Unknown / unselected province: fall back to the native text input.
            showNativeCity(ctx, true);
            return;
        }

        var $sel = $('#' + ctx.selectId);
        var prevId = $(ctx.hiddenSel).val();
        var prevName = $(ctx.city).val();

        $sel.empty().append(option('', i18n.chooseCity));
        cities.forEach(function (c) {
            $sel.append(option(c.id, c.name).attr('data-name', c.name));
        });

        // Restore a previous selection by UUID, then by name.
        var selId = '';
        if (prevId) {
            cities.forEach(function (c) { if (c.id === prevId) { selId = prevId; } });
        }
        if (!selId && prevName) {
            cities.forEach(function (c) { if (c.name === prevName) { selId = c.id; } });
        }
        $sel.val(selId);

        showNativeCity(ctx, false);
        syncFromSelect(ctx, false);
    }

    // National ID (کد ملی) is optional — no required indicator is applied.

    function enhanceAll() {
        populate(checkoutCtx);
        populate(cartCtx);
    }

    $(function () {
        enhanceAll();

        // Re-run after WooCommerce re-renders fields (checkout + cart AJAX).
        $(document.body).on(
            'updated_checkout updated_wc_div updated_cart_totals country_to_state_changed',
            enhanceAll
        );

        // Province (State) changes -> rebuild that context's city list.
        $(document.body).on('change', checkoutCtx.state, function () { populate(checkoutCtx); });
        $(document.body).on('change', cartCtx.state, function () { populate(cartCtx); });

        // City selection -> mirror name + UUID into the native + hidden fields.
        $(document.body).on('change', '#' + checkoutCtx.selectId, function () { syncFromSelect(checkoutCtx, true); });
        $(document.body).on('change', '#' + cartCtx.selectId, function () { syncFromSelect(cartCtx, false); });
    });
})(jQuery);
