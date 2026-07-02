/**
 * PARSI City Autocomplete
 *
 * Handles city search functionality for admin settings
 *
 * @package PARSI
 */

(function($) {
    'use strict';

    /**
     * PARSI City Autocomplete Class
     */
    var PARSI_City_Autocomplete = {
        
        /**
         * Initialize the autocomplete functionality
         */
        init: function() {
            this.bindEvents();
            this.createDropdown();
        },
        
        /**
         * Bind events to the city input field
         */
        bindEvents: function() {
            var self = this;
            
            // Handle city input field
            $(document).on('input', '#parsi_origin_city_input', function(e) {
                var $input = $(this);
                var search = $input.val().trim();
                
                if (search.length >= 2) {
                    self.searchCities(search, $input);
                } else {
                    self.hideDropdown();
                }
            });
            
            // Hide dropdown when clicking outside
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.parsi-city-autocomplete').length) {
                    self.hideDropdown();
                }
            });
            
            // Handle keyboard navigation
            $(document).on('keydown', '#parsi_origin_city_input', function(e) {
                self.handleKeyNavigation(e);
            });
            
            // Handle city selection
            $(document).on('click', '.parsi-city-option', function(e) {
                e.preventDefault();
                self.selectCity($(this));
            });
        },
        
        /**
         * Create dropdown element
         */
        createDropdown: function() {
            if ($('.parsi-city-dropdown').length === 0) {
                var dropdown = $('<div class="parsi-city-dropdown"></div>').css({
                    'display': 'none',
                    'position': 'absolute',
                    'z-index': '999999',
                    'background': '#fff',
                    'border': '1px solid #ddd',
                    'border-top': 'none',
                    'max-height': '200px',
                    'overflow-y': 'auto',
                    'min-width': '250px'
                });
                
                $('#parsi_origin_city_input').after(dropdown);
            }
        },
        
        /**
         * Search cities via AJAX
         */
        searchCities: function(search, $input) {
            var self = this;
            var $dropdown = $('.parsi-city-dropdown');
            
            // Show loading state
            $dropdown.html('<div class="parsi-city-loading">در حال جستجو...</div>');
            self.positionDropdown($input, $dropdown);
            $dropdown.show();
            
            // Abort previous request if exists
            if (self.currentRequest) {
                self.currentRequest.abort();
            }
            
            // Make AJAX request
            self.currentRequest = $.ajax({
                url: parsiAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'parsi_search_cities',
                    search: search,
                    nonce: parsiAdmin.nonce
                },
                beforeSend: function() {
                    $dropdown.find('.parsi-city-loading').show();
                },
                success: function(response) {
                    if (response.success && response.data) {
                        self.displayResults(response.data, $input);
                    } else {
                        self.displayNoResults();
                    }
                },
                error: function(xhr, status, error) {
                    if (status !== 'abort') {
                        self.displayError();
                    }
                },
                complete: function() {
                    self.currentRequest = null;
                }
            });
        },
        
        /**
         * Display search results
         */
        displayResults: function(cities, $input) {
            var $dropdown = $('.parsi-city-dropdown');
            var $results = $('<div class="parsi-city-results"></div>');
            
            if (cities.length === 0) {
                this.displayNoResults();
                return;
            }
            
            $.each(cities, function(cityId, cityData) {
                var $option = $('<a href="#" class="parsi-city-option"></a>')
                    .attr('data-city-id', cityId)
                    .attr('data-city-name', cityData.name)
                    .attr('data-city-province', cityData.province)
                    .html(
                        '<strong>' + cityData.name + '</strong>' +
                        '<small>(' + cityData.province + ')</small>'
                    );
                
                $results.append($option);
            });
            
            $dropdown.html($results);
            this.positionDropdown($input, $dropdown);
        },
        
        /**
         * Display no results message
         */
        displayNoResults: function() {
            var $dropdown = $('.parsi-city-dropdown');
            $dropdown.html('<div class="parsi-city-no-results">شهری یافت نشد</div>');
        },
        
        /**
         * Display error message
         */
        displayError: function() {
            var $dropdown = $('.parsi-city-dropdown');
            $dropdown.html('<div class="parsi-city-error">خطا در جستجو</div>');
        },
        
        /**
         * Position dropdown below input
         */
        positionDropdown: function($input, $dropdown) {
            var offset = $input.offset();
            var height = $input.outerHeight();
            
            $dropdown.css({
                'top': offset.top + height + 'px',
                'left': offset.left + 'px',
                'width': $input.outerWidth() + 'px'
            });
        },
        
        /**
         * Hide dropdown
         */
        hideDropdown: function() {
            $('.parsi-city-dropdown').hide();
        },
        
        /**
         * Handle keyboard navigation
         */
        handleKeyNavigation: function(e) {
            var $dropdown = $('.parsi-city-dropdown');
            var $options = $dropdown.find('.parsi-city-option');
            var $current = $options.filter('.active');
            
            switch (e.which) {
                case 40: // Down arrow
                    e.preventDefault();
                    if ($current.length === 0) {
                        $options.first().addClass('active');
                    } else {
                        $current.removeClass('active');
                        $current.next().addClass('active');
                    }
                    break;
                    
                case 38: // Up arrow
                    e.preventDefault();
                    if ($current.length === 0) {
                        $options.last().addClass('active');
                    } else {
                        $current.removeClass('active');
                        $current.prev().addClass('active');
                    }
                    break;
                    
                case 13: // Enter
                    e.preventDefault();
                    if ($current.length > 0) {
                        this.selectCity($current);
                    }
                    break;
                    
                case 27: // Escape
                    e.preventDefault();
                    this.hideDropdown();
                    break;
            }
        },
        
        /**
         * Select a city
         */
        selectCity: function($option) {
            var cityId = $option.attr('data-city-id');
            var cityName = $option.attr('data-city-name');
            var cityProvince = $option.attr('data-city-province');
            
            // Update input value
            $('#parsi_origin_city_input').val(cityName);
            
            // Update hidden field with city ID
            if ($('#parsi_origin_city_id').length === 0) {
                $('#parsi_origin_city_input').after(
                    '<input type="hidden" name="parsi_origin_city_id" id="parsi_origin_city_id" value="' + cityId + '">'
                );
            } else {
                $('#parsi_origin_city_id').val(cityId);
            }
            
            // Update province display if exists
            if ($('#parsi_origin_province_display').length > 0) {
                $('#parsi_origin_province_display').text(cityProvince);
            }
            
            this.hideDropdown();
            
            // Trigger change event
            $('#parsi_origin_city_input').trigger('change');
        }
    };
    
    /**
     * Initialize on document ready
     */
    $(document).ready(function() {
        // Check if we have the required data
        if (typeof parsiAdmin !== 'undefined' && parsiAdmin.ajaxUrl) {
            PARSI_City_Autocomplete.init();
        }
    });
    
})(jQuery);