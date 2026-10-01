# Catalog Health Tabs Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split catalog-health problems by current sellability, add a live refresh action, correct false-positive capability diagnostics, and remediate the remaining source-backed product attributes.

**Architecture:** `CatalogHealth` will expose separate `information_issues` and `commerce_issues` collections while preserving the existing aggregate `issues` contract. `AdminScreens` will render those collections as two accessible server-rendered tabs and provide a GET refresh link. Capability interpretation will be narrowed to evidence that matches the shopper flow before any live WooCommerce data is changed.

**Tech Stack:** PHP 8, WordPress/WooCommerce, existing PHP test harness, browser-admin verification.

**Spec:** `docs/superpowers/specs/2026-10-01-catalog-health-tabs-design.md`

## Global Constraints

- Product health remains read-only; only the separately audited product remediation may write WooCommerce attributes.
- USB-C charging is never evidence of USB-C audio.
- Existing public recommendation and validation behavior must remain backward compatible.
- No new runtime dependency or JavaScript framework.

## Review Focus

- A product with multiple commerce blockers appears only in the commerce tab and retains every blocker row.
- An eligible product with multiple specification warnings appears only in the information tab.
- A stock-status transition changes the product's tab on the next report request without stored state.
- Empty issue collections render a clear empty state without malformed tab markup.
- Refresh preserves the settings page route and cannot submit or overwrite settings.

---

### Task 1: Split the report contract by issue class

**Files:**
- Modify: `tests/unit/catalog.php`
- Modify: `includes/src/CatalogHealth.php`

**Interfaces:**
- Produces: `CatalogHealth::report()` keys `information_issues` and `commerce_issues`, each an array of existing issue rows.

- [ ] Add failing catalog tests for issue separation, blocker retention, and dynamic stock transitions.
- [ ] Run `php tests/unit/catalog.php` and confirm the new assertions fail.
- [ ] Populate both collections in `CatalogHealth::report()` while retaining aggregate `issues`.
- [ ] Run `php tests/unit/catalog.php` and confirm it passes.

### Task 2: Correct capability false positives

**Files:**
- Modify: `tests/unit/run.php`
- Modify: `tests/unit/catalog.php`
- Modify: `includes/src/CapabilityRegistry.php`
- Modify: `includes/src/CatalogHealth.php`

**Interfaces:**
- Consumes: existing tri-state `CapabilityRegistry::interpretation()` contract.
- Produces: flow-relevant warnings and explicit non-USB connection interpretation without weakening USB-C audio evidence.

- [ ] Add failing tests for Bluetooth/AUX connection values, charging-only USB-C, dual wired/wireless products, and ignored headphone ear-tip warnings.
- [ ] Run the focused unit tests and confirm the expected failures.
- [ ] Implement the smallest registry and health-scope changes that satisfy the cases.
- [ ] Run `php tests/unit/run.php` and `php tests/unit/catalog.php` and confirm they pass.

### Task 3: Render two tabs and refresh action

**Files:**
- Modify: `tests/unit/admin.php`
- Modify: `includes/src/AdminScreens.php`

**Interfaces:**
- Consumes: `information_issues` and `commerce_issues` from Task 1.
- Produces: two accessible server-rendered tab panels and a safe GET refresh URL on the same admin page.

- [ ] Add failing output tests for both tab labels, per-tab rows/counts, empty states, ARIA relationships, and refresh URL.
- [ ] Run `php tests/unit/admin.php` and confirm the assertions fail.
- [ ] Render the tab navigation, panels, reusable issue table, and refresh link with a cache-busting `ts_sound_refresh` argument.
- [ ] Run `php tests/unit/admin.php` and confirm it passes.

### Task 4: Audit and repair live product specifications

**Files:**
- Create: `docs/reports/2026-10-01-product-attribute-audit.md`

**Interfaces:**
- Consumes: corrected production report from Tasks 1–3 and official manufacturer evidence.
- Produces: an auditable product/source/value table and corresponding WooCommerce attribute edits for genuine remaining gaps only.

- [ ] Re-extract the live information-problem list after deployment.
- [ ] Research each remaining product using official manufacturer pages/manuals and record source, field, old value, and new value.
- [ ] Update only source-backed WooCommerce specification attributes through the authenticated product editor.
- [ ] Refresh the health report and confirm edited products clear or retain only documented unresolved warnings.

### Task 5: Release, deploy, and verify

**Files:**
- Modify: `ts-sound-guide.php`
- Modify: `README.md` if release notes are maintained there.

**Interfaces:**
- Produces: a versioned production ZIP whose top-level directory is `ts-sound-guide-main/`.

- [ ] Run all PHP, JS, characterization, and browser-test commands available in the repository.
- [ ] Bump the patch version and build the production archive from committed runtime files.
- [ ] Commit the implementation, tests, design/plan, and audit record.
- [ ] Install the archive over the active production plugin.
- [ ] Verify the active version, both admin tabs, refresh behavior, live issue movement, REST responses, and a public recommendation smoke test.
