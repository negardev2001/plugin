# PARSI - WooCommerce Shipping Integration

A WooCommerce shipping plugin that calculates shipping rates via an external shipping API.

## Status

Active development. See [docs/](docs/) for design notes, refactoring history, and security hardening summaries.

## Requirements

- WordPress 5.8+
- WooCommerce
- PHP 7.4+

## Installation

1. Copy the plugin folder into `wp-content/plugins/`.
2. Activate **PARSI** from the WordPress Plugins screen.
3. Go to **WooCommerce → Settings → Shipping → PARSI Shipping** to configure API key, API URL, and options.

See [readme.txt](readme.txt) for the full WordPress.org-style plugin readme, including supported API response formats.

## Project layout

```
parsi.php           Main plugin bootstrap
includes/           Core classes (shipping method, API client, orders, tracking, etc.)
assets/             CSS, JS, logo
languages/          Translations (fa_IR)
tests/              Ad-hoc PHP test scripts
docs/               Internal design notes and summaries
uninstall.php       Cleanup hook
```

## Development

This repo is being developed collaboratively. Open issues / PRs for changes.
"# plugin" 
