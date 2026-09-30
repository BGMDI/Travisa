<?php
defined('ABSPATH') || exit;
class TraVisa_Admin {
    public static function boot(): void {
        add_action('admin_menu', static fn() => add_menu_page('TraVisa', 'TraVisa', 'manage_options', 'travisa', [self::class,'page'], 'dashicons-airplane', 56));
        add_action('admin_post_travisa_manage', [self::class,'post']);
        add_filter('woocommerce_prevent_admin_access', static function ($prevent) {
            if (is_user_logged_in() && current_user_can('manage_options') && (($_GET['page'] ?? '') === 'travisa' || ($_POST['action'] ?? '') === 'travisa_manage')) { return false; } return $prevent;
        });
    }
    private static function key(): string { return 'travisa_preview_' . get_current_user_id(); }
    private static function redirect(string $message): void {
        set_transient('travisa_message_' . get_current_user_id(), $message, 120);
        wp_safe_redirect(admin_url('admin.php?page=travisa')); exit;
    }
    public static function post(): void {
        if (!is_user_logged_in() || !current_user_can('manage_options')) { wp_die('هذه العملية متاحة للأدمن فقط.', '', ['response'=>403]); }
        check_admin_referer('travisa_manage');
        try {
            $op = sanitize_key($_POST['op'] ?? '');
            if ($op === 'preview') {
                delete_transient(self::key());
                $file = $_FILES['workbook'] ?? [];
                if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '') || strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION)) !== 'xlsx') { throw new RuntimeException('ارفع ملف XLSX صالحًا.'); }
                $base = TraVisa_Store::version(); $result = TraVisa_Xlsx::parse($file['tmp_name']);
                $preview = $result + ['base'=>$base,'name'=>sanitize_file_name($file['name']),'hash'=>hash_file('sha256',$file['tmp_name']),'restore'=>0,'token'=>wp_generate_uuid4()];
                $preview['diff'] = TraVisa_Domain::diff(array_values(TraVisa_Store::rows($base)), $preview['rows']);
                if (!set_transient(self::key(), $preview, 30 * MINUTE_IN_SECONDS)) { throw new RuntimeException('تعذر حفظ المعاينة؛ افحص اتصال قاعدة البيانات وحدود الحجم.'); }
                self::redirect('اكتمل الفحص. راجع الأخطاء والتغييرات أدناه.');
            }
            if ($op === 'restore_preview') {
                $id = absint($_POST['version'] ?? 0);
                if (!$id || !TraVisa_Store::has_version($id)) { throw new RuntimeException('الإصدار غير موجود.'); }
                $rows = array_values(TraVisa_Store::rows($id)); $base = TraVisa_Store::version();
                if (!set_transient(self::key(), ['rows'=>$rows,'errors'=>TraVisa_Domain::validate($rows),'base'=>$base,'name'=>'استرجاع الإصدار ' . $id,'hash'=>hash('sha256',wp_json_encode($rows)),'restore'=>$id,'token'=>wp_generate_uuid4(),'diff'=>TraVisa_Domain::diff(array_values(TraVisa_Store::rows($base)),$rows)], 30 * MINUTE_IN_SECONDS)) { throw new RuntimeException('تعذر حفظ معاينة الاسترجاع.'); }
                self::redirect('راجع معاينة الاسترجاع. إعدادات العرض والتفعيل وقواعد الحساب ستبقى كما هي.');
            }
            if ($op === 'commit') {
                $p = get_transient(self::key());
                if (!$p || !hash_equals($p['token'], sanitize_text_field(wp_unslash($_POST['token'] ?? '')))) { throw new RuntimeException('انتهت صلاحية المعاينة؛ أعد الفحص.'); }
                if (!empty($p['errors'])) { throw new RuntimeException('لا يمكن الاعتماد حتى تصحيح جميع الأخطاء وإعادة الفحص.'); }
                $version = TraVisa_Store::commit($p['rows'], $p['base'], $p['name'], $p['hash'], $p['restore']);
                delete_transient(self::key()); self::redirect('تم اعتماد الإصدار ' . $version . ' وحفظ سجل العملية.');
            }
            if ($op === 'display' || $op === 'rules') {
                if (!current_user_can('manage_options')) { wp_die('إدارة العرض وقواعد الحساب متاحة للأدمن فقط.', '', ['response'=>403]); }
                if ($op === 'display') {
                    $scopes = self::scopes(); $settings = [];
                    if (!isset($_POST['display_complete']) || (int)$_POST['display_complete'] !== count($scopes)) { throw new RuntimeException('الطلب ناقص أو تغيرت البيانات. أعد تحميل الصفحة، أو ارفع max_input_vars في الاستضافة.'); }
                    foreach ($scopes as $key => $label) { $h = hash('sha256',$key); $settings[$key] = ['enabled'=>isset($_POST['enabled'][$h]),'visible'=>isset($_POST['visible'][$h])]; }
                    TraVisa_Store::save_display($settings); self::redirect('تم حفظ الإظهار والتفعيل.');
                }
                $min = filter_var($_POST['discount_min_travelers'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>100]]);
                if ($min === false) { throw new RuntimeException('حد المسافرين بين 1 و100.'); }
                update_option('travisa_rules', ['discount_min_travelers'=>$min,'home_extra_once'=>isset($_POST['home_extra_once'])?1:0,'vip_additive'=>isset($_POST['vip_additive'])?1:0], false);
                self::redirect('تم حفظ قواعد الحساب. ستُعاد مراجعة أسعار الحجوزات الموجودة بالسلة.');
            }
            throw new RuntimeException('عملية غير معروفة.');
        } catch (Throwable $e) { self::redirect('خطأ: ' . $e->getMessage()); }
    }
    private static function form(string $op, bool $upload = false): void {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"' . ($upload?' enctype="multipart/form-data"':'') . '>';
        echo '<input type="hidden" name="action" value="travisa_manage"><input type="hidden" name="op" value="' . esc_attr($op) . '">'; wp_nonce_field('travisa_manage');
    }
    private static function scopes(): array {
        $out = []; foreach (TraVisa_Store::rows() as $r) { if ($r['country'] !== '') { $out['country:' . $r['country']] = 'الدولة: ' . $r['country']; } }
        foreach (TraVisa_Domain::TIERS as $key=>$label) { $out['tier:' . $key] = 'المستوى: ' . $label; }
        foreach (TraVisa_Store::rows() as $r) { $out['row:' . $r['id']] = trim($r['country'] . ' / ' . $r['category'] . ' / ' . $r['name']); } return $out;
    }
    public static function page(): void {
        if (!current_user_can('manage_options')) { return; }
        echo '<div class="wrap" dir="rtl"><h1>TraVisa — إدارة الخدمات والأسعار</h1>';
        $message = get_transient('travisa_message_' . get_current_user_id());
        if ($message) { echo '<div class="notice notice-info"><p>' . esc_html($message) . '</p></div>'; delete_transient('travisa_message_' . get_current_user_id()); }
        echo '<p>الإصدار النشط: <strong>' . esc_html(TraVisa_Store::version()) . '</strong> · عدد السجلات: ' . count(TraVisa_Store::rows()) . '</p>';
        echo '<p>واجهة العميل: أضف <code>[travisa_services]</code> إلى صفحة ووردبريس. الاستيراد يستبدل كامل جدول الأسعار؛ السجلات المحذوفة تظهر في المعاينة. الإصدارات السابقة تبقى محفوظة.</p>';
        echo '<h2>استيراد Excel</h2><p>تُقبل ورقة4 مع ورقة2 أو ورقة5 من الملف الأصلي، أو قالب TraVisa. الخدمة التي لا تحتوي أي سعر تُتجاهل ولا تُرفع. الصفر الصريح يُعد سعرًا. لا يعتمد أي ملف به أخطاء.</p>';
        echo '<p><a href="' . esc_url(plugins_url('templates/travisa-template.xlsx',TRAVISA_FILE)) . '">تنزيل قالب الاستيراد الفارغ</a></p>';
        self::form('preview',true); echo '<label>ملف الأسعار <input type="file" name="workbook" accept=".xlsx" required></label> <button class="button button-primary">فحص الملف ومعاينة التغييرات</button></form>';
        $p = get_transient(self::key());
        if ($p) {
            echo '<hr><h2>معاينة: ' . esc_html($p['name']) . '</h2><p>المعاينة صالحة 30 دقيقة. عدد السجلات: ' . count($p['rows']) . '</p>';
            if (!empty($p['skipped'])) { echo '<div class="notice notice-warning inline"><p>تم تجاهل ' . count($p['skipped']) . ' خدمة لأنها لا تحتوي أي سعر، ولن تُرفع أو تظهر للعميل.</p></div>'; }
            if ($p['errors']) { echo '<div class="notice notice-error inline"><p><strong>الاعتماد موقوف — ' . count($p['errors']) . ' أخطاء</strong></p><ul>'; foreach ($p['errors'] as $e) { echo '<li>' . esc_html($e) . '</li>'; } echo '</ul></div>'; }
            self::diff($p['diff']);
            echo '<details><summary>عرض بيانات الأسعار المستوردة</summary><div style="overflow:auto;max-height:500px"><table class="widefat striped"><thead><tr><th>المصدر</th><th>الدولة / الفئة / الخدمة</th><th>مكونات السعر (بالعملة الأساسية)</th></tr></thead><tbody>';
            foreach ($p['rows'] as $r) {
                echo '<tr><td>' . esc_html($r['source']) . '</td><td>' . esc_html($r['country'] . ' / ' . $r['category'] . ' / ' . $r['name']) . '<details><summary>تفاصيل الخدمة</summary><p style="white-space:pre-line">' . esc_html($r['details'] ?? '') . '</p></details></td><td>';
                foreach (TraVisa_Domain::MONEY as $f) { echo esc_html($f . ': ' . ($r[$f] === null ? 'غير محدد' : number_format($r[$f]/100,2))) . ' | '; } echo 'خصم: ' . esc_html($r['discount_bp']/100) . '%</td></tr>';
            } echo '</tbody></table></div></details>';
            self::form('commit'); echo '<input type="hidden" name="token" value="' . esc_attr($p['token']) . '"><p><button class="button button-primary" ' . disabled(!empty($p['errors']),true,false) . '>اعتماد التغييرات</button></p></form>';
        }
        echo '<hr><h2>سجل الإصدارات والعمليات</h2><table class="widefat striped"><thead><tr><th>الإصدار</th><th>التاريخ والوقت (UTC)</th><th>المستخدم</th><th>العملية / الملف</th><th>التغييرات</th><th>الاسترجاع</th></tr></thead><tbody>';
        foreach (TraVisa_Store::versions() as $version) {
            echo '<tr><td>' . esc_html($version['id']) . '</td><td>' . esc_html($version['created_at']) . '</td><td>' . esc_html($version['actor_name']) . '</td><td>' . esc_html(($version['operation']==='restore'?'استرجاع':'استيراد') . ' / ' . $version['source_name']) . '</td><td><details><summary>عرض الملخص</summary>';
            self::diff(json_decode($version['summary'],true) ?: []); echo '</details></td><td>';
            self::form('restore_preview'); echo '<input type="hidden" name="version" value="' . esc_attr($version['id']) . '"><button class="button">معاينة الاسترجاع</button></form></td></tr>';
        } echo '</tbody></table><p>تظهر آخر 100 عملية؛ جميع الإصدارات محفوظة في قاعدة البيانات.</p>';
        if (current_user_can('manage_options')) {
            echo '<hr><h2>قواعد الحساب</h2><p>الخصم على رسوم الخدمة وتجهيز الملف والطباعة والخدمات الإضافية فقط. التأمين والموعد لا يدخلان في الخصم. رسوم التأشيرة والشحن معلوماتية ولا تُضاف إلى المدفوع.</p>';
            $rules = TraVisa_Store::rules(); self::form('rules');
            echo '<p><label>الحد الأدنى للمسافرين لتطبيق الخصم <input type="number" min="1" max="100" name="discount_min_travelers" value="' . esc_attr($rules['discount_min_travelers']) . '"></label></p>';
            echo '<p><label><input type="checkbox" name="home_extra_once" ' . checked($rules['home_extra_once'],1,false) . '> احتساب مكون الخدمات الإضافية للزيارة المنزلية مرة واحدة لكل فئة مختارة بدلًا من كل مسافر</label></p>';
            echo '<p><label><input type="checkbox" name="vip_additive" ' . checked($rules['vip_additive'],1,false) . '> رسوم موعد VIP إضافية فوق رسوم الموعد العادي (افتراضيًا تحل محلها)</label></p><button class="button">حفظ قواعد الحساب</button></form>';
            echo '<hr><h2>الدول والمستويات والخدمات — التفعيل والإظهار</h2><p>تعطيل دولة أو مستوى يخفي خدماته. الإخفاء والتعطيل يمنعان الشراء حتى عند إرسال طلب مباشر. إضافة الدول والخدمات وتغيير أسعارها يتم بالاستيراد.</p>';
            $settings = TraVisa_Store::settings(); self::form('display');
            echo '<table class="widefat striped"><thead><tr><th>الدولة / المستوى / الخدمة</th><th>مفعّل</th><th>ظاهر</th></tr></thead><tbody>';
            foreach (self::scopes() as $key=>$label) { $s = $settings[$key] ?? ['enabled'=>true,'visible'=>true]; $h = hash('sha256',$key); echo '<tr><td>' . esc_html($label) . '</td><td><input aria-label="تفعيل ' . esc_attr($label) . '" type="checkbox" name="enabled[' . esc_attr($h) . ']" ' . checked($s['enabled'],true,false) . '></td><td><input aria-label="إظهار ' . esc_attr($label) . '" type="checkbox" name="visible[' . esc_attr($h) . ']" ' . checked($s['visible'],true,false) . '></td></tr>'; }
            echo '</tbody></table><input type="hidden" name="display_complete" value="' . count(self::scopes()) . '"><p><button class="button button-primary">حفظ إعدادات العرض</button></p></form>';
        }
        echo '</div>';
    }
    private static function diff(array $diff): void {
        echo '<p>جديد: ' . count($diff['added'] ?? []) . ' · معدل: ' . count($diff['changed'] ?? []) . ' · محذوف: ' . count($diff['removed'] ?? []) . ' · دون تغيير: ' . esc_html($diff['unchanged'] ?? 0) . '</p>';
        $labels = ['service'=>'الخدمة','file'=>'تجهيز الملف','print'=>'الطباعة','extra'=>'الإضافات','insurance'=>'التأمين','appointment_normal'=>'موعد عادي','appointment_vip'=>'موعد VIP','visa_normal'=>'التأشيرة','visa_vip'=>'تأشيرة VIP','shipping'=>'الشحن','discount_bp'=>'الخصم %'];
        foreach (['new_countries'=>'دول جديدة','added'=>'سجلات جديدة','changed'=>'تعديلات الأسعار والتفاصيل','removed'=>'سجلات ستُحذف'] as $type=>$title) {
            if (empty($diff[$type])) { continue; } echo '<details><summary>' . esc_html($title) . '</summary><ul>';
            foreach ($diff[$type] as $entry) {
                if (is_array($entry)) {
                    echo '<li>' . esc_html($entry['name']); foreach ($entry['fields'] as $f=>$change) { if ($f === 'details') { echo '<br><strong>تفاصيل الخدمة</strong><p style="white-space:pre-line">قبل: ' . esc_html($change['before'] ?? '') . '</p><p style="white-space:pre-line">بعد: ' . esc_html($change['after'] ?? '') . '</p>'; } else { echo '<br>' . esc_html(($labels[$f] ?? $f) . ': ' . ($change['before']===null?'غير محدد':$change['before']/100) . ' ← ' . ($change['after']===null?'غير محدد':$change['after']/100)); } } echo '</li>';
                } else { echo '<li>' . esc_html($entry) . '</li>'; }
            } echo '</ul></details>';
        }
    }
}
