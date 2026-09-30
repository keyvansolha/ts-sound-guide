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

Capabilities are tri-state and resolve in a fixed precedence order:

1. **Category authority — `yes`.** A product in a category whose name declares the capability gets it, even when an attribute says otherwise. `151` «نویز کنسلینگ» grants ANC; `144` «بی سیم \| بلوتوث» and `119` «هدفون بی سیم» grant wireless; `34745` «با سیم \| سیمی» denies it. Ancestor categories count, and both category names and slugs are matched. When a category and an attribute disagree — in either direction — the disagreement is reported for administrators instead of being hidden by the category's priority.
2. **Explicit attribute value — `yes`/`no`.** The primary taxonomy is read first; when its text answers neither yes nor no for this capability (e.g. «اقلام داخل جعبه» that never names the tips), the alternate taxonomy (`pa_headphones-type` for fit) is consulted before the value counts as unreadable.
3. **Category negation — `no`.**
4. **Absence — `unknown`.** A missing or empty attribute means the store has not listed the feature. Nothing listed is never presented to the shopper as a claim: it is not «ندارد» and it is not «دارد», and it does not by itself mark the product as a data defect. Catalog health reports those products separately as a coverage gap (`not_listed`) so the spec sheet can be completed.
5. **Present but contradictory or unreadable — `unknown`.** Reported in catalog health with the field to correct.

The three states stay independent: a required capability that is `unknown` is never offered as if it had been confirmed, and an unlisted spec is never displayed as «ندارد».

Category vocabulary is data-derived from this store's `product_cat` tree and lives in the registry as term IDs plus exact names, so it survives a rename (ID) or a re-created category (name). Extend it with `ts_sound_guide_category_signals` and `ts_sound_guide_category_negations` — no code edits needed.

| Capability | WooCommerce attribute | Categories that grant it | Categories that deny it |
| --- | --- | --- | --- |
| Bluetooth/wireless | `pa_bluetooth` | `144` «بی سیم \| بلوتوث»، `119` «هدفون بی سیم» | `34745` «با سیم \| سیمی» |
| Listening ANC | `pa_noise-cancellation` | `151` «نویز کنسلینگ» | — |
| USB-C audio | `pa_connection` | — (attribute only) | — |
| AUX | `pa_aux` | — (attribute only) | — |
| Microphone while using AUX | `pa_aux-microphone` | — (attribute only) | — |
| Multipoint | `pa_qip5asto9pe6c2dzxq` | — (attribute only) | — |
| Silicone/open fit | `pa_inside-the-box`, then `pa_headphones-type` | — (attribute only) | — |
| Human-readable form factor | `pa_headphones-type` | — (product field) | — |

Everything else in the tree describes form, type, or use — «ایرباد» `128`, «ایرفون» `117`, «هدفون دور گوشی» `127`, «هدفون گیمینگ» `122`, «هدفون ورزشی» `120`, «هدست» `126`, «میکروفون» `111` — and grants no capability, because a form name is not a feature claim. Two feature tags are deliberately left unclaimed until you confirm how consistently they are applied: `149` «پخش یو اس بی» (USB playback: could be USB-C, USB-A, or a dongle) and `143` «مکالمه تلفنی» (phone calls: proves a call microphone, not a microphone in AUX mode).

Use explicit values that match the registry in `includes/src/CapabilityRegistry.php`. In particular:

- a USB-C charging connector does not prove USB-C audio. A connection value that names a USB-C port as charging only («… USB-C کیس فقط برای شارژ») resolves to `no`, never to `yes`: the port is a power inlet, so the model is not offered on a wired USB-C path;
- silicone fit is confirmed by the tips the store lists (`ایرتیپ`/`سری سیلیکونی`/`سری قرار گیرنده`) in «اقلام داخل جعبه», or by a form-factor name that names the tips («داخل گوش (In-Ear) با سری سیلیکونی»). A plain form-factor name («روی گوش») answers nothing;
- ENC or generic noise-reduction language does not prove listening ANC;
- a product microphone does not prove microphone support over AUX;
- product names and marketing prose are never used to infer compatibility.

A capability that resolves to `no` is displayed to shoppers as «ندارد», so the category tree and the attributes are the storefront's claim: keep both accurate. When a category and an attribute disagree, the category wins and catalog health flags the product so the data can be fixed.

### ANC is a wireless-mode feature

Active noise cancelling runs off the product's own power; on a wired connection the circuit is off even on models that support both modes. The guide therefore treats ANC as usable only while the product is used wirelessly:

- an ANC priority (`pain=noise`) requires a wireless product — a wired path rejects with reason `anc_wired` and a notice, because no cable can satisfy that priority;
- ANC is never credited as a scoring reason or as a shown feature on `aux` or `usbc` paths;
- the question flow withholds the wired connection option once ANC is the priority, and says why.

Content consequence: a product tagged `151` «نویز کنسلینگ» also needs `144` «بی سیم \| بلوتوث» or `119` «هدفون بی سیم» to appear in ANC paths; a wired-only product carrying the ANC tag is a data-quality conflict.

### Budget field range

The budget field spans the live catalog: its floor is the cheapest eligible price and its ceiling the most expensive one, per flow (falling back to the whole catalog), never outside the documented public range `500000`–`500000000` that the REST boundary enforces. The span is computed server-side (`CatalogAdapter::price_range()`) and shipped in the page's bootstrap config as `prices`; the browser only renders it. A page whose catalog cannot be read falls back to the documented range instead of failing.


The read-only catalog-health report groups products as ready, incomplete, or unusable and links directly to each affected WooCommerce product. Correct the named WooCommerce field; the plugin never writes the correction itself.

A capability the store has not listed is **not** a defect: those products stay `ready` and are reported apart, under `not_listed`, with a per-capability count so the data team can see which spec sheets are incomplete. Only contradictory or unreadable values and category/attribute conflicts make a product `incomplete`.

## Suggestions, primary cards, and «گزینه‌های بیشتر»

Eligibility and presentation are separate decisions. A product is either eligible for the answers or it is not; being eligible never depends on winning one of the three primary cards. The recommend response therefore carries:

- `picks` — up to three primary cards with their roles (`پیشنهاد اصلی`, `هزینه کمتر`, `امکانات بیشتر`, `گزینه جایگزین`);
- `options` / `optionsTotal` — every other eligible model, reachable through **«دیدن گزینه‌های بیشتر»** on the results section, and purchasable through the same final validation as a primary card.

`امکانات بیشتر` is only used when the model really adds the capability the shopper asked for (ANC for commute, multipoint for work); a higher price alone never earns the label. A suggestion above the first budget ceiling always states the extra amount *and* the related advantage; when there is no related advantage it says so.

## Answer effects (what each answer actually changes)

`RecommendationEngine::answer_effects()` is the documented contract, and the shopper-facing copy follows it:

| Answer | Effect |
| --- | --- |
| flow | filter — product category |
| use | filter + score: gaming rejects an unproven wireless path; commute scores ANC (+12); work scores multipoint (+10) and requires an AUX microphone on a wired path |
| pain | filter + score: noise requires ANC (wireless only) and scores +25; switch requires multipoint and scores +25; charge and balanced filter and score nothing |
| connection | filter — wireless / USB-C / AUX |
| device | filter — only a device with a USB-C port on the USB-C path |
| fit | filter on silicone/open; «استفاده طولانی» and «عینک» add a comfort note only |
| calls, charge | copy only — the store has no verified, comparable numbers for call quality or battery life, so the interface says so and never promises a ranking change |
| budget | filter — price ceiling; the 20% increase applies only when the shopper ticks it (`flex`) |

## Budget amounts, units, and prices

Every budget surface states the same amount in the same unit: the fast-choice buttons print the full toman amount (`۲۰٬۵۰۰٬۰۰۰ تومان`), and the button value, the numeric field, the slider, the final ceiling, and the submitted request always agree. IRR is divided by ten exactly once in `CatalogAdapter`; the card price always matches the currently selected colour and guarantee.

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

The amazing theme owns appearance through its existing `body.dark`, `data-theme`, `tsThemeMode`, and `tsMode` state. The plugin does not create a second theme preference or toggle. `assets/token-bridge.css` provides scoped fallbacks while preserving the theme's semantic token contract. Hero artwork uses the branded accent blend inside the product circle.

The frontend is split into ES modules under `assets/js/`; there is no build step and no hidden browser catalog. WordPress supplies only public bootstrap configuration.

## Verification

From the plugin directory:

```sh
npm test
node tests/js/dom.js
php tests/unit/parity.php            # unrecorded behaviour changes fail here
php tests/unit/parity.php --record   # re-record deliberate deltas, with a reason
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
