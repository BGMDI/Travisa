<?php
defined('ABSPATH') || exit;
class TraVisa_Frontend {
    public static function boot(): void {
        add_shortcode('travisa_services', [self::class,'render']);
        foreach (['travisa_quote'=>'quote','travisa_add'=>'add'] as $action=>$method) {
            add_action('wp_ajax_' . $action, [self::class,$method]); add_action('wp_ajax_nopriv_' . $action, [self::class,$method]);
        }
    }
    public static function request(): array {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { throw new InvalidArgumentException('طريقة الطلب غير مسموحة.'); }
        check_ajax_referer('travisa_front','nonce');
        $raw = wp_unslash($_POST['request'] ?? '');
        if (!is_string($raw) || strlen($raw) > 20000) { throw new InvalidArgumentException('طلب غير صالح.'); }
        $data = json_decode($raw,true); if (!is_array($data)) { throw new InvalidArgumentException('طلب غير صالح.'); } return $data;
    }
    public static function calculate(array $request): array {
        if (!function_exists('WC') || !function_exists('wc_get_price_decimals')) { throw new RuntimeException('المتجر غير متاح حاليًا.'); }
        if (wc_get_price_decimals() !== 2) { throw new RuntimeException('يجب ضبط العملة إلى منزلتين عشريتين لاستخدام TraVisa.'); }
        $version = TraVisa_Store::version();
        $rules = TraVisa_Store::rules();
        $q = TraVisa_Domain::quote(TraVisa_Store::rows($version), $request, TraVisa_Store::settings(), $rules);
        $q['version'] = $version; $q['currency'] = get_woocommerce_currency();
        $q['rules'] = hash('sha256',wp_json_encode($rules));
        $q['signature'] = hash_hmac('sha256', wp_json_encode($q), wp_salt('auth'));
        return $q;
    }
    public static function quote(): void {
        try { wp_send_json_success(self::calculate(self::request())); }
        catch (Throwable $e) { wp_send_json_error(['message'=>$e->getMessage()],400); }
    }
    public static function add(): void {
        try {
            $request = self::request(); $quote = self::calculate($request);
            $signature = $_POST['signature'] ?? '';
            if (!is_string($signature) || !preg_match('/^[a-f0-9]{64}$/D',$signature)) { throw new InvalidArgumentException('بصمة السعر غير صالحة.'); }
            if (!hash_equals($quote['signature'],$signature)) { throw new RuntimeException('تغير السعر أو الاختيارات. احسب الإجمالي مرة أخرى قبل الإضافة.'); }
            if (!WC()->cart) { wc_load_cart(); }
            $key = TraVisa_WooCommerce::add($request,$quote);
            if (!$key) { throw new RuntimeException('تعذرت الإضافة إلى السلة.'); }
            wp_send_json_success(['url'=>wc_get_cart_url()]);
        } catch (Throwable $e) { wp_send_json_error(['message'=>$e->getMessage()],400); }
    }
    public static function render(): string {
        if (!function_exists('WC')) { return '<p dir="rtl">خدمات الحجز غير متاحة حاليًا.</p>'; }
        wp_enqueue_style('travisa',plugins_url('assets/customer.css',TRAVISA_FILE),[],TRAVISA_VERSION);
        wp_enqueue_script('travisa',plugins_url('assets/customer.js',TRAVISA_FILE),[],TRAVISA_VERSION,true);
        $catalog = []; $settings = TraVisa_Store::settings();
        foreach (TraVisa_Store::rows() as $r) { if (TraVisa_Domain::visible($r,$settings)) { $catalog[] = array_intersect_key($r,array_flip(['id','kind','name','country','category','tier'])); } }
        wp_localize_script('travisa','TraVisa', ['url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('travisa_front'),'catalog'=>$catalog,'tiers'=>TraVisa_Domain::TIERS,'currency'=>get_woocommerce_currency()]);
        ob_start(); ?>
        <section class="tv" dir="rtl" aria-label="حجز خدمات TraVisa">
          <header class="tv-heading"><div><span class="tv-brand" dir="ltr">TraVisa</span><p>خدمات السفر والتأشيرات</p></div><div class="tv-stamp" aria-hidden="true">رحلتك<br>تبدأ هنا</div></header>
          <h2>جهّز خطوتك القادمة.</h2><p class="tv-intro">اختر ما تحتاجه، وراجع تكلفة الخدمة قبل إضافتها إلى السلة.</p>
          <noscript><p>فعّل JavaScript في متصفحك لحساب الحجز وإضافته إلى السلة.</p></noscript>
          <?php if (!$catalog): ?><div class="tv-empty">الخدمات قيد التحديث. يرجى العودة لاحقًا.</div><?php else: ?>
          <form class="tv-form"><div class="tv-layout"><div class="tv-choices">
            <fieldset><legend>الوجهة والخدمة</legend><div class="tv-grid"><label>الدولة<select class="tv-country"><option value="">خدمات مستقلة فقط</option></select></label><label>مستوى الخدمة<select class="tv-tier" disabled></select></label></div>
            <label class="tv-appointment-label">نوع الموعد<select class="tv-appointment"><option value="normal">عادي</option><option value="vip">VIP</option></select></label><p class="tv-home-note" hidden>الزيارة المنزلية تستخدم رسوم الموعد المنزلي / VIP المسجلة.</p></fieldset>
            <fieldset class="tv-travelers-box" hidden><legend>المسافرون</legend><p>أدخل العدد لكل فئة كما هي مسجلة للخدمة المختارة.</p><div class="tv-travelers"></div></fieldset>
            <fieldset><legend>خدمات مستقلة</legend><p>يمكن طلبها منفردة أو إضافتها للحجز.</p><div class="tv-extras"></div></fieldset>
          </div><aside class="tv-summary" aria-label="ملخص التكلفة"><span class="tv-eyebrow">ملخص حجزك</span><div class="tv-result" aria-live="polite">اختر الخدمات والمسافرين لعرض السعر.</div><button class="tv-calculate" type="submit">حساب التكلفة</button><button class="tv-add" type="button" disabled>إضافة إلى السلة</button><p class="tv-note">رسوم التأشيرة والشحن معلوماتية، وتُدفع للجهة المختصة. يتم احتساب الضرائب وفق إعدادات المتجر عند إتمام الطلب.</p><p class="tv-status" role="status" aria-live="polite"></p></aside></div></form>
          <?php endif; ?>
        </section>
        <?php return ob_get_clean();
    }
}
