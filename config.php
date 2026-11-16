<?php
declare(strict_types=1);

// Локальные переопределения (не коммитить!).
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

function cfg(string $name, string $default = ''): string
{
    if (defined($name)) {
        return (string) constant($name);
    }
    $env = getenv($name);
    return ($env === false || $env === '') ? $default : $env;
}

function connect(bool $useDb = true): PDO
{
    static $pool = [];
    $key = $useDb ? 'db' : 'server';
    if (!isset($pool[$key])) {
        $dsn = 'mysql:host=' . cfg('DB_HOST', '127.0.0.1')
             . ';port=' . cfg('DB_PORT', '3306')
             . ';charset=' . cfg('DB_CHARSET', 'utf8mb4');
        if ($useDb) {
            $dsn .= ';dbname=' . cfg('DB_NAME', 'kamikaze_calc');
        }
        $pool[$key] = new PDO($dsn, cfg('DB_USER', 'root'), cfg('DB_PASS'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pool[$key];
}

function admin_credentials(): array
{
    return [
        'user' => cfg('ADMIN_USER', 'admin'),
        'pass' => cfg('ADMIN_PASS', ''),
        'hash' => cfg('ADMIN_PASS_HASH', ''),
    ];
}

function settings_all(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    try {
        $rows = connect()->query('SELECT `key`, `value`, `type` FROM settings')->fetchAll();
    } catch (Throwable $e) {
        error_log('[config] ' . $e->getMessage());
        return $cache;
    }
    foreach ($rows as $row) {
        $key = (string)$row['key'];
        $raw = (string)$row['value'];
        switch ((string)$row['type']) {
            case 'int':
                $cache[$key] = (int)$raw;
                break;
            case 'bool':
                $cache[$key] = in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
                break;
            default:
                $cache[$key] = $raw;
        }
    }
    return $cache;
}

function setting(string $key, string $default = ''): string
{
    $all = settings_all();
    return array_key_exists($key, $all) ? (string)$all[$key] : $default;
}

function setting_int(string $key, int $default, int $min = 0, int $max = 255): int
{
    $all = settings_all();
    $value = array_key_exists($key, $all) ? (int)$all[$key] : $default;
    return max($min, min($max, $value));
}

function enum_all(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    try {
        $rows = connect()->query('
            SELECT group_code, code, name, scope
            FROM enum_labels
            ORDER BY group_code, sort_order, code
        ')->fetchAll();
    } catch (Throwable $e) {
        error_log('[config] ' . $e->getMessage());
        return $cache;
    }
    foreach ($rows as $row) {
        $cache[(string)$row['group_code']][(string)$row['code']] = [
            'name'  => (string)$row['name'],
            'scope' => array_values(array_filter(array_map('trim', explode(',', (string)$row['scope'])))),
        ];
    }
    return $cache;
}

function enum_rows(string $group, string $scope = ''): array
{
    $out = [];
    foreach (enum_all()[$group] ?? [] as $code => $row) {
        if ($scope !== '' && !in_array($scope, $row['scope'], true)) {
            continue;
        }
        $out[$code] = $row['name'];
    }
    return $out;
}

function enum_codes(string $group, string $scope = ''): array
{
    return array_keys(enum_rows($group, $scope));
}

function enum_label(string $group, string $code, string $fallback = ''): string
{
    return enum_rows($group)[$code] ?? ($fallback !== '' ? $fallback : $code);
}

function enum_payload(): array
{
    $out = [];
    foreach (enum_all() as $group => $rows) {
        foreach ($rows as $code => $row) {
            $out[$group][] = ['code' => $code, 'name' => $row['name'], 'scope' => $row['scope']];
        }
    }
    return $out;
}

function enum_scopes(): array
{
    return ['item', 'card', 'set', 'character'];
}

function grade_all(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    try {
        $rows = connect()->query('
            SELECT code, name, sort_order, max_upgrade_level
            FROM grades
            ORDER BY sort_order, code
        ')->fetchAll();
    } catch (Throwable $e) {
        error_log('[config] ' . $e->getMessage());
        return $cache;
    }
    foreach ($rows as $row) {
        $code = strtoupper((string)$row['code']);
        $cache[$code] = [
            'name' => (string)$row['name'],
            'sort_order' => (int)$row['sort_order'],
            'max_upgrade_level' => $row['max_upgrade_level'] === null ? null : (int)$row['max_upgrade_level'],
        ];
    }
    return $cache;
}

function grade_rows(): array
{
    $out = [];
    foreach (grade_all() as $code => $row) {
        $out[$code] = $row['name'];
    }
    return $out;
}

function grade_codes(): array
{
    return array_keys(grade_all());
}

function grade_label(string $code): string
{
    $code = strtoupper(trim($code));
    return grade_all()[$code]['name'] ?? $code;
}

function base_stat_labels(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    try {
        $rows = connect()->query('
            SELECT base_field, name_ru
            FROM stats
            WHERE is_base = 1 AND base_field IS NOT NULL
        ')->fetchAll();
    } catch (Throwable $e) {
        error_log('[config] ' . $e->getMessage());
        return $cache;
    }
    foreach ($rows as $row) {
        $cache[(string)$row['base_field']] = (string)$row['name_ru'];
    }
    return $cache;
}

function base_stat_label(string $field, string $default = ''): string
{
    return base_stat_labels()[$field] ?? $default;
}

function slot_all(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    try {
        $rows = connect()->query('
            SELECT slot_number, name, column_no, pair_group, sort_order
            FROM slots
            ORDER BY sort_order, slot_number
        ')->fetchAll();
    } catch (Throwable $e) {
        error_log('[config] ' . $e->getMessage());
        return $cache;
    }
    foreach ($rows as $row) {
        $cache[(int)$row['slot_number']] = [
            'slot_number' => (int)$row['slot_number'],
            'name'        => (string)$row['name'],
            'column_no'   => (int)$row['column_no'],
            'pair_group'  => $row['pair_group'] === null ? null : (int)$row['pair_group'],
            'sort_order'  => (int)$row['sort_order'],
        ];
    }
    return $cache;
}

function slot_numbers(): array
{
    return array_keys(slot_all());
}

function slot_count(): int
{
    $n = count(slot_all());
    // Таблица ещё не создана — не ограничиваем.
    return $n > 0 ? $n : 255;
}

function slot_label(int $number): string
{
    return slot_all()[$number]['name'] ?? ('Ячейка ' . $number);
}

function slot_pair_groups(): array
{
    $groups = [];
    foreach (slot_all() as $slot) {
        if ($slot['pair_group'] === null) {
            continue;
        }
        $groups[$slot['pair_group']][] = $slot['slot_number'];
    }
    return array_values(array_filter($groups, static fn($list) => count($list) > 1));
}

function slot_payload(): array
{
    $columns = [];
    try {
        $columns = connect()->query('
            SELECT column_no, name, align, sort_order
            FROM slot_columns
            ORDER BY sort_order, column_no
        ')->fetchAll();
    } catch (Throwable $e) {
        error_log('[config] ' . $e->getMessage());
        $columns = [];
    }
    return [
        'columns' => $columns,
        'slots'   => array_values(slot_all()),
    ];
}

/* ---------- Файловый кэш JSON-ответов API ---------- */

function api_cache_dir(): string
{
    return __DIR__ . '/cache/api';
}

function api_cache_get(string $key): ?string
{
    $file = api_cache_dir() . '/' . $key . '.json';
    if (!is_file($file) || time() - (int)filemtime($file) > 300) {
        return null;
    }
    $json = @file_get_contents($file);
    return ($json === false || $json === '') ? null : $json;
}

function api_cache_set(string $key, string $json): void
{
    $dir = api_cache_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return;
    }
    $file = $dir . '/' . $key . '.json';
    $tmp = $file . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
        @rename($tmp, $file);
    }
}

function api_cache_clear(): void
{
    foreach (glob(api_cache_dir() . '/*.json') ?: [] as $file) {
        @unlink($file);
    }
}