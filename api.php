<?php
require 'config.php';

header('Content-Type: application/json; charset=utf-8');

function in_placeholders(array $values): string
{
    return implode(',', array_fill(0, count($values), '?'));
}

function normalize_id_list($value): array
{
    if ($value === null || $value === '') {
        return [];
    }

    if (is_array($value)) {
        $ids = $value;
    } else {
        $ids = preg_split('/[\s,]+/', (string)$value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

$ids = array_map('intval', $ids);
$ids = array_filter($ids, static fn($id) => $id > 0);
$ids = array_values(array_unique($ids));
if (count($ids) > 300) {
throw new RuntimeException('Слишком много ID в запросе (максимум 300).');
}
return $ids;
}

function cast_int_or_null($value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    return (int)$value;
}

function cast_float_or_null($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    return (float)$value;
}

function attach_item_bonus_conditions(PDO $pdo, array &$bonuses): void
{
    if (!$bonuses) {
        return;
    }

    $ids = array_values(array_unique(array_map('intval', array_column($bonuses, 'id'))));

    if (!$ids) {
        return;
    }

    $placeholders = in_placeholders($ids);

    $classStmt = $pdo->prepare("
        SELECT item_bonus_id, class_id
        FROM item_bonus_class_restrictions
        WHERE item_bonus_id IN ($placeholders)
        ORDER BY item_bonus_id, class_id
    ");
    $classStmt->execute($ids);

    $classMap = [];

    foreach ($classStmt->fetchAll() as $row) {
        $classMap[(int)$row['item_bonus_id']][] = (int)$row['class_id'];
    }

    $conditionStmt = $pdo->prepare("
        SELECT item_bonus_id, item_type_id, required_count
        FROM item_bonus_item_type_conditions
        WHERE item_bonus_id IN ($placeholders)
        ORDER BY item_bonus_id, item_type_id
    ");
    $conditionStmt->execute($ids);

    $conditionMap = [];

    foreach ($conditionStmt->fetchAll() as $row) {
        $conditionMap[(int)$row['item_bonus_id']][] = [
            'item_type_id' => (int)$row['item_type_id'],
            'required_count' => (int)$row['required_count'],
        ];
    }

    foreach ($bonuses as &$bonus) {
        $id = (int)$bonus['id'];

        $bonus['class_ids'] = $classMap[$id] ?? [];
        $bonus['item_type_conditions'] = $conditionMap[$id] ?? [];
        $bonus['item_type_condition_mode'] = (string)($bonus['item_type_condition_mode'] ?? 'all');

        $bonus['state_id'] = cast_int_or_null($bonus['state_id'] ?? null);
        $bonus['scaling_type'] = (string)($bonus['scaling_type'] ?? 'none');
        $bonus['scaling_step'] = (int)($bonus['scaling_step'] ?? 1);
        $bonus['scaling_threshold'] = (int)($bonus['scaling_threshold'] ?? 0);

        $bonus['stat_source_id'] = cast_int_or_null($bonus['stat_source_id'] ?? null);
        $bonus['stat_step'] = cast_int_or_null($bonus['stat_step'] ?? null);
        $bonus['stat_threshold'] = cast_int_or_null($bonus['stat_threshold'] ?? null);
        $bonus['stat_value_true'] = cast_float_or_null($bonus['stat_value_true'] ?? null);
    }

    unset($bonus);
}

function attach_card_bonus_conditions(PDO $pdo, array &$bonuses): void
{
    if (!$bonuses) {
        return;
    }

    $ids = array_values(array_unique(array_map('intval', array_column($bonuses, 'id'))));

    if (!$ids) {
        return;
    }

    $placeholders = in_placeholders($ids);

    $classStmt = $pdo->prepare("
        SELECT card_bonus_id, class_id
        FROM card_bonus_class_restrictions
        WHERE card_bonus_id IN ($placeholders)
        ORDER BY card_bonus_id, class_id
    ");
    $classStmt->execute($ids);

    $classMap = [];

    foreach ($classStmt->fetchAll() as $row) {
        $classMap[(int)$row['card_bonus_id']][] = (int)$row['class_id'];
    }

    $restrictionStmt = $pdo->prepare("
        SELECT card_bonus_id, item_type_id
        FROM card_bonus_item_type_restrictions
        WHERE card_bonus_id IN ($placeholders)
        ORDER BY card_bonus_id, item_type_id
    ");
    $restrictionStmt->execute($ids);

    $restrictionMap = [];

    foreach ($restrictionStmt->fetchAll() as $row) {
        $restrictionMap[(int)$row['card_bonus_id']][] = (int)$row['item_type_id'];
    }

    $conditionStmt = $pdo->prepare("
        SELECT card_bonus_id, item_type_id, required_count
        FROM card_bonus_item_type_conditions
        WHERE card_bonus_id IN ($placeholders)
        ORDER BY card_bonus_id, item_type_id
    ");
    $conditionStmt->execute($ids);

    $conditionMap = [];

    foreach ($conditionStmt->fetchAll() as $row) {
        $conditionMap[(int)$row['card_bonus_id']][] = [
            'item_type_id' => (int)$row['item_type_id'],
            'required_count' => (int)$row['required_count'],
        ];
    }

    $sourceStmt = $pdo->prepare("
        SELECT
            card_bonus_id,
            stat_id,
            step
        FROM card_bonus_stat_sources
        WHERE card_bonus_id IN ($placeholders)
        ORDER BY sort_order, id
    ");
    $sourceStmt->execute($ids);

    $sourceMap = [];

    foreach ($sourceStmt->fetchAll() as $row) {
        $sourceMap[(int)$row['card_bonus_id']][] = [
            'stat_id' => (int)$row['stat_id'],
            'step'    => max(1, (int)$row['step']),
        ];
    }

    foreach ($bonuses as &$bonus) {
        $id = (int)$bonus['id'];

        $bonus['class_ids'] = $classMap[$id] ?? [];
        $bonus['item_type_restrictions'] = $restrictionMap[$id] ?? [];
        $bonus['item_type_conditions'] = $conditionMap[$id] ?? [];
        $bonus['item_type_condition_mode'] = (string)($bonus['item_type_condition_mode'] ?? 'all');

        $bonus['state_id'] = cast_int_or_null($bonus['state_id'] ?? null);
        $bonus['scaling_type'] = (string)($bonus['scaling_type'] ?? 'none');
        $bonus['scaling_step'] = (int)($bonus['scaling_step'] ?? 1);
        $bonus['scaling_threshold'] = (int)($bonus['scaling_threshold'] ?? 0);
        $bonus['scaling_required_count'] = cast_int_or_null($bonus['scaling_required_count'] ?? null);
        $bonus['scaling_multiplier'] = cast_float_or_null($bonus['scaling_multiplier'] ?? null);

        $bonus['stat_source_id'] = cast_int_or_null($bonus['stat_source_id'] ?? null);
        $bonus['stat_step'] = cast_int_or_null($bonus['stat_step'] ?? null);
        $bonus['stat_threshold'] = cast_int_or_null($bonus['stat_threshold'] ?? null);
        $bonus['stat_value_true'] = cast_float_or_null($bonus['stat_value_true'] ?? null);

        $bonus['stat_condition_mode'] = in_array(
            (string)($bonus['stat_condition_mode'] ?? 'all'),
            ['all', 'any'],
            true
        )
            ? (string)$bonus['stat_condition_mode']
            : 'all';

        $bonus['stat_sources'] = $sourceMap[$id] ?? [];
    }

    unset($bonus);
}

function fetch_item_bonuses_grouped(PDO $pdo, array $itemIds): array
{
    if (!$itemIds) {
        return [];
    }

    $placeholders = in_placeholders($itemIds);

    $stmt = $pdo->prepare("
        SELECT
            b.id,
            b.item_id,
            b.stat_id,
            s.name_ru,
            s.slug,
            s.alias AS stat_alias,
            b.value,
            b.value_type,
            b.feature_id,
            b.text_note_id,
            tn.`text` AS text_note_text,
            b.state_id,
            b.scaling_type,
            b.scaling_step,
            b.scaling_threshold,
            b.item_type_condition_mode,
            b.stat_source_id,
            b.stat_step,
            b.stat_threshold,
            b.stat_value_true,
            b.sort_order
        FROM item_bonuses b
        JOIN stats s ON s.id = b.stat_id
        LEFT JOIN text_notes tn ON tn.id = b.text_note_id
        WHERE b.item_id IN ($placeholders)
        ORDER BY b.item_id, b.sort_order, b.id
    ");

    $stmt->execute($itemIds);
    $bonuses = $stmt->fetchAll();

    foreach ($bonuses as &$bonus) {
        $bonus['id'] = (int)$bonus['id'];
        $bonus['item_id'] = (int)$bonus['item_id'];
        $bonus['stat_id'] = (int)$bonus['stat_id'];
        $bonus['value'] = (float)$bonus['value'];
        $bonus['feature_id'] = cast_int_or_null($bonus['feature_id']);
        $bonus['text_note_id'] = cast_int_or_null($bonus['text_note_id']);
    }

    unset($bonus);

    attach_item_bonus_conditions($pdo, $bonuses);

    $grouped = [];

    foreach ($bonuses as $bonus) {
        $grouped[(int)$bonus['item_id']][] = $bonus;
    }

    return $grouped;
}

function build_items_payload(PDO $pdo, array $items): array
{
    if (!$items) {
        return [];
    }

    $ids = array_values(array_unique(array_map(
        static fn($item) => (int)$item['id'],
        $items
    )));

    $bonusesByItem = fetch_item_bonuses_grouped($pdo, $ids);

    foreach ($items as &$item) {
        $itemId = (int)$item['id'];

        $item['id'] = $itemId;
        $item['item_type_id'] = (int)$item['item_type_id'];
        $item['required_level'] = cast_int_or_null($item['required_level'] ?? null);
$item['internal_level'] = cast_int_or_null($item['internal_level'] ?? null);
        $item['base_defense'] = (int)$item['base_defense'];
        $item['base_attack'] = (int)$item['base_attack'];
        $item['base_attack_speed'] = (float)$item['base_attack_speed'];
        $item['base_dps'] = (float)$item['base_dps'];
        $item['sockets'] = (int)$item['sockets'];
        $item['can_upgrade'] = (int)$item['can_upgrade'];
        $item['state_id'] = cast_int_or_null($item['state_id'] ?? null);
        $item['description_feature_id'] = cast_int_or_null($item['description_feature_id'] ?? null);
        $item['bonuses'] = $bonusesByItem[$itemId] ?? [];
    }

    unset($item);

    return $items;
}

function fetch_card_bonuses_grouped(PDO $pdo, array $cardIds): array
{
    if (!$cardIds) {
        return [];
    }

    $placeholders = in_placeholders($cardIds);

    $stmt = $pdo->prepare("
        SELECT
            b.id,
            b.card_id,
            b.stat_id,
            s.name_ru,
            s.slug,
            s.alias AS stat_alias,
            b.value,
            b.value_max,
            b.value_type,
            b.feature_id,
            b.text_note_id,
            tn.`text` AS text_note_text,
            b.state_id,
            b.depends_on_card_id,
            b.scaling_type,
            b.scaling_step,
            b.scaling_threshold,
            b.scaling_required_count,
            b.scaling_multiplier,
            b.item_type_condition_mode,
            b.stat_source_id,
            b.stat_step,
            b.stat_threshold,
            b.stat_value_true,
            b.stat_condition_mode,
            b.sort_order
        FROM card_bonuses b
        JOIN stats s ON s.id = b.stat_id
        LEFT JOIN text_notes tn ON tn.id = b.text_note_id
        WHERE b.card_id IN ($placeholders)
        ORDER BY b.card_id, b.sort_order, b.id
    ");

    $stmt->execute($cardIds);
    $bonuses = $stmt->fetchAll();

    foreach ($bonuses as &$bonus) {
        $bonus['id'] = (int)$bonus['id'];
        $bonus['card_id'] = (int)$bonus['card_id'];
        $bonus['stat_id'] = (int)$bonus['stat_id'];
        $bonus['value'] = (float)$bonus['value'];
        $bonus['value_max'] = cast_float_or_null($bonus['value_max']);
        $bonus['feature_id'] = cast_int_or_null($bonus['feature_id']);
        $bonus['text_note_id'] = cast_int_or_null($bonus['text_note_id']);
        $bonus['depends_on_card_id'] = cast_int_or_null($bonus['depends_on_card_id']);
    }

    unset($bonus);

    attach_card_bonus_conditions($pdo, $bonuses);

    $bonusIds = array_values(array_unique(array_map('intval', array_column($bonuses, 'id'))));
    $overridesByBonus = [];

    if ($bonusIds) {
        $bonusPlaceholders = in_placeholders($bonusIds);

        $overrideStmt = $pdo->prepare("
            SELECT
                card_bonus_id,
                item_type_id,
                `value`,
                value_max
            FROM card_bonus_item_type_overrides
            WHERE card_bonus_id IN ($bonusPlaceholders)
            ORDER BY card_bonus_id, item_type_id
        ");

        $overrideStmt->execute($bonusIds);
        $overrides = $overrideStmt->fetchAll();

        foreach ($overrides as $override) {
            $override['value'] = (float)$override['value'];
            $override['value_max'] = cast_float_or_null($override['value_max']);

            $overridesByBonus[(int)$override['card_bonus_id']][] = [
                'item_type_id' => (int)$override['item_type_id'],
                'value' => $override['value'],
                'value_max' => $override['value_max'],
            ];
        }
    }

    $grouped = [];

    foreach ($bonuses as $bonus) {
        $bonus['overrides'] = $overridesByBonus[(int)$bonus['id']] ?? [];
        $grouped[(int)$bonus['card_id']][] = $bonus;
    }

    return $grouped;
}

function get_cards_by_ids(PDO $pdo, array $cardIds): array
{
    $cardIds = array_values(array_unique(array_filter(array_map('intval', $cardIds), static fn($id) => $id > 0)));

    if (!$cardIds) {
        return [];
    }

    $placeholders = in_placeholders($cardIds);

    $stmt = $pdo->prepare("
        SELECT
            c.id,
            c.name,
            c.icon_path,
            c.card_type_id,
            ct.name AS card_type_name,
            ct.socket_number AS card_type_socket_number,
            c.set_id,
            cs.name AS set_name,
            cs.negative_threshold,
            cs.negative_below_mode,
            cs.negative_reached_mode,
            c.is_set_main,
            c.state_id,
            c.sort_order
        FROM cards c
        JOIN card_types ct ON ct.id = c.card_type_id
        LEFT JOIN card_sets cs ON cs.id = c.set_id
        WHERE c.id IN ($placeholders)
        ORDER BY c.sort_order, c.id
    ");

    $stmt->execute($cardIds);
    $cards = $stmt->fetchAll();

    $bonusesByCard = fetch_card_bonuses_grouped($pdo, $cardIds);

    $allowedStmt = $pdo->prepare("
        SELECT card_id, item_type_id
        FROM card_allowed_item_types
        WHERE card_id IN ($placeholders)
        ORDER BY card_id, item_type_id
    ");

    $allowedStmt->execute($cardIds);
    $allowedRows = $allowedStmt->fetchAll();

$allowedByCard = [];
foreach ($allowedRows as $row) {
$allowedByCard[(int)$row['card_id']][] = (int)$row['item_type_id'];
}
$gradeStmt = $pdo->prepare("
SELECT card_id, grade
FROM card_allowed_grades
WHERE card_id IN ($placeholders)
ORDER BY card_id, grade
");
$gradeStmt->execute($cardIds);
$gradesByCard = [];
foreach ($gradeStmt->fetchAll() as $row) {
$gradesByCard[(int)$row['card_id']][] = (string)$row['grade'];
}

    $setIds = array_values(array_unique(array_filter(array_map(
        static fn($card) => cast_int_or_null($card['set_id']),
        $cards
    ), static fn($id) => $id !== null && $id > 0)));

    $setBonusesBySet = [];

    if ($setIds) {
        $setPlaceholders = in_placeholders($setIds);

        $setBonusStmt = $pdo->prepare("
            SELECT
                b.id,
                b.set_id,
                b.required_count,
                b.stat_id,
                s.name_ru,
                s.slug,
                b.value,
                b.value_type,
                b.sort_order
            FROM card_set_bonuses b
            JOIN stats s ON s.id = b.stat_id
            WHERE b.set_id IN ($setPlaceholders)
            ORDER BY b.set_id, b.required_count, b.sort_order, b.id
        ");

        $setBonusStmt->execute($setIds);
        $setBonuses = $setBonusStmt->fetchAll();

        foreach ($setBonuses as $setBonus) {
            $setBonus['id'] = (int)$setBonus['id'];
            $setBonus['set_id'] = (int)$setBonus['set_id'];
            $setBonus['required_count'] = (int)$setBonus['required_count'];
            $setBonus['stat_id'] = (int)$setBonus['stat_id'];
            $setBonus['value'] = (float)$setBonus['value'];

            $setBonusesBySet[(int)$setBonus['set_id']][] = $setBonus;
        }
    }

    foreach ($cards as &$card) {
        $cardId = (int)$card['id'];
        $setId = cast_int_or_null($card['set_id']);

        $card['id'] = $cardId;
        $card['card_type_id'] = (int)$card['card_type_id'];
        $card['card_type_socket_number'] = cast_int_or_null($card['card_type_socket_number']);
        $card['set_id'] = $setId;
        $card['is_set_main'] = (int)$card['is_set_main'];
        $card['state_id'] = cast_int_or_null($card['state_id'] ?? null);
        $card['sort_order'] = (int)$card['sort_order'];

$card['allowed_grades'] = $gradesByCard[$cardId] ?? [];

        $card['bonuses'] = $bonusesByCard[$cardId] ?? [];
        $card['allowed_item_type_ids'] = $allowedByCard[$cardId] ?? [];

        if ($setId !== null) {
            $card['set'] = [
                'id' => $setId,
                'name' => $card['set_name'],
                'negative_threshold' => (int)$card['negative_threshold'],
                'negative_below_mode' => (string)$card['negative_below_mode'],
                'negative_reached_mode' => (string)$card['negative_reached_mode'],
                'bonuses' => $setBonusesBySet[$setId] ?? [],
            ];
        } else {
            $card['set'] = null;
        }

        unset(
            $card['set_name'],
            $card['negative_threshold'],
            $card['negative_below_mode'],
            $card['negative_reached_mode']
        );
    }

    unset($card);

    return $cards;
}

function attach_stamp_bonus_conditions(PDO $pdo, array &$bonuses): void
{
    if (!$bonuses) {
        return;
    }
    $ids = array_values(array_unique(array_map('intval', array_column($bonuses, 'id'))));
    if (!$ids) {
        return;
    }
    $ph = in_placeholders($ids);

    $st = $pdo->prepare("SELECT stamp_bonus_id, class_id FROM stamp_bonus_class_restrictions WHERE stamp_bonus_id IN ($ph) ORDER BY stamp_bonus_id, class_id");
    $st->execute($ids);
    $classMap = [];
    foreach ($st->fetchAll() as $row) {
        $classMap[(int)$row['stamp_bonus_id']][] = (int)$row['class_id'];
    }

    $st = $pdo->prepare("SELECT stamp_bonus_id, item_type_id, required_count FROM stamp_bonus_item_type_conditions WHERE stamp_bonus_id IN ($ph) ORDER BY stamp_bonus_id, item_type_id");
    $st->execute($ids);
    $conditionMap = [];
    foreach ($st->fetchAll() as $row) {
        $conditionMap[(int)$row['stamp_bonus_id']][] = [
            'item_type_id' => (int)$row['item_type_id'],
            'required_count' => (int)$row['required_count'],
        ];
    }

    foreach ($bonuses as &$bonus) {
        $id = (int)$bonus['id'];
        $bonus['class_ids'] = $classMap[$id] ?? [];
        $bonus['item_type_conditions'] = $conditionMap[$id] ?? [];
        $bonus['item_type_restrictions'] = [];
        $bonus['overrides'] = [];
        $bonus['stat_sources'] = [];
        $bonus['item_type_condition_mode'] = (string)($bonus['item_type_condition_mode'] ?? 'all');
        $bonus['state_id'] = cast_int_or_null($bonus['state_id'] ?? null);
        $bonus['feature_id'] = cast_int_or_null($bonus['feature_id'] ?? null);
        $bonus['text_note_id'] = cast_int_or_null($bonus['text_note_id'] ?? null);
        $bonus['depends_on_card_id'] = null;
        $bonus['value_max'] = null;
        $bonus['scaling_type'] = 'stamp_quality';
        $bonus['scaling_step'] = 1;
        $bonus['scaling_threshold'] = 0;
    }
    unset($bonus);
}

function get_stamp_variants_by_ids(PDO $pdo, array $variantIds): array
{
    $variantIds = array_values(array_unique(array_filter(array_map('intval', $variantIds), static fn($id) => $id > 0)));
    if (!$variantIds) {
        return [];
    }
    $ph = in_placeholders($variantIds);
    $st = $pdo->prepare("
        SELECT v.id, v.stamp_id, v.sort_order,
               s.name, s.sort_order AS stamp_sort_order,
               c.id AS color_id, c.name AS color_name, c.hex AS color_hex, c.sort_order AS color_sort_order
        FROM stamp_variants v
        JOIN stamps s ON s.id = v.stamp_id
        JOIN stamp_colors c ON c.id = v.color_id
        WHERE v.id IN ($ph)
        ORDER BY s.sort_order, s.id, v.sort_order, c.sort_order, c.id
    ");
    $st->execute($variantIds);
    $variants = $st->fetchAll();
    if (!$variants) {
        return [];
    }
    $st = $pdo->prepare("
        SELECT b.id, b.stamp_variant_id, b.stat_id, s.name_ru, s.slug, s.alias AS stat_alias,
               CASE b.value_type WHEN 'percent' THEN s.stamp_percent_min_coef ELSE s.stamp_flat_min_coef END AS stamp_min_coef,
               CASE b.value_type WHEN 'percent' THEN s.stamp_percent_max_coef ELSE s.stamp_flat_max_coef END AS stamp_max_coef,
               b.quality_value, b.display_value, b.value_type, b.feature_id, b.text_note_id,
               tn.`text` AS text_note_text, b.state_id, b.item_type_condition_mode, b.sort_order
        FROM stamp_variant_bonuses b
        JOIN stats s ON s.id = b.stat_id
        LEFT JOIN text_notes tn ON tn.id = b.text_note_id
        WHERE b.stamp_variant_id IN ($ph)
        ORDER BY b.stamp_variant_id, b.sort_order, b.id
    ");
    $st->execute($variantIds);
    $bonuses = $st->fetchAll();
    foreach ($bonuses as &$bonus) {
        $bonus['id'] = (int)$bonus['id'];
        $bonus['stamp_variant_id'] = (int)$bonus['stamp_variant_id'];
        $bonus['stat_id'] = (int)$bonus['stat_id'];
        $bonus['quality_value'] = (float)$bonus['quality_value'];
        $bonus['value'] = (float)$bonus['quality_value'];
        $bonus['display_value'] = cast_float_or_null($bonus['display_value']);
        $bonus['stamp_min_coef'] = (float)$bonus['stamp_min_coef'];
        $bonus['stamp_max_coef'] = (float)$bonus['stamp_max_coef'];
    }
    unset($bonus);
    attach_stamp_bonus_conditions($pdo, $bonuses);
    $bonusesByVariant = [];
    foreach ($bonuses as $bonus) {
        $bonusesByVariant[(int)$bonus['stamp_variant_id']][] = $bonus;
    }
    $stampIds = array_values(array_unique(array_map(static fn($v) => (int)$v['stamp_id'], $variants)));
    $sph = in_placeholders($stampIds);
    $st = $pdo->prepare("SELECT stamp_id, item_type_id FROM stamp_allowed_item_types WHERE stamp_id IN ($sph) ORDER BY stamp_id, item_type_id");
    $st->execute($stampIds);
    $typesByStamp = [];
    foreach ($st->fetchAll() as $row) {
        $typesByStamp[(int)$row['stamp_id']][] = (int)$row['item_type_id'];
    }
    $st = $pdo->prepare("SELECT stamp_id, class_id FROM stamp_class_restrictions WHERE stamp_id IN ($sph) ORDER BY stamp_id, class_id");
    $st->execute($stampIds);
    $classesByStamp = [];
    foreach ($st->fetchAll() as $row) {
        $classesByStamp[(int)$row['stamp_id']][] = (int)$row['class_id'];
    }
    foreach ($variants as &$variant) {
        $id = (int)$variant['id'];
        $stampId = (int)$variant['stamp_id'];
        $variant['id'] = $id;
        $variant['stamp_id'] = $stampId;
        $variant['color_id'] = (int)$variant['color_id'];
        $variant['sort_order'] = (int)$variant['sort_order'];
        $variant['stamp_sort_order'] = (int)$variant['stamp_sort_order'];
        $variant['color_sort_order'] = (int)$variant['color_sort_order'];
        $variant['allowed_item_type_ids'] = $typesByStamp[$stampId] ?? [];
        $variant['class_ids'] = $classesByStamp[$stampId] ?? [];
        $variant['bonuses'] = $bonusesByVariant[$id] ?? [];
    }
    unset($variant);
    return $variants;
}

function api_bootstrap_actions(): array
{
    return [
        'modal_tabs', 'reference_names', 'card_sets', 'states', 'settings', 'enum_labels', 'base_stats', 'slots',
        'slot_rules', 'upgrades', 'grades', 'features', 'classes', 'item_type_tabs', 'stamps',
    ];
}

function api_cacheable_actions(): array
{
    return array_merge(api_bootstrap_actions(), [
        'bootstrap', 'card_types', 'text_notes', 'items', 'cards',
        'items_by_ids', 'cards_by_ids', 'item_cards', 'equipment_cards', 'slot_types',
    ]);
}

function api_output(array $result, bool $cacheable): void
{
    $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    if ($cacheable) {
        $etag = '"' . md5($json) . '"';
        header('ETag: ' . $etag);
        header('Cache-Control: private, max-age=0, must-revalidate');
        if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            header('Content-Length: 0');
            return;
        }
    } else {
        header('Cache-Control: no-store');
    }
    header('Content-Length: ' . strlen($json));
    echo $json;
}

$action = (string)($_GET['action'] ?? '');
try {
$pdo = connect();
$run = static function (string $action) use ($pdo): array {
$result = null;
switch ($action) {
        case 'classes':
            $stmt = $pdo->query('
                SELECT c.id, c.name, c.parent_id, p.name AS parent_name,
                    c.min_level, c.max_level
                FROM classes c
                LEFT JOIN classes p ON p.id = c.parent_id
                ORDER BY c.sort_order, c.id
            ');
            $result = $stmt->fetchAll();
            break;

        case 'features':
            $stmt = $pdo->query('
                SELECT id, checkbox_id, tab_id, slug, name
                FROM features
                ORDER BY checkbox_id IS NULL, checkbox_id, sort_order, id
            ');
            $result = $stmt->fetchAll();
            break;

case 'settings':
$result = settings_all();
break;
case 'stamps':
$itemTypeId = isset($_GET['item_type_id']) && $_GET['item_type_id'] !== ''
    ? (int)$_GET['item_type_id']
    : null;
$sql = 'SELECT v.id FROM stamp_variants v JOIN stamps s ON s.id = v.stamp_id WHERE 1 = 1';
$params = [];
if ($itemTypeId !== null) {
    $sql .= ' AND (
        NOT EXISTS (SELECT 1 FROM stamp_allowed_item_types a WHERE a.stamp_id = s.id)
        OR EXISTS (SELECT 1 FROM stamp_allowed_item_types a WHERE a.stamp_id = s.id AND a.item_type_id = ?)
    )';
    $params[] = $itemTypeId;
}
$sql .= ' ORDER BY s.sort_order, s.id, v.sort_order, v.id';
$st = $pdo->prepare($sql);
$st->execute($params);
$result = get_stamp_variants_by_ids($pdo, $st->fetchAll(PDO::FETCH_COLUMN));
break;
case 'stamps_by_ids':
$result = get_stamp_variants_by_ids($pdo, normalize_id_list($_GET['ids'] ?? ''));
break;
case 'item_stamps':
$itemId = (int)($_GET['item_id'] ?? 0);
if ($itemId <= 0) {
    throw new RuntimeException('Некорректный ID предмета.');
}
$st = $pdo->prepare('SELECT id, item_type_id FROM items WHERE id = ?');
$st->execute([$itemId]);
$item = $st->fetch();
if (!$item) {
    throw new RuntimeException('Предмет не найден.');
}
$st = $pdo->prepare('SELECT stamp_variant_id, is_locked FROM item_stamps WHERE item_id = ?');
$st->execute([$itemId]);
$row = $st->fetch();
$variants = $row ? get_stamp_variants_by_ids($pdo, [(int)$row['stamp_variant_id']]) : [];
$result = [
    'item_id' => (int)$item['id'],
    'item_type_id' => (int)$item['item_type_id'],
    'is_locked' => $row ? (int)$row['is_locked'] : 0,
    'variant' => $variants[0] ?? null,
];
break;
case 'equipment_stamps':
$itemIds = normalize_id_list($_GET['item_ids'] ?? $_GET['ids'] ?? '');
if (!$itemIds) {
    $result = [];
    break;
}
$ph = in_placeholders($itemIds);
$st = $pdo->prepare("SELECT item_id, stamp_variant_id, is_locked FROM item_stamps WHERE item_id IN ($ph)");
$st->execute($itemIds);
$rows = $st->fetchAll();
$variants = get_stamp_variants_by_ids($pdo, array_map(static fn($r) => (int)$r['stamp_variant_id'], $rows));
$byId = [];
foreach ($variants as $variant) {
    $byId[(int)$variant['id']] = $variant;
}
$response = [];
foreach ($itemIds as $id) {
    $response[(string)$id] = ['is_locked' => 0, 'variant' => null];
}
foreach ($rows as $row) {
    $response[(string)(int)$row['item_id']] = [
        'is_locked' => (int)$row['is_locked'],
        'variant' => $byId[(int)$row['stamp_variant_id']] ?? null,
    ];
}
$result = $response;
break;

        case 'item_type_tabs':
            $tabRows = $pdo->query('SELECT id, slug FROM modal_tabs')->fetchAll();
            $slugById = [];
            foreach ($tabRows as $t) {
                $slugById[(int)$t['id']] = (string)$t['slug'];
            }
            $hiddenRows = $pdo->query('SELECT item_type_id, tab_id FROM item_type_tabs WHERE is_visible = 0')->fetchAll();
            $result = [];
            foreach ($hiddenRows as $row) {
                $slug = $slugById[(int)$row['tab_id']] ?? null;
                if ($slug === null) {
                    continue;
                }
                $result[(int)$row['item_type_id']][] = $slug;
            }
            break;
            
        case 'slots':
            $result = slot_payload();
            break;
            
        case 'enum_labels':
            $result = enum_payload();
            break;

        case 'base_stats':
            $result = $pdo->query('
            SELECT id, slug, name_ru, alias, base_field, base_format, sort_order
            FROM stats
            WHERE is_base = 1
            AND base_field IS NOT NULL
            AND base_format IS NOT NULL
            ORDER BY sort_order, id
            ')->fetchAll();
            break;
            
        case 'modal_tabs':
            $stmt = $pdo->query('
                SELECT
                    id,
                    slug,
                    name,
                    sort_order
                FROM modal_tabs
                ORDER BY sort_order, id
            ');
            $result = $stmt->fetchAll();
            break;

        case 'text_notes':
            $stmt = $pdo->query('
                SELECT
                    id,
                    title,
                    `text`,
                    sort_order
                FROM text_notes
                ORDER BY sort_order, id
            ');
            $result = $stmt->fetchAll();
            break;

        case 'slot_rules':
            $stmt = $pdo->query('
                SELECT class_id, item_type_id, slot_number, restriction
                FROM slot_rules
                ORDER BY slot_number, class_id, item_type_id
            ');
            $result = $stmt->fetchAll();
            break;

        case 'slot_types':
            $gender = (string)($_GET['gender'] ?? '');
            $classId = (int)($_GET['class_id'] ?? 0);
            $level = (int)($_GET['level'] ?? 0);

            if (!in_array($gender, enum_codes('gender', 'character'), true)) {
                throw new RuntimeException('Некорректный пол персонажа.');
            }

            if ($classId <= 0) {
                throw new RuntimeException('Некорректный класс персонажа.');
            }

            if ($level < 1 || $level > setting_int('max_character_level', 65, 1, 255)) {
                throw new RuntimeException('Некорректный уровень персонажа.');
            }

            $stmt = $pdo->prepare('SELECT id, parent_id FROM classes WHERE id = ?');
            $stmt->execute([$classId]);
            $class = $stmt->fetch();

            if (!$class) {
                throw new RuntimeException('Класс не найден.');
            }

            $classIds = [$classId];

            if ($class['parent_id'] !== null) {
                $classIds[] = (int)$class['parent_id'];
            }

            $classIds = array_values(array_unique($classIds));
            $classPlaceholders = in_placeholders($classIds);

            $stmt = $pdo->prepare("
                SELECT DISTINCT
                    s.slot_number,
                    i.item_type_id
                FROM items i
                JOIN item_slots s ON s.item_id = i.id
WHERE (i.required_level IS NULL OR i.required_level <= ?)
AND (i.gender = 'any' OR i.gender = ?)
                  AND EXISTS (
                      SELECT 1
                      FROM item_classes ic
                      WHERE ic.item_id = i.id
                        AND ic.class_id IN ($classPlaceholders)
                  )
                ORDER BY s.slot_number, i.item_type_id
            ");

            $stmt->execute(array_merge([$level, $gender], $classIds));
            $rows = $stmt->fetchAll();

            $ruleStmt = $pdo->prepare("
                SELECT slot_number, class_id, item_type_id, restriction
                FROM slot_rules
                WHERE class_id IN ($classPlaceholders)
            ");
            $ruleStmt->execute($classIds);

            $rulesBySlot = [];

            foreach ($ruleStmt->fetchAll() as $rule) {
                $slot = (int)$rule['slot_number'];
                $typeId = (int)$rule['item_type_id'];
                $restriction = (string)$rule['restriction'];

                if ((int)$rule['class_id'] === $classId) {
                    $rulesBySlot[$slot]['direct'][$typeId] = $restriction;
                } else {
                    $rulesBySlot[$slot]['parent'][$typeId] = $restriction;
                }
            }

            $result = [];

            foreach ($rows as $row) {
                $slot = (int)$row['slot_number'];
                $typeId = (int)$row['item_type_id'];

                $parentRules = $rulesBySlot[$slot]['parent'] ?? [];
                $directRules = $rulesBySlot[$slot]['direct'] ?? [];
                $effectiveRules = array_replace($parentRules, $directRules);

                $restriction = $effectiveRules[$typeId] ?? 'allowed';

                if ($restriction === 'denied' || $restriction === 'locked') {
                    continue;
                }

                $result[] = [
                    'slot_number' => $slot,
                    'item_type_id' => $typeId,
                ];
            }

            break;

        case 'upgrades':
            $stmt = $pdo->query('
                SELECT level, attack_percent, defense_percent
                FROM upgrade_levels
                ORDER BY level
            ');
            $result = $stmt->fetchAll();
            break;

        case 'grades':
            $result = $pdo->query('
            SELECT code, name, sort_order, max_upgrade_level
            FROM grades
            ORDER BY sort_order, code
            ')->fetchAll();
            break;

        case 'card_types':
            $stmt = $pdo->query('
                SELECT
                    id,
                    slug,
                    name,
                    socket_number,
                    sort_order
                FROM card_types
                ORDER BY sort_order, id
            ');
            $result = $stmt->fetchAll();
            break;

        case 'card_sets':
            $stmt = $pdo->query('
                SELECT
                    id,
                    slug,
                    name,
                    negative_threshold,
                    negative_below_mode,
                    negative_reached_mode,
                    sort_order
                FROM card_sets
                ORDER BY sort_order, id
            ');
            $sets = $stmt->fetchAll();

            if ($sets) {
                $setIds = array_map('intval', array_column($sets, 'id'));
                $setPlaceholders = in_placeholders($setIds);

                $bonusStmt = $pdo->prepare("
                    SELECT
                        b.id,
                        b.set_id,
                        b.required_count,
                        b.stat_id,
                        s.name_ru,
                        s.slug,
                        b.value,
                        b.value_type,
                        b.sort_order
                    FROM card_set_bonuses b
                    JOIN stats s ON s.id = b.stat_id
                    WHERE b.set_id IN ($setPlaceholders)
                    ORDER BY b.set_id, b.required_count, b.sort_order, b.id
                ");

                $bonusStmt->execute($setIds);
                $bonuses = $bonusStmt->fetchAll();

                $grouped = [];

                foreach ($bonuses as $bonus) {
                    $bonus['value'] = (float)$bonus['value'];
                    $grouped[(int)$bonus['set_id']][] = $bonus;
                }

                foreach ($sets as &$set) {
                    $set['bonuses'] = $grouped[(int)$set['id']] ?? [];
                }

                unset($set);
            }

            $result = $sets;
            break;

        case 'cards':
            $cardTypeId = isset($_GET['card_type_id']) && $_GET['card_type_id'] !== ''
                ? (int)$_GET['card_type_id']
                : null;

            $setId = isset($_GET['set_id']) && $_GET['set_id'] !== ''
                ? (int)$_GET['set_id']
                : null;

            $itemTypeId = isset($_GET['item_type_id']) && $_GET['item_type_id'] !== ''
                ? (int)$_GET['item_type_id']
                : null;

            $sql = '
                SELECT c.id
                FROM cards c
                JOIN card_types ct ON ct.id = c.card_type_id
                LEFT JOIN card_sets cs ON cs.id = c.set_id
                WHERE 1 = 1
            ';

            $params = [];

            if ($cardTypeId !== null) {
                $sql .= ' AND c.card_type_id = ?';
                $params[] = $cardTypeId;
            }

            if ($setId !== null) {
                $sql .= ' AND c.set_id = ?';
                $params[] = $setId;
            }

            if ($itemTypeId !== null) {
                $sql .= ' AND EXISTS (
                    SELECT 1
                    FROM card_allowed_item_types ait
                    WHERE ait.card_id = c.id
                      AND ait.item_type_id = ?
                )';
                $params[] = $itemTypeId;
            }

            $sql .= ' ORDER BY c.sort_order, c.id';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $result = get_cards_by_ids($pdo, $ids);
            break;

        case 'cards_by_ids':
            $ids = normalize_id_list($_GET['ids'] ?? '');
            $result = get_cards_by_ids($pdo, $ids);
            break;

        case 'item_cards':
            $itemId = (int)($_GET['item_id'] ?? 0);

            if ($itemId <= 0) {
                throw new RuntimeException('Некорректный ID предмета.');
            }

            $stmt = $pdo->prepare('
                SELECT
                    i.id,
                    i.sockets,
                    i.item_type_id,
                    t.name AS item_type_name
                FROM items i
                JOIN item_types t ON t.id = i.item_type_id
                WHERE i.id = ?
            ');

            $stmt->execute([$itemId]);
            $item = $stmt->fetch();

            if (!$item) {
                throw new RuntimeException('Предмет не найден.');
            }

            $stmt = $pdo->prepare('
                SELECT
                    ic.socket_number,
                    ic.is_locked,
                    ic.card_id
                FROM item_cards ic
                WHERE ic.item_id = ?
                ORDER BY ic.socket_number
            ');

            $stmt->execute([$itemId]);
            $assignedRows = $stmt->fetchAll();

            $cardIds = array_values(array_unique(array_filter(array_map(
                static fn($row) => (int)$row['card_id'],
                $assignedRows
            ), static fn($id) => $id > 0)));

            $cards = get_cards_by_ids($pdo, $cardIds);
            $cardsById = [];

            foreach ($cards as $card) {
                $cardsById[(int)$card['id']] = $card;
            }

            $assignedBySocket = [];

            foreach ($assignedRows as $row) {
                $assignedBySocket[(int)$row['socket_number']] = $row;
            }

            $sockets = [];
            $socketCount = (int)$item['sockets'];

            for ($socket = 1; $socket <= $socketCount; $socket++) {
                $row = $assignedBySocket[$socket] ?? null;
                $card = $row && isset($cardsById[(int)$row['card_id']])
                    ? $cardsById[(int)$row['card_id']]
                    : null;

                $sockets[] = [
                    'socket_number' => $socket,
                    'is_locked' => $row ? (int)$row['is_locked'] : 0,
                    'card' => $card,
                ];
            }

            $result = [
                'item_id' => (int)$item['id'],
                'item_type_id' => (int)$item['item_type_id'],
                'item_type_name' => $item['item_type_name'],
                'sockets' => $sockets,
            ];

            break;

        case 'equipment_cards':
            $itemIds = normalize_id_list($_GET['item_ids'] ?? $_GET['ids'] ?? '');

            if (!$itemIds) {
                $result = [];
                break;
            }

            $placeholders = in_placeholders($itemIds);

            $stmt = $pdo->prepare("
                SELECT
                    item_id,
                    socket_number,
                    is_locked,
                    card_id
                FROM item_cards
                WHERE item_id IN ($placeholders)
                ORDER BY item_id, socket_number
            ");

            $stmt->execute($itemIds);
            $rows = $stmt->fetchAll();

            $cardIds = array_values(array_unique(array_filter(array_map(
                static fn($row) => (int)$row['card_id'],
                $rows
            ), static fn($id) => $id > 0)));

            $cards = get_cards_by_ids($pdo, $cardIds);
            $cardsById = [];

            foreach ($cards as $card) {
                $cardsById[(int)$card['id']] = $card;
            }

            $response = [];

            foreach ($itemIds as $itemId) {
                $response[(string)$itemId] = [
                    'sockets' => [],
                ];
            }

            foreach ($rows as $row) {
                $itemId = (int)$row['item_id'];
                $cardId = (int)$row['card_id'];

                $response[(string)$itemId]['sockets'][] = [
                    'socket_number' => (int)$row['socket_number'],
                    'is_locked' => (int)$row['is_locked'],
                    'card' => $cardsById[$cardId] ?? null,
                ];
            }

            $result = $response;
            break;

        case 'items_by_ids':
            $ids = normalize_id_list($_GET['ids'] ?? '');

            if (!$ids) {
                $result = [];
                break;
            }

            $placeholders = in_placeholders($ids);

            $stmt = $pdo->prepare("
                SELECT
                    i.id,
                    i.name,
                    i.description,
                    i.description_feature_id,
                    i.icon_path,
                    i.item_type_id,
                    t.name AS type_name,
i.grade,
g.name AS grade_name,
i.required_level,
i.internal_level,
i.gender,
i.base_defense,
i.base_attack,
i.base_attack_speed,
i.base_dps,
i.sockets,
i.can_upgrade,
i.state_id
FROM items i
JOIN item_types t ON t.id = i.item_type_id
LEFT JOIN grades g ON g.code = i.grade
WHERE i.id IN ($placeholders)
                ORDER BY i.id
            ");

            $stmt->execute($ids);
            $items = $stmt->fetchAll();

            $result = build_items_payload($pdo, $items);
            break;

        case 'items':
            $slot = (int)($_GET['slot'] ?? 0);
            $gender = (string)($_GET['gender'] ?? '');
            $classId = (int)($_GET['class_id'] ?? 0);
            $level = (int)($_GET['level'] ?? 0);

            if (!in_array($slot, slot_numbers(), true)) {
throw new RuntimeException('Некорректный номер ячейки.');
}

            if (!in_array($gender, enum_codes('gender', 'character'), true)) {
                throw new RuntimeException('Некорректный пол персонажа.');
            }

            if ($classId <= 0) {
                throw new RuntimeException('Некорректный класс персонажа.');
            }

            if ($level < 1 || $level > setting_int('max_character_level', 65, 1, 255)) {
                throw new RuntimeException('Некорректный уровень персонажа.');
            }

            $stmt = $pdo->prepare('SELECT id, parent_id FROM classes WHERE id = ?');
            $stmt->execute([$classId]);
            $class = $stmt->fetch();

            if (!$class) {
                throw new RuntimeException('Класс не найден.');
            }

            $classIds = [$classId];

            if ($class['parent_id'] !== null) {
                $classIds[] = (int)$class['parent_id'];
            }

            $classIds = array_values(array_unique($classIds));
            $classPlaceholders = in_placeholders($classIds);

            $sql = "
                SELECT
                    i.id,
                    i.name,
                    i.description,
                    i.description_feature_id,
                    i.icon_path,
                    i.item_type_id,
                    t.name AS type_name,
i.grade,
g.name AS grade_name,
i.required_level,
i.internal_level,
i.gender,
i.base_defense,
i.base_attack,
i.base_attack_speed,
i.base_dps,
i.sockets,
i.can_upgrade,
i.state_id
FROM items i
JOIN item_types t ON t.id = i.item_type_id
LEFT JOIN grades g ON g.code = i.grade
WHERE EXISTS (
                    SELECT 1
                    FROM item_slots s
                    WHERE s.item_id = i.id
                      AND s.slot_number = ?
                )
                AND (i.required_level IS NULL OR i.required_level <= ?)
                AND (i.gender = 'any' OR i.gender = ?)
                AND EXISTS (
                    SELECT 1
                    FROM item_classes ic
                    WHERE ic.item_id = i.id
                      AND ic.class_id IN ($classPlaceholders)
                )
ORDER BY
COALESCE(g.sort_order, 999999),
COALESCE(i.required_level, 0) DESC,
i.name ASC
            ";

            $params = array_merge(
                [$slot, $level, $gender],
                $classIds
            );

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $items = $stmt->fetchAll();

            $ruleStmt = $pdo->prepare("
                SELECT class_id, item_type_id, restriction
                FROM slot_rules
                WHERE slot_number = ?
                  AND class_id IN ($classPlaceholders)
            ");
            $ruleStmt->execute(array_merge([$slot], $classIds));

            $parentRules = [];
            $directRules = [];

            foreach ($ruleStmt->fetchAll() as $rule) {
                $typeId = (int)$rule['item_type_id'];
                $restriction = (string)$rule['restriction'];

                if ((int)$rule['class_id'] === $classId) {
                    $directRules[$typeId] = $restriction;
                } else {
                    $parentRules[$typeId] = $restriction;
                }
            }

            $effectiveRules = array_replace($parentRules, $directRules);

            $items = array_values(array_filter(
                $items,
                static function ($item) use ($effectiveRules) {
                    $restriction = $effectiveRules[(int)$item['item_type_id']] ?? 'allowed';
                    return $restriction !== 'denied' && $restriction !== 'locked';
                }
            ));

            $result = build_items_payload($pdo, $items);
            break;

        case 'validate_equipment':
            $raw = file_get_contents('php://input');
            $data = json_decode($raw, true);

            if (!is_array($data)) {
                throw new RuntimeException('Некорректный JSON.');
            }

            $gender = (string)($data['gender'] ?? '');
            $classId = (int)($data['class_id'] ?? 0);
            $level = (int)($data['level'] ?? 0);
            $equipment = $data['equipment'] ?? [];

            if (!is_array($equipment)) {
                throw new RuntimeException('Некорректный список предметов.');
            }

            if (!in_array($gender, enum_codes('gender', 'character'), true)) {
                throw new RuntimeException('Некорректный пол персонажа.');
            }

            if ($classId <= 0) {
                throw new RuntimeException('Некорректный класс персонажа.');
            }

            if ($level < 1 || $level > setting_int('max_character_level', 65, 1, 255)) {
                throw new RuntimeException('Некорректный уровень персонажа.');
            }

            $stmt = $pdo->prepare('SELECT id, parent_id FROM classes WHERE id = ?');
            $stmt->execute([$classId]);
            $class = $stmt->fetch();

            if (!$class) {
                throw new RuntimeException('Класс не найден.');
            }

            $classIds = [$classId];

            if ($class['parent_id'] !== null) {
                $classIds[] = (int)$class['parent_id'];
            }

            $classIds = array_values(array_unique($classIds));
            $classPlaceholders = in_placeholders($classIds);

            $checkStmt = $pdo->prepare("
                SELECT i.id
                FROM items i
                WHERE i.id = ?
                  AND (i.required_level IS NULL OR i.required_level <= ?)
                  AND (i.gender = 'any' OR i.gender = ?)
                  AND EXISTS (
                      SELECT 1
                      FROM item_slots s
                      WHERE s.item_id = i.id
                        AND s.slot_number = ?
                  )
                  AND EXISTS (
                      SELECT 1
                      FROM item_classes ic
                      WHERE ic.item_id = i.id
                        AND ic.class_id IN ($classPlaceholders)
                  )
                LIMIT 1
            ");

            $invalidSlots = [];

            foreach ($equipment as $slot => $itemId) {
                $slot = (int)$slot;
                $itemId = is_array($itemId) ? (int)($itemId['id'] ?? 0) : (int)$itemId;

                $slotExists = in_array($slot, slot_numbers(), true);
if (!$slotExists || $itemId <= 0) {
if ($slotExists) {
$invalidSlots[] = (string)$slot;
}
continue;
}

                $params = array_merge([$itemId, $level, $gender, $slot], $classIds);
                $checkStmt->execute($params);

                if (!$checkStmt->fetchColumn()) {
                    $invalidSlots[] = (string)$slot;
                }
            }

            $result = [
                'invalid_slots' => array_values(array_unique($invalidSlots)),
            ];

            break;

        case 'reference_names':
            $result = [
                'item_types' => $pdo->query('
                    SELECT id, name
                    FROM item_types
                    ORDER BY sort_order, id
                ')->fetchAll(PDO::FETCH_ASSOC),

                'card_names' => $pdo->query('
                    SELECT id, name
                    FROM cards
                    ORDER BY sort_order, id
                ')->fetchAll(PDO::FETCH_ASSOC),

                'stat_names' => $pdo->query('
                    SELECT id, name_ru AS name
                    FROM stats
                    WHERE is_base = 0
                    ORDER BY sort_order, id
                ')->fetchAll(PDO::FETCH_ASSOC),

                'class_names' => $pdo->query('
                    SELECT id, name, parent_id
                    FROM classes
                    ORDER BY sort_order, id
                ')->fetchAll(PDO::FETCH_ASSOC),
            ];
            break;

        case 'states':
            $result = $pdo->query('
                SELECT
                    id,
                    slug,
                    name,
                    is_default,
                    sort_order
                FROM states
                ORDER BY is_default DESC, sort_order, id
            ')->fetchAll(PDO::FETCH_ASSOC);
            break;

default:
throw new RuntimeException('Неизвестное действие.');
}
return $result ?? [];
};

if ($action === 'bootstrap') {
    $result = [];
    foreach (api_bootstrap_actions() as $key) {
        $result[$key] = $run($key);
    }
} else {
    $result = $run($action);
}
api_output($result, in_array($action, api_cacheable_actions(), true));
} catch (Throwable $e) {
if ($e instanceof RuntimeException) {
http_response_code(400);
echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} else {
error_log('[api] ' . $e->getMessage());
http_response_code(500);
echo json_encode(['error' => 'Внутренняя ошибка сервера.'], JSON_UNESCAPED_UNICODE);
}
}