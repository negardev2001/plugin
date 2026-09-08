<?php
/**
 * Shipment Meta Box Template
 *
 * @package PARSI
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get meta box instance
$meta_box = PARSI_Order_Meta_Box::get_instance();

// Get current shipment data. When no shipment type was saved yet, derive the
// default from the package weight (heavy orders → بسته) instead of always
// defaulting to پاکت, so the packaging options match the real parcel.
$shipment_type = $meta_box->resolve_default_shipment_type(
    isset($order) ? $order : false,
    $shipment_data['shipment_type'],
    (float) $shipment_data['weight']
);
$weight = $shipment_data['weight'] ?: '';
$length = $shipment_data['length'] ?: '';
$width = $shipment_data['width'] ?: '';
$height = $shipment_data['height'] ?: '';
$contents = $shipment_data['contents'] ?: '';
$declared_value = $shipment_data['declared_value'] ?: '';
$package_type = $shipment_data['package_type'] ?: '';
$packaging_cost = $shipment_data['packaging_cost'] ?: '';
$insurance_type = $shipment_data['insurance_type'] ?: 'mandatory';

// Get options
$packet_package_options = $meta_box->get_packet_package_options();
$parcel_package_options = $meta_box->get_parcel_package_options();
$insurance_options = $meta_box->get_insurance_options();

// Check if insurance should be forced to mandatory
$force_mandatory_insurance = (empty($declared_value) || intval($declared_value) < 5000000);
?>

<div class="parsi-shipment-meta-box">
    <div class="parsi-shipment-fields">
        <!-- Shipment Type -->
        <div class="parsi-field-row">
            <div class="parsi-field-group">
                <label class="parsi-field-label">
                    <?php esc_html_e('نوع مرسوله', 'parsi'); ?> <span class="required">*</span>
                </label>
                <div class="parsi-radio-group">
                    <label class="parsi-radio-label">
                        <input type="radio" name="parsi_shipment_type" value="packet" <?php checked($shipment_type, 'packet'); ?> class="parsi-shipment-type" />
                        <?php esc_html_e('پاکت', 'parsi'); ?>
                    </label>
                    <label class="parsi-radio-label">
                        <input type="radio" name="parsi_shipment_type" value="parcel" <?php checked($shipment_type, 'parcel'); ?> class="parsi-shipment-type" />
                        <?php esc_html_e('بسته', 'parsi'); ?>
                    </label>
                </div>
            </div>
        </div>

        <!-- Common Fields (Both Packet and Parcel) -->
        <div class="parsi-field-row">
            <div class="parsi-field-group">
                <label for="parsi_weight" class="parsi-field-label">
                    <?php esc_html_e('وزن (گرم)', 'parsi'); ?> <span class="required">*</span>
                </label>
                <input type="number" id="parsi_weight" name="parsi_weight" value="<?php echo esc_attr($weight); ?>" 
                       class="parsi-input" min="1" step="1" required />
            </div>
            
            <div class="parsi-field-group">
                <label for="parsi_contents" class="parsi-field-label">
                    <?php esc_html_e('توضیحات محتویات', 'parsi'); ?> <span class="required">*</span>
                </label>
                <textarea id="parsi_contents" name="parsi_contents" class="parsi-input" rows="3" required><?php echo esc_textarea($contents); ?></textarea>
            </div>
        </div>

        <div class="parsi-field-row">
            <div class="parsi-field-group">
                <label for="parsi_declared_value" class="parsi-field-label">
                    <?php esc_html_e('ارزش اعلامی (ریال)', 'parsi'); ?>
                </label>
                <input type="number" id="parsi_declared_value" name="parsi_declared_value" 
                       value="<?php echo esc_attr($declared_value); ?>" class="parsi-input" min="0" step="1" />
                <p class="parsi-field-description">
                    <?php esc_html_e('اگر ارزش مرسوله کمتر از ۵,۰۰۰,۰۰۰ ریال باشد، بیمه اجباری اعمال می‌شود.', 'parsi'); ?>
                </p>
            </div>
            
            <div class="parsi-field-group">
                <label for="parsi_packaging_cost" class="parsi-field-label">
                    <?php esc_html_e('هزینه بسته‌بندی (ریال)', 'parsi'); ?>
                </label>
                <input type="number" id="parsi_packaging_cost" name="parsi_packaging_cost" 
                       value="<?php echo esc_attr($packaging_cost); ?>" class="parsi-input" min="0" step="1" />
            </div>
        </div>

        <!-- Package Type (Different options for packet vs parcel) -->
        <div class="parsi-field-row">
            <div class="parsi-field-group">
                <label for="parsi_package_type" class="parsi-field-label">
                    <?php esc_html_e('نوع بسته‌بندی', 'parsi'); ?> <span class="required">*</span>
                </label>
                <select id="parsi_package_type" name="parsi_package_type" class="parsi-select" required>
                    <option value=""><?php esc_html_e('انتخاب کنید...', 'parsi'); ?></option>
                    
                    <!-- Packet Options -->
                    <optgroup id="parsi_packet_options" label="<?php esc_attr_e('گزینه‌های پاکت', 'parsi'); ?>" style="display:<?php echo $shipment_type === 'packet' ? 'block' : 'none'; ?>;">
                        <?php foreach ($packet_package_options as $key => $label): ?>
                            <option value="<?php echo esc_attr($key); ?>" <?php selected($package_type, $key); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                    
                    <!-- Parcel Options -->
                    <optgroup id="parsi_parcel_options" label="<?php esc_attr_e('گزینه‌های بسته', 'parsi'); ?>" style="display:<?php echo $shipment_type === 'parcel' ? 'block' : 'none'; ?>;">
                        <?php foreach ($parcel_package_options as $key => $label): ?>
                            <option value="<?php echo esc_attr($key); ?>" <?php selected($package_type, $key); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
            </div>
            
            <div class="parsi-field-group">
                <label for="parsi_insurance_type" class="parsi-field-label">
                    <?php esc_html_e('نوع بیمه', 'parsi'); ?> <span class="required">*</span>
                </label>
                <select id="parsi_insurance_type" name="parsi_insurance_type" class="parsi-select" required>
                    <?php foreach ($insurance_options as $key => $label): ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($insurance_type, $key); ?> <?php disabled($force_mandatory_insurance && $key !== 'mandatory'); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($force_mandatory_insurance): ?>
                    <div class="parsi-notice">
                        <?php esc_html_e('با توجه به ارزش مرسوله، بیمه اجباری اعمال شد.', 'parsi'); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Parcel-Specific Fields (Dimensions) -->
        <div id="parsi_parcel_fields" style="display:<?php echo $shipment_type === 'parcel' ? 'block' : 'none'; ?>;">
            <div class="parsi-field-row">
                <div class="parsi-field-group">
                    <label for="parsi_length" class="parsi-field-label">
                        <?php esc_html_e('طول (سانتی‌متر)', 'parsi'); ?> <span class="required">*</span>
                    </label>
                    <input type="number" id="parsi_length" name="parsi_length" value="<?php echo esc_attr($length); ?>" 
                           class="parsi-input" min="1" step="1" />
                </div>
                
                <div class="parsi-field-group">
                    <label for="parsi_width" class="parsi-field-label">
                        <?php esc_html_e('عرض (سانتی‌متر)', 'parsi'); ?> <span class="required">*</span>
                    </label>
                    <input type="number" id="parsi_width" name="parsi_width" value="<?php echo esc_attr($width); ?>" 
                           class="parsi-input" min="1" step="1" />
                </div>
                
                <div class="parsi-field-group">
                    <label for="parsi_height" class="parsi-field-label">
                        <?php esc_html_e('ارتفاع (سانتی‌متر)', 'parsi'); ?> <span class="required">*</span>
                    </label>
                    <input type="number" id="parsi_height" name="parsi_height" value="<?php echo esc_attr($height); ?>" 
                           class="parsi-input" min="1" step="1" />
                </div>
            </div>
        </div>
    </div>

    <!-- Save Button -->
    <div class="parsi-field-row">
        <div class="parsi-field-group">
            <button type="button" id="parsi_save_shipment" class="parsi-button parsi-button-primary">
                <?php esc_html_e('ذخیره اطلاعات مرسوله', 'parsi'); ?>
            </button>
            <span id="parsi_save_status" class="parsi-save-status"></span>
        </div>
    </div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    // Toggle fields based on shipment type
    function toggleShipmentFields() {
        var shipmentType = $('input[name="parsi_shipment_type"]:checked').val();

        if (shipmentType === 'parcel') {
            $('#parsi_parcel_fields').show();
            $('#parsi_packet_options').hide();
            $('#parsi_parcel_options').show();

            // Make dimensions required
            $('#parsi_length, #parsi_width, #parsi_height').prop('required', true);
        } else {
            $('#parsi_parcel_fields').hide();
            $('#parsi_packet_options').show();
            $('#parsi_parcel_options').hide();

            // Remove required from dimensions
            $('#parsi_length, #parsi_width, #parsi_height').prop('required', false);
        }

        // Reset a packaging option that belongs to the now-hidden group so the
        // packaging can never mismatch the shipment type (e.g. a پاکت box left
        // selected on a بسته shipment). Runs on load and on every type change.
        var visibleGroup = (shipmentType === 'parcel') ? 'parsi_parcel_options' : 'parsi_packet_options';
        var selectedOption = $('#parsi_package_type option:selected');
        if (selectedOption.val() && selectedOption.closest('optgroup').attr('id') !== visibleGroup) {
            $('#parsi_package_type').val('');
        }
    }
    
    // Check insurance requirement based on declared value
    function checkInsuranceRequirement() {
        var declaredValue = parseInt($('#parsi_declared_value').val()) || 0;
        var insuranceSelect = $('#parsi_insurance_type');
        var currentSelection = insuranceSelect.val();
        
        if (declaredValue < 5000000 || declaredValue === 0) {
            // Force mandatory insurance
            insuranceSelect.val('mandatory').prop('disabled', false);
            $('option[value!="mandatory"]', insuranceSelect).prop('disabled', true);
            
            // Show notice if it wasn't already mandatory
            if (currentSelection !== 'mandatory') {
                $('.parsi-notice').show();
            }
        } else {
            // Enable all insurance options
            $('option', insuranceSelect).prop('disabled', false);
            $('.parsi-notice').hide();
        }
    }
    
    // Initialize on page load
    toggleShipmentFields();
    checkInsuranceRequirement();
    
    // Handle shipment type change
    $('input[name="parsi_shipment_type"]').on('change', toggleShipmentFields);
    
    // Handle declared value change
    $('#parsi_declared_value').on('input change', checkInsuranceRequirement);
});
</script>