<?php
/**
 * PARSI API Data Fetcher
 *
 * @package PARSI
 *
 * @version 3.0.0 - Enhanced with cache stampede protection and adaptive TTL
 * - Removed Bearer token authentication
 * - Added x-api-key and office custom headers
 * - Updated to use GetApiOrderBasicInfo endpoint for cities data
 * - Added single-flight request protection
 * - Added adaptive TTL for empty arrays
 * - Added stale cache fallback
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * PARSI API Data Class
 * 
 * Handles fetching data from Parsi Post API (cities, deadlines, etc.)
 * with cache stampede protection and adaptive TTL
 */
class PARSI_API_Data {
    
    /**
     * Cache expiration time in seconds (12 hours for valid data)
     *
     * @var int
     */
    const CACHE_EXPIRATION = 43200; // 12 hours
    
    /**
     * Cache expiration time for empty arrays (5 minutes)
     *
     * @var int
     */
    const EMPTY_CACHE_EXPIRATION = 300; // 5 minutes
    
    /**
     * Stale cache expiration time in seconds (24 hours)
     *
     * @var int
     */
    const STALE_CACHE_EXPIRATION = 86400; // 24 hours
    
    /**
     * Lock expiration time in seconds
     *
     * @var int
     */
    const LOCK_EXPIRATION = 30; // 30 seconds
    
    /**
     * Maximum wait time for lock in microseconds
     *
     * @var int
     */
    const MAX_WAIT_TIME = 2000000; // 2 seconds
    
    /**
     * Polling interval in microseconds
     *
     * @var int
     */
    const POLL_INTERVAL = 100000; // 100ms
    
    /**
     * Get cities from API using GetApiOrderBasicInfo endpoint
     * with cache stampede protection and adaptive TTL
     *
     * @param bool $force_refresh Force refresh cache
     * @return array|false Array of cities or false on error
     */
    public static function get_cities($force_refresh = false) {
        $cache_key = 'parsi_cities_list';
        $lock_key = 'parsi_cities_fetch_lock';
        
        // Try to get from cache first
        if (!$force_refresh) {
            $cached = get_transient($cache_key);
            if (false !== $cached) {
                parsi_log('Cities cache hit', 'info');
                return $cached;
            }
        }
        
        parsi_log('Cities cache miss', 'info');
        
        // Try to acquire lock using the best available method
        $lock_acquired = false;
        if (wp_using_ext_object_cache()) {
            // Level 1: Use wp_cache_add for atomic locking
            $lock_acquired = wp_cache_add($lock_key, 'locked', self::LOCK_EXPIRATION);
            if ($lock_acquired) {
                parsi_log('Cities lock acquired via wp_cache_add', 'info');
            }
        } else {
            // Level 2 fallback: Use add_option mutex for atomic locking
            $lock_acquired = add_option($lock_key, 'locked', '', 'no');
            if ($lock_acquired) {
                parsi_log('Cities lock acquired via add_option mutex', 'info');
            }
        }
        
        // If we didn't get the lock, wait for other worker
        if (!$lock_acquired) {
            parsi_log('Waiting for cities fetcher', 'info');
            return self::wait_for_cache($cache_key, $lock_key);
        }
        
        try {
            // We have the lock - fetch from API
            $cities = self::fetch_cities_from_api();
            
            if (false === $cities) {
                parsi_log('Cities API refresh failed, serving stale cache if available', 'warning');
                // Try to return stale cache
                $stale_cache = get_transient($cache_key . '_stale');
                if (false !== $stale_cache) {
                    parsi_log('Serving stale cities cache', 'info');
                    return $stale_cache;
                }
                return false;
            }
            
            // Determine TTL based on data
            $ttl = !empty($cities) ? self::CACHE_EXPIRATION : self::EMPTY_CACHE_EXPIRATION;
            
            // Store stale cache before updating main cache
            $existing_cache = get_transient($cache_key);
            if (false !== $existing_cache) {
                set_transient($cache_key . '_stale', $existing_cache, self::CACHE_EXPIRATION);
            }
            
            // Cache the result
            set_transient($cache_key, $cities, $ttl);
            
            if (empty($cities)) {
                parsi_log('Cities data empty, cached for 300 seconds', 'warning');
            } else {
                parsi_log('Cities cache filled successfully', 'info');
            }
            
            return $cities;
            
        } finally {
            // Always release the lock
            self::release_lock($lock_key);
        }
    }
    
    /**
     * Fetch cities from API
     *
     * @return array|false Array of cities or false on error
     */
    private static function fetch_cities_from_api() {
        // Make API request
        $api_key = get_option('parsi_api_key', '');
        $office_id = get_option('parsi_office_id', '');
        
        if (empty($api_key) || empty($office_id)) {
            parsi_log('API key or office ID not configured', 'error');
            return false;
        }
        
        // 🔒 SECURITY: Use new authentication headers (x-api-key and office)
        // Bearer token authentication has been removed per new API documentation
        $url = PARSI_API_Config::get_order_info_url();
        
        $args = array(
            'method' => 'GET',
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-api-key' => $api_key,  // Required custom header
                'office' => $office_id,   // Required custom header
            ),
            'timeout' => 30,
            'sslverify' => true,
        );
        
        parsi_log('Fetching cities from API', 'info');
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            parsi_log('Failed to fetch cities from API: ' . $response->get_error_message(), 'error');
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code < 200 || $response_code >= 300) {
            parsi_log('Order Info API returned error: ' . $response_body, 'error');
            return false;
        }
        
        $decoded = json_decode($response_body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            parsi_log('Failed to decode order info JSON response', 'error');
            return false;
        }
        
        // Normalize cities format from Provinces array
        $cities = self::normalize_cities_new_api($decoded);
        
        parsi_log('Cities API response processed successfully', 'info');
        
        return $cities;
    }
    
    /**
     * Get deadlines from API
     * with cache stampede protection and adaptive TTL
     *
     * @param bool $force_refresh Force refresh cache
     * @return array|false Array of deadlines or false on error
     */
    public static function get_deadlines($force_refresh = false) {
        $cache_key = 'parsi_deadlines_list';
        $lock_key = 'parsi_deadlines_fetch_lock';
        
        // Try to get from cache first
        if (!$force_refresh) {
            $cached = get_transient($cache_key);
            if (false !== $cached) {
                parsi_log('Deadlines cache hit', 'info');
                return $cached;
            }
        }
        
        parsi_log('Deadlines cache miss', 'info');
        
        // Try to acquire lock using the best available method
        $lock_acquired = false;
        if (wp_using_ext_object_cache()) {
            // Level 1: Use wp_cache_add for atomic locking
            $lock_acquired = wp_cache_add($lock_key, 'locked', self::LOCK_EXPIRATION);
            if ($lock_acquired) {
                parsi_log('Deadlines lock acquired via wp_cache_add', 'info');
            }
        } else {
            // Level 2 fallback: Use add_option mutex for atomic locking
            $lock_acquired = add_option($lock_key, 'locked', '', 'no');
            if ($lock_acquired) {
                parsi_log('Deadlines lock acquired via add_option mutex', 'info');
            }
        }
        
        // If we didn't get the lock, wait for other worker
        if (!$lock_acquired) {
            parsi_log('Waiting for deadlines fetcher', 'info');
            return self::wait_for_cache($cache_key, $lock_key);
        }
        
        try {
            // We have the lock - fetch from API
            $deadlines = self::fetch_deadlines_from_api();
            
            if (false === $deadlines) {
                parsi_log('Deadlines API refresh failed, serving stale cache if available', 'warning');
                // Try to return stale cache
                $stale_cache = get_transient($cache_key . '_stale');
                if (false !== $stale_cache) {
                    parsi_log('Serving stale deadlines cache', 'info');
                    return $stale_cache;
                }
                return false;
            }
            
            // Determine TTL based on data
            $ttl = !empty($deadlines) ? self::CACHE_EXPIRATION : self::EMPTY_CACHE_EXPIRATION;
            
            // Store stale cache before updating main cache
            $existing_cache = get_transient($cache_key);
            if (false !== $existing_cache) {
                set_transient($cache_key . '_stale', $existing_cache, self::CACHE_EXPIRATION);
            }
            
            // Cache the result
            set_transient($cache_key, $deadlines, $ttl);
            
            if (empty($deadlines)) {
                parsi_log('Deadlines data empty, cached for 300 seconds', 'warning');
            } else {
                parsi_log('Deadlines cache filled successfully', 'info');
            }
            
            return $deadlines;
            
        } finally {
            // Always release the lock
            self::release_lock($lock_key);
        }
    }
    
    /**
     * Fetch deadlines from API
     *
     * @return array|false Array of deadlines or false on error
     */
    private static function fetch_deadlines_from_api() {
        // Make API request
        $api_key = get_option('parsi_api_key', '');
        $office_id = get_option('parsi_office_id', '');
        
        if (empty($api_key) || empty($office_id)) {
            parsi_log('API key or office ID not configured', 'error');
            return false;
        }
        
        // Deadlines come from GetApiOrderBasicInfo, same endpoint as cities.
        $url = PARSI_API_Config::get_deadlines_url();
        
        // 🔒 SECURITY: Use new authentication headers (x-api-key and office)
        // Bearer token authentication has been removed per new API documentation
        $args = array(
            'method' => 'GET',
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-api-key' => $api_key,  // Required custom header
                'office' => $office_id,   // Required custom header
            ),
            'timeout' => 30,
            'sslverify' => true,
        );
        
        parsi_log('Fetching deadlines from API', 'info');
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            parsi_log('Failed to fetch deadlines from API: ' . $response->get_error_message(), 'error');
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code < 200 || $response_code >= 300) {
            parsi_log('Deadlines API returned error: ' . $response_body, 'error');
            return false;
        }
        
        $decoded = json_decode($response_body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            parsi_log('Failed to decode deadlines JSON response', 'error');
            return false;
        }
        
        // Normalize deadlines format
        $deadlines = self::normalize_deadlines($decoded);
        
        parsi_log('Deadlines API response processed successfully', 'info');
        
        return $deadlines;
    }
    
    /**
     * Normalize cities format from new API structure (GetApiOrderBasicInfo)
     * Parses Provinces array and extracts all Cities into flat list
     *
     * @param array $response API response
     * @return array Normalized cities with id, name, and centerLocation
     */
    private static function normalize_cities_new_api($response) {
        $cities = array();
        
        // New API format: Provinces array containing Cities
        $provinces = array();
        if (isset($response['Provinces']) && is_array($response['Provinces'])) {
            $provinces = $response['Provinces'];
        } elseif (isset($response['data']['Provinces']) && is_array($response['data']['Provinces'])) {
            $provinces = $response['data']['Provinces'];
        } elseif (isset($response['provinces']) && is_array($response['provinces'])) {
            $provinces = $response['provinces'];
        }
        
        // Extract all cities from all provinces
        foreach ($provinces as $province) {
            if (!is_array($province)) {
                continue;
            }
            
            // Get cities array from province
            $province_cities = array();
            if (isset($province['Cities']) && is_array($province['Cities'])) {
                $province_cities = $province['Cities'];
            } elseif (isset($province['cities']) && is_array($province['cities'])) {
                $province_cities = $province['cities'];
            }
            
            foreach ($province_cities as $city) {
                if (!is_array($city)) {
                    continue;
                }
                
                // Extract city ID
                $city_id = '';
                if (isset($city['CityId'])) {
                    $city_id = sanitize_text_field($city['CityId']);
                } elseif (isset($city['cityId'])) {
                    $city_id = sanitize_text_field($city['cityId']);
                } elseif (isset($city['id'])) {
                    $city_id = sanitize_text_field($city['id']);
                } else {
                    continue;
                }
                
                // Extract city name
                $city_name = '';
                if (isset($city['CityName'])) {
                    $city_name = sanitize_text_field($city['CityName']);
                } elseif (isset($city['cityName'])) {
                    $city_name = sanitize_text_field($city['cityName']);
                } elseif (isset($city['name'])) {
                    $city_name = sanitize_text_field($city['name']);
                } else {
                    $city_name = $city_id;
                }
                
                // Extract center location (lat,long format)
                $center_location = '';
                if (isset($city['CenterLocation'])) {
                    $lat = isset($city['CenterLocation']['Latitude']) 
                        ? floatval($city['CenterLocation']['Latitude']) 
                        : (isset($city['CenterLocation']['latitude']) ? floatval($city['CenterLocation']['latitude']) : 0);
                    $lng = isset($city['CenterLocation']['Longitude']) 
                        ? floatval($city['CenterLocation']['Longitude']) 
                        : (isset($city['CenterLocation']['longitude']) ? floatval($city['CenterLocation']['longitude']) : 0);
                    $center_location = $lat . ',' . $lng;
                } elseif (isset($city['centerLocation'])) {
                    $lat = isset($city['centerLocation']['Latitude']) 
                        ? floatval($city['centerLocation']['Latitude']) 
                        : (isset($city['centerLocation']['latitude']) ? floatval($city['centerLocation']['latitude']) : 0);
                    $lng = isset($city['centerLocation']['Longitude']) 
                        ? floatval($city['centerLocation']['Longitude']) 
                        : (isset($city['centerLocation']['longitude']) ? floatval($city['centerLocation']['longitude']) : 0);
                    $center_location = $lat . ',' . $lng;
                }
                
                // Build normalized city entry
                $normalized = array(
                    'id' => $city_id,
                    'name' => $city_name,
                    'centerLocation' => $center_location,
                );
                
                // Add province name if available
                if (isset($province['ProvinceName'])) {
                    $normalized['province'] = sanitize_text_field($province['ProvinceName']);
                } elseif (isset($province['provinceName'])) {
                    $normalized['province'] = sanitize_text_field($province['provinceName']);
                }
                
                $cities[] = $normalized;
            }
        }
        
        return $cities;
    }
    
    /**
     * Normalize cities format (legacy for backward compatibility)
     *
     * @param array $response API response
     * @return array Normalized cities
     */
    private static function normalize_cities($response) {
        $cities = array();
        
        // Handle different response formats
        $data = array();
        if (isset($response['cities']) && is_array($response['cities'])) {
            $data = $response['cities'];
        } elseif (isset($response['data']) && is_array($response['data'])) {
            $data = $response['data'];
        } elseif (is_array($response) && isset($response[0])) {
            $data = $response;
        }
        
        foreach ($data as $city) {
            if (!is_array($city)) {
                continue;
            }
            
            $normalized = array();
            
            // Extract ID
            if (isset($city['id'])) {
                $normalized['id'] = sanitize_text_field($city['id']);
            } elseif (isset($city['city_id'])) {
                $normalized['id'] = sanitize_text_field($city['city_id']);
            } elseif (isset($city['cityId'])) {
                $normalized['id'] = sanitize_text_field($city['cityId']);
            } else {
                continue;
            }
            
            // Extract name
            if (isset($city['name'])) {
                $normalized['name'] = sanitize_text_field($city['name']);
            } elseif (isset($city['city_name'])) {
                $normalized['name'] = sanitize_text_field($city['city_name']);
            } elseif (isset($city['cityName'])) {
                $normalized['name'] = sanitize_text_field($city['cityName']);
            } else {
                $normalized['name'] = $normalized['id'];
            }
            
            // Extract province if available
            if (isset($city['province'])) {
                $normalized['province'] = sanitize_text_field($city['province']);
            }
            
            $cities[] = $normalized;
        }
        
        return $cities;
    }
    
    /**
     * Normalize deadlines format
     *
     * @param array $response API response
     * @return array Normalized deadlines
     */
    private static function normalize_deadlines($response) {
        $deadlines = array();
        
        // Handle different response formats
        $data = array();
        if (isset($response['deadlines']) && is_array($response['deadlines'])) {
            $data = $response['deadlines'];
        } elseif (isset($response['data']) && is_array($response['data'])) {
            $data = $response['data'];
        } elseif (is_array($response) && isset($response[0])) {
            $data = $response;
        }
        
        foreach ($data as $deadline) {
            if (!is_array($deadline)) {
                continue;
            }
            
            $normalized = array();
            
            // Extract ID
            if (isset($deadline['id'])) {
                $normalized['id'] = sanitize_text_field($deadline['id']);
            } elseif (isset($deadline['deadline_id'])) {
                $normalized['id'] = sanitize_text_field($deadline['deadline_id']);
            } elseif (isset($deadline['deadlineId'])) {
                $normalized['id'] = sanitize_text_field($deadline['deadlineId']);
            } else {
                continue;
            }
            
            // Extract label
            if (isset($deadline['label'])) {
                $normalized['label'] = sanitize_text_field($deadline['label']);
            } elseif (isset($deadline['name'])) {
                $normalized['label'] = sanitize_text_field($deadline['name']);
            } elseif (isset($deadline['title'])) {
                $normalized['label'] = sanitize_text_field($deadline['title']);
            } else {
                $normalized['label'] = $normalized['id'];
            }
            
            // Extract description if available
            if (isset($deadline['description'])) {
                $normalized['description'] = sanitize_text_field($deadline['description']);
            }
            
            $deadlines[] = $normalized;
        }
        
        return $deadlines;
    }
    
    /**
     * Wait for cache to be filled by another worker
     *
     * @param string $cache_key The cache key to wait for
     * @param string $lock_key The lock key to check
     * @return array|false Cached data or false on timeout
     */
    private static function wait_for_cache($cache_key, $lock_key) {
        $wait_time = 0;
        
        while ($wait_time < self::MAX_WAIT_TIME) {
            // Check if cache is now available
            $cached = get_transient($cache_key);
            if (false !== $cached) {
                parsi_log('Cache filled by another worker', 'info');
                return $cached;
            }
            
            // Check if lock still exists, using the same store it was written to.
            // The lock is stored via wp_cache_add() when an external object cache is
            // available, otherwise via add_option(). It is NOT a transient — checking
            // get_transient() here always returned false and short-circuited the wait.
            $lock_exists = wp_using_ext_object_cache()
                ? (false !== wp_cache_get($lock_key))
                : (false !== get_option($lock_key, false));
            if (!$lock_exists) {
                parsi_log('Lock released, proceeding to fetch', 'warning');
                return false;
            }
            
            // Wait before checking again
            usleep(self::POLL_INTERVAL);
            $wait_time += self::POLL_INTERVAL;
        }
        
        parsi_log('Lock timeout reached', 'warning');
        // Try to return stale cache if available
        $stale_cache = get_transient($cache_key . '_stale');
        if (false !== $stale_cache) {
            parsi_log('Serving stale cache after lock timeout', 'info');
            return $stale_cache;
        }
        
        return false;
    }
    
    /**
     * Release a lock safely
     *
     * @param string $lock_key The lock key to release
     * @return void
     */
    private static function release_lock($lock_key) {
        if (wp_using_ext_object_cache()) {
            wp_cache_delete($lock_key);
        } else {
            delete_option($lock_key);
        }
        parsi_log('Lock released: ' . $lock_key, 'info');
    }
    
    /**
     * Clear all cached data including locks and stale cache
     *
     * @return void
     */
    public static function clear_cache() {
        delete_transient('parsi_cities_list');
        delete_transient('parsi_deadlines_list');
        delete_transient('parsi_cities_list_stale');
        delete_transient('parsi_deadlines_list_stale');
        delete_option('parsi_cities_fetch_lock');
        delete_option('parsi_deadlines_fetch_lock');
        
        // Also clear from object cache if available
        if (wp_using_ext_object_cache()) {
            wp_cache_delete('parsi_cities_list');
            wp_cache_delete('parsi_deadlines_list');
            wp_cache_delete('parsi_cities_fetch_lock');
            wp_cache_delete('parsi_deadlines_fetch_lock');
        }
        
        parsi_log('All PARSI API cache cleared', 'info');
    }
}
