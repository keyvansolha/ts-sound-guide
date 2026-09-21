# TehranSpeaker Sound Guide 3.0

A WordPress/WooCommerce guide that recommends live, purchasable earbuds and headphones from the TehranSpeaker catalog. Version 3 replaces the legacy JSON profile catalog with WooCommerce-only product mapping, strict REST validation, a configurable landing page, and theme-owned light/dark appearance.

## Requirements

- WordPress with WooCommerce enabled
- PHP 8.0 or newer
- Store currency `IRR`, `IRT`, or `TOMAN`
- The amazing theme for the intended production presentation and navigation integration
- Variable products must expose both `pa_color` and `pa_guarantee` on purchasable variations

Activation does not create pages or options, and never changes products, variations, prices, stock, attributes, accounting data, or theme settings.

## Install and configure

1. Install the plugin directory as `wp-content/plugins/ts-sound-guide` and activate **TehranSpeaker Sound Guide**.
2. Create and publish the WordPress page that should host the guide. Its builder content and default title will be replaced on the frontend.
3. Open **Settings → راهنمای انتخاب صدا**.
4. Select the landing page, the earbud product category, and the headphone product category. The category defaults resolve the existing `handsfree` and `headphone` slugs when available.
5. Optionally select the hero earbud and hero headphone shown inside the landing-page circle. **Automatic** selects the cheapest eligible product with an image; an unavailable or stale selection safely falls back to automatic mode.
6. Save settings and review the WooCommerce, currency, REST-route, and catalog-health diagnostics on the same screen.
7. Purge the selected page from page/CDN caches after configuration changes.

The configured landing page is the preferred path. It keeps the theme header and footer while the plugin owns the content between them, and the guide renders once even if the page also contains the shortcode.

For backward compatibility, an unconfigured page may use:

```text
[ts_sound_guide]
[ts_sound_guide flow="headphones"]
```

Shortcode pages retain their normal theme template and surrounding page content. Assets load only on the configured page or a page containing that shortcode.

## WooCommerce catalog contract

The guide queries published products in the two configured category trees on every recommendation and validation request. It never reads `profiles.json` or another plugin-owned product database.

Eligible products must be visible, purchasable, in stock, and not have `product-status=stop`. Eligible variations must be published/enabled, visible, purchasable, priced, in stock, not backordered, and include color and guarantee selections. For managed stock, quantity minus WooCommerce held reservations must be at least one. Unmanaged stock follows WooCommerce's stock state and is not treated as zero.

Prices are normalized internally to toman:

- `IRR`: divided by 10
- `IRT` or `TOMAN`: used as-is
- Other currencies: catalog unavailable with a controlled `503`

## Recommendation attributes

Capabilities are tri-state: explicit **yes**, explicit **no**, or **unknown**. Missing, empty, contradictory, and unrecognized values remain unknown. Unknown values are shown in catalog health; they exclude a product only when the shopper's selected path requires that capability.

| Capability | WooCommerce attribute |
| --- | --- |
| Bluetooth/wireless | `pa_bluetooth` |
| USB-C audio | `pa_connection` |
| AUX | `pa_aux` |
| Microphone while using AUX | `pa_aux-microphone` |
| Listening ANC | `pa_noise-cancellation` |
| Multipoint | `pa_qip5asto9pe6c2dzxq` |
| Silicone/open fit | `pa_inside-the-box`, then `pa_headphones-type` |
| Human-readable form factor | `pa_headphones-type` |

Use explicit values that match the registry in `includes/src/CapabilityRegistry.php`. In particular:

- a USB-C charging connector does not prove USB-C audio;
- ENC or generic noise-reduction language does not prove listening ANC;
- a product microphone does not prove microphone support over AUX;
- product names and marketing prose are never used to infer compatibility.

The read-only catalog-health report groups products as ready, incomplete, or unusable and links directly to each affected WooCommerce product. Correct the named WooCommerce field; the plugin never writes the correction itself.

## REST and caching

The same-origin JSON endpoints are:

- `POST /wp-json/ts-sound/v1/recommend`
- `POST /wp-json/ts-sound/v1/validate`

Both endpoints reject bodies larger than 4,096 bytes, unknown/invalid answer fields, and incomplete conditional answers. They return controlled `400`, `409`, or `503` responses and set `Cache-Control: no-store`, `Pragma: no-cache`, and `X-Robots-Tag: noindex, nofollow`.

Do not cache or edge-cache either REST route. `/validate` rebuilds the live catalog and verifies that the exact shown product, variation, price, and submitted answer path are still eligible. It never substitutes another product or relaxes the budget.

If physical warehouse freshness must be enforced, connect a verified source through:

```php
add_filter( 'ts_sound_inventory_health', static function () {
	return [
		'healthy'    => true,
		'expires_at' => time() + 120,
	];
} );
```

Return `null` when no verified provider exists. Returning unhealthy data or an expired timestamp makes both recommendation and final validation fail closed with `503`.

## Attribution and privacy

Successful final validation creates a cryptographically random token stored in a WordPress transient for 30 minutes. The product URL carries the token and selected variation attributes. The token is copied into the WooCommerce session only on the matching product and order metadata is written under `_ts_sound_guide` only when the exact product and variation are in the order.

Classic checkout and Store API checkout are supported. Attribution contains coarse answer enums and commerce identifiers only; it stores no contact information or free text. Matomo tracking is optional and the guide continues to work when `_paq` is absent.

## Appearance and frontend behavior

The amazing theme owns appearance through its existing `body.dark`, `data-theme`, `tsThemeMode`, and `tsMode` state. The plugin does not create a second theme preference or toggle. `assets/token-bridge.css` provides scoped fallbacks while preserving the theme's semantic token contract. Hero artwork keeps its original colors over the branded accent circle.

The frontend is split into ES modules under `assets/js/`; there is no build step and no hidden browser catalog. WordPress supplies only public bootstrap configuration.

## Verification

From the plugin directory:

```sh
npm test
node tests/js/dom.js
npm run test:browser
php tests/characterization/run.php
php tests/unit/bootstrap.php
php tests/unit/run.php
php tests/unit/parity.php
php tests/unit/catalog.php
php tests/unit/catalog.php irr
php tests/unit/catalog.php usd
php tests/unit/rest.php
php tests/unit/attribution.php
php tests/unit/landing.php
php tests/unit/admin.php
find . -path './tests/js/node_modules' -prune -o -name '*.php' -type f -print0 | xargs -0 -n1 php -l
```

Install test dependencies with `npm install` and `npm --prefix tests/js install`. The DOM suite uses the test-only `jsdom` installation under `tests/js/`.

`npm run test:browser` starts an isolated PHP server and runs real Chrome at desktop/mobile widths in light/dark modes. It covers success, empty, network-error, changed-inventory, comparison, and final-validation workflows; checks theme tokens, overflow, console/PHP errors, and critical accessibility invariants; and compares the hero, quiz, results, empty, and comparison states against the PNG baselines in `tests/browser/baselines/`.

The default Chrome executable is `/opt/google/chrome/chrome`; set `CHROME_PATH` to use another installation. Snapshot comparison allows at most 1% changed pixels at a per-pixel threshold of 0.12 to accommodate small font-rasterization differences while still detecting layout or palette regressions. To intentionally replace baselines after human review:

```sh
UPDATE_SNAPSHOTS=1 npm run test:browser
```

For ad-hoc manual inspection of the same controlled harness:

```sh
php -S 127.0.0.1:8765 -t .
```

Open `http://127.0.0.1:8765/tests/browser/index.php`. Query parameters select controlled states:

- `scenario=success|empty|error|changed`
- `theme=light|dark`

The harness uses the production CSS and ES modules but fake products and responses; it never connects to or modifies WooCommerce. The checked-in baselines were reviewed against the legacy guide markup from repository history before being adopted as the project-owned refactor baseline.

Before production rollout, also verify on staging:

- configured-page header/footer and required theme navigation;
- one guide render and no page-builder content;
- real product/variation imagery, price, and availability;
- classic and Store API checkout attribution;
- light/dark switching using the theme control;
- no browser-console errors, PHP warnings, or accessibility-critical issues;
- CDN exclusions for both REST routes.
