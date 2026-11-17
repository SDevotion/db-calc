<?php
declare(strict_types=1);
define('ADMIN_ENTRY', true);

require __DIR__ . '/core.php';
require __DIR__ . '/crud.php';

$action = strtolower((string)preg_replace('/[^a-z0-9_]/', '', (string)($_GET['action'] ?? 'dashboard')));
if ($action === '') {
    $action = 'dashboard';
}
$menu = admin_menu();

/* ======================= Вспомогательные ======================= */

function admin_scalar(PDO $pdo, string $sql, array $params = []): int
{
    $rows = admin_rows_safe($pdo, $sql, $params);
    return $rows ? (int)reset($rows[0]) : 0;
}

function admin_rows_safe(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function admin_stat_tile(string $label, int $value, string $tone = ''): void
{
    $class = 'admin-stat' . ($tone !== '' ? ' is-' . $tone : '');
    echo '<div class="' . e($class) . '">'
        . '<div class="admin-stat-label">' . e($label) . '</div>'
        . '<div class="admin-stat-value">' . $value . '</div>'
        . '</div>';
}

/** Счётчики всех справочников — чистая статистика наполненности. */
function admin_reference_counts(PDO $pdo): array
{
    return [
        'Типы предметов'      => admin_scalar($pdo, 'SELECT COUNT(*) FROM item_types'),
        'Типы карт'           => admin_scalar($pdo, 'SELECT COUNT(*) FROM card_types'),
        'Сеты карт'           => admin_scalar($pdo, 'SELECT COUNT(*) FROM card_sets'),
        'Классы и подклассы'  => admin_scalar($pdo, 'SELECT COUNT(*) FROM classes'),
        'Состояния'           => admin_scalar($pdo, 'SELECT COUNT(*) FROM states'),
        'Характеристики'      => admin_scalar($pdo, 'SELECT COUNT(*) FROM stats'),
        'Функции'             => admin_scalar($pdo, 'SELECT COUNT(*) FROM features'),
        'Текстовые заметки'   => admin_scalar($pdo, 'SELECT COUNT(*) FROM text_notes'),
        'Ячейки экипировки'   => admin_scalar($pdo, 'SELECT COUNT(*) FROM slots'),
        'Колонки ячеек'       => admin_scalar($pdo, 'SELECT COUNT(*) FROM slot_columns'),
        'Правила ячеек'       => admin_scalar($pdo, 'SELECT COUNT(*) FROM slot_rules'),
        'Грейды'              => admin_scalar($pdo, 'SELECT COUNT(*) FROM grades'),
        'Уровни улучшения'    => admin_scalar($pdo, 'SELECT COUNT(*) FROM upgrade_levels'),
        'Вкладки окна'        => admin_scalar($pdo, 'SELECT COUNT(*) FROM modal_tabs'),
        'Подписи кодов'       => admin_scalar($pdo, 'SELECT COUNT(*) FROM enum_labels'),
        'Параметры'           => admin_scalar($pdo, 'SELECT COUNT(*) FROM settings'),
    ];
}

/* ======================= Вход и выход ======================= */

function render_login(string $error): void
{
    ?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light">
<meta name="robots" content="noindex, nofollow">
<title>Вход — Kamikaze Calc Admin</title>
<link rel="stylesheet" href="<?= e(asset_url('admin.css')) ?>">
</head>
<body class="admin-body">
<div class="login-wrap">
<form method="post" action="<?= e(admin_url('login')) ?>" class="login-card">
<div class="admin-brand">Kamikaze Calc</div>
<?php if (!auth_configured()): ?>
<div class="flash error">Не заданы <b>ADMIN_USER</b> и <b>ADMIN_PASS</b> в <code>config.local.php</code>. Вход невозможен, пока параметры не заполнены.</div>
<?php endif; ?>
<?php if ($error !== ''): ?>
<div class="flash error"><?= e($error) ?></div>
<?php endif; ?>
<?= csrf_field() ?>
<div class="field">
<label for="user">Логин</label>
<input type="text" id="user" name="user" required autofocus autocomplete="username"
       value="<?= e((string)($_POST['user'] ?? '')) ?>">
</div>
<div class="field">
<label for="pass">Пароль</label>
<input type="password" id="pass" name="pass" required autocomplete="current-password">
</div>
<button type="submit" class="btn btn-primary"<?= auth_configured() ? '' : ' disabled' ?>>Войти в панель</button>
<p class="muted" style="margin:0">После пяти неудачных попыток вход блокируется на минуту.</p>
</form>
</div>
</body>
</html><?php
}

if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
    }
    session_destroy();
    redirect('?action=login');
}

if ($action === 'login') {
    if (auth_check()) {
        redirect(admin_url('dashboard'));
    }
    $error = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        verify_csrf();
        $now  = time();
        $lock = (int)($_SESSION['login_lock_until'] ?? 0);
        $user = trim((string)($_POST['user'] ?? ''));
        if ($lock > $now) {
            $error = 'Слишком много попыток. Подождите ' . ($lock - $now) . ' с.';
        } elseif (auth_attempt($user, (string)($_POST['pass'] ?? ''))) {
            session_regenerate_id(true);
            $_SESSION['admin_user'] = $user;
            $_SESSION['login_attempts'] = 0;
            unset($_SESSION['login_lock_until']);
            flash('success', 'Добро пожаловать, ' . $user . '.');
            redirect(admin_url('dashboard'));
        } else {
            $n = (int)($_SESSION['login_attempts'] ?? 0) + 1;
            $_SESSION['login_attempts'] = $n;
            if ($n >= 5) {
                $_SESSION['login_lock_until'] = $now + 60;
                $_SESSION['login_attempts'] = 0;
            }
            usleep(400000);
            $error = 'Неверный логин или пароль.';
        }
    }
    render_login($error);
    exit;
}

require_auth();

/* ======================= Справочники ======================= */

$definitions = crud_definitions();
if (isset($definitions[$action])) {
    crud_page($definitions[$action], $action, $menu);
    exit;
}

/* ======================= Главная: только статистика ======================= */

function page_dashboard(string $action, array $menu): void
{
    $pdo = db();

    $items       = admin_scalar($pdo, 'SELECT COUNT(*) FROM items');
    $cards       = admin_scalar($pdo, 'SELECT COUNT(*) FROM cards');
    $stats       = admin_scalar($pdo, 'SELECT COUNT(*) FROM stats WHERE is_base = 0');
    $baseStats   = admin_scalar($pdo, 'SELECT COUNT(*) FROM stats WHERE is_base = 1');
    $classes     = admin_scalar($pdo, 'SELECT COUNT(*) FROM classes');
    $subclasses  = admin_scalar($pdo, 'SELECT COUNT(*) FROM classes WHERE parent_id IS NOT NULL');
    $slots       = admin_scalar($pdo, 'SELECT COUNT(*) FROM slots');
    $slotRules   = admin_scalar($pdo, 'SELECT COUNT(*) FROM slot_rules');
    $sets        = admin_scalar($pdo, 'SELECT COUNT(*) FROM card_sets');
    $grades      = admin_scalar($pdo, 'SELECT COUNT(*) FROM grades');
    $upgrades    = admin_scalar($pdo, 'SELECT COUNT(*) FROM upgrade_levels');
    $states      = admin_scalar($pdo, 'SELECT COUNT(*) FROM states');

    $itemBonuses = admin_scalar($pdo, 'SELECT COUNT(*) FROM item_bonuses');
    $cardBonuses = admin_scalar($pdo, 'SELECT COUNT(*) FROM card_bonuses');
    $setBonuses  = admin_scalar($pdo, 'SELECT COUNT(*) FROM card_set_bonuses');
    $inserted    = admin_scalar($pdo, 'SELECT COUNT(*) FROM item_cards');
    $forced      = admin_scalar($pdo, 'SELECT COUNT(*) FROM item_cards WHERE is_locked = 1');
    $socketSlots = admin_scalar($pdo, 'SELECT COALESCE(SUM(sockets), 0) FROM items');

    $noSlots     = admin_scalar($pdo, 'SELECT COUNT(*) FROM items i WHERE NOT EXISTS (SELECT 1 FROM item_slots s WHERE s.item_id = i.id)');
    $noClasses   = admin_scalar($pdo, 'SELECT COUNT(*) FROM items i WHERE NOT EXISTS (SELECT 1 FROM item_classes c WHERE c.item_id = i.id)');
    $noTypes     = admin_scalar($pdo, 'SELECT COUNT(*) FROM cards c WHERE NOT EXISTS (SELECT 1 FROM card_allowed_item_types a WHERE a.card_id = c.id)');
    $noMain      = admin_scalar($pdo, 'SELECT COUNT(*) FROM card_sets s WHERE NOT EXISTS (SELECT 1 FROM cards c WHERE c.set_id = s.id AND c.is_set_main = 1)');
    $noDefault   = admin_scalar($pdo, 'SELECT COUNT(*) FROM states') > 0
        ? (admin_scalar($pdo, 'SELECT COUNT(*) FROM states WHERE is_default = 1') === 0 ? 1 : 0)
        : 1;

    $typeStats = admin_rows_safe($pdo, '
        SELECT t.name,
               COUNT(i.id) AS item_count,
               SUM(CASE WHEN i.can_upgrade = 1 THEN 1 ELSE 0 END) AS upgradable,
               SUM(CASE WHEN i.sockets > 0 THEN 1 ELSE 0 END) AS with_slots
        FROM item_types t
        LEFT JOIN items i ON i.item_type_id = t.id
        GROUP BY t.id, t.name
        ORDER BY t.sort_order, t.id
    ');
    $gradeStats = admin_rows_safe($pdo, '
        SELECT g.code, g.name, g.max_upgrade_level, COUNT(i.id) AS item_count
        FROM grades g
        LEFT JOIN items i ON i.grade = g.code
        GROUP BY g.code, g.name, g.max_upgrade_level
        ORDER BY g.sort_order, g.code
    ');
    $setStats = admin_rows_safe($pdo, '
        SELECT s.name,
               COUNT(c.id) AS card_count,
               SUM(CASE WHEN c.is_set_main = 1 THEN 1 ELSE 0 END) AS main_count,
               (SELECT COUNT(*) FROM card_set_bonuses b WHERE b.set_id = s.id) AS bonus_count
        FROM card_sets s
        LEFT JOIN cards c ON c.set_id = s.id
        GROUP BY s.id, s.name
        ORDER BY s.sort_order, s.id
    ');
    $references = admin_reference_counts($pdo);

    admin_layout_start('Статистика', $action, $menu);
    ?>
<div class="page-head">
<h1>Статистика</h1>
<p class="muted">Сводные показатели по всем данным калькулятора. Переход к разделам — в меню слева.</p>
</div>
<div class="admin-stat-grid">
<?php
    admin_stat_tile('Предметы', $items);
    admin_stat_tile('Карты', $cards);
    admin_stat_tile('Характеристики', $stats);
    admin_stat_tile('Классы и подклассы', $classes);
    admin_stat_tile('Ячейки экипировки', $slots);
    admin_stat_tile('Правила ячеек', $slotRules);
?>
</div>

<h2 class="admin-section-title">Наполнение</h2>
<div class="admin-stat-grid">
<?php
    admin_stat_tile('Бонусы предметов', $itemBonuses);
    admin_stat_tile('Бонусы карт', $cardBonuses);
    admin_stat_tile('Сетовые бонусы', $setBonuses);
    admin_stat_tile('Карт в предметах', $inserted);
    admin_stat_tile('Принудительных карт', $forced);
    admin_stat_tile('Всего слотов', $socketSlots);
?>
</div>

<h2 class="admin-section-title">Требует внимания</h2>
<div class="admin-stat-grid">
<?php
    admin_stat_tile('Предметы без ячеек', $noSlots, $noSlots > 0 ? 'warn' : 'ok');
    admin_stat_tile('Предметы без классов', $noClasses, $noClasses > 0 ? 'warn' : 'ok');
    admin_stat_tile('Карты без типов предметов', $noTypes, $noTypes > 0 ? 'warn' : 'ok');
    admin_stat_tile('Сеты без главной карты', $noMain, $noMain > 0 ? 'warn' : 'ok');
    admin_stat_tile('Нет состояния по умолчанию', $noDefault, $noDefault > 0 ? 'warn' : 'ok');
?>
</div>

<h2 class="admin-section-title">Предметы по типам</h2>
<div class="card">
<table>
<thead><tr><th>Тип предмета</th><th>Предметов</th><th>Улучшаемых</th><th>Со слотами</th></tr></thead>
<tbody>
<?php if (!$typeStats): ?><tr><td colspan="4">Типы предметов не заданы.</td></tr><?php endif; ?>
<?php foreach ($typeStats as $row): ?>
<tr>
<td><b><?= e($row['name']) ?></b></td>
<td><?= (int)$row['item_count'] ?></td>
<td><?= (int)$row['upgradable'] ?></td>
<td><?= (int)$row['with_slots'] ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<h2 class="admin-section-title">Предметы по грейдам</h2>
<div class="card">
<table>
<thead><tr><th>Грейд</th><th>Код</th><th>Предметов</th><th>Лимит улучшения</th></tr></thead>
<tbody>
<?php if (!$gradeStats): ?><tr><td colspan="4">Грейды не заданы.</td></tr><?php endif; ?>
<?php foreach ($gradeStats as $row): ?>
<tr>
<td><b><?= e($row['name']) ?></b></td>
<td><?= e($row['code']) ?></td>
<td><?= (int)$row['item_count'] ?></td>
<td><?= $row['max_upgrade_level'] === null ? '<span class="badge">без ограничения</span>' : '<span class="badge badge-ok">' . (int)$row['max_upgrade_level'] . '</span>' ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<h2 class="admin-section-title">Карты по сетам</h2>
<div class="card">
<table>
<thead><tr><th>Сет</th><th>Карт</th><th>Главных карт</th><th>Сетовых бонусов</th></tr></thead>
<tbody>
<?php if (!$setStats): ?><tr><td colspan="4">Сеты карт не заданы.</td></tr><?php endif; ?>
<?php foreach ($setStats as $row): $main = (int)$row['main_count']; ?>
<tr>
<td><b><?= e($row['name']) ?></b></td>
<td><?= (int)$row['card_count'] ?></td>
<td><?= $main > 0 ? '<span class="badge badge-ok">' . $main . '</span>' : '<span class="badge badge-danger">нет</span>' ?></td>
<td><?= (int)$row['bonus_count'] ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<h2 class="admin-section-title">Справочники</h2>
<div class="card">
<table>
<thead><tr><th>Справочник</th><th>Записей</th><th>Состояние</th></tr></thead>
<tbody>
<?php foreach ($references as $label => $count): ?>
<tr>
<td><b><?= e($label) ?></b></td>
<td><?= (int)$count ?></td>
<td><?= $count > 0 ? '<span class="badge badge-ok">заполнен</span>' : '<span class="badge badge-warn">пусто</span>' ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<h2 class="admin-section-title">Механика</h2>
<div class="admin-stat-grid">
<?php
    admin_stat_tile('Базовые характеристики', $baseStats, $baseStats > 0 ? '' : 'warn');
    admin_stat_tile('Уровни улучшения', $upgrades, $upgrades > 0 ? '' : 'warn');
    admin_stat_tile('Грейды', $grades, $grades > 0 ? '' : 'warn');
    admin_stat_tile('Сеты карт', $sets);
    admin_stat_tile('Состояния', $states, $states > 0 ? '' : 'warn');
    admin_stat_tile('Подклассы', $subclasses);
?>
</div>
<?php
    admin_layout_end();
}

/* ======================= Раздел: Предметы ======================= */

function page_items_section(string $action, array $menu): void
{
    $pdo = db();
    $total       = admin_scalar($pdo, 'SELECT COUNT(*) FROM items');
    $typesCount  = admin_scalar($pdo, 'SELECT COUNT(*) FROM item_types');
    $upgradable  = admin_scalar($pdo, 'SELECT COUNT(*) FROM items WHERE can_upgrade = 1');
    $withSlots   = admin_scalar($pdo, 'SELECT COUNT(*) FROM items WHERE sockets > 0');
    $withCards   = admin_scalar($pdo, 'SELECT COUNT(DISTINCT item_id) FROM item_cards');
    $bonuses     = admin_scalar($pdo, 'SELECT COUNT(*) FROM item_bonuses');
    $socketSlots = admin_scalar($pdo, 'SELECT COALESCE(SUM(sockets), 0) FROM items');
    $noSlots     = admin_scalar($pdo, 'SELECT COUNT(*) FROM items i WHERE NOT EXISTS (SELECT 1 FROM item_slots s WHERE s.item_id = i.id)');
    $noClasses   = admin_scalar($pdo, 'SELECT COUNT(*) FROM items i WHERE NOT EXISTS (SELECT 1 FROM item_classes c WHERE c.item_id = i.id)');

    $typeStats = admin_rows_safe($pdo, '
        SELECT t.name,
               COUNT(i.id) AS item_count,
               SUM(CASE WHEN i.can_upgrade = 1 THEN 1 ELSE 0 END) AS upgradable,
               SUM(CASE WHEN i.sockets > 0 THEN 1 ELSE 0 END) AS with_slots,
               COALESCE(SUM(i.sockets), 0) AS slot_count
        FROM item_types t
        LEFT JOIN items i ON i.item_type_id = t.id
        GROUP BY t.id, t.name
        ORDER BY t.sort_order, t.id
    ');
    $slotStats = admin_rows_safe($pdo, '
        SELECT s.slot_number, s.name, COUNT(its.item_id) AS item_count
        FROM slots s
        LEFT JOIN item_slots its ON its.slot_number = s.slot_number
        GROUP BY s.slot_number, s.name
        ORDER BY s.column_no, s.sort_order, s.slot_number
    ');

    admin_layout_start('Предметы · статистика', $action, $menu);
    ?>
<div class="page-head">
<h1>Обзор предметов</h1>
<p class="muted">Наполнение каталога предметов, распределение по типам и заполненность ячеек.</p>
</div>
<div class="admin-stat-grid">
<?php
    admin_stat_tile('Всего предметов', $total);
    admin_stat_tile('Типов предметов', $typesCount);
    admin_stat_tile('Можно улучшать', $upgradable);
    admin_stat_tile('Со слотами', $withSlots);
    admin_stat_tile('С картами', $withCards);
    admin_stat_tile('Бонусных характеристик', $bonuses);
?>
</div>

<h2 class="admin-section-title">Требует внимания</h2>
<div class="admin-stat-grid">
<?php
    admin_stat_tile('Предметы без ячеек', $noSlots, $noSlots > 0 ? 'warn' : 'ok');
    admin_stat_tile('Предметы без классов', $noClasses, $noClasses > 0 ? 'warn' : 'ok');
    admin_stat_tile('Всего слотов', $socketSlots);
?>
</div>

<h2 class="admin-section-title">Предметы по типам</h2>
<div class="card">
<table>
<thead><tr><th>Тип предмета</th><th>Предметов</th><th>Улучшаемых</th><th>Со слотами</th><th>Слотов</th></tr></thead>
<tbody>
<?php if (!$typeStats): ?><tr><td colspan="5">Типы предметов не заданы.</td></tr><?php endif; ?>
<?php foreach ($typeStats as $row): ?>
<tr>
<td><b><?= e($row['name']) ?></b></td>
<td><?= (int)$row['item_count'] ?></td>
<td><?= (int)$row['upgradable'] ?></td>
<td><?= (int)$row['with_slots'] ?></td>
<td><?= (int)$row['slot_count'] ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<h2 class="admin-section-title">Заполненность ячеек</h2>
<div class="card">
<table>
<thead><tr><th>Ячейка</th><th>Название</th><th>Доступно предметов</th><th>Заполненность</th></tr></thead>
<tbody>
<?php if (!$slotStats): ?><tr><td colspan="4">Ячейки не заданы.</td></tr><?php endif; ?>
<?php foreach ($slotStats as $row): $count = (int)$row['item_count']; ?>
<tr>
<td><?= (int)$row['slot_number'] ?></td>
<td><b><?= e($row['name']) ?></b></td>
<td><?= $count ?></td>
<td><?= $count > 0 ? '<span class="badge badge-ok">заполнена</span>' : '<span class="badge badge-warn">пусто</span>' ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php
    admin_layout_end();
}

/* ======================= Раздел: Карты ======================= */

function page_cards_section(string $action, array $menu): void
{
    $pdo = db();
    $totalCards = admin_scalar($pdo, 'SELECT COUNT(*) FROM cards');
    $totalTypes = admin_scalar($pdo, 'SELECT COUNT(*) FROM card_types');
    $totalSets  = admin_scalar($pdo, 'SELECT COUNT(*) FROM card_sets');
    $usedCards  = admin_scalar($pdo, 'SELECT COUNT(DISTINCT card_id) FROM item_cards');
    $mainCards  = admin_scalar($pdo, 'SELECT COUNT(*) FROM cards WHERE is_set_main = 1');
    $bonuses    = admin_scalar($pdo, 'SELECT COUNT(*) FROM card_bonuses');
    $setBonuses = admin_scalar($pdo, 'SELECT COUNT(*) FROM card_set_bonuses');
    $noTypes    = admin_scalar($pdo, 'SELECT COUNT(*) FROM cards c WHERE NOT EXISTS (SELECT 1 FROM card_allowed_item_types a WHERE a.card_id = c.id)');
    $noMain     = admin_scalar($pdo, 'SELECT COUNT(*) FROM card_sets s WHERE NOT EXISTS (SELECT 1 FROM cards c WHERE c.set_id = s.id AND c.is_set_main = 1)');

    $typeStats = admin_rows_safe($pdo, '
        SELECT t.name, t.socket_number, COUNT(c.id) AS card_count
        FROM card_types t
        LEFT JOIN cards c ON c.card_type_id = t.id
        GROUP BY t.id, t.name, t.socket_number
        ORDER BY t.sort_order, t.id
    ');
    $setStats = admin_rows_safe($pdo, '
        SELECT s.name, s.negative_threshold, s.negative_below_mode, s.negative_reached_mode,
               COUNT(c.id) AS card_count,
               SUM(CASE WHEN c.is_set_main = 1 THEN 1 ELSE 0 END) AS main_count,
               (SELECT COUNT(*) FROM card_set_bonuses b WHERE b.set_id = s.id) AS bonus_count
        FROM card_sets s
        LEFT JOIN cards c ON c.set_id = s.id
        GROUP BY s.id, s.name, s.negative_threshold, s.negative_below_mode, s.negative_reached_mode
        ORDER BY s.sort_order, s.id
    ');
    $modeLabels = enum_rows('negative_mode');

    admin_layout_start('Карты · статистика', $action, $menu);
    ?>
<div class="page-head">
<h1>Обзор карт</h1>
<p class="muted">Наполнение каталога карт, распределение по типам и состояние сетов.</p>
</div>
<div class="admin-stat-grid">
<?php
    admin_stat_tile('Всего карт', $totalCards);
    admin_stat_tile('Типов карт', $totalTypes);
    admin_stat_tile('Сетов карт', $totalSets);
    admin_stat_tile('Главных карт сета', $mainCards);
    admin_stat_tile('Характеристик карт', $bonuses);
    admin_stat_tile('Сетовых бонусов', $setBonuses);
?>
</div>

<h2 class="admin-section-title">Требует внимания</h2>
<div class="admin-stat-grid">
<?php
    admin_stat_tile('Карты без типов предметов', $noTypes, $noTypes > 0 ? 'warn' : 'ok');
    admin_stat_tile('Сеты без главной карты', $noMain, $noMain > 0 ? 'warn' : 'ok');
    admin_stat_tile('Вставлено в предметы', $usedCards);
?>
</div>

<h2 class="admin-section-title">Карты по типам</h2>
<div class="card">
<table>
<thead><tr><th>Тип карты</th><th>Слот</th><th>Карт</th></tr></thead>
<tbody>
<?php if (!$typeStats): ?><tr><td colspan="3">Типы карт не заданы.</td></tr><?php endif; ?>
<?php foreach ($typeStats as $row): ?>
<tr>
<td><b><?= e($row['name']) ?></b></td>
<td><?= $row['socket_number'] === null ? '<span class="badge">любой</span>' : '<span class="badge badge-ok">№ ' . (int)$row['socket_number'] . '</span>' ?></td>
<td><?= (int)$row['card_count'] ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<h2 class="admin-section-title">Сеты карт</h2>
<div class="card">
<table>
<thead><tr><th>Сет</th><th>Порог</th><th>До порога</th><th>После порога</th><th>Карт</th><th>Главных</th><th>Бонусов</th></tr></thead>
<tbody>
<?php if (!$setStats): ?><tr><td colspan="7">Сеты карт не заданы.</td></tr><?php endif; ?>
<?php foreach ($setStats as $row): $mainCount = (int)$row['main_count']; ?>
<tr>
<td><b><?= e($row['name']) ?></b></td>
<td><?= (int)$row['negative_threshold'] ?></td>
<td><?= e($modeLabels[$row['negative_below_mode']] ?? $row['negative_below_mode']) ?></td>
<td><?= e($modeLabels[$row['negative_reached_mode']] ?? $row['negative_reached_mode']) ?></td>
<td><?= (int)$row['card_count'] ?></td>
<td><?= $mainCount > 0 ? '<span class="badge badge-ok">' . $mainCount . '</span>' : '<span class="badge badge-danger">нет</span>' ?></td>
<td><?= (int)$row['bonus_count'] ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php
    admin_layout_end();
}

/* ======================= 404 ======================= */

function page_not_found(string $action, array $menu): void
{
    admin_layout_start('Раздел не найден', $action, $menu);
    ?>
<div class="card">
<div class="card-header"><h1>Раздел не найден</h1></div>
<p class="muted">
Запрошенный раздел <code><?= e($action) ?></code> не существует или ещё не подключён к роутеру.
Все доступные разделы находятся в меню слева. Нажмите <kbd>/</kbd>, чтобы найти нужный по названию.
</p>
</div>
<?php
    admin_layout_end();
}

/* ======================= Маршрутизация ======================= */

switch ($action) {
    case 'dashboard':
        page_dashboard($action, $menu);
        exit;
    case 'items_section':
        page_items_section($action, $menu);
        exit;
    case 'cards_section':
        page_cards_section($action, $menu);
        exit;
}

$modules = [
    'items.php' => ['items', 'item_form', 'item_bonus_conditions', 'item_cards'],
    'cards.php' => ['cards', 'card_form', 'card_bonuses', 'card_set_bonuses'],
    'stamps.php' => ['stamps', 'stamp_form', 'stamp_bonuses', 'item_stamp'],
];
foreach ($modules as $file => $actions) {
    if (in_array($action, $actions, true) && is_file(__DIR__ . '/' . $file)) {
        require __DIR__ . '/' . $file;
        exit;
    }
}

http_response_code(404);
page_not_found($action, $menu);