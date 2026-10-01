# Catalog Health Tabs and Product Data Design

## Goal

Make the Sound Guide health screen operationally useful: prioritize specification problems on products customers can buy now, keep availability/commerce blockers separate, and let an administrator refresh the live report immediately after editing a WooCommerce product.

## Report behavior

- The first tab is «مشکلات اطلاعاتی محصولات موجود». It contains only specification warnings for products that currently pass all commerce checks (published, visible, active, in stock, purchasable, and with at least one eligible offer).
- The second tab is «مشکلات موجودی و فروش». It contains publication, visibility, lifecycle, stock, purchasability, category, price, and variation-eligibility blockers.
- The report is recomputed from live WooCommerce data on every page request. When a previously unavailable product becomes eligible, it leaves the commerce tab and its specification warnings automatically appear in the information tab.
- A «رفرش گزارش» button reloads the settings page with a cache-busting query argument. It must not edit product data or save plugin settings.
- Existing readiness totals and the separate coverage-gap report remain available.

## Diagnostic correctness

The current 17-product list includes false positives and must not be “fixed” by inventing WooCommerce values:

- A general connection value such as Bluetooth or AUX is a valid explicit indication that USB-C audio is not the connection mode; it is not unreadable merely because the shared connection field contains text.
- USB-C charging must remain distinct from USB-C audio. USB-C audio is true only with explicit audio evidence.
- Silicone/open-fit diagnostics apply only to the earbuds flow; over-ear/on-ear headphones must not be marked incomplete for ear-tip material.
- A wired-capable category does not negate Bluetooth because a product can support Bluetooth and AUX simultaneously. Explicit Bluetooth product data or the wireless category remains authoritative.

## Product-data remediation

After correcting the diagnostics, re-run the live report and research only the remaining real gaps. Prefer official manufacturer product pages and manuals. Update WooCommerce only when the source establishes a safe, explicit value; do not infer USB-C audio from a charging connector, and do not change stock, price, publication, lifecycle, categories, or variations as part of specification cleanup.

## Acceptance criteria

- The admin page renders two accessible tabs with correct issue counts and a refresh button.
- Commerce-blocked products do not appear in the information-problem table.
- An eligible product's specification warnings appear in the information tab.
- The report remains dynamic across stock-status changes.
- The known false-positive classes above are covered by tests.
- Remaining live specification warnings are resolved only with source-backed WooCommerce edits.
- The packaged release is installed and both the admin report and public Sound Guide are smoke-tested.
