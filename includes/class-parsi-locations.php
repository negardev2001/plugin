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
     * Box type (نوع بسته‌بندی) UUIDs from the dataset, grouped by parcel family.
     * Used by select_box_type() to send a box that matches the shipment's
     * weight instead of the previous fixed "پاکت حبابدار A5" for everything.
     */
    // Packet family (belongs to PACKET_ID) — bubble envelopes for light parcels.
    const BOX_PACKET_A5 = '7d0a1a9e-0ec3-458c-a733-1aa04c33bcc8'; // پاکت حبابدار A5
    const BOX_PACKET_A4 = '646db1ed-da13-4ac4-b8c6-6518698ec820'; // پاکت حبابدار A4
    const BOX_PACKET_A3 = '028f086a-91b6-4dca-9f2f-642c6813f19f'; // پاکت حبابدار A3
    // Parcel family (belongs to PARCEL_ID) — cartons, ascending size.
    const BOX_CARTON_1  = 'f0917aa0-c624-4d39-82ff-eabd83a95003'; // کارتن سایز 1
    const BOX_CARTON_2  = '1645d0f8-e98f-46fa-8cae-e0e9cde275f6'; // کارتن سایز 2
    const BOX_CARTON_3  = '5a017fd8-833d-4d52-9739-75163ac69150'; // کارتن سایز 3
    const BOX_CARTON_4  = 'd9467f22-2b07-4876-9d74-3c405306a091'; // کارتن سایز 4
    const BOX_CARTON_5  = '1956c3fe-ec44-4da9-9858-c269c88d3209'; // کارتن سایز 5
    const BOX_CARTON_6  = '57f91368-8b1b-4279-a892-b4ab8913cc63'; // کارتن سایز 6
    const BOX_CARTON_7  = 'fb18d0b7-016e-4007-8104-e27dd3439e4d'; // کارتن سایز 7
    const BOX_CARTON_8  = 'dbb71971-60e3-4b1e-ad2c-4329e7166b9e'; // کارتن سایز 8
    const BOX_CARTON_9  = '3628e23b-fe62-473c-be65-d760b4bd87b1'; // کارتن سایز 9

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

    /**
     * Ascending list of carton box types with their inner dimensions (cm) and
     * a weight ceiling (grams). Both dimensions and weight are taken from the
     * bundled Parsi dataset. Ordering is by capacity, smallest first, so a
     * lower index always means a smaller/cheaper box.
     *
     * @return array<int,array{id:string,dims:array<int>,max_g:int}>
     */
    private static function carton_boxes() {
        return array(
            array('id' => self::BOX_CARTON_1, 'dims' => array(15, 10, 10), 'max_g' => 3000),
            array('id' => self::BOX_CARTON_2, 'dims' => array(20, 15, 10), 'max_g' => 5000),
            array('id' => self::BOX_CARTON_3, 'dims' => array(20, 20, 15), 'max_g' => 8000),
            array('id' => self::BOX_CARTON_4, 'dims' => array(30, 20, 20), 'max_g' => 12000),
            array('id' => self::BOX_CARTON_5, 'dims' => array(35, 25, 18), 'max_g' => 17000),
            array('id' => self::BOX_CARTON_6, 'dims' => array(45, 25, 20), 'max_g' => 25000),
            array('id' => self::BOX_CARTON_7, 'dims' => array(40, 30, 25), 'max_g' => 35000),
            array('id' => self::BOX_CARTON_8, 'dims' => array(45, 40, 30), 'max_g' => 45000),
            array('id' => self::BOX_CARTON_9, 'dims' => array(55, 45, 35), 'max_g' => PHP_INT_MAX),
        );
    }

    /**
     * Auto-select the box type (نوع بسته‌بندی) UUID from BOTH the package weight
     * and its dimensions.
     *
     * Because the Parsi API has no explicit length/width/height field, the box's
     * fixed dimensions are how a parcel's size reaches the pricing engine (which
     * charges by volumetric weight). Picking the box on weight alone made a
     * bulky 2 kg parcel (e.g. 90×90×90) cost the same as a compact 2 kg one
     * (20×10×15). This now chooses:
     *   - Light packets (<= 2000g, no dimensions) → a bubble envelope by weight.
     *   - Parcels → the smaller carton is only used when it BOTH holds the
     *     weight AND physically contains the item (rotation allowed). A bulky
     *     item is bumped up to the carton that fits it, so it is priced higher.
     *
     * Items larger than the biggest carton fall back to that carton; the Parsi
     * "مرسوله ابعاد غیر استاندارد دارد" extra service can cover true oversize.
     *
     * @param int|float                                        $weight_grams Package weight in grams.
     * @param array{length?:float,width?:float,height?:float}  $dimensions   Package size in cm.
     * @return string Box type UUID.
     */
    public static function select_box_type($weight_grams, $dimensions = array()) {
        $weight_grams = (float) $weight_grams;

        // Backward compatibility: an old caller may pass a boolean $has_dimensions.
        if (!is_array($dimensions)) {
            $dimensions = array();
        }

        $length = (float) (isset($dimensions['length']) ? $dimensions['length'] : 0);
        $width  = (float) (isset($dimensions['width'])  ? $dimensions['width']  : 0);
        $height = (float) (isset($dimensions['height']) ? $dimensions['height'] : 0);
        $has_dimensions = ($length > 0 || $width > 0 || $height > 0);

        // Light packets: bubble envelope sized by weight (A5 → A4 → A3).
        if ($weight_grams <= 2000 && !$has_dimensions) {
            $packet = ($weight_grams <= 500)
                ? self::BOX_PACKET_A5
                : (($weight_grams <= 1000) ? self::BOX_PACKET_A4 : self::BOX_PACKET_A3);
            return apply_filters('parsi_selected_box_type', $packet, $weight_grams, $dimensions);
        }

        $cartons = self::carton_boxes();
        $last    = count($cartons) - 1;

        // Smallest carton whose weight ceiling covers the parcel.
        $weight_index = $last;
        foreach ($cartons as $i => $carton) {
            if ($weight_grams <= $carton['max_g']) {
                $weight_index = $i;
                break;
            }
        }

        // Smallest carton whose inner dimensions can contain the item. Both the
        // item and the box are sorted largest-first so any rotation is allowed.
        $product = array($length, $width, $height);
        rsort($product);
        $dim_index = -1;
        if ($has_dimensions) {
            foreach ($cartons as $i => $carton) {
                $box = $carton['dims'];
                rsort($box);
                if ($box[0] >= $product[0] && $box[1] >= $product[1] && $box[2] >= $product[2]) {
                    $dim_index = $i;
                    break;
                }
            }
            // Item exceeds every carton → use the largest.
            if ($dim_index === -1) {
                $dim_index = $last;
            }
        }

        // A heavy item needs the weight-rated box; a bulky item needs the box
        // that fits. Use whichever is larger so both constraints are satisfied.
        $index = max($weight_index, $dim_index);

        return apply_filters('parsi_selected_box_type', $cartons[$index]['id'], $weight_grams, $dimensions);
    }
}
