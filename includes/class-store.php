<?php
defined('ABSPATH') || exit;
class TraVisa_Store {
    public static function table(string $name): string { global $wpdb; return $wpdb->prefix . 'travisa_' . $name; }
    public static function install(bool $network_wide = false): void {
        global $wpdb;
        if (is_multisite() && $network_wide) { wp_die('فعّل TraVisa لكل موقع بشكل منفصل.'); }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php'; $collate = $wpdb->get_charset_collate();
        dbDelta('CREATE TABLE ' . self::table('versions') . " (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            actor_id bigint unsigned NOT NULL,
            actor_name varchar(250) NOT NULL,
            operation varchar(30) NOT NULL,
            source_name varchar(250) NOT NULL,
            source_hash varchar(64) NOT NULL,
            restored_from bigint unsigned NOT NULL DEFAULT 0,
            summary longtext NOT NULL,
            PRIMARY KEY  (id)
        ) ENGINE=InnoDB $collate;");
        dbDelta('CREATE TABLE ' . self::table('prices') . " (
            version_id bigint unsigned NOT NULL,
            record_key varchar(64) NOT NULL,
            payload longtext NOT NULL,
            PRIMARY KEY  (version_id,record_key)
        ) ENGINE=InnoDB $collate;");
        dbDelta('CREATE TABLE ' . self::table('state') . " (
            id tinyint unsigned NOT NULL,
            version_id bigint unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id)
        ) ENGINE=InnoDB $collate;");
        dbDelta('CREATE TABLE ' . self::table('display') . " (
            scope_key varchar(64) NOT NULL,
            scope_name varchar(500) NOT NULL,
            enabled tinyint NOT NULL DEFAULT 1,
            visible tinyint NOT NULL DEFAULT 1,
            PRIMARY KEY  (scope_key)
        ) ENGINE=InnoDB $collate;");
        $wpdb->query('INSERT IGNORE INTO ' . self::table('state') . ' (id,version_id) VALUES (1,0)');
        add_option('travisa_rules', ['discount_min_travelers'=>1,'home_extra_once'=>0,'vip_additive'=>0], '', false);
        update_option('travisa_db_version', TRAVISA_VERSION, false);
    }
    public static function version(): int { global $wpdb; return (int)$wpdb->get_var('SELECT version_id FROM ' . self::table('state') . ' WHERE id=1'); }
    public static function rows(?int $version = null): array {
        global $wpdb; $version = $version ?? self::version();
        $raw = $wpdb->get_col($wpdb->prepare('SELECT payload FROM ' . self::table('prices') . ' WHERE version_id=%d ORDER BY record_key', $version));
        $rows = []; foreach ($raw as $json) { $r = json_decode($json, true); if (is_array($r)) { $rows[$r['id']] = $r; } } return $rows;
    }
    public static function settings(): array {
        global $wpdb; $out = [];
        foreach ($wpdb->get_results('SELECT * FROM ' . self::table('display'), ARRAY_A) as $r) { $out[$r['scope_name']] = ['enabled'=>(bool)$r['enabled'],'visible'=>(bool)$r['visible']]; } return $out;
    }
    public static function rules(): array { return array_merge(['discount_min_travelers'=>1,'home_extra_once'=>0,'vip_additive'=>0], (array)get_option('travisa_rules', [])); }
    public static function save_display(array $scopes): void {
        global $wpdb;
        foreach ($scopes as $scope => $flags) {
            $ok = $wpdb->replace(self::table('display'), ['scope_key'=>hash('sha256',$scope),'scope_name'=>$scope,'enabled'=>empty($flags['enabled'])?0:1,'visible'=>empty($flags['visible'])?0:1], ['%s','%s','%d','%d']);
            if ($ok === false) { throw new RuntimeException('تعذر حفظ إعدادات العرض.'); }
        }
    }
    public static function versions(): array { global $wpdb; return $wpdb->get_results('SELECT * FROM ' . self::table('versions') . ' ORDER BY id DESC LIMIT 100', ARRAY_A); }
    public static function has_version(int $id): bool { global $wpdb; return (bool)$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table('versions') . ' WHERE id=%d', $id)); }
    public static function commit(array $rows, int $base, string $name, string $hash, int $restore = 0): int {
        global $wpdb;
        $errors = TraVisa_Domain::validate($rows); if ($errors) { throw new RuntimeException(implode(' / ', array_slice($errors, 0, 5))); }
        foreach ($rows as $r) { if (($r['id'] ?? '') !== TraVisa_Domain::key($r)) { throw new RuntimeException('هوية سجل غير صالحة.'); } }
        if ($wpdb->query('START TRANSACTION') === false) { throw new RuntimeException('تعذر بدء معاملة قاعدة البيانات.'); }
        try {
            $current = (int)$wpdb->get_var('SELECT version_id FROM ' . self::table('state') . ' WHERE id=1 FOR UPDATE');
            if ($base !== $current) { throw new RuntimeException('تغيرت الأسعار منذ المعاينة. أعد المعاينة والتحقق أولًا.'); }
            $summary = TraVisa_Domain::diff(array_values(self::rows($current)), array_values($rows)); $user = wp_get_current_user();
            $ok = $wpdb->insert(self::table('versions'), ['created_at'=>current_time('mysql', true),'actor_id'=>get_current_user_id(),'actor_name'=>$user->user_login,'operation'=>$restore?'restore':'import','source_name'=>sanitize_text_field($name),'source_hash'=>$hash,'restored_from'=>$restore,'summary'=>wp_json_encode($summary)]);
            if (!$ok) { throw new RuntimeException('تعذر حفظ سجل الإصدار.'); }
            $version = (int)$wpdb->insert_id;
            foreach ($rows as $r) {
                if (!$wpdb->insert(self::table('prices'), ['version_id'=>$version,'record_key'=>$r['id'],'payload'=>wp_json_encode($r)], ['%d','%s','%s'])) { throw new RuntimeException('تعذر حفظ أسعار الإصدار.'); }
            }
            if ($wpdb->update(self::table('state'), ['version_id'=>$version], ['id'=>1], ['%d'], ['%d']) === false) { throw new RuntimeException('تعذر تفعيل الإصدار.'); }
            if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('تعذر اعتماد المعاملة.'); }
            do_action('travisa_prices_committed', $rows, $version);
            return $version;
        } catch (Throwable $e) { $wpdb->query('ROLLBACK'); throw $e; }
    }
}
