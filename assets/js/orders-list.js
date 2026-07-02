/**
 * PARSI Orders List JavaScript
 * Handles order status updates and re-registration
 */

(function($) {
    'use strict';

    // Validate required variables exist
    if (typeof parsiOrdersList === 'undefined') {
        console.error('Parsi Orders List: parsiOrdersList object not defined');
        return;
    }

    // Handle update status button click
    $(document).on('click', '.parsi-update-status-btn', function() {
        var $button = $(this);
        var orderId = $button.data('order-id');
        var nonce = $button.data('nonce');
        var loadingText = $button.data('loading-text');
        var originalText = $button.text();
        var $row = $button.closest('tr');
        var $statusCell = $row.find('.column-status');
        
        // Validate required data attributes
        if (!orderId || !nonce) {
            console.error('Parsi Orders List: Missing required data attributes');
            return;
        }
        
        // Show loading state
        $button.prop('disabled', true).text(loadingText);
        
        // Send AJAX request
        $.ajax({
            url: parsiOrdersList.ajaxUrl,
            type: 'POST',
            data: {
                action: 'parsi_update_order_status',
                order_id: orderId,
                nonce: nonce
            },
            success: function(response) {
                $button.prop('disabled', false).text(originalText);
                
                if (response.success) {
                    // Update status cell
                    var newStatus = response.data.status;
                    
                    // Determine status color
                    var color = '#999';
                    if (newStatus.indexOf('ثبت') !== -1 || newStatus.indexOf('registered') !== -1) {
                        color = '#0073aa';
                    } else if (newStatus.indexOf('ارسال') !== -1 || newStatus.indexOf('shipped') !== -1 || newStatus.indexOf('در حال') !== -1) {
                        color = '#ff9000';
                    } else if (newStatus.indexOf('تحویل') !== -1 || newStatus.indexOf('delivered') !== -1) {
                        color = '#46b450';
                    } else if (newStatus.indexOf('خطا') !== -1 || newStatus.indexOf('error') !== -1 || newStatus.indexOf('ناموفق') !== -1) {
                        color = '#dc3232';
                    }
                    
                    $statusCell.html('<span style="color: ' + color + '; font-weight: bold;">' + newStatus + '</span>');
                    
                    // Show success message
                    alert(response.data.message || parsiOrdersList.strings.updateSuccess);
                    
                    // If order is delivered, disable re-register button
                    if (color === '#46b450') {
                        $row.find('.parsi-reregister-btn').prop('disabled', true).hide();
                    }
                } else {
                    alert(response.data.message || parsiOrdersList.strings.updateError);
                }
            },
            error: function(xhr, status, error) {
                $button.prop('disabled', false).text(originalText);
                
                // Log error for debugging
                console.error('Parsi Orders List: AJAX error', {
                    status: status,
                    error: error,
                    responseText: xhr.responseText
                });
                
                alert(parsiOrdersList.strings.updateError);
            }
        });
    });

    // Handle re-register button click
    $(document).on('click', '.parsi-reregister-btn', function() {
        var $button = $(this);
        var orderId = $button.data('order-id');
        var nonce = $button.data('nonce');
        var loadingText = $button.data('loading-text');
        var originalText = $button.text();
        var $row = $button.closest('tr');
        var $trackingCell = $row.find('.column-tracking-code');
        
        // Validate required data attributes
        if (!orderId || !nonce) {
            console.error('Parsi Orders List: Missing required data attributes');
            return;
        }
        
        // Confirm action
        if (!confirm(parsiOrdersList.strings.confirmReregister)) {
            return;
        }
        
        // Show loading state
        $button.prop('disabled', true).text(loadingText);
        
        // Send AJAX request
        $.ajax({
            url: parsiOrdersList.ajaxUrl,
            type: 'POST',
            data: {
                action: 'parsi_reregister_order',
                order_id: orderId,
                nonce: nonce
            },
            success: function(response) {
                $button.prop('disabled', false).text(originalText);
                
                if (response.success) {
                    // Update tracking code cell
                    var newTracking = response.data.tracking_number;
                    if (newTracking) {
                        $trackingCell.text(newTracking);
                    }
                    
                    // Show success message
                    alert(response.data.message || parsiOrdersList.strings.reregisterSuccess);
                    
                    // Reload page to show updated data
                    location.reload();
                } else {
                    alert(response.data.message || parsiOrdersList.strings.reregisterError);
                }
            },
            error: function(xhr, status, error) {
                $button.prop('disabled', false).text(originalText);
                
                // Log error for debugging
                console.error('Parsi Orders List: AJAX error', {
                    status: status,
                    error: error,
                    responseText: xhr.responseText
                });
                
                alert(parsiOrdersList.strings.reregisterError);
            }
        });
    });

})(jQuery);
