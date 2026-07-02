/**
 * PARSI Order Meta Box JavaScript
 *
 * Handles client-side functionality for the shipment meta box
 *
 * @package PARSI
 */

jQuery(document).ready(function($) {
    'use strict';
    
    // Toggle fields based on shipment type
    function toggleShipmentFields() {
        var shipmentType = $('input[name="parsi_shipment_type"]:checked').val();
        
        if (shipmentType === 'parcel') {
            $('#parsi_parcel_fields').slideDown(300);
            $('#parsi_packet_options').hide();
            $('#parsi_parcel_options').show();
            
            // Make dimensions required
            $('#parsi_length, #parsi_width, #parsi_height').prop('required', true);
            
            // Update package type label
            $('label[for="parsi_package_type"]').text('نوع بسته‌بندی');
        } else {
            $('#parsi_parcel_fields').slideUp(300);
            $('#parsi_packet_options').show();
            $('#parsi_parcel_options').hide();
            
            // Remove required from dimensions
            $('#parsi_length, #parsi_width, #parsi_height').prop('required', false);
            
            // Update package type label
            $('label[for="parsi_package_type"]').text('نوع بسته‌بندی');
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
            
            // Show notice
            if (!$('.parsi-notice').is(':visible')) {
                $('.parsi-notice').slideDown(300);
            }
        } else {
            // Enable all insurance options
            $('option', insuranceSelect).prop('disabled', false);
            $('.parsi-notice').slideUp(300);
        }
    }
    
    // Validate form fields
    function validateForm() {
        var isValid = true;
        var shipmentType = $('input[name="parsi_shipment_type"]:checked').val();
        
        // Clear previous errors
        $('.parsi-field-error').remove();
        $('.parsi-input, .parsi-select').removeClass('error');
        
        // Validate shipment type
        if (!shipmentType) {
            showError('parsi_shipment_type', 'نوع مرسوله الزامی است.');
            isValid = false;
        }
        
        // Validate weight
        var weight = $('#parsi_weight').val();
        if (!weight || parseInt(weight) <= 0) {
            showError('parsi_weight', 'وزن مرسوله الزامی است.');
            isValid = false;
        }
        
        // Validate contents
        var contents = $('#parsi_contents').val();
        if (!contents.trim()) {
            showError('parsi_contents', 'توضیحات محتویات الزامی است.');
            isValid = false;
        }
        
        // Validate package type
        var packageType = $('#parsi_package_type').val();
        if (!packageType) {
            showError('parsi_package_type', 'نوع بسته‌بندی الزامی است.');
            isValid = false;
        }
        
        // Validate dimensions for parcel
        if (shipmentType === 'parcel') {
            var length = $('#parsi_length').val();
            var width = $('#parsi_width').val();
            var height = $('#parsi_height').val();
            
            if (!length || parseInt(length) <= 0) {
                showError('parsi_length', 'طول مرسوله الزامی است.');
                isValid = false;
            }
            
            if (!width || parseInt(width) <= 0) {
                showError('parsi_width', 'عرض مرسوله الزامی است.');
                isValid = false;
            }
            
            if (!height || parseInt(height) <= 0) {
                showError('parsi_height', 'ارتفاع مرسوله الزامی است.');
                isValid = false;
            }
        }
        
        return isValid;
    }
    
    // Show error message for a field
    function showError(fieldId, message) {
        var field = $('#' + fieldId);
        field.addClass('error');
        field.after('<div class="parsi-field-error">' + message + '</div>');
    }
    
    // Save shipment data via AJAX
    function saveShipmentData() {
        if (!validateForm()) {
            return;
        }
        
        var orderId = $('#post_ID').val();
        if (!orderId) {
            alert('شناسه سفارش یافت نشد.');
            return;
        }
        
        // Confirm save
        if (!confirm(parsiOrderMeta.strings.confirmSave)) {
            return;
        }
        
        // Show loading state
        var saveButton = $('#parsi_save_shipment');
        var originalText = saveButton.text();
        saveButton.prop('disabled', true).text(parsiOrderMeta.strings.saving);
        $('#parsi_save_status').removeClass('success error').text('');
        
        // Prepare form data
        var formData = {
            action: 'parsi_save_shipment_data',
            order_id: orderId,
            nonce: parsiOrderMeta.nonce,
            parsi_shipment_type: $('input[name="parsi_shipment_type"]:checked').val(),
            parsi_weight: $('#parsi_weight').val(),
            parsi_length: $('#parsi_length').val(),
            parsi_width: $('#parsi_width').val(),
            parsi_height: $('#parsi_height').val(),
            parsi_contents: $('#parsi_contents').val(),
            parsi_declared_value: $('#parsi_declared_value').val(),
            parsi_package_type: $('#parsi_package_type').val(),
            parsi_packaging_cost: $('#parsi_packaging_cost').val(),
            parsi_insurance_type: $('#parsi_insurance_type').val()
        };
        
        // Send AJAX request
        $.ajax({
            url: parsiOrderMeta.ajaxUrl,
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#parsi_save_status').addClass('success').text(response.data.message || parsiOrderMeta.strings.saved);
                    
                    // Remove any unsaved changes warning
                    if (typeof unsavedChanges !== 'undefined') {
                        unsavedChanges = false;
                    }
                } else {
                    $('#parsi_save_status').addClass('error').text(response.data.message || parsiOrderMeta.strings.error);
                }
            },
            error: function(xhr, status, error) {
                var errorMessage = parsiOrderMeta.strings.error;
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMessage = xhr.responseJSON.data.message;
                }
                $('#parsi_save_status').addClass('error').text(errorMessage);
            },
            complete: function() {
                // Restore button state
                setTimeout(function() {
                    saveButton.prop('disabled', false).text(originalText);
                    $('#parsi_save_status').fadeOut(3000, function() {
                        $(this).text('').removeClass('success error');
                    });
                }, 1000);
            }
        });
    }
    
    // Initialize on page load
    function initialize() {
        // Set initial field visibility
        toggleShipmentFields();
        checkInsuranceRequirement();
        
        // Bind event handlers
        $('input[name="parsi_shipment_type"]').on('change', toggleShipmentFields);
        $('#parsi_declared_value').on('input change', checkInsuranceRequirement);
        $('#parsi_save_shipment').on('click', saveShipmentData);
        
        // Add keyboard shortcut for save (Ctrl+S)
        $(document).on('keydown', function(e) {
            if (e.ctrlKey && e.keyCode === 83) {
                e.preventDefault();
                saveShipmentData();
            }
        });
        
        // Auto-save on field change (optional - can be enabled/disabled)
        // Uncomment the following lines if you want auto-save functionality
        /*
        var autoSaveTimer;
        $('.parsi-input, .parsi-select').on('input change', function() {
            clearTimeout(autoSaveTimer);
            autoSaveTimer = setTimeout(function() {
                saveShipmentData();
            }, 3000); // Auto-save after 3 seconds of inactivity
        });
        */
    }
    
    // Run initialization
    initialize();
    
    // Expose functions globally for potential use by other scripts
    window.ParsiOrderMetaBox = {
        toggleShipmentFields: toggleShipmentFields,
        checkInsuranceRequirement: checkInsuranceRequirement,
        validateForm: validateForm,
        saveShipmentData: saveShipmentData
    };
});