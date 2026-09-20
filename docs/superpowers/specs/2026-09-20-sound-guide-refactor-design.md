# TehranSpeaker Sound Guide Refactor Design

## Purpose

Refactor TehranSpeaker Sound Guide into a conventional, maintainable WordPress and WooCommerce plugin while preserving its current Persian storefront experience and recommendation business rules.

The plugin must let an administrator select an existing WordPress page as the guide landing page. On that page, the theme continues to own the header, footer, and required navigation components; the plugin replaces the content between them with the guide.

## Success Criteria

- The guide's Persian copy, RTL layout, quiz sequence, recommendation behavior, product cards, comparison table, responsive layout, accessibility behavior, and motion remain recognizable and visually equivalent to the current version.
- The selected landing page is configured through WordPress administration and does not require a shortcode.
- WooCommerce is the only product-data source. No JSON product profile catalog or parallel product database remains.
- Products, variations, prices, images, availability, and recommendation capabilities are read from existing WooCommerce records at request time.
- Unknown product capabilities are never guessed. They are reported to administrators and exclude a product only from recommendation paths that require the unknown capability.
- The guide follows the amazing theme's existing light/dark preference and control without maintaining a second preference.
- Recommendation policy, WooCommerce mapping, REST endpoints, rendering, frontend state, and attribution are separated into focused, testable units.
- Automated tests cover the business rules, failure behavior, browser workflow, dark mode, and visual parity.

## Scope

### Included

- Plugin bootstrap and service organization
- WordPress settings for selecting the landing page and product categories
- Selected-page template behavior
- Backward-compatible shortcode rendering
- WooCommerce catalog and variation mapping
- Product-data health reporting in WordPress administration
- Existing recommendation policy and notices
- Recommendation and final-validation REST endpoints
- Existing short-lived attribution and order metadata behavior
- Componentized PHP views and frontend JavaScript
- Theme-integrated light and dark appearance
- PHP, JavaScript, browser, security, and visual-regression tests
- Installation and operations documentation

### Excluded

- Importing or migrating the 25 entries from `includes/profiles.json`
- Creating a custom product-profile table
- Creating duplicate plugin-owned product capability fields
- Editing WooCommerce product data automatically
- Changing WooCommerce inventory, pricing, reservations, or accounting synchronization
- Changing the current Persian content or recommendation intent unless required to correct a defect
- Redesigning the site header, footer, or theme appearance control

## Architecture

The plugin will use a small composition root in the main plugin file. It will construct and register focused services through WordPress hooks while keeping business logic out of hook callbacks.

### Bootstrap

The bootstrap is responsible only for:

- defining plugin constants;
- checking the supported PHP and WooCommerce environment;
- loading classes;
- constructing services;
- registering activation, admin, frontend, REST, and WooCommerce hooks.

The plugin must fail safely when WooCommerce is unavailable. Administrative users receive a clear notice; public routes return a controlled unavailable response instead of a fatal error.

### Settings

A settings service stores one plugin option containing:

- the selected landing page ID;
- the earbud product-category term ID;
- the headphone product-category term ID.

Defaults resolve the existing `handsfree` and `headphone` categories when present. Settings are registered with the WordPress Settings API, sanitized on write, and editable only by an administrator with `manage_options` capability.

The settings page also links to catalog health and shows read-only route and WooCommerce availability diagnostics.

### Landing Page

The landing-page service identifies the configured page by numeric page ID. When that page is requested, it uses a plugin template that calls the theme's normal `get_header()` and `get_footer()` functions and retains the required amazing-theme commerce navigation integration. The plugin renders the guide as the page's main content and does not render the page builder content, default page title, or theme content container.

The existing `[ts_sound_guide]` shortcode remains available as a compatibility path. When the configured landing page is being rendered, the configured-page behavior takes precedence and the guide is rendered exactly once.

Assets are enqueued only on the configured landing page or a page that contains the compatibility shortcode.

### WooCommerce Catalog Adapter

WooCommerce is the sole source of product facts. The catalog adapter queries published products from the configured category terms and maps WooCommerce products into an internal immutable catalog representation.

The adapter reads:

- product and variation IDs;
- product name and permalink;
- featured and variation images;
- publication and catalog visibility;
- the theme-owned `product-status` lifecycle field;
- product-category membership;
- WooCommerce product attributes;
- variation attributes;
- current display price and store currency;
- purchasability, stock status, backorder state, managed stock, and held reservations.

Only published, visible, purchasable, in-stock products are eligible. A product with `product-status=stop` is ineligible. A variation must be enabled, visible, purchasable, in stock, priced, and not on backorder. For managed stock, quantity minus held reservations must be at least one. Unmanaged stock relies on WooCommerce stock state and is not coerced to zero.

The amazing theme's purchase flow currently requires color and guarantee variation attributes. The adapter preserves that rule for variable-product recommendations.

Prices are represented internally in toman. IRR display prices are divided by ten. IRT and TOMAN are accepted directly. Unsupported currencies make the catalog unavailable with a controlled diagnostic.

### Capability Mapping

Recommendation capabilities use a tri-state value:

- `yes`: explicitly supported by an existing WooCommerce attribute;
- `no`: explicitly unsupported by an existing WooCommerce attribute;
- `unknown`: absent, empty, contradictory, or not safely interpretable.

The initial mapped capabilities are:

- product flow/category;
- wireless connection;
- USB-C audio connection;
- AUX connection;
- microphone support while using AUX;
- active noise cancellation for listening;
- simultaneous multipoint connection;
- silicone or open earbud fit;
- human-readable form factor.

The mapping layer will define the exact existing WooCommerce attribute keys and accepted explicit values in one registry. It must not infer:

- USB audio from a USB-C charging connector;
- ANC from ENC or generic noise-reduction language;
- AUX microphone support from the presence of a microphone;
- wireless, fit, or compatibility from product names or marketing prose.

Missing facts remain `unknown`. A product with an unknown capability remains eligible for recommendation paths that do not depend on that capability. It is excluded when the user's selected path requires that capability to be confirmed.

### Catalog Health

The catalog-health service runs the same catalog mapping rules used by recommendations and produces product-level diagnostics without maintaining a second dataset.

The administration report groups relevant products into:

- **Ready:** sufficient valid data for all applicable recommendation paths;
- **Incomplete:** sellable but missing or ambiguous recommendation capabilities;
- **Unusable:** cannot be safely offered because core commerce data is invalid or unavailable.

Each issue includes:

- product name and ID;
- severity;
- affected capability;
- the exact WooCommerce attribute or field to correct;
- a plain-language explanation;
- a direct link to edit the product.

The report is read-only. It never changes product attributes or creates plugin-owned profile metadata.

### Recommendation Engine

The recommendation engine is a pure PHP service. It receives normalized answers and an internal catalog and returns ranked matches, rejected products with machine-readable reasons, shopper notices, and up to three public recommendations.

The refactor preserves these business rules:

- allowed answer enums and conditional answers;
- budget clamping from 500,000 to 500,000,000 toman;
- optional budget flexibility capped at 20 percent;
- strict connection, device, ANC, multipoint, fit, AUX microphone, and gaming-latency constraints;
- existing commute and work suitability bonuses;
- price-first variant selection;
- inventory depth only as a tie-break after equal suitability and price;
- deduplicated parent-managed stock in tie-breaking;
- primary, lower-cost, additional-feature, and alternative recommendation roles;
- the existing safety notices for noisy calls, comfort, USB-C audio, gaming latency, and unverified battery comparisons;
- no silent substitution and no automatic budget relaxation.

Internal ranking scores, stock quantities, reserved quantities, and rejection details are never included in the public product response.

### REST API

Two same-origin POST routes remain under `/wp-json/ts-sound/v1`:

- `/recommend` normalizes answers, builds the live catalog, runs the engine, and returns public recommendation DTOs;
- `/validate` rebuilds the live catalog and confirms the exact selected product, variation, and price before returning the product URL.

Both routes:

- accept JSON only;
- enforce a 4,096-byte request-body limit;
- validate all enum and conditional fields;
- use controlled `400`, `409`, and `503` responses;
- set `Cache-Control: no-store` and related no-cache/no-index headers;
- log exception classes without exposing internal messages to visitors.

`/recommend` returns a short response expiry so the browser can disable stale purchase actions. `/validate` returns `409` when the selected variation, price, or availability changed.

The public API contains only the fields needed to render recommendations. It excludes stock depth, held reservations, cost data, internal ranking scores, and administration diagnostics.

### Attribution

After successful final validation, the server creates a random, short-lived attribution token stored as a WordPress transient for 30 minutes. The destination URL includes that token and the selected variation attributes.

On the matching WooCommerce product page, the token is copied into the WooCommerce session only if it resolves to that product. Order metadata is attached only when the exact product and variation are present in the order. Classic checkout and Store API checkout remain supported.

Attribution contains coarse answer enums and commerce identifiers. It does not store personal free text or contact information.

## Frontend Design

### Visual Contract

The refactor preserves:

- Persian text and RTL direction;
- hero hierarchy and product artwork treatment;
- earbud/headphone flow switch;
- preset entry points;
- conditional quiz sequence and progress display;
- answer trail and feedback;
- budget slider, presets, exact input, and 20-percent option;
- loading, freshness, changed-inventory, error, and empty states;
- recommendation cards, variation selection, reasons, cautions, sources, and purchase action;
- comparison table;
- editorial and FAQ sections;
- existing desktop, tablet, and mobile layout behavior;
- reduced-motion behavior and the page's motion control;
- keyboard focus, semantic headings, live regions, and status announcements.

No product name, ID, image, URL, or recommendation is hardcoded in a view. Hero products are selected deterministically from eligible WooCommerce products for each configured category. If a category has no eligible hero, the layout renders a neutral fallback without a broken image.

### Theme Integration

The amazing theme already owns visitor appearance through:

- the `tsThemeMode` local-storage value;
- the `tsMode` cookie;
- `body.dark`;
- `data-theme` on the document and body;
- `.toggle-darkmode` controls;
- semantic CSS custom properties from `theme-system.css`.

The plugin will not create or persist its own light/dark preference. It will remove the hardcoded `data-appearance="dark"` state and style against the theme's semantic tokens. The guide responds immediately whenever the theme changes `body.dark` or `data-theme`.

The plugin may provide scoped fallback token values so its content remains readable if the theme stylesheet is unavailable, but those fallbacks must not override the theme when its tokens exist.

### Frontend Modules

The current monolithic browser script will be split by responsibility:

- immutable question definitions and conditional question construction;
- guide state and transitions;
- REST client and response validation;
- number and presentation formatting;
- accessible rendering helpers;
- results and comparison rendering;
- analytics adapter;
- DOM controller and event wiring.

The modules communicate through explicit data objects and events. They do not read hidden global product catalogs. WordPress supplies only public bootstrap configuration.

Matomo tracking remains optional. When `_paq` is available, the guide emits the existing coarse answer and commerce events. The guide must continue to work when Matomo is absent or blocked.

## PHP Organization

The implementation plan will assign exact paths, but the code is organized around these responsibilities:

- plugin bootstrap and lifecycle;
- settings and admin screens;
- landing-page routing and rendering;
- WooCommerce catalog queries;
- capability registry and product mapping;
- catalog-health diagnostics;
- answer normalization;
- recommendation policy;
- public DTO mapping;
- REST recommendation controller;
- REST validation controller;
- attribution service;
- PHP view components.

Classes will use the plugin namespace and WordPress coding conventions. WordPress and WooCommerce access will be placed behind narrow adapters so the recommendation and validation rules can be tested without a database.

## Error Handling

- An unconfigured landing page produces no automatic frontend replacement.
- A deleted or non-public selected page is flagged in settings and ignored on the frontend.
- Missing WooCommerce produces an administrator notice and controlled `503` API responses.
- A catalog query failure or unsupported currency produces a controlled `503` response.
- Invalid or incomplete answers produce `400` without querying recommendations.
- A changed product, variation, price, or availability produces `409` during final validation.
- A stale recommendation disables purchase actions until refreshed.
- A failed network request keeps the visitor on the guide, explains that availability could not be verified, and offers a retry.
- Unknown recommendation capabilities never become affirmative claims.
- Missing hero imagery never creates a broken image element.

## Security and Privacy

- Settings and health pages require appropriate WordPress capabilities.
- Settings writes require WordPress nonces and registered sanitizers.
- All rendered dynamic text, attributes, and URLs are escaped for their output context.
- REST input is allow-listed, size-limited, normalized, and type-checked.
- Purchase URLs must use HTTP or HTTPS and match the configured site origin.
- The public DTO is explicitly allow-listed.
- Administrative diagnostics are never returned from public REST endpoints.
- Attribution tokens use cryptographically strong WordPress password generation and expire after 30 minutes.
- Analytics contains no free-text answers, phone numbers, or other personal input.

## Testing Strategy

### Characterization and Unit Tests

Before changing recommendation behavior, characterization fixtures will pin representative current outcomes for:

- each product flow;
- each connection constraint;
- required ANC and multipoint paths;
- open and silicone fit;
- AUX calling support;
- gaming over wireless;
- exact budget and 20-percent flexibility boundaries;
- unavailable and backordered variants;
- equal-score, equal-price inventory tie-breaking;
- empty-result notices.

Pure PHP tests cover answer normalization, availability, ranking, public DTOs, capability mapping, health diagnostics, validation decisions, and attribution matching. WordPress/WooCommerce adapters are tested at their boundary with complete product fixtures.

### Frontend Tests

JavaScript tests cover:

- conditional question construction;
- forward, backward, edit, close, and restart transitions;
- Persian and Arabic numeral budget parsing;
- validation of budget bounds;
- request timeout, abort, stale response, `409`, and `503` behavior;
- variant selection and price/image updates;
- comparison rendering;
- stale recommendation expiry;
- analytics absence;
- response to the theme's light/dark state.

### Browser and Visual Tests

Browser tests run the complete guide at desktop and mobile widths in light and dark modes. They exercise successful recommendations, empty results, network failure, changed inventory, comparison, and final validation.

Reference screenshots of the existing guide establish the visual baseline for the hero, quiz, results, empty state, and comparison table. The refactored output is compared against those references with documented tolerances for dynamic product content, font rasterization, and animation timing.

### Verification

Completion requires:

- all PHP and JavaScript tests passing;
- WordPress coding and PHP syntax checks passing;
- browser workflows passing at supported viewport sizes;
- light and dark visual comparisons reviewed;
- a manual checklist confirming every success criterion in this specification;
- no production reference to `profiles.json` or hardcoded product identities;
- no unexpected PHP warnings, browser console errors, or accessibility-critical violations.

## Rollout and Compatibility

- The refactor receives a major plugin version bump.
- Activation does not modify WooCommerce products, prices, stock, attributes, pages, or theme settings.
- Existing shortcode pages continue to render the guide.
- The configured landing page is the preferred installation path.
- The old JSON profile file is removed only after the WooCommerce-only catalog path and tests are green.
- Existing REST route names and attribution metadata key remain compatible unless a verified security problem requires a documented change.
- The README documents installation, landing-page selection, required WooCommerce attributes, catalog-health resolution, REST caching exclusions, and verification steps.

## Acceptance Checklist

- An administrator can select a landing page and both product categories.
- The configured page keeps the amazing theme header, footer, and required navigation while replacing all other page content.
- The guide renders exactly once on the configured page.
- No JSON or plugin-owned product profile database is used.
- Unknown fields are visible in the admin and never inferred for shoppers.
- Products with unknown irrelevant fields can still be recommended.
- Products with unknown required fields are excluded from that specific recommendation path.
- Live WooCommerce price, variation, and availability are checked for recommendations and checked again before purchase.
- The current recommendation rules and shopper notices remain covered by characterization tests.
- The guide follows the theme's current user-selected appearance without a second toggle or storage key.
- Light and dark desktop/mobile views match the approved visual baseline.
- Public responses expose no internal inventory depth, reservations, or ranking data.
- Existing attribution works for classic and block checkout flows.
- The plugin remains usable through the legacy shortcode during transition.
