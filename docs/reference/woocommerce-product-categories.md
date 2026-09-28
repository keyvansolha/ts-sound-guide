# WooCommerce Product Categories

Snapshot of the store's `product_cat` terms. This is the data the capability
registry keys its category signals on (see
`includes/src/CapabilityRegistry.php` and the "Recommendation attributes"
section of the plugin README).

Capability authority is taken only from categories that *state* a capability —
chiefly the «ویژگی ها» feature tree. Form, type, and use categories grant
nothing.

| ID | Category | Parent ID | Grants |
|---:|---|---:|---|
| 129 | اسپیکر | 0 | — |
| 130 | اسپیکر قابل حمل | 129 | — |
| 137 | اسپیکر خانگی | 129 | — |
| 132 | ساندبار | 129 | — |
| 131 | سینمای خانگی | 129 | — |
| 133 | اسپیکر کامپیوتر | 129 | — |
| 134 | اسپیکر مانیتورینگ | 129 | — |
| 136 | اسپیکر های فای | 129 | — |
| 135 | اسپیکر اجرای زنده | 129 | — |
| 138 | اسپیکر سقفی | 129 | — |
| 34822 | اسپیکر دیواری \| دکوراتیو | 129 | — |
| 116 | هدفون | 0 | flow: headphones |
| 122 | هدفون گیمینگ | 116 | — (use, not a capability) |
| 120 | هدفون ورزشی | 116 | — |
| 126 | هدست | 116 | — |
| 119 | هدفون بی سیم | 116 | wireless |
| 127 | هدفون دور گوشی | 116 | — (form) |
| 121 | هدفون دی جی | 116 | — |
| 118 | هدفون روی گوشی | 116 | — (form) |
| 124 | هندزفری گردنی | 116 | — (form) |
| 123 | هدفون مانیتورینگ | 116 | — |
| 125 | هندزفری | 116 | flow: earbuds |
| 128 | ایرباد | 125 | — (form) |
| 117 | ایرفون | 125 | — (form) |
| 105 | سایر محصولات | 0 | — |
| 39691 | پاور استیشن | 105 | — |
| 40401 | ساعت و مچ بند هوشمند | 105 | — |
| 40402 | ساعت هوشمند | 40401 | — |
| 40403 | مچ بند هوشمند | 40401 | — |
| 41678 | ورک استیشن هوش مصنوعی | 105 | — |
| 36148 | ویدئو پروژکتور | 105 | — |
| 114 | کیف و کاور | 105 | — |
| 111 | میکروفون | 105 | — (a product type, not AUX microphone support) |
| 113 | شارژر | 105 | — |
| 107 | پاور بانک | 105 | — |
| 34743 | رقص نور | 105 | — |
| 33034 | میکسر و کارت صدا | 105 | — |
| 109 | پلیر | 105 | — |
| 40594 | ترن تیبل | 109 | — |
| 115 | عینک هوشمند | 105 | — |
| 112 | کابل اتصال | 105 | — |
| 35647 | کابل شارژ | 105 | — |
| 35412 | فلش مموری | 105 | — |
| 35583 | جارو رباتیک | 105 | — |
| 35649 | پایه نگهدارنده | 105 | — |
| 35650 | ترازو دیجیتال | 105 | — |
| 106 | لوازم جانبی | 105 | — |
| 139 | ویژگی ها | 0 | — (feature tree root) |
| 144 | بی سیم \| بلوتوث | 139 | **wireless = yes** |
| 34745 | با سیم \| سیمی | 139 | **wireless = no** |
| 140 | پخش سوراند | 139 | — |
| 149 | پخش یو اس بی | 139 | — (unclaimed: USB playback is not necessarily USB-C audio) |
| 148 | دالبی سوراند | 139 | — |
| 142 | شارژ سریع | 139 | — |
| 145 | صدای 360 درجه | 139 | — |
| 146 | ضد آب | 139 | — |
| 147 | قابل شارژ | 139 | — |
| 141 | مقاوم در برابر آب | 139 | — |
| 143 | مکالمه تلفنی | 139 | — (unclaimed: call microphone ≠ microphone over AUX) |
| 150 | نورپردازی | 139 | — |
| 151 | نویز کنسلینگ | 139 | **ANC = yes** |
| 32132 | کیف پول | 0 | — |

## Flow categories

The plugin settings select one earbud and one headphone category. In this store
those are `125` «هندزفری» (with children `117` ایرفون and `128` ایرباد) and
`116` «هدفون». The plugin resolves them by the existing `handsfree` and
`headphone` slugs on activation; descriptions in the admin screen show the
resolved term IDs.

## Known gaps worth a decision

- `149` «پخش یو اس بی» — if every product carrying it is USB-C audio, it can be
  added to the `usbc` signals.
- `143` «مکالمه تلفنی» — if it is only applied to products whose wired (AUX)
  microphone works, it can be added to the `auxMic` signals.
- No category states tip material, so silicone/open fit stays attribute-only.
