=== TraVisa Services ===
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 1.1.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires Plugins: woocommerce

Arabic travel services with validated XLSX imports, immutable pricing versions, visibility controls and server-side WooCommerce pricing.

== Installation ==
Install and activate WooCommerce, then upload this plugin ZIP. Add [travisa_services] to a page. Import a valid workbook through the TraVisa menu. No live prices are installed automatically.

Read README-AR.md for pricing defaults, permissions, schema, installation, limits and the available-source caveat.

== Changelog ==

= 1.1.4 =
* إصلاح حفظ معاينة الاستيراد عبر ضغطها والتحقق من استعادتها، لتجاوز حدود التخزين المؤقت وقاعدة البيانات.

= 1.1.3 =
* إضافة رسوم التأشيرة للزيارة المنزلية وVIP PRO فقط، مع احتساب موعد عادي أو VIP واحد حسب اختيار العميل.

= 1.1.2 =
* دعم ورقة5 الجديدة للخدمات المستقلة وتجاهل أوصاف الخدمات التي لا تملك صف تسعير.

= 1.1.1 =
* تتجاهل المعاينة والاستيراد أي خدمة لا تحتوي أي سعر، وتعرض عدد الخدمات المتجاهلة للأدمن.
= 1.1.0 =
* Import sheet1 service descriptions, preview/version changes and display escaped text to customers.

= 1.0.2 =
* Restrict all TraVisa administration and price import/rollback to manage_options.

= 1.0.1 =
Security: bound XML complexity, sheet references, cells and shared strings; reject ambiguous XLSX structures. Cap cart bookings; validate request shapes and missing order metadata.
= 1.0.0 =
Initial release.
