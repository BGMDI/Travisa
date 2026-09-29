<?php
defined('ABSPATH') || exit;
class TraVisa_WooCommerce {
    private static bool $adding = false;
    public static function boot(): void {
        add_filter('woocommerce_add_to_cart_validation',[self::class,'validate_add'],10,6);
        add_filter('woocommerce_add_cart_item_data',static function($data,$product_id,$variation_id,$quantity) {
            if (self::is_product((int)$product_id) && (!self::$adding || (float)$quantity !== 1.0 || !isset($data['travisa']))) {
                throw new Exception('أضف الحجز من نموذج TraVisa بعد حساب السعر.');
            }
            return $data;
        },10,4);
        add_action('woocommerce_before_calculate_totals',[self::class,'prices'],20);
        add_action('woocommerce_check_cart_items',[self::class,'validate_cart']);
        add_filter('woocommerce_get_item_data',[self::class,'details'],10,2);
        add_action('woocommerce_checkout_create_order_line_item',[self::class,'order'],10,4);
        add_filter('woocommerce_update_cart_validation',static function($pass,$key,$item,$quantity) { return isset($item['travisa']) && (float)$quantity !== 1.0 && (float)$quantity !== 0.0 ? false : $pass; },10,4);
        add_filter('woocommerce_cart_item_quantity',static function($html,$key,$item) { return isset($item['travisa']) ? '1' : $html; },10,3);
        add_filter('woocommerce_store_api_product_quantity_maximum',static fn($max,$product) => self::is_product($product->get_id())?1:$max,10,2);
        add_filter('woocommerce_coupon_is_valid_for_product',static fn($valid,$product) => self::is_product($product->get_id())?false:$valid,10,2);
        // Block fixed-cart coupons as well: their allocation differs from product coupons.
        add_filter('woocommerce_coupon_is_valid',static function($valid,$coupon) {
            if ($coupon->is_type('fixed_cart') && WC()->cart) { foreach (WC()->cart->get_cart() as $item) { if (isset($item['travisa'])) { throw new Exception('لا يمكن جمع كوبون السلة مع حجز TraVisa.'); } } } return $valid;
        },10,2);
    }
    public static function is_product(int $id): bool { return (bool)get_post_meta($id,'_travisa_carrier',true); }
    private static function product(): int {
        $id = (int)get_option('travisa_product_id',0); $p = $id ? wc_get_product($id) : false;
        if ($p && $p->get_status()==='publish' && self::is_product($id)) { return $id; }
        // Atomic option lock prevents parallel first requests creating duplicate carrier products.
        if (!add_option('travisa_product_lock',time(),'',false)) {
            if ((int)get_option('travisa_product_lock') < time()-60) { delete_option('travisa_product_lock'); }
            throw new RuntimeException('جارٍ تجهيز الخدمة. أعد المحاولة بعد لحظات.');
        }
        try {
            $p = new WC_Product_Simple(); $p->set_name('حجز خدمات TraVisa'); $p->set_status('publish'); $p->set_catalog_visibility('hidden'); $p->set_virtual(true); $p->set_regular_price('0'); $p->set_tax_status('taxable'); $p->update_meta_data('_travisa_carrier',1); $id = $p->save();
            if (!$id) { throw new RuntimeException('تعذر إنشاء منتج الخدمة.'); }
            update_option('travisa_product_id',$id,false); return $id;
        } finally { delete_option('travisa_product_lock'); }
    }
    public static function add(array $request,array $quote) {
        $bookings = 0;
        foreach (WC()->cart->get_cart() as $item) { if (isset($item['travisa'])) { $bookings++; } }
        if ($bookings >= 20) { throw new RuntimeException('الحد الأقصى 20 حجز TraVisa في السلة. أكمل الطلب أو احذف حجزًا للمتابعة.'); }
        $product = self::product(); self::$adding = true;
        try { return WC()->cart->add_to_cart($product,1,0,[],['travisa'=>['request'=>$request,'quote'=>$quote],'travisa_unique'=>wp_generate_uuid4()]); }
        finally { self::$adding = false; }
    }
    public static function validate_add($passed,$product_id,$quantity,$variation_id=0,$variations=[],$data=[]) {
        if (self::is_product((int)$product_id) && (!self::$adding || (float)$quantity !== 1.0)) { wc_add_notice('أضف الحجز من نموذج TraVisa بعد حساب السعر.','error'); return false; } return $passed;
    }
    public static function prices($cart): void {
        foreach ($cart->get_cart() as $key=>$item) {
            if (!isset($item['travisa'])) { continue; }
            try {
                $quote = TraVisa_Frontend::calculate($item['travisa']['request']);
                if (!hash_equals($quote['signature'],(string)($item['travisa']['quote']['signature'] ?? ''))) { throw new RuntimeException('تغيرت أسعار أو قواعد حجز TraVisa. احذف الحجز وأعد إضافته للموافقة على السعر الحالي.'); }
                $item['data']->set_price(number_format($quote['total']/100,2,'.',''));
                unset($cart->cart_contents[$key]['travisa_error']);
            } catch (Throwable $e) {
                $cart->cart_contents[$key]['travisa_error'] = $e->getMessage();
                // Retain the last quoted amount for display only; validation blocks checkout.
                $item['data']->set_price(number_format(($item['travisa']['quote']['total'] ?? 0)/100,2,'.',''));
            }
        }
    }
    public static function validate_cart(): void {
        if (!WC()->cart) { return; }
        foreach (WC()->cart->get_cart() as $item) {
            if (self::is_product((int)$item['product_id']) && !isset($item['travisa'])) { wc_add_notice('حجز غير صالح؛ احذفه وأضفه من نموذج TraVisa.','error'); continue; }
            if (!isset($item['travisa'])) { continue; }
            try {
                if ((float)$item['quantity'] !== 1.0) { throw new RuntimeException('كمية الحجز يجب أن تكون 1؛ عدد المسافرين داخل الحجز.'); }
                $fresh = TraVisa_Frontend::calculate($item['travisa']['request']);
                if (!hash_equals($fresh['signature'],(string)($item['travisa']['quote']['signature'] ?? ''))) { throw new RuntimeException('تغير حجز TraVisa؛ احذفه وأعد إضافته لمراجعة السعر الجديد.'); }
            } catch (Throwable $e) { wc_add_notice($e->getMessage(),'error'); }
        }
    }
    public static function details(array $data,array $item): array {
        if (!isset($item['travisa'])) { return $data; }
        foreach ($item['travisa']['quote']['lines'] as $line) { $data[] = ['key'=>trim($line['country'] . ' ' . $line['name']),'value'=>esc_html($line['category'] . ' × ' . $line['quantity'] . ' — موعد ' . ($line['appointment']==='vip'?'VIP':'عادي'))]; }
        $data[] = ['key'=>'إصدار التسعير','value'=>(string)$item['travisa']['quote']['version']]; return $data;
    }
    public static function order($item,$key,$values,$order): void {
        if (!isset($values['travisa'])) {
            if (self::is_product((int)($values['product_id']??0))) { throw new Exception('بيانات حجز TraVisa مفقودة.'); }
            return;
        }
        $q = TraVisa_Frontend::calculate($values['travisa']['request']);
        if (!hash_equals($q['signature'],$values['travisa']['quote']['signature'])) { throw new Exception('تغير السعر قبل حفظ الطلب؛ أعد إضافة الحجز.'); }
        if ((float)$values['quantity'] !== 1.0) { throw new Exception('كمية حجز غير صالحة.'); }
        $item->add_meta_data('_travisa_snapshot',wp_json_encode($values['travisa']),true);
        $item->add_meta_data('إصدار الأسعار',$q['version'],true);
        foreach ($q['lines'] as $i=>$line) {
            $item->add_meta_data('الخدمة ' . ($i+1),trim($line['country'] . ' / ' . $line['category'] . ' / ' . $line['name']) . ' × ' . $line['quantity'] . ' | موعد ' . ($line['appointment']==='vip'?'VIP':'عادي'),true);
        }
        $item->add_meta_data('خصم TraVisa',number_format($q['discount']/100,2) . ' ' . $q['currency'],true);
        $item->add_meta_data('رسوم معلوماتية غير مشمولة',number_format($q['information']/100,2) . ' ' . $q['currency'],true);
    }
}
