<?php
declare(strict_types=1);
if (!defined('ADMIN_ENTRY')) { http_response_code(403); exit; }

/** @var string $action */
/** @var array $menu */

switch ($action) {
    case 'cards':            cards_list_page($action, $menu); break;
    case 'card_form':        card_form_page($action, $menu); break;
    case 'card_bonuses':     card_bonuses_page($action, $menu); break;
    case 'card_set_bonuses': card_set_bonuses_page($action, $menu); break;
    default:
        flash('error', 'Неизвестный раздел.');
        redirect(admin_url('cards'));
}

/* ======================= ОБЩИЕ ХЕЛПЕРЫ ======================= */

function cards_stat_map(array $stats): array
{
    $out = [];
    foreach ($stats as $s) $out[(int)$s['id']] = $s;
    return $out;
}

function cards_id_list(PDO $pdo, string $table): array
{
    return array_map('intval', $pdo->query("SELECT id FROM `$table`")->fetchAll(PDO::FETCH_COLUMN));
}

function assert_stat_value_type(array $stat, string $valueType): void
{
    if ($valueType === 'flat' && !(int)$stat['supports_flat']) {
        throw new RuntimeException('Характеристика «' . $stat['name_ru'] . '» не поддерживает плоское значение.');
    }
    if ($valueType === 'percent' && !(int)$stat['supports_percent']) {
        throw new RuntimeException('Характеристика «' . $stat['name_ru'] . '» не поддерживает процентное значение.');
    }
}

function posted_override_rows(): array
{
    $types = (array)($_POST['override_item_type_id'] ?? []);
    $vals  = (array)($_POST['override_value'] ?? []);
    $maxes = (array)($_POST['override_value_max'] ?? []);
    $rows = [];
    for ($i = 0, $n = max(count($types), count($vals), count($maxes)); $i < $n; $i++) {
        $rows[] = [
            'item_type_id' => (int)($types[$i] ?? 0),
            'value'        => (string)($vals[$i] ?? ''),
            'value_max'    => (string)($maxes[$i] ?? ''),
        ];
    }
    return $rows;
}

function posted_stat_source_rows(): array
{
    $ids   = (array)($_POST['stat_source_stat'] ?? []);
    $steps = (array)($_POST['stat_source_step'] ?? []);
    $rows = [];
    for ($i = 0, $n = max(count($ids), count($steps)); $i < $n; $i++) {
        $sid  = (int)($ids[$i] ?? 0);
        $step = (int)($steps[$i] ?? 0);
        if ($sid <= 0 && $step <= 0) continue;
        $rows[] = ['stat_id' => $sid, 'step' => $step];
    }
    return $rows;
}

function read_stat_sources(array $statsById): array
{
    $rows = [];
    $seen = [];
    foreach (posted_stat_source_rows() as $r) {
        $sid  = (int)$r['stat_id'];
        $step = (int)$r['step'];
        if ($sid <= 0) throw new RuntimeException('Выберите характеристику-источник.');
        if ($step < 1) throw new RuntimeException('Шаг характеристики-источника должен быть больше нуля.');
        if (isset($seen[$sid])) throw new RuntimeException('Одна и та же характеристика-источник не может быть указана дважды.');
        if (!isset($statsById[$sid])) throw new RuntimeException('Характеристика-источник не найдена.');
        $seen[$sid] = true;
        $rows[] = ['stat_id' => $sid, 'step' => $step];
    }
    return $rows;
}

function card_stat_source_row(array $row, array $stats): void
{
    ?>
<div class="dynamic-row dynamic-row-3">
<select name="stat_source_stat[]"><?= stat_options($stats, (int)($row['stat_id'] ?? 0)) ?></select>
<input type="number" name="stat_source_step[]" min="1" value="<?= (int)($row['step'] ?? 1) ?>" placeholder="Шаг">
<button type="button" class="btn btn-danger js-remove-stat-source"><?= admin_icon('trash', 15) ?>Удалить</button>
</div>
<?php
}

function card_override_row(array $row, array $allowedItemTypes): void
{
    $tid = (int)($row['item_type_id'] ?? 0);
    ?>
<div class="dynamic-row">
<select name="override_item_type_id[]">
<option value="0">— тип предмета —</option>
<?php foreach ($allowedItemTypes as $t): ?>
<option value="<?= (int)$t['id'] ?>"<?= selected($tid === (int)$t['id']) ?>><?= e($t['name']) ?></option>
<?php endforeach; ?>
</select>
<input type="number" step="0.01" name="override_value[]" value="<?= e($row['value'] ?? '') ?>" placeholder="Значение">
<input type="number" step="0.01" name="override_value_max[]" value="<?= e($row['value_max'] ?? '') ?>" placeholder="Максимум для диапазона">
<button type="button" class="btn btn-danger js-remove-override"><?= admin_icon('trash', 15) ?>Удалить</button>
</div>
<?php
}

/** Удаляет связи, ставшие несовместимыми после изменения карты. */
function card_cleanup_incompatible(PDO $pdo, int $cardId, array $typeIds, ?int $socketNumber, array $grades): int
{
    $removed = 0;
    $ph = implode(',', array_fill(0, count($typeIds), '?'));

    foreach (['card_bonus_item_type_restrictions', 'card_bonus_item_type_overrides'] as $table) {
        $st = $pdo->prepare("DELETE FROM `$table`
            WHERE card_bonus_id IN (SELECT id FROM card_bonuses WHERE card_id = ?)
            AND item_type_id NOT IN ($ph)");
        $st->execute(array_merge([$cardId], $typeIds));
        $removed += $st->rowCount();
    }

    $st = $pdo->prepare("DELETE ic FROM item_cards ic JOIN items i ON i.id = ic.item_id
        WHERE ic.card_id = ? AND i.item_type_id NOT IN ($ph)");
    $st->execute(array_merge([$cardId], $typeIds));
    $removed += $st->rowCount();

    if ($socketNumber !== null) {
        $st = $pdo->prepare('DELETE FROM item_cards WHERE card_id = ? AND socket_number <> ?');
        $st->execute([$cardId, $socketNumber]);
        $removed += $st->rowCount();
    }

    if ($grades) {
        $gph = implode(',', array_fill(0, count($grades), '?'));
        $st = $pdo->prepare("DELETE ic FROM item_cards ic JOIN items i ON i.id = ic.item_id
            WHERE ic.card_id = ? AND i.grade NOT IN ($gph)");
        $st->execute(array_merge([$cardId], $grades));
        $removed += $st->rowCount();
    }

    return $removed;
}

/* ======================= СПИСОК КАРТ ======================= */

function cards_page_counts(PDO $pdo, array $ids): array
{
    $out = [];
    if (!$ids) return $out;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $map = [
        'types'   => "SELECT card_id, COUNT(*) c FROM card_allowed_item_types WHERE card_id IN ($ph) GROUP BY card_id",
        'bonuses' => "SELECT card_id, COUNT(*) c FROM card_bonuses WHERE card_id IN ($ph) GROUP BY card_id",
        'used'    => "SELECT card_id, COUNT(*) c FROM item_cards WHERE card_id IN ($ph) GROUP BY card_id",
    ];
    foreach ($map as $key => $sql) {
        $st = $pdo->prepare($sql);
        $st->execute($ids);
        foreach ($st->fetchAll() as $r) $out[(int)$r['card_id']][$key] = (int)$r['c'];
    }
    return $out;
}

function cards_list_page(string $action, array $menu): void
{
    $pdo = db();

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        verify_csrf();
        try {
            if ((string)($_POST['form_action'] ?? '') !== 'delete_card') {
                throw new RuntimeException('Неизвестное действие.');
            }
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Некорректный ID карты.');
            foreach ([
                ['SELECT COUNT(*) FROM item_cards WHERE card_id = ?', 'Нельзя удалить карту, которая вставлена в предметы.'],
                ['SELECT COUNT(*) FROM card_bonuses WHERE depends_on_card_id = ?', 'Нельзя удалить карту, от которой зависят характеристики других карт.'],
            ] as [$sql, $message]) {
                $st = $pdo->prepare($sql);
                $st->execute([$id]);
                if ((int)$st->fetchColumn() > 0) throw new RuntimeException($message);
            }
            $st = $pdo->prepare('DELETE FROM cards WHERE id = ?');
            $st->execute([$id]);
            if ($st->rowCount() === 0) throw new RuntimeException('Карта не найдена.');
            flash('success', 'Карта удалена вместе со своими характеристиками и совместимостью.');
        } catch (Throwable $ex) {
            flash('error', friendly_error($ex));
        }
        redirect(admin_url('cards'));
    }

    $q        = trim((string)($_GET['q'] ?? ''));
    $typeId   = (int)($_GET['type'] ?? 0);
    $setId    = (int)($_GET['set'] ?? 0);
    $onlySet  = isset($_GET['only_set']) ? 1 : 0;
    $onlyMain = isset($_GET['only_main']) ? 1 : 0;
    $perPage  = 50;
    $page     = page_number();

    $from = ' FROM cards c JOIN card_types ct ON ct.id = c.card_type_id LEFT JOIN card_sets cs ON cs.id = c.set_id';
    $where = ' WHERE 1 = 1';
    $params = [];
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where .= ' AND (c.name LIKE ? OR EXISTS (
            SELECT 1 FROM card_bonuses b JOIN stats s ON s.id = b.stat_id
            WHERE b.card_id = c.id AND (s.name_ru LIKE ? OR s.alias LIKE ?)))';
        array_push($params, $like, $like, $like);
    }
    if ($typeId > 0) { $where .= ' AND c.card_type_id = ?'; $params[] = $typeId; }
    if ($setId > 0)  { $where .= ' AND c.set_id = ?'; $params[] = $setId; }
    if ($onlySet)    { $where .= ' AND c.set_id IS NOT NULL'; }
    if ($onlyMain)   { $where .= ' AND c.is_set_main = 1'; }

    $st = $pdo->prepare('SELECT COUNT(*)' . $from . $where);
    $st->execute($params);
    $total = (int)$st->fetchColumn();

    $pages = max(1, (int)ceil($total / $perPage));
    if ($page > $pages) $page = $pages;
    $offset = ($page - 1) * $perPage;

    $st = $pdo->prepare('SELECT c.id, c.name, c.card_type_id, ct.name AS card_type_name,
        ct.socket_number AS card_type_socket_number, c.set_id, cs.name AS set_name, c.is_set_main, c.sort_order'
        . $from . $where . ' ORDER BY c.sort_order, c.id LIMIT ' . $perPage . ' OFFSET ' . $offset);
    $st->execute($params);
    $cards = $st->fetchAll();

    $counts = cards_page_counts($pdo, array_map(static fn($r) => (int)$r['id'], $cards));
    $cardTypes = $pdo->query('SELECT id, name FROM card_types ORDER BY sort_order, id')->fetchAll();
    $cardSets = $pdo->query('SELECT id, name FROM card_sets ORDER BY sort_order, id')->fetchAll();
    $filterParams = array_filter([
        'q' => $q, 'type' => $typeId ?: null, 'set' => $setId ?: null,
        'only_set' => $onlySet ?: null, 'only_main' => $onlyMain ?: null,
    ], static fn($v) => $v !== null && $v !== '');
    $hasFilters = (bool)$filterParams;

    admin_layout_start('Карты', $action, $menu);
    ?>
<div class="card">
<div class="card-header">
<h1>Карты</h1>
<div class="actions">
<span class="badge">Найдено: <?= $total ?></span>
<a class="btn" href="<?= e(admin_url('cards_section')) ?>"><?= admin_icon('layers', 16) ?>Раздел</a>
<a class="btn btn-primary" href="<?= e(admin_url('card_form', ['id' => 0])) ?>"><?= admin_icon('plus', 16) ?>Создать карту</a>
</div>
</div>
<p class="muted">
Поиск работает по названию карты и её характеристикам, включая alias.
Карта без типов предметов не может быть вставлена ни в один предмет — такие строки помечены красным.
</p>
<form method="get" action="<?= e(admin_url('cards')) ?>" class="filters">
<input type="hidden" name="action" value="cards">
<div class="filter-field">
<label for="q">Поиск</label>
<input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Название карты или характеристика">
</div>
<div class="filter-field">
<label for="type">Тип карты</label>
<select id="type" name="type">
<option value="0">Все типы</option>
<?php foreach ($cardTypes as $t): ?>
<option value="<?= (int)$t['id'] ?>"<?= selected($typeId === (int)$t['id']) ?>><?= e($t['name']) ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="filter-field">
<label for="set">Сет</label>
<select id="set" name="set">
<option value="0">Все сеты</option>
<?php foreach ($cardSets as $s): ?>
<option value="<?= (int)$s['id'] ?>"<?= selected($setId === (int)$s['id']) ?>><?= e($s['name']) ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="filter-field">
<label>Дополнительно</label>
<div class="checkbox-line"><label><input type="checkbox" name="only_set" value="1"<?= checked($onlySet === 1) ?>> Только сетовые</label></div>
<div class="checkbox-line"><label><input type="checkbox" name="only_main" value="1"<?= checked($onlyMain === 1) ?>> Только главные карты сета</label></div>
</div>
<div class="filter-actions">
<button type="submit" class="btn btn-primary"><?= admin_icon('search', 16) ?>Применить</button>
<?php if ($hasFilters): ?><a href="<?= e(admin_url('cards')) ?>" class="btn">Сбросить</a><?php endif; ?>
</div>
</form>
</div>

<div class="card">
<div class="card-header">
<h2>Список</h2>
<div class="actions"><span class="badge">Страница <?= $page ?> из <?= $pages ?></span></div>
</div>
<table>
<thead><tr>
<th>ID</th><th>Название</th><th>Тип</th><th>Слот</th><th>Сет</th><th>Главная</th>
<th>Типы предметов / Характеристики / В предметах</th><th>Порядок</th><th>Действия</th>
</tr></thead>
<tbody>
<?php if (!$cards): ?>
<tr><td colspan="9"><?= $hasFilters ? 'По фильтрам ничего не найдено. Сбросьте фильтры и попробуйте снова.' : 'Карт нет. Создайте первую карту.' ?></td></tr>
<?php endif; ?>
<?php foreach ($cards as $c):
    $cid = (int)$c['id'];
    $n = $counts[$cid] ?? [];
    $typesCount = (int)($n['types'] ?? 0);
    $bonusesCount = (int)($n['bonuses'] ?? 0);
    $usedCount = (int)($n['used'] ?? 0);
?>
<tr>
<td><?= $cid ?></td>
<td><b><?= e($c['name']) ?></b></td>
<td><?= e($c['card_type_name']) ?></td>
<td><?= $c['card_type_socket_number'] === null
    ? '<span class="badge">Любой</span>'
    : '<span class="badge badge-ok">№ ' . (int)$c['card_type_socket_number'] . '</span>' ?></td>
<td><?= e($c['set_name'] ?? '—') ?></td>
<td><?= (int)$c['is_set_main'] ? '<span class="badge badge-ok">да</span>' : '<span class="badge">нет</span>' ?></td>
<td>
<span class="badge <?= $typesCount > 0 ? '' : 'badge-danger' ?>" title="Типы предметов, куда можно вставить карту">Т <?= $typesCount ?></span>
<span class="badge" title="Характеристики карты">Х <?= $bonusesCount ?></span>
<span class="badge <?= $usedCount > 0 ? 'badge-ok' : '' ?>" title="Сколько раз вставлена в предметы">П <?= $usedCount ?></span>
</td>
<td><?= (int)$c['sort_order'] ?></td>
<td class="actions">
<a class="btn" href="<?= e(admin_url('card_form', ['id' => $cid])) ?>">Редактировать</a>
<a class="btn" href="<?= e(admin_url('card_bonuses', ['card_id' => $cid])) ?>">Характеристики</a>
<form method="post" class="inline js-confirm" data-confirm="Удалить карту «<?= e($c['name']) ?>»? Будут удалены её характеристики, ограничения и совместимость.">
<input type="hidden" name="form_action" value="delete_card">
<input type="hidden" name="id" value="<?= $cid ?>">
<?= csrf_field() ?>
<button type="submit" class="btn btn-danger"><?= admin_icon('trash', 15) ?>Удалить</button>
</form>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php render_pagination($total, $perPage, $page, $action, $filterParams); ?>
</div>
<?php
    admin_layout_end();
}

/* ======================= ФОРМА КАРТЫ ======================= */

function card_form_page(string $action, array $menu): void
{
    $pdo = db();
    $values = null;
    $allowedItemTypeIds = [];

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        verify_csrf();
        try {
            if ((string)($_POST['form_action'] ?? '') !== 'save_card') {
                throw new RuntimeException('Неизвестное действие.');
            }
            $cardId     = (int)($_POST['id'] ?? 0);
            $name       = trim((string)($_POST['name'] ?? ''));
            $iconPath   = trim((string)($_POST['icon_path'] ?? ''));
            $cardTypeId = (int)($_POST['card_type_id'] ?? 0);
            $setId      = intOrNull($_POST['set_id'] ?? null);
            $isSetMain  = isset($_POST['is_set_main']) ? 1 : 0;
            $stateId    = intOrNull($_POST['state_id'] ?? null);
            $sortOrder  = (int)($_POST['sort_order'] ?? 0);

            $grades = [];
            foreach ((array)($_POST['allowed_grades'] ?? []) as $g) {
                $g = strtoupper(trim((string)$g));
                if (in_array($g, grade_codes(), true)) $grades[] = $g;
            }
            $grades = array_values(array_unique($grades));

            if ($name === '') throw new RuntimeException('Укажите название карты.');
            if (mb_strlen($name) > 150) throw new RuntimeException('Название карты не должно превышать 150 символов.');
            if ($iconPath === '') throw new RuntimeException('Укажите путь к иконке карты.');
            if (mb_strlen($iconPath) > 255) throw new RuntimeException('Путь к иконке не должен превышать 255 символов.');
            if ($cardTypeId <= 0) throw new RuntimeException('Выберите тип карты.');

            $st = $pdo->prepare('SELECT socket_number FROM card_types WHERE id = ?');
            $st->execute([$cardTypeId]);
            $cardType = $st->fetch();
            if (!$cardType) throw new RuntimeException('Тип карты не найден.');
            $socketNumber = $cardType['socket_number'] === null ? null : (int)$cardType['socket_number'];

            if ($setId !== null) {
                assert_row_exists($pdo, 'card_sets', $setId, 'Сет не найден.');
            } else {
                $isSetMain = 0;
            }
            if ($stateId !== null) assert_row_exists($pdo, 'states', $stateId, 'Состояние не найдено.');

            $allowedItemTypeIds = array_values(array_unique(array_map('intval', (array)($_POST['allowed_item_type_ids'] ?? []))));
            $allowedItemTypeIds = array_values(array_intersect($allowedItemTypeIds, cards_id_list($pdo, 'item_types')));
            if (!$allowedItemTypeIds) {
                throw new RuntimeException('Выберите хотя бы один тип предмета, в который можно вставлять карту.');
            }
            if ($cardId > 0) assert_row_exists($pdo, 'cards', $cardId, 'Карта не найдена.');

            $pdo->beginTransaction();
            try {
                $fields = [$cardTypeId, $setId, $name, $iconPath, $isSetMain, $stateId, $sortOrder];
                if ($cardId > 0) {
                    $pdo->prepare('UPDATE cards SET card_type_id=?, set_id=?, name=?, icon_path=?,
                        is_set_main=?, state_id=?, sort_order=? WHERE id=?')
                        ->execute([...$fields, $cardId]);
                } else {
                    $pdo->prepare('INSERT INTO cards (card_type_id, set_id, name, icon_path,
                        is_set_main, state_id, sort_order) VALUES (?,?,?,?,?,?,?)')
                        ->execute($fields);
                    $cardId = (int)$pdo->lastInsertId();
                }

                $pdo->prepare('DELETE FROM card_allowed_grades WHERE card_id = ?')->execute([$cardId]);
                $gradeStmt = $pdo->prepare('INSERT INTO card_allowed_grades (card_id, grade) VALUES (?, ?)');
                foreach ($grades as $g) $gradeStmt->execute([$cardId, $g]);

                $pdo->prepare('DELETE FROM card_allowed_item_types WHERE card_id = ?')->execute([$cardId]);
                $st = $pdo->prepare('INSERT INTO card_allowed_item_types (card_id, item_type_id) VALUES (?, ?)');
                foreach ($allowedItemTypeIds as $tid) $st->execute([$cardId, $tid]);

                $removed = card_cleanup_incompatible($pdo, $cardId, $allowedItemTypeIds, $socketNumber, $grades);
                $pdo->commit();

                $message = 'Карта сохранена.';
                if ($removed > 0) {
                    $message .= ' Удалено несовместимых связей: ' . $removed . ' (ограничения, переопределения, вставленные карты).';
                }
                flash('success', $message);
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $ex;
            }
            redirect(admin_url('card_form', ['id' => $cardId]));
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', friendly_error($ex));
            $values = $_POST;
            $allowedItemTypeIds = array_map('intval', (array)($_POST['allowed_item_type_ids'] ?? []));
        }
    }

    $id = (int)($_GET['id'] ?? 0);
    $edit = null;
    if ($id > 0) {
        $st = $pdo->prepare('SELECT * FROM cards WHERE id = ?');
        $st->execute([$id]);
        $edit = $st->fetch();
        if (!$edit) { flash('error', 'Карта не найдена.'); redirect(admin_url('cards')); }
    }

    $cardTypes = $pdo->query('SELECT * FROM card_types ORDER BY sort_order, id')->fetchAll();
    $cardSets = $pdo->query('SELECT id, name FROM card_sets ORDER BY sort_order, id')->fetchAll();
    $itemTypes = fetch_item_types($pdo);
    $states = fetch_states($pdo);

    if ($values === null) {
        $values = $edit ?: [];
        if ($id > 0) {
            $st = $pdo->prepare('SELECT item_type_id FROM card_allowed_item_types WHERE card_id = ?');
            $st->execute([$id]);
            $allowedItemTypeIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            $st = $pdo->prepare('SELECT grade FROM card_allowed_grades WHERE card_id = ? ORDER BY grade');
            $st->execute([$id]);
            $values['allowed_grades'] = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
        }
    }

    $values = array_merge([
        'id' => 0, 'card_type_id' => 0, 'set_id' => '', 'name' => '', 'icon_path' => '',
        'is_set_main' => 0, 'state_id' => '', 'allowed_grades' => [], 'sort_order' => 0,
    ], $values);
    $currentId = (int)($values['id'] ?? 0);
    $allowedItemTypeIds = array_values(array_unique(array_map('intval', $allowedItemTypeIds)));

    $rawGrades = $values['allowed_grades'] ?? '';
    $parts = is_array($rawGrades) ? $rawGrades : explode(',', (string)$rawGrades);
    $selectedGrades = array_values(array_filter(array_unique(array_map(
        static fn($g) => strtoupper(trim((string)$g)), $parts
    )), static fn($g) => in_array($g, grade_codes(), true)));

    admin_layout_start($currentId > 0 ? 'Редактирование карты' : 'Новая карта', $action, $menu);
    ?>
<form method="post" action="<?= e(admin_url('card_form')) ?>">
<input type="hidden" name="form_action" value="save_card">
<input type="hidden" name="id" value="<?= $currentId ?>">
<?= csrf_field() ?>

<div class="card">
<div class="card-header">
<h1><?= $currentId > 0 ? 'Редактирование карты' : 'Новая карта' ?></h1>
<div class="actions">
<?php if ($currentId > 0): ?><span class="badge badge-warn">ID: <?= $currentId ?></span><?php endif; ?>
<a class="btn" href="<?= e(admin_url('cards')) ?>"><?= admin_icon('layers', 16) ?>К картам</a>
<?php if ($currentId > 0): ?>
<a class="btn btn-primary" href="<?= e(admin_url('card_bonuses', ['card_id' => $currentId])) ?>"><?= admin_icon('chart', 16) ?>Характеристики карты</a>
<?php endif; ?>
</div>
</div>
<p class="muted">
Карта вставляется в слот предмета. Тип карты задаёт номер слота, а типы предметов и грейды — куда именно её можно вставить.
При сужении списков несовместимые связи и уже вставленные карты удаляются автоматически.
</p>
<div class="grid">
<div class="field"><label for="name">Название</label>
<input type="text" id="name" name="name" required maxlength="150" value="<?= e($values['name']) ?>"></div>
<div class="field"><label for="icon_path">Путь к иконке</label>
<input type="text" id="icon_path" name="icon_path" required maxlength="255" value="<?= e($values['icon_path']) ?>" placeholder="assets/icons/cards/card.png"></div>
<div class="field"><label for="card_type_id">Тип карты</label>
<select id="card_type_id" name="card_type_id" required><option value="0">— выбрать —</option>
<?php foreach ($cardTypes as $t): ?>
<option value="<?= (int)$t['id'] ?>"<?= selected((int)$values['card_type_id'] === (int)$t['id']) ?>><?= e($t['name']) ?><?= $t['socket_number'] !== null ? ' (слот ' . (int)$t['socket_number'] . ')' : '' ?></option>
<?php endforeach; ?></select>
<div class="form-help">При смене слота карты из других слотов предметов будут удалены.</div></div>
<div class="field"><label for="set_id">Сет</label>
<select id="set_id" name="set_id"><option value="">— нет —</option>
<?php foreach ($cardSets as $s): ?>
<option value="<?= (int)$s['id'] ?>"<?= selected((string)$values['set_id'] === (string)$s['id']) ?>><?= e($s['name']) ?></option>
<?php endforeach; ?></select></div>
<div class="field field-checkbox"><label>
<input type="checkbox" id="is_set_main" name="is_set_main"<?= checked((int)$values['is_set_main'] === 1) ?>> Главная карта сета</label>
<div class="form-help">Без главной карты сета сетовые бонусы не работают.</div></div>
<div class="field"><label for="state_id">Состояние</label>
<select id="state_id" name="state_id"<?= $states ? '' : ' disabled' ?>><?= state_options($states, intOrNull($values['state_id'])) ?></select>
<div class="form-help">Если состояние выбрано, карта участвует в расчётах только когда оно активно.</div></div>
<div class="field"><label for="sort_order">Порядок</label>
<input type="number" id="sort_order" name="sort_order" value="<?= e($values['sort_order']) ?>"></div>
</div>
</div>

<div class="card">
<div class="card-header">
<h2>Грейды предметов</h2>
<div class="actions">
<span class="badge <?= $selectedGrades ? '' : 'badge-ok' ?>"><?= $selectedGrades ? 'Выбрано: ' . count($selectedGrades) : 'Любой грейд' ?></span>
</div>
</div>
<p class="muted">
Если ничего не выбрано, карту можно вставлять в предметы любого грейда.
При сужении списка лишние вставленные карты удаляются.
</p>
<div class="checkbox-grid">
<?php foreach (grade_rows() as $gCode => $gName): ?>
<label><input type="checkbox" name="allowed_grades[]" value="<?= e($gCode) ?>"<?= checked(in_array($gCode, $selectedGrades, true)) ?>><span><?= e($gName) ?></span></label>
<?php endforeach; ?>
</div>
</div>

<div class="card">
<div class="card-header">
<h2>Типы предметов</h2>
<div class="actions">
<span class="badge <?= $allowedItemTypeIds ? '' : 'badge-danger' ?>">Выбрано: <?= count($allowedItemTypeIds) ?></span>
<button type="button" class="btn" data-check-all="#card-item-types">Выбрать все</button>
<button type="button" class="btn" data-uncheck-all="#card-item-types">Снять все</button>
</div>
</div>
<p class="muted">
Выберите типы предметов, в которые можно вставлять эту карту.
При снятии типа связанные ограничения, переопределения и вставленные карты удаляются.
</p>
<div id="card-item-types" class="checkbox-grid">
<?php foreach ($itemTypes as $t): ?>
<label><input type="checkbox" name="allowed_item_type_ids[]" value="<?= (int)$t['id'] ?>"<?= checked(in_array((int)$t['id'], $allowedItemTypeIds, true)) ?>><span><?= e($t['name']) ?></span></label>
<?php endforeach; ?>
</div>
<div class="form-actions">
<button type="submit" class="btn btn-primary"><?= admin_icon('check', 16) ?>Сохранить карту</button>
<a href="<?= e(admin_url('cards')) ?>" class="btn">Отмена</a>
<span class="form-help">Сочетание <kbd>Ctrl</kbd> + <kbd>Enter</kbd> сохраняет форму.</span>
</div>
</div>
</form>
<?php
    admin_layout_end();
}

/* ======================= ХАРАКТЕРИСТИКИ КАРТЫ ======================= */

function card_bonuses_page(string $action, array $menu): void
{
    $pdo = db();
    $cardId = (int)($_GET['card_id'] ?? $_POST['card_id'] ?? 0);
    if ($cardId <= 0) { flash('error', 'Некорректный ID карты.'); redirect(admin_url('cards')); }
    $st = $pdo->prepare('SELECT c.*, ct.name AS card_type_name, ct.socket_number AS card_type_socket_number,
        cs.name AS set_name
        FROM cards c
        JOIN card_types ct ON ct.id = c.card_type_id
        LEFT JOIN card_sets cs ON cs.id = c.set_id
        WHERE c.id = ?');
    $st->execute([$cardId]);
    $card = $st->fetch();
    if (!$card) { flash('error', 'Карта не найдена.'); redirect(admin_url('cards')); }

    $scalingTypes = scaling_type_labels(true);
    $stats = fetch_stats($pdo);
    $statsById = cards_stat_map($stats);
    $features = fetch_features($pdo);
    $notes = fetch_text_notes($pdo);
    $states = fetch_states($pdo);
    $itemTypes = fetch_item_types($pdo);
    $classes = fetch_classes($pdo);

    $featureIds = array_map(static fn($f) => (int)$f['id'], $features);
    $noteIds = array_map(static fn($n) => (int)$n['id'], $notes);
    $stateIds = array_map(static fn($s) => (int)$s['id'], $states);

    $st = $pdo->prepare('SELECT item_type_id FROM card_allowed_item_types WHERE card_id = ? ORDER BY item_type_id');
    $st->execute([$cardId]);
    $allowedItemTypeIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $allowedItemTypes = array_values(array_filter(
        $itemTypes, static fn($t) => in_array((int)$t['id'], $allowedItemTypeIds, true)
    ));

    $values = null;
    $classIds = [];
    $restrictionIds = [];
    $conditionRows = [];
    $overrideRows = [];
    $statSourceRows = [];

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        verify_csrf();
        $formAction = (string)($_POST['form_action'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        try {
            if ($formAction === 'delete_card_bonus') {
                if ($id <= 0) throw new RuntimeException('Некорректный ID характеристики.');
                $st = $pdo->prepare('DELETE FROM card_bonuses WHERE id = ? AND card_id = ?');
                $st->execute([$id, $cardId]);
                if ($st->rowCount() === 0) throw new RuntimeException('Характеристика не найдена у этой карты.');
                flash('success', 'Характеристика карты удалена.');
                redirect(admin_url('card_bonuses', ['card_id' => $cardId]));
            }
            if ($formAction !== 'save_card_bonus') throw new RuntimeException('Неизвестное действие.');

            $statId = (int)($_POST['stat_id'] ?? 0);
            if ($statId <= 0) throw new RuntimeException('Выберите характеристику.');
            $stat = $statsById[$statId] ?? null;
            if (!$stat) throw new RuntimeException('Характеристика не найдена.');

            $valueType = (string)($_POST['value_type'] ?? 'flat');
            if (!in_array($valueType, enum_codes('value_type', 'card'), true)) throw new RuntimeException('Некорректный тип значения.');
            assert_stat_value_type($stat, $valueType);
            $value = read_decimal($_POST['value'] ?? '', 'Значение');

            $scalingType = (string)($_POST['scaling_type'] ?? 'none');
            if (!array_key_exists($scalingType, $scalingTypes)) throw new RuntimeException('Некорректный тип масштабирования.');
            $scalingStep = max(1, (int)($_POST['scaling_step'] ?? 1));
            $scalingThreshold = (int)($_POST['scaling_threshold'] ?? 0);
            if ($scalingThreshold < 0) throw new RuntimeException('Порог уровня не может быть отрицательным.');
            $mode = read_condition_mode();
            $sortOrder = (int)($_POST['sort_order'] ?? 0);

            $featureId = intOrNull($_POST['feature_id'] ?? null);
            if ($featureId !== null && !in_array($featureId, $featureIds, true)) throw new RuntimeException('Функция не найдена.');
            $textNoteId = intOrNull($_POST['text_note_id'] ?? null);
            if ($textNoteId !== null && !in_array($textNoteId, $noteIds, true)) throw new RuntimeException('Текстовая заметка не найдена.');
            $bonusStateId = intOrNull($_POST['state_id'] ?? null);
            if ($bonusStateId !== null && !in_array($bonusStateId, $stateIds, true)) throw new RuntimeException('Состояние не найдено.');

            $dependsOnCardId = intOrNull($_POST['depends_on_card_id'] ?? null);
            if ($dependsOnCardId !== null) {
                if ($dependsOnCardId === $cardId) throw new RuntimeException('Карта не может зависеть сама от себя.');
                assert_row_exists($pdo, 'cards', $dependsOnCardId, 'Зависимая карта не найдена.');
            }

            $valueMax = null;
            if ($scalingType === 'range') {
                $valueMax = read_decimal($_POST['value_max'] ?? '', 'Максимальное значение');
                if ((float)$valueMax < (float)$value) {
                    throw new RuntimeException('Максимальное значение диапазона не может быть меньше минимального.');
                }
            }

            $requiredCount = null;
            $multiplier = null;
            if ($scalingType === 'multiply_by_identical_cards') {
                $requiredCount = (int)($_POST['scaling_required_count'] ?? 0);
                if ($requiredCount < 1) throw new RuntimeException('Количество одинаковых карт должно быть больше нуля.');
                $multiplier = (float)read_decimal($_POST['scaling_multiplier'] ?? '', 'Множитель');
                if ($multiplier <= 0) throw new RuntimeException('Множитель должен быть больше нуля.');
            }

            $statSourceId = null;
            $statThreshold = null;
            $statValueTrue = null;
            $statSources = [];
            $statConditionMode = 'all';

            if ($scalingType === 'per_stat') {
                $statSources = read_stat_sources($statsById);
                if (!$statSources) throw new RuntimeException('Добавьте хотя бы одну характеристику-источник.');
                $rawMode = strtolower(trim((string)($_POST['stat_condition_mode'] ?? 'all')));
                $statConditionMode = in_array($rawMode, ['all', 'any'], true) ? $rawMode : 'all';
                foreach ($statSources as $src) {
                    if (stat_dependency_would_create_cycle($pdo, (int)$src['stat_id'], $statId, 'card', $id > 0 ? $id : null)) {
                        throw new RuntimeException('Такая зависимость создаёт цикл характеристик.');
                    }
                }
            } elseif ($scalingType === 'stat_threshold') {
                $statSourceId = intOrNull($_POST['stat_source_id'] ?? null);
                if ($statSourceId === null || !isset($statsById[$statSourceId])) {
                    throw new RuntimeException('Выберите характеристику-источник.');
                }
                $statThreshold = intOrNull($_POST['stat_threshold'] ?? null);
                if ($statThreshold === null || $statThreshold < 0) {
                    throw new RuntimeException('Порог характеристики-источника некорректен.');
                }
                $statValueTrue = read_decimal($_POST['stat_value_true'] ?? '', 'Значение при выполненном пороге');
                if (stat_dependency_would_create_cycle($pdo, $statSourceId, $statId, 'card', $id > 0 ? $id : null)) {
                    throw new RuntimeException('Такая зависимость создаёт цикл характеристик.');
                }
            }

            $classIds = read_class_ids($pdo);
            $restrictionIds = array_values(array_unique(array_map('intval', (array)($_POST['item_type_restriction_ids'] ?? []))));
            if (array_diff($restrictionIds, $allowedItemTypeIds)) {
                throw new RuntimeException('Ограничение по типу предмета возможно только среди типов, в которые можно вставить карту.');
            }
            $conditions = read_item_type_conditions($pdo);

            $validatedOverrides = [];
            $usedOverrideTypes = [];
            foreach (posted_override_rows() as $r) {
                $tid = (int)$r['item_type_id'];
                $raw = trim((string)$r['value']);
                if ($tid <= 0 && $raw === '') continue;
                if ($tid <= 0) throw new RuntimeException('Укажите тип предмета для переопределения.');
                if (!in_array($tid, $allowedItemTypeIds, true)) {
                    throw new RuntimeException('Переопределение возможно только для типов предметов, разрешённых для этой карты.');
                }
                if (isset($usedOverrideTypes[$tid])) {
                    throw new RuntimeException('Для одного типа предмета нельзя добавить несколько переопределений одного бонуса.');
                }
                $usedOverrideTypes[$tid] = true;
                $ovValue = read_decimal($raw, 'Значение переопределения');
                $ovMax = null;
                if ($scalingType === 'range') {
                    $ovMax = read_decimal($r['value_max'], 'Максимальное значение переопределения');
                    if ((float)$ovMax < (float)$ovValue) {
                        throw new RuntimeException('Максимальное значение переопределения не может быть меньше минимального.');
                    }
                }
                $validatedOverrides[] = ['item_type_id' => $tid, 'value' => $ovValue, 'value_max' => $ovMax];
            }

            if ($id > 0) {
                $st = $pdo->prepare('SELECT COUNT(*) FROM card_bonuses WHERE id = ? AND card_id = ?');
                $st->execute([$id, $cardId]);
                if (!(int)$st->fetchColumn()) {
                    throw new RuntimeException('Характеристика не найдена у этой карты.');
                }
            }

            $pdo->beginTransaction();
            try {
                $fields = [$statId, $value, $valueMax, $valueType, $featureId, $textNoteId, $bonusStateId,
                    $dependsOnCardId, $scalingType, $scalingStep, $scalingThreshold, $requiredCount,
                    $multiplier, $mode, $statSourceId, null, $statThreshold, $statValueTrue, $statConditionMode, $sortOrder];

                if ($id > 0) {
                    $pdo->prepare('UPDATE card_bonuses SET stat_id=?, `value`=?, value_max=?, value_type=?,
                        feature_id=?, text_note_id=?, state_id=?, depends_on_card_id=?, scaling_type=?,
                        scaling_step=?, scaling_threshold=?, scaling_required_count=?, scaling_multiplier=?,
                        item_type_condition_mode=?, stat_source_id=?, stat_step=?, stat_threshold=?,
                        stat_value_true=?, stat_condition_mode=?, sort_order=? WHERE id=? AND card_id=?')
                        ->execute([...$fields, $id, $cardId]);
                    $bonusId = $id;
                } else {
                    $pdo->prepare('INSERT INTO card_bonuses (card_id, stat_id, `value`, value_max, value_type,
                        feature_id, text_note_id, state_id, depends_on_card_id, scaling_type, scaling_step,
                        scaling_threshold, scaling_required_count, scaling_multiplier, item_type_condition_mode,
                        stat_source_id, stat_step, stat_threshold, stat_value_true, stat_condition_mode, sort_order)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                        ->execute(array_merge([$cardId], $fields));
                    $bonusId = (int)$pdo->lastInsertId();
                }

                $pdo->prepare('DELETE FROM card_bonus_stat_sources WHERE card_bonus_id = ?')->execute([$bonusId]);
                if ($statSources) {
                    $st = $pdo->prepare('INSERT INTO card_bonus_stat_sources (card_bonus_id, stat_id, step, sort_order) VALUES (?,?,?,?)');
                    $order = 0;
                    foreach ($statSources as $src) $st->execute([$bonusId, $src['stat_id'], $src['step'], $order++]);
                }

                $pdo->prepare('DELETE FROM card_bonus_item_type_overrides WHERE card_bonus_id = ?')->execute([$bonusId]);
                if ($validatedOverrides) {
                    $st = $pdo->prepare('INSERT INTO card_bonus_item_type_overrides (card_bonus_id, item_type_id, `value`, value_max) VALUES (?,?,?,?)');
                    foreach ($validatedOverrides as $o) $st->execute([$bonusId, $o['item_type_id'], $o['value'], $o['value_max']]);
                }

                $pdo->prepare('DELETE FROM card_bonus_class_restrictions WHERE card_bonus_id = ?')->execute([$bonusId]);
                if ($classIds) {
                    $st = $pdo->prepare('INSERT INTO card_bonus_class_restrictions (card_bonus_id, class_id) VALUES (?,?)');
                    foreach ($classIds as $cid) $st->execute([$bonusId, $cid]);
                }

                $pdo->prepare('DELETE FROM card_bonus_item_type_restrictions WHERE card_bonus_id = ?')->execute([$bonusId]);
                if ($restrictionIds) {
                    $st = $pdo->prepare('INSERT INTO card_bonus_item_type_restrictions (card_bonus_id, item_type_id) VALUES (?,?)');
                    foreach ($restrictionIds as $tid) $st->execute([$bonusId, $tid]);
                }

                $pdo->prepare('DELETE FROM card_bonus_item_type_conditions WHERE card_bonus_id = ?')->execute([$bonusId]);
                if ($conditions) {
                    $st = $pdo->prepare('INSERT INTO card_bonus_item_type_conditions (card_bonus_id, item_type_id, required_count) VALUES (?,?,?)');
                    foreach ($conditions as $c) $st->execute([$bonusId, $c['item_type_id'], $c['required_count']]);
                }
                $pdo->commit();
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $ex;
            }
            flash('success', 'Характеристика карты сохранена.');
            redirect(admin_url('card_bonuses', ['card_id' => $cardId]));
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', friendly_error($ex));
            if ($formAction === 'delete_card_bonus') redirect(admin_url('card_bonuses', ['card_id' => $cardId]));
            $values = $_POST;
            $classIds = array_map('intval', (array)($_POST['class_ids'] ?? []));
            $restrictionIds = array_map('intval', (array)($_POST['item_type_restriction_ids'] ?? []));
            $conditionRows = posted_condition_rows();
            $overrideRows = posted_override_rows();
            $statSourceRows = posted_stat_source_rows();
        }
    }

    $id = (int)($_GET['id'] ?? 0);
    $edit = null;
    if ($id > 0) {
        $st = $pdo->prepare('SELECT * FROM card_bonuses WHERE id = ? AND card_id = ?');
        $st->execute([$id, $cardId]);
        $edit = $st->fetch();
        if (!$edit) { flash('error', 'Характеристика не найдена.'); redirect(admin_url('card_bonuses', ['card_id' => $cardId])); }
    }

    if ($values === null) {
        $values = $edit ?: [];
        if ($id > 0) {
            $st = $pdo->prepare('SELECT item_type_id, `value`, value_max FROM card_bonus_item_type_overrides WHERE card_bonus_id = ? ORDER BY item_type_id');
            $st->execute([$id]);
            foreach ($st->fetchAll() as $o) {
                $overrideRows[] = [
                    'item_type_id' => (int)$o['item_type_id'],
                    'value' => (string)$o['value'],
                    'value_max' => $o['value_max'] === null ? '' : (string)$o['value_max'],
                ];
            }
            $st = $pdo->prepare('SELECT class_id FROM card_bonus_class_restrictions WHERE card_bonus_id = ? ORDER BY class_id');
            $st->execute([$id]);
            $classIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            $st = $pdo->prepare('SELECT item_type_id FROM card_bonus_item_type_restrictions WHERE card_bonus_id = ? ORDER BY item_type_id');
            $st->execute([$id]);
            $restrictionIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            $st = $pdo->prepare('SELECT item_type_id, required_count FROM card_bonus_item_type_conditions WHERE card_bonus_id = ? ORDER BY item_type_id');
            $st->execute([$id]);
            $conditionRows = $st->fetchAll();
            $st = $pdo->prepare('SELECT stat_id, step FROM card_bonus_stat_sources WHERE card_bonus_id = ? ORDER BY sort_order, id');
            $st->execute([$id]);
            $statSourceRows = $st->fetchAll();
            // Миграция legacy одиночного источника в мульти-строки.
            if (!$statSourceRows && (string)$edit['scaling_type'] === 'per_stat' && $edit['stat_source_id'] !== null) {
                $statSourceRows = [['stat_id' => (int)$edit['stat_source_id'], 'step' => max(1, (int)$edit['stat_step'])]];
            }
        }
    }

    $loadedType = (string)($values['scaling_type'] ?? 'none');
    if (!array_key_exists($loadedType, $scalingTypes)) $loadedType = 'none';
    $values = array_merge([
        'id' => 0, 'stat_id' => 0, 'value' => '', 'value_max' => '', 'value_type' => 'flat',
        'feature_id' => '', 'text_note_id' => '', 'state_id' => '', 'depends_on_card_id' => '',
        'scaling_type' => 'none', 'scaling_step' => 1, 'scaling_threshold' => 0,
        'scaling_required_count' => '', 'scaling_multiplier' => '', 'item_type_condition_mode' => 'all',
        'stat_source_id' => '', 'stat_threshold' => '', 'stat_value_true' => '',
        'stat_condition_mode' => 'all', 'sort_order' => 0,
    ], $values);
    $values['scaling_type'] = $loadedType;
    $currentId = (int)($values['id'] ?? 0);
    $mode = (string)$values['item_type_condition_mode'];

    $otherCards = $pdo->prepare('SELECT id, name FROM cards WHERE id <> ? ORDER BY sort_order, id');
    $otherCards->execute([$cardId]);
    $otherCards = $otherCards->fetchAll();

    /* --- Список характеристик с пакетной выборкой связей --- */
    $st = $pdo->prepare('SELECT b.*, s.name_ru AS stat_name, f.name AS feature_name,
        tn.title AS text_note_title, dc.name AS depends_on_card_name, ss.name_ru AS stat_source_name
        FROM card_bonuses b
        JOIN stats s ON s.id = b.stat_id
        LEFT JOIN features f ON f.id = b.feature_id
        LEFT JOIN text_notes tn ON tn.id = b.text_note_id
        LEFT JOIN cards dc ON dc.id = b.depends_on_card_id
        LEFT JOIN stats ss ON ss.id = b.stat_source_id
        WHERE b.card_id = ? ORDER BY b.sort_order, b.id');
    $st->execute([$cardId]);
    $bonuses = $st->fetchAll();

    $byBonus = ['classes' => [], 'restrictions' => [], 'conditions' => [], 'overrides' => [], 'sources' => []];
    $bonusIds = array_map('intval', array_column($bonuses, 'id'));
    if ($bonusIds) {
        $ph = implode(',', array_fill(0, count($bonusIds), '?'));
        $queries = [
            'classes' => "SELECT card_bonus_id, class_id FROM card_bonus_class_restrictions WHERE card_bonus_id IN ($ph) ORDER BY card_bonus_id, class_id",
            'restrictions' => "SELECT card_bonus_id, item_type_id FROM card_bonus_item_type_restrictions WHERE card_bonus_id IN ($ph) ORDER BY card_bonus_id, item_type_id",
            'conditions' => "SELECT card_bonus_id, item_type_id, required_count FROM card_bonus_item_type_conditions WHERE card_bonus_id IN ($ph) ORDER BY card_bonus_id, item_type_id",
            'overrides' => "SELECT o.card_bonus_id, o.item_type_id, o.`value`, o.value_max, t.name AS item_type_name
                FROM card_bonus_item_type_overrides o JOIN item_types t ON t.id = o.item_type_id
                WHERE o.card_bonus_id IN ($ph) ORDER BY o.card_bonus_id, o.item_type_id",
            'sources' => "SELECT s.card_bonus_id, s.stat_id, s.step, st.name_ru
                FROM card_bonus_stat_sources s JOIN stats st ON st.id = s.stat_id
                WHERE s.card_bonus_id IN ($ph) ORDER BY s.card_bonus_id, s.sort_order, s.id",
        ];
        foreach ($queries as $key => $sql) {
            $q = $pdo->prepare($sql);
            $q->execute($bonusIds);
            foreach ($q->fetchAll() as $r) $byBonus[$key][(int)$r['card_bonus_id']][] = $r;
        }
    }

    $classNames = [];
    foreach ($classes as $c) $classNames[(int)$c['id']] = (string)$c['name'];
    $typeNames = [];
    foreach ($itemTypes as $t) $typeNames[(int)$t['id']] = (string)$t['name'];
    $stateNames = [];
    foreach ($states as $s) $stateNames[(int)$s['id']] = (string)$s['name'];

    admin_layout_start('Характеристики карты: ' . $card['name'], $action, $menu);
    ?>
<div class="card">
<div class="card-header">
<h1>Характеристики карты</h1>
<div class="actions">
<span class="badge">Всего: <?= count($bonuses) ?></span>
<a class="btn" href="<?= e(admin_url('cards')) ?>"><?= admin_icon('layers', 16) ?>К картам</a>
<a class="btn" href="<?= e(admin_url('card_form', ['id' => $cardId])) ?>">Редактировать карту</a>
</div>
</div>
<div class="toolbar">
<span class="badge badge-ok"><?= e($card['name']) ?></span>
<span class="badge">Тип: <?= e($card['card_type_name']) ?></span>
<span class="badge">Слот: <?= $card['card_type_socket_number'] === null ? 'любой' : (int)$card['card_type_socket_number'] ?></span>
<span class="badge">Сет: <?= e($card['set_name'] ?? '—') ?></span>
<span class="badge <?= $allowedItemTypeIds ? '' : 'badge-danger' ?>">Разрешённых типов: <?= count($allowedItemTypeIds) ?></span>
</div>
<p class="muted">
Характеристика может зависеть от типа предмета, уровня улучшения, других карт, состояния и экипировки.
Если условие не выполнено, в калькуляторе она показывается со знаком «?» и пояснением.
</p>
<?php if (!$allowedItemTypeIds): ?>
<div class="admin-note">У карты не выбрано ни одного типа предмета. Сначала настройте их в форме карты — иначе карта не сможет быть вставлена и её характеристики не сработают.</div>
<?php endif; ?>
</div>

<form method="post" action="<?= e(admin_url('card_bonuses', ['card_id' => $cardId])) ?>">
<input type="hidden" name="form_action" value="save_card_bonus">
<input type="hidden" name="card_id" value="<?= $cardId ?>">
<input type="hidden" name="id" value="<?= $currentId ?>">
<?= csrf_field() ?>

<div class="card">
<div class="card-header">
<h2><?= $currentId > 0 ? 'Редактирование характеристики' : 'Новая характеристика' ?></h2>
<div class="actions">
<?php if ($currentId > 0): ?>
<span class="badge badge-warn">ID: <?= $currentId ?></span>
<a class="btn" href="<?= e(admin_url('card_bonuses', ['card_id' => $cardId])) ?>"><?= admin_icon('close', 16) ?>Отменить правку</a>
<?php endif; ?>
</div>
</div>
<div class="grid">
<div class="field"><label for="stat_id">Характеристика</label>
<select id="stat_id" name="stat_id" required><?= stat_options($stats, (int)$values['stat_id']) ?></select></div>
<div class="field"><label for="value">Значение</label>
<input type="number" id="value" name="value" step="0.01" required value="<?= e($values['value']) ?>"></div>
<div class="field" id="bonus-value-max-wrap"><label for="value_max">Максимальное значение</label>
<input type="number" id="value_max" name="value_max" step="0.01" value="<?= e($values['value_max']) ?>">
<div class="form-help">Используется только для типа «Диапазон значений».</div></div>
<div class="field"><label for="value_type">Тип значения</label>
<select id="value_type" name="value_type" required><?= enum_select('value_type', 'card', (string)$values['value_type']) ?></select></div>
<div class="field"><label for="feature_id">Функция</label>
<select id="feature_id" name="feature_id"><?= feature_options($features, intOrNull($values['feature_id'])) ?></select>
<div class="form-help">Характеристика активна только когда функция включена.</div></div>
<div class="field"><label for="text_note_id">Доп. текст</label>
<select id="text_note_id" name="text_note_id"><?= text_note_options($notes, intOrNull($values['text_note_id'])) ?></select>
<div class="form-help">Показывается вместо текста «Зависит от…», когда условие выполнено.</div></div>
<div class="field"><label for="state_id">Состояние</label>
<select id="state_id" name="state_id"<?= $states ? '' : ' disabled' ?>><?= state_options($states, intOrNull($values['state_id'])) ?></select></div>
<div class="field"><label for="depends_on_card_id">Зависит от карты</label>
<select id="depends_on_card_id" name="depends_on_card_id"><option value="">— нет —</option>
<?php foreach ($otherCards as $oc): ?>
<option value="<?= (int)$oc['id'] ?>"<?= selected((string)$values['depends_on_card_id'] === (string)$oc['id']) ?>><?= e($oc['name']) ?></option>
<?php endforeach; ?></select>
<div class="form-help">Работает только если указанная карта экипирована в любом предмете.</div></div>
<div class="field"><label for="sort_order">Порядок</label>
<input type="number" id="sort_order" name="sort_order" value="<?= e($values['sort_order']) ?>"></div>
</div>
</div>

<div class="card">
<div class="card-header"><h2>Динамическое значение</h2></div>
<div class="grid">
<div class="field"><label for="bonus-scaling-type">Тип масштабирования</label>
<select id="bonus-scaling-type" name="scaling_type" required>
<?php foreach ($scalingTypes as $k => $label): ?>
<option value="<?= e($k) ?>"<?= selected((string)$values['scaling_type'] === $k) ?>><?= e($label) ?></option>
<?php endforeach; ?></select></div>
<div class="field" id="bonus-step-wrap"><label for="scaling_step">Шаг N уровней</label>
<input type="number" id="scaling_step" name="scaling_step" min="1" value="<?= e($values['scaling_step']) ?>"></div>
<div class="field" id="bonus-threshold-wrap"><label for="scaling_threshold">Порог X уровней</label>
<input type="number" id="scaling_threshold" name="scaling_threshold" min="0" value="<?= e($values['scaling_threshold']) ?>"></div>
<div class="field" id="bonus-required-count-wrap"><label for="scaling_required_count">Количество одинаковых карт N</label>
<input type="number" id="scaling_required_count" name="scaling_required_count" min="1" value="<?= e($values['scaling_required_count']) ?>">
<div class="form-help">Например: 2, если нужно две одинаковые карты.</div></div>
<div class="field" id="bonus-multiplier-wrap"><label for="scaling_multiplier">Множитель X</label>
<input type="number" id="scaling_multiplier" name="scaling_multiplier" step="0.01" min="0.01" value="<?= e($values['scaling_multiplier']) ?>">
<div class="form-help">Например: 2, если характеристика должна удвоиться.</div></div>
<div class="field" id="bonus-stat-mode-wrap"><label for="stat_condition_mode">Режим источников</label>
<select id="stat_condition_mode" name="stat_condition_mode">
<option value="all"<?= selected((string)$values['stat_condition_mode'] === 'all') ?>>Все характеристики (AND)</option>
<option value="any"<?= selected((string)$values['stat_condition_mode'] === 'any') ?>>Любая характеристика (OR)</option>
</select>
<div class="form-help">AND: бонус растёт только когда все источники набирают полные шаги. OR: шаги суммируются.</div></div>
<div class="field field-wide" id="bonus-stat-sources-wrap"><label>Характеристики-источники</label>
<div class="toolbar"><button type="button" class="btn btn-primary js-add-stat-source"><?= admin_icon('plus', 16) ?>Добавить источник</button></div>
<div id="bonus-stat-sources" class="dynamic-rows">
<?php foreach ($statSourceRows as $r) card_stat_source_row($r, $stats); ?>
</div>
<template id="bonus-stat-source-template"><?php card_stat_source_row(['stat_id' => 0, 'step' => 1], $stats); ?></template>
<div class="form-help">Используется только для типа «За каждые N единиц другой характеристики».</div></div>
<div class="field" id="bonus-stat-source-wrap"><label for="stat_source_id">Характеристика-источник</label>
<select id="stat_source_id" name="stat_source_id"><?= stat_options($stats, (int)$values['stat_source_id']) ?></select></div>
<div class="field" id="bonus-stat-threshold-wrap"><label for="stat_threshold">Порог X единиц</label>
<input type="number" id="stat_threshold" name="stat_threshold" min="0" value="<?= e($values['stat_threshold']) ?>"></div>
<div class="field" id="bonus-stat-value-wrap"><label for="stat_value_true">Значение при пороге</label>
<input type="number" id="stat_value_true" name="stat_value_true" step="0.01" value="<?= e($values['stat_value_true']) ?>">
<div class="form-help">Подставляется вместо базового значения, когда источник достиг порога.</div></div>
</div>
</div>

<div class="card">
<div class="card-header"><h2>Ограничения доступа</h2></div>
<h3 class="admin-section-title">По классам</h3>
<p class="muted">Если не выбран ни один класс, характеристика доступна всем. Базовый класс включает свои подклассы.</p>
<?php class_checkbox_grid($classes, $classIds); ?>
<h3 class="admin-section-title">По типу предмета</h3>
<p class="muted">
Характеристика активна только в выбранных типах предметов. Доступны только типы, разрешённые для этой карты.
Если ничего не выбрано — активна для всех разрешённых.
</p>
<?php if ($allowedItemTypes): ?>
<div class="checkbox-grid">
<?php foreach ($allowedItemTypes as $t): ?>
<label><input type="checkbox" name="item_type_restriction_ids[]" value="<?= (int)$t['id'] ?>"<?= checked(in_array((int)$t['id'], $restrictionIds, true)) ?>><span><?= e($t['name']) ?></span></label>
<?php endforeach; ?>
</div>
<?php else: ?>
<div class="admin-note">У карты не выбрано ни одного типа предмета — ограничение недоступно.</div>
<?php endif; ?>
</div>

<div class="card">
<div class="card-header"><h2>Условия по экипированным предметам</h2></div>
<p class="muted">Например: характеристика активна, если экипировано минимум 2 кинжала.</p>
<div class="grid">
<div class="field"><label for="item_type_condition_mode">Режим условий</label>
<select id="item_type_condition_mode" name="item_type_condition_mode" required>
<option value="all"<?= selected($mode === 'all') ?>>Все условия (AND)</option>
<option value="any"<?= selected($mode === 'any') ?>>Любое условие (OR)</option>
</select></div>
</div>
<?php item_type_condition_block($itemTypes, $conditionRows); ?>
</div>

<div class="card">
<div class="card-header">
<h2>Переопределения по типам предметов</h2>
<div class="actions"><span class="badge">Задано: <?= count($overrideRows) ?></span></div>
</div>
<p class="muted">
Позволяют задать другое значение этой характеристики для конкретных типов предметов.
Например, базовое значение +10, а для двуручного меча +20.
</p>
<div class="toolbar"><button type="button" class="btn btn-primary js-add-override"><?= admin_icon('plus', 16) ?>Добавить переопределение</button></div>
<div id="bonus-overrides" class="dynamic-rows">
<?php foreach ($overrideRows as $r) card_override_row($r, $allowedItemTypes); ?>
</div>
<template id="bonus-override-template"><?php card_override_row([], $allowedItemTypes); ?></template>
<div class="form-actions">
<button type="submit" class="btn btn-primary"><?= admin_icon('check', 16) ?><?= $currentId > 0 ? 'Сохранить характеристику' : 'Добавить характеристику' ?></button>
<?php if ($currentId > 0): ?>
<a href="<?= e(admin_url('card_bonuses', ['card_id' => $cardId])) ?>" class="btn">Отмена</a>
<?php endif; ?>
<span class="form-help">Сочетание <kbd>Ctrl</kbd> + <kbd>Enter</kbd> сохраняет форму.</span>
</div>
</div>
</form>

<div class="card">
<div class="card-header">
<h2>Характеристики карты</h2>
<div class="actions">
<span class="badge">Всего: <?= count($bonuses) ?></span>
<?php if ($currentId > 0): ?><a class="btn btn-primary" href="<?= e(admin_url('card_bonuses', ['card_id' => $cardId])) ?>"><?= admin_icon('plus', 16) ?>Новая характеристика</a><?php endif; ?>
</div>
</div>
<table>
<thead><tr>
<th>ID</th><th>Характеристика</th><th>Значение</th><th>Тип</th><th>Масштабирование</th><th>Функция</th>
<th>Доп. текст</th><th>Состояние</th><th>Зависит от карты</th><th>Ограничения и условия</th>
<th>Переопределения</th><th>Порядок</th><th>Действия</th>
</tr></thead>
<tbody>
<?php if (!$bonuses): ?><tr><td colspan="13">Характеристик нет. Добавьте первую через форму выше.</td></tr><?php endif; ?>
<?php foreach ($bonuses as $b):
    $bid = (int)$b['id'];
    $bClasses = array_map(static fn($r) => (int)$r['class_id'], $byBonus['classes'][$bid] ?? []);
    $bRestrictions = array_map(static fn($r) => (int)$r['item_type_id'], $byBonus['restrictions'][$bid] ?? []);
    $bConditions = $byBonus['conditions'][$bid] ?? [];
    $bOverrides = $byBonus['overrides'][$bid] ?? [];
    $bSources = $byBonus['sources'][$bid] ?? [];
    $isCurrent = $currentId > 0 && $bid === $currentId;
?>
<tr>
<td><?= $bid ?></td>
<td><b><?= e($b['stat_name']) ?></b></td>
<td><?= e($b['value']) ?><?= $b['value_max'] !== null ? ' – ' . e($b['value_max']) : '' ?></td>
<td><?= e(enum_label('value_type', (string)$b['value_type'])) ?></td>
<td>
<?= e($scalingTypes[$b['scaling_type']] ?? $b['scaling_type']) ?>
<?php if (in_array($b['scaling_type'], ['per_level', 'per_every_n_levels', 'per_level_above'], true)): ?><br>шаг: <?= (int)$b['scaling_step'] ?><?php endif; ?>
<?php if (in_array($b['scaling_type'], ['per_level_above', 'once_above'], true)): ?><br>порог: <?= (int)$b['scaling_threshold'] ?><?php endif; ?>
<?php if ($b['scaling_type'] === 'multiply_by_identical_cards'): ?><br>требуется карт: <?= (int)$b['scaling_required_count'] ?><br>множитель: <?= e($b['scaling_multiplier']) ?><?php endif; ?>
<?php if ($b['scaling_type'] === 'per_stat'): ?>
<?php if ($bSources): ?>
<br>режим: <?= $b['stat_condition_mode'] === 'any' ? 'OR' : 'AND' ?>
<?php foreach ($bSources as $src): ?><br><?= e($src['name_ru']) ?>: за каждые <?= (int)$src['step'] ?><?php endforeach; ?>
<?php else: ?>
<br>источник: <?= e($b['stat_source_name'] ?? '—') ?><br>шаг: <?= (int)$b['stat_step'] ?>
<?php endif; ?>
<?php endif; ?>
<?php if ($b['scaling_type'] === 'stat_threshold'): ?>
<br>источник: <?= e($b['stat_source_name'] ?? '—') ?><br>порог: <?= (int)$b['stat_threshold'] ?><br>истина: <?= e($b['stat_value_true'] ?? '—') ?>
<?php endif; ?>
</td>
<td><?= e($b['feature_name'] ?? '—') ?></td>
<td><?= e($b['text_note_title'] ?? '—') ?></td>
<td><?= e($stateNames[(int)($b['state_id'] ?? 0)] ?? '—') ?></td>
<td><?= e($b['depends_on_card_name'] ?? '—') ?></td>
<td>
<?php
$parts = [];
$parts[] = 'Классы: ' . ($bClasses
    ? implode(', ', array_map(static fn($id) => $classNames[$id] ?? ('#' . $id), $bClasses))
    : 'все');
$parts[] = 'Активна в типах: ' . ($bRestrictions
    ? implode(', ', array_map(static fn($id) => $typeNames[$id] ?? ('#' . $id), $bRestrictions))
    : 'все разрешённые');
if ($bConditions) {
    $parts[] = 'Экипировка (' . ($b['item_type_condition_mode'] === 'any' ? 'любое' : 'все') . '): '
        . implode(', ', array_map(static function ($c) use ($typeNames) {
            return ($typeNames[(int)$c['item_type_id']] ?? ('#' . $c['item_type_id'])) . ' >= ' . (int)$c['required_count'];
        }, $bConditions));
}
echo '<ul class="condition-list">';
foreach ($parts as $p) echo '<li>' . e($p) . '</li>';
echo '</ul>';
?>
</td>
<td>
<?php if (!$bOverrides): ?>—<?php else: ?>
<?php foreach ($bOverrides as $o): ?>
<?= e($o['item_type_name']) ?>: <?= e($o['value']) ?><?= $o['value_max'] !== null ? ' – ' . e($o['value_max']) : '' ?><br>
<?php endforeach; ?>
<?php endif; ?>
</td>
<td><?= (int)$b['sort_order'] ?></td>
<td class="actions">
<?php if ($isCurrent): ?><span class="badge badge-warn">правится</span><?php endif; ?>
<a class="btn" href="<?= e(admin_url('card_bonuses', ['card_id' => $cardId, 'id' => $bid])) ?>">Редактировать</a>
<form method="post" action="<?= e(admin_url('card_bonuses', ['card_id' => $cardId])) ?>" class="inline js-confirm" data-confirm="Удалить характеристику «<?= e($b['stat_name']) ?>» карты «<?= e($card['name']) ?>»?">
<input type="hidden" name="form_action" value="delete_card_bonus">
<input type="hidden" name="card_id" value="<?= $cardId ?>">
<input type="hidden" name="id" value="<?= $bid ?>">
<?= csrf_field() ?>
<button type="submit" class="btn btn-danger"><?= admin_icon('trash', 15) ?>Удалить</button>
</form>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php
    admin_layout_end();
}

/* ======================= СЕТОВЫЕ БОНУСЫ ======================= */

function card_set_bonuses_page(string $action, array $menu): void
{
    $pdo = db();
    $setId = (int)($_GET['set_id'] ?? $_POST['set_id'] ?? 0);
    if ($setId <= 0) { flash('error', 'Некорректный ID сета.'); redirect(admin_url('card_sets')); }
    $st = $pdo->prepare('SELECT * FROM card_sets WHERE id = ?');
    $st->execute([$setId]);
    $set = $st->fetch();
    if (!$set) { flash('error', 'Сет не найден.'); redirect(admin_url('card_sets')); }

    $stats = fetch_stats($pdo);
    $statsById = cards_stat_map($stats);
    $maxSetCards = setting_int('max_set_cards', 64, 1, 255);
    $modeLabels = enum_rows('negative_mode');
    $values = null;

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        verify_csrf();
        $formAction = (string)($_POST['form_action'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        try {
            if ($formAction === 'delete_card_set_bonus') {
                if ($id <= 0) throw new RuntimeException('Некорректный ID бонуса.');
                $st = $pdo->prepare('DELETE FROM card_set_bonuses WHERE id = ? AND set_id = ?');
                $st->execute([$id, $setId]);
                if ($st->rowCount() === 0) throw new RuntimeException('Бонус не найден у этого сета.');
                flash('success', 'Сетовый бонус удалён.');
                redirect(admin_url('card_set_bonuses', ['set_id' => $setId]));
            }
            if ($formAction !== 'save_card_set_bonus') throw new RuntimeException('Неизвестное действие.');

            $requiredCount = (int)($_POST['required_count'] ?? 0);
            if ($requiredCount < 1 || $requiredCount > $maxSetCards) {
                throw new RuntimeException('Количество карт должно быть от 1 до ' . $maxSetCards . '.');
            }
            $statId = (int)($_POST['stat_id'] ?? 0);
            if ($statId <= 0) throw new RuntimeException('Выберите характеристику.');
            $stat = $statsById[$statId] ?? null;
            if (!$stat) throw new RuntimeException('Характеристика не найдена.');
            $valueType = (string)($_POST['value_type'] ?? 'flat');
            if (!in_array($valueType, enum_codes('value_type', 'set'), true)) throw new RuntimeException('Некорректный тип значения бонуса.');
            assert_stat_value_type($stat, $valueType);
            $value = read_decimal($_POST['value'] ?? '', 'Значение бонуса');
            $sortOrder = (int)($_POST['sort_order'] ?? 0);

            $st = $pdo->prepare('SELECT COUNT(*) FROM card_set_bonuses
                WHERE set_id = ? AND required_count = ? AND stat_id = ? AND value_type = ? AND id <> ?');
            $st->execute([$setId, $requiredCount, $statId, $valueType, $id]);
            if ((int)$st->fetchColumn() > 0) throw new RuntimeException('Такой сетовый бонус уже существует.');

            if ($id > 0) {
                $st = $pdo->prepare('SELECT COUNT(*) FROM card_set_bonuses WHERE id = ? AND set_id = ?');
                $st->execute([$id, $setId]);
                if (!(int)$st->fetchColumn()) throw new RuntimeException('Бонус не найден у этого сета.');
                $pdo->prepare('UPDATE card_set_bonuses SET required_count=?, stat_id=?, `value`=?, value_type=?, sort_order=? WHERE id=? AND set_id=?')
                    ->execute([$requiredCount, $statId, $value, $valueType, $sortOrder, $id, $setId]);
            } else {
                $pdo->prepare('INSERT INTO card_set_bonuses (set_id, required_count, stat_id, `value`, value_type, sort_order) VALUES (?,?,?,?,?,?)')
                    ->execute([$setId, $requiredCount, $statId, $value, $valueType, $sortOrder]);
            }
            flash('success', 'Сетовый бонус сохранён.');
            redirect(admin_url('card_set_bonuses', ['set_id' => $setId]));
        } catch (Throwable $ex) {
            flash('error', friendly_error($ex));
            if ($formAction === 'delete_card_set_bonus') redirect(admin_url('card_set_bonuses', ['set_id' => $setId]));
            $values = $_POST;
        }
    }

    $id = (int)($_GET['id'] ?? 0);
    $edit = null;
    if ($id > 0) {
        $st = $pdo->prepare('SELECT * FROM card_set_bonuses WHERE id = ? AND set_id = ?');
        $st->execute([$id, $setId]);
        $edit = $st->fetch();
        if (!$edit) { flash('error', 'Сетовый бонус не найден.'); redirect(admin_url('card_set_bonuses', ['set_id' => $setId])); }
    }

    $values = array_merge([
        'id' => 0, 'required_count' => 1, 'stat_id' => 0, 'value' => '', 'value_type' => 'flat', 'sort_order' => 0,
    ], $values ?? ($edit ?: []));
    $currentId = (int)($values['id'] ?? 0);

    $st = $pdo->prepare('SELECT b.*, s.name_ru AS stat_name FROM card_set_bonuses b
        JOIN stats s ON s.id = b.stat_id WHERE b.set_id = ? ORDER BY b.required_count, b.sort_order, b.id');
    $st->execute([$setId]);
    $bonuses = $st->fetchAll();

    $st = $pdo->prepare('SELECT COUNT(*) FROM cards WHERE set_id = ?');
    $st->execute([$setId]);
    $cardCount = (int)$st->fetchColumn();
    $st = $pdo->prepare('SELECT COUNT(*) FROM cards WHERE set_id = ? AND is_set_main = 1');
    $st->execute([$setId]);
    $mainCount = (int)$st->fetchColumn();

    admin_layout_start('Сетовые бонусы: ' . $set['name'], $action, $menu);
    ?>
<div class="card">
<div class="card-header">
<h1>Сетовые бонусы</h1>
<div class="actions">
<span class="badge">Бонусов: <?= count($bonuses) ?></span>
<a class="btn" href="<?= e(admin_url('card_sets')) ?>"><?= admin_icon('layers', 16) ?>К сетам</a>
<a class="btn" href="<?= e(admin_url('card_sets', ['id' => $setId])) ?>">Редактировать сет</a>
</div>
</div>
<div class="toolbar">
<span class="badge badge-ok"><?= e($set['name']) ?></span>
<span class="badge">Порог: <?= (int)$set['negative_threshold'] ?></span>
<span class="badge">До порога: <?= e($modeLabels[$set['negative_below_mode']] ?? $set['negative_below_mode']) ?></span>
<span class="badge">После порога: <?= e($modeLabels[$set['negative_reached_mode']] ?? $set['negative_reached_mode']) ?></span>
<span class="badge">Карт в сете: <?= $cardCount ?></span>
<span class="badge <?= $mainCount > 0 ? 'badge-ok' : 'badge-danger' ?>">Главных карт: <?= $mainCount ?></span>
</div>
<p class="muted">
Бонусы применяются, если экипировано указанное количество карт этого сета.
Пороги складываются: при 6 картах и бонусах за 2, 4 и 6 применяются все три.
</p>
<?php if ($mainCount === 0): ?>
<div class="admin-note">У сета нет ни одной главной карты — сетовые бонусы не активируются. Отметьте главную карту в форме карты.</div>
<?php endif; ?>
</div>

<div class="card">
<div class="card-header">
<h2><?= $currentId > 0 ? 'Редактирование бонуса' : 'Новый бонус' ?></h2>
<div class="actions">
<?php if ($currentId > 0): ?>
<span class="badge badge-warn">ID: <?= $currentId ?></span>
<a class="btn" href="<?= e(admin_url('card_set_bonuses', ['set_id' => $setId])) ?>"><?= admin_icon('close', 16) ?>Отменить правку</a>
<?php endif; ?>
</div>
</div>
<form method="post" action="<?= e(admin_url('card_set_bonuses', ['set_id' => $setId])) ?>">
<input type="hidden" name="form_action" value="save_card_set_bonus">
<input type="hidden" name="set_id" value="<?= $setId ?>">
<input type="hidden" name="id" value="<?= $currentId ?>">
<?= csrf_field() ?>
<div class="grid">
<div class="field"><label for="required_count">Количество карт</label>
<input type="number" id="required_count" name="required_count" min="1" max="<?= $maxSetCards ?>" required value="<?= e($values['required_count']) ?>"></div>
<div class="field"><label for="stat_id">Характеристика</label>
<select id="stat_id" name="stat_id" required><?= stat_options($stats, (int)$values['stat_id']) ?></select></div>
<div class="field"><label for="value">Значение</label>
<input type="number" id="value" name="value" step="0.01" required value="<?= e($values['value']) ?>"></div>
<div class="field"><label for="value_type">Тип</label>
<select id="value_type" name="value_type" required><?= enum_select('value_type', 'set', (string)$values['value_type']) ?></select></div>
<div class="field"><label for="sort_order">Порядок</label>
<input type="number" id="sort_order" name="sort_order" value="<?= e($values['sort_order']) ?>"></div>
</div>
<div class="form-actions">
<button type="submit" class="btn btn-primary"><?= admin_icon('check', 16) ?><?= $currentId > 0 ? 'Сохранить' : 'Добавить' ?></button>
<?php if ($currentId > 0): ?><a href="<?= e(admin_url('card_set_bonuses', ['set_id' => $setId])) ?>" class="btn">Отмена</a><?php endif; ?>
<span class="form-help">Сочетание <kbd>Ctrl</kbd> + <kbd>Enter</kbd> сохраняет форму.</span>
</div>
</form>
</div>

<div class="card">
<div class="card-header">
<h2>Список бонусов</h2>
<div class="actions"><span class="badge">Всего: <?= count($bonuses) ?></span></div>
</div>
<table>
<thead><tr><th>ID</th><th>Количество карт</th><th>Характеристика</th><th>Значение</th><th>Тип</th><th>Порядок</th><th>Действия</th></tr></thead>
<tbody>
<?php if (!$bonuses): ?><tr><td colspan="7">Сетовых бонусов нет. Добавьте первый через форму выше.</td></tr><?php endif; ?>
<?php foreach ($bonuses as $b): $bid = (int)$b['id']; ?>
<tr>
<td><?= $bid ?></td>
<td><span class="badge badge-ok"><?= (int)$b['required_count'] ?></span></td>
<td><b><?= e($b['stat_name']) ?></b></td>
<td><?= e($b['value']) ?></td>
<td><?= e(enum_label('value_type', (string)$b['value_type'])) ?></td>
<td><?= (int)$b['sort_order'] ?></td>
<td class="actions">
<?php if ($currentId > 0 && $bid === $currentId): ?><span class="badge badge-warn">правится</span><?php endif; ?>
<a class="btn" href="<?= e(admin_url('card_set_bonuses', ['set_id' => $setId, 'id' => $bid])) ?>">Редактировать</a>
<form method="post" action="<?= e(admin_url('card_set_bonuses', ['set_id' => $setId])) ?>" class="inline js-confirm" data-confirm="Удалить сетовый бонус «<?= e($b['stat_name']) ?>» за <?= (int)$b['required_count'] ?> карт?">
<input type="hidden" name="form_action" value="delete_card_set_bonus">
<input type="hidden" name="set_id" value="<?= $setId ?>">
<input type="hidden" name="id" value="<?= $bid ?>">
<?= csrf_field() ?>
<button type="submit" class="btn btn-danger"><?= admin_icon('trash', 15) ?>Удалить</button>
</form>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php
    admin_layout_end();
}