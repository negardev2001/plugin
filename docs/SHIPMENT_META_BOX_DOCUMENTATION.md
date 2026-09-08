# PARSI Shipment Meta Box Documentation

## Overview

The PARSI shipment meta box provides a structured interface for managing shipment information directly from the WooCommerce Order Edit page. This implementation supports both packet and parcel shipment types with conditional fields and business rule enforcement.

## Features

### 1. Dual Shipment Type Support
- **Packet (پاکت)**: For documents and small items
- **Parcel (بسته)**: For larger items requiring dimensions

### 2. Conditional Field Visibility
- Fields automatically show/hide based on shipment type
- Dimensions only required for parcel shipments
- Package type options change based on shipment type

### 3. Insurance Business Rule
- If declared value is empty or less than 5,000,000 Rial (500,000 Toman)
- Insurance type automatically set to "mandatory"
- Other insurance options are disabled

### 4. Server-Side Validation
- All required fields validated before saving
- Numeric fields properly sanitized
- HPOS-compatible meta storage

### 5. RTL Compatibility
- Full right-to-left language support
- Persian language optimized
- Clean wp-admin style integration

## Field Specifications

### Common Fields (Both Packet and Parcel)
- **Weight (grams)**: Required, numeric, > 0
- **Contents Description**: Required, text field
- **Declared Value (Rial)**: Optional, numeric
- **Package Type**: Required, dropdown with type-specific options
- **Packaging Cost (Rial)**: Optional, numeric
- **Insurance Type**: Required, dropdown with business rule enforcement

### Packet-Specific Options
Package Type Options:
1. بدون نیاز به بسته بندی
2. پاکت حباب دار A3
3. پاکت حباب دار A4
4. پاکت حباب دار A5
5. پاکت لمینت A3
6. پاکت لمینت A4
7. پاکت لمینت A5
8. کاور اسناد

### Parcel-Specific Options
Package Type Options:
1. کارتن 1
2. کارتن 2
3. کارتن 3
4. کارتن 4
5. کارتن 5
6. کارتن 6
7. کارتن 7
8. کارتن 8
9. کارتن 9
10. کارتن 10

Additional Required Fields for Parcel:
- **Length (cm)**: Required, numeric, > 0
- **Width (cm)**: Required, numeric, > 0
- **Height (cm)**: Required, numeric, > 0

### Insurance Type Options
1. mandatory (اجباری)
2. cash_up_to_50m (نقدی تا ۵۰ میلیون)
3. goods_up_to_2b (کالا تا ۲ میلیارد)
4. documents_up_to_1b (اسناد تا ۱ میلیارد)
5. bulk_up_to_1_5b (حجمی تا ۱.۵ میلیارد)

## Data Storage

All shipment data is stored in order meta using the following keys:

- `_parsi_shipment_type`: packet or parcel
- `_parsi_weight`: Weight in grams
- `_parsi_length`: Length in cm (parcel only)
- `_parsi_width`: Width in cm (parcel only)
- `_parsi_height`: Height in cm (parcel only)
- `_parsi_contents`: Contents description
- `_parsi_declared_value`: Declared value in Rial
- `_parsi_package_type`: Selected package type
- `_parsi_packaging_cost`: Packaging cost in Rial
- `_parsi_insurance_type`: Insurance type

## Security Features

### 1. Nonce Verification
- All save operations protected by WordPress nonces
- AJAX requests validated with proper nonce checking

### 2. Capability Checking
- Only users with `edit_shop_orders` capability can save data
- Order access properly validated

### 3. Input Sanitization
- Text fields: `sanitize_text_field()`
- Textarea: `sanitize_textarea()`
- Numeric fields: `absint()`
- All data properly escaped on output

### 4. Server-Side Validation
- Required fields cannot be empty
- Numeric values must be positive
- Shipment type validation
- Insurance business rule enforcement

## Frontend Features

### 1. JavaScript Functionality
- Real-time field toggling based on shipment type
- Insurance rule enforcement with visual feedback
- Form validation before AJAX submission
- Loading states and error handling
- Keyboard shortcut support (Ctrl+S to save)

### 2. CSS Styling
- Two-column responsive layout
- RTL language support
- WooCommerce admin style integration
- Mobile-responsive design
- Accessibility features (focus states, high contrast support)

### 3. User Experience
- Smooth transitions and animations
- Clear error messaging
- Visual feedback for save operations
- Auto-save capability (commented out, can be enabled)

## Integration Points

### 1. WordPress Hooks
- `add_meta_boxes`: Registers the meta box
- `save_post`: Handles traditional form submission
- `wp_ajax_parsi_save_shipment_data`: AJAX save handler
- `admin_enqueue_scripts`: Loads necessary assets

### 2. WooCommerce Compatibility
- Works with WooCommerce 9+
- Fully HPOS compatible
- Uses `wc_get_order()` for order retrieval
- Integrates with WooCommerce admin styles

### 3. Plugin Architecture
- Singleton pattern for class instantiation
- Proper initialization in main plugin file
- Modular component design
- Extensible for future enhancements

## Usage Instructions

### For Store Administrators
1. Navigate to WooCommerce → Orders
2. Click on any order to edit it
3. Find the "اطلاعات مرسوله پارسی" meta box
4. Select shipment type (packet or parcel)
5. Fill in required fields based on shipment type
6. Click "ذخیره اطلاعات مرسوله" to save

### For Developers
The meta box can be extended or customized using:
- WordPress filters: `parsi_shipment_meta_fields`
- WordPress actions: `parsi_before_save_shipment`, `parsi_after_save_shipment`
- JavaScript hooks: `parsiOrderMetaBox` global object

## Testing

A comprehensive test suite is included in `test-shipment-meta-box.php`. This file verifies:
- Packet and parcel shipment type handling
- Data validation for both types
- Insurance business rule enforcement
- Meta key storage and retrieval
- Form validation logic

## Future Enhancements

Potential improvements for future versions:
1. Bulk shipment editing
2. Shipment template presets
3. Integration with shipping calculators
4. Barcode scanning support
5. Advanced validation rules
6. Multi-language support extension

## Troubleshooting

### Common Issues
1. **Fields not saving**: Check user capabilities and nonce verification
2. **JavaScript errors**: Ensure jQuery is loaded before meta box script
3. **CSS conflicts**: Verify WooCommerce admin styles are loaded
4. **Validation failures**: Check required field completion

### Debug Mode
Enable debug mode in plugin settings to:
- Log all validation errors
- Track AJAX request/response
- Monitor meta field updates

## Support

For technical support or questions:
- Check the plugin documentation
- Review WooCommerce order system requirements
- Verify PHP version compatibility (7.4+)
- Ensure WordPress version compatibility (5.8+)