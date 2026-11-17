<?php
declare(strict_types=1);
if (!defined('ADMIN_ENTRY')) { http_response_code(403); exit; }

/** @var string $action */
/** @var array $menu */
switch ($action) {
    case 'stamps':        stamps_list_page($action, $menu); break;
    case 'stamp_form':    stamp_form_page($action, $menu); break;
    case 'stamp_bonuses': stamp_bonuses_page($action, $menu); break;
    case 'item_stamp':    item_stamp_page($action, $menu); break;
    default:
        flash('error', 'Неизвестный раздел.');
        redirect(admin_url('stamps'));
}

/* ======================= Хелперы ======================= */
function stamp_color_map(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query('SELECT id, name, hex FROM stamp_colors ORDER BY sort_order, id')->fetchAll() as $row) {
        $out[(int)$row['id']] = $row;
    }
    return $out;
}

function stamp_color_options(array $colors): array
{
    $out = [];
    foreach ($colors as $id => $row) {
        $out[$id] = (string)$row['name'];
    }
    return $out;
}

function stamp_color_badge(array $color): string
{
    return '<span class="badge" style="background:' . e($color['hex']) . '22;color:' . e($color['hex']) . '">'
        . e($color['name']) . '</span>';
}

function stamp_page_counts(PDO $pdo, array $ids): array
{
    return related_counts($pdo, $ids, 'stamp_id', [
        'types'    => "SELECT stamp_id, COUNT(*) c FROM stamp_allowed_item_types WHERE stamp_id IN ({ph}) GROUP BY stamp_id",
        'classes'  => "SELECT stamp_id, COUNT(*) c FROM stamp_class_restrictions WHERE stamp_id IN ({ph}) GROUP BY stamp_id",
        'variants' => "SELECT stamp_id, COUNT(*) c FROM stamp_variants WHERE stamp_id IN ({ph}) GROUP BY stamp_id",
        'used'     => "SELECT v.stamp_id, COUNT(*) c FROM item_stamps i JOIN stamp_variants v ON v.id = i.stamp_variant_id WHERE v.stamp_id IN ({ph}) GROUP BY v.stamp_id",
    ]);
}

/** Варианты, применимые к типу предмета. Пустой список типов = без ограничения. */
function stamp_variants_for_item_type(PDO $pdo, int $itemTypeId): array
{
    $st = $pdo->prepare('
        SELECT v.id, v.sort_order, s.name AS stamp_name, c.name AS color_name, c.hex,
            (SELECT COUNT(*) FROM stamp_variant_bonuses b WHERE b.stamp_variant_id = v.id) AS bonus_count
        FROM stamp_variants v
        JOIN stamps s ON s.id = v.stamp_id
        JOIN stamp_colors c ON c.id = v.color_id
        WHERE NOT EXISTS (SELECT 1 FROM stamp_allowed_item_types a WHERE a.stamp_id = s.id)
           OR EXISTS (SELECT 1 FROM stamp_allowed_item_types a WHERE a.stamp_id = s.id AND a.item_type_id = ?)
        ORDER BY s.sort_order, s.id, c.sort_order, c.id
    ');
    $st->execute([$itemTypeId]);
    return $st->fetchAll();
}

/** Пара коэффициентов для конкретного типа значения (flat / percent). */
function stamp_stat_coefs(array $row, string $valueType): array
{
    if ($valueType === 'percent') {
        return [(float)$row['stamp_percent_min_coef'], (float)$row['stamp_percent_max_coef']];
    }
    return [(float)$row['stamp_flat_min_coef'], (float)$row['stamp_flat_max_coef']];
}

/** Селект характеристик с коэффициентами в data-атрибутах (для подсказки в форме). */
function stamp_stat_options(array $stats, int $selectedId = 0): string
{
    $html = '<option value="0"' . selected($selectedId === 0) . '>— выбрать —</option>';
    foreach ($stats as $s) {
        $id = (int)$s['id'];
        $html .= '<option value="' . $id . '"'
            . ' data-flat="' . (int)$s['supports_flat'] . '"'
            . ' data-percent="' . (int)$s['supports_percent'] . '"'
            . ' data-flat-min="' . e($s['stamp_flat_min_coef']) . '"'
            . ' data-flat-max="' . e($s['stamp_flat_max_coef']) . '"'
            . ' data-percent-min="' . e($s['stamp_percent_min_coef']) . '"'
            . ' data-percent-max="' . e($s['stamp_percent_max_coef']) . '"'
            . selected($selectedId === $id) . '>' . e($s['name_ru']) . '</option>';
    }
    return $html;
}

/* ======================= Подбор качества по максимуму ======================= */
/** Убирает погрешность float перед ceil/trunc (аналог clean в rq-calc). */
function stamp_clean(float $x): float
{
    return round($x * 1e9) / 1e9;
}

/** Значение, которое формула покажет для данного quality при коэффициенте coef. */
function stamp_shown_at(float $quality, float $coef, float $tolerance): float
{
    $raw = stamp_clean($coef * $quality);
    $up = ceil($raw);
    return ($up - $raw < $tolerance) ? $up : floor($raw);
}

/** Коэффициент качества на заданном внутреннем уровне. */
function stamp_coef_at(float $minCoef, float $maxCoef, int $level, int $minLevel, int $maxLevel): float
{
    $span = $maxLevel - $minLevel;
    if ($span <= 0) {
        return $minCoef;
    }
    return $minCoef + ($maxCoef - $minCoef) / $span * max(0, $level - $minLevel);
}

/**
 * Подбирает целое quality, которое формулой даёт ровно введённый максимум
 * на reference-уровне. Если целого нет — возвращает дробную базу.
 */
function stamp_quality_for(float $displayValue, float $minCoef, float $maxCoef, int $refLevel, int $minLevel, int $maxLevel, float $tolerance): string
{
    $coef = stamp_coef_at($minCoef, $maxCoef, $refLevel, $minLevel, $maxLevel);
    if ($coef <= 0) {
        return number_format($displayValue, 2, '.', '');
    }

    // Формула выдаёт целое D, когда coef*q лежит примерно в (D-0.98, D+0.02].
    // Берём середину полосы и подбираем q сразу в точности БД (2 знака),
    // проверяя той же формулой, что и фронт.
    $center = ($displayValue - 0.5) / $coef;
    for ($i = 0; $i <= 5000; $i++) {
        $offsets = $i === 0 ? [0.0] : [$i * 0.01, -$i * 0.01];
        foreach ($offsets as $offset) {
            $candidate = number_format(round($center + $offset, 2), 2, '.', '');
            if (stamp_shown_at((float)$candidate, $coef, $tolerance) === $displayValue) {
                return $candidate;
            }
        }
    }

    throw new RuntimeException(
        'Не удалось подобрать качество для значения ' . $displayValue
        . ' (коэффициент ' . $coef . '). Измените значение или коэффициенты характеристики.'
    );
}

/* ======================= Список печатей ======================= */
function stamps_list_page(string $action, array $menu): void
{
    $pdo = db();
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        verify_csrf();
        try {
            if ((string)($_POST['form_action'] ?? '') !== 'delete_stamp') {
                throw new RuntimeException('Неизвестное действие.');
            }
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Некорректный ID печати.');
            $st = $pdo->prepare('SELECT COUNT(*) FROM item_stamps i JOIN stamp_variants v ON v.id = i.stamp_variant_id WHERE v.stamp_id = ?');
            $st->execute([$id]);
            if ((int)$st->fetchColumn() > 0) {
                throw new RuntimeException('Нельзя удалить печать, варианты которой назначены предметам.');
            }
            $st = $pdo->prepare('DELETE FROM stamps WHERE id = ?');
            $st->execute([$id]);
            if ($st->rowCount() === 0) throw new RuntimeException('Печать не найдена.');
            flash('success', 'Печать удалена вместе с вариантами, ограничениями и характеристиками.');
        } catch (Throwable $ex) {
            flash('error', friendly_error($ex));
        }
        redirect(admin_url('stamps'));
    }

    $q = trim((string)($_GET['q'] ?? ''));
    $perPage = 50;
    $page = page_number();
    $where = ' WHERE 1 = 1';
    $params = [];
    if ($q !== '') {
        $like = '%' . addcslashes($q, '\\%_') . '%';
        $where .= ' AND (s.name LIKE ? OR EXISTS (
            SELECT 1 FROM stamp_variants v
            JOIN stamp_variant_bonuses b ON b.stamp_variant_id = v.id
            JOIN stats st ON st.id = b.stat_id
            WHERE v.stamp_id = s.id AND (st.name_ru LIKE ? OR st.alias LIKE ?)))';
        array_push($params, $like, $like, $like);
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM stamps s' . $where);
    $st->execute($params);
    $total = (int)$st->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));
    if ($page > $pages) $page = $pages;
    $offset = ($page - 1) * $perPage;
    $st = $pdo->prepare('SELECT s.* FROM stamps s' . $where . ' ORDER BY s.sort_order, s.id LIMIT ' . $perPage . ' OFFSET ' . $offset);
    $st->execute($params);
    $stamps = $st->fetchAll();
    $counts = stamp_page_counts($pdo, array_map(static fn($r) => (int)$r['id'], $stamps));
    $filterParams = array_filter(['q' => $q], static fn($v) => $v !== '');
    admin_layout_start('Печати', $action, $menu);
    ?>
    <div class="card">
        <div class="card-header">
            <h1>Печати</h1>
            <div class="actions">
                <span class="badge">Найдено: <?= $total ?></span>
                <a class="btn" href="<?= e(admin_url('stamp_colors')) ?>">Цвета печатей</a>
                <a class="btn btn-primary" href="<?= e(admin_url('stamp_form', ['id' => 0])) ?>"><?= admin_icon('plus', 16) ?>Создать печать</a>
            </div>
        </div>
        <p class="muted">
            Печать накладывается на предмет и даёт характеристики, зависящие от внутреннего уровня предмета и цвета печати.
            Ограничения по классам и типам предметов задаются на печати, а характеристики — отдельно для каждого цвета.
        </p>
        <form method="get" action="<?= e(admin_url('stamps')) ?>" class="filters">
            <input type="hidden" name="action" value="stamps">
            <div class="filter-field">
                <label for="q">Поиск</label>
                <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Название печати или характеристика">
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn btn-primary"><?= admin_icon('search', 16) ?>Применить</button>
                <?php if ($filterParams): ?><a href="<?= e(admin_url('stamps')) ?>" class="btn">Сбросить</a><?php endif; ?>
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
                <th>ID</th><th>Название</th>
                <th>Типы предметов / Классы</th><th>Варианты</th><th>В предметах</th><th>Порядок</th><th>Действия</th>
            </tr></thead>
            <tbody>
            <?php if (!$stamps): ?><tr><td colspan="7">Печатей нет. Создайте первую печать.</td></tr><?php endif; ?>
            <?php foreach ($stamps as $stamp):
                $sid = (int)$stamp['id'];
                $c = $counts[$sid] ?? [];
                $typesCount = (int)($c['types'] ?? 0);
                $classCount = (int)($c['classes'] ?? 0);
                $variantCount = (int)($c['variants'] ?? 0);
                ?>
                <tr>
                    <td><?= $sid ?></td>
                    <td><b><?= e($stamp['name']) ?></b></td>
                    <td>
                        <span class="badge <?= $typesCount ? '' : 'badge-ok' ?>" title="Пусто = все типы"><?= $typesCount ?: 'все' ?></span>
                        <span class="badge <?= $classCount ? '' : 'badge-ok' ?>" title="Пусто = все классы"><?= $classCount ?: 'все' ?></span>
                    </td>
                    <td><?= $variantCount > 0 ? '<span class="badge badge-ok">' . $variantCount . '</span>' : '<span class="badge badge-warn">нет</span>' ?></td>
                    <td><?= (int)($c['used'] ?? 0) ?></td>
                    <td><?= (int)$stamp['sort_order'] ?></td>
                    <td class="actions">
                        <a class="btn" href="<?= e(admin_url('stamp_form', ['id' => $sid])) ?>">Редактировать</a>
                        <form method="post" class="inline js-confirm" data-confirm="Удалить печать «<?= e($stamp['name']) ?>»? Будут удалены её варианты, ограничения и характеристики.">
                            <input type="hidden" name="form_action" value="delete_stamp">
                            <input type="hidden" name="id" value="<?= $sid ?>">
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

/* ======================= Форма печати + варианты ======================= */
function stamp_form_page(string $action, array $menu): void
{
    $pdo = db();
    $values = null;
    $allowedItemTypeIds = [];
    $classIds = [];
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        verify_csrf();
        $formAction = (string)($_POST['form_action'] ?? '');
        $stampId = (int)($_POST['id'] ?? 0);
        try {
            if ($formAction === 'add_variant') {
                if ($stampId <= 0) throw new RuntimeException('Сначала сохраните печать.');
                assert_row_exists($pdo, 'stamps', $stampId, 'Печать не найдена.');
                $colorId = (int)($_POST['color_id'] ?? 0);
                if ($colorId <= 0) throw new RuntimeException('Выберите цвет.');
                assert_row_exists($pdo, 'stamp_colors', $colorId, 'Цвет не найден.');
                $st = $pdo->prepare('SELECT COUNT(*) FROM stamp_variants WHERE stamp_id = ? AND color_id = ?');
                $st->execute([$stampId, $colorId]);
                if ((int)$st->fetchColumn() > 0) throw new RuntimeException('Вариант с этим цветом уже существует.');
                $pdo->prepare('INSERT INTO stamp_variants (stamp_id, color_id, sort_order) VALUES (?, ?, ?)')
                    ->execute([$stampId, $colorId, (int)($_POST['variant_sort_order'] ?? 0)]);
                flash('success', 'Вариант добавлен. Теперь задайте его характеристики.');
                redirect(admin_url('stamp_form', ['id' => $stampId]));
            }
            if ($formAction === 'delete_variant') {
                $variantId = (int)($_POST['variant_id'] ?? 0);
                if ($variantId <= 0) throw new RuntimeException('Некорректный ID варианта.');
                $st = $pdo->prepare('SELECT COUNT(*) FROM item_stamps WHERE stamp_variant_id = ?');
                $st->execute([$variantId]);
                if ((int)$st->fetchColumn() > 0) throw new RuntimeException('Нельзя удалить вариант, назначенный предметам.');
                $st = $pdo->prepare('DELETE FROM stamp_variants WHERE id = ? AND stamp_id = ?');
                $st->execute([$variantId, $stampId]);
                if ($st->rowCount() === 0) throw new RuntimeException('Вариант не найден у этой печати.');
                flash('success', 'Вариант удалён.');
                redirect(admin_url('stamp_form', ['id' => $stampId]));
            }
            if ($formAction !== 'save_stamp') throw new RuntimeException('Неизвестное действие.');

            $name = trim((string)($_POST['name'] ?? ''));
            $sortOrder = (int)($_POST['sort_order'] ?? 0);
            if ($name === '') throw new RuntimeException('Укажите название печати.');
            if (mb_strlen($name) > 150) throw new RuntimeException('Название печати не должно превышать 150 символов.');

            $allowedItemTypeIds = array_values(array_unique(array_map('intval', (array)($_POST['allowed_item_type_ids'] ?? []))));
            $allowedItemTypeIds = array_values(array_intersect($allowedItemTypeIds, array_map('intval', $pdo->query('SELECT id FROM item_types')->fetchAll(PDO::FETCH_COLUMN))));
            $classIds = read_class_ids($pdo);
            if ($stampId > 0) assert_row_exists($pdo, 'stamps', $stampId, 'Печать не найдена.');

            $pdo->beginTransaction();
            try {
                if ($stampId > 0) {
                    $pdo->prepare('UPDATE stamps SET name=?, sort_order=? WHERE id=?')
                        ->execute([$name, $sortOrder, $stampId]);
                } else {
                    $pdo->prepare('INSERT INTO stamps (name, sort_order) VALUES (?,?)')
                        ->execute([$name, $sortOrder]);
                    $stampId = (int)$pdo->lastInsertId();
                }
                $pdo->prepare('DELETE FROM stamp_allowed_item_types WHERE stamp_id = ?')->execute([$stampId]);
                $st = $pdo->prepare('INSERT INTO stamp_allowed_item_types (stamp_id, item_type_id) VALUES (?, ?)');
                foreach ($allowedItemTypeIds as $tid) $st->execute([$stampId, $tid]);
                $pdo->prepare('DELETE FROM stamp_class_restrictions WHERE stamp_id = ?')->execute([$stampId]);
                $st = $pdo->prepare('INSERT INTO stamp_class_restrictions (stamp_id, class_id) VALUES (?, ?)');
                foreach ($classIds as $cid) $st->execute([$stampId, $cid]);

                // Снятие типа предмета делает назначенные печати несовместимыми.
                $removed = 0;
                if ($allowedItemTypeIds) {
                    $ph = implode(',', array_fill(0, count($allowedItemTypeIds), '?'));
                    $st = $pdo->prepare("DELETE ist FROM item_stamps ist
                        JOIN stamp_variants v ON v.id = ist.stamp_variant_id
                        JOIN items i ON i.id = ist.item_id
                        WHERE v.stamp_id = ? AND i.item_type_id NOT IN ($ph)");
                    $st->execute(array_merge([$stampId], $allowedItemTypeIds));
                    $removed = $st->rowCount();
                }
                $pdo->commit();
                $message = 'Печать сохранена.';
                if ($removed > 0) {
                    $message .= ' Снято несовместимых назначений предметов: ' . $removed . '.';
                }
                flash('success', $message);
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $ex;
            }
            redirect(admin_url('stamp_form', ['id' => $stampId]));
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', friendly_error($ex));
            if ($formAction !== 'save_stamp') redirect(admin_url('stamp_form', ['id' => $stampId]));
            $values = $_POST;
            $allowedItemTypeIds = array_map('intval', (array)($_POST['allowed_item_type_ids'] ?? []));
            $classIds = array_map('intval', (array)($_POST['class_ids'] ?? []));
        }
    }

    $id = (int)($_GET['id'] ?? 0);
    $edit = null;
    if ($id > 0) {
        $st = $pdo->prepare('SELECT * FROM stamps WHERE id = ?');
        $st->execute([$id]);
        $edit = $st->fetch();
        if (!$edit) { flash('error', 'Печать не найдена.'); redirect(admin_url('stamps')); }
    }
    $itemTypes = fetch_item_types($pdo);
    $classes = fetch_classes($pdo);
    $colors = stamp_color_map($pdo);
    if ($values === null) {
        $values = $edit ?: [];
        if ($id > 0) {
            $st = $pdo->prepare('SELECT item_type_id FROM stamp_allowed_item_types WHERE stamp_id = ?');
            $st->execute([$id]);
            $allowedItemTypeIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            $st = $pdo->prepare('SELECT class_id FROM stamp_class_restrictions WHERE stamp_id = ? ORDER BY class_id');
            $st->execute([$id]);
            $classIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        }
    }
    $values = array_merge([
        'id' => 0, 'name' => '', 'sort_order' => 0,
    ], $values);
    $currentId = (int)($values['id'] ?? 0);

    $variants = [];
    if ($currentId > 0) {
        $st = $pdo->prepare('
            SELECT v.*, c.name AS color_name, c.hex,
                (SELECT COUNT(*) FROM stamp_variant_bonuses b WHERE b.stamp_variant_id = v.id) AS bonus_count,
                (SELECT COUNT(*) FROM item_stamps i WHERE i.stamp_variant_id = v.id) AS used_count
            FROM stamp_variants v
            JOIN stamp_colors c ON c.id = v.color_id
            WHERE v.stamp_id = ?
            ORDER BY v.sort_order, c.sort_order, c.id
        ');
        $st->execute([$currentId]);
        $variants = $st->fetchAll();
    }
    $usedColorIds = array_map(static fn($v) => (int)$v['color_id'], $variants);
    $freeColors = [];
    foreach ($colors as $cid => $row) {
        if (!in_array($cid, $usedColorIds, true)) $freeColors[$cid] = (string)$row['name'];
    }
    admin_layout_start($currentId > 0 ? 'Редактирование печати' : 'Новая печать', $action, $menu);
    ?>
    <form method="post" action="<?= e(admin_url('stamp_form')) ?>">
        <input type="hidden" name="form_action" value="save_stamp">
        <input type="hidden" name="id" value="<?= $currentId ?>">
        <?= csrf_field() ?>
        <div class="card">
            <div class="card-header">
                <h1><?= $currentId > 0 ? 'Редактирование печати' : 'Новая печать' ?></h1>
                <div class="actions">
                    <?php if ($currentId > 0): ?><span class="badge badge-warn">ID: <?= $currentId ?></span><?php endif; ?>
                    <a class="btn" href="<?= e(admin_url('stamps')) ?>"><?= admin_icon('tags', 16) ?>К печатям</a>
                </div>
            </div>
            <p class="muted">
                Печать — общий шаблон. Характеристики задаются отдельно для каждого цвета в блоке «Варианты» ниже.
                Ограничения действуют на все цвета сразу.
            </p>
            <div class="grid">
                <div class="field"><label for="name">Название</label>
                    <input type="text" id="name" name="name" required maxlength="150" value="<?= e($values['name']) ?>"></div>
                <div class="field"><label for="sort_order">Порядок</label>
                    <input type="number" id="sort_order" name="sort_order" value="<?= e($values['sort_order']) ?>"></div>
            </div>
        </div>
        <div class="card">
            <div class="card-header">
                <h2>Ограничения наложения</h2>
                <div class="actions">
                    <span class="badge <?= $allowedItemTypeIds ? '' : 'badge-ok' ?>">Типов: <?= $allowedItemTypeIds ? count($allowedItemTypeIds) : 'все' ?></span>
                    <span class="badge <?= $classIds ? '' : 'badge-ok' ?>">Классов: <?= $classIds ? count($classIds) : 'все' ?></span>
                </div>
            </div>
            <h3 class="admin-section-title">Типы предметов</h3>
            <p class="muted">Если ничего не выбрано, печать можно наложить на предмет любого типа. При сужении списка несовместимые назначения предметов снимаются.</p>
            <div id="stamp-item-types" class="checkbox-grid">
                <?php foreach ($itemTypes as $t): ?>
                    <label><input type="checkbox" name="allowed_item_type_ids[]" value="<?= (int)$t['id'] ?>"<?= checked(in_array((int)$t['id'], $allowedItemTypeIds, true)) ?>><span><?= e($t['name']) ?></span></label>
                <?php endforeach; ?>
            </div>
            <div class="toolbar">
                <button type="button" class="btn" data-check-all="#stamp-item-types">Выбрать все</button>
                <button type="button" class="btn" data-uncheck-all="#stamp-item-types">Снять все</button>
            </div>
            <h3 class="admin-section-title">Классы</h3>
            <p class="muted">Если не выбран ни один класс, печать доступна всем. Базовый класс включает свои подклассы.</p>
            <?php class_checkbox_grid($classes, $classIds); ?>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= admin_icon('check', 16) ?>Сохранить печать</button>
                <a href="<?= e(admin_url('stamps')) ?>" class="btn">Отмена</a>
            </div>
        </div>
    </form>
    <div class="card">
        <div class="card-header">
            <h2>Варианты по цветам</h2>
            <div class="actions"><span class="badge">Всего: <?= count($variants) ?></span></div>
        </div>
        <?php if ($currentId === 0): ?>
            <div class="admin-note">Варианты станут доступны после сохранения печати.</div>
        <?php else: ?>
            <p class="muted">
                Каждый цвет — отдельный вариант со своим набором характеристик. Цвет нельзя изменить: удалите вариант и создайте новый.
                Удаление варианта снимает его характеристики.
            </p>
            <form method="post" action="<?= e(admin_url('stamp_form', ['id' => $currentId])) ?>" class="filters">
                <input type="hidden" name="form_action" value="add_variant">
                <input type="hidden" name="id" value="<?= $currentId ?>">
                <?= csrf_field() ?>
                <div class="filter-field">
                    <label for="color_id">Цвет</label>
                    <select id="color_id" name="color_id" required<?= $freeColors ? '' : ' disabled' ?>>
                        <option value="">— выбрать —</option>
                        <?php foreach ($freeColors as $cid => $cname): ?>
                            <option value="<?= (int)$cid ?>"><?= e($cname) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$freeColors): ?><div class="form-help">Все цвета справочника уже использованы.</div><?php endif; ?>
                </div>
                <div class="filter-field">
                    <label for="variant_sort_order">Порядок</label>
                    <input type="number" id="variant_sort_order" name="variant_sort_order" value="0">
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary"<?= $freeColors ? '' : ' disabled' ?>><?= admin_icon('plus', 16) ?>Добавить вариант</button>
                </div>
            </form>
            <table>
                <thead><tr><th>ID</th><th>Цвет</th><th>Характеристик</th><th>В предметах</th><th>Порядок</th><th>Действия</th></tr></thead>
                <tbody>
                <?php if (!$variants): ?><tr><td colspan="6">Вариантов нет. Добавьте хотя бы один цвет.</td></tr><?php endif; ?>
                <?php foreach ($variants as $variant): $vid = (int)$variant['id']; ?>
                    <tr>
                        <td><?= $vid ?></td>
                        <td><?= stamp_color_badge(['name' => $variant['color_name'], 'hex' => $variant['hex']]) ?></td>
                        <td><?= (int)$variant['bonus_count'] > 0 ? '<span class="badge badge-ok">' . (int)$variant['bonus_count'] . '</span>' : '<span class="badge badge-warn">нет</span>' ?></td>
                        <td><?= (int)$variant['used_count'] ?></td>
                        <td><?= (int)$variant['sort_order'] ?></td>
                        <td class="actions">
                            <a class="btn" href="<?= e(admin_url('stamp_bonuses', ['variant_id' => $vid])) ?>">Характеристики</a>
                            <form method="post" action="<?= e(admin_url('stamp_form', ['id' => $currentId])) ?>" class="inline js-confirm" data-confirm="Удалить вариант «<?= e($variant['color_name']) ?>»? Его характеристики будут удалены.">
                                <input type="hidden" name="form_action" value="delete_variant">
                                <input type="hidden" name="id" value="<?= $currentId ?>">
                                <input type="hidden" name="variant_id" value="<?= $vid ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-danger"><?= admin_icon('trash', 15) ?>Удалить</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
    admin_layout_end();
}

/* ======================= Характеристики варианта ======================= */
function stamp_bonuses_page(string $action, array $menu): void
{
    $pdo = db();
    $variantId = (int)($_GET['variant_id'] ?? $_POST['variant_id'] ?? 0);
    if ($variantId <= 0) { flash('error', 'Некорректный ID варианта.'); redirect(admin_url('stamps')); }
    $st = $pdo->prepare('
        SELECT v.*, s.id AS stamp_id, s.name AS stamp_name, c.name AS color_name, c.hex
        FROM stamp_variants v
        JOIN stamps s ON s.id = v.stamp_id
        JOIN stamp_colors c ON c.id = v.color_id
        WHERE v.id = ?
    ');
    $st->execute([$variantId]);
    $variant = $st->fetch();
    if (!$variant) { flash('error', 'Вариант печати не найден.'); redirect(admin_url('stamps')); }
    $stampId = (int)$variant['stamp_id'];

    $stats = fetch_stats($pdo);
    $statsById = [];
    foreach ($stats as $s) $statsById[(int)$s['id']] = $s;
    $features = fetch_features($pdo);
    $notes = fetch_text_notes($pdo);
    $states = fetch_states($pdo);
    $classes = fetch_classes($pdo);
    $itemTypes = fetch_item_types($pdo);
    $featureIds = array_map(static fn($f) => (int)$f['id'], $features);
    $noteIds = array_map(static fn($n) => (int)$n['id'], $notes);
    $stateIds = array_map(static fn($s) => (int)$s['id'], $states);
    $minLevel = setting_int('stamp_quality_min_level', 10, 0, 65535);
    $maxLevel = setting_int('stamp_quality_max_level', 60, 1, 65535);
    $refLevel = setting_int('stamp_value_level', 65, 1, 65535);
    $tolerance = (float)setting('stamp_round_tolerance', '0.98');

    $values = null;
    $classIds = [];
    $conditionRows = [];
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        verify_csrf();
        $formAction = (string)($_POST['form_action'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        try {
            if ($formAction === 'delete_stamp_bonus') {
                if ($id <= 0) throw new RuntimeException('Некорректный ID характеристики.');
                $st = $pdo->prepare('DELETE FROM stamp_variant_bonuses WHERE id = ? AND stamp_variant_id = ?');
                $st->execute([$id, $variantId]);
                if ($st->rowCount() === 0) throw new RuntimeException('Характеристика не найдена у этого варианта.');
                flash('success', 'Характеристика удалена.');
                redirect(admin_url('stamp_bonuses', ['variant_id' => $variantId]));
            }
            if ($formAction !== 'save_stamp_bonus') throw new RuntimeException('Неизвестное действие.');

            $statId = (int)($_POST['stat_id'] ?? 0);
            if ($statId <= 0) throw new RuntimeException('Выберите характеристику.');
            $stat = $statsById[$statId] ?? null;
            if (!$stat) throw new RuntimeException('Характеристика не найдена.');
            $valueType = (string)($_POST['value_type'] ?? 'flat');
            if (!in_array($valueType, enum_codes('value_type', 'card'), true)) throw new RuntimeException('Некорректный тип значения.');
            if ($valueType === 'flat' && !(int)$stat['supports_flat']) {
                throw new RuntimeException('Характеристика «' . $stat['name_ru'] . '» не поддерживает плоское значение.');
            }
            if ($valueType === 'percent' && !(int)$stat['supports_percent']) {
                throw new RuntimeException('Характеристика «' . $stat['name_ru'] . '» не поддерживает процентное значение.');
            }

            // Значение максимума. Поддерживаем и старое имя поля quality_value.
            $rawDisplay = trim((string)($_POST['display_value'] ?? ''));
            if ($rawDisplay === '') {
                $rawDisplay = trim((string)($_POST['quality_value'] ?? ''));
            }
            $displayValue = (float)read_decimal($rawDisplay, 'Значение на уровне ' . $refLevel);
            if (floor($displayValue) !== $displayValue) {
                throw new RuntimeException('Значение печати должно быть целым числом: формула качества всегда выдаёт целое.');
            }
            [$minCoef, $maxCoef] = stamp_stat_coefs($stat, $valueType);
            $qualityValue = stamp_quality_for($displayValue, $minCoef, $maxCoef, $refLevel, $minLevel, $maxLevel, $tolerance);
            $displayValueSql = number_format($displayValue, 2, '.', '');

            $featureId = intOrNull($_POST['feature_id'] ?? null);
            if ($featureId !== null && !in_array($featureId, $featureIds, true)) throw new RuntimeException('Функция не найдена.');
            $textNoteId = intOrNull($_POST['text_note_id'] ?? null);
            if ($textNoteId !== null && !in_array($textNoteId, $noteIds, true)) throw new RuntimeException('Текстовая заметка не найдена.');
            $stateId = intOrNull($_POST['state_id'] ?? null);
            if ($stateId !== null && !in_array($stateId, $stateIds, true)) throw new RuntimeException('Состояние не найдено.');
            $sortOrder = (int)($_POST['sort_order'] ?? 0);
            $classIds = read_class_ids($pdo);
            $conditions = read_item_type_conditions($pdo);
            $mode = read_condition_mode();

            if ($id > 0) {
                $st = $pdo->prepare('SELECT COUNT(*) FROM stamp_variant_bonuses WHERE id = ? AND stamp_variant_id = ?');
                $st->execute([$id, $variantId]);
                if (!(int)$st->fetchColumn()) throw new RuntimeException('Характеристика не найдена у этого варианта.');
            }

            $pdo->beginTransaction();
            try {
                $fields = [$statId, $qualityValue, $displayValueSql, $valueType, $featureId, $textNoteId, $stateId, $mode, $sortOrder];
                if ($id > 0) {
                    $pdo->prepare('UPDATE stamp_variant_bonuses SET stat_id=?, quality_value=?, display_value=?, value_type=?,
                        feature_id=?, text_note_id=?, state_id=?, item_type_condition_mode=?, sort_order=?
                        WHERE id=? AND stamp_variant_id=?')->execute([...$fields, $id, $variantId]);
                    $bonusId = $id;
                } else {
                    $pdo->prepare('INSERT INTO stamp_variant_bonuses (stamp_variant_id, stat_id, quality_value, display_value,
                        value_type, feature_id, text_note_id, state_id, item_type_condition_mode, sort_order)
                        VALUES (?,?,?,?,?,?,?,?,?,?)')->execute(array_merge([$variantId], $fields));
                    $bonusId = (int)$pdo->lastInsertId();
                }
                $pdo->prepare('DELETE FROM stamp_bonus_class_restrictions WHERE stamp_bonus_id = ?')->execute([$bonusId]);
                if ($classIds) {
                    $st = $pdo->prepare('INSERT INTO stamp_bonus_class_restrictions (stamp_bonus_id, class_id) VALUES (?,?)');
                    foreach ($classIds as $cid) $st->execute([$bonusId, $cid]);
                }
                $pdo->prepare('DELETE FROM stamp_bonus_item_type_conditions WHERE stamp_bonus_id = ?')->execute([$bonusId]);
                if ($conditions) {
                    $st = $pdo->prepare('INSERT INTO stamp_bonus_item_type_conditions (stamp_bonus_id, item_type_id, required_count) VALUES (?,?,?)');
                    foreach ($conditions as $c) $st->execute([$bonusId, $c['item_type_id'], $c['required_count']]);
                }
                $pdo->commit();
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $ex;
            }
            flash('success', 'Характеристика сохранена. Подобрано качество: ' . $qualityValue
                . ' (коэффициенты ' . $minCoef . ' → ' . $maxCoef . ').');
            redirect(admin_url('stamp_bonuses', ['variant_id' => $variantId]));
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', friendly_error($ex));
            if ($formAction === 'delete_stamp_bonus') redirect(admin_url('stamp_bonuses', ['variant_id' => $variantId]));
            $values = $_POST;
            $classIds = array_map('intval', (array)($_POST['class_ids'] ?? []));
            $conditionRows = posted_condition_rows();
        }
    }

    $id = (int)($_GET['id'] ?? 0);
    $edit = null;
    if ($id > 0) {
        $st = $pdo->prepare('SELECT * FROM stamp_variant_bonuses WHERE id = ? AND stamp_variant_id = ?');
        $st->execute([$id, $variantId]);
        $edit = $st->fetch();
        if (!$edit) { flash('error', 'Характеристика не найдена.'); redirect(admin_url('stamp_bonuses', ['variant_id' => $variantId])); }
    }
    if ($values === null) {
        $values = $edit ?: [];
        if ($id > 0) {
            $st = $pdo->prepare('SELECT class_id FROM stamp_bonus_class_restrictions WHERE stamp_bonus_id = ? ORDER BY class_id');
            $st->execute([$id]);
            $classIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            $st = $pdo->prepare('SELECT item_type_id, required_count FROM stamp_bonus_item_type_conditions WHERE stamp_bonus_id = ? ORDER BY item_type_id');
            $st->execute([$id]);
            $conditionRows = $st->fetchAll();
        }
    }
    $values = array_merge([
        'id' => 0, 'stat_id' => 0, 'quality_value' => '', 'display_value' => '', 'value_type' => 'flat',
        'feature_id' => '', 'text_note_id' => '', 'state_id' => '',
        'item_type_condition_mode' => 'all', 'sort_order' => 0,
    ], $values);
    // Старые записи без display_value: показываем базу как запасной вариант.
    if (($values['display_value'] ?? '') === '' && ($values['quality_value'] ?? '') !== '') {
        $values['display_value'] = $values['quality_value'];
    }
    $currentId = (int)($values['id'] ?? 0);
    $mode = (string)$values['item_type_condition_mode'];

    $st = $pdo->prepare('
        SELECT b.*, s.name_ru AS stat_name,
            s.stamp_flat_min_coef, s.stamp_flat_max_coef,
            s.stamp_percent_min_coef, s.stamp_percent_max_coef,
            f.name AS feature_name, tn.title AS text_note_title, st.name AS state_name
        FROM stamp_variant_bonuses b
        JOIN stats s ON s.id = b.stat_id
        LEFT JOIN features f ON f.id = b.feature_id
        LEFT JOIN text_notes tn ON tn.id = b.text_note_id
        LEFT JOIN states st ON st.id = b.state_id
        WHERE b.stamp_variant_id = ?
        ORDER BY b.sort_order, b.id
    ');
    $st->execute([$variantId]);
    $bonuses = $st->fetchAll();
    $bonusIds = array_map('intval', array_column($bonuses, 'id'));
    $byBonus = ['classes' => [], 'conditions' => []];
    if ($bonusIds) {
        $ph = implode(',', array_fill(0, count($bonusIds), '?'));
        $q = $pdo->prepare("SELECT stamp_bonus_id, class_id FROM stamp_bonus_class_restrictions WHERE stamp_bonus_id IN ($ph) ORDER BY stamp_bonus_id, class_id");
        $q->execute($bonusIds);
        foreach ($q->fetchAll() as $row) $byBonus['classes'][(int)$row['stamp_bonus_id']][] = (int)$row['class_id'];
        $q = $pdo->prepare("SELECT stamp_bonus_id, item_type_id, required_count FROM stamp_bonus_item_type_conditions WHERE stamp_bonus_id IN ($ph) ORDER BY stamp_bonus_id, item_type_id");
        $q->execute($bonusIds);
        foreach ($q->fetchAll() as $row) $byBonus['conditions'][(int)$row['stamp_bonus_id']][] = $row;
    }
    $classNames = [];
    foreach ($classes as $c) $classNames[(int)$c['id']] = (string)$c['name'];
    $typeNames = [];
    foreach ($itemTypes as $t) $typeNames[(int)$t['id']] = (string)$t['name'];
    admin_layout_start('Характеристики варианта: ' . $variant['stamp_name'], $action, $menu);
    ?>
    <div class="card">
        <div class="card-header">
            <h1>Характеристики варианта</h1>
            <div class="actions">
                <span class="badge">Всего: <?= count($bonuses) ?></span>
                <a class="btn" href="<?= e(admin_url('stamp_form', ['id' => $stampId])) ?>"><?= admin_icon('tags', 16) ?>К печати</a>
                <a class="btn" href="<?= e(admin_url('stamps')) ?>">К печатям</a>
            </div>
        </div>
        <div class="toolbar">
            <span class="badge badge-ok"><?= e($variant['stamp_name']) ?></span>
            <?= stamp_color_badge(['name' => $variant['color_name'], 'hex' => $variant['hex']]) ?>
            <span class="badge">Максимум на уровне: <?= $refLevel ?></span>
            <span class="badge">Диапазон уровней: <?= $minLevel ?> – <?= $maxLevel ?></span>
        </div>
        <p class="muted">
            Задаётся значение на внутреннем уровне <?= $refLevel ?> для текущего цвета. Сервер подбирает целое качество,
            которое формулой даёт ровно это число. Коэффициенты берутся из характеристики отдельно для плоского
            и процентного значения. На предмете значение пересчитывается по внутреннему уровню
            (<?= $minLevel ?>–<?= $maxLevel ?>) с округлением как в offline.
        </p>
    </div>
    <form method="post" action="<?= e(admin_url('stamp_bonuses', ['variant_id' => $variantId])) ?>">
        <input type="hidden" name="form_action" value="save_stamp_bonus">
        <input type="hidden" name="variant_id" value="<?= $variantId ?>">
        <input type="hidden" name="id" value="<?= $currentId ?>">
        <?= csrf_field() ?>
        <div class="card">
            <div class="card-header">
                <h2><?= $currentId > 0 ? 'Редактирование характеристики' : 'Новая характеристика' ?></h2>
                <div class="actions">
                    <?php if ($currentId > 0): ?>
                        <span class="badge badge-warn">ID: <?= $currentId ?></span>
                        <a class="btn" href="<?= e(admin_url('stamp_bonuses', ['variant_id' => $variantId])) ?>"><?= admin_icon('close', 16) ?>Отменить правку</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="grid">
                <div class="field"><label for="stat_id">Характеристика</label>
                    <select id="stat_id" name="stat_id" required><?= stamp_stat_options($stats, (int)$values['stat_id']) ?></select></div>
                <div class="field"><label for="display_value">Значение на уровне <?= $refLevel ?></label>
                    <input type="number" id="display_value" name="display_value" step="1" required value="<?= e($values['display_value']) ?>">
                    <div class="form-help">Максимум для этого цвета. Сервер сам подберёт целое качество (quality_value).</div>
                    <div class="form-help" id="stamp-coef-hint"></div></div>
                <div class="field"><label for="value_type">Тип значения</label>
                    <select id="value_type" name="value_type" required><?= enum_select('value_type', 'card', (string)$values['value_type']) ?></select></div>
                <div class="field"><label for="feature_id">Функция</label>
                    <select id="feature_id" name="feature_id"><?= feature_options($features, intOrNull($values['feature_id'])) ?></select>
                    <div class="form-help">Характеристика активна только когда функция включена.</div></div>
                <div class="field"><label for="text_note_id">Доп. текст</label>
                    <select id="text_note_id" name="text_note_id"><?= text_note_options($notes, intOrNull($values['text_note_id'])) ?></select></div>
                <div class="field"><label for="state_id">Состояние</label>
                    <select id="state_id" name="state_id"<?= $states ? '' : ' disabled' ?>><?= state_options($states, intOrNull($values['state_id'])) ?></select></div>
                <div class="field"><label for="sort_order">Порядок</label>
                    <input type="number" id="sort_order" name="sort_order" value="<?= e($values['sort_order']) ?>"></div>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h2>Ограничение по классам</h2></div>
            <p class="muted">Если не выбран ни один класс, характеристика доступна всем. Базовый класс включает свои подклассы.</p>
            <?php class_checkbox_grid($classes, $classIds); ?>
        </div>
        <div class="card">
            <?php render_item_type_conditions($mode, $itemTypes, $conditionRows); ?>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= admin_icon('check', 16) ?><?= $currentId > 0 ? 'Сохранить характеристику' : 'Добавить характеристику' ?></button>
                <?php if ($currentId > 0): ?><a href="<?= e(admin_url('stamp_bonuses', ['variant_id' => $variantId])) ?>" class="btn">Отмена</a><?php endif; ?>
            </div>
        </div>
    </form>
    <div class="card">
        <div class="card-header">
            <h2>Список характеристик</h2>
            <div class="actions"><span class="badge">Всего: <?= count($bonuses) ?></span></div>
        </div>
        <table>
            <thead><tr>
                <th>ID</th><th>Характеристика</th><th>Максимум</th><th>Качество</th><th>Тип</th><th>Коэффициенты</th>
                <th>Функция</th><th>Доп. текст</th><th>Состояние</th><th>Ограничения и условия</th><th>Порядок</th><th>Действия</th>
            </tr></thead>
            <tbody>
            <?php if (!$bonuses): ?><tr><td colspan="12">Характеристик нет. Добавьте первую через форму выше.</td></tr><?php endif; ?>
            <?php foreach ($bonuses as $b):
                $bid = (int)$b['id'];
                $bClasses = $byBonus['classes'][$bid] ?? [];
                $bConditions = $byBonus['conditions'][$bid] ?? [];
                [$bMin, $bMax] = stamp_stat_coefs($b, (string)$b['value_type']);
                ?>
                <tr>
                    <td><?= $bid ?></td>
                    <td><b><?= e($b['stat_name']) ?></b></td>
                    <td><?= e($b['display_value'] ?? '—') ?></td>
                    <td><?= e($b['quality_value']) ?></td>
                    <td><?= e(enum_label('value_type', (string)$b['value_type'])) ?></td>
                    <td><?= e($bMin) ?> → <?= e($bMax) ?></td>
                    <td><?= e($b['feature_name'] ?? '—') ?></td>
                    <td><?= e($b['text_note_title'] ?? '—') ?></td>
                    <td><?= e($b['state_name'] ?? '—') ?></td>
                    <td>
                        <?php
                        render_bonus_conditions($b, $bClasses, $bConditions, $classNames, $typeNames);
                        ?>
                    </td>
                    <td><?= (int)$b['sort_order'] ?></td>
                    <td class="actions">
                        <?php if ($currentId > 0 && $bid === $currentId): ?><span class="badge badge-warn">правится</span><?php endif; ?>
                        <a class="btn" href="<?= e(admin_url('stamp_bonuses', ['variant_id' => $variantId, 'id' => $bid])) ?>">Редактировать</a>
                        <form method="post" action="<?= e(admin_url('stamp_bonuses', ['variant_id' => $variantId])) ?>" class="inline js-confirm" data-confirm="Удалить характеристику «<?= e($b['stat_name']) ?>»?">
                            <input type="hidden" name="form_action" value="delete_stamp_bonus">
                            <input type="hidden" name="variant_id" value="<?= $variantId ?>">
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

/* ======================= Печать предмета ======================= */
function item_stamp_page(string $action, array $menu): void
{
    $pdo = db();
    $itemId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    if ($itemId <= 0) { flash('error', 'Некорректный ID предмета.'); redirect(admin_url('items')); }
    $st = $pdo->prepare('
        SELECT i.id, i.name, i.item_type_id, i.required_level, i.internal_level,
            t.name AS item_type_name, g.name AS grade_name
        FROM items i
        JOIN item_types t ON t.id = i.item_type_id
        LEFT JOIN grades g ON g.code = i.grade
        WHERE i.id = ?
    ');
    $st->execute([$itemId]);
    $item = $st->fetch();
    if (!$item) { flash('error', 'Предмет не найден.'); redirect(admin_url('items')); }
    $variants = stamp_variants_for_item_type($pdo, (int)$item['item_type_id']);
    $variantById = [];
    foreach ($variants as $v) $variantById[(int)$v['id']] = $v;
    $assignedVariantId = 0;
    $isLocked = 0;
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        verify_csrf();
        $assignedVariantId = (int)($_POST['stamp_variant_id'] ?? 0);
        $isLocked = isset($_POST['is_locked']) ? 1 : 0;
        try {
            if ((string)($_POST['form_action'] ?? '') !== 'save_item_stamp') {
                throw new RuntimeException('Неизвестное действие.');
            }
            if ($assignedVariantId > 0 && !isset($variantById[$assignedVariantId])) {
                throw new RuntimeException('Эта печать не может быть наложена на предмет данного типа.');
            }
            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM item_stamps WHERE item_id = ?')->execute([$itemId]);
                if ($assignedVariantId > 0) {
                    $pdo->prepare('INSERT INTO item_stamps (item_id, stamp_variant_id, is_locked) VALUES (?, ?, ?)')
                        ->execute([$itemId, $assignedVariantId, $isLocked]);
                }
                $pdo->commit();
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $ex;
            }
            flash('success', 'Печать предмета сохранена.');
            redirect(admin_url('item_stamp', ['id' => $itemId]));
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', friendly_error($ex));
        }
    }
    if ($assignedVariantId === 0) {
        $st = $pdo->prepare('SELECT stamp_variant_id, is_locked FROM item_stamps WHERE item_id = ?');
        $st->execute([$itemId]);
        $row = $st->fetch();
        if ($row) {
            $assignedVariantId = (int)$row['stamp_variant_id'];
            $isLocked = (int)$row['is_locked'];
        }
    }
    $effectiveLevel = $item['internal_level'] !== null
        ? (int)$item['internal_level']
        : ($item['required_level'] !== null ? (int)$item['required_level'] : null);
    $classRestrictions = [];
    if ($variants) {
        $ids = array_map(static fn($v) => (int)$v['id'], $variants);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("
            SELECT v.id, c.name
            FROM stamp_variants v
            JOIN stamp_class_restrictions r ON r.stamp_id = v.stamp_id
            JOIN classes c ON c.id = r.class_id
            WHERE v.id IN ($ph)
            ORDER BY v.id, c.name
        ");
        $st->execute($ids);
        foreach ($st->fetchAll() as $row) {
            $classRestrictions[(int)$row['id']][] = (string)$row['name'];
        }
    }
    admin_layout_start('Печать предмета', $action, $menu);
    ?>
    <div class="card">
        <div class="card-header">
            <h1>Печать предмета</h1>
            <div class="actions">
                <a class="btn" href="<?= e(admin_url('items')) ?>"><?= admin_icon('box', 16) ?>К предметам</a>
                <a class="btn" href="<?= e(admin_url('item_form', ['id' => $itemId])) ?>">Редактировать предмет</a>
            </div>
        </div>
        <div class="toolbar">
            <span class="badge badge-ok"><?= e($item['name']) ?></span>
            <span class="badge">Тип: <?= e($item['item_type_name']) ?></span>
            <span class="badge">Грейд: <?= e($item['grade_name'] ?? 'без грейда') ?></span>
            <span class="badge">Уровень для печати: <?= $effectiveLevel === null ? 'не задан' : $effectiveLevel ?></span>
        </div>
        <?php if ($effectiveLevel === null): ?>
            <div class="admin-note">У предмета не задан ни внутренний, ни требуемый уровень — качество печати будет рассчитано по нижней границе диапазона.</div>
        <?php endif; ?>
        <p class="muted">
            На предмет можно наложить одну печать. В списке только печати, разрешённые для этого типа предмета.
            Принудительную печать пользователь не сможет заменить в калькуляторе.
        </p>
    </div>
    <form method="post" action="<?= e(admin_url('item_stamp', ['id' => $itemId])) ?>">
        <input type="hidden" name="form_action" value="save_item_stamp">
        <input type="hidden" name="id" value="<?= $itemId ?>">
        <?= csrf_field() ?>
        <div class="card">
            <div class="card-header"><h2>Назначение</h2></div>
            <div class="grid">
                <div class="field field-wide"><label for="stamp_variant_id">Печать</label>
                    <select id="stamp_variant_id" name="stamp_variant_id">
                        <option value="0">— не наложена —</option>
                        <?php foreach ($variants as $v): $vid = (int)$v['id']; ?>
                            <option value="<?= $vid ?>"<?= selected($assignedVariantId === $vid) ?>><?= e($v['stamp_name']) ?> · <?= e($v['color_name']) ?> (<?= (int)$v['bonus_count'] ?> хар.)</option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$variants): ?><div class="form-help">Нет печатей, разрешённых для этого типа предмета.</div><?php endif; ?>
                </div>
                <div class="field field-checkbox"><label>
                    <input type="checkbox" name="is_locked" value="1"<?= checked($isLocked === 1) ?>> Принудительная печать</label>
                    <div class="form-help">Замена на стороне пользователя запрещена.</div></div>
            </div>
            <?php if ($classRestrictions): ?>
                <div class="admin-note">
                    Ограничения по классам у доступных печатей:
                    <?php foreach ($classRestrictions as $vid => $names): ?>
                        <br><b><?= e($variantById[$vid]['stamp_name'] ?? ('#' . $vid)) ?> · <?= e($variantById[$vid]['color_name'] ?? '') ?></b>: <?= e(implode(', ', $names)) ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= admin_icon('check', 16) ?>Сохранить печать</button>
                <a href="<?= e(admin_url('items')) ?>" class="btn">Отмена</a>
            </div>
        </div>
    </form>
    <?php
    admin_layout_end();
}