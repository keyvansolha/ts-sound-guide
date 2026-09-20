# TehranSpeaker Sound Guide v2

Prepared integration, not deployed. Brand source: `KEYVAN-TS/tehranspeaker.com-amazing` at `61f53b026522e7c671837b000f4e644d19311188`.

## Install on staging

1. Install `TehranSpeaker-Sound-Guide-WordPress.zip`. Requires PHP 8.0+, WooCommerce and the amazing theme. Do not upload the whole review package as a plugin.
2. Create a draft page with one shortcode:

```text
[ts_sound_guide flow="headphones"]
```

or:

```text
[ts_sound_guide flow="earbuds"]
```

3. The scoped template avoids duplicate page titles and the default Page Builder container. It reuses global header/footer/navigation. Check existing builder content before using the shortcode on an existing page. Exactly one guide per page is supported.
4. Verify both same-origin POST routes under `/wp-json/ts-sound/v1`: `recommend` and `validate`. Do not cache these routes at the CDN. Both respond with `Cache-Control: no-store`.
5. Verify product-category slugs `headphone` and `handsfree`, IRT or IRR currency, color/guarantee taxonomies, WordPress REST access, Matomo events and attribution for classic and block checkout on the actual store.

No inventory, accounting, price, page or theme setting is changed by activation. The module reads the current WooCommerce category and variation inventory after the site's existing Sepidar sync. The preview file is an offline snapshot and cannot claim live availability.

## Inventory contract

Published, visible, in-stock products; `product-status=stop` excluded even when stock remains. Variations must be enabled, visible, purchasable, instock, priced and not on backorder. Managed stock minus held reservations must be at least one. Unmanaged stock uses WooCommerce stock state; null is not coerced to zero. The existing amazing variation purchase UI requires `pa_color` and `pa_guarantee`.

The server discovers new/restocked products from the two categories on each request. Reviewed profiles override raw attributes. New profiles only use explicit features; missing information is unknown. Charging USB-C is never interpreted as USB audio, ENC is not hearing ANC, and AUX microphone support is never inferred from a microphone checkbox. The `ts_sound_profiles` filter allows reviewed additions. Dynamic models use negative WC IDs for the internal `id` field to avoid collision with legacy positive MyTS IDs; `wcId` remains the real positive WooCommerce ID.

The browser receives a public DTO without quantities, reserved quantities, costs or internal ranking values. Inventory depth is a tie-break only at equal price and suitability; parent-managed stock is deduplicated. Server validation re-runs eligibility, checks the chosen variation and exact current price, and returns 409 on change. No silent substitution or automatic budget relaxation. It is not a stock reservation.

## Upstream freshness

The existing accounting code schedules source fetching at 240 seconds and applying the queue at 260 seconds. This is not an instant warehouse feed or a latency SLA. No successful-sync heartbeat is assumed.

Connect `ts_sound_inventory_health` to a verified source if physical warehouse freshness must be enforced:

```php
// Return null while no verified provider is integrated; never fabricate timestamps.
// A configured provider must return:
// ['healthy' => true, 'expires_at' => <actual expiry Unix timestamp>]
// healthy=false or an expired timestamp makes the API return 503.
```

## Measurement

Uses the existing `_paq.push` interface when present. The guide emits coarse answer enums and commerce events, not phone numbers or free-text personal data. `/validate` stores a 30-minute attribution transient, then the product page captures it in WooCommerce session. `_ts_sound_guide` order metadata is attached only when that exact product and variation are in the order. Count paid orders once by order ID; don't report total cart value as revenue or infer profit from inventory depth. End-to-end attribution still needs staging validation.

## Review and tests

The offline preview includes a separate operator simulation panel. Those controls never modify store inventory and are absent from the plugin.

`review/tests` contains the pure JS policy, PHP implementation, controlled stock fixtures and DOM/HTTP tests. `qa-*.json` identifies method and scope. DOM tests need jsdom 26.1.0; PHP tests ran with `@php-wasm/cli` 3.1.54. Adjust the jsdom require path to your local installation. Browser rendering, production WooCommerce behavior and a paid checkout were not tested here.

Run policy tests before the PHP parity test; the first generates `policy-fixtures.json`. In the tests folder:

```sh
node verify.cjs
node verify-dom.cjs
node verify-live.cjs
php verify-php.php
```

Product imagery and the YekanBakh font are reused for TehranSpeaker's own review. The installed plugin uses the site's font and media; it does not distribute a separate production font copy. `token-bridge.css` is generated from the repository's semantic tokens, not an invented palette.
