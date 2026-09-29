<?php
class TraVisa_Xlsx {
    private static function xml(string $text): SimpleXMLElement {
        if (str_contains($text, "\0") || preg_match('/<!DOCTYPE|<!ENTITY/i', $text)) { throw new RuntimeException('تعريفات XML الخارجية أو ترميز XML غير المدعوم غير مسموحة.'); }
        $prior = libxml_use_internal_errors(true);
        try {
            // Streaming preflight bounds the XML tree before SimpleXML materializes it.
            $reader = new XMLReader(); $nodes = 0;
            if (!$reader->XML($text, null, LIBXML_NONET)) { throw new RuntimeException('ملف XML غير صالح.'); }
            try {
                while ($reader->read()) {
                    if (++$nodes > 350000 || $reader->depth > 40 || $reader->attributeCount > 64 || strlen($reader->value) > 8192) { throw new RuntimeException('بنية XML تتجاوز حدود المعالجة الآمنة.'); }
                    if (in_array($reader->nodeType, [XMLReader::DOC_TYPE, XMLReader::ENTITY, XMLReader::ENTITY_REF], true)) { throw new RuntimeException('كيانات XML غير مسموحة.'); }
                }
                if (libxml_get_errors()) { throw new RuntimeException('ملف XML غير صالح داخل المصنف.'); }
            } finally { $reader->close(); }
            $x = simplexml_load_string($text, 'SimpleXMLElement', LIBXML_NONET);
            if ($x === false) { throw new RuntimeException('ملف XML غير صالح داخل المصنف.'); }
            return $x;
        }
        finally { libxml_clear_errors(); libxml_use_internal_errors($prior); }
    }
    public static function read(string $path): array {
        if (!class_exists('ZipArchive') || !class_exists('XMLReader') || !function_exists('simplexml_load_string')) { throw new RuntimeException('يلزم تفعيل إضافات PHP: zip وsimplexml وxmlreader.'); }
        if (filesize($path) > 5 * 1024 * 1024) { throw new RuntimeException('الحد الأقصى لحجم الملف 5 MB.'); }
        $z = new ZipArchive();
        if ($z->open($path) !== true) { throw new RuntimeException('ليس ملف XLSX صالحًا.'); }
        try {
            $bytes = 0; $entries = [];
            if ($z->numFiles > 3000) { throw new RuntimeException('المصنف يتجاوز حدود الأمان.'); }
            for ($i = 0; $i < $z->numFiles; $i++) {
                $s = $z->statIndex($i); $bytes += $s['size'];
                if (isset($entries[$s['name']])) { throw new RuntimeException('عنصر مكرر داخل أرشيف Excel.'); }
                $entries[$s['name']] = true;
                if ($bytes > 30 * 1024 * 1024 || $s['size'] > 10 * 1024 * 1024 || str_contains(strtolower($s['name']), 'vbaproject')) { throw new RuntimeException('مصنف كبير جدًا أو يحتوي وحدات ماكرو.'); }
            }
            $get = static function ($p) use ($z) { $v = $z->getFromName($p); if ($v === false) { throw new RuntimeException('عنصر مفقود داخل XLSX: ' . $p); } return self::xml($v); };
            $strings = []; $raw = $z->getFromName('xl/sharedStrings.xml');
            if ($raw !== false) { foreach (self::xml($raw)->xpath('//*[local-name()="si"]') as $si) { if (count($strings)>=50000) { throw new RuntimeException('عدد النصوص المشتركة كبير جدًا.'); } $text = ''; foreach ($si->xpath('.//*[local-name()="t"]') as $t) { $text .= (string)$t; } if (strlen($text)>8192) { throw new RuntimeException('نص خلية طويل جدًا.'); } $strings[] = $text; } }
            $rels = [];
            foreach ($get('xl/_rels/workbook.xml.rels')->children() as $rel) {
                if ((string)$rel['TargetMode'] === 'External') { continue; }
                $target = (string)$rel['Target'];
                if (str_contains($target, '..') || str_contains($target, '\\')) { throw new RuntimeException('مسار ورقة غير صالح.'); }
                if (isset($rels[(string)$rel['Id']])) { throw new RuntimeException('علاقة ورقة مكررة.'); }
                $rels[(string)$rel['Id']] = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
            }
            $sheets = []; $targets = []; $cell_count = 0;
            $sheet_list = $get('xl/workbook.xml')->xpath('//*[local-name()="sheet"]');
            if (count($sheet_list)>10) { throw new RuntimeException('الحد الأقصى 10 أوراق في المصنف.'); }
            foreach ($sheet_list as $sheet) {
                $rid = (string)$sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                if (!isset($rels[$rid])) { throw new RuntimeException('علاقة ورقة مفقودة.'); }
                if (isset($targets[$rels[$rid]]) || isset($sheets[(string)$sheet['name']])) { throw new RuntimeException('لا يمكن تكرار اسم ورقة أو مصدرها داخل المصنف.'); }
                $targets[$rels[$rid]] = true;
                $rows = [];
                foreach ($get($rels[$rid])->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
                    $n = (int)$row['r']; if ($n < 1 || $n > 10000 || isset($rows[$n])) { throw new RuntimeException('رقم صف مكرر أو خارج المجال 1–10000.'); }
                    $rows[$n] = [];
                    foreach ($row->xpath('./*[local-name()="c"]') as $cell) {
                        if (++$cell_count > 90000) { throw new RuntimeException('الحد الأقصى 90000 خلية في المصنف.'); }
                        $ref = (string)$cell['r']; preg_match('/^([A-Z]{1,3})([1-9][0-9]{0,4})$/', $ref, $match);
                        if (!$match || (int)$match[2] !== $n) { throw new RuntimeException('عنوان خلية غير صالح أو لا يطابق صفها.'); }
                        $col_number = 0; foreach (str_split($match[1]) as $letter) { $col_number = $col_number*26+ord($letter)-64; }
                        if ($col_number>64 || isset($rows[$n][$match[1]])) { throw new RuntimeException('عمود يتجاوز 64 أو خلية مكررة.'); }
                        $vs = $cell->xpath('./*[local-name()="v"]'); $value = isset($vs[0]) ? (string)$vs[0] : null;
                        $type = (string)$cell['t']; $f = $cell->xpath('./*[local-name()="f"]');
                        if ($type === 's') { if ($value===null || !ctype_digit($value) || !array_key_exists((int)$value,$strings)) { throw new RuntimeException('مرجع نص خلية غير صالح.'); } $value = $strings[(int)$value]; }
                        elseif ($type === 'inlineStr') { $value = ''; foreach ($cell->xpath('.//*[local-name()="t"]') as $t) { $value .= (string)$t; } }
                        elseif ($value !== null && $type !== 'e' && is_numeric($value)) { $value = (float)$value; }
                        if (is_string($value) && strlen($value)>8192) { throw new RuntimeException('نص خلية طويل جدًا.'); }
                        $rows[$n][$match[1]] = ['value' => $value, 'formula' => isset($f[0]) ? (string)$f[0] : null, 'error' => $type === 'e'];
                    }
                }
                $sheets[(string)$sheet['name']] = $rows;
            }
            return $sheets;
        } finally { $z->close(); }
    }
    public static function parse(string $path): array {
        $sheets = self::read($path); $rows = []; $errors = [];
        $v = static fn($r, $col) => $r[$col]['value'] ?? null;
        $make = static function ($cells, $map, $meta, $discountCol, $source) use (&$errors, $v) {
            $r = $meta + ['source' => $source];
            foreach (TraVisa_Domain::MONEY as $f) {
                try { $r[$f] = isset($map[$f]) ? TraVisa_Domain::minor($v($cells, $map[$f])) : null; }
                catch (Throwable $e) { $errors[] = $source . ' / ' . ($map[$f] ?? $f) . ': ' . $e->getMessage(); $r[$f] = null; }
            }
            $discount = $v($cells, $discountCol);
            if ($discount === null || $discount === '' || trim((string)$discount) === 'لا ينطبق') { $r['discount_bp'] = 0; }
            elseif (!is_numeric($discount) || (float)$discount < 0 || (float)$discount > 1) { $errors[] = $source . ': نسبة الخصم يجب أن تكون نسبة Excel بين 0 و1.'; $r['discount_bp'] = 0; }
            else { $r['discount_bp'] = (int)round((float)$discount * 10000); }
            foreach ($cells as $col => $cell) {
                if ($cell['error'] || ($cell['formula'] !== null && $cell['value'] === null)) { $errors[] = $source . ' / ' . $col . ': خطأ في صيغة Excel أو نتيجة غير محفوظة؛ أعد الحساب والحفظ في Excel.'; }
            }
            $r['id'] = TraVisa_Domain::key($r); return $r;
        };
        if (isset($sheets['TraVisa'])) {
            $header = $sheets['TraVisa'][1] ?? []; $cols = [];
            foreach ($header as $col => $cell) { $label = trim((string)$cell['value']); if ($label === '') { continue; } if (isset($cols[$label])) { $errors[] = 'عنوان عمود مكرر: ' . $label; } $cols[$label] = $col; }
            $required = array_merge(['kind', 'name', 'country', 'category', 'tier', 'discount'], TraVisa_Domain::MONEY);
            foreach ($required as $field) { if (!isset($cols[$field])) { $errors[] = 'عمود مطلوب: ' . $field; } }
            if ($errors) { return ['rows' => [], 'errors' => $errors]; }
            foreach ($sheets['TraVisa'] as $n => $cells) {
                if ($n === 1 || !array_filter(array_column($cells, 'value'), static fn($x) => $x !== null && $x !== '')) { continue; }
                $meta = []; foreach (['kind', 'name', 'country', 'category', 'tier'] as $f) { $meta[$f] = trim((string)$v($cells, $cols[$f])); }
                $rows[] = $make($cells, $cols, $meta, $cols['discount'], 'TraVisa!' . $n);
            }
        } else {
            if (!isset($sheets['ورقة4'], $sheets['ورقة2'])) { return ['rows' => [], 'errors' => ['يلزم ورقة2 وورقة4 من الملف الأصلي، أو ورقة TraVisa بالقالب المرفق.']]; }
            $map = array_combine(TraVisa_Domain::MONEY, ['F','G','H','I','J','K','L','M','N','O']);
            foreach ($sheets['ورقة4'] as $n => $cells) {
                $name = trim((string)$v($cells, 'C')); if ($n < 3 || $name === '') { continue; }
                $r = $make($cells, $map, ['kind' => 'visa', 'name' => str_replace('YOVISA', 'TraVisa', $name), 'country' => trim((string)$v($cells, 'D')), 'category' => trim((string)$v($cells, 'E')), 'tier' => TraVisa_Domain::tier($name)], 'Q', 'ورقة4!' . $n);
                $rows[] = $r;
                if ($v($cells, 'P') === null) { $errors[] = 'ورقة4!P' . $n . ': الإجمالي مفقود.'; }
                $eligible = ($r['service'] ?? 0)+($r['file'] ?? 0)+($r['print'] ?? 0)+($r['extra'] ?? 0);
                $expected_discount = round($eligible * $r['discount_bp'] / 10000);
                if (is_numeric($v($cells,'R')) && abs((float)$v($cells,'R')*100-$expected_discount)>1) { $errors[] = 'ورقة4!R' . $n . ': قيمة الخصم لا تطابق مكونات الخدمة ونسبته.'; }
                if (is_numeric($v($cells,'P')) && is_numeric($v($cells,'S')) && abs(((float)$v($cells,'P')-(float)($v($cells,'R')??0)-(float)$v($cells,'S'))*100)>1) { $errors[] = 'ورقة4!S' . $n . ': صافي الإجمالي لا يطابق الإجمالي ناقص الخصم.'; }
                if ($v($cells,'P') !== null && (!is_numeric($v($cells,'P')) || (float)$v($cells,'P')<0)) { $errors[] = 'ورقة4!P' . $n . ': الإجمالي غير صالح.'; }
                if (is_numeric($v($cells, 'S')) && (float)$v($cells, 'S') < 0) { $errors[] = 'ورقة4!S' . $n . ': إجمالي بعد الخصم سالب.'; }
                $normal = $r['appointment_normal']; $vip = $r['appointment_vip'];
                if (is_numeric($v($cells,'P'))) {
                    $base = $eligible + ($r['insurance'] ?? 0); $totals = [];
                    if ($normal !== null) { $totals[] = $base + $normal; }
                    if ($vip !== null) { $totals[] = $base + $vip; }
                    if ($totals && min(array_map(static fn($t)=>abs($t-(float)$v($cells,'P')*100),$totals))>1) { $errors[] = 'ورقة4!P' . $n . ': الإجمالي لا يطابق مكونات الخدمة والتأمين وأحد نوعي الموعد.'; }
                }
                if ($normal !== null && $vip !== null && $normal > 0 && $vip > 0 && is_numeric($v($cells, 'P'))) {
                    $both = array_sum(array_map(static fn($f) => $r[$f] ?? 0, ['service','file','print','extra','insurance','appointment_normal','appointment_vip']));
                    if (abs((float)$v($cells, 'P') * 100 - $both) < 1) { $errors[] = 'ورقة4!P' . $n . ': الإجمالي يجمع الموعد العادي وVIP؛ صحّح الإجمالي لنوع الموعد المقصود أو استخدم قالب TraVisa الذي يفصل المكونات.'; }
                }
            }
            $map = ['service'=>'D','file'=>'E','print'=>'F','extra'=>'G','insurance'=>'H','appointment_normal'=>'I','appointment_vip'=>'J'];
            foreach ($sheets['ورقة2'] as $n => $cells) {
                $name = trim((string)$v($cells, 'B')); if ($n < 3 || $name === '') { continue; }
                // These four bundles belong to the country catalog, not the standalone catalog.
                if (str_contains($name, 'تجهيز ملف التأشيرة') || str_contains($name, 'الزيارة المنزلية')) { continue; }
                $r = $make($cells, $map, ['kind'=>'standalone','name'=>str_replace('YOVISA','TraVisa',$name),'country'=>'','category'=>'','tier'=>TraVisa_Domain::tier($name)], 'L', 'ورقة2!' . $n); $rows[] = $r;
                $base = ($r['service']??0)+($r['file']??0)+($r['print']??0)+($r['extra']??0);
                $total = $base+($r['insurance']??0)+($r['appointment_normal']??0)+($r['appointment_vip']??0);
                if (is_numeric($v($cells,'K')) && abs((float)$v($cells,'K')*100-$total)>1) { $errors[] = 'ورقة2!K' . $n . ': الإجمالي لا يطابق المكونات.'; }
                if (is_numeric($v($cells,'M')) && abs((float)$v($cells,'M')*100-round($base*$r['discount_bp']/10000))>1) { $errors[] = 'ورقة2!M' . $n . ': الخصم لا يطابق المكونات.'; }
                if (is_numeric($v($cells, 'N')) && (float)$v($cells, 'N') < 0) { $errors[] = 'ورقة2!N' . $n . ': إجمالي سالب.'; }
            }
        }
        $errors = array_merge($errors, TraVisa_Domain::validate($rows));
        return ['rows' => $rows, 'errors' => array_values(array_unique($errors)), 'sheets' => array_keys($sheets)];
    }
}
