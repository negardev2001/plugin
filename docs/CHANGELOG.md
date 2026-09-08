# Changelog

All notable changes to the Parsi WooCommerce shipping plugin are documented here.

> Releases are versioned from **1.1.0** onward to match the plugin header
> (`PARSI_VERSION`) and `readme.txt`. The earlier `3.0.0` / `2.0.0` / `1.x`
> headings below are historical development milestones, not released versions.

---

## [1.1.0] — 2026-05-24 — Authorization Fix & API Hardening

Fixed the root cause of "no shipping rates appear at checkout" and verified the
whole rate flow against the live API.

### Fixed

- **Removed the phantom license gate.** `PARSI_Auth::is_authorized()` previously
  called a non-existent `https://parsipost.com/api/validate` endpoint; any non-
  `{"valid":true}` response silently blocked every shipping rate. This was the
  real reason the plugin "didn't work."
- `is_authorized()` now means *credentials that the real API accepts*: it probes
  `GetApiOrderBasicInfo` with `x-api-key` + `office` (result cached 24h). A
  transport error falls back to the last known-good result instead of locking out
  a working store.
- The authorization cache is now cleared automatically whenever `parsi_api_key`
  or `parsi_office_id` changes (and on activate/deactivate), so corrected
  credentials take effect immediately instead of after the cache expires.

### Changed

- **Test Connection** now sends and validates the `office` header alongside the
  API key. Both are required — the API returns `403` without `office` and `401`
  without `x-api-key` (confirmed by live testing).
- API errors now surface the server's real Persian message: `request()` parses
  the documented `{ success, messages: [ { persian_message } ] }` envelope instead
  of returning raw JSON.

### Docs / housekeeping

- Verified the live auth model: `x-api-key` + `office` headers, no login/Bearer
  step (the vendor PDF's `LOGIN` → Bearer-token flow is not used by the live API).
- Added importable Postman collection with request/response examples:
  `docs/Parsi-Post-API.postman_collection.json`.
- Removed a broken alternate plugin rewrite (`/v2`) and stale/duplicate docs
  (`API_STRUCTURE_DOCUMENTATION.md`, old `client-order-api*.json` exports).

---

## [3.0.0] — Cache Stampede Protection

Hardened `class-parsi-api-data.php` against cache stampede and empty-array caching issues.

- Two-level lock strategy: `wp_cache_add()` for external object cache, transient-based lock as fallback
- Adaptive TTL: empty arrays cached for 5 minutes, populated results cached longer (cities now 12 hours)
- Stale cache fallback when the API is unreachable
- Constants: `EMPTY_CACHE_EXPIRATION` (300s), `LOCK_EXPIRATION` (30s), `MAX_WAIT_TIME` (2s), `POLL_INTERVAL` (100ms)
- New helpers: `wait_for_cache()`, `release_lock()`, `fetch_cities_from_api()`, `fetch_deadlines_from_api()`
- `clear_cache()` now also clears locks and stale entries

---

## [2.0.0] — API Refactor (Breaking)

Aligned the plugin with the new official Parsi Post API documentation.

### Breaking changes

- **Authentication**: removed `Authorization: Bearer`. Now uses `x-api-key` and `office` headers. New required setting: `parsi_office_id`.
- **Endpoints**:
  - `/rates` → `Ordering/ClientOrder/GetPrice`
  - `/register` → `Ordering/ClientOrder/Save`
  - `/cities`, `/deadlines` → `GetApiOrderBasicInfo`
  - `/status` kept as legacy for tracking
- **Base URL**: `https://post.pishgaman.top/api` (was `.../api/Ordering/ClientOrder/Save`)
- **Cities payload**: now parsed from nested `Provinces[].Cities[]` structure (was flat)
- **GetPrice parameters**: `origin_city` → `senderCityId`, `destination_city` → `receiverCityId`; added required `serviceTypeId` and `insuranceTypeId`
- **Save Order schema**: keys exactly match Postman spec; location formatted as `lat,long` (no spaces); weight always sent in grams

### Maintained backward compatibility

- WooCommerce shipping method registration, checkout flow, order meta box, tracking display unchanged
- Public method signatures (`get_rate_id()`, `calculate_shipping()`) unchanged
- Existing settings preserved; new settings added alongside

### Migration

1. Configure Office ID in plugin settings (now required)
2. Verify API Key
3. Optionally update Contract ID

---

## [1.x] — Security Hardening & Persian Localization

### Removed hardcoded defaults

- New `PARSI_API_IDs_Config` class for strict UUID validation (8-4-4-4-12 hex)
- Fail-fast on missing config; admin warnings for incomplete setup
- Required IDs: `contract_id`, `payment_type_id`, `service_type_id`, `deadline_id`, `parcel_type_id`, `box_type_id`, `insurance_type_id`, `sender_city_id`

### Security improvements

- `parsi_mask_sensitive_data()` masks API keys, phone numbers, national codes in logs (shows first/last 2 chars only)
- Detailed logs only when `WP_DEBUG` is true
- Strict input validators: `parsi_validate_weight_strict()`, `parsi_validate_dimension_strict()`, `parsi_validate_phone_strict()` (Iranian `^09[0-9]{9}$`), `parsi_validate_postal_code_strict()` (10 digits), `parsi_validate_city_id_strict()` (UUID or numeric)
- Order ownership validation (`validate_order_ownership()`) on all admin AJAX endpoints
- Request flood prevention via `parsi_can_make_api_request()` (30s minimum interval, configurable per action)
- Atomic shipment registration via SQL `UPDATE ... WHERE` to prevent double-registration races
- Removed `wp_ajax_nopriv_parsi_manual_sync_order` (admin-only). Tracking nopriv handler kept for customer-facing use.
- All API requests use `wp_remote_post()` with `sslverify => true` and 30s timeout

### Persian (Farsi) localization

- All admin UI, error messages, and notices translated to Persian
- Shipping method title: "ارسال با پارسی پست"
- Translation files: `languages/parsi-fa_IR.po`, `assets/js/parsi-translations.js`
- Full RTL CSS support in `admin.css`

### Cart & checkout stability

- Defensive validation for `$package['destination']`, `$package['contents']`, item data objects
- `method_exists()` checks before calling `has_weight()` / `has_dimensions()`
- Graceful API failure handling — no PHP warnings on checkout, optional fallback rate via `parsi_show_fallback_rate` filter
- Timeout detection in `make_request()`; response/rate structure validation in `parse_response()` and `normalize_rate()`

### WooCommerce compatibility

- Fixed `get_rate_id()` signature to match `WC_Shipping_Method::get_rate_id($suffix = '')`
- CSS/JS only enqueued on plugin-specific admin pages (no global conflicts)
- Settings page uses `do_settings_sections()` and `settings_fields()` properly
- Added `wp_ajax_parsi_test_connection` handler for the test-connection button
- Verified compatible with WooCommerce 8.0+ and HPOS

---

## Reference

- API reference (endpoints, fields, auth): [api-documentation.md](api-documentation.md)
- Importable API collection with examples: [Parsi-Post-API.postman_collection.json](Parsi-Post-API.postman_collection.json)
- Shipment meta box feature documentation: [SHIPMENT_META_BOX_DOCUMENTATION.md](SHIPMENT_META_BOX_DOCUMENTATION.md)
