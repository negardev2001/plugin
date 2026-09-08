

/**
 * PARSI Admin Orders JavaScript
 * Handles quick toggle of Parsi Post shipping in orders list
 */

(function($) {
    'use strict';

    // Validate required variables exist
    if (typeof parsiAdminOrders === 'undefined') {
        console.error('Parsi Admin Orders: parsiAdminOrders object not defined');
        return;
    }

    // Handle checkbox toggle
    $(document).on('change', '.parsi-shipping-checkbox', function() {
        var $checkbox = $(this);
        var orderId = $checkbox.data('order-id');
        var nonce = $checkbox.data('nonce');
        var $container = $checkbox.closest('.parsi-shipping-toggle');
        var $toggleText = $container.find('.parsi-toggle-text');
        var originalText = $toggleText.html();
        
        // Validate required data attributes
        if (!orderId || !nonce) {
            console.error('Parsi Admin Orders: Missing required data attributes');
            return;
        }
        
        // Show loading state
        var loadingText = $checkbox.prop('checked') ? parsiAdminOrders.strings.enabling : parsiAdminOrders.strings.disabling;
        $toggleText.html('<span style="color: #999;">' + loadingText + '</span>');
        $checkbox.prop('disabled', true);
        
        // Send AJAX request
        $.ajax({
            url: parsiAdminOrders.ajaxUrl,
            type: 'POST',
            data: {
                action: 'parsi_toggle_shipping',
                order_id: orderId,
                nonce: nonce
            },
            success: function(response) {
                $checkbox.prop('disabled', false);
                
                if (response.success) {
                    // Update UI based on new state
                    if (response.data.enabled) {
                        var statusText = response.data.status_text;
                        var html = '<span class="parsi-status parsi-active" style="color: #46b450; font-weight: bold;">✓ ' + statusText + '</span>';
                        
                        // Add tracking info if available
                        if (response.data.tracking_number) {
                            html += '<div class="parsi-tracking-info"><small style="color: #666;">' + 
                                    parsiAdminOrders.strings.tracking + ' ' + 
                                    response.data.tracking_number + 
                                    '</small></div>';
                        }
                        
                        $toggleText.html(html);
                    } else {
                        $toggleText.html('<span class="parsi-status parsi-inactive" style="color: #999;">' + 
                                        parsiAdminOrders.strings.inactive + '</span>');
                        // Remove tracking info div if exists
                        $container.find('.parsi-tracking-info').remove();
                    }
                } else {
                    // Revert on error
                    $checkbox.prop('checked', !$checkbox.prop('checked'));
                    $toggleText.html(originalText);
                    alert(response.data.message || parsiAdminOrders.strings.error);
                }
            },
            error: function(xhr, status, error) {
                $checkbox.prop('disabled', false);
                $checkbox.prop('checked', !$checkbox.prop('checked'));
                $toggleText.html(originalText);
                
                // Log error for debugging
                console.error('Parsi Admin Orders: AJAX error', {
                    status: status,
                    error: error,
                    responseText: xhr.responseText
                });
                
                alert(parsiAdminOrders.strings.error);
            }
        });
    });

})(jQuery);
