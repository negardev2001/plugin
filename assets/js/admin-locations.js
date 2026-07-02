/**
 * PARSI Admin Location Dropdowns
 *
 * Cascading Province -> City selects on the plugin settings page. The province
 * select repopulates the city select from the localized `parsiLocations.provinces`
 * map (province_id => [{id, name}]). Selecting a city mirrors its name into the
 * hidden `parsi_origin_city` field so existing readers keep working; the server
 * derives coordinates + province from the saved city UUID on save.
 *
 * @package PARSI
 */
(function () {
    'use strict';

    function byId(id) {
        return document.getElementById(id);
    }

    function citiesFor(provinceId) {
        if (!window.parsiLocations || !window.parsiLocations.provinces) {
            return [];
        }
        return window.parsiLocations.provinces[provinceId] || [];
    }

    /**
     * Rebuild the city <select> for the given province, optionally preselecting
     * a city UUID.
     */
    function populateCities(citySelect, provinceId, selectedCityId) {
        var cities = citiesFor(provinceId);
        // Reset to just the placeholder (first option).
        var placeholder = citySelect.options.length ? citySelect.options[0] : null;
        citySelect.innerHTML = '';
        if (placeholder) {
            citySelect.appendChild(placeholder);
        }

        cities.forEach(function (city) {
            var opt = document.createElement('option');
            opt.value = city.id;
            opt.textContent = city.name;
            if (selectedCityId && city.id === selectedCityId) {
                opt.selected = true;
            }
            citySelect.appendChild(opt);
        });
    }

    function syncOriginName(citySelect, originField) {
        if (!originField) {
            return;
        }
        var opt = citySelect.options[citySelect.selectedIndex];
        originField.value = opt && opt.value ? opt.textContent.trim() : '';
    }

    function init() {
        var provinceSelect = byId('parsi_sender_province_id');
        var citySelect = byId('parsi_sender_city_id');
        var originField = byId('parsi_origin_city');

        if (!provinceSelect || !citySelect) {
            return;
        }

        provinceSelect.addEventListener('change', function () {
            populateCities(citySelect, provinceSelect.value, '');
            syncOriginName(citySelect, originField);
        });

        citySelect.addEventListener('change', function () {
            syncOriginName(citySelect, originField);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
