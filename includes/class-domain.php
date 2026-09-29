<?php
/** Pure pricing and validation: no WordPress dependency. Amounts use integer minor units. */
class TraVisa_Domain {
    public const TIERS = ['normal' => 'عادي', 'vip' => 'VIP', 'vip_pro' => 'VIP PRO', 'home' => 'زيارة منزلية'];
    public const MONEY = ['service', 'file', 'print', 'extra', 'insurance', 'appointment_normal', 'appointment_vip', 'visa_normal', 'visa_vip', 'shipping'];
    public static function key(array $r): string {
        return hash('sha256', json_encode([$r['kind'], trim($r['country']), trim($r['category']), $r['tier'], trim($r['name'])], JSON_UNESCAPED_UNICODE));
    }
    public static function minor($value): ?int {
        if ($value === null || $value === '') { return null; }
        if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0 || (float)$value > 10000000) { throw new InvalidArgumentException('قيمة مالية غير صالحة'); }
        if (abs((float)$value * 100 - round((float)$value * 100)) > 0.00001) { throw new InvalidArgumentException('السعر يجب أن يكون بمنزلتين عشريتين كحد أقصى'); }
        return (int)round((float)$value * 100);
    }
    public static function tier(string $name): string {
        if (str_contains($name, 'المنزلية')) { return 'home'; }
        if (str_contains($name, 'VIP PRO')) { return 'vip_pro'; }
        if (str_contains($name, 'VIP')) { return 'vip'; }
        return 'normal';
    }
    public static function validate(array $rows): array {
        $errors = []; $seen = []; $combinations = [];
        if (!$rows) { $errors[] = 'لا توجد سجلات تسعير قابلة للاستيراد.'; }
        if (count($rows) > 5000) { $errors[] = 'الحد الأقصى 5000 سجل.'; }
        foreach ($rows as $i => $r) {
            $at = ($r['source'] ?? ('السجل ' . ($i + 1))) . ': ';
            if (!in_array($r['kind'] ?? '', ['visa', 'standalone'], true) || empty($r['name']) || !isset(self::TIERS[$r['tier'] ?? ''])) { $errors[] = $at . 'نوع الخدمة أو اسمها أو مستواها غير صالح.'; continue; }
            if ($r['kind'] === 'visa' && (empty($r['country']) || empty($r['category']))) { $errors[] = $at . 'الدولة والفئة مطلوبتان.'; }
            foreach (['name','country','category'] as $f) { if (strlen($r[$f] ?? '') > 400) { $errors[] = $at . 'النص طويل جدًا: ' . $f; } }
            if ($r['kind'] === 'visa') {
                $combination = json_encode([$r['country'],$r['category'],$r['tier']]);
                if (isset($combinations[$combination])) { $errors[] = $at . 'تكرار الدولة والفئة والمستوى.'; }
                $combinations[$combination] = true;
            }
            foreach (self::MONEY as $field) {
                $v = $r[$field] ?? null;
                if ($v !== null && (!is_int($v) || $v < 0 || $v > 1000000000)) { $errors[] = $at . 'قيمة غير صالحة: ' . $field; }
            }
            if (($r['service'] ?? null) === null) { $errors[] = $at . 'رسوم الخدمة مفقودة؛ الفراغ لا يعني صفرًا.'; }
            if ($r['kind'] === 'visa' && ($r['appointment_normal'] ?? null) === null && ($r['appointment_vip'] ?? null) === null) { $errors[] = $at . 'يلزم سعر موعد واحد على الأقل.'; }
            if ($r['kind'] === 'visa' && $r['tier'] === 'home' && ($r['appointment_vip'] ?? null) === null) { $errors[] = $at . 'رسوم الموعد المنزلي / VIP مفقودة.'; }
            if ($r['kind'] === 'standalone' && str_contains($r['name'],'موعد') && ($r['appointment_' . ($r['tier']==='vip'?'vip':'normal')] ?? null) === null) { $errors[] = $at . 'رسوم الموعد المستقل مفقودة.'; }
            if (!is_int($r['discount_bp'] ?? null) || $r['discount_bp'] < 0 || $r['discount_bp'] > 10000) { $errors[] = $at . 'نسبة الخصم يجب أن تكون بين 0 و100%.'; }
            $key = self::key($r);
            if (isset($seen[$key])) { $errors[] = $at . 'سجل مكرر مع ' . $seen[$key]; }
            $seen[$key] = $r['source'] ?? (string)$i;
        }
        return $errors;
    }
    public static function visible(array $r, array $settings): bool {
        foreach (['row:' . $r['id'], 'country:' . $r['country'], 'tier:' . $r['tier']] as $key) {
            if (isset($settings[$key]) && (!$settings[$key]['enabled'] || !$settings[$key]['visible'])) { return false; }
        }
        return true;
    }
    public static function quote(array $catalog, array $request, array $settings, array $rules): array {
        $items = $request['items'] ?? [];
        if (!is_array($items) || !$items || count($items) > 50) { throw new InvalidArgumentException('اختر خدمة واحدة على الأقل، وبحد أقصى 50 بندًا.'); }
        $count = 0; $seen = []; $selected = []; $countries = []; $tiers = [];
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['id'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D',$item['id'])) { throw new InvalidArgumentException('معرّف الخدمة غير صالح.'); }
            $id = $item['id']; $q = $item['quantity'] ?? null;
            if ((!is_int($q) && !(is_string($q) && ctype_digit($q))) || (int)$q < 1 || (int)$q > 100) { throw new InvalidArgumentException('العدد يجب أن يكون عددًا صحيحًا بين 1 و100.'); }
            if (isset($seen[$id]) || !isset($catalog[$id])) { throw new InvalidArgumentException('خدمة مكررة أو غير موجودة.'); }
            $seen[$id] = true; $r = $catalog[$id];
            if (!self::visible($r, $settings)) { throw new InvalidArgumentException('هذه الخدمة مخفية أو غير مفعّلة.'); }
            if ($r['kind'] === 'visa') { $count += (int)$q; $countries[$r['country']] = true; $tiers[$r['tier']] = true; }
            $selected[] = [$r, (int)$q];
        }
        if ($count > 100 || count($countries) > 1 || count($tiers) > 1) { throw new InvalidArgumentException('اختر دولة ومستوى واحدًا، وبحد أقصى 100 مسافر لكل حجز.'); }
        $appointment = $request['appointment'] ?? 'normal';
        if (!in_array($appointment, ['normal', 'vip'], true)) { throw new InvalidArgumentException('نوع الموعد غير صالح.'); }
        $total = 0; $discount = 0; $info = 0; $lines = [];
        foreach ($selected as [$r, $q]) {
            $base = ($r['service'] ?? 0) + ($r['file'] ?? 0) + ($r['print'] ?? 0);
            $extra = ($r['extra'] ?? 0) * (($r['tier'] === 'home' && !empty($rules['home_extra_once'])) ? 1 : $q);
            $eligible = $base * $q + $extra;
            $insurance = ($r['insurance'] ?? 0) * $q;
            $chosen = $r['tier'] === 'home' ? 'vip' : $appointment;
            $ap = 0;
            if ($r['kind'] === 'visa' || str_contains($r['name'], 'موعد')) {
                if ($r['kind'] === 'standalone') { $chosen = $r['tier'] === 'vip' ? 'vip' : 'normal'; }
                $ap = $r['appointment_' . $chosen] ?? null;
                if ($ap === null) { throw new InvalidArgumentException('رسوم الموعد المختار غير متاحة: ' . $r['name'] . ' / ' . $r['category']); }
                if ($chosen === 'vip' && !empty($rules['vip_additive'])) {
                    if (($r['appointment_normal'] ?? null) === null && $r['tier'] !== 'home') { throw new InvalidArgumentException('رسوم الموعد العادي مفقودة.'); }
                    $ap += $r['appointment_normal'] ?? 0;
                }
            }
            $minimum = max(1, (int)($rules['discount_min_travelers'] ?? 1));
            $off = (($r['kind'] === 'visa' ? $count : $q) >= $minimum) ? (int)round($eligible * $r['discount_bp'] / 10000) : 0;
            $visa = $r['visa_' . $chosen] ?? $r['visa_normal'] ?? null;
            $shipping = $r['shipping'] ?? null;
            $payable = $eligible + $insurance + $ap * $q - $off;
            $information = (($visa ?? 0) + ($shipping ?? 0)) * $q;
            $lines[] = ['id' => $r['id'], 'name' => $r['name'], 'country' => $r['country'], 'category' => $r['category'], 'tier' => $r['tier'], 'quantity' => $q, 'appointment' => $chosen, 'eligible' => $eligible, 'insurance' => $insurance, 'appointment_fee' => $ap * $q, 'discount' => $off, 'payable' => $payable, 'visa_info' => $visa === null ? null : $visa * $q, 'shipping_info' => $shipping === null ? null : $shipping * $q];
            $total += $payable; $discount += $off; $info += $information;
        }
        return ['total' => $total, 'discount' => $discount, 'information' => $info, 'travelers' => $count, 'lines' => $lines];
    }
    public static function diff(array $before, array $after): array {
        $b = []; $a = []; foreach ($before as $r) { $b[$r['id']] = $r; } foreach ($after as $r) { $a[$r['id']] = $r; }
        $out = ['added' => [], 'changed' => [], 'removed' => [], 'unchanged' => 0];
        foreach ($a as $id => $r) {
            $name = trim($r['country'] . ' / ' . $r['category'] . ' / ' . $r['name']);
            if (!isset($b[$id])) { $out['added'][] = $name; continue; }
            $old = $b[$id]; unset($old['source'], $r['source']);
            if ($old != $r) {
                $changes = []; foreach (array_merge(self::MONEY, ['discount_bp']) as $f) { if (($old[$f] ?? null) !== ($r[$f] ?? null)) { $changes[$f] = ['before' => $old[$f] ?? null, 'after' => $r[$f] ?? null]; } }
                $out['changed'][] = ['name' => $name, 'fields' => $changes];
            } else { $out['unchanged']++; }
        }
        foreach ($b as $id => $r) { if (!isset($a[$id])) { $out['removed'][] = $r['country'] . ' / ' . $r['category'] . ' / ' . $r['name']; } }
        $out['new_countries'] = array_values(array_filter(array_diff(array_unique(array_column($after, 'country')), array_unique(array_column($before, 'country'))), static fn($name)=>$name!==''));
        return $out;
    }
}
