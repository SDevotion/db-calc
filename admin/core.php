<?php
declare(strict_types=1);
if (!defined('ADMIN_ENTRY')) { http_response_code(403); exit; }

require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_name('KAMIKAZE_ADMIN');
    session_start();
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Любая запись в админке сбрасывает серверный кэш API.
// Проверка auth на момент shutdown — неаутентифицированный POST кэш не сбрасывает.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    register_shutdown_function(static function (): void {
        if (auth_check()) {
            api_cache_clear();
        }
    });
}

/* ---------- Базовые хелперы ---------- */

function db(): PDO
{
    static $pdo = null;
    return $pdo ??= connect();
}

function render_item_type_conditions(string $mode, array $itemTypes, array $conditionRows): void
{
    ?>
<div class="card-header"><h2>Условия по экипированным предметам</h2></div>
<p class="muted">Например: характеристика активна, если экипировано минимум 2 кинжала.</p>
<div class="grid">
<div class="field"><label for="item_type_condition_mode">Режим условий</label>
<select id="item_type_condition_mode" name="item_type_condition_mode" required>
<option value="all"<?= selected($mode === 'all') ?>>Все условия (AND)</option>
<option value="any"<?= selected($mode === 'any') ?>>Любое условие (OR)</option>
</select></div>
</div>
<?php item_type_condition_block($itemTypes, $conditionRows);
}

function related_counts(PDO $pdo, array $ids, string $keyCol, array $map): array
{
    $out = [];
    if (!$ids) return $out;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    foreach ($map as $key => $sql) {
        $st = $pdo->prepare(str_replace('{ph}', $ph, $sql));
        $st->execute($ids);
        foreach ($st->fetchAll() as $r) {
            $out[(int)$r[$keyCol]][$key] = (int)$r['c'];
        }
    }
    return $out;
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function checked(bool $c): string { return $c ? ' checked' : ''; }
function selected(bool $c): string { return $c ? ' selected' : ''; }

function intOrNull($value): ?int
{
    return ($value === null || $value === '') ? null : (int)$value;
}

function admin_url(string $action, array $params = []): string
{
    $params['action'] = $action;
    return '?' . http_build_query($params);
}

function asset_url(string $file): string
{
    $path = __DIR__ . '/assets/' . $file;
    return 'assets/' . rawurlencode($file) . '?v=' . (is_file($path) ? filemtime($path) : 1);
}

function redirect(string $url): void { header('Location: ' . $url); exit; }

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e($_SESSION['csrf_token']) . '">';
}

function verify_csrf(): void
{
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(400);
        exit('Некорректный токен формы.');
    }
}

function friendly_error(Throwable $ex): string
{
    if ($ex instanceof PDOException) {
        $code = (int)($ex->errorInfo[1] ?? 0);
        $map = [
            1062 => 'Такая запись уже существует.',
            1451 => 'Нельзя удалить запись: на неё ссылаются другие данные.',
            1452 => 'Указана несуществующая связанная запись.',
            1406 => 'Значение слишком длинное для поля базы данных.',
            1264 => 'Значение вне допустимого диапазона.',
            1265 => 'Недопустимое значение поля.',
        ];
        if (isset($map[$code])) return $map[$code];
        error_log('[admin] DB: ' . $ex->getMessage());
        return 'Ошибка базы данных. Подробности в логе сервера.';
    }
    return $ex->getMessage();
}

/* ---------- Авторизация ---------- */

function auth_check(): bool { return !empty($_SESSION['admin_user']); }

function auth_configured(): bool
{
    $c = admin_credentials();
    return $c['hash'] !== '' || $c['pass'] !== '';
}

function auth_attempt(string $user, string $pass): bool
{
    $c = admin_credentials();
    if (!auth_configured()) return false;
    $userOk = hash_equals($c['user'], $user);
    $passOk = $c['hash'] !== ''
        ? password_verify($pass, $c['hash'])
        : hash_equals($c['pass'], $pass);
    return $userOk && $passOk;
}

function require_auth(): void
{
    if (!auth_check()) redirect(admin_url('login'));
}

/* ---------- Справочные опции ---------- */

function db_options(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(int)$row['id']] = (string)$row['name'];
    }
    return $out;
}

function restriction_labels(): array
{
    return enum_rows('restriction');
}

function restriction_options(string $selectedValue = 'allowed'): string
{
    $html = '';
    foreach (restriction_labels() as $value => $label) {
        $html .= '<option value="' . e($value) . '"' . selected($selectedValue === $value) . '>' . e($label) . '</option>';
    }
    return $html;
}

function enum_select(
    string $group,
    string $scope = '',
    string $selectedValue = '',
    bool $withEmpty = false,
    string $emptyLabel = '— не указано —'
): string {
    $html = $withEmpty ? '<option value="">' . e($emptyLabel) . '</option>' : '';
    foreach (enum_rows($group, $scope) as $code => $label) {
        $html .= '<option value="' . e($code) . '"'
            . selected((string)$selectedValue === (string)$code) . '>' . e($label) . '</option>';
    }
    return $html;
}

function stat_options(array $stats, int $selectedId = 0): string
{
    $html = '<option value="0"' . selected($selectedId === 0) . '>— выбрать —</option>';
    foreach ($stats as $s) {
        $html .= '<option value="' . (int)$s['id'] . '"'
            . ' data-flat="' . (int)$s['supports_flat'] . '"'
            . ' data-percent="' . (int)$s['supports_percent'] . '"'
            . selected($selectedId === (int)$s['id']) . '>' . e($s['name_ru']) . '</option>';
    }
    return $html;
}

function feature_options(array $features, ?int $selectedId = null): string
{
    $html = '<option value=""' . selected($selectedId === null) . '>нет</option>';
    foreach ($features as $f) {
        $html .= '<option value="' . (int)$f['id'] . '"' . selected($selectedId === (int)$f['id']) . '>' . e($f['name']) . '</option>';
    }
    return $html;
}

function text_note_options(array $notes, ?int $selectedId = null): string
{
    $html = '<option value=""' . selected($selectedId === null) . '>— нет —</option>';
    foreach ($notes as $n) {
        $html .= '<option value="' . (int)$n['id'] . '"' . selected($selectedId === (int)$n['id']) . '>' . e($n['title']) . '</option>';
    }
    return $html;
}

function state_options(array $states, ?int $selectedId = null, bool $allowEmpty = true): string
{
    $html = $allowEmpty
        ? '<option value=""' . selected($selectedId === null) . '>— без состояния —</option>'
        : '';
    foreach ($states as $s) {
        $id = (int)$s['id'];
        $html .= '<option value="' . $id . '"' . selected($selectedId === $id) . '>'
            . e($s['name']) . ((int)($s['is_default'] ?? 0) === 1 ? ' (по умолчанию)' : '') . '</option>';
    }
    return $html;
}

/* ---------- Граф зависимостей характеристик ---------- */

function stat_dependency_graph(PDO $pdo): array
{
    static $graph = null;
    if ($graph !== null) return $graph;

    $graph = [];
    $rows = $pdo->query("
        SELECT 'item' AS owner_type, id AS owner_id, stat_source_id AS src, stat_id AS dst
        FROM item_bonuses WHERE stat_source_id IS NOT NULL
        UNION ALL
        SELECT 'card', id, stat_source_id, stat_id
        FROM card_bonuses WHERE stat_source_id IS NOT NULL
        UNION ALL
        SELECT 'card', s.card_bonus_id, s.stat_id, b.stat_id
        FROM card_bonus_stat_sources s
        JOIN card_bonuses b ON b.id = s.card_bonus_id
    ")->fetchAll();

    foreach ($rows as $r) {
        $from = (int)$r['src'];
        $to   = (int)$r['dst'];
        if ($from <= 0 || $to <= 0) continue;
        $graph[$from][] = ['to' => $to, 'type' => (string)$r['owner_type'], 'id' => (int)$r['owner_id']];
    }
    return $graph;
}

function stat_dependency_would_create_cycle(
    PDO $pdo,
    int $sourceStatId,
    int $targetStatId,
    ?string $ignoreOwnerType = null,
    ?int $ignoreOwnerId = null
): bool {
    if ($sourceStatId <= 0 || $targetStatId <= 0) return false;
    if ($sourceStatId === $targetStatId) return true;

    $graph = stat_dependency_graph($pdo);
    $stack = [$targetStatId];
    $seen  = [];

    while ($stack) {
        $current = array_pop($stack);
        if ($current === $sourceStatId) return true;
        if (isset($seen[$current])) continue;
        $seen[$current] = true;
        foreach ($graph[$current] ?? [] as $edge) {
            if ($ignoreOwnerType !== null && $ignoreOwnerId !== null
                && $edge['type'] === $ignoreOwnerType && $edge['id'] === $ignoreOwnerId) {
                continue;
            }
            if (!isset($seen[$edge['to']])) $stack[] = $edge['to'];
        }
    }
    return false;
}

/* ---------- Общие хелперы форм и списков ---------- */

function page_number(): int
{
    return max(1, (int)($_GET['page'] ?? 1));
}

function render_pagination(int $total, int $perPage, int $page, string $action, array $params = []): void
{
    $perPage = max(1, $perPage);
    $pages = max(1, (int)ceil($total / $perPage));
    echo '<div class="pager"><span class="pager-info">Всего: <b>' . $total . '</b> · стр. ' . $page . ' из ' . $pages . '</span>';
    if ($pages <= 1) { echo '</div>'; return; }
    $url = static function (int $p) use ($action, $params): string {
        $params['page'] = $p;
        return admin_url($action, $params);
    };
    echo '<span class="pager-links">';
    if ($page > 1) echo '<a class="btn btn-icon" href="' . e($url($page - 1)) . '" aria-label="Предыдущая страница">' . admin_icon('chevron-left', 16) . '</a>';
    $from = max(1, $page - 2);
    $to = min($pages, $page + 2);
    if ($from > 1) echo '<a class="btn btn-page" href="' . e($url(1)) . '">1</a>';
    if ($from > 2) echo '<span class="pager-gap">…</span>';
    for ($p = $from; $p <= $to; $p++) {
        echo '<a class="btn btn-page' . ($p === $page ? ' current' : '') . '" href="' . e($url($p)) . '">' . $p . '</a>';
    }
    if ($to < $pages - 1) echo '<span class="pager-gap">…</span>';
    if ($to < $pages) echo '<a class="btn btn-page" href="' . e($url($pages)) . '">' . $pages . '</a>';
    if ($page < $pages) echo '<a class="btn btn-icon" href="' . e($url($page + 1)) . '" aria-label="Следующая страница">' . admin_icon('chevron-right', 16) . '</a>';
    echo '</span></div>';
}

function is_generated_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (!isset($cache[$key])) {
        $st = $pdo->prepare('SELECT EXTRA FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $st->execute([$table, $column]);
        $cache[$key] = str_contains(strtoupper((string)$st->fetchColumn()), 'GENERATED');
    }
    return $cache[$key];
}

function assert_row_exists(PDO $pdo, string $table, int $id, string $message): void
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE id = ?");
    $st->execute([$id]);
    if (!(int)$st->fetchColumn()) throw new RuntimeException($message);
}

function assert_bonus_stat(PDO $pdo, int $statId, string $message): void
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM stats WHERE id = ? AND is_base = 0');
    $st->execute([$statId]);
    if (!(int)$st->fetchColumn()) throw new RuntimeException($message);
}

function read_decimal($raw, string $label, bool $required = true): ?string
{
    $s = trim((string)$raw);
    if ($s === '') {
        if ($required) throw new RuntimeException('Поле «' . $label . '» должно быть числом.');
        return null;
    }
    if (!is_numeric($s)) throw new RuntimeException('Поле «' . $label . '» должно быть числом.');
    if (abs((float)$s) > 99999999.99) throw new RuntimeException('Поле «' . $label . '»: значение слишком большое.');
    return $s;
}

function fetch_classes(PDO $pdo): array
{
    return $pdo->query('SELECT c.id, c.name, c.parent_id, c.sort_order, p.name AS parent_name
        FROM classes c LEFT JOIN classes p ON p.id = c.parent_id
        ORDER BY c.parent_id IS NOT NULL, COALESCE(p.sort_order, c.sort_order), c.sort_order, c.id')->fetchAll();
}

function fetch_stats(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM stats WHERE is_base = 0 ORDER BY category, sort_order, id')->fetchAll();
}

function fetch_features(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM features ORDER BY sort_order, id')->fetchAll();
}

function fetch_text_notes(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM text_notes ORDER BY sort_order, id')->fetchAll();
}

function fetch_states(PDO $pdo): array
{
    return $pdo->query('SELECT id, name, is_default FROM states ORDER BY is_default DESC, sort_order, id')->fetchAll();
}

function fetch_item_types(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM item_types ORDER BY sort_order, id')->fetchAll();
}

function scaling_type_labels(bool $forCard): array
{
    return enum_rows('scaling_type', $forCard ? 'card' : 'item');
}

function class_checkbox_grid(array $classes, array $selectedIds): void
{
    echo '<div class="checkbox-grid">';
    foreach ($classes as $c) {
        $id = (int)$c['id'];
        echo '<label><input type="checkbox" name="class_ids[]" value="' . $id . '"'
            . checked(in_array($id, $selectedIds, true)) . '><span>' . e($c['name']);
        if (!empty($c['parent_name'])) echo ' <em>(' . e($c['parent_name']) . ')</em>';
        echo '</span></label>';
    }
    echo '</div>';
}

function item_type_condition_block(array $itemTypes, array $rows): void
{
    $options = '<option value="0">— тип предмета —</option>';
    foreach ($itemTypes as $t) {
        $options .= '<option value="' . (int)$t['id'] . '">' . e($t['name']) . '</option>';
    }
    $max = slot_count();
    echo '<div class="toolbar"><button type="button" class="btn btn-primary js-add-condition">' . admin_icon('plus', 16) . 'Добавить условие</button></div>';
    echo '<div id="item-type-conditions" class="dynamic-rows">';
    foreach ($rows as $row) {
        $tid = (int)($row['item_type_id'] ?? 0);
        $cnt = (int)($row['required_count'] ?? 1);
        $sel = '<select name="condition_item_type_id[]"><option value="0">— тип предмета —</option>';
        foreach ($itemTypes as $t) {
            $sel .= '<option value="' . (int)$t['id'] . '"' . selected($tid === (int)$t['id']) . '>' . e($t['name']) . '</option>';
        }
        $sel .= '</select>';
        echo '<div class="dynamic-row dynamic-row-3">' . $sel
            . '<input type="number" name="condition_required_count[]" min="1" max="' . $max . '" value="' . $cnt . '" placeholder="Количество">'
            . '<button type="button" class="btn btn-danger js-remove-condition">' . admin_icon('trash', 16) . 'Удалить</button></div>';
    }
    echo '</div>';
    echo '<template id="item-type-condition-template"><div class="dynamic-row dynamic-row-3">'
        . '<select name="condition_item_type_id[]">' . $options . '</select>'
        . '<input type="number" name="condition_required_count[]" min="1" max="' . $max . '" value="1" placeholder="Количество">'
        . '<button type="button" class="btn btn-danger js-remove-condition">' . admin_icon('trash', 16) . 'Удалить</button>'
        . '</div></template>';
}

function read_class_ids(PDO $pdo): array
{
    $ids = array_values(array_unique(array_map('intval', (array)($_POST['class_ids'] ?? []))));
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id FROM classes WHERE id IN ($ph)");
    $st->execute($ids);
    return array_values(array_intersect($ids, array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN))));
}

function read_condition_mode(): string
{
    $mode = (string)($_POST['item_type_condition_mode'] ?? 'all');
    if (!in_array($mode, ['all', 'any'], true)) {
        throw new RuntimeException('Некорректный режим условий по типам предметов.');
    }
    return $mode;
}

function read_item_type_conditions(PDO $pdo): array
{
    $typeIds = (array)($_POST['condition_item_type_id'] ?? []);
    $counts  = (array)($_POST['condition_required_count'] ?? []);
    $n = max(count($typeIds), count($counts));
    $rows = [];
    $seen = [];
    $maxItems = slot_count();
    for ($i = 0; $i < $n; $i++) {
        $tid = (int)($typeIds[$i] ?? 0);
        $cnt = (int)($counts[$i] ?? 0);
        if ($tid <= 0 && $cnt <= 0) continue;
        if ($tid <= 0) throw new RuntimeException('Выберите тип предмета для условия.');
        if ($cnt < 1 || $cnt > $maxItems) throw new RuntimeException('Количество предметов должно быть от 1 до ' . $maxItems . '.');
        if (isset($seen[$tid])) throw new RuntimeException('Один тип предмета не может быть указан в условиях дважды.');
        $seen[$tid] = true;
        $rows[] = ['item_type_id' => $tid, 'required_count' => $cnt];
    }
    if ($rows) {
        $ids = array_column($rows, 'item_type_id');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT id FROM item_types WHERE id IN ($ph)");
        $st->execute($ids);
        $valid = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if (array_diff($ids, $valid)) throw new RuntimeException('Тип предмета не найден.');
    }
    return $rows;
}

function posted_condition_rows(): array
{
    $typeIds = (array)($_POST['condition_item_type_id'] ?? []);
    $counts  = (array)($_POST['condition_required_count'] ?? []);
    $rows = [];
    for ($i = 0, $n = max(count($typeIds), count($counts)); $i < $n; $i++) {
        $tid = (int)($typeIds[$i] ?? 0);
        $cnt = (int)($counts[$i] ?? 0);
        if ($tid <= 0 && $cnt <= 0) continue;
        $rows[] = ['item_type_id' => $tid, 'required_count' => $cnt];
    }
    return $rows;
}

/* ---------- Иконки ---------- */

function admin_icon(string $name, int $size = 18): string
{
    static $paths = [
        'home'    => '<path d="M3 10.6 12 3.2l9 7.4"/><path d="M5.6 9.6V20.8h12.8V9.6"/><path d="M9.8 20.8v-5.6h4.4v5.6"/>',
        'box'     => '<path d="M20.8 8.4 12 3.3 3.2 8.4v7.2L12 20.7l8.8-5.1V8.4Z"/><path d="m3.2 8.4 8.8 5.1 8.8-5.1"/><path d="M12 13.5v7.2"/>',
        'layers'  => '<path d="m12 3.2 8.6 4.6L12 12.4 3.4 7.8 12 3.2Z"/><path d="m3.4 12.4 8.6 4.6 8.6-4.6"/><path d="m3.4 16.6 8.6 4.6 8.6-4.6"/>',
        'users'   => '<circle cx="9.2" cy="8.2" r="3.3"/><path d="M3.4 20.2a5.8 5.8 0 0 1 11.6 0"/><path d="M16.2 5.4a3.3 3.3 0 0 1 0 6"/><path d="M17.4 20.2a6 6 0 0 0-2-4.3"/>',
        'flag'    => '<path d="M5.4 21V3.6"/><path d="M5.4 4.8h11.4l-1.7 3.6 1.7 3.6H5.4"/>',
        'shield'  => '<path d="M12 3.2 5.2 6v6c0 4.2 2.9 7.7 6.8 9 3.9-1.3 6.8-4.8 6.8-9V6L12 3.2Z"/><path d="m9.2 12 2 2 3.6-3.9"/>',
        'grid'    => '<rect x="3.4" y="3.4" width="7.2" height="7.2" rx="1.7"/><rect x="13.4" y="3.4" width="7.2" height="7.2" rx="1.7"/><rect x="3.4" y="13.4" width="7.2" height="7.2" rx="1.7"/><rect x="13.4" y="13.4" width="7.2" height="7.2" rx="1.7"/>',
        'columns' => '<rect x="3.4" y="4.2" width="4.8" height="15.6" rx="1.5"/><rect x="9.6" y="4.2" width="4.8" height="15.6" rx="1.5"/><rect x="15.8" y="4.2" width="4.8" height="15.6" rx="1.5"/>',
        'chart'   => '<path d="M3.4 20.4h17.2"/><path d="M6.4 20.4V11"/><path d="M11.2 20.4V4.4"/><path d="M16 20.4v-6.6"/>',
        'arrows'  => '<path d="M12 20V5.2"/><path d="m6.6 10.6 5.4-5.4 5.4 5.4"/><path d="M5 20.4h14"/>',
        'spark'   => '<path d="m12 3.2 2.2 5.7 5.7 2.2-5.7 2.2L12 19l-2.2-5.7L4.1 11.1l5.7-2.2L12 3.2Z"/>',
        'toggle'  => '<rect x="2.6" y="7.4" width="18.8" height="9.2" rx="4.6"/><circle cx="16.2" cy="12" r="2.6"/>',
        'note'    => '<path d="M6.2 3.6h8.6L18.8 8v12.4H6.2z"/><path d="M14.6 3.6V8h4.2"/><path d="M9 12.4h6.4"/><path d="M9 16.2h4.6"/>',
        'tabs'    => '<path d="M3.2 8.6a2 2 0 0 1 2-2h3.9l1.6 2.2h8.1a2 2 0 0 1 2 2v7.6a2 2 0 0 1-2 2H5.2a2 2 0 0 1-2-2Z"/>',
        'tags'    => '<path d="M11.2 3.6h9.2v9.2l-8.6 8.6a2 2 0 0 1-2.8 0l-6.4-6.4a2 2 0 0 1 0-2.8Z"/><circle cx="16.4" cy="7.6" r="1.4"/>',
        'settings'=> '<circle cx="12" cy="12" r="3.2"/><path d="M12 2.6v2.6M12 18.8v2.6M21.4 12h-2.6M5.2 12H2.6M18.7 5.3l-1.8 1.8M7.1 16.9l-1.8 1.8M18.7 18.7l-1.8-1.8M7.1 7.1 5.3 5.3"/>',
        'search'  => '<circle cx="11" cy="11" r="6.4"/><path d="m15.8 15.8 4.6 4.6"/>',
        'menu'    => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'close'   => '<path d="m6.4 6.4 11.2 11.2M17.6 6.4 6.4 17.6"/>',
        'chevron-left'  => '<path d="m14.4 5.6-6.2 6.4 6.2 6.4"/>',
        'chevron-right' => '<path d="m9.6 5.6 6.2 6.4-6.2 6.4"/>',
        'external'=> '<path d="M14.2 3.8h6v6"/><path d="M20.2 3.8 11.4 12.6"/><path d="M18 14v5.2a1.6 1.6 0 0 1-1.6 1.6H5.2a1.6 1.6 0 0 1-1.6-1.6V7.6A1.6 1.6 0 0 1 5.2 6h5.2"/>',
        'logout'  => '<path d="M14.6 4.4h3.8A1.6 1.6 0 0 1 20 6v12a1.6 1.6 0 0 1-1.6 1.6h-3.8"/><path d="M9.8 8.4 6.2 12l3.6 3.6"/><path d="M6.2 12h9"/>',
        'check'   => '<path d="m5.2 12.6 4.6 4.6L18.8 7.4"/>',
        'alert'   => '<circle cx="12" cy="12" r="8.6"/><path d="M12 7.6v5.2"/><path d="M12 16.4h.01"/>',
        'info'    => '<circle cx="12" cy="12" r="8.6"/><path d="M12 11.2v5.2"/><path d="M12 7.6h.01"/>',
        'warning' => '<path d="M12 3.8 21 19.6H3Z"/><path d="M12 9.8v4.2"/><path d="M12 17h.01"/>',
        'rows'    => '<rect x="3.4" y="5" width="17.2" height="4.6" rx="1.5"/><rect x="3.4" y="14.4" width="17.2" height="4.6" rx="1.5"/>',
        'user'    => '<circle cx="12" cy="8.4" r="3.5"/><path d="M5.2 20.2a6.8 6.8 0 0 1 13.6 0"/>',
        'calc'    => '<rect x="4.6" y="3.2" width="14.8" height="17.6" rx="2.2"/><path d="M8.2 7.4h7.6"/><path d="M8.6 12h.01M12 12h.01M15.4 12h.01M8.6 16.2h.01M12 16.2h.01M15.4 16.2h.01"/>',
        'plus'    => '<path d="M12 5.4v13.2M5.4 12h13.2"/>',
        'trash'   => '<path d="M4.6 7h14.8"/><path d="M9.4 7V4.8h5.2V7"/><path d="M6.6 7l.9 12.4a1.6 1.6 0 0 0 1.6 1.4h5.8a1.6 1.6 0 0 0 1.6-1.4L17.4 7"/>',
        'dot'     => '<circle cx="12" cy="12" r="3"/>',
    ];
    $d = $paths[$name] ?? $paths['dot'];
    return '<svg class="ico" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" '
        . 'stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . $d . '</svg>';
}

/* ---------- Меню ---------- */

function admin_menu(): array
{
    return ['groups' => [
        ['title' => 'Обзор', 'items' => [
            ['action' => 'dashboard', 'title' => 'Статистика', 'description' => 'Сводные цифры по всем данным калькулятора.'],
        ]],
        ['title' => 'Предметы', 'items' => [
            ['action' => 'items_section', 'title' => 'Обзор предметов', 'description' => 'Статистика по предметам, типам и ячейкам.'],
            ['action' => 'items',         'title' => 'Список предметов', 'description' => 'Поиск, фильтры, создание и редактирование предметов.'],
            ['action' => 'types',         'title' => 'Типы предметов',   'description' => 'Категории экипировки: оружие, броня, аксессуары.'],
        ]],
        ['title' => 'Карты', 'items' => [
            ['action' => 'cards_section', 'title' => 'Обзор карт',  'description' => 'Статистика по картам, типам и сетам.'],
            ['action' => 'cards',         'title' => 'Список карт', 'description' => 'Поиск, фильтры, создание и редактирование карт.'],
            ['action' => 'card_types',    'title' => 'Типы карт',   'description' => 'Типы карт и ограничение по номеру слота.'],
            ['action' => 'card_sets',     'title' => 'Сеты карт',   'description' => 'Пороги, режимы отрицательных значений, сетовые бонусы.'],
        ]],
        ['title' => 'Печати', 'items' => [
            ['action' => 'stamps',       'title' => 'Список печатей', 'description' => 'Печати, их цвета, ограничения и характеристики вариантов.'],
            ['action' => 'stamp_colors', 'title' => 'Цвета печатей',  'description' => 'Справочник цветов и их отображаемый оттенок.'],
        ]],
        ['title' => 'Персонаж', 'items' => [
            ['action' => 'classes',      'title' => 'Классы',        'description' => 'Базовые классы, подклассы и диапазоны уровней.'],
            ['action' => 'states',       'title' => 'Состояния',     'description' => 'Состояния персонажа, влияющие на активность предметов и карт.'],
            ['action' => 'slot_rules',   'title' => 'Правила ячеек', 'description' => 'Доступ, блокировки, исключения, показ и скрытие ячеек.'],
            ['action' => 'slots',        'title' => 'Ячейки',        'description' => 'Номера, названия и парные группы ячеек экипировки.'],
            ['action' => 'slot_columns', 'title' => 'Колонки ячеек', 'description' => 'Колонки блока экипировки и их выравнивание.'],
        ]],
        ['title' => 'Механика', 'items' => [
            ['action' => 'stats',          'title' => 'Характеристики',   'description' => 'Бонусные и базовые характеристики, поиск и формат вывода.'],
            ['action' => 'upgrade_levels', 'title' => 'Уровни улучшения', 'description' => 'Проценты атаки и защиты для каждого уровня улучшения.'],
            ['action' => 'grades',         'title' => 'Грейды',           'description' => 'Грейды предметов, порядок сортировки и лимит улучшения.'],
            ['action' => 'features',       'title' => 'Функции',          'description' => 'Условные функции для бонусов и описаний.'],
            ['action' => 'text_notes',     'title' => 'Текстовые заметки','description' => 'Дополнительные тексты под характеристиками.'],
        ]],
        ['title' => 'Интерфейс', 'items' => [
            ['action' => 'modal_tabs',  'title' => 'Вкладки окна',  'description' => 'Вкладки модального окна выбора предметов и карт.'],
            ['action' => 'enum_labels', 'title' => 'Подписи кодов', 'description' => 'Названия кодов и тексты причин с подстановками.'],
            ['action' => 'settings',    'title' => 'Параметры',     'description' => 'Лимиты слотов, уровней, сетов и округление улучшения.'],
        ]],
    ]];
}

function admin_action_alias(string $action): string
{
    static $map = [
        'item_form'             => 'items',
        'item_bonus_conditions' => 'items',
        'item_cards'            => 'items',
        'card_form'             => 'cards',
        'card_bonuses'          => 'cards',
        'card_set_bonuses'      => 'card_sets',
        'stamp_form'            => 'stamps',
        'stamp_bonuses'         => 'stamps',
        'item_stamp'            => 'items',
    ];
    return $map[$action] ?? $action;
}

function admin_child_title(string $action, string $fallback): string
{
    static $map = [
        'item_form'             => 'Форма предмета',
        'item_bonus_conditions' => 'Условия характеристики',
        'item_cards'            => 'Карты предмета',
        'card_form'             => 'Форма карты',
        'card_bonuses'          => 'Характеристики карты',
        'card_set_bonuses'      => 'Сетовые бонусы',
        'stamp_form'            => 'Форма печати',
        'stamp_bonuses'         => 'Характеристики варианта',
        'item_stamp'            => 'Печать предмета',
    ];
    return $map[$action] ?? $fallback;
}

function admin_breadcrumbs(string $activeAction, array $menu, string $pageTitle): array
{
    $crumbs = [['title' => 'Обзор', 'href' => admin_url('dashboard')]];
    if ($activeAction === 'dashboard') {
        return $crumbs;
    }
    $alias = admin_action_alias($activeAction);
    $found = false;
    foreach ($menu['groups'] as $group) {
        foreach ($group['items'] as $item) {
            if ($item['action'] !== $alias) continue;
            $found = true;
            $crumbs[] = ['title' => $group['title'], 'href' => null];
            $crumbs[] = [
                'title' => $item['title'],
                'href'  => $alias === $activeAction ? null : admin_url($alias),
            ];
            break 2;
        }
    }
    if (!$found) {
        $crumbs[] = ['title' => admin_child_title($activeAction, $pageTitle), 'href' => null];
        return $crumbs;
    }
    if ($alias !== $activeAction) {
        $crumbs[] = ['title' => admin_child_title($activeAction, $pageTitle), 'href' => null];
    }
    return $crumbs;
}

/* ---------- Оболочка ---------- */

function admin_layout_start(string $pageTitle, string $activeAction, array $menu): void
{
    $flashes = get_flashes();
    $alias = admin_action_alias($activeAction);
    $crumbs = admin_breadcrumbs($activeAction, $menu, $pageTitle);
    $user = (string)($_SESSION['admin_user'] ?? '');
    $initial = $user !== '' ? mb_strtoupper(mb_substr($user, 0, 1)) : 'A';
    $toastTitles = ['success' => 'Готово', 'error' => 'Ошибка', 'warning' => 'Внимание', 'info' => 'Информация'];
    $toastIcons  = ['success' => 'check', 'error' => 'alert', 'warning' => 'warning', 'info' => 'info'];
    ?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light">
<meta name="robots" content="noindex, nofollow">
<title><?= e($pageTitle) ?> — Kamikaze Calc Admin</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='9' fill='%232fb380'/%3E%3Cpath d='M9 22V10h2.6v5.2L16.4 10h3.1l-5 5.6 5.3 6.4h-3.3l-3.9-4.9-1 1.1V22z' fill='white'/%3E%3C/svg%3E">
<link rel="stylesheet" href="<?= e(asset_url('admin.css')) ?>">
</head>
<body class="admin-body">
<a class="skip-link" href="#admin-main">Перейти к содержимому</a>

<div class="toast-stack" id="toast-stack">
<?php foreach ($flashes as $i => $f):
    $type = isset($toastTitles[$f['type']]) ? (string)$f['type'] : 'info'; ?>
<div class="toast toast-<?= e($type) ?>" data-toast data-toast-type="<?= e($type) ?>"
     role="<?= $type === 'error' ? 'alert' : 'status' ?>" style="--i:<?= (int)$i ?>">
<span class="toast-ico"><?= admin_icon($toastIcons[$type], 18) ?></span>
<div class="toast-body">
<b class="toast-title"><?= e($toastTitles[$type]) ?></b>
<span class="toast-text"><?= e($f['message']) ?></span>
</div>
<button type="button" class="toast-close" data-toast-close aria-label="Закрыть уведомление"><?= admin_icon('close', 15) ?></button>
</div>
<?php endforeach; ?>
</div>

<div class="admin-shell" id="admin-shell">
<aside class="admin-sidebar" id="admin-sidebar" aria-label="Основная навигация">
<div class="sidebar-brand">
<span class="brand-mark" aria-hidden="true">KC</span>
<span class="brand-text">
<b>Kamikaze Calc</b>
<i>Панель управления</i>
</span>
</div>

<div class="sidebar-search">
<span class="search-ico" aria-hidden="true"><?= admin_icon('search', 16) ?></span>
<input type="search" id="nav-search" placeholder="Поиск по разделам" autocomplete="off"
       aria-label="Поиск по разделам">
</div>

<nav class="admin-nav" id="admin-nav">
<?php foreach ($menu['groups'] as $group): ?>
<div class="nav-group" data-nav-group>
<div class="nav-group-title"><span><?= e($group['title']) ?></span></div>
<?php foreach ($group['items'] as $item):
    $isActive = $alias === $item['action'];
    $haystack = mb_strtolower($item['title'] . ' ' . ($item['description'] ?? '') . ' ' . $item['action']);
?>
<a class="nav-link<?= $isActive ? ' is-active' : '' ?>" data-nav-link
   data-nav-search="<?= e($haystack) ?>"
   href="<?= e($item['href'] ?? admin_url($item['action'])) ?>"
   title="<?= e($item['title']) ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
<span class="nav-ico"><?= admin_icon('dot', 16) ?></span>
<span class="nav-text"><?= e($item['title']) ?></span>
</a>
<?php endforeach; ?>
</div>
<?php endforeach; ?>
<p class="nav-empty" data-nav-empty hidden>Ничего не найдено</p>
</nav>

<div class="sidebar-foot">
<a class="btn btn-ghost btn-block" href="../index.html" target="_blank" rel="noopener"
   title="Открыть калькулятор"><?= admin_icon('calc', 17) ?><span>Калькулятор</span><?= admin_icon('external', 14) ?></a>
<div class="user-chip">
<span class="user-avatar" aria-hidden="true"><?= e($initial) ?></span>
<span class="user-name"><?= e($user) ?></span>
<a class="icon-btn" href="<?= e(admin_url('logout')) ?>" aria-label="Выйти" title="Выйти"><?= admin_icon('logout', 17) ?></a>
</div>
</div>
</aside>

<div class="admin-main-wrap">
<header class="admin-topbar">
<nav class="crumbs" aria-label="Хлебные крошки">
<?php foreach ($crumbs as $i => $crumb): ?>
<?php if ($i > 0): ?><span class="crumb-sep" aria-hidden="true"><?= admin_icon('chevron-right', 13) ?></span><?php endif; ?>
<?php if ($crumb['href'] !== null): ?>
<a class="crumb" href="<?= e($crumb['href']) ?>"><?= e($crumb['title']) ?></a>
<?php else: ?>
<span class="crumb is-current" aria-current="page"><?= e($crumb['title']) ?></span>
<?php endif; ?>
<?php endforeach; ?>
</nav>
<div class="topbar-actions">
<a class="btn btn-ghost topbar-calc" href="../index.html" target="_blank" rel="noopener">
<?= admin_icon('calc', 17) ?><span>Калькулятор</span>
</a>
<span class="topbar-user" title="<?= e($user) ?>"><?= e($initial) ?></span>
</div>
</header>

<main class="admin-main" id="admin-main" data-page="<?= e($activeAction) ?>">
<?php
}

function admin_layout_end(): void
{
    ?>
</main>
<footer class="admin-foot">
<span>Kamikaze Calc · панель администрирования</span>
<span class="foot-hint"><kbd>/</kbd> — поиск по разделам · <kbd>Esc</kbd> — закрыть меню</span>
</footer>
</div>
</div>
<script src="<?= e(asset_url('admin.js')) ?>" defer></script>
<script src="<?= e(asset_url('shell.js')) ?>" defer></script>
</body>
</html>
<?php
}