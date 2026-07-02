<?php
/**
 * PARSI Locations Provider
 *
 * @package PARSI
 *
 * Reads the bundled GetApiOrderBasicInfo dataset (includes/data/parsi-locations.json)
 * and exposes provinces, cities, parcel types and insurance types for the admin
 * settings dropdowns and the classic-checkout city picker. The data is static so it
 * is bundled rather than fetched, which keeps the dropdowns working offline and
 * removes the need for an AJAX/nonce surface.
 */

if (!defined('ABSPATH')) {
    exit;
}

class PARSI_Locations {

    /**
     * Parcel type UUIDs from the dataset. Hardcoded so auto-selection keeps
     * working even if the JSON file is missing or unreadable.
     */
    const PACKET_ID = '976c51bd-cc4a-40fb-897c-4f8b905f489a'; // پاکت  (maxWeight 2000g, no dimensions)
    const PARCEL_ID = 'f5eb5404-2b1a-41aa-90c2-a0513c7f2e5f'; // بسته  (needs dimensions)

    /**
     * Decoded dataset cache. Null until first load.
     *
     * @var array|null
     */
    private static $data = null;

    /**
     * Lazy index of city_id => array(city + province) for O(1) lookups.
     *
     * @var array|null
     */
    private static $city_index = null;

    /**
     * Lazy index of normalized-city-name => city_id.
     *
     * @var array|null
     */
    private static $name_index = null;

    /**
     * Load and statically cache the bundled dataset.
     *
     * @return array Dataset with keys provinces, parcelTypes, insuranceTypes.
     */
    private static function data() {
        if (null !== self::$data) {
            return self::$data;
        }

        $empty = array('provinces' => array(), 'parcelTypes' => array(), 'insuranceTypes' => array());
        $file  = PARSI_PLUGIN_DIR . 'includes/data/parsi-locations.json';

        if (!is_readable($file)) {
            if (function_exists('parsi_log')) {
                parsi_log('Locations dataset not readable: ' . $file, 'error');
            }
            self::$data = $empty;
            return self::$data;
        }

        $raw     = file_get_contents($file);
        $decoded = json_decode($raw, true);

        if (!is_array($decoded) || empty($decoded['provinces'])) {
            if (function_exists('parsi_log')) {
                parsi_log('Locations dataset is empty or invalid', 'error');
            }
            self::$data = $empty;
            return self::$data;
        }

        self::$data = array(
            'provinces'      => isset($decoded['provinces']) && is_array($decoded['provinces']) ? $decoded['provinces'] : array(),
            'parcelTypes'    => isset($decoded['parcelTypes']) && is_array($decoded['parcelTypes']) ? $decoded['parcelTypes'] : array(),
            'insuranceTypes' => isset($decoded['insuranceTypes']) && is_array($decoded['insuranceTypes']) ? $decoded['insuranceTypes'] : array(),
        );

        return self::$data;
    }

    /**
     * Provinces as a flat list of id + name (cities stripped).
     *
     * @return array List of array('id' => string, 'name' => string).
     */
    public static function get_provinces() {
        $provinces = array();
        foreach (self::data()['provinces'] as $province) {
            if (isset($province['id'], $province['name'])) {
                $provinces[] = array(
                    'id'   => (string) $province['id'],
                    'name' => (string) $province['name'],
                );
            }
        }
        return $provinces;
    }

    /**
     * Cities for a given province.
     *
     * @param string $province_id Province UUID.
     * @return array List of array('id', 'name', 'centerLocation').
     */
    public static function get_cities($province_id) {
        $province_id = (string) $province_id;
        if ('' === $province_id) {
            return array();
        }
        foreach (self::data()['provinces'] as $province) {
            if (isset($province['id']) && (string) $province['id'] === $province_id) {
                return isset($province['cities']) && is_array($province['cities']) ? $province['cities'] : array();
            }
        }
        return array();
    }

    /**
     * Look up a single city by id, including the province it belongs to.
     *
     * @param string $city_id City UUID.
     * @return array|null array('id','name','centerLocation','province_id','province_name') or null.
     */
    public static function get_city($city_id) {
        $city_id = (string) $city_id;
        if ('' === $city_id) {
            return null;
        }

        if (null === self::$city_index) {
            self::$city_index = array();
            foreach (self::data()['provinces'] as $province) {
                $pid   = isset($province['id']) ? (string) $province['id'] : '';
                $pname = isset($province['name']) ? (string) $province['name'] : '';
                $cities = isset($province['cities']) && is_array($province['cities']) ? $province['cities'] : array();
                foreach ($cities as $city) {
                    if (!isset($city['id'])) {
                        continue;
                    }
                    self::$city_index[(string) $city['id']] = array(
                        'id'             => (string) $city['id'],
                        'name'           => isset($city['name']) ? (string) $city['name'] : '',
                        'centerLocation' => isset($city['centerLocation']) ? (string) $city['centerLocation'] : '',
                        'province_id'    => $pid,
                        'province_name'  => $pname,
                    );
                }
            }
        }

        return isset(self::$city_index[$city_id]) ? self::$city_index[$city_id] : null;
    }

    /**
     * Resolve a city UUID from its (Persian) name. Uses the same Arabic/Persian
     * normalization as the rest of the plugin so typed variants still match.
     *
     * Note: ~12% of city names are shared across provinces; this returns the
     * first match. Prefer get_city() with a known UUID when precision matters.
     *
     * @param string $name City name.
     * @return string|null City UUID or null when not found.
     */
    public static function get_city_id_by_name($name) {
        $name = trim((string) $name);
        if ('' === $name) {
            return null;
        }

        $needle = self::normalize_name($name);

        if (null === self::$name_index) {
            self::$name_index = array();
            foreach (self::data()['provinces'] as $province) {
                $cities = isset($province['cities']) && is_array($province['cities']) ? $province['cities'] : array();
                foreach ($cities as $city) {
                    if (!isset($city['id'], $city['name'])) {
                        continue;
                    }
                    $key = self::normalize_name((string) $city['name']);
                    if ($key !== '' && !isset(self::$name_index[$key])) {
                        self::$name_index[$key] = (string) $city['id'];
                    }
                }
            }
        }

        return isset(self::$name_index[$needle]) ? self::$name_index[$needle] : null;
    }

    /**
     * Provinces with their cities (id + name only) plus a normalized name, for
     * the customer-side cascading dropdown. The normalized name lets JS match a
     * province against WooCommerce's native State field label.
     *
     * @return array List of array('id','name','norm','cities'=>array('id','name')).
     */
    public static function get_provinces_with_cities() {
        $provinces = array();
        foreach (self::data()['provinces'] as $province) {
            if (!isset($province['id'], $province['name'])) {
                continue;
            }
            $cities = array();
            $province_cities = isset($province['cities']) && is_array($province['cities']) ? $province['cities'] : array();
            foreach ($province_cities as $city) {
                if (isset($city['id'], $city['name'])) {
                    $cities[] = array('id' => (string) $city['id'], 'name' => (string) $city['name']);
                }
            }
            $provinces[] = array(
                'id'     => (string) $province['id'],
                'name'   => (string) $province['name'],
                'norm'   => self::normalize_name((string) $province['name']),
                'cities' => $cities,
            );
        }
        return $provinces;
    }

    /**
     * Normalize a Persian place name for matching: unify Arabic/Persian letter
     * variants and collapse whitespace. Mirrors the JS normalizer in
     * assets/js/checkout-locations.js so both sides agree.
     *
     * @param string $name Raw name.
     * @return string Normalized name.
     */
    public static function normalize_name($name) {
        $name = (string) $name;
        // Arabic Yeh/Alef Maksura -> Persian Yeh; Arabic Kaf -> Persian Kaf.
        $name = str_replace(array("\u{064A}", "\u{0649}"), "\u{06CC}", $name);
        $name = str_replace("\u{0643}", "\u{06A9}", $name);
        // Drop ZWNJ, then collapse all whitespace to single spaces.
        $name = str_replace("\u{200C}", ' ', $name);
        $name = preg_replace('/\s+/u', ' ', $name);
        return trim(function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name));
    }

    /**
     * Insurance types from the dataset.
     *
     * @return array List of array('id' => string, 'title' => string).
     */
    public static function get_insurance_types() {
        $types = array();
        foreach (self::data()['insuranceTypes'] as $type) {
            if (isset($type['id'])) {
                $types[] = array(
                    'id'    => (string) $type['id'],
                    'title' => isset($type['title']) ? (string) $type['title'] : (string) $type['id'],
                );
            }
        }
        return $types;
    }

    /**
     * Parcel types from the dataset.
     *
     * @return array List of array('id','title','maxWeight','needDimension').
     */
    public static function get_parcel_types() {
        $types = array();
        foreach (self::data()['parcelTypes'] as $type) {
            if (isset($type['id'])) {
                $types[] = array(
                    'id'            => (string) $type['id'],
                    'title'         => isset($type['title']) ? (string) $type['title'] : (string) $type['id'],
                    'maxWeight'     => isset($type['maxWeight']) ? (int) $type['maxWeight'] : 0,
                    'needDimension' => !empty($type['needDimension']),
                );
            }
        }
        return $types;
    }

    /**
     * Province => cities map (id + name only) for client-side cascading
     * dropdowns. Center locations are intentionally omitted to keep the
     * localized JS payload small; they are looked up server-side.
     *
     * @return array array('province_id' => array(array('id','name'), ...)).
     */
    public static function get_province_cities_map() {
        $map = array();
        foreach (self::data()['provinces'] as $province) {
            if (!isset($province['id'])) {
                continue;
            }
            $cities = array();
            $province_cities = isset($province['cities']) && is_array($province['cities']) ? $province['cities'] : array();
            foreach ($province_cities as $city) {
                if (isset($city['id'], $city['name'])) {
                    $cities[] = array(
                        'id'   => (string) $city['id'],
                        'name' => (string) $city['name'],
                    );
                }
            }
            $map[(string) $province['id']] = $cities;
        }
        return $map;
    }

    /**
     * Auto-select a parcel type from package weight and whether the package
     * has dimensions: پاکت for light, dimensionless parcels (<= 2000g),
     * otherwise بسته.
     *
     * @param int|float $weight_grams   Package weight in grams.
     * @param bool      $has_dimensions Whether the package declares dimensions.
     * @return string Parcel type UUID.
     */
    public static function select_parcel_type($weight_grams, $has_dimensions = false) {
        $weight_grams = (float) $weight_grams;
        if ($weight_grams <= 2000 && !$has_dimensions) {
            return self::PACKET_ID;
        }
        return self::PARCEL_ID;
    }
}
