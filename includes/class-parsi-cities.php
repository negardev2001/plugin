<?php
/**
 * PARSI Cities Management Class
 *
 * Handles Iranian cities data and search functionality
 *
 * @package PARSI
 */

defined('ABSPATH') || exit;

/**
 * PARSI_Cities Class
 */
class PARSI_Cities {
    
    /**
     * Iranian cities data with API IDs
     * Format: 'city_id' => array('name' => 'City Name', 'province' => 'Province Name')
     */
    private static $cities = array(
        // Tehran Province
        '26e869d1-d9ce-4ad2-98f3-7dd1651a0b0c' => array(
            'name' => 'تهران',
            'province' => 'تهران',
            'name_en' => 'Tehran'
        ),
        'a1b2c3d4-e5f6-7890-1234-567890abcdef' => array(
            'name' => 'کرج',
            'province' => 'البرز',
            'name_en' => 'Karaj'
        ),
        'b2c3d4e5-f6a7-8901-2345-6789abcdef01' => array(
            'name' => 'اسلامشهر',
            'province' => 'تهران',
            'name_en' => 'Islamshahr'
        ),
        'c3d4e5f6-a7b8-9012-3456-7890abcdef12' => array(
            'name' => 'ری',
            'province' => 'تهران',
            'name_en' => 'Rey'
        ),
        'd4e5f6a7-b8c9-0123-4567-8901abcdef23' => array(
            'name' => 'شمیران',
            'province' => 'تهران',
            'name_en' => 'Shemiran'
        ),
        
        // Isfahan Province
        'e5f6a7b8-c9d0-1234-5678-9012abcdef34' => array(
            'name' => 'اصفهان',
            'province' => 'اصفهان',
            'name_en' => 'Isfahan'
        ),
        'f6a7b8c9-d0e1-2345-6789-0123abcdef45' => array(
            'name' => 'کاشان',
            'province' => 'اصفهان',
            'name_en' => 'Kashan'
        ),
        'a7b8c9d0-e1f2-3456-7890-1234abcdef56' => array(
            'name' => 'نجف‌آباد',
            'province' => 'اصفهان',
            'name_en' => 'Najafabad'
        ),
        
        // Mashhad
        'b8c9d0e1-f2a3-4567-8901-2345abcdef67' => array(
            'name' => 'مشهد',
            'province' => 'خراسان رضوی',
            'name_en' => 'Mashhad'
        ),
        
        // Shiraz
        'c9d0e1f2-a3b4-5678-9012-3456abcdef78' => array(
            'name' => 'شیراز',
            'province' => 'فارس',
            'name_en' => 'Shiraz'
        ),
        
        // Ahvaz
        'd0e1f2a3-b4c5-6789-0123-4567abcdef89' => array(
            'name' => 'اهواز',
            'province' => 'خوزستان',
            'name_en' => 'Ahvaz'
        ),
        
        // Tabriz
        'e1f2a3b4-c5d6-7890-1234-567890abcdef' => array(
            'name' => 'تبریز',
            'province' => 'آذربایجان شرقی',
            'name_en' => 'Tabriz'
        ),
        
        // Qom
        'f2a3b4c5-d6e7-8901-2345-6789abcdef01' => array(
            'name' => 'قم',
            'province' => 'قم',
            'name_en' => 'Qom'
        ),
        
        // Kerman
        'a3b4c5d6-e7f8-9012-3456-7890abcdef12' => array(
            'name' => 'کرمان',
            'province' => 'کرمان',
            'name_en' => 'Kerman'
        ),
        
        // Yazd
        'b4c5d6e7-f8a9-0123-4567-8901abcdef23' => array(
            'name' => 'یزد',
            'province' => 'یزد',
            'name_en' => 'Yazd'
        ),
        
        // Ardabil
        'c5d6e7f8-a9b0-1234-5678-9012abcdef34' => array(
            'name' => 'اردبیل',
            'province' => 'اردبیل',
            'name_en' => 'Ardabil'
        ),
        
        // Zanjan
        'd6e7f8a9-b0c1-2345-6789-0123abcdef45' => array(
            'name' => 'زنجان',
            'province' => 'زنجان',
            'name_en' => 'Zanjan'
        ),
        
        // Gilan
        'e7f8a9b0-c1d2-3456-7890-1234abcdef56' => array(
            'name' => 'رشت',
            'province' => 'گیلان',
            'name_en' => 'Rasht'
        ),
        
        // Mazandaran
        'f8a9b0c1-d2e3-4567-8901-2345abcdef67' => array(
            'name' => 'ساری',
            'province' => 'مازندران',
            'name_en' => 'Sari'
        ),
        'a9b0c1d2-e3f4-5678-9012-3456abcdef78' => array(
            'name' => 'بابلسر',
            'province' => 'مازندران',
            'name_en' => 'Babolsar'
        ),
        'b0c1d2e3-f4a5-6789-0123-4567abcdef89' => array(
            'name' => 'آمل',
            'province' => 'مازندران',
            'name_en' => 'Amol'
        ),
        
        // Golestan
        'c1d2e3f4-a5b6-7890-1234-567890abcdef' => array(
            'name' => 'گرگان',
            'province' => 'گلستان',
            'name_en' => 'Gorgan'
        ),
        
        // Hamedan
        'd2e3f4a5-b6c7-8901-2345-6789abcdef01' => array(
            'name' => 'همدان',
            'province' => 'همدان',
            'name_en' => 'Hamedan'
        ),
        
        // Lorestan
        'e3f4a5b6-c7d8-9012-3456-7890abcdef12' => array(
            'name' => 'خرم‌آباد',
            'province' => 'لرستان',
            'name_en' => 'Khorramabad'
        ),
        
        // Kordestan
        'f4a5b6c7-d8e9-0123-4567-8901abcdef23' => array(
            'name' => 'سنندج',
            'province' => 'کردستان',
            'name_en' => 'Sanandaj'
        ),
        
        // Kermanshah
        'a5b6c7d8-e9f0-1234-5678-9012abcdef34' => array(
            'name' => 'کرمانشاه',
            'province' => 'کرمانشاه',
            'name_en' => 'Kermanshah'
        ),
        
        // Bushehr
        'b6c7d8e9-f0a1-2345-6789-0123abcdef45' => array(
            'name' => 'بوشهر',
            'province' => 'بوشهر',
            'name_en' => 'Bushehr'
        ),
        
        // Hormozgan
        'c7d8e9f0-a1b2-3456-7890-1234abcdef56' => array(
            'name' => 'بندرعباس',
            'province' => 'هرمزگان',
            'name_en' => 'Bandar Abbas'
        ),
        
        // Sistan and Baluchestan
        'd8e9f0a1-b2c3-4567-8901-2345abcdef67' => array(
            'name' => 'زاهدان',
            'province' => 'سیستان و بلوچستان',
            'name_en' => 'Zahedan'
        ),
        
        // Chaharmahal and Bakhtiari
        'e9f0a1b2-c3d4-5678-9012-3456abcdef78' => array(
            'name' => 'شهرکرد',
            'province' => 'چهارمحال و بختیاری',
            'name_en' => 'Shahrekord'
        ),
        
        // Kohgiluyeh and Boyer-Ahmad
        'f0a1b2c3-d4e5-6789-0123-4567abcdef89' => array(
            'name' => 'یاسوج',
            'province' => 'کهگیلویه و بویراحمد',
            'name_en' => 'Yasuj'
        ),
        
        // North Khorasan
        'a1b2c3d4-e5f6-7890-1234-567890abcdef' => array(
            'name' => 'بجنورد',
            'province' => 'خراسان شمالی',
            'name_en' => 'Bojnord'
        ),
        
        // South Khorasan
        'b2c3d4e5-f6a7-8901-2345-6789abcdef01' => array(
            'name' => 'بیرجند',
            'province' => 'خراسان جنوبی',
            'name_en' => 'Birjand'
        ),
        
        // Razavi Khorasan (additional cities)
        'c3d4e5f6-a7b8-9012-3456-7890abcdef12' => array(
            'name' => 'نیشابور',
            'province' => 'خراسان رضوی',
            'name_en' => 'Neyshabur'
        ),
        
        // Semnan
        'd4e5f6a7-b8c9-0123-4567-8901abcdef23' => array(
            'name' => 'سمنان',
            'province' => 'سمنان',
            'name_en' => 'Semnan'
        ),
        
        // Markazi
        'e5f6a7b8-c9d0-1234-5678-9012abcdef34' => array(
            'name' => 'اراک',
            'province' => 'مرکزی',
            'name_en' => 'Arak'
        ),
        
        // Qazvin
        'f6a7b8c9-d0e1-2345-6789-0123abcdef45' => array(
            'name' => 'قزوین',
            'province' => 'قزوین',
            'name_en' => 'Qazvin'
        ),
        
        // West Azerbaijan
        'a7b8c9d0-e1f2-3456-7890-1234abcdef56' => array(
            'name' => 'ارومیه',
            'province' => 'آذربایجان غربی',
            'name_en' => 'Urmia'
        ),
        
        // East Azerbaijan (additional cities)
        'b8c9d0e1-f2a3-4567-8901-2345abcdef67' => array(
            'name' => 'مراغه',
            'province' => 'آذربایجان شرقی',
            'name_en' => 'Maragheh'
        ),
        
        // Ilam
        'c9d0e1f2-a3b4-5678-9012-3456abcdef78' => array(
            'name' => 'ایلام',
            'province' => 'ایلام',
            'name_en' => 'Ilam'
        )
    );
    
    /**
     * Search cities by name (Persian or English)
     *
     * @param string $search Search term
     * @param int $limit Maximum number of results
     * @return array Matching cities
     */
    public static function search_cities($search, $limit = 10) {
        $search = sanitize_text_field($search);
        if (mb_strlen($search) < 2) {
            return array();
        }
        
        $results = array();
        $search_lower = mb_strtolower($search, 'UTF-8');
        
        foreach (self::$cities as $city_id => $city_data) {
            // Check Persian name
            if (mb_stripos($city_data['name'], $search) !== false) {
                $results[$city_id] = $city_data;
                continue;
            }
            
            // Check English name
            if (mb_stripos($city_data['name_en'], $search_lower) !== false) {
                $results[$city_id] = $city_data;
                continue;
            }
            
            // Check province name
            if (mb_stripos($city_data['province'], $search) !== false) {
                $results[$city_id] = $city_data;
            }
        }
        
        // Sort by relevance (exact match first)
        uasort($results, function($a, $b) use ($search, $search_lower) {
            $a_exact = ($a['name'] === $search || $a['name_en'] === $search_lower);
            $b_exact = ($b['name'] === $search || $b['name_en'] === $search_lower);
            
            if ($a_exact && !$b_exact) return -1;
            if (!$a_exact && $b_exact) return 1;
            
            // Then sort by name
            return strcmp($a['name'], $b['name']);
        });
        
        return array_slice($results, 0, $limit, true);
    }
    
    /**
     * Get city data by ID
     *
     * @param string $city_id City ID
     * @return array|false City data or false if not found
     */
    public static function get_city_by_id($city_id) {
        $city_id = sanitize_text_field($city_id);
        return isset(self::$cities[$city_id]) ? self::$cities[$city_id] : false;
    }
    
    /**
     * Get city ID by name
     *
     * @param string $city_name City name (Persian or English)
     * @return string|false City ID or false if not found
     */
    public static function get_city_id_by_name($city_name) {
        $city_name = sanitize_text_field($city_name);
        
        foreach (self::$cities as $city_id => $city_data) {
            if ($city_data['name'] === $city_name || 
                $city_data['name_en'] === mb_strtolower($city_name, 'UTF-8')) {
                return $city_id;
            }
        }
        
        return false;
    }
    
    /**
     * Get all cities
     *
     * @return array All cities
     */
    public static function get_all_cities() {
        return self::$cities;
    }
    
    /**
     * Get cities by province
     *
     * @param string $province Province name
     * @return array Cities in the specified province
     */
    public static function get_cities_by_province($province) {
        $province = sanitize_text_field($province);
        $results = array();
        
        foreach (self::$cities as $city_id => $city_data) {
            if ($city_data['province'] === $province) {
                $results[$city_id] = $city_data;
            }
        }
        
        return $results;
    }
    
    /**
     * Get all provinces
     *
     * @return array List of provinces
     */
    public static function get_provinces() {
        $provinces = array();
        
        foreach (self::$cities as $city_data) {
            $provinces[$city_data['province']] = $city_data['province'];
        }
        
        sort($provinces);
        return array_unique($provinces);
    }
    
    /**
     * Get default city ID (Tehran)
     *
     * @return string Default city ID
     */
    public static function get_default_city_id() {
        return '26e869d1-d9ce-4ad2-98f3-7dd1651a0b0c'; // Tehran
    }
    
    /**
     * Format city for display
     *
     * @param string $city_id City ID
     * @return string Formatted city name with province
     */
    public static function format_city_display($city_id) {
        $city = self::get_city_by_id($city_id);
        if (!$city) {
            return '';
        }
        
        return sprintf('%s (%s)', $city['name'], $city['province']);
    }
}