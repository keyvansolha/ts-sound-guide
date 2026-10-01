# TS Sound Guide product-attribute audit — 2026-10-01

## Result

The pre-fix report named 17 currently sellable products as incomplete. After deploying the corrected 3.2.3 diagnostics and pressing «رفرش گزارش», 16 cleared without product-data changes because their rows were false positives. One genuine ambiguity remained and was corrected from official JBL evidence. The refreshed information tab now contains zero rows.

## Initial 17-product disposition

| WooCommerce ID | Product | Disposition after corrected diagnostics |
|---:|---|---|
| 11474 | JBL Quantum 100 | Cleared: non-USB wired mode is a valid USB-C-audio “no”; headphone ear tips are irrelevant. |
| 11531 | JBL Quantum 400 | Genuine ambiguity; source-backed attribute correction recorded below. |
| 148012 | JBL Tune 520BT | Cleared: Bluetooth is a valid non-USB audio mode; headphone ear tips are irrelevant. |
| 169667 | Soundcore Life Q20i A3004 | Cleared: wired capability does not negate explicit Bluetooth; headphone ear tips are irrelevant. |
| 175977 | Nothing Ear | Cleared: Bluetooth-only connection is a valid USB-C-audio “no”. |
| 237941 | Soundcore Q11i A3005 | Cleared: non-USB connection mode; headphone ear tips are irrelevant. |
| 253007 | Mifa X17 | Cleared: Bluetooth-only connection is a valid USB-C-audio “no”. |
| 257821 | JBL Tune 780NC | Cleared: headphone ear tips are irrelevant. |
| 257824 | JBL Tune 680NC | Cleared: non-USB connection mode; headphone ear tips are irrelevant. |
| 257827 | JBL Tune 530BT | Cleared: non-USB connection mode; headphone ear tips are irrelevant. |
| 272476 | CMF Buds Pro 2 | Cleared: Bluetooth-only connection is a valid USB-C-audio “no”. |
| 272477 | CMF Buds 2a | Cleared: Bluetooth-only connection is a valid USB-C-audio “no”. |
| 272480 | CMF Headphone Pro | Cleared: non-USB connection mode; headphone ear tips are irrelevant. |
| 277999 | JBL Sense Lite | Cleared: the explicit alternate open-fit value answers the fit question; Bluetooth answers USB-C audio. |
| 286814 | Baseus Bowie 30 Max | Cleared: wired capability does not negate explicit Bluetooth; non-USB connection value is valid. |
| 286816 | Baseus AirNora 2 | Cleared: Bluetooth-only connection is a valid USB-C-audio “no”. |
| 287214 | Baseus Bass BP1 NC | Cleared: Bluetooth-only connection is a valid USB-C-audio “no”. |

## Source-backed WooCommerce edit

| Product | Field | Previous value | New value | Evidence |
|---|---|---|---|---|
| JBL Quantum 400 (#11531) | `pa_connection` / «نوع اتصال» | `با سیم` | `باسیم با کانکتور USB-C؛ سازگاری پخش و تماس وابسته به دستگاه میزبان` | JBL's official product page states USB compatibility for PC, PlayStation and Mac, and the official owner's manual instructs connecting the USB-A cable to the host and its USB-C end to the headset for USB use. |

Sources:

- [JBL Quantum 400 official product page](https://www.jbl.com/QUANTUM400.html)
- [JBL Quantum 400 official owner's manual](https://support.jbl.com/on/demandware.static/-/Sites-masterCatalog_Harman/default/dw3e40e9ff/pdfs/JBL_Quantum%20400_Owner%27s%20Manual_EN.pdf)
- [JBL official supported-platforms note](https://support.jbl.com/us/en/howto/jbl-quantum-400-platform-support-us/000019306.html)

## Verification evidence

- Before the data edit on 3.2.3: information tab showed two warning rows, both for JBL Quantum 400 USB-C audio.
- After saving the exact global WooCommerce term and refreshing: «مشکلات اطلاعاتی محصولات موجود (0)» and the panel empty state «هیچ مشکل اطلاعاتی برای محصولات قابل‌خرید پیدا نشد.»
- No stock, price, publication, lifecycle, category, or variation field was changed.
