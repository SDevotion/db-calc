<?php
declare(strict_types=1);
if (!defined('ADMIN_ENTRY')) { http_response_code(403); exit; }

/** @var string $action */
/** @var array $menu */

switch ($action) {
    case 'items':                 items_list_page($action, $menu); break;
    case 'item_form':             item_form_page($action, $menu); break;
    case 'item_bonus_conditions': item_bonus_conditions_page($action, $menu); break;
    case 'item_cards':            item_cards_page($action, $menu); break;
    default:
        flash('error', 'Неизвестный раздел.');
        redirect(admin_url('items'));
}

/* ======================= СПИСОК ПРЕДМЕТОВ ======================= */

function items_page_counts(PDO $pdo, array $ids): array
{
    $out = [];
    if (!$ids) return $out;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $map = [
        'slots'   => "SELECT item_id, COUNT(*) c FROM item_slots WHERE item_id IN ($ph) GROUP BY item_id",
        'classes' => "SELECT item_id, COUNT(*) c FROM item_classes WHERE item_id IN ($ph) GROUP BY item_id",
        'bonuses' => "SELECT item_id, COUNT(*) c FROM item_bonuses WHERE item_id IN ($ph) GROUP BY item_id",
    ];
    foreach ($map as $key => $sql) {
        $st = $pdo->prepare($sql);
        $st->execute($ids);
        foreach ($st->fetchAll() as $r) {
            $out[(int)$r['item_id']][$key] = (int)$r['c'];
        }
    }
    return $out;
}

function items_list_page(string $action, array $menu): void
{
    $pdo = db();

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        verify_csrf();
        try {
            if ((string)($_POST['form_action'] ?? '') !== 'delete_item') {
                throw new RuntimeException('Неизвестное действие.');
            }
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Некорректный ID предмета.');
            $st = $pdo->prepare('DELETE FROM items WHERE id = ?');
            $st->execute([$id]);
            if ($st->rowCount() === 0) throw new RuntimeException('Предмет не найден.');
            flash('success', 'Предмет удалён вместе с ячейками, классами, бонусами и вставленными картами.');
        } catch (Throwable $ex) {
            flash('error', friendly_error($ex));
        }
        redirect(admin_url('items'));
    }

    $q       = trim((string)($_GET['q'] ?? ''));
    $grade   = (string)($_GET['grade'] ?? '');
    $typeId  = (int)($_GET['type'] ?? 0);
    $level   = (int)($_GET['level'] ?? 0);
    $perPage = 50;
    $page    = page_number();
    $maxLevel = setting_int('max_character_level', 65, 1, 255);

    $where = ' WHERE 1 = 1';
    $params = [];
    if ($q !== '') {
        $like = '%' . addcslashes($q, '\\%_') . '%';
        $where .= ' AND (i.name LIKE ? OR i.description LIKE ? OR EXISTS (
            SELECT 1 FROM item_bonuses b JOIN stats s ON s.id = b.stat_id
            WHERE b.item_id = i.id AND (s.name_ru LIKE ? OR s.alias LIKE ?)))';
        array_push($params, $like, $like, $like, $like);
    }
    if (in_array($grade, grade_codes(), true)) { $where .= ' AND i.grade = ?'; $params[] = $grade; }
    if ($typeId > 0) { $where .= ' AND i.item_type_id = ?'; $params[] = $typeId; }
    if ($level > 0)  { $where .= ' AND i.required_level = ?'; $params[] = $level; }

    $st = $pdo->prepare('SELECT COUNT(*) FROM items i' . $where);
    $st->execute($params);
    $total = (int)$st->fetchColumn();

    $pages = max(1, (int)ceil($total / $perPage));
    if ($page > $pages) $page = $pages;
    $offset = ($page - 1) * $perPage;

    $st = $pdo->prepare('SELECT i.*, t.name AS type_name, g.name AS grade_name FROM items i
        JOIN item_types t ON t.id = i.item_type_id
        LEFT JOIN grades g ON g.code = i.grade' . $where
        . ' ORDER BY i.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
    $st->execute($params);
    $items = $st->fetchAll();

    $counts = items_page_counts($pdo, array_map(static fn($r) => (int)$r['id'], $items));
    $types = fetch_item_types($pdo);
    $genderLabels = enum_rows('gender');
    $filterParams = array_filter([
        'q' => $q, 'grade' => $grade, 'type' => $typeId ?: null, 'level' => $level ?: null,
    ], static fn($v) => $v !== null && $v !== '');
    $hasFilters = (bool)$filterParams;

    admin_layout_start('Список предметов', $action, $menu);
    ?>
<div class="card">
<div class="card-header">
<h1>Предметы</h1>
<div class="actions">
<span class="badge">Найдено: <?= $total ?></span>
<a class="btn" href="<?= e(admin_url('items_section')) ?>"><?= admin_icon('box', 16) ?>Раздел</a>
<a class="btn btn-primary" href="<?= e(admin_url('item_form', ['id' => 0])) ?>"><?= admin_icon('plus', 16) ?>Создать предмет</a>
</div>
</div>
<p class="muted">
Поиск работает по названию, описанию и характеристикам предмета, включая их alias.
Предмет без ячеек или без классов не появится в калькуляторе — такие строки помечены красным.
</p>
<form method="get" action="<?= e(admin_url('items')) ?>" class="filters">
<input type="hidden" name="action" value="items">
<div class="filter-field">
<label for="q">Поиск</label>
<input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Название, описание или характеристика">
</div>
<div class="filter-field">
<label for="grade">Грейд</label>
<select id="grade" name="grade">
<option value="">Все грейды</option>
<?php foreach (grade_rows() as $gCode => $gName): ?>
<option value="<?= e($gCode) ?>"<?= selected($grade === $gCode) ?>><?= e($gName) ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="filter-field">
<label for="type">Тип предмета</label>
<select id="type" name="type">
<option value="0">Все типы</option>
<?php foreach ($types as $t): ?>
<option value="<?= (int)$t['id'] ?>"<?= selected($typeId === (int)$t['id']) ?>><?= e($t['name']) ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="filter-field">
<label for="level">Требуемый уровень</label>
<input type="number" id="level" name="level" min="1" max="<?= $maxLevel ?>" value="<?= $level > 0 ? $level : '' ?>" placeholder="Любой">
</div>
<div class="filter-actions">
<button type="submit" class="btn btn-primary"><?= admin_icon('search', 16) ?>Применить</button>
<?php if ($hasFilters): ?><a href="<?= e(admin_url('items')) ?>" class="btn">Сбросить</a><?php endif; ?>
</div>
</form>
</div>

<div class="card">
<div class="card-header">
<h2>Список</h2>
<div class="actions">
<span class="badge">Страница <?= $page ?> из <?= $pages ?></span>
</div>
</div>
<table>
<thead><tr>
<th>ID</th><th>Название</th><th>Тип</th><th>Грейд</th><th>Уровень</th><th>Пол</th>
<th>Атака / Защита</th><th>Скорость / DPS</th><th>Слоты</th><th>Улучшение</th>
<th>Ячейки / Классы / Бонусы</th><th>Действия</th>
</tr></thead>
<tbody>
<?php if (!$items): ?>
<tr><td colspan="12"><?= $hasFilters ? 'По фильтрам ничего не найдено. Сбросьте фильтры и попробуйте снова.' : 'Предметов нет. Создайте первый предмет.' ?></td></tr>
<?php endif; ?>
<?php foreach ($items as $item):
    $iid = (int)$item['id'];
    $c = $counts[$iid] ?? [];
    $slotsCount = (int)($c['slots'] ?? 0);
    $classesCount = (int)($c['classes'] ?? 0);
    $bonusesCount = (int)($c['bonuses'] ?? 0);
    $sockets = (int)$item['sockets'];
?>
<tr>
<td><?= $iid ?></td>
<td><b><?= e($item['name']) ?></b></td>
<td><?= e($item['type_name']) ?></td>
<td><?= $item['grade_name'] !== null ? '<span class="badge">' . e($item['grade_name']) . '</span>' : '<span class="badge badge-warn">без грейда</span>' ?></td>
<td><?= $item['required_level'] !== null ? (int)$item['required_level'] : '—' ?></td>
<td><?= e($genderLabels[$item['gender']] ?? $item['gender']) ?></td>
<td><?= (int)$item['base_attack'] ?> / <?= (int)$item['base_defense'] ?></td>
<td><?= e($item['base_attack_speed']) ?> / <?= e($item['base_dps']) ?></td>
<td><span class="badge <?= $sockets > 0 ? 'badge-ok' : '' ?>"><?= $sockets ?></span></td>
<td><?= (int)$item['can_upgrade'] ? '<span class="badge badge-ok">да</span>' : '<span class="badge">нет</span>' ?></td>
<td>
<span class="badge <?= $slotsCount > 0 ? '' : 'badge-danger' ?>" title="Ячейки экипировки">Я <?= $slotsCount ?></span>
<span class="badge <?= $classesCount > 0 ? '' : 'badge-danger' ?>" title="Доступность по классам">К <?= $classesCount ?></span>
<span class="badge" title="Бонусные характеристики">Б <?= $bonusesCount ?></span>
</td>
<td class="actions">
<a class="btn" href="<?= e(admin_url('item_form', ['id' => $iid])) ?>">Редактировать</a>
<a class="btn" href="<?= e(admin_url('item_cards', ['id' => $iid])) ?>">Карты</a>
<a class="btn" href="<?= e(admin_url('item_stamp', ['id' => $iid])) ?>">Печать</a>
<form method="post" class="inline js-confirm" data-confirm="Удалить предмет «<?= e($item['name']) ?>»? Будут удалены его ячейки, классы, бонусы и вставленные карты.">
<input type="hidden" name="form_action" value="delete_item">
<input type="hidden" name="id" value="<?= $iid ?>">
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

/* ======================= ФОРМА ПРЕДМЕТА ======================= */

function item_bonus_row(array $row, array $stats, array $features, array $notes, array $states, bool $isTemplate = false): void
{
    $rowId = (int)($row['id'] ?? 0);
    ?>
<tr>
<td><input type="hidden" name="bonus_id[]" value="<?= $rowId ?>">
<select name="bonus_stat[]" class="js-stat-select"><?= stat_options($stats, (int)($row['stat_id'] ?? 0)) ?></select></td>
<td><input type="number" step="0.01" name="bonus_value[]" value="<?= e($row['value'] ?? '') ?>"></td>
<td><select name="bonus_type[]"><?= enum_select('value_type', 'item', (string)($row['value_type'] ?? 'flat')) ?></select></td>
<td><select name="bonus_feature[]"><?= feature_options($features, intOrNull($row['feature_id'] ?? null)) ?></select></td>
<td><select name="bonus_text_note[]"><?= text_note_options($notes, intOrNull($row['text_note_id'] ?? null)) ?></select></td>
<td><select name="bonus_state[]"<?= $states ? '' : ' disabled' ?>><?= state_options($states, intOrNull($row['state_id'] ?? null)) ?></select></td>
<td class="bonus-actions">
<?php if (!$isTemplate && $rowId > 0): ?>
<a class="btn" href="<?= e(admin_url('item_bonus_conditions', ['item_bonus_id' => $rowId])) ?>">Условия</a>
<?php endif; ?>
<button type="button" class="btn btn-danger js-remove-bonus"><?= admin_icon('trash', 15) ?>Удалить</button>
</td>
</tr>
<?php
}

function item_form_page(string $action, array $menu): void
{
    $pdo = db();
    $values = null;
    $slotSet = [];
    $classSet = [];
    $bonusRows = [];
    $maxLevel = setting_int('max_character_level', 65, 1, 255);
    $maxSockets = setting_int('max_sockets', 3, 0, 16);

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        verify_csrf();
        try {
            if ((string)($_POST['form_action'] ?? '') !== 'save_item') {
                throw new RuntimeException('Неизвестное действие.');
            }
            $itemId        = (int)($_POST['id'] ?? 0);
            $name          = trim((string)($_POST['name'] ?? ''));
            $iconPath      = trim((string)($_POST['icon_path'] ?? ''));
            $itemTypeId    = (int)($_POST['item_type_id'] ?? 0);
            $grade = trim((string)($_POST['grade'] ?? ''));
            $grade = $grade === '' ? null : $grade;
            $requiredLevelRaw = trim((string)($_POST['required_level'] ?? ''));
            $requiredLevel = $requiredLevelRaw === '' ? null : (int)$requiredLevelRaw;
            $internalLevelRaw = trim((string)($_POST['internal_level'] ?? ''));
            $internalLevel = $internalLevelRaw === '' ? null : (int)$internalLevelRaw;
            $gender        = (string)($_POST['gender'] ?? 'any');
            $baseDefense   = (int)($_POST['base_defense'] ?? 0);
            $baseAttack    = (int)($_POST['base_attack'] ?? 0);
            $sockets       = (int)($_POST['sockets'] ?? 0);
            $description   = trim((string)($_POST['description'] ?? ''));
            $descFeatureId = intOrNull($_POST['description_feature_id'] ?? null);
            $canUpgrade    = isset($_POST['can_upgrade']) ? 1 : 0;
            if ($grade === null) $canUpgrade = 0;
            $stateId       = intOrNull($_POST['state_id'] ?? null);
            $speedRaw      = read_decimal($_POST['base_attack_speed'] ?? '', 'Базовая скорость атаки', false) ?? '0';
            $dpsRaw        = read_decimal($_POST['base_dps'] ?? '', 'Базовый DPS', false) ?? '0';

            if ($name === '') throw new RuntimeException('Укажите название предмета.');
            if (mb_strlen($name) > 150) throw new RuntimeException('Название предмета не должно превышать 150 символов.');
            if ($iconPath === '') throw new RuntimeException('Укажите путь к иконке предмета.');
            if (mb_strlen($iconPath) > 255) throw new RuntimeException('Путь к иконке не должен превышать 255 символов.');
            if ($itemTypeId <= 0) throw new RuntimeException('Выберите тип предмета.');
            assert_row_exists($pdo, 'item_types', $itemTypeId, 'Тип предмета не найден.');
            if ($grade !== null && !in_array($grade, grade_codes(), true)) throw new RuntimeException('Некорректный грейд предмета.');
            if ($requiredLevel !== null && ($requiredLevel < 1 || $requiredLevel > $maxLevel)) {
                throw new RuntimeException('Уровень предмета должен быть от 1 до ' . $maxLevel . ' или пустым.');
            }
            if ($internalLevel !== null && ($internalLevel < 1 || $internalLevel > 65535)) {
                throw new RuntimeException('Внутренний уровень должен быть от 1 до 65535 или пустым.');
            }
            if (!in_array($gender, enum_codes('gender', 'item'), true)) throw new RuntimeException('Некорректный пол предмета.');
            if ($baseDefense < 0) throw new RuntimeException('Базовая защита не может быть отрицательной.');
            if ($baseAttack < 0) throw new RuntimeException('Базовая атака не может быть отрицательной.');
            if ((float)$speedRaw < 0) throw new RuntimeException('Базовая скорость атаки не может быть отрицательной.');
            if ((float)$dpsRaw < 0) throw new RuntimeException('Базовый DPS не может быть отрицательным.');
            if ($sockets < 0 || $sockets > $maxSockets) throw new RuntimeException('Количество слотов должно быть от 0 до ' . $maxSockets . '.');
            if (mb_strlen($description) > 500) throw new RuntimeException('Описание предмета не должно превышать 500 символов.');
            if ($description === '') $descFeatureId = null;
            if ($stateId !== null) assert_row_exists($pdo, 'states', $stateId, 'Состояние не найдено.');

            $featureIds = array_map('intval', $pdo->query('SELECT id FROM features')->fetchAll(PDO::FETCH_COLUMN));
            $noteIds = array_map('intval', $pdo->query('SELECT id FROM text_notes')->fetchAll(PDO::FETCH_COLUMN));
            if ($descFeatureId !== null && !in_array($descFeatureId, $featureIds, true)) {
                throw new RuntimeException('Некорректная функция описания.');
            }

            $slots = array_values(array_unique(array_map('intval', (array)($_POST['slots'] ?? []))));
            $slots = array_values(array_filter($slots, static fn($s) => in_array($s, slot_numbers(), true)));
            if (!$slots) throw new RuntimeException('Выберите хотя бы одну ячейку для предмета.');

            $classIds = array_values(array_unique(array_map('intval', (array)($_POST['classes'] ?? []))));
            $validClasses = array_map('intval', $pdo->query('SELECT id FROM classes')->fetchAll(PDO::FETCH_COLUMN));
            $classIds = array_values(array_intersect($classIds, $validClasses));
            if (!$classIds) throw new RuntimeException('Выберите хотя бы один класс или подкласс для предмета.');

            $statsById = [];
            foreach ($pdo->query('SELECT * FROM stats WHERE is_base = 0')->fetchAll() as $s) $statsById[(int)$s['id']] = $s;
            $stateIds = array_map('intval', $pdo->query('SELECT id FROM states')->fetchAll(PDO::FETCH_COLUMN));

            $bIds    = (array)($_POST['bonus_id'] ?? []);
            $bStats  = (array)($_POST['bonus_stat'] ?? []);
            $bValues = (array)($_POST['bonus_value'] ?? []);
            $bTypes  = (array)($_POST['bonus_type'] ?? []);
            $bFeats  = (array)($_POST['bonus_feature'] ?? []);
            $bNotes  = (array)($_POST['bonus_text_note'] ?? []);
            $bStates = (array)($_POST['bonus_state'] ?? []);
            $n = max(count($bIds), count($bStats), count($bValues), count($bTypes), count($bFeats), count($bNotes), count($bStates));

            $submitted = [];
            $dup = [];
            for ($i = 0; $i < $n; $i++) {
                $bid = (int)($bIds[$i] ?? 0);
                $sid = (int)($bStats[$i] ?? 0);
                $raw = trim((string)($bValues[$i] ?? ''));
                if ($sid <= 0 && $raw === '') continue;
                if ($sid <= 0) throw new RuntimeException('Выберите характеристику для всех заполненных строк бонусов.');
                $stat = $statsById[$sid] ?? null;
                if (!$stat) throw new RuntimeException('Некорректная характеристика бонуса.');
                $value = read_decimal($raw, 'Значение бонуса');
                $vt = (string)($bTypes[$i] ?? '');
                if (!in_array($vt, enum_codes('value_type', 'item'), true)) throw new RuntimeException('Некорректный тип значения бонуса.');
                if ($vt === 'flat' && !(int)$stat['supports_flat']) {
                    throw new RuntimeException('Характеристика «' . $stat['name_ru'] . '» не поддерживает плоское значение.');
                }
                if ($vt === 'percent' && !(int)$stat['supports_percent']) {
                    throw new RuntimeException('Характеристика «' . $stat['name_ru'] . '» не поддерживает процентное значение.');
                }
                $fid = intOrNull($bFeats[$i] ?? null);
                if ($fid !== null && !in_array($fid, $featureIds, true)) throw new RuntimeException('Некорректная функция бонуса.');
                $nid = intOrNull($bNotes[$i] ?? null);
                if ($nid !== null && !in_array($nid, $noteIds, true)) throw new RuntimeException('Некорректная текстовая заметка бонуса.');
                $stid = intOrNull($bStates[$i] ?? null);
                if ($stid !== null && !in_array($stid, $stateIds, true)) throw new RuntimeException('Состояние бонуса не найдено.');

                $key = $sid . '|' . $vt . '|' . ($fid ?? 0) . '|' . ($stid ?? 0);
                if (isset($dup[$key])) {
                    throw new RuntimeException('Дублируется характеристика «' . $stat['name_ru'] . '» с тем же типом, функцией и состоянием.');
                }
                $dup[$key] = true;

                $submitted[] = [
                    'id' => $bid, 'stat_id' => $sid, 'value' => $value, 'value_type' => $vt,
                    'feature_id' => $fid, 'text_note_id' => $nid, 'state_id' => $stid,
                ];
            }

            if ($itemId > 0) assert_row_exists($pdo, 'items', $itemId, 'Предмет не найден.');

            $pdo->beginTransaction();
            try {
                $fields = [$name, $description, $descFeatureId, $iconPath, $itemTypeId, $grade, $requiredLevel,
                    $internalLevel, $gender, $baseDefense, $baseAttack, $speedRaw, $dpsRaw, $sockets, $canUpgrade, $stateId];
                if ($itemId > 0) {
                    $pdo->prepare('UPDATE items SET name=?, description=?, description_feature_id=?, icon_path=?,
                        item_type_id=?, grade=?, required_level=?, internal_level=?, gender=?, base_defense=?, base_attack=?,
                        base_attack_speed=?, base_dps=?, sockets=?, can_upgrade=?, state_id=? WHERE id=?')
                        ->execute([...$fields, $itemId]);
                } else {
                    $pdo->prepare('INSERT INTO items (name, description, description_feature_id, icon_path,
                        item_type_id, grade, required_level, internal_level, gender, base_defense, base_attack,
                        base_attack_speed, base_dps, sockets, can_upgrade, state_id)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute($fields);
                    $itemId = (int)$pdo->lastInsertId();
                }

                $pdo->prepare('DELETE FROM item_cards WHERE item_id = ? AND socket_number > ?')->execute([$itemId, $sockets]);
                $pdo->prepare('DELETE FROM item_slots WHERE item_id = ?')->execute([$itemId]);
                $pdo->prepare('DELETE FROM item_classes WHERE item_id = ?')->execute([$itemId]);
                $st = $pdo->prepare('INSERT INTO item_slots (item_id, slot_number) VALUES (?, ?)');
                foreach ($slots as $s) $st->execute([$itemId, $s]);
                $st = $pdo->prepare('INSERT INTO item_classes (item_id, class_id) VALUES (?, ?)');
                foreach ($classIds as $cid) $st->execute([$itemId, $cid]);

                $existing = [];
                $st = $pdo->prepare('SELECT id, stat_id, stat_source_id, state_id FROM item_bonuses WHERE item_id = ?');
                $st->execute([$itemId]);
                foreach ($st->fetchAll() as $r) $existing[(int)$r['id']] = $r;

                $upd = $pdo->prepare('UPDATE item_bonuses SET stat_id=?, `value`=?, value_type=?, feature_id=?,
                    text_note_id=?, state_id=?, sort_order=? WHERE id=? AND item_id=?');
                $ins = $pdo->prepare('INSERT INTO item_bonuses (item_id, stat_id, `value`, value_type, feature_id,
                    text_note_id, state_id, scaling_type, scaling_step, scaling_threshold, item_type_condition_mode, sort_order)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');

                $kept = [];
                $order = 1;
                foreach ($submitted as $b) {
                    $bid = (int)$b['id'];
                    if ($bid > 0) {
                        if (!isset($existing[$bid])) {
                            throw new RuntimeException('Характеристика #' . $bid . ' не принадлежит этому предмету.');
                        }
                        $src = $existing[$bid]['stat_source_id'] === null ? null : (int)$existing[$bid]['stat_source_id'];
                        if ($src !== null && stat_dependency_would_create_cycle($pdo, $src, (int)$b['stat_id'], 'item', $bid)) {
                            throw new RuntimeException('Изменение характеристики бонуса #' . $bid . ' создаёт цикл зависимости.');
                        }
                        $upd->execute([$b['stat_id'], $b['value'], $b['value_type'], $b['feature_id'],
                            $b['text_note_id'], $b['state_id'], $order, $bid, $itemId]);
                        $kept[] = $bid;
                    } else {
                        $ins->execute([$itemId, $b['stat_id'], $b['value'], $b['value_type'], $b['feature_id'],
                            $b['text_note_id'], $b['state_id'], 'none', 1, 0, 'all', $order]);
                    }
                    $order++;
                }
                $toDelete = array_values(array_diff(array_keys($existing), $kept));
                if ($toDelete) {
                    $ph = implode(',', array_fill(0, count($toDelete), '?'));
                    $pdo->prepare("DELETE FROM item_bonuses WHERE item_id = ? AND id IN ($ph)")
                        ->execute(array_merge([$itemId], $toDelete));
                }
                $pdo->commit();
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $ex;
            }
            flash('success', 'Предмет сохранён. Условия характеристик настраиваются кнопкой «Условия».');
            redirect(admin_url('item_form', ['id' => $itemId]));
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', friendly_error($ex));
            $values = $_POST;
        }
    }

    $id = (int)($_GET['id'] ?? 0);
    $edit = null;
    if ($id > 0) {
        $st = $pdo->prepare('SELECT * FROM items WHERE id = ?');
        $st->execute([$id]);
        $edit = $st->fetch();
        if (!$edit) { flash('error', 'Предмет не найден.'); redirect(admin_url('items')); }
    }

    $types = fetch_item_types($pdo);
    $stats = fetch_stats($pdo);
    $features = fetch_features($pdo);
    $notes = fetch_text_notes($pdo);
    $states = fetch_states($pdo);
    $classes = fetch_classes($pdo);

    if ($values === null) {
        $values = $edit ?: [];
        if ($id > 0) {
            $st = $pdo->prepare('SELECT slot_number FROM item_slots WHERE item_id = ?');
            $st->execute([$id]);
            $slotSet = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            $st = $pdo->prepare('SELECT class_id FROM item_classes WHERE item_id = ?');
            $st->execute([$id]);
            $classSet = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            $st = $pdo->prepare('SELECT id, stat_id, `value`, value_type, feature_id, text_note_id, state_id
                FROM item_bonuses WHERE item_id = ? ORDER BY sort_order, id');
            $st->execute([$id]);
            $bonusRows = $st->fetchAll();
        }
    } else {
        $slotSet = array_map('intval', (array)($values['slots'] ?? []));
        $classSet = array_map('intval', (array)($values['classes'] ?? []));
        $bIds = (array)($values['bonus_id'] ?? []);
        $bStats = (array)($values['bonus_stat'] ?? []);
        $bValues = (array)($values['bonus_value'] ?? []);
        $bTypes = (array)($values['bonus_type'] ?? []);
        $bFeats = (array)($values['bonus_feature'] ?? []);
        $bNotes = (array)($values['bonus_text_note'] ?? []);
        $bStates = (array)($values['bonus_state'] ?? []);
        $n = max(count($bIds), count($bStats), count($bValues), count($bTypes), count($bFeats), count($bNotes), count($bStates));
        for ($i = 0; $i < $n; $i++) {
            $bid = (int)($bIds[$i] ?? 0);
            $sid = (int)($bStats[$i] ?? 0);
            $val = trim((string)($bValues[$i] ?? ''));
            if ($bid <= 0 && $sid <= 0 && $val === '') continue;
            $bonusRows[] = [
                'id' => $bid, 'stat_id' => $sid, 'value' => $val,
                'value_type' => (string)($bTypes[$i] ?? 'flat'),
                'feature_id' => intOrNull($bFeats[$i] ?? null),
                'text_note_id' => intOrNull($bNotes[$i] ?? null),
                'state_id' => intOrNull($bStates[$i] ?? null),
            ];
        }
    }

    $emptyRow = ['id' => 0, 'stat_id' => 0, 'value' => '', 'value_type' => 'flat',
        'feature_id' => null, 'text_note_id' => null, 'state_id' => null];
    if (!$bonusRows) $bonusRows = [$emptyRow, $emptyRow, $emptyRow];

    $values = array_merge([
        'id' => 0, 'name' => '', 'description' => '', 'description_feature_id' => '', 'icon_path' => '',
        'item_type_id' => 0, 'grade' => '', 'required_level' => '', 'internal_level' => '', 'gender' => 'any',
        'base_defense' => 0, 'base_attack' => 0, 'base_attack_speed' => '0', 'base_dps' => '0',
        'sockets' => 0, 'can_upgrade' => 0, 'state_id' => '',
    ], $values);
    $values['description_feature_id'] = $values['description_feature_id'] ?? '';
    $currentId = (int)($values['id'] ?? 0);

    $baseClasses = [];
    $childrenByParent = [];
    foreach ($classes as $c) {
        if ($c['parent_id'] === null) $baseClasses[] = $c;
        else $childrenByParent[(int)$c['parent_id']][] = $c;
    }
    $pairGroups = slot_pair_groups();
    $savedBonusCount = 0;
    foreach ($bonusRows as $r) if ((int)($r['id'] ?? 0) > 0) $savedBonusCount++;

    admin_layout_start($currentId > 0 ? 'Редактирование предмета' : 'Новый предмет', $action, $menu);
    ?>
<form method="post" action="<?= e(admin_url('item_form')) ?>">
<input type="hidden" name="form_action" value="save_item">
<input type="hidden" name="id" value="<?= $currentId ?>">
<?= csrf_field() ?>

<div class="card">
<div class="card-header">
<h1><?= $currentId > 0 ? 'Редактирование предмета' : 'Новый предмет' ?></h1>
<div class="actions">
<?php if ($currentId > 0): ?><span class="badge badge-warn">ID: <?= $currentId ?></span><?php endif; ?>
<a class="btn" href="<?= e(admin_url('items')) ?>"><?= admin_icon('box', 16) ?>К списку</a>
<?php if ($currentId > 0): ?>
<a class="btn" href="<?= e(admin_url('item_cards', ['id' => $currentId])) ?>"><?= admin_icon('layers', 16) ?>Карты предмета</a>
<?php endif; ?>
</div>
</div>
<p class="muted">
Предмет появится в калькуляторе только если у него выбрана хотя бы одна ячейка и хотя бы один класс.
Названия базовых характеристик и их формат вывода задаются в разделе «Характеристики».
</p>
<div class="grid">
<div class="field"><label for="name">Название</label>
<input type="text" id="name" name="name" required maxlength="150" value="<?= e($values['name']) ?>"></div>
<div class="field"><label for="icon_path">Путь к иконке</label>
<input type="text" id="icon_path" name="icon_path" required maxlength="255" value="<?= e($values['icon_path']) ?>" placeholder="assets/icons/items/sword.png"></div>
<div class="field"><label for="item_type_id">Тип предмета</label>
<select id="item_type_id" name="item_type_id" required><option value="0">— выбрать —</option>
<?php foreach ($types as $t): ?>
<option value="<?= (int)$t['id'] ?>"<?= selected((int)$values['item_type_id'] === (int)$t['id']) ?>><?= e($t['name']) ?></option>
<?php endforeach; ?></select></div>
<div class="field"><label for="grade">Грейд</label>
<select id="grade" name="grade">
<option value=""<?= selected((string)$values['grade'] === '') ?>>— без грейда —</option>
<?php foreach (grade_rows() as $gCode => $gName): ?>
<option value="<?= e($gCode) ?>"<?= selected((string)$values['grade'] === $gCode) ?>><?= e($gName) ?></option>
<?php endforeach; ?></select>
<div class="form-help">Без грейда предмет нельзя улучшать, улучшение отключается автоматически.</div></div>
<div class="field"><label for="required_level">Требуемый уровень</label>
<input type="number" id="required_level" name="required_level" min="1" max="<?= $maxLevel ?>" value="<?= e($values['required_level']) ?>" placeholder="Любой">
<div class="form-help">Пусто — предмет доступен на любом уровне.</div></div>
<div class="field"><label for="internal_level">Внутренний уровень</label>
<input type="number" id="internal_level" name="internal_level" min="1" max="65535" value="<?= e($values['internal_level']) ?>" placeholder="Как требуемый">
<div class="form-help">Уровень для расчёта качества печати. Пусто — берётся требуемый уровень.</div></div>
<div class="field"><label for="gender">Пол</label>
<select id="gender" name="gender" required><?= enum_select('gender', 'item', (string)$values['gender']) ?></select></div>
<div class="field"><label for="state_id">Состояние</label>
<select id="state_id" name="state_id"<?= $states ? '' : ' disabled' ?>><?= state_options($states, intOrNull($values['state_id'])) ?></select>
<div class="form-help">Если состояние выбрано, предмет участвует в расчётах только когда оно активно.</div></div>
</div>
</div>

<div class="card">
<div class="card-header"><h2>Базовые характеристики</h2></div>
<div class="grid">
<div class="field"><label for="base_attack"><?= e(base_stat_label('base_attack', 'Базовая атака')) ?></label>
<input type="number" id="base_attack" name="base_attack" min="0" required value="<?= e($values['base_attack']) ?>"></div>
<div class="field"><label for="base_defense"><?= e(base_stat_label('base_defense', 'Базовая защита')) ?></label>
<input type="number" id="base_defense" name="base_defense" min="0" required value="<?= e($values['base_defense']) ?>"></div>
<div class="field"><label for="base_attack_speed"><?= e(base_stat_label('base_attack_speed', 'Базовая скорость атаки')) ?></label>
<input type="number" id="base_attack_speed" name="base_attack_speed" step="0.01" min="0" value="<?= e($values['base_attack_speed']) ?>"></div>
<div class="field"><label for="base_dps"><?= e(base_stat_label('base_dps', 'Базовый DPS')) ?></label>
<input type="number" id="base_dps" name="base_dps" step="0.01" min="0" value="<?= e($values['base_dps']) ?>"></div>
<div class="field"><label for="sockets">Количество слотов</label>
<input type="number" id="sockets" name="sockets" min="0" max="<?= $maxSockets ?>" required value="<?= e($values['sockets']) ?>">
<div class="form-help">При уменьшении количества слотов лишние вставленные карты удаляются.</div></div>
<div class="field field-checkbox"><label>
<input type="checkbox" name="can_upgrade"<?= checked((int)$values['can_upgrade'] === 1) ?>> Можно улучшать</label>
<div class="form-help">Недоступно, если грейд не выбран.</div></div>
<div class="field field-wide"><label for="description"><?= e(base_stat_label('description', 'Описание')) ?></label>
<input type="text" id="description" name="description" maxlength="500" value="<?= e($values['description']) ?>" placeholder="Необязательное текстовое описание предмета"></div>
<div class="field"><label for="description_feature_id">Функция описания</label>
<select id="description_feature_id" name="description_feature_id"><?= feature_options($features, intOrNull($values['description_feature_id'])) ?></select>
<div class="form-help">Описание показывается только когда функция включена.</div></div>
</div>
</div>

<div class="card">
<div class="card-header">
<h2>Ячейки экипировки</h2>
<div class="actions">
<span class="badge" data-slot-count>Выбрано: <?= count($slotSet) ?></span>
<button type="button" class="btn" data-check-all="#slot-checkboxes">Выбрать все</button>
<button type="button" class="btn" data-uncheck-all="#slot-checkboxes">Снять все</button>
</div>
</div>
<p class="muted">
<?php if ($pairGroups): ?>
Парные ячейки выбирайте вместе: <?= e(implode(', ', array_map(static fn($g) => implode(' + ', $g), $pairGroups))) ?>.
<?php else: ?>
Парных ячеек нет. Отметьте ячейки, в которые можно надеть предмет.
<?php endif; ?>
</p>
<div id="slot-checkboxes" class="checkbox-grid">
<?php foreach (slot_all() as $s => $slotRow): ?>
<label title="<?= e($slotRow['name']) ?>"><input type="checkbox" name="slots[]" value="<?= $s ?>"<?= checked(in_array($s, $slotSet, true)) ?>><span><?= $s ?> <em><?= e($slotRow['name']) ?></em></span></label>
<?php endforeach; ?>
</div>
</div>

<div class="card">
<div class="card-header">
<h2>Доступность по классам</h2>
<div class="actions">
<button type="button" class="btn" data-select-base-all>Все базовые классы</button>
<button type="button" class="btn" data-clear-classes>Снять все</button>
</div>
</div>
<p class="muted">
Если выбран базовый класс, его подклассы автоматически получают доступ на стороне калькулятора.
Отдельно отмеченный подкласс даёт доступ только ему.
</p>
<div class="classes-block">
<?php foreach ($baseClasses as $bc): $bid = (int)$bc['id']; ?>
<fieldset class="class-group">
<legend><label><input type="checkbox" class="js-group-master"> <?= e($bc['name']) ?></label></legend>
<label class="base-class-label">
<input type="checkbox" class="js-base-class" name="classes[]" value="<?= $bid ?>"<?= checked(in_array($bid, $classSet, true)) ?>> Сам базовый класс</label>
<?php if (!empty($childrenByParent[$bid])): ?>
<div class="subclass-list">
<?php foreach ($childrenByParent[$bid] as $ch): ?>
<label><input type="checkbox" name="classes[]" value="<?= (int)$ch['id'] ?>"<?= checked(in_array((int)$ch['id'], $classSet, true)) ?>> <?= e($ch['name']) ?></label>
<?php endforeach; ?>
</div>
<?php endif; ?>
</fieldset>
<?php endforeach; ?>
</div>
</div>

<div class="card">
<div class="card-header">
<h2>Бонусные характеристики</h2>
<div class="actions">
<span class="badge">Сохранённых: <?= $savedBonusCount ?></span>
<button type="button" class="btn btn-primary js-add-bonus"><?= admin_icon('plus', 16) ?>Добавить бонус</button>
</div>
</div>
<p class="muted">
Здесь задаются базовое значение, тип, функция, дополнительный текст и состояние.
Масштабирование и ограничения настраиваются кнопкой «Условия» после сохранения.
Нельзя добавить две одинаковые характеристики с тем же типом, функцией и состоянием.
</p>
<table class="bonus-table">
<thead><tr><th>Характеристика</th><th>Значение</th><th>Тип</th><th>Функция</th><th>Доп. текст</th><th>Состояние</th><th>Действия</th></tr></thead>
<tbody id="bonus-rows">
<?php foreach ($bonusRows as $row) item_bonus_row($row, $stats, $features, $notes, $states); ?>
</tbody>
</table>
<template id="bonus-row-template">
<?php item_bonus_row($emptyRow, $stats, $features, $notes, $states, true); ?>
</template>
<div class="form-actions">
<button type="submit" class="btn btn-primary"><?= admin_icon('check', 16) ?>Сохранить предмет</button>
<a href="<?= e(admin_url('items')) ?>" class="btn">Отмена</a>
<span class="form-help">Сочетание <kbd>Ctrl</kbd> + <kbd>Enter</kbd> сохраняет форму.</span>
</div>
</div>
</form>
<?php
    admin_layout_end();
}

/* ======================= УСЛОВИЯ ХАРАКТЕРИСТИКИ ======================= */

function item_bonus_conditions_page(string $action, array $menu): void
{
    $pdo = db();
    $bonusId = (int)($_GET['item_bonus_id'] ?? $_POST['item_bonus_id'] ?? 0);
    if ($bonusId <= 0) { flash('error', 'Некорректный ID характеристики предмета.'); redirect(admin_url('items')); }

    $st = $pdo->prepare('SELECT ib.*, i.name AS item_name, s.name_ru AS stat_name
        FROM item_bonuses ib
        JOIN items i ON i.id = ib.item_id
        JOIN stats s ON s.id = ib.stat_id
        WHERE ib.id = ?');
    $st->execute([$bonusId]);
    $bonus = $st->fetch();
    if (!$bonus) { flash('error', 'Характеристика предмета не найдена.'); redirect(admin_url('items')); }

    $scalingTypes = scaling_type_labels(false);
    $values = null;
    $classIds = [];
    $conditionRows = [];

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        verify_csrf();
        try {
            if ((string)($_POST['form_action'] ?? '') !== 'save_item_bonus_conditions') {
                throw new RuntimeException('Неизвестное действие.');
            }
            $type = (string)($_POST['scaling_type'] ?? 'none');
            if (!array_key_exists($type, $scalingTypes)) throw new RuntimeException('Некорректный тип масштабирования.');
            $step = max(1, (int)($_POST['scaling_step'] ?? 1));
            $threshold = (int)($_POST['scaling_threshold'] ?? 0);
            if ($threshold < 0) throw new RuntimeException('Порог уровня не может быть отрицательным.');

            $sourceId = intOrNull($_POST['stat_source_id'] ?? null);
            $statStep = intOrNull($_POST['stat_step'] ?? null);
            $statThreshold = intOrNull($_POST['stat_threshold'] ?? null);
            $trueValue = null;

            if (in_array($type, ['per_stat', 'stat_threshold'], true)) {
                if ($sourceId === null || $sourceId <= 0) throw new RuntimeException('Выберите характеристику-источник.');
                assert_bonus_stat($pdo, $sourceId, 'Характеристика-источник не найдена.');
                if ($type === 'per_stat') {
                    if ($statStep === null || $statStep < 1) throw new RuntimeException('Шаг характеристики-источника должен быть больше нуля.');
                    $statThreshold = null;
                } else {
                    if ($statThreshold === null || $statThreshold < 0) throw new RuntimeException('Порог характеристики-источника некорректен.');
                    $trueValue = read_decimal($_POST['stat_value_true'] ?? '', 'Значение при выполненном пороге');
                    $statStep = null;
                }
                if (stat_dependency_would_create_cycle($pdo, $sourceId, (int)$bonus['stat_id'], 'item', $bonusId)) {
                    throw new RuntimeException('Такая зависимость создаёт цикл характеристик.');
                }
            } else {
                $sourceId = null; $statStep = null; $statThreshold = null;
            }

            $classIds = read_class_ids($pdo);
            $conditions = read_item_type_conditions($pdo);
            $mode = read_condition_mode();

            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE item_bonuses SET scaling_type=?, scaling_step=?, scaling_threshold=?,
                    item_type_condition_mode=?, stat_source_id=?, stat_step=?, stat_threshold=?, stat_value_true=?
                    WHERE id=?')->execute([$type, $step, $threshold, $mode, $sourceId, $statStep, $statThreshold, $trueValue, $bonusId]);

                $pdo->prepare('DELETE FROM item_bonus_class_restrictions WHERE item_bonus_id = ?')->execute([$bonusId]);
                if ($classIds) {
                    $st = $pdo->prepare('INSERT INTO item_bonus_class_restrictions (item_bonus_id, class_id) VALUES (?, ?)');
                    foreach ($classIds as $cid) $st->execute([$bonusId, $cid]);
                }
                $pdo->prepare('DELETE FROM item_bonus_item_type_conditions WHERE item_bonus_id = ?')->execute([$bonusId]);
                if ($conditions) {
                    $st = $pdo->prepare('INSERT INTO item_bonus_item_type_conditions (item_bonus_id, item_type_id, required_count) VALUES (?, ?, ?)');
                    foreach ($conditions as $c) $st->execute([$bonusId, $c['item_type_id'], $c['required_count']]);
                }
                $pdo->commit();
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $ex;
            }
            flash('success', 'Условия характеристики сохранены.');
            redirect(admin_url('item_bonus_conditions', ['item_bonus_id' => $bonusId]));
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', friendly_error($ex));
            $values = $_POST;
            $classIds = array_map('intval', (array)($_POST['class_ids'] ?? []));
            $conditionRows = posted_condition_rows();
        }
    }

    if ($values === null) {
        $loaded = (string)$bonus['scaling_type'];
        if (!array_key_exists($loaded, $scalingTypes)) $loaded = 'none';
        $values = [
            'scaling_type' => $loaded,
            'scaling_step' => (int)$bonus['scaling_step'],
            'scaling_threshold' => (int)$bonus['scaling_threshold'],
            'item_type_condition_mode' => (string)$bonus['item_type_condition_mode'],
            'stat_source_id' => $bonus['stat_source_id'] === null ? '' : (int)$bonus['stat_source_id'],
            'stat_step' => $bonus['stat_step'] === null ? '' : (int)$bonus['stat_step'],
            'stat_threshold' => $bonus['stat_threshold'] === null ? '' : (int)$bonus['stat_threshold'],
            'stat_value_true' => $bonus['stat_value_true'] === null ? '' : (string)$bonus['stat_value_true'],
        ];
        $st = $pdo->prepare('SELECT class_id FROM item_bonus_class_restrictions WHERE item_bonus_id = ? ORDER BY class_id');
        $st->execute([$bonusId]);
        $classIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        $st = $pdo->prepare('SELECT item_type_id, required_count FROM item_bonus_item_type_conditions WHERE item_bonus_id = ? ORDER BY item_type_id');
        $st->execute([$bonusId]);
        $conditionRows = $st->fetchAll();
    }

    $values = array_merge([
        'scaling_type' => 'none', 'scaling_step' => 1, 'scaling_threshold' => 0,
        'item_type_condition_mode' => 'all', 'stat_source_id' => '', 'stat_step' => '',
        'stat_threshold' => '', 'stat_value_true' => '',
    ], $values);
    $mode = (string)$values['item_type_condition_mode'];
    $classes = fetch_classes($pdo);
    $itemTypes = fetch_item_types($pdo);
    $stats = fetch_stats($pdo);
    $valueSuffix = $bonus['value_type'] === 'percent' ? '%' : '';

    admin_layout_start('Условия характеристики: ' . $bonus['stat_name'], $action, $menu);
    ?>
<div class="card">
<div class="card-header">
<h1>Условия характеристики</h1>
<div class="actions">
<a class="btn" href="<?= e(admin_url('item_form', ['id' => (int)$bonus['item_id']])) ?>"><?= admin_icon('box', 16) ?>К предмету</a>
<a class="btn" href="<?= e(admin_url('items')) ?>">К предметам</a>
</div>
</div>
<div class="toolbar">
<span class="badge">Предмет: <?= e($bonus['item_name']) ?></span>
<span class="badge badge-ok"><?= e($bonus['stat_name']) ?></span>
<span class="badge badge-warn">Базовое значение: <?= e($bonus['value']) . $valueSuffix ?></span>
<span class="badge">ID бонуса: <?= $bonusId ?></span>
</div>
<p class="muted">
Если условие не выполнено, характеристика показывается в калькуляторе со знаком «?» и пояснением.
Пустые ограничения означают «доступно всем».
</p>
</div>

<form method="post" action="<?= e(admin_url('item_bonus_conditions')) ?>">
<input type="hidden" name="form_action" value="save_item_bonus_conditions">
<input type="hidden" name="item_bonus_id" value="<?= $bonusId ?>">
<?= csrf_field() ?>

<div class="card">
<div class="card-header"><h2>Динамическое значение</h2></div>
<div class="grid">
<div class="field"><label for="scaling_type">Тип масштабирования</label>
<select id="scaling_type" name="scaling_type" required>
<?php foreach ($scalingTypes as $k => $label): ?>
<option value="<?= e($k) ?>"<?= selected((string)$values['scaling_type'] === $k) ?>><?= e($label) ?></option>
<?php endforeach; ?></select></div>
<div class="field" id="cond-step-wrap"><label for="scaling_step">Шаг N уровней</label>
<input type="number" id="scaling_step" name="scaling_step" min="1" value="<?= e($values['scaling_step']) ?>"></div>
<div class="field" id="cond-threshold-wrap"><label for="scaling_threshold">Порог X уровней</label>
<input type="number" id="scaling_threshold" name="scaling_threshold" min="0" value="<?= e($values['scaling_threshold']) ?>"></div>
<div class="field" id="cond-stat-wrap"><label for="stat_source_id">Характеристика-источник</label>
<select id="stat_source_id" name="stat_source_id"><?= stat_options($stats, (int)$values['stat_source_id']) ?></select></div>
<div class="field" id="cond-stat-step-wrap"><label for="stat_step">Шаг N единиц</label>
<input type="number" id="stat_step" name="stat_step" min="1" value="<?= e($values['stat_step']) ?>"></div>
<div class="field" id="cond-stat-threshold-wrap"><label for="stat_threshold">Порог X единиц</label>
<input type="number" id="stat_threshold" name="stat_threshold" min="0" value="<?= e($values['stat_threshold']) ?>"></div>
<div class="field" id="cond-stat-value-wrap"><label for="stat_value_true">Значение при пороге</label>
<input type="number" id="stat_value_true" name="stat_value_true" step="0.01" value="<?= e($values['stat_value_true']) ?>">
<div class="form-help">Подставляется вместо базового значения, когда источник достиг порога.</div></div>
</div>
</div>

<div class="card">
<div class="card-header">
<h2>Ограничение по классам</h2>
<div class="actions"><span class="badge"><?= $classIds ? 'Выбрано: ' . count($classIds) : 'Все классы' ?></span></div>
</div>
<p class="muted">Если не выбран ни один класс, характеристика доступна всем. Базовый класс включает свои подклассы.</p>
<?php class_checkbox_grid($classes, $classIds); ?>
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
<div class="form-actions">
<button type="submit" class="btn btn-primary"><?= admin_icon('check', 16) ?>Сохранить условия</button>
<a href="<?= e(admin_url('item_form', ['id' => (int)$bonus['item_id']])) ?>" class="btn">Отмена</a>
</div>
</div>
</form>
<?php
    admin_layout_end();
}

/* ======================= КАРТЫ ПРЕДМЕТА ======================= */

function item_cards_page(string $action, array $menu): void
{
    $pdo = db();
    $itemId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    if ($itemId <= 0) { flash('error', 'Некорректный ID предмета.'); redirect(admin_url('items')); }

    $st = $pdo->prepare('SELECT i.id, i.name, i.sockets, i.grade, i.item_type_id,
        t.name AS item_type_name, g.name AS grade_name
        FROM items i
        JOIN item_types t ON t.id = i.item_type_id
        LEFT JOIN grades g ON g.code = i.grade
        WHERE i.id = ?');
    $st->execute([$itemId]);
    $item = $st->fetch();
    if (!$item) { flash('error', 'Предмет не найден.'); redirect(admin_url('items')); }

    $socketCount = (int)$item['sockets'];
    $itemGrade = strtoupper((string)$item['grade']);

    $st = $pdo->prepare('SELECT c.id, c.name, ct.name AS card_type_name, ct.socket_number,
        GROUP_CONCAT(cag.grade ORDER BY cag.grade) AS allowed_grades
        FROM cards c
        JOIN card_types ct ON ct.id = c.card_type_id
        JOIN card_allowed_item_types ait ON ait.card_id = c.id
        LEFT JOIN card_allowed_grades cag ON cag.card_id = c.id
        WHERE ait.item_type_id = ?
        GROUP BY c.id, c.name, ct.name, ct.socket_number
        ORDER BY c.name');
    $st->execute([(int)$item['item_type_id']]);
    $allCards = $st->fetchAll();

    $availableBySocket = [];
    for ($s = 1; $s <= $socketCount; $s++) {
        $availableBySocket[$s] = [];
        foreach ($allCards as $c) {
            if ($c['socket_number'] !== null && (int)$c['socket_number'] !== $s) continue;
            $grades = array_filter(array_map('trim', explode(',', (string)($c['allowed_grades'] ?? ''))));
            if ($grades && !in_array($itemGrade, $grades, true)) continue;
            $availableBySocket[$s][(int)$c['id']] = $c;
        }
    }

    $socketValues = [];
    $lockedValues = [];

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        verify_csrf();
        $socketValues = (array)($_POST['socket_card'] ?? []);
        $lockedValues = (array)($_POST['socket_locked'] ?? []);
        try {
            if ((string)($_POST['form_action'] ?? '') !== 'save_item_cards') {
                throw new RuntimeException('Неизвестное действие.');
            }
            $rows = [];
            for ($s = 1; $s <= $socketCount; $s++) {
                $cardId = (int)($socketValues[$s] ?? 0);
                if ($cardId <= 0) continue;
                if (!isset($availableBySocket[$s][$cardId])) {
                    throw new RuntimeException('Карта #' . $cardId . ' не может быть вставлена в слот ' . $s . ' этого предмета.');
                }
                $rows[] = [$itemId, $s, $cardId, isset($lockedValues[$s]) ? 1 : 0];
            }
            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM item_cards WHERE item_id = ?')->execute([$itemId]);
                $st = $pdo->prepare('INSERT INTO item_cards (item_id, socket_number, card_id, is_locked) VALUES (?, ?, ?, ?)');
                foreach ($rows as $r) $st->execute($r);
                $pdo->commit();
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $ex;
            }
            flash('success', 'Карты предмета сохранены.');
            redirect(admin_url('item_cards', ['id' => $itemId]));
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', friendly_error($ex));
        }
    }

    if (!$socketValues) {
        $st = $pdo->prepare('SELECT socket_number, card_id, is_locked FROM item_cards WHERE item_id = ?');
        $st->execute([$itemId]);
        foreach ($st->fetchAll() as $r) {
            $socketValues[(int)$r['socket_number']] = (int)$r['card_id'];
            if ((int)$r['is_locked'] === 1) $lockedValues[(int)$r['socket_number']] = 1;
        }
    }
    $filledCount = 0;
    for ($s = 1; $s <= $socketCount; $s++) if ((int)($socketValues[$s] ?? 0) > 0) $filledCount++;

    admin_layout_start('Карты предмета', $action, $menu);
    ?>
<div class="card">
<div class="card-header">
<h1>Карты предмета</h1>
<div class="actions">
<a class="btn" href="<?= e(admin_url('items')) ?>"><?= admin_icon('box', 16) ?>К предметам</a>
<a class="btn" href="<?= e(admin_url('item_form', ['id' => $itemId])) ?>">Редактировать предмет</a>
</div>
</div>
<div class="toolbar">
<span class="badge badge-ok"><?= e($item['name']) ?></span>
<span class="badge">Тип: <?= e($item['item_type_name']) ?></span>
<span class="badge">Грейд: <?= e($item['grade_name']) ?></span>
<span class="badge <?= $socketCount > 0 ? 'badge-ok' : 'badge-warn' ?>">Слотов: <?= $socketCount ?></span>
<?php if ($socketCount > 0): ?><span class="badge">Заполнено: <?= $filledCount ?></span><?php endif; ?>
</div>
<?php if ($socketCount === 0): ?>
<div class="admin-note">У этого предмета нет слотов — карты вставить нельзя. Измените количество слотов в форме предмета.</div>
<?php else: ?>
<p class="muted">
В списке только карты, совместимые с типом предмета, номером слота и грейдом.
Принудительную карту пользователь не сможет заменить в калькуляторе.
</p>
<?php endif; ?>
</div>

<?php if ($socketCount > 0): ?>
<form method="post" action="<?= e(admin_url('item_cards', ['id' => $itemId])) ?>">
<input type="hidden" name="form_action" value="save_item_cards">
<input type="hidden" name="id" value="<?= $itemId ?>">
<?= csrf_field() ?>
<div class="card">
<div class="card-header"><h2>Слоты</h2></div>
<div class="socket-grid">
<?php for ($s = 1; $s <= $socketCount; $s++): ?>
<div class="socket-row">
<div class="socket-title"><?= admin_icon('layers', 16) ?>Слот <?= $s ?></div>
<div class="field"><label for="socket_card_<?= $s ?>">Карта</label>
<select id="socket_card_<?= $s ?>" name="socket_card[<?= $s ?>]">
<option value="0">— не вставлена —</option>
<?php foreach ($availableBySocket[$s] as $card): ?>
<option value="<?= (int)$card['id'] ?>"<?= selected((int)($socketValues[$s] ?? 0) === (int)$card['id']) ?>><?= e($card['name']) ?> (<?= e($card['card_type_name']) ?>)</option>
<?php endforeach; ?>
</select>
<?php if (!$availableBySocket[$s]): ?><div class="form-help">Нет совместимых карт для этого слота.</div><?php endif; ?>
</div>
<div class="field field-checkbox"><label>
<input type="checkbox" name="socket_locked[<?= $s ?>]" value="1"<?= checked(!empty($lockedValues[$s])) ?>> Принудительная карта</label>
<div class="form-help">Замена на стороне пользователя запрещена.</div></div>
</div>
<?php endfor; ?>
</div>
<div class="form-actions">
<button type="submit" class="btn btn-primary"><?= admin_icon('check', 16) ?>Сохранить карты</button>
<a href="<?= e(admin_url('items')) ?>" class="btn">Отмена</a>
</div>
</div>
</form>
<?php endif; ?>
<?php
    admin_layout_end();
}