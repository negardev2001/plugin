=== PARSI - WooCommerce Shipping Integration ===
Contributors: yourname
Tags: woocommerce, shipping, api, rates
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A WooCommerce shipping plugin that calculates shipping rates using an external shipping API.

== Description ==

PARSI is a powerful WooCommerce shipping plugin that integrates with external shipping APIs to provide real-time shipping rate calculations. The plugin allows store owners to connect their WooCommerce store to any shipping API service and display accurate shipping rates to customers during checkout.

== Features ==

* **Custom Shipping Method**: Adds a custom shipping method to WooCommerce that fetches rates from an external API
* **API Integration**: Supports both POST and GET methods for API requests
* **Flexible Configuration**: Easy-to-use settings page for API credentials and shipping options
* **Error Handling**: Comprehensive logging system for API requests and errors
* **Checkout Integration**: Seamlessly displays shipping rates on the WooCommerce checkout page
* **Handling Fees**: Option to add handling fees to shipping rates
* **Multiple Rate Support**: Handles multiple shipping options from the API
* **Automatic Updates**: Built-in support for automatic plugin updates

== Installation ==

1. Upload the `parsi` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to WooCommerce > Settings > Shipping > PARSI Shipping to configure
4. Enter your API Key and API URL in the plugin settings
5. Configure additional options such as handling fees and logging preferences

== Configuration ==

1. Go to **WooCommerce > PARSI Shipping** in your WordPress admin
2. Enter your **API Key** for authentication
3. Enter your **API URL** endpoint
4. Select the **API Method** (POST or GET)
5. Optionally configure:
   - Handling Fee: Additional fee to add to shipping rates
   - Enable Logging: Log API requests and errors
   - Default Shipping Method: Default method ID if API returns multiple options

== API Integration ==

The plugin expects the API to return shipping rates in one of the following formats:

**Format 1:**
```json
{
  "rates": [
    {
      "id": "standard",
      "label": "Standard Shipping",
      "cost": 10.00,
      "estimated_delivery": "3-5 business days"
    }
  ]
}
```

**Format 2:**
```json
[
  {
    "id": "standard",
    "label": "Standard Shipping",
    "cost": 10.00
  }
]
```

**Format 3:**
```json
{
  "shipping_options": [
    {
      "id": "standard",
      "label": "Standard Shipping",
      "cost": 10.00
    }
  ]
}
```

The API request will include:
- Destination (country, state, postcode, city, address)
- Package weight (in kg)
- Package dimensions (length, width, height in cm)

== Frequently Asked Questions ==

= Does this plugin support Cash on Delivery (COD)? =

No, this plugin only calculates shipping rates based on the external API. COD functionality is not included.

= What happens if the API request fails? =

The plugin will log the error and no shipping rates will be displayed. Customers will need to contact the store for shipping options.

= Can I use multiple shipping APIs? =

Currently, the plugin supports one API at a time. You can configure different APIs for different shipping zones if needed.

= How do I view API logs? =

Go to WooCommerce > Status > Logs and look for entries with the source "parsi".

== Changelog ==

= 1.1.0 =
* Fixed: shipping rates no longer blocked by a phantom license check. Authorization now verifies your credentials against the real Parsi Post API.
* Changed: "Test connection" now checks the Office ID together with the API key (both are required) and shows the API's actual Persian error message.
* Changed: API errors now display the server's Persian message instead of raw data.
* Improved: the authorization check refreshes immediately when you change the API key or Office ID.

= 1.0.0 =
* Initial release
* Custom WooCommerce shipping method
* External API integration (POST/GET)
* Settings page for API configuration
* Logging system for API requests
* Error handling and validation
* Support for multiple shipping rates
* Handling fee option
* Automatic update support structure

== Upgrade Notice ==

= 1.1.0 =
Fixes shipping rates not appearing at checkout. After updating, open the plugin settings and use "Test connection" to confirm your API Key and Office ID.

= 1.0.0 =
Initial release of PARSI WooCommerce Shipping Integration plugin.

