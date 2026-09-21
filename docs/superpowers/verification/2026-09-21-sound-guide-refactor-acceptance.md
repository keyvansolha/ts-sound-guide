# Sound Guide refactor acceptance — 2026-09-21

Implementation target: `docs/superpowers/specs/2026-09-20-sound-guide-refactor-design.md`

## Automated acceptance

- Legacy characterization: 21 frozen scenarios verified.
- PHP parity: 21/21 scenarios identical.
- PHP engine/DTO/capability tests: 32/32.
- Plugin bootstrap metadata: 5/5.
- WooCommerce catalog and health: 19/19 for IRT, including the real term-ID query contract, applicable capability fields, form-factor diagnostics, and unpublished products, plus IRR conversion and unsupported-currency checks.
- REST controller boundary: 31/31, including strict types/allow-lists, 4 KB limit, `400`/`409`/`503`, no-store headers, same-origin URLs, stale health, and exact revalidation.
- Attribution: 5/5, including simple and variable products and exact variation matching.
- Landing/composition integration: 10/10, including configured-page replacement, legacy-shortcode template preservation, and configured category URLs.
- Browser-independent JS: 22/22, including the theme-token bridge contract.
- DOM workflow: 30/30, including stale conditional-answer pruning and configured category links.
- Real-Chrome workflow and visual suite: 37/37.
- PHP syntax: 30 files; JavaScript syntax and `git diff --check` clean.

The complete command matrix is documented in `README.md` and was run from the plugin root with an exit status of zero.

## Real-browser harness acceptance

Checked automatically with the production CSS and ES modules through `npm run test:browser`:

- Desktop and 390×844 mobile layouts in light and dark theme states.
- Successful quiz through recommendation cards and comparison table.
- Empty catalog, server error with retry, and changed-stock/price flows.
- Back, edit, close, and restart navigation.
- No horizontal overflow at the mobile width.
- No guide-owned `data-appearance` attribute and no browser console errors during the checked flows.
- Visual structure remains consistent with the legacy guide while using theme-owned tokens.

Eight project-owned PNG baselines cover desktop/mobile light/dark heroes plus quiz, results, empty, and comparison states. Baselines were reviewed against the legacy guide from repository history. Automated comparison permits at most 1% changed pixels at a 0.12 pixel threshold, covering minor font rasterization without accepting structural or palette drift.

## Staging-only rollout checks

The following need the real WordPress database, WooCommerce checkout, theme controls, and CDN and therefore remain deployment checks rather than local implementation checks:

- Confirm the configured production page keeps the live theme header/footer/navigation and renders exactly one guide.
- Inspect real product imagery, variation labels, prices, stock, and the catalog-health report.
- Complete classic and Store API checkout flows and confirm `_ts_sound_guide` order metadata.
- Toggle the live theme control in both directions and inspect desktop/mobile pages.
- Confirm both REST paths are excluded from every page/CDN cache.
- Review production PHP/browser logs and run the site's accessibility tooling.

These checks are also included in `README.md` so they travel with the plugin.
