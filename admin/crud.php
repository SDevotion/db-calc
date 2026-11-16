<?php
declare(strict_types=1);
if (!defined('ADMIN_ENTRY')) { http_response_code(403); exit; }

function fld(string $label, string $type, array $o = []): array
{
    return ['label' => $label, 'type' => $type] + $o;
}

function crud_definitions(): array
{
    $modes = enum_rows('negative_mode');
    $slots = [];
    foreach (slot_all() as $slotNumber => $slotRow) {
        $slots[$slotNumber] = $slotNumber . ' — ' . $slotRow['name'];
    }

    $maxLevel = setting_int('max_character_level', 65, 1, 255);
    $maxSockets = setting_int('max_sockets', 3, 0, 16);
    $maxSetCards = setting_int('max_set_cards', 64, 1, 255);
    $socketOptions = [];
    for ($i = 1; $i <= $maxSockets; $i++) $socketOptions[$i] = (string)$i;

    return [

    /* ======================= КАТАЛОГ ======================= */

    'types' => [
        'table' => 'item_types', 'title' => 'Типы предметов', 'order' => 't.sort_order, t.id',
        'intro' => 'Категории экипировки: оружие, броня, аксессуары. Тип определяет, в какие ячейки можно надеть предмет и какие карты в него вставлять.',
        'fields' => [
            'slug' => fld('Slug', 'text', ['max' => 50, 'required' => true, 'slug' => true]),
            'name' => fld('Название', 'text', ['max' => 100, 'required' => true]),
            'tab_ids' => fld('Вкладки модального окна', 'checks', [
                'wide' => true, 'list' => false,
                'options' => fn(PDO $pdo) => db_options($pdo, 'SELECT id, name FROM modal_tabs ORDER BY sort_order, id'),
                'load' => function (PDO $pdo, $key): array {
                    $st = $pdo->prepare('
                        SELECT t.id
                        FROM modal_tabs t
                        WHERE t.id NOT IN (
                            SELECT itt.tab_id FROM item_type_tabs itt
                            WHERE itt.item_type_id = ? AND itt.is_visible = 0
                        )
                        ORDER BY t.sort_order, t.id
                    ');
                    $st->execute([(int)$key]);
                    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
                },
                'save' => function (PDO $pdo, $key, array $visibleIds): void {
                    $all = array_map('intval', $pdo->query('SELECT id FROM modal_tabs')->fetchAll(PDO::FETCH_COLUMN));
                    $pdo->prepare('DELETE FROM item_type_tabs WHERE item_type_id = ?')->execute([(int)$key]);
                    $st = $pdo->prepare('INSERT INTO item_type_tabs (item_type_id, tab_id, is_visible) VALUES (?, ?, ?)');
                    foreach ($all as $tabId) {
                        $st->execute([(int)$key, $tabId, in_array($tabId, $visibleIds, true) ? 1 : 0]);
                    }
                },
                'help' => 'Отмечены вкладки, доступные в модальном окне для предметов этого типа. Снимите отметку, чтобы скрыть вкладку.',
            ]),
            'sort_order' => fld('Порядок', 'int', ['default' => 0]),
        ],
        'unique' => [['cols' => ['slug'], 'message' => 'Тип предмета с таким slug уже существует.']],
        'validate' => function (array &$d): void {
            if (empty($d['tab_ids'])) {
                throw new RuntimeException('Оставьте хотя бы одну вкладку видимой для типа предмета.');
            }
        },
        'guards' => [['sql' => 'SELECT COUNT(*) FROM items WHERE item_type_id = ?', 'message' => 'Нельзя удалить тип предмета, который используется предметами.']],
        'counts' => ['Предметы' => 'SELECT COUNT(*) FROM items i WHERE i.item_type_id = t.id'],
        'warn' => 'Удалить тип предмета? Связанные правила ячеек и совместимость карт будут удалены.',
    ],

    'stamp_colors' => [
        'table' => 'stamp_colors', 'title' => 'Цвета печатей', 'order' => 't.sort_order, t.id',
        'intro' => 'Цвет влияет только на оформление и на то, какие характеристики даёт вариант печати. Оттенок используется для плашки в калькуляторе.',
        'fields' => [
            'slug' => fld('Slug', 'text', ['max' => 64, 'required' => true, 'slug' => true]),
            'name' => fld('Название', 'text', ['max' => 120, 'required' => true]),
            'hex' => fld('Оттенок', 'text', ['max' => 7, 'required' => true, 'default' => '#8b909c', 'help' => 'Формат #RRGGBB']),
            'sort_order' => fld('Порядок', 'int', ['default' => 0]),
        ],
        'unique' => [['cols' => ['slug'], 'message' => 'Цвет с таким slug уже существует.']],
        'validate' => function (array &$d): void {
            if (!preg_match('/^#[0-9a-f]{6}$/i', (string)$d['hex'])) {
                throw new RuntimeException('Оттенок должен быть в формате #RRGGBB.');
            }
            $d['hex'] = strtolower((string)$d['hex']);
        },
        'guards' => [
            ['sql' => 'SELECT COUNT(*) FROM stamp_variants WHERE color_id = ?', 'message' => 'Нельзя удалить цвет, у которого есть варианты печатей.'],
        ],
        'counts' => ['Варианты' => 'SELECT COUNT(*) FROM stamp_variants v WHERE v.color_id = t.id'],
        'columns' => [
            'ID' => 'id',
            'Slug' => 'slug',
            'Название' => 'name',
            'Оттенок' => fn($r) => $r['hex'],
            'Варианты' => '__cnt0',
            'Порядок' => 'sort_order',
        ],
    ],

    'card_types' => [
        'table' => 'card_types', 'title' => 'Типы карт', 'order' => 't.sort_order, t.id',
        'intro' => 'Если указан слот, карты этого типа можно вставлять только в этот слот предмета. Пустое значение — тип не ограничен слотом.',
        'fields' => [
            'slug' => fld('Slug', 'text', ['max' => 64, 'required' => true, 'slug' => true]),
            'name' => fld('Название', 'text', ['max' => 120, 'required' => true]),
            'socket_number' => fld('Слот', 'ref', ['null' => true, 'empty' => 'Любой', 'options' => $socketOptions]),
            'sort_order' => fld('Порядок', 'int', ['default' => 0]),
        ],
        'unique' => [['cols' => ['slug'], 'message' => 'Тип карты с таким slug уже существует.']],
        'guards' => [['sql' => 'SELECT COUNT(*) FROM cards WHERE card_type_id = ?', 'message' => 'Нельзя удалить тип карты, к которому привязаны карты.']],
        'counts' => ['Карты' => 'SELECT COUNT(*) FROM cards c WHERE c.card_type_id = t.id'],
    ],

    'card_sets' => [
        'table' => 'card_sets', 'title' => 'Сеты карт', 'order' => 't.sort_order, t.id',
        'intro' => 'Порог определяет, когда меняется поведение отрицательных значений характеристик карт сета. Сетовые бонусы работают только при наличии главной карты сета.',
        'fields' => [
            'slug' => fld('Slug', 'text', ['max' => 64, 'required' => true, 'slug' => true]),
            'name' => fld('Название', 'text', ['max' => 120, 'required' => true]),
            'negative_threshold' => fld('Порог количества карт', 'int', ['min' => 1, 'maxv' => $maxSetCards, 'default' => 1, 'required' => true]),
            'negative_below_mode' => fld('До порога', 'enum', ['options' => $modes, 'default' => 'allow', 'required' => true]),
            'negative_reached_mode' => fld('После порога', 'enum', ['options' => $modes, 'default' => 'allow', 'required' => true]),
            'sort_order' => fld('Порядок', 'int', ['default' => 0]),
        ],
        'unique' => [['cols' => ['slug'], 'message' => 'Сет с таким slug уже существует.']],
        'guards' => [['sql' => 'SELECT COUNT(*) FROM cards WHERE set_id = ?', 'message' => 'Нельзя удалить сет, к которому привязаны карты.']],
        'counts' => [
            'Карты'  => 'SELECT COUNT(*) FROM cards c WHERE c.set_id = t.id',
            'Бонусы' => 'SELECT COUNT(*) FROM card_set_bonuses b WHERE b.set_id = t.id',
        ],
        'links' => [['label' => 'Бонусы', 'action' => 'card_set_bonuses', 'param' => 'set_id']],
    ],

    /* ======================= ПЕРСОНАЖ ======================= */

    'classes' => [
        'table' => 'classes', 'title' => 'Классы',
        'order' => 't.parent_id IS NOT NULL, COALESCE((SELECT p.sort_order FROM classes p WHERE p.id = t.parent_id), t.sort_order), t.sort_order, t.id',
        'intro' => 'Базовый класс не имеет родителя. Подкласс наследует доступ к предметам и правилам ячеек базового класса. Диапазон уровней задаёт границы выбора уровня в калькуляторе.',
        'fields' => [
            'name' => fld('Название', 'text', ['max' => 50, 'required' => true]),
            'parent_id' => fld('Родительский класс', 'ref', [
                'null' => true, 'empty' => '— нет (базовый класс) —',
                'options' => fn(PDO $pdo, $id) => db_options($pdo, 'SELECT id, name FROM classes WHERE parent_id IS NULL AND id <> ? ORDER BY sort_order, id', [(int)$id]),
            ]),
            'min_level' => fld('Мин. уровень', 'int', ['min' => 1, 'maxv' => $maxLevel, 'default' => 1, 'required' => true]),
            'max_level' => fld('Макс. уровень', 'int', ['min' => 1, 'maxv' => $maxLevel, 'default' => $maxLevel, 'required' => true]),
            'sort_order' => fld('Порядок', 'int', ['default' => 0]),
        ],
        'unique' => [['cols' => ['name'], 'message' => 'Класс с таким названием уже существует.']],
        'validate' => function (array &$d, $id, PDO $pdo): void {
            if ($d['min_level'] > $d['max_level']) throw new RuntimeException('Некорректный диапазон уровней.');
            if ($d['parent_id'] !== null) {
                if ((int)$d['parent_id'] === (int)$id) throw new RuntimeException('Класс не может быть родителем сам для себя.');
                $st = $pdo->prepare('SELECT parent_id FROM classes WHERE id = ?');
                $st->execute([$d['parent_id']]);
                $p = $st->fetch();
                if (!$p) throw new RuntimeException('Родительский класс не найден.');
                if ($p['parent_id'] !== null) throw new RuntimeException('Родителем может быть только базовый класс.');
            }
        },
        'guards' => [
            ['sql' => 'SELECT COUNT(*) FROM classes WHERE parent_id = ?', 'message' => 'Нельзя удалить класс, у которого есть подклассы.'],
            ['sql' => 'SELECT COUNT(*) FROM item_classes WHERE class_id = ?', 'message' => 'Нельзя удалить класс, который назначен предметам.'],
        ],
        'list_sql' => 'SELECT t.*, p.name AS parent_name,
            (SELECT COUNT(*) FROM classes ch WHERE ch.parent_id = t.id) AS child_count,
            (SELECT COUNT(*) FROM item_classes ic WHERE ic.class_id = t.id) AS item_count
            FROM classes t LEFT JOIN classes p ON p.id = t.parent_id
            ORDER BY t.parent_id IS NOT NULL, COALESCE(p.sort_order, t.sort_order), t.sort_order, t.id',
        'columns' => [
            'ID' => 'id', 'Название' => 'name',
            'Родитель' => fn($r) => $r['parent_name'] ?? '—',
            'Мин. уровень' => 'min_level', 'Макс. уровень' => 'max_level',
            'Подклассы' => 'child_count', 'Предметы' => 'item_count', 'Порядок' => 'sort_order',
        ],
        'warn' => 'Удалить класс? Его правила ячеек и ограничения бонусов будут удалены.',
    ],

    'states' => [
        'table' => 'states', 'title' => 'Состояния', 'order' => 't.is_default DESC, t.sort_order, t.id',
        'intro' => 'Slug — техническое имя (mounted, battle, town). Название — отображаемое имя. Ровно одно состояние должно быть состоянием по умолчанию.',
        'fields' => [
            'slug' => fld('Slug', 'text', ['max' => 64, 'required' => true, 'slug' => true]),
            'name' => fld('Название', 'text', ['max' => 150, 'required' => true]),
            'sort_order' => fld('Порядок', 'int', ['default' => 0]),
        ],
        'unique' => [['cols' => ['slug'], 'message' => 'Состояние с таким slug уже существует.']],
        'before_save' => function (array &$d, $id, PDO $pdo): void {
            if ((int)$id === 0) {
                $d['is_default'] = ((int)$pdo->query('SELECT COUNT(*) FROM states')->fetchColumn() === 0) ? 1 : 0;
            }
        },
        'list_sql' => 'SELECT t.* FROM states t ORDER BY t.is_default DESC, t.sort_order, t.id',
        'columns' => [
            'ID' => 'id', 'Slug' => 'slug', 'Название' => 'name',
            'По умолчанию' => fn($r) => (int)$r['is_default'] === 1 ? 'да' : 'нет',
            'Порядок' => 'sort_order',
        ],
        'row_actions' => function (array $r): string {
            if ((int)$r['is_default'] === 1) return '';
            return '<form method="post" class="inline">'
                . '<input type="hidden" name="form_action" value="set_default_state">'
                . '<input type="hidden" name="id" value="' . (int)$r['id'] . '">'
                . csrf_field()
                . '<button type="submit" class="btn">По умолчанию</button></form>';
        },
        'actions' => [
            'set_default_state' => [
                'done' => 'Состояние по умолчанию обновлено.',
                'handler' => function (PDO $pdo, $id): void {
                    if ((int)$id <= 0) throw new RuntimeException('Некорректный ID состояния.');
                    $pdo->beginTransaction();
                    try {
                        $st = $pdo->prepare('SELECT COUNT(*) FROM states WHERE id = ?');
                        $st->execute([(int)$id]);
                        if (!(int)$st->fetchColumn()) throw new RuntimeException('Состояние не найдено.');
                        $pdo->exec('UPDATE states SET is_default = 0');
                        $pdo->prepare('UPDATE states SET is_default = 1 WHERE id = ?')->execute([(int)$id]);
                        $pdo->commit();
                    } catch (Throwable $ex) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        throw $ex;
                    }
                },
            ],
        ],
        'after_delete' => function (PDO $pdo, $id, array $row): void {
            if ((int)$row['is_default'] !== 1) return;
            $next = $pdo->query('SELECT id FROM states ORDER BY sort_order, id LIMIT 1')->fetchColumn();
            if ($next) $pdo->prepare('UPDATE states SET is_default = 1 WHERE id = ?')->execute([(int)$next]);
        },
        'warn' => 'Удалить состояние? Предметы, карты и бонусы с этим состоянием станут безусловными.',
    ],

    'slot_rules' => [
        'table' => 'slot_rules', 'title' => 'Правила ячеек',
        'order' => 't.slot_number, t.class_id, t.item_type_id',
        'intro' => 'Класс → Тип предмета → Ячейка → Ограничение. Для «Показ» и «Скрытие» тип не требуется. Правило подкласса переопределяет правило базового класса. Без правил ячейка видима, а тип предмета доступен.',
        'fields' => [
            'class_id' => fld('Класс / подкласс', 'ref', [
                'required' => true, 'empty' => '— выбрать —', 'id' => 'rule_class',
                'options' => fn(PDO $pdo) => db_options($pdo, 'SELECT id, CONCAT(name, IFNULL(CONCAT(" (", (SELECT p.name FROM classes p WHERE p.id = c.parent_id), ""), ")")) AS name FROM classes c ORDER BY c.parent_id IS NOT NULL, c.sort_order, c.id'),
            ]),
            'item_type_id' => fld('Тип предмета', 'ref', [
                'null' => true, 'empty' => '— не указан —', 'id' => 'rule_type',
                'options' => fn(PDO $pdo) => db_options($pdo, 'SELECT id, name FROM item_types ORDER BY sort_order, id'),
            ]),
            'slot_number' => fld('Ячейка', 'enum', ['options' => $slots, 'default' => 1, 'required' => true, 'id' => 'rule_slot', 'cast' => 'int']),
            'restriction' => fld('Ограничение', 'enum', ['options' => restriction_labels(), 'default' => 'allowed', 'required' => true, 'id' => 'rule_restriction']),
        ],
        'validate' => function (array &$d): void {
            if (in_array($d['restriction'], ['show', 'hide'], true)) {
                $d['item_type_id'] = null;
            } elseif ($d['item_type_id'] === null) {
                throw new RuntimeException('Выберите тип предмета.');
            }
        },
        'before_save' => function (array &$d, $id, PDO $pdo): void {
            if (!is_generated_column($pdo, 'slot_rules', 'item_type_key')) {
                $d['item_type_key'] = $d['item_type_id'] ?? 0;
            }
        },
        'unique' => [['cols' => ['class_id', 'item_type_key', 'slot_number'], 'message' => 'Такое правило уже существует.']],
        'list_sql' => 'SELECT t.*, c.name AS class_name, p.name AS parent_name, it.name AS type_name
            FROM slot_rules t
            JOIN classes c ON c.id = t.class_id
            LEFT JOIN classes p ON p.id = c.parent_id
            LEFT JOIN item_types it ON it.id = t.item_type_id
            ORDER BY t.slot_number, c.parent_id IS NOT NULL, COALESCE(p.name, c.name), c.name, it.name',
        'columns' => [
            'ID' => 'id', 'Ячейка' => 'slot_number', 'Класс / подкласс' => 'class_name',
            'Родитель' => fn($r) => $r['parent_name'] ?? '—',
            'Тип предмета' => fn($r) => $r['type_name'] ?? '—',
            'Ограничение' => fn($r) => restriction_labels()[$r['restriction']] ?? $r['restriction'],
        ],
        'empty' => 'Правил нет. По умолчанию все ячейки видимы, а все типы предметов доступны.',
    ],

    'slots' => [
        'table' => 'slots', 'title' => 'Ячейки',
        'pk' => 'slot_number', 'order' => 't.column_no, t.sort_order, t.slot_number',
        'intro' => 'Ячейки экипировки. Номер используется предметами и правилами, поэтому при редактировании он заблокирован. Название показывается в подсказке ячейки. Парная группа объединяет ячейки в одну горизонтальную пару внутри колонки.',
        'fields' => [
            'slot_number' => fld('Номер ячейки', 'int', ['min' => 1, 'maxv' => 255, 'required' => true]),
            'name' => fld('Название', 'text', ['max' => 60, 'required' => true]),
            'column_no' => fld('Колонка', 'ref', [
                'required' => true, 'empty' => '— выбрать —',
                'options' => fn(PDO $pdo) => db_options(
                    $pdo,
                    "SELECT column_no AS id, CONCAT(column_no, ' — ', name) AS name
                     FROM slot_columns ORDER BY sort_order, column_no"
                ),
            ]),
            'pair_group' => fld('Парная группа', 'int', [
                'null' => true, 'min' => 1, 'maxv' => 255,
                'help' => 'Пусто = одиночная ячейка. Одинаковый номер у двух ячеек одной колонки = пара.',
            ]),
            'sort_order' => fld('Порядок', 'int', ['default' => 0]),
        ],
        'unique' => [['cols' => ['slot_number'], 'message' => 'Ячейка с таким номером уже существует.']],
        'guards' => [
            ['sql' => 'SELECT COUNT(*) FROM item_slots WHERE slot_number = ?', 'message' => 'Нельзя удалить ячейку, которая используется предметами.'],
            ['sql' => 'SELECT COUNT(*) FROM slot_rules WHERE slot_number = ?', 'message' => 'Нельзя удалить ячейку, для которой заданы правила.'],
        ],
        'counts' => [
            'Предметы' => 'SELECT COUNT(*) FROM item_slots s WHERE s.slot_number = t.slot_number',
            'Правила'  => 'SELECT COUNT(*) FROM slot_rules r WHERE r.slot_number = t.slot_number',
        ],
        'columns' => [
            'Номер' => 'slot_number',
            'Название' => 'name',
            'Колонка' => 'column_no',
            'Пара' => fn($r) => $r['pair_group'] === null ? '—' : (int)$r['pair_group'],
            'Предметы' => '__cnt0',
            'Правила' => '__cnt1',
            'Порядок' => 'sort_order',
        ],
        'warn' => 'Удалить ячейку? Она исчезнет из блока экипировки.',
    ],

    'slot_columns' => [
        'table' => 'slot_columns', 'title' => 'Колонки ячеек',
        'pk' => 'column_no', 'order' => 't.sort_order, t.column_no',
        'intro' => 'Колонки блока ячеек. Выравнивание «по правому краю» нужно для колонки, где рядом с одиночными ячейками стоит парная группа.',
        'fields' => [
            'column_no' => fld('Номер колонки', 'int', ['min' => 1, 'maxv' => 255, 'required' => true]),
            'name' => fld('Название', 'text', ['max' => 60, 'default' => '']),
            'align' => fld('Выравнивание', 'enum', [
                'options' => ['left' => 'По левому краю', 'right' => 'По правому краю'],
                'default' => 'left', 'required' => true,
            ]),
            'sort_order' => fld('Порядок', 'int', ['default' => 0]),
        ],
        'unique' => [['cols' => ['column_no'], 'message' => 'Колонка с таким номером уже существует.']],
        'guards' => [
            ['sql' => 'SELECT COUNT(*) FROM slots WHERE column_no = ?', 'message' => 'Нельзя удалить колонку, в которой есть ячейки.'],
        ],
        'counts' => ['Ячейки' => 'SELECT COUNT(*) FROM slots s WHERE s.column_no = t.column_no'],
        'columns' => [
            'Номер' => 'column_no',
            'Название' => fn($r) => $r['name'] !== '' ? $r['name'] : '—',
            'Выравнивание' => fn($r) => $r['align'] === 'right' ? 'по правому краю' : 'по левому краю',
            'Ячейки' => '__cnt0',
            'Порядок' => 'sort_order',
        ],
        'warn' => 'Удалить колонку?',
    ],

    /* ======================= МЕХАНИКА ======================= */

    'stats' => [
        'table' => 'stats', 'title' => 'Характеристики', 'order' => 't.category, t.sort_order, t.id',
        'intro' => 'Alias — дополнительное название для поиска. Базовые характеристики описывают колонки таблицы items и выводятся у предмета до бонусных; в бонусах они не участвуют.',
        'fields' => [
            'name_ru'  => fld('Название', 'text', ['max' => 120, 'required' => true]),
            'slug'     => fld('Slug', 'text', ['max' => 64, 'required' => true, 'slug' => true]),
            'alias'    => fld('Alias для поиска', 'text', ['max' => 150, 'null' => true]),
            'category' => fld('Категория', 'text', ['max' => 50, 'required' => true]),
            'supports_flat'    => fld('Поддерживает плоское значение', 'bool', ['default' => 1]),
            'supports_percent' => fld('Поддерживает процентное значение', 'bool', ['default' => 0]),
            'stamp_flat_min_coef' => fld('Печать: flat мин. коэффициент', 'decimal', [
                'min' => 0, 'maxv' => 9999, 'decimals' => 4, 'step' => '0.0001',
                'default' => '1.0000', 'required' => true,
                'help' => 'Множитель плоского значения на нижней границе уровня печати.',
            ]),
            'stamp_flat_max_coef' => fld('Печать: flat макс. коэффициент', 'decimal', [
                'min' => 0, 'maxv' => 9999, 'decimals' => 4, 'step' => '0.0001',
                'default' => '1.0000', 'required' => true,
                'help' => 'Множитель плоского значения на верхней границе. 1 и 1 отключают масштабирование.',
            ]),
            'stamp_percent_min_coef' => fld('Печать: % мин. коэффициент', 'decimal', [
                'min' => 0, 'maxv' => 9999, 'decimals' => 4, 'step' => '0.0001',
                'default' => '1.0000', 'required' => true,
                'help' => 'Множитель процентного значения на нижней границе уровня печати.',
            ]),
            'stamp_percent_max_coef' => fld('Печать: % макс. коэффициент', 'decimal', [
                'min' => 0, 'maxv' => 9999, 'decimals' => 4, 'step' => '0.0001',
                'default' => '1.0000', 'required' => true,
                'help' => 'Множитель процентного значения на верхней границе. Обычно равен минимальному.',
            ]),
            'is_base' => fld('Базовая характеристика предмета', 'bool', ['default' => 0, 'id' => 'is_base']),
            'base_field' => fld('Поле предмета', 'text', [
                'max' => 40, 'null' => true, 'id' => 'base_field',
                'help' => 'Колонка таблицы items, например base_attack',
            ]),
            'base_format' => fld('Формат вывода', 'enum', [
                'id' => 'base_format', 'empty' => '— не выбран —',
                'options' => [
                    'plain'           => 'Как есть',
                    'upgrade_attack'  => 'С прибавкой от улучшения (атака)',
                    'upgrade_defense' => 'С прибавкой от улучшения (защита)',
                    'dps'             => 'Пересчёт DPS от атаки',
                    'text'            => 'Текст с проверкой функции',
                ],
            ]),
            'sort_order' => fld('Порядок', 'int', ['default' => 0]),
        ],
        'unique' => [
            ['cols' => ['slug'], 'message' => 'Характеристика с таким slug уже существует.'],
            ['cols' => ['base_field'], 'message' => 'Это поле предмета уже описано другой базовой характеристикой.'],
        ],
        'validate' => function (array &$d, $id, PDO $pdo): void {
            if ((float)$d['stamp_flat_min_coef'] > (float)$d['stamp_flat_max_coef']
                || (float)$d['stamp_percent_min_coef'] > (float)$d['stamp_percent_max_coef']) {
                throw new RuntimeException('Минимальный коэффициент печати не может быть больше максимального.');
            }
            if (!$d['supports_flat'] && !$d['supports_percent']) {
                throw new RuntimeException('Характеристика должна поддерживать хотя бы один тип значения.');
            }
            if (!$d['is_base']) {
                $d['base_field'] = null;
                $d['base_format'] = null;
                return;
            }
            if ($d['base_field'] === null || $d['base_field'] === '') {
                throw new RuntimeException('Для базовой характеристики укажите поле предмета.');
            }
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $d['base_field'])) {
                throw new RuntimeException('Имя поля может содержать только строчные латинские буквы, цифры и подчёркивание.');
            }
            if ($d['base_format'] === null) {
                throw new RuntimeException('Для базовой характеристики выберите формат вывода.');
            }
            $st = $pdo->prepare("
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'items' AND COLUMN_NAME = ?
            ");
            $st->execute([$d['base_field']]);
            if (!(int)$st->fetchColumn()) {
                throw new RuntimeException('В таблице items нет поля «' . $d['base_field'] . '».');
            }
        },
        'guards' => [
            ['sql' => 'SELECT COUNT(*) FROM item_bonuses WHERE stat_id = ?', 'message' => 'Характеристика используется в бонусах предметов.'],
            ['sql' => 'SELECT COUNT(*) FROM card_bonuses WHERE stat_id = ?', 'message' => 'Характеристика используется в бонусах карт.'],
            ['sql' => 'SELECT COUNT(*) FROM card_set_bonuses WHERE stat_id = ?', 'message' => 'Характеристика используется в бонусах сетов.'],
            ['sql' => 'SELECT COUNT(*) FROM item_bonuses WHERE stat_source_id = ?', 'message' => 'Характеристика используется как источник в бонусах предметов.'],
            ['sql' => 'SELECT COUNT(*) FROM card_bonuses WHERE stat_source_id = ?', 'message' => 'Характеристика используется как источник в бонусах карт.'],
            ['sql' => 'SELECT COUNT(*) FROM card_bonus_stat_sources WHERE stat_id = ?', 'message' => 'Характеристика используется как мульти-источник в бонусах карт.'],
        ],
        'counts' => [
            'Предметы' => 'SELECT COUNT(*) FROM item_bonuses b WHERE b.stat_id = t.id',
            'Карты'    => 'SELECT COUNT(*) FROM card_bonuses b WHERE b.stat_id = t.id',
            'Сеты'     => 'SELECT COUNT(*) FROM card_set_bonuses b WHERE b.stat_id = t.id',
        ],
        'columns' => [
            'ID' => 'id',
            'Название' => 'name_ru',
            'Slug' => 'slug',
            'Alias' => fn($r) => $r['alias'] ?? '—',
            'Категория' => 'category',
            'Тип' => fn($r) => (int)$r['is_base'] === 1 ? 'базовая' : 'бонусная',
            'Поле / формат' => fn($r) => $r['base_field'] ? $r['base_field'] . ' · ' . $r['base_format'] : '—',
            'Плоское' => fn($r) => (int)$r['supports_flat'] ? 'да' : 'нет',
            'Процент' => fn($r) => (int)$r['supports_percent'] ? 'да' : 'нет',
            'Печать: flat' => fn($r) => rtrim(rtrim((string)$r['stamp_flat_min_coef'], '0'), '.') . ' → ' . rtrim(rtrim((string)$r['stamp_flat_max_coef'], '0'), '.'),
            'Печать: %' => fn($r) => rtrim(rtrim((string)$r['stamp_percent_min_coef'], '0'), '.') . ' → ' . rtrim(rtrim((string)$r['stamp_percent_max_coef'], '0'), '.'),
            'Предметы' => '__cnt0',
            'Карты' => '__cnt1',
            'Сеты' => '__cnt2',
            'Порядок' => 'sort_order',
        ],
        'warn' => 'Удалить характеристику? Базовая характеристика перестанет выводиться у предметов.',
    ],

    'upgrade_levels' => [
        'table' => 'upgrade_levels', 'title' => 'Уровни улучшения',
        'pk' => 'level', 'order' => 't.level',
        'intro' => 'Проценты добавляются к базовой атаке и защите предмета. Режим округления задаётся в «Параметрах». Максимальный уровень улучшения в калькуляторе равен максимальному значению «Уровень» в этой таблице.',
        'fields' => [
            'level' => fld('Уровень улучшения', 'int', ['min' => 0, 'maxv' => 255, 'required' => true]),
            'attack_percent'  => fld('Атака, %', 'decimal', ['min' => -9999.99, 'maxv' => 9999.99, 'default' => '0.00', 'required' => true]),
            'defense_percent' => fld('Защита, %', 'decimal', ['min' => -9999.99, 'maxv' => 9999.99, 'default' => '0.00', 'required' => true]),
        ],
        'unique' => [['cols' => ['level'], 'message' => 'Такой уровень улучшения уже существует.']],
        'columns' => [
            'Уровень' => 'level',
            'Атака, %' => 'attack_percent',
            'Защита, %' => 'defense_percent',
        ],
        'empty' => 'Уровни улучшения не заданы. Предметы нельзя будет улучшать.',
        'warn' => 'Удалить уровень улучшения? Если это максимальный уровень, предел улучшения в калькуляторе уменьшится.',
    ],

    'grades' => [
        'table' => 'grades', 'title' => 'Грейды',
        'pk' => 'code', 'pk_type' => 'string', 'pk_case' => 'upper', 'order' => 't.sort_order, t.code',
        'intro' => 'Грейды предметов. Порядок задаёт сортировку в списке предметов и в калькуляторе. Пустое «Макс. уровень улучшения» означает отсутствие ограничения. Код используется предметами и картами, поэтому при редактировании заблокирован.',
        'fields' => [
            'code' => fld('Код', 'text', ['max' => 8, 'required' => true, 'slug' => true]),
            'name' => fld('Название', 'text', ['max' => 30, 'required' => true]),
            'sort_order' => fld('Порядок', 'int', ['default' => 0]),
            'max_upgrade_level' => fld('Макс. уровень улучшения', 'int', [
                'null' => true, 'min' => 0, 'maxv' => 255,
                'help' => 'Пусто = без ограничения',
            ]),
        ],
        'unique' => [['cols' => ['code'], 'message' => 'Грейд с таким кодом уже существует.']],
        'guards' => [
            ['sql' => 'SELECT COUNT(*) FROM items WHERE grade = ?', 'message' => 'Нельзя удалить грейд, который используется предметами.'],
            ['sql' => 'SELECT COUNT(*) FROM card_allowed_grades WHERE grade = ?', 'message' => 'Нельзя удалить грейд, который разрешён для карт.'],
        ],
        'counts' => [
            'Предметы' => 'SELECT COUNT(*) FROM items i WHERE i.grade = t.code',
            'Карты' => 'SELECT COUNT(*) FROM card_allowed_grades cg WHERE cg.grade = t.code',
        ],
        'columns' => [
            'Код' => 'code',
            'Название' => 'name',
            'Макс. улучшение' => fn($r) => $r['max_upgrade_level'] === null ? 'без ограничения' : (int)$r['max_upgrade_level'],
            'Предметы' => '__cnt0',
            'Карты' => '__cnt1',
            'Порядок' => 'sort_order',
        ],
        'warn' => 'Удалить грейд? Он исчезнет из списков выбора у предметов и карт.',
    ],

    'features' => [
        'table' => 'features', 'title' => 'Функции', 'order' => 't.sort_order, t.id',
        'intro' => 'Если у функции указан ID Checkbox, она управляется переключателем в модальном окне. Если указана вкладка — отображается только на ней. Без ID Checkbox функция считается выключенной.',
        'fields' => [
            'slug' => fld('Slug', 'text', ['max' => 64, 'required' => true, 'slug' => true]),
            'name' => fld('Название', 'text', ['max' => 120, 'required' => true]),
            'checkbox_id' => fld('ID Checkbox', 'int', ['null' => true, 'min' => 1, 'help' => 'Необязательно']),
            'tab_id' => fld('Вкладка', 'ref', [
                'null' => true, 'empty' => 'Все вкладки',
                'options' => fn(PDO $pdo) => db_options($pdo, 'SELECT id, name FROM modal_tabs ORDER BY sort_order, id'),
            ]),
            'sort_order' => fld('Порядок', 'int', ['default' => 0]),
        ],
        'unique' => [
            ['cols' => ['slug'], 'message' => 'Функция с таким slug уже существует.'],
            ['cols' => ['checkbox_id'], 'message' => 'Такой ID Checkbox уже используется другой функцией.'],
        ],
        'counts' => [
            'Бонусы предметов' => 'SELECT COUNT(*) FROM item_bonuses b WHERE b.feature_id = t.id',
            'Бонусы карт' => 'SELECT COUNT(*) FROM card_bonuses b WHERE b.feature_id = t.id',
            'Описания' => 'SELECT COUNT(*) FROM items i WHERE i.description_feature_id = t.id',
        ],
        'warn' => 'Удалить функцию? Связанные бонусы и описания потеряют функцию.',
    ],

    'text_notes' => [
        'table' => 'text_notes', 'title' => 'Текстовые заметки', 'order' => 't.sort_order, t.id',
        'intro' => 'Название используется только в админке. Текст отображается на фронте под характеристикой, когда условие зависимости выполнено.',
        'fields' => [
            'title' => fld('Название для админки', 'text', ['max' => 150, 'required' => true]),
            'text'  => fld('Текст', 'textarea', ['max' => 500, 'required' => true, 'wide' => true]),
            'sort_order' => fld('Порядок', 'int', ['default' => 0]),
        ],
        'counts' => [
            'Бонусы предметов' => 'SELECT COUNT(*) FROM item_bonuses b WHERE b.text_note_id = t.id',
            'Бонусы карт' => 'SELECT COUNT(*) FROM card_bonuses b WHERE b.text_note_id = t.id',
        ],
        'warn' => 'Удалить заметку? Связи в характеристиках будут очищены.',
    ],

    /* ======================= ИНТЕРФЕЙС ======================= */

    'enum_labels' => [
        'table' => 'enum_labels', 'title' => 'Подписи кодов',
        'order' => 't.group_code, t.sort_order, t.id',
        'allow_delete' => false,
        'intro' => 'Названия технических кодов и тексты причин. Код и группа заданы схемой БД и кодом — при редактировании они заблокированы, удалять записи нельзя. Области: item — бонусы предметов, card — бонусы карт, set — сетовые бонусы, character — пол персонажа. В группе «Текст причины» доступны подстановки: {name}, {step}, {threshold}, {source}, {value}, {parts}, {conditions}, {count}, {types}, {classes}, {required}, {multiplier}, {valueSuffix}. Пробелы и знаки препинания вокруг фрагментов-связок добавляет код.',
        'fields' => [
            'group_code' => fld('Группа', 'enum', [
                'options' => [
                    'scaling_type'  => 'Тип масштабирования',
                    'restriction'   => 'Ограничение ячейки',
                    'negative_mode' => 'Режим отрицательных значений сета',
                    'gender'        => 'Пол',
                    'value_type'    => 'Тип значения',
                    'reason'        => 'Текст причины / подсказки',
                ],
                'required' => true,
                'locked' => true,
                'locked_help' => 'Группа задана кодом.',
            ]),
            'code' => fld('Код', 'text', [
                'max' => 40, 'required' => true, 'slug' => true,
                'locked' => true, 'locked_help' => 'Код задан схемой БД.',
            ]),
            'name' => fld('Название', 'text', ['max' => 120, 'required' => true]),
            'scope' => fld('Области', 'text', [
                'max' => 64, 'required' => true, 'default' => 'item,card',
                'help' => 'Через запятую: item, card, set, character',
            ]),
            'sort_order' => fld('Порядок', 'int', ['default' => 0]),
        ],
        'unique' => [['cols' => ['group_code', 'code'], 'message' => 'Такой код в этой группе уже существует.']],
        'validate' => function (array &$d): void {
            $tokens = array_values(array_unique(array_filter(array_map(
                'trim', explode(',', (string)$d['scope'])
            ))));
            if (!$tokens) throw new RuntimeException('Укажите хотя бы одну область.');
            $allowed = enum_scopes();
            foreach ($tokens as $t) {
                if (!in_array($t, $allowed, true)) {
                    throw new RuntimeException('Неизвестная область «' . $t . '». Допустимо: ' . implode(', ', $allowed) . '.');
                }
            }
            $d['scope'] = implode(',', $tokens);
        },
        'columns' => [
            'Группа' => 'group_code',
            'Код' => 'code',
            'Название' => 'name',
            'Области' => 'scope',
            'Порядок' => 'sort_order',
        ],
    ],

    'settings' => [
        'table' => 'settings', 'title' => 'Параметры',
        'pk' => 'key', 'pk_type' => 'string', 'order' => 't.`key`',
        'intro' => 'Общие лимиты калькулятора. Системные ключи используются кодом — их нельзя удалить или переименовать, но значение менять можно. После изменения обновите страницу калькулятора.',
        'fields' => [
            'key'   => fld('Ключ', 'text', ['max' => 64, 'required' => true, 'slug' => true]),
            'value' => fld('Значение', 'text', ['max' => 255, 'required' => true]),
            'type'  => fld('Тип', 'enum', [
                'options' => ['int' => 'Целое число', 'string' => 'Строка', 'bool' => 'Да / нет'],
                'default' => 'string', 'required' => true,
            ]),
            'note'  => fld('Описание', 'text', ['max' => 255, 'wide' => true]),
        ],
        'unique' => [['cols' => ['key'], 'message' => 'Параметр с таким ключом уже существует.']],
        'validate' => function (array &$d, $key, PDO $pdo): void {
            $system = ['max_sockets', 'max_character_level', 'max_set_cards', 'upgrade_rounding',
                'stamp_quality_min_level', 'stamp_quality_max_level', 'stamp_value_level', 'stamp_round_tolerance'];
            if ((string)$key !== '' && in_array((string)$key, $system, true) && $d['key'] !== (string)$key) {
                throw new RuntimeException('Ключ системного параметра изменять нельзя.');
            }
            if ($d['type'] === 'int' && !preg_match('/^-?\d+$/', $d['value'])) {
                throw new RuntimeException('Для типа «Целое число» значение должно быть целым.');
            }
            if ($d['type'] === 'bool' && !in_array(strtolower($d['value']), ['0', '1', 'true', 'false', 'yes', 'no', 'on', 'off'], true)) {
                throw new RuntimeException('Для типа «Да / нет» значение должно быть 1 или 0.');
            }
            if ($d['key'] === 'upgrade_rounding' && !in_array($d['value'], ['ceil', 'round', 'floor'], true)) {
                throw new RuntimeException('Округление может быть только ceil, round или floor.');
            }
            $ranges = [
                'max_sockets' => [0, 16],
                'max_character_level' => [1, 255],
                'max_set_cards' => [1, 255],
                'stamp_quality_min_level' => [0, 65535],
                'stamp_quality_max_level' => [1, 65535],
                'stamp_value_level' => [1, 65535],
            ];
            if ($d['type'] === 'int' && isset($ranges[$d['key']])) {
                [$lo, $hi] = $ranges[$d['key']];
                $v = (int)$d['value'];
                if ($v < $lo || $v > $hi) {
                    throw new RuntimeException('Значение должно быть от ' . $lo . ' до ' . $hi . '.');
                }
            }
            if ($d['key'] === 'stamp_round_tolerance') {
                $raw = str_replace(',', '.', $d['value']);
                if (!is_numeric($raw) || (float)$raw <= 0 || (float)$raw > 1) {
                    throw new RuntimeException('Допуск округления печати должен быть числом в диапазоне (0, 1].');
                }
            }
            if (in_array($d['key'], ['stamp_quality_min_level', 'stamp_quality_max_level'], true)) {
                $otherKey = $d['key'] === 'stamp_quality_min_level' ? 'stamp_quality_max_level' : 'stamp_quality_min_level';
                $st = $pdo->prepare('SELECT `value` FROM settings WHERE `key` = ?');
                $st->execute([$otherKey]);
                $otherVal = $st->fetchColumn();
                if ($otherVal !== false) {
                    $lo = $d['key'] === 'stamp_quality_min_level' ? (int)$d['value'] : (int)$otherVal;
                    $hi = $d['key'] === 'stamp_quality_max_level' ? (int)$d['value'] : (int)$otherVal;
                    if ($lo >= $hi) {
                        throw new RuntimeException('Нижняя граница уровня печати должна быть меньше верхней.');
                    }
                }
            }
        },
        'guards' => [[
            'sql' => "SELECT COUNT(*) FROM settings WHERE `key` = ?
                AND `key` IN ('max_sockets','max_character_level','max_set_cards','upgrade_rounding',
                    'stamp_quality_min_level','stamp_quality_max_level','stamp_value_level','stamp_round_tolerance')",
            'message' => 'Этот параметр используется кодом и не может быть удалён.',
        ]],
        'columns' => [
            'Ключ' => 'key',
            'Значение' => 'value',
            'Тип' => fn($r) => ['int' => 'Целое', 'string' => 'Строка', 'bool' => 'Да / нет'][$r['type']] ?? $r['type'],
            'Описание' => fn($r) => $r['note'] !== '' ? $r['note'] : '—',
        ],
        'warn' => 'Удалить параметр? Код будет использовать значение по умолчанию.',
    ],

    'modal_tabs' => [
        'table' => 'modal_tabs', 'title' => 'Вкладки модального окна', 'order' => 't.sort_order, t.id',
        'intro' => 'Вкладки в левой колонке окна выбора. Slug «items» и «cards» — служебные: калькулятор ориентируется на них, поэтому их нельзя удалять или переименовывать.',
        'fields' => [
            'slug' => fld('Slug', 'text', ['max' => 64, 'required' => true, 'slug' => true, 'help' => 'Латиница, цифры, подчёркивание']),
            'name' => fld('Название', 'text', ['max' => 120, 'required' => true]),
            'sort_order' => fld('Порядок', 'int', ['default' => 0]),
        ],
        'unique' => [['cols' => ['slug'], 'message' => 'Вкладка с таким slug уже существует.']],
        'validate' => function (array &$d, $key, PDO $pdo): void {
            if ((int)$key <= 0) return;
            $st = $pdo->prepare('SELECT slug FROM modal_tabs WHERE id = ?');
            $st->execute([(int)$key]);
            $old = (string)$st->fetchColumn();
            if (in_array($old, ['items', 'cards'], true) && $d['slug'] !== $old) {
                throw new RuntimeException('Slug служебной вкладки «' . $old . '» изменять нельзя.');
            }
        },
        'guards' => [[
            'sql' => "SELECT COUNT(*) FROM modal_tabs WHERE id = ? AND slug IN ('items', 'cards')",
            'message' => 'Служебные вкладки «items» и «cards» удалять нельзя — на них завязан калькулятор.',
        ]],
        'counts' => ['Функции' => 'SELECT COUNT(*) FROM features f WHERE f.tab_id = t.id'],
        'warn' => 'Удалить вкладку? Функции, привязанные к ней, станут общими для всех вкладок.',
    ],
    ];
}

/* ======================= Ключи записи ======================= */

function crud_pk(array $c): string
{
    return $c['pk'] ?? 'id';
}

function crud_pk_is_string(array $c): bool
{
    return ($c['pk_type'] ?? 'int') === 'string';
}

function crud_pk_is_natural(array $c): bool
{
    return isset($c['fields'][crud_pk($c)]);
}

function crud_empty_key(array $c)
{
    return crud_pk_is_string($c) ? '' : 0;
}

function crud_request_key(array $c, array $src)
{
    $raw = trim((string)($src[crud_pk($c)] ?? ''));
    if (!crud_pk_is_string($c)) {
        return (int)$raw;
    }
    return ($c['pk_case'] ?? '') === 'upper' ? strtoupper($raw) : $raw;
}

function crud_key_filled(array $c, $key): bool
{
    return crud_pk_is_string($c) ? ((string)$key !== '') : ((int)$key > 0);
}

function crud_assert_key_exists(PDO $pdo, array $c, $key): void
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM ' . $c['table'] . ' WHERE `' . crud_pk($c) . '` = ?');
    $st->execute([$key]);
    if (!(int)$st->fetchColumn()) throw new RuntimeException('Запись не найдена.');
}

/* ======================= Чтение и валидация ======================= */

function crud_options(PDO $pdo, array $fields, $id = 0): array
{
    $out = [];
    foreach ($fields as $name => $f) {
        if (!in_array($f['type'], ['enum', 'ref', 'checks'], true)) continue;
        $o = $f['options'] ?? [];
        $out[$name] = is_callable($o) ? $o($pdo, $id) : $o;
    }
    return $out;
}

function crud_read(array $fields, array $options): array
{
    $data = [];
    foreach ($fields as $name => $f) {
        $label = $f['label'];
        $raw = $_POST[$name] ?? null;
        switch ($f['type']) {
            case 'bool':
                $data[$name] = isset($_POST[$name]) ? 1 : 0;
                break;

            case 'int':
                $s = trim((string)$raw);
                if ($s === '') {
                    if (!empty($f['required'])) throw new RuntimeException('Заполните поле «' . $label . '».');
                    $data[$name] = !empty($f['null']) ? null : (int)($f['default'] ?? 0);
                    break;
                }
                if (!preg_match('/^-?\d+$/', $s)) throw new RuntimeException('Поле «' . $label . '» должно быть целым числом.');
                $v = (int)$s;
                if (isset($f['min']) && $v < $f['min']) throw new RuntimeException('Поле «' . $label . '»: минимум ' . $f['min'] . '.');
                if (isset($f['maxv']) && $v > $f['maxv']) throw new RuntimeException('Поле «' . $label . '»: максимум ' . $f['maxv'] . '.');
                $data[$name] = $v;
                break;

            case 'decimal':
                $s = str_replace(',', '.', trim((string)$raw));
                if ($s === '') {
                    if (!empty($f['required'])) throw new RuntimeException('Заполните поле «' . $label . '».');
                    $data[$name] = !empty($f['null']) ? null : (string)($f['default'] ?? '0');
                    break;
                }
                if (!is_numeric($s)) throw new RuntimeException('Поле «' . $label . '» должно быть числом.');
                $v = (float)$s;
                if (isset($f['min']) && $v < $f['min']) throw new RuntimeException('Поле «' . $label . '»: минимум ' . $f['min'] . '.');
                if (isset($f['maxv']) && $v > $f['maxv']) throw new RuntimeException('Поле «' . $label . '»: максимум ' . $f['maxv'] . '.');
                $data[$name] = number_format($v, (int)($f['decimals'] ?? 2), '.', '');
                break;

            case 'checks':
                $data[$name] = array_values(array_unique(array_map('intval', (array)($_POST[$name] ?? []))));
                break;

            case 'enum':
            case 'ref':
                $s = trim((string)$raw);
                $allowed = $options[$name] ?? [];
                if ($s === '' || ($f['type'] === 'ref' && $s === '0')) {
                    if (!empty($f['required'])) throw new RuntimeException('Выберите значение в поле «' . $label . '».');
                    $data[$name] = null;
                    break;
                }
                if ($f['type'] === 'ref' || ($f['cast'] ?? '') === 'int') {
                    $key = (int)$s;
                } else {
                    $key = $s;
                }
                if (!array_key_exists($key, $allowed)) throw new RuntimeException('Некорректное значение поля «' . $label . '».');
                $data[$name] = $key;
                break;

            default:
                $s = trim((string)$raw);
                if ($s === '' && !empty($f['required'])) throw new RuntimeException('Заполните поле «' . $label . '».');
                if (isset($f['max']) && mb_strlen($s) > $f['max']) {
                    throw new RuntimeException('Поле «' . $label . '» не должно превышать ' . $f['max'] . ' символов.');
                }
                if (!empty($f['slug']) && $s !== '' && !preg_match('/^[a-z0-9_]+$/i', $s)) {
                    throw new RuntimeException('Slug может содержать только латиницу, цифры и нижнее подчёркивание.');
                }
                $data[$name] = ($s === '' && !empty($f['null'])) ? null : $s;
        }
    }
    return $data;
}

function crud_check_unique(array $c, PDO $pdo, array $data, $key, bool $isEdit): void
{
    foreach ($c['unique'] ?? [] as $rule) {
        $where = [];
        $params = [];
        $skip = false;
        foreach ($rule['cols'] as $col) {
            $val = $data[$col] ?? null;
            if ($val === null) { $skip = true; break; }
            $where[] = "`$col` = ?";
            $params[] = $val;
        }
        if ($skip || !$where) continue;
        if ($isEdit) {
            $where[] = '`' . crud_pk($c) . '` <> ?';
            $params[] = $key;
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM ' . $c['table'] . ' WHERE ' . implode(' AND ', $where));
        $st->execute($params);
        if ((int)$st->fetchColumn() > 0) throw new RuntimeException($rule['message']);
    }
}

/* ======================= Запись ======================= */

function crud_write(PDO $pdo, array $c, array $data, $key, bool $isEdit)
{
    $pk = crud_pk($c);
    $natural = crud_pk_is_natural($c);
    $table = $c['table'];

    if ($isEdit) {
        $set = $data;
        if ($natural) unset($set[$pk]);
        if (!$set) return $key;
        $cols = array_keys($set);
        $sql = 'UPDATE `' . $table . '` SET '
            . implode(', ', array_map(static fn($x) => "`$x` = ?", $cols))
            . ' WHERE `' . $pk . '` = ?';
        $pdo->prepare($sql)->execute([...array_values($set), $key]);
        return $key;
    }

    $cols = array_keys($data);
    $sql = 'INSERT INTO `' . $table . '` ('
        . implode(', ', array_map(static fn($x) => "`$x`", $cols))
        . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
    $pdo->prepare($sql)->execute(array_values($data));
    return $natural ? $data[$pk] : (int)$pdo->lastInsertId();
}

function crud_delete(array $c, PDO $pdo, $key): void
{
    if (!crud_key_filled($c, $key)) throw new RuntimeException('Некорректный ключ записи.');
    $pk = crud_pk($c);
    $st = $pdo->prepare('SELECT * FROM ' . $c['table'] . ' WHERE `' . $pk . '` = ?');
    $st->execute([$key]);
    $row = $st->fetch();
    if (!$row) throw new RuntimeException('Запись не найдена.');

    foreach ($c['guards'] ?? [] as $g) {
        $st = $pdo->prepare($g['sql']);
        $st->execute([$key]);
        if ((int)$st->fetchColumn() > 0) throw new RuntimeException($g['message']);
    }

    $pdo->beginTransaction();
    try {
        if (isset($c['before_delete'])) ($c['before_delete'])($pdo, $key, $row);
        $pdo->prepare('DELETE FROM ' . $c['table'] . ' WHERE `' . $pk . '` = ?')->execute([$key]);
        if (isset($c['after_delete'])) ($c['after_delete'])($pdo, $key, $row);
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/* ======================= Список ======================= */

function crud_rows(array $c, PDO $pdo): array
{
    if (!empty($c['list_sql'])) return $pdo->query($c['list_sql'])->fetchAll();
    $sel = 't.*';
    $i = 0;
    foreach ($c['counts'] ?? [] as $sql) { $sel .= ', (' . $sql . ') AS __cnt' . $i++; }
    return $pdo->query('SELECT ' . $sel . ' FROM ' . $c['table'] . ' t ORDER BY ' . $c['order'])->fetchAll();
}

function crud_cell(array $f, $value, array $options): string
{
    if ($value === null || $value === '') return (string)($f['empty'] ?? '—');
    if ($f['type'] === 'bool') return (int)$value === 1 ? 'да' : 'нет';
    if (in_array($f['type'], ['enum', 'ref'], true)) return (string)($options[$value] ?? $value);
    return (string)$value;
}

function crud_columns(array $c): array
{
    if (!empty($c['columns'])) return $c['columns'];
    $pk = crud_pk($c);
    $columns = ['ID' => $pk];
    foreach ($c['fields'] as $name => $f) {
        if ($name === $pk) continue;
        if (($f['list'] ?? true) === false) continue;
        $columns[$f['label']] = $name;
    }
    $i = 0;
    foreach ($c['counts'] ?? [] as $label => $sql) { $columns[$label] = '__cnt' . $i++; }
    return $columns;
}

/* ======================= Страница ======================= */

function crud_page(array $c, string $action, array $menu): void
{
    $pdo = db();
    $pk = crud_pk($c);
    $natural = crud_pk_is_natural($c);
    $values = null;
    $postedValues = false;
    $isEdit = false;
    $key = crud_empty_key($c);

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        verify_csrf();
        $formAction = (string)($_POST['form_action'] ?? '');
        $key = crud_request_key($c, $_POST);
        $isEdit = (string)($_POST['edit_mode'] ?? '') === '1';
        try {
            if ($formAction === 'delete') {
                if (($c['allow_delete'] ?? true) === false) {
                    throw new RuntimeException('Удаление записей в этом разделе запрещено.');
                }
                crud_delete($c, $pdo, $key);
                flash('success', 'Запись удалена.');
                redirect(admin_url($action));
            }
            if (isset($c['actions'][$formAction])) {
                ($c['actions'][$formAction]['handler'])($pdo, $key);
                flash('success', $c['actions'][$formAction]['done'] ?? 'Готово.');
                redirect(admin_url($action));
            }
            if ($formAction !== 'save') throw new RuntimeException('Неизвестное действие.');

            $options = crud_options($pdo, $c['fields'], $isEdit ? $key : crud_empty_key($c));
            $data = crud_read($c['fields'], $options);
            if ($isEdit) {
                $st = $pdo->prepare('SELECT * FROM ' . $c['table'] . ' WHERE `' . $pk . '` = ?');
                $st->execute([$key]);
                $original = $st->fetch();
                if (!$original) throw new RuntimeException('Запись не найдена.');
                foreach ($c['fields'] as $name => $f) {
                    if (!empty($f['locked'])) $data[$name] = $original[$name];
                }
                if ($natural) $data[$pk] = $key;
            }
            if (isset($c['validate'])) ($c['validate'])($data, $key, $pdo);
            if (isset($c['before_save'])) ($c['before_save'])($data, $key, $pdo);
            crud_check_unique($c, $pdo, $data, $key, $isEdit);
            $needsTx = false;
            foreach ($c['fields'] as $name => $f) {
                if (($f['type'] ?? '') === 'checks' && isset($f['save'])) { $needsTx = true; break; }
            }
            if ($needsTx) $pdo->beginTransaction();
            try {
                if ($isEdit) crud_assert_key_exists($pdo, $c, $key);
                $writeData = $data;
                foreach ($c['fields'] as $fieldName => $fieldDef) {
                    if (($fieldDef['type'] ?? '') === 'checks') {
                        unset($writeData[$fieldName]);
                    }
                }
                $key = crud_write($pdo, $c, $writeData, $key, $isEdit);
                foreach ($c['fields'] as $name => $f) {
                    if (($f['type'] ?? '') === 'checks' && isset($f['save'])) {
                        ($f['save'])($pdo, $key, $data[$name] ?? []);
                    }
                }
                if (isset($c['after_save'])) ($c['after_save'])($data, $key, $pdo);
                if ($needsTx) $pdo->commit();
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $ex;
            }
            flash('success', 'Запись сохранена.');
            redirect(admin_url($action));
        } catch (Throwable $ex) {
            flash('error', friendly_error($ex));
            if ($formAction !== 'save') redirect(admin_url($action));
            $values = $_POST;
            $postedValues = true;
        }
    }

    if ($values === null) {
        $getKey = crud_request_key($c, $_GET);
        if (crud_key_filled($c, $getKey)) {
            $st = $pdo->prepare('SELECT * FROM ' . $c['table'] . ' WHERE `' . $pk . '` = ?');
            $st->execute([$getKey]);
            $edit = $st->fetch();
            if (!$edit) { flash('error', 'Запись не найдена.'); redirect(admin_url($action)); }
            $values = $edit;
            $isEdit = true;
            $key = $getKey;
        }
    }

    $defaults = [];
    foreach ($c['fields'] as $name => $f) {
        $defaults[$name] = $f['default'] ?? ($f['type'] === 'bool' ? 0 : '');
    }
    $values = array_merge($defaults, $values ?? []);
    $options = crud_options($pdo, $c['fields'], $isEdit ? $key : crud_empty_key($c));
    foreach ($c['fields'] as $name => $f) {
        if (($f['type'] ?? '') !== 'checks') continue;
        if ($postedValues) continue;
        if ($isEdit) {
            $values[$name] = isset($f['load']) ? ($f['load'])($pdo, $key) : [];
        } else {
            $values[$name] = array_map('intval', array_keys($options[$name] ?? []));
        }
    }
    $listOptions = crud_options($pdo, $c['fields'], crud_empty_key($c));
    $rows = crud_rows($c, $pdo);
    $columns = crud_columns($c);
    $total = count($rows);
    $allowDelete = ($c['allow_delete'] ?? true) !== false;

    admin_layout_start($isEdit ? 'Редактирование — ' . $c['title'] : $c['title'], $action, $menu);
    ?>
<div class="card" id="crud-form">
<div class="card-header">
<h1><?= $isEdit ? 'Редактирование записи' : e($c['title']) ?></h1>
<div class="actions">
<?php if ($isEdit): ?>
<span class="badge badge-warn"><?= e($pk) ?>: <?= e($key) ?></span>
<a class="btn" href="<?= e(admin_url($action)) ?>"><?= admin_icon('close', 16) ?>Отменить правку</a>
<?php else: ?>
<span class="badge">Записей: <?= $total ?></span>
<?php endif; ?>
<a class="btn btn-ghost" href="<?= e(admin_url('dashboard')) ?>"><?= admin_icon('home', 16) ?>Обзор</a>
</div>
</div>
<?php if (!empty($c['intro'])): ?><p class="muted"><?= e($c['intro']) ?></p><?php endif; ?>
<form method="post" action="<?= e(admin_url($action)) ?>">
<input type="hidden" name="form_action" value="save">
<input type="hidden" name="edit_mode" value="<?= $isEdit ? 1 : 0 ?>">
<?php if (!$natural): ?><input type="hidden" name="<?= e($pk) ?>" value="<?= e($key) ?>"><?php endif; ?>
<?= csrf_field() ?>
<div class="grid">
<?php foreach ($c['fields'] as $name => $f):
    $fid = $f['id'] ?? $name;
    $val = $values[$name] ?? '';
    $wide = !empty($f['wide']) ? ' field-wide' : '';
    if (($natural && $isEdit && $name === $pk) || (!empty($f['locked']) && $isEdit)): ?>
<div class="field<?= $wide ?>">
<label><?= e($f['label']) ?></label>
<input type="text" value="<?= e($val) ?>" disabled>
<input type="hidden" name="<?= e($name) ?>" value="<?= e($val) ?>">
<div class="form-help"><?= e($f['locked_help'] ?? 'Ключ записи изменить нельзя.') ?></div>
</div>
<?php continue; endif;
    if ($f['type'] === 'bool'): ?>
<div class="field field-checkbox<?= $wide ?>">
<label><input type="checkbox" id="<?= e($fid) ?>" name="<?= e($name) ?>"<?= checked((int)$val === 1) ?>> <?= e($f['label']) ?></label>
<?php if (!empty($f['help'])): ?><div class="form-help"><?= e($f['help']) ?></div><?php endif; ?>
</div>
<?php continue; endif;
    if ($f['type'] === 'checks'): ?>
<div class="field<?= $wide ?>">
<label><?= e($f['label']) ?></label>
<div class="checkbox-grid">
<?php foreach (($options[$name] ?? []) as $k => $lbl): ?>
<label><input type="checkbox" name="<?= e($name) ?>[]" value="<?= (int)$k ?>"<?= checked(in_array((int)$k, array_map('intval', (array)$val), true)) ?>><span><?= e($lbl) ?></span></label>
<?php endforeach; ?>
</div>
<?php if (!empty($f['help'])): ?><div class="form-help"><?= e($f['help']) ?></div><?php endif; ?>
</div>
<?php continue; endif; ?>
<div class="field<?= $wide ?>">
<label for="<?= e($fid) ?>"><?= e($f['label']) ?></label>
<?php if ($f['type'] === 'textarea'): ?>
<textarea id="<?= e($fid) ?>" name="<?= e($name) ?>" rows="4"<?= isset($f['max']) ? ' maxlength="' . (int)$f['max'] . '"' : '' ?><?= !empty($f['required']) ? ' required' : '' ?>><?= e($val) ?></textarea>
<?php elseif ($f['type'] === 'enum' || $f['type'] === 'ref'): ?>
<select id="<?= e($fid) ?>" name="<?= e($name) ?>"<?= !empty($f['required']) ? ' required' : '' ?>>
<?php if (empty($f['required']) || isset($f['empty'])): ?>
<option value=""><?= e($f['empty'] ?? '— не указано —') ?></option>
<?php endif; ?>
<?php foreach (($options[$name] ?? []) as $k => $lbl): ?>
<option value="<?= e($k) ?>"<?= selected((string)$val === (string)$k) ?>><?= e($lbl) ?></option>
<?php endforeach; ?>
</select>
<?php else:
    $isNum = in_array($f['type'], ['int', 'decimal'], true); ?>
<input type="<?= $isNum ? 'number' : 'text' ?>" id="<?= e($fid) ?>" name="<?= e($name) ?>" value="<?= e($val) ?>"<?= $f['type'] === 'decimal' ? ' step="' . e($f['step'] ?? '0.01') . '"' : '' ?><?= isset($f['max']) ? ' maxlength="' . (int)$f['max'] . '"' : '' ?><?= isset($f['min']) ? ' min="' . e($f['min']) . '"' : '' ?><?= isset($f['maxv']) ? ' max="' . e($f['maxv']) . '"' : '' ?><?= !empty($f['required']) ? ' required' : '' ?>>
<?php endif; ?>
<?php if (!empty($f['help'])): ?><div class="form-help"><?= e($f['help']) ?></div><?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<div class="form-actions">
<button type="submit" class="btn btn-primary"><?= admin_icon('check', 16) ?><?= $isEdit ? 'Сохранить изменения' : 'Добавить запись' ?></button>
<?php if ($isEdit): ?>
<a class="btn" href="<?= e(admin_url($action)) ?>">Отмена</a>
<?php else: ?>
<span class="form-help">Сочетание <kbd>Ctrl</kbd> + <kbd>Enter</kbd> сохраняет форму.</span>
<?php endif; ?>
</div>
</form>
</div>

<div class="card">
<div class="card-header">
<h2>Записи</h2>
<div class="actions">
<span class="badge" data-filter-count>Записей: <?= $total ?></span>
<?php if ($isEdit): ?><a class="btn btn-primary" href="<?= e(admin_url($action)) ?>"><?= admin_icon('plus', 16) ?>Новая запись</a><?php endif; ?>
</div>
</div>
<?php if ($total > 0): ?>
<div class="filters">
<div class="filter-field">
<label for="crud-filter">Быстрый фильтр по таблице</label>
<input type="search" id="crud-filter" data-table-filter="crud-table" autocomplete="off"
       placeholder="Введите часть названия, кода или значения">
</div>
</div>
<?php endif; ?>
<table id="crud-table">
<thead><tr>
<?php foreach (array_keys($columns) as $label) echo '<th>' . e($label) . '</th>'; ?>
<th>Действия</th>
</tr></thead>
<tbody>
<?php if (!$rows): ?>
<tr><td colspan="<?= count($columns) + 1 ?>"><?= e($c['empty'] ?? 'Записей нет. Добавьте первую через форму выше.') ?></td></tr>
<?php endif; ?>
<?php foreach ($rows as $r):
    $rowKey = $r[$pk];
    $isCurrent = $isEdit && (string)$rowKey === (string)$key;
?>
<tr>
<?php foreach ($columns as $col):
    $v = is_callable($col) ? $col($r) : ($r[$col] ?? null);
    if (!is_callable($col) && isset($c['fields'][$col]) && empty($c['columns'])) {
        $v = crud_cell($c['fields'][$col], $v, $listOptions[$col] ?? []);
    }
?>
<td><?= e($v ?? '—') ?></td>
<?php endforeach; ?>
<td class="actions">
<?php if ($isCurrent): ?><span class="badge badge-warn">правится</span><?php endif; ?>
<?php if (isset($c['row_actions'])) echo ($c['row_actions'])($r); ?>
<?php foreach ($c['links'] ?? [] as $link): ?>
<a class="btn" href="<?= e(admin_url($link['action'], [$link['param'] => (int)$r['id']])) ?>"><?= e($link['label']) ?></a>
<?php endforeach; ?>
<a class="btn" href="<?= e(admin_url($action, [$pk => $rowKey])) ?>">Редактировать</a>
<?php if ($allowDelete): ?>
<form method="post" class="inline js-confirm" data-confirm="<?= e($c['warn'] ?? 'Удалить запись?') ?>">
<input type="hidden" name="form_action" value="delete">
<input type="hidden" name="<?= e($pk) ?>" value="<?= e($rowKey) ?>">
<?= csrf_field() ?>
<button type="submit" class="btn btn-danger"><?= admin_icon('trash', 15) ?>Удалить</button>
</form>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php if (!$allowDelete): ?>
<div class="admin-note">Удаление записей в этом разделе отключено: коды используются схемой БД и кодом калькулятора. Менять можно названия, порядок и области применимости.</div>
<?php endif; ?>
</div>
<?php
    admin_layout_end();
}