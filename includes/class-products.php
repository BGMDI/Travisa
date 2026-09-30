<?php
defined('ABSPATH') || exit;
class TraVisa_Products {
    public const META_KEY = '_travisa_catalog_key';
    public static function boot(): void {
        add_action('travisa_prices_committed', [self::class,'on_commit'], 10, 2);
        add_action('admin_init', [self::class,'maybe_sync']);
        add_action('woocommerce_single_product_summary', [self::class,'render_product_options'], 25);
        add_filter('woocommerce_is_purchasable', [self::class,'purchasable'], 10, 2);
        add_filter('woocommerce_get_price_html', [self::class,'price_html'], 20, 2);
    }
    public static function group(array $row): string {
        $name = $row['name'] ?? '';
        if (str_contains($name,'رخصة قيادة')) { return 'license'; }
        if (str_contains($name,'فندقي')) { return 'hotel'; }
        if (str_contains($name,'طيران')) { return 'flight'; }
        if (str_contains($name,'تدقيق')) { return 'audit'; }
        return 'other';
    }
    public static function synthetic_insurance(array $rows): array {
        $out = [];
        foreach ($rows as $row) {
            if (($row['kind'] ?? '') !== 'visa' || ($row['insurance'] ?? null) === null) { continue; }
            $derived = [
                'kind'=>'standalone','name'=>'التأمين الطبي للسفر','country'=>$row['country'],'category'=>$row['category'],
                'tier'=>'normal','details'=>'تأمين طبي للسفر إلى ' . $row['country'] . ' — ' . $row['category'],
                'source'=>'مشتق من ' . ($row['source'] ?? ''),'service'=>$row['insurance'],'file'=>null,'print'=>null,'extra'=>null,
                'insurance'=>null,'appointment_normal'=>null,'appointment_vip'=>null,'visa_normal'=>null,'visa_vip'=>null,
                'shipping'=>null,'discount_bp'=>0,
            ];
            $derived['id'] = TraVisa_Domain::key($derived); $out[$derived['id']] = $derived;
        }
        return $out;
    }
    public static function catalog(): array {
        $rows = TraVisa_Store::rows();
        return $rows + self::synthetic_insurance($rows);
    }
    public static function product_scope(int $product_id): array|false {
        $key = (string)get_post_meta($product_id,self::META_KEY,true);
        if (!str_contains($key,':')) { return false; }
        [$type,$value] = explode(':',$key,2);
        return in_array($type,['country','service'],true) && $value !== '' ? [$type,$value] : false;
    }
    public static function scoped_rows(string $type,string $value): array {
        $rows = self::catalog();
        if ($type === 'country') { return array_filter($rows,static fn($r)=>($r['kind']??'')==='visa' && ($r['country']??'')===$value); }
        if ($value === 'insurance') { return array_filter($rows,static fn($r)=>($r['source']??'')!=='' && str_starts_with($r['source'],'مشتق من ')); }
        return array_filter($rows,static fn($r)=>($r['kind']??'')==='standalone' && self::group($r)===$value);
    }
    public static function render_product_options(): void {
        global $product; if (!$product instanceof WC_Product) { return; }
        $scope = self::product_scope($product->get_id()); if (!$scope) { return; }
        echo TraVisa_Frontend::render(['scope_type'=>$scope[0],'scope_value'=>$scope[1],'product_id'=>$product->get_id(),'compact'=>1]);
    }
    public static function purchasable(bool $purchasable,$product): bool {
        return $product instanceof WC_Product && self::product_scope($product->get_id()) ? false : $purchasable;
    }
    public static function price_html(string $html,$product): string {
        if (!$product instanceof WC_Product || !self::product_scope($product->get_id())) { return $html; }
        return $product->get_price() === '' ? '<span class="price">اختر الخدمات لعرض السعر</span>' : '<span class="price">يبدأ من ' . wc_price((float)$product->get_price()) . '</span>';
    }
    private static function groups(array $rows): array {
        $groups = [];
        foreach ($rows as $row) {
            if (($row['kind']??'') === 'visa') { $groups['country:'.$row['country']] = ['country',$row['country'],$row['country']]; }
            elseif (($g=self::group($row)) !== 'other') { $labels=['license'=>'رخصة القيادة الدولية','hotel'=>'حجز فندقي','flight'=>'حجز تذكرة طيران','audit'=>'تدقيق الأوراق']; $groups['service:'.$g]=['service',$g,$labels[$g]]; }
        }
        if (self::synthetic_insurance($rows)) { $groups['service:insurance']=['service','insurance','التأمين الطبي للسفر']; }
        return $groups;
    }
    private static function minimum(array $rows): string {
        $prices=[]; $rules=TraVisa_Store::rules();
        foreach ($rows as $row) {
            foreach (['normal','vip'] as $appointment) {
                try { $q=TraVisa_Domain::quote([$row['id']=>$row],['items'=>[['id'=>$row['id'],'quantity'=>1]],'appointment'=>$appointment],[],$rules); if($q['total']>0){$prices[]=$q['total'];} } catch(Throwable $e) {}
            }
        }
        return $prices ? number_format(min($prices)/100,2,'.','') : '';
    }
    private static function category(string $name): int {
        $term=term_exists($name,'product_cat'); if(!$term){$term=wp_insert_term($name,'product_cat');}
        return is_wp_error($term)?0:(int)(is_array($term)?$term['term_id']:$term);
    }
    private static function find(string $key): int {
        $ids=get_posts(['post_type'=>'product','post_status'=>'any','numberposts'=>1,'fields'=>'ids','meta_key'=>self::META_KEY,'meta_value'=>$key]);
        return $ids ? (int)$ids[0] : 0;
    }
    private static function legacy_image(string $name): int {
        $post=get_page_by_title($name,OBJECT,'product'); return $post ? (int)get_post_thumbnail_id($post->ID) : 0;
    }
    public static function sync(array $rows): array {
        if (!class_exists('WC_Product_Simple')) { throw new RuntimeException('WooCommerce غير متاح لإنشاء المنتجات.'); }
        $groups=self::groups($rows); $active=[]; $countryCat=self::category('تأشيرات الدول'); $serviceCat=self::category('خدمات السفر');
        foreach($groups as $key=>[$type,$value,$name]) {
            $id=self::find($key); $product=$id?wc_get_product($id):new WC_Product_Simple(); if(!$product){$product=new WC_Product_Simple();}
            $isNew=!$id; $product->set_name($name); $product->set_status('publish'); $product->set_catalog_visibility('visible'); $product->set_virtual(true); $product->set_tax_status('taxable');
            $scoped=self::scoped_rows($type,$value); $product->set_regular_price(self::minimum($scoped));
            $product->set_short_description($type==='country'?'اختر مستوى الخدمة والفئة وعدد المسافرين.':'اختر الخدمات المطلوبة ثم راجع الإجمالي قبل الدفع.');
            $details=array_values(array_filter(array_unique(array_map(static fn($r)=>trim((string)($r['details']??'')),$scoped)))); if($details){$product->set_description(implode("\n\n",$details));}
            $product->set_category_ids(array_values(array_filter([$type==='country'?$countryCat:$serviceCat]))); $product->update_meta_data(self::META_KEY,$key); $product->update_meta_data('_travisa_catalog_product',1);
            if($isNew){$image=self::legacy_image($name); if($image){$product->set_image_id($image);}}
            $id=$product->save(); if(!$id){throw new RuntimeException('تعذر إنشاء منتج: '.$name);} $active[]=$id;
        }
        $managed=get_posts(['post_type'=>'product','post_status'=>'any','numberposts'=>-1,'fields'=>'ids','meta_key'=>'_travisa_catalog_product','meta_value'=>1]);
        foreach($managed as $id){if(!in_array((int)$id,$active,true)){wp_update_post(['ID'=>(int)$id,'post_status'=>'draft']);}}
        update_option('travisa_catalog_product_ids',$active,false); return $active;
    }
    public static function maybe_sync(): void {
        if (!current_user_can('manage_options') || !class_exists('WooCommerce')) { return; }
        $version = TraVisa_Store::version(); if (!$version || (int)get_option('travisa_catalog_synced_version',0) === $version) { return; }
        self::on_commit(array_values(TraVisa_Store::rows($version)),$version);
    }
    public static function on_commit(array $rows,int $version): void {
        try { self::sync($rows); update_option('travisa_catalog_synced_version',$version,false); delete_option('travisa_product_sync_error'); }
        catch(Throwable $e){ update_option('travisa_product_sync_error',$e->getMessage(),false); }
    }
}