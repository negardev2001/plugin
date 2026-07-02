<?php
/**
 * PARSI API IDs Configuration Class
 *
 * @package PARSI
 *
 * Handles validation and retrieval of required API UUIDs
 * with no hardcoded fallbacks for production safety.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * PARSI API IDs Config Class
 * 
 * Manages all required API UUIDs with proper validation
 * and fails fast if configuration is incomplete.
 */
class PARSI_API_IDs_Config {
    
    /**
     * Required API IDs that must be configured
     *
     * @var array
     */
    const REQUIRED_IDS = array(
        'contract_id',
        'payment_type_id',
        'service_type_id',
        'deadline_id',
        'parcel_type_id',
        'box_type_id',
        'insurance_type_id',
        'sender_city_id',
    );
    
    /**
     * Optional API IDs (not required for basic operation)
     *
     * @var array
     */
    const OPTIONAL_IDS = array(
        'default_city_id',
        'default_deadline_id',
    );
    
    /**
     * Get a required API ID with validation
     *
     * @param string $key The ID key (e.g., 'contract_id')
     * @return string The validated UUID
     * @throws Exception If ID is not configured or invalid
     */
    public static function get_required_id($key) {
        // Validate key
        if (!in_array($key, self::REQUIRED_IDS, true)) {
            throw new Exception("Invalid API ID key: {$key}");
        }
        
        // Get option value
        $option_key = 'parsi_' . $key;
        $value = get_option($option_key, '');
        
        // Validate value exists
        if (empty($value)) {
            throw new Exception("Required API ID not configured: {$key}");
        }
        
        // Validate UUID format
        if (!self::validate_uuid($value)) {
            throw new Exception("Invalid UUID format for {$key}: {$value}");
        }
        
        return $value;
    }
    
    /**
     * Get an optional API ID with validation
     *
     * @param string $key The ID key
     * @param string $default Default value if not configured
     * @return string The validated UUID or default
     */
    public static function get_optional_id($key, $default = '') {
        // Validate key
        if (!in_array($key, self::OPTIONAL_IDS, true)) {
            throw new Exception("Invalid optional API ID key: {$key}");
        }
        
        // Get option value
        $option_key = 'parsi_' . $key;
        $value = get_option($option_key, $default);
        
        // If value is empty, return default
        if (empty($value)) {
            return $default;
        }
        
        // Validate UUID format if not default
        if ($value !== $default && !self::validate_uuid($value)) {
            throw new Exception("Invalid UUID format for {$key}: {$value}");
        }
        
        return $value;
    }
    
    /**
     * Validate UUID format
     *
     * @param string $uuid UUID to validate
     * @return bool True if valid UUID format
     */
    public static function validate_uuid($uuid) {
        if (empty($uuid) || !is_string($uuid)) {
            return false;
        }
        
        // Validate UUID v4 format (8-4-4-4-12 hex digits)
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid) === 1;
    }
    
    /**
     * Validate all required API IDs
     *
     * @return array Array of missing or invalid IDs
     */
    public static function validate_all_required_ids() {
        $errors = array();
        
        foreach (self::REQUIRED_IDS as $key) {
            try {
                self::get_required_id($key);
            } catch (Exception $e) {
                $errors[$key] = $e->getMessage();
            }
        }
        
        return $errors;
    }
    
    /**
     * Get missing required IDs
     *
     * @return array Array of missing ID keys
     */
    public static function get_missing_required_ids() {
        $missing = array();
        
        foreach (self::REQUIRED_IDS as $key) {
            $option_key = 'parsi_' . $key;
            $value = get_option($option_key, '');
            
            if (empty($value)) {
                $missing[] = $key;
            } elseif (!self::validate_uuid($value)) {
                $missing[] = $key . ' (invalid format)';
            }
        }
        
        return $missing;
    }
    
    /**
     * Check if all required IDs are configured
     *
     * @return bool True if all required IDs are configured and valid
     */
    public static function is_configured() {
        return empty(self::get_missing_required_ids());
    }
    
    /**
     * Get configuration status
     *
     * @return array Configuration status with details
     */
    public static function get_configuration_status() {
        $status = array(
            'configured' => true,
            'required' => array(),
            'optional' => array(),
            'missing' => array(),
            'invalid' => array(),
        );
        
        // Check required IDs
        foreach (self::REQUIRED_IDS as $key) {
            $option_key = 'parsi_' . $key;
            $value = get_option($option_key, '');
            
            $status['required'][$key] = array(
                'configured' => !empty($value),
                'valid' => self::validate_uuid($value),
                'value' => $value,
            );
            
            if (empty($value)) {
                $status['missing'][] = $key;
                $status['configured'] = false;
            } elseif (!self::validate_uuid($value)) {
                $status['invalid'][] = $key;
                $status['configured'] = false;
            }
        }
        
        // Check optional IDs
        foreach (self::OPTIONAL_IDS as $key) {
            $option_key = 'parsi_' . $key;
            $value = get_option($option_key, '');
            
            $status['optional'][$key] = array(
                'configured' => !empty($value),
                'valid' => empty($value) || self::validate_uuid($value),
                'value' => $value,
            );
        }
        
        return $status;
    }
    
    /**
     * Get human-readable error message for missing IDs
     *
     * @return string Error message
     */
    public static function get_configuration_error_message() {
        $missing = self::get_missing_required_ids();
        
        if (empty($missing)) {
            return '';
        }
        
        $message = __('PARSI Plugin is not fully configured. The following API IDs are missing or invalid:', 'parsi');
        $message .= '<ul>';
        
        foreach ($missing as $id) {
            $message .= '<li>' . esc_html($id) . '</li>';
        }
        
        $message .= '</ul>';
        $message .= '<p>' . sprintf(
            __('Please <a href="%s">configure these settings</a> to enable PARSI shipping functionality.', 'parsi'),
            admin_url('admin.php?page=parsi-settings')
        ) . '</p>';
        
        return $message;
    }
    
    /**
     * Sanitize UUID option
     *
     * @param string $value Raw UUID value
     * @param string $key Option key for error messages
     * @return string|false Sanitized UUID or false on error
     */
    public static function sanitize_uuid_option($value, $key) {
        if (empty($value)) {
            return ''; // Allow empty value (will be caught by validation)
        }
        
        if (!self::validate_uuid($value)) {
            add_settings_error(
                'parsi_' . $key,
                'invalid_uuid',
                sprintf(__('Invalid UUID format for %s. Please enter a valid UUID (e.g., 12345678-1234-1234-1234-123456789012).', 'parsi'), $key)
            );
            return false;
        }
        
        return sanitize_text_field($value);
    }
}