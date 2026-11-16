-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Хост: localhost
-- Время создания: Ноя 16 2026 г., 21:35
-- Версия сервера: 8.4.11-11
-- Версия PHP: 8.3.36

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- База данных: `kamikaze_calc`
--

-- --------------------------------------------------------

--
-- Структура таблицы `cards`
--

CREATE TABLE `cards` (
  `id` int UNSIGNED NOT NULL,
  `card_type_id` int UNSIGNED NOT NULL,
  `set_id` int UNSIGNED DEFAULT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `icon_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_set_main` tinyint(1) NOT NULL DEFAULT '0',
  `state_id` int UNSIGNED DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ;

-- --------------------------------------------------------

--
-- Структура таблицы `card_allowed_grades`
--

CREATE TABLE `card_allowed_grades` (
  `card_id` int UNSIGNED NOT NULL,
  `grade` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `card_allowed_item_types`
--

CREATE TABLE `card_allowed_item_types` (
  `card_id` int UNSIGNED NOT NULL,
  `item_type_id` int UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `card_bonuses`
--

CREATE TABLE `card_bonuses` (
  `id` int UNSIGNED NOT NULL,
  `card_id` int UNSIGNED NOT NULL,
  `stat_id` smallint UNSIGNED NOT NULL,
  `value` decimal(10,2) NOT NULL,
  `value_max` decimal(10,2) DEFAULT NULL,
  `value_type` enum('flat','percent') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'flat',
  `feature_id` int UNSIGNED DEFAULT NULL,
  `text_note_id` int UNSIGNED DEFAULT NULL,
  `state_id` int UNSIGNED DEFAULT NULL,
  `depends_on_card_id` int UNSIGNED DEFAULT NULL,
  `scaling_type` enum('none','per_level','per_level_above','once_above','per_every_n_levels','range','per_stat','stat_threshold','multiply_by_identical_cards') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `scaling_step` int UNSIGNED NOT NULL DEFAULT '1',
  `scaling_threshold` int UNSIGNED NOT NULL DEFAULT '0',
  `scaling_required_count` int UNSIGNED DEFAULT NULL,
  `scaling_multiplier` decimal(10,2) DEFAULT NULL,
  `item_type_condition_mode` enum('all','any') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'all',
  `stat_source_id` smallint UNSIGNED DEFAULT NULL,
  `stat_step` int UNSIGNED DEFAULT NULL,
  `stat_threshold` int UNSIGNED DEFAULT NULL,
  `stat_value_true` decimal(10,2) DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `stat_condition_mode` enum('all','any') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'all'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `card_bonus_class_restrictions`
--

CREATE TABLE `card_bonus_class_restrictions` (
  `card_bonus_id` int UNSIGNED NOT NULL,
  `class_id` int UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `card_bonus_item_type_conditions`
--

CREATE TABLE `card_bonus_item_type_conditions` (
  `card_bonus_id` int UNSIGNED NOT NULL,
  `item_type_id` int UNSIGNED NOT NULL,
  `required_count` tinyint UNSIGNED NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `card_bonus_item_type_overrides`
--

CREATE TABLE `card_bonus_item_type_overrides` (
  `card_bonus_id` int UNSIGNED NOT NULL,
  `item_type_id` int UNSIGNED NOT NULL,
  `value` decimal(10,2) NOT NULL,
  `value_max` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `card_bonus_item_type_restrictions`
--

CREATE TABLE `card_bonus_item_type_restrictions` (
  `card_bonus_id` int UNSIGNED NOT NULL,
  `item_type_id` int UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `card_bonus_stat_sources`
--

CREATE TABLE `card_bonus_stat_sources` (
  `id` int UNSIGNED NOT NULL,
  `card_bonus_id` int UNSIGNED NOT NULL,
  `stat_id` smallint UNSIGNED NOT NULL,
  `step` int UNSIGNED NOT NULL DEFAULT '1',
  `sort_order` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `card_sets`
--

CREATE TABLE `card_sets` (
  `id` int UNSIGNED NOT NULL,
  `slug` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `negative_threshold` tinyint UNSIGNED NOT NULL DEFAULT '1',
  `negative_below_mode` enum('allow','ignore','absolute') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'allow',
  `negative_reached_mode` enum('allow','ignore','absolute') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'allow',
  `sort_order` int NOT NULL DEFAULT '0'
) ;

-- --------------------------------------------------------

--
-- Структура таблицы `card_set_bonuses`
--

CREATE TABLE `card_set_bonuses` (
  `id` int UNSIGNED NOT NULL,
  `set_id` int UNSIGNED NOT NULL,
  `required_count` tinyint UNSIGNED NOT NULL,
  `stat_id` smallint UNSIGNED NOT NULL,
  `value` decimal(10,2) NOT NULL,
  `value_type` enum('flat','percent') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'flat',
  `sort_order` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `card_types`
--

CREATE TABLE `card_types` (
  `id` int UNSIGNED NOT NULL,
  `slug` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `socket_number` tinyint UNSIGNED DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0'
) ;

-- --------------------------------------------------------

--
-- Структура таблицы `classes`
--

CREATE TABLE `classes` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `parent_id` int UNSIGNED DEFAULT NULL,
  `min_level` tinyint UNSIGNED NOT NULL DEFAULT '1',
  `max_level` tinyint UNSIGNED NOT NULL DEFAULT '65',
  `sort_order` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `enum_labels`
--

CREATE TABLE `enum_labels` (
  `id` int UNSIGNED NOT NULL,
  `group_code` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `scope` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'item,card',
  `sort_order` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `features`
--

CREATE TABLE `features` (
  `id` int UNSIGNED NOT NULL,
  `checkbox_id` int UNSIGNED DEFAULT NULL,
  `tab_id` int UNSIGNED DEFAULT NULL,
  `slug` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `grades`
--

CREATE TABLE `grades` (
  `code` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `max_upgrade_level` tinyint UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `items`
--

CREATE TABLE `items` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'Текстовая характеристика предмета, не влияет на параметры',
  `description_feature_id` int UNSIGNED DEFAULT NULL,
  `icon_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_type_id` int UNSIGNED NOT NULL,
  `grade` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `required_level` tinyint UNSIGNED DEFAULT NULL,
  `internal_level` smallint UNSIGNED DEFAULT NULL,
  `gender` enum('any','male','female') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'any',
  `base_defense` int UNSIGNED NOT NULL DEFAULT '0',
  `base_attack` int UNSIGNED NOT NULL DEFAULT '0',
  `base_attack_speed` decimal(8,2) NOT NULL DEFAULT '0.00',
  `base_dps` decimal(10,2) NOT NULL DEFAULT '0.00',
  `sockets` tinyint UNSIGNED NOT NULL DEFAULT '0',
  `can_upgrade` tinyint(1) NOT NULL DEFAULT '0',
  `state_id` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ;

-- --------------------------------------------------------

--
-- Структура таблицы `item_bonuses`
--

CREATE TABLE `item_bonuses` (
  `id` int UNSIGNED NOT NULL,
  `item_id` int UNSIGNED NOT NULL,
  `stat_id` smallint UNSIGNED NOT NULL,
  `value` decimal(10,2) NOT NULL COMMENT 'Может быть положительным или отрицательным',
  `value_type` enum('flat','percent') COLLATE utf8mb4_unicode_ci NOT NULL,
  `feature_id` int UNSIGNED DEFAULT NULL,
  `text_note_id` int UNSIGNED DEFAULT NULL,
  `state_id` int UNSIGNED DEFAULT NULL,
  `scaling_type` enum('none','per_level','per_level_above','once_above','per_every_n_levels','per_stat','stat_threshold') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `scaling_step` int UNSIGNED NOT NULL DEFAULT '1',
  `scaling_threshold` int UNSIGNED NOT NULL DEFAULT '0',
  `item_type_condition_mode` enum('all','any') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'all',
  `stat_source_id` smallint UNSIGNED DEFAULT NULL,
  `stat_step` int UNSIGNED DEFAULT NULL,
  `stat_threshold` int UNSIGNED DEFAULT NULL,
  `stat_value_true` decimal(10,2) DEFAULT NULL,
  `feature_key` int UNSIGNED NOT NULL DEFAULT '0',
  `sort_order` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `item_bonus_class_restrictions`
--

CREATE TABLE `item_bonus_class_restrictions` (
  `item_bonus_id` int UNSIGNED NOT NULL,
  `class_id` int UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `item_bonus_item_type_conditions`
--

CREATE TABLE `item_bonus_item_type_conditions` (
  `item_bonus_id` int UNSIGNED NOT NULL,
  `item_type_id` int UNSIGNED NOT NULL,
  `required_count` tinyint UNSIGNED NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `item_cards`
--

CREATE TABLE `item_cards` (
  `item_id` int UNSIGNED NOT NULL,
  `socket_number` tinyint UNSIGNED NOT NULL,
  `card_id` int UNSIGNED NOT NULL,
  `is_locked` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `item_classes`
--

CREATE TABLE `item_classes` (
  `item_id` int UNSIGNED NOT NULL,
  `class_id` int UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `item_slots`
--

CREATE TABLE `item_slots` (
  `item_id` int UNSIGNED NOT NULL,
  `slot_number` tinyint UNSIGNED NOT NULL
) ;

-- --------------------------------------------------------

--
-- Структура таблицы `item_stamps`
--

CREATE TABLE `item_stamps` (
  `item_id` int UNSIGNED NOT NULL,
  `stamp_variant_id` int UNSIGNED NOT NULL,
  `is_locked` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `item_types`
--

CREATE TABLE `item_types` (
  `id` int UNSIGNED NOT NULL,
  `slug` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `item_type_tabs`
--

CREATE TABLE `item_type_tabs` (
  `item_type_id` int UNSIGNED NOT NULL,
  `tab_id` int UNSIGNED NOT NULL,
  `is_visible` tinyint(1) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `modal_tabs`
--

CREATE TABLE `modal_tabs` (
  `id` int UNSIGNED NOT NULL,
  `slug` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `settings`
--

CREATE TABLE `settings` (
  `key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('int','string','bool') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'string',
  `note` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `slots`
--

CREATE TABLE `slots` (
  `slot_number` tinyint UNSIGNED NOT NULL,
  `name` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `column_no` tinyint UNSIGNED NOT NULL,
  `pair_group` tinyint UNSIGNED DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `slot_columns`
--

CREATE TABLE `slot_columns` (
  `column_no` tinyint UNSIGNED NOT NULL,
  `name` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `align` enum('left','right') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'left',
  `sort_order` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `slot_rules`
--

CREATE TABLE `slot_rules` (
  `id` int UNSIGNED NOT NULL,
  `class_id` int UNSIGNED NOT NULL,
  `item_type_id` int UNSIGNED DEFAULT NULL,
  `item_type_key` int UNSIGNED NOT NULL DEFAULT '0',
  `slot_number` tinyint UNSIGNED NOT NULL,
  `restriction` enum('allowed','denied','locked','exception','show','hide') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'allowed',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ;

-- --------------------------------------------------------

--
-- Структура таблицы `stamps`
--

CREATE TABLE `stamps` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `stamp_allowed_item_types`
--

CREATE TABLE `stamp_allowed_item_types` (
  `stamp_id` int UNSIGNED NOT NULL,
  `item_type_id` int UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `stamp_bonus_class_restrictions`
--

CREATE TABLE `stamp_bonus_class_restrictions` (
  `stamp_bonus_id` int UNSIGNED NOT NULL,
  `class_id` int UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `stamp_bonus_item_type_conditions`
--

CREATE TABLE `stamp_bonus_item_type_conditions` (
  `stamp_bonus_id` int UNSIGNED NOT NULL,
  `item_type_id` int UNSIGNED NOT NULL,
  `required_count` tinyint UNSIGNED NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `stamp_class_restrictions`
--

CREATE TABLE `stamp_class_restrictions` (
  `stamp_id` int UNSIGNED NOT NULL,
  `class_id` int UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `stamp_colors`
--

CREATE TABLE `stamp_colors` (
  `id` int UNSIGNED NOT NULL,
  `slug` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `hex` varchar(7) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#8b909c',
  `sort_order` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `stamp_variants`
--

CREATE TABLE `stamp_variants` (
  `id` int UNSIGNED NOT NULL,
  `stamp_id` int UNSIGNED NOT NULL,
  `color_id` int UNSIGNED NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `stamp_variant_bonuses`
--

CREATE TABLE `stamp_variant_bonuses` (
  `id` int UNSIGNED NOT NULL,
  `stamp_variant_id` int UNSIGNED NOT NULL,
  `stat_id` smallint UNSIGNED NOT NULL,
  `quality_value` decimal(10,2) NOT NULL,
  `display_value` decimal(10,2) DEFAULT NULL,
  `value_type` enum('flat','percent') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'flat',
  `feature_id` int UNSIGNED DEFAULT NULL,
  `text_note_id` int UNSIGNED DEFAULT NULL,
  `state_id` int UNSIGNED DEFAULT NULL,
  `item_type_condition_mode` enum('all','any') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'all',
  `sort_order` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `states`
--

CREATE TABLE `states` (
  `id` int UNSIGNED NOT NULL,
  `slug` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `default_key` tinyint UNSIGNED GENERATED ALWAYS AS (if((`is_default` = 1),1,NULL)) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `stats`
--

CREATE TABLE `stats` (
  `id` smallint UNSIGNED NOT NULL,
  `slug` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_ru` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `alias` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `category` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'base',
  `supports_flat` tinyint(1) NOT NULL DEFAULT '1',
  `supports_percent` tinyint(1) NOT NULL DEFAULT '0',
  `stamp_flat_min_coef` decimal(8,4) NOT NULL DEFAULT '1.0000',
  `stamp_flat_max_coef` decimal(8,4) NOT NULL DEFAULT '1.0000',
  `stamp_percent_min_coef` decimal(8,4) NOT NULL DEFAULT '1.0000',
  `stamp_percent_max_coef` decimal(8,4) NOT NULL DEFAULT '1.0000',
  `is_base` tinyint(1) NOT NULL DEFAULT '0',
  `base_field` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `base_format` enum('plain','upgrade_attack','upgrade_defense','dps','text') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0'
) ;

-- --------------------------------------------------------

--
-- Структура таблицы `text_notes`
--

CREATE TABLE `text_notes` (
  `id` int UNSIGNED NOT NULL,
  `title` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `text` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `upgrade_levels`
--

CREATE TABLE `upgrade_levels` (
  `level` tinyint UNSIGNED NOT NULL,
  `attack_percent` decimal(6,2) NOT NULL DEFAULT '0.00',
  `defense_percent` decimal(6,2) NOT NULL DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Индексы сохранённых таблиц
--

--
-- Индексы таблицы `cards`
--
ALTER TABLE `cards`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cards_name` (`name`),
  ADD KEY `idx_cards_type` (`card_type_id`),
  ADD KEY `idx_cards_set` (`set_id`),
  ADD KEY `idx_cards_state` (`state_id`),
  ADD KEY `idx_cards_sort` (`sort_order`,`id`);

--
-- Индексы таблицы `card_allowed_grades`
--
ALTER TABLE `card_allowed_grades`
  ADD PRIMARY KEY (`card_id`,`grade`),
  ADD KEY `idx_cag_grade` (`grade`);

--
-- Индексы таблицы `card_allowed_item_types`
--
ALTER TABLE `card_allowed_item_types`
  ADD PRIMARY KEY (`card_id`,`item_type_id`),
  ADD KEY `idx_ait_item_type` (`item_type_id`);

--
-- Индексы таблицы `card_bonuses`
--
ALTER TABLE `card_bonuses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_card_bonuses_card` (`card_id`),
  ADD KEY `idx_card_bonuses_stat` (`stat_id`),
  ADD KEY `idx_card_bonuses_feature` (`feature_id`),
  ADD KEY `idx_card_bonuses_depends` (`depends_on_card_id`),
  ADD KEY `fk_card_bonuses_text_note` (`text_note_id`),
  ADD KEY `fk_card_bonuses_stat_source` (`stat_source_id`),
  ADD KEY `idx_card_bonuses_state` (`state_id`),
  ADD KEY `idx_card_bonuses_card_sort` (`card_id`,`sort_order`,`id`);

--
-- Индексы таблицы `card_bonus_class_restrictions`
--
ALTER TABLE `card_bonus_class_restrictions`
  ADD PRIMARY KEY (`card_bonus_id`,`class_id`),
  ADD KEY `idx_cbcr_class` (`class_id`);

--
-- Индексы таблицы `card_bonus_item_type_conditions`
--
ALTER TABLE `card_bonus_item_type_conditions`
  ADD PRIMARY KEY (`card_bonus_id`,`item_type_id`),
  ADD KEY `idx_cbitc_item_type` (`item_type_id`);

--
-- Индексы таблицы `card_bonus_item_type_overrides`
--
ALTER TABLE `card_bonus_item_type_overrides`
  ADD PRIMARY KEY (`card_bonus_id`,`item_type_id`),
  ADD KEY `idx_cbiv_item_type` (`item_type_id`);

--
-- Индексы таблицы `card_bonus_item_type_restrictions`
--
ALTER TABLE `card_bonus_item_type_restrictions`
  ADD PRIMARY KEY (`card_bonus_id`,`item_type_id`),
  ADD KEY `idx_cbitr_item_type` (`item_type_id`);

--
-- Индексы таблицы `card_bonus_stat_sources`
--
ALTER TABLE `card_bonus_stat_sources`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_card_bonus_stat_source` (`card_bonus_id`,`stat_id`),
  ADD KEY `idx_card_bonus_stat_sources_bonus` (`card_bonus_id`),
  ADD KEY `idx_card_bonus_stat_sources_stat` (`stat_id`);

--
-- Индексы таблицы `card_sets`
--
ALTER TABLE `card_sets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`);

--
-- Индексы таблицы `card_set_bonuses`
--
ALTER TABLE `card_set_bonuses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_card_set_bonus` (`set_id`,`required_count`,`stat_id`,`value_type`),
  ADD KEY `idx_csb_set` (`set_id`),
  ADD KEY `idx_csb_stat` (`stat_id`);

--
-- Индексы таблицы `card_types`
--
ALTER TABLE `card_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`);

--
-- Индексы таблицы `classes`
--
ALTER TABLE `classes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD KEY `fk_classes_parent` (`parent_id`);

--
-- Индексы таблицы `enum_labels`
--
ALTER TABLE `enum_labels`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_enum_label` (`group_code`,`code`),
  ADD KEY `idx_enum_labels_group` (`group_code`,`sort_order`,`id`);

--
-- Индексы таблицы `features`
--
ALTER TABLE `features`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD UNIQUE KEY `uniq_features_checkbox_id` (`checkbox_id`),
  ADD KEY `fk_features_tab` (`tab_id`);

--
-- Индексы таблицы `grades`
--
ALTER TABLE `grades`
  ADD PRIMARY KEY (`code`);

--
-- Индексы таблицы `items`
--
ALTER TABLE `items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_items_name` (`name`),
  ADD KEY `idx_items_grade` (`grade`),
  ADD KEY `idx_items_required_level` (`required_level`),
  ADD KEY `idx_items_gender` (`gender`),
  ADD KEY `idx_items_type` (`item_type_id`),
  ADD KEY `idx_items_grade_level` (`grade`,`required_level`),
  ADD KEY `idx_items_description_feature` (`description_feature_id`),
  ADD KEY `idx_items_state` (`state_id`),
  ADD KEY `idx_items_type_level` (`item_type_id`,`required_level`);

--
-- Индексы таблицы `item_bonuses`
--
ALTER TABLE `item_bonuses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_item_bonuses_stat` (`stat_id`),
  ADD KEY `idx_item_bonuses_feature` (`feature_id`),
  ADD KEY `fk_item_bonuses_text_note` (`text_note_id`),
  ADD KEY `fk_item_bonuses_stat_source` (`stat_source_id`),
  ADD KEY `idx_item_bonuses_state` (`state_id`),
  ADD KEY `idx_item_bonuses_item_sort` (`item_id`,`sort_order`,`id`);

--
-- Индексы таблицы `item_bonus_class_restrictions`
--
ALTER TABLE `item_bonus_class_restrictions`
  ADD PRIMARY KEY (`item_bonus_id`,`class_id`),
  ADD KEY `idx_ibcr_class` (`class_id`);

--
-- Индексы таблицы `item_bonus_item_type_conditions`
--
ALTER TABLE `item_bonus_item_type_conditions`
  ADD PRIMARY KEY (`item_bonus_id`,`item_type_id`),
  ADD KEY `idx_ibitc_item_type` (`item_type_id`);

--
-- Индексы таблицы `item_cards`
--
ALTER TABLE `item_cards`
  ADD PRIMARY KEY (`item_id`,`socket_number`),
  ADD KEY `idx_item_cards_card` (`card_id`);

--
-- Индексы таблицы `item_classes`
--
ALTER TABLE `item_classes`
  ADD PRIMARY KEY (`item_id`,`class_id`),
  ADD KEY `idx_item_classes_class` (`class_id`);

--
-- Индексы таблицы `item_slots`
--
ALTER TABLE `item_slots`
  ADD PRIMARY KEY (`item_id`,`slot_number`),
  ADD KEY `idx_item_slots_slot` (`slot_number`);

--
-- Индексы таблицы `item_stamps`
--
ALTER TABLE `item_stamps`
  ADD PRIMARY KEY (`item_id`),
  ADD KEY `idx_item_stamps_variant` (`stamp_variant_id`);

--
-- Индексы таблицы `item_types`
--
ALTER TABLE `item_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`);

--
-- Индексы таблицы `item_type_tabs`
--
ALTER TABLE `item_type_tabs`
  ADD PRIMARY KEY (`item_type_id`,`tab_id`),
  ADD KEY `idx_itt_tab` (`tab_id`);

--
-- Индексы таблицы `modal_tabs`
--
ALTER TABLE `modal_tabs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`);

--
-- Индексы таблицы `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`key`);

--
-- Индексы таблицы `slots`
--
ALTER TABLE `slots`
  ADD PRIMARY KEY (`slot_number`),
  ADD KEY `idx_slots_column` (`column_no`,`sort_order`,`slot_number`);

--
-- Индексы таблицы `slot_columns`
--
ALTER TABLE `slot_columns`
  ADD PRIMARY KEY (`column_no`);

--
-- Индексы таблицы `slot_rules`
--
ALTER TABLE `slot_rules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_slot_rules_slot` (`slot_number`),
  ADD KEY `idx_slot_rules_type` (`item_type_id`),
  ADD KEY `idx_slot_rules_class` (`class_id`);

--
-- Индексы таблицы `stamps`
--
ALTER TABLE `stamps`
  ADD PRIMARY KEY (`id`);

--
-- Индексы таблицы `stamp_allowed_item_types`
--
ALTER TABLE `stamp_allowed_item_types`
  ADD PRIMARY KEY (`stamp_id`,`item_type_id`),
  ADD KEY `idx_sait_type` (`item_type_id`);

--
-- Индексы таблицы `stamp_bonus_class_restrictions`
--
ALTER TABLE `stamp_bonus_class_restrictions`
  ADD PRIMARY KEY (`stamp_bonus_id`,`class_id`),
  ADD KEY `idx_sbcr_class` (`class_id`);

--
-- Индексы таблицы `stamp_bonus_item_type_conditions`
--
ALTER TABLE `stamp_bonus_item_type_conditions`
  ADD PRIMARY KEY (`stamp_bonus_id`,`item_type_id`),
  ADD KEY `idx_sbitc_type` (`item_type_id`);

--
-- Индексы таблицы `stamp_class_restrictions`
--
ALTER TABLE `stamp_class_restrictions`
  ADD PRIMARY KEY (`stamp_id`,`class_id`),
  ADD KEY `idx_scr_class` (`class_id`);

--
-- Индексы таблицы `stamp_colors`
--
ALTER TABLE `stamp_colors`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_stamp_color_slug` (`slug`);

--
-- Индексы таблицы `stamp_variants`
--
ALTER TABLE `stamp_variants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_stamp_variant` (`stamp_id`,`color_id`),
  ADD KEY `idx_sv_color` (`color_id`);

--
-- Индексы таблицы `stamp_variant_bonuses`
--
ALTER TABLE `stamp_variant_bonuses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_svb_variant` (`stamp_variant_id`),
  ADD KEY `idx_svb_stat` (`stat_id`),
  ADD KEY `fk_svb_feature` (`feature_id`),
  ADD KEY `fk_svb_note` (`text_note_id`),
  ADD KEY `fk_svb_state` (`state_id`);

--
-- Индексы таблицы `states`
--
ALTER TABLE `states`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD UNIQUE KEY `uniq_state_default` (`default_key`);

--
-- Индексы таблицы `stats`
--
ALTER TABLE `stats`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD UNIQUE KEY `uniq_stats_base_field` (`base_field`),
  ADD KEY `idx_stats_name` (`name_ru`),
  ADD KEY `idx_stats_category` (`category`),
  ADD KEY `idx_stats_alias` (`alias`),
  ADD KEY `idx_stats_base` (`is_base`,`sort_order`,`id`);

--
-- Индексы таблицы `text_notes`
--
ALTER TABLE `text_notes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_text_notes_title` (`title`),
  ADD KEY `idx_text_notes_sort` (`sort_order`,`id`);

--
-- Индексы таблицы `upgrade_levels`
--
ALTER TABLE `upgrade_levels`
  ADD PRIMARY KEY (`level`);

--
-- AUTO_INCREMENT для сохранённых таблиц
--

--
-- AUTO_INCREMENT для таблицы `cards`
--
ALTER TABLE `cards`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `card_bonuses`
--
ALTER TABLE `card_bonuses`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `card_bonus_stat_sources`
--
ALTER TABLE `card_bonus_stat_sources`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `card_sets`
--
ALTER TABLE `card_sets`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `card_set_bonuses`
--
ALTER TABLE `card_set_bonuses`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `card_types`
--
ALTER TABLE `card_types`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `classes`
--
ALTER TABLE `classes`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `enum_labels`
--
ALTER TABLE `enum_labels`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `features`
--
ALTER TABLE `features`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `items`
--
ALTER TABLE `items`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `item_bonuses`
--
ALTER TABLE `item_bonuses`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `item_types`
--
ALTER TABLE `item_types`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `modal_tabs`
--
ALTER TABLE `modal_tabs`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `slot_rules`
--
ALTER TABLE `slot_rules`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `stamps`
--
ALTER TABLE `stamps`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `stamp_colors`
--
ALTER TABLE `stamp_colors`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `stamp_variants`
--
ALTER TABLE `stamp_variants`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `stamp_variant_bonuses`
--
ALTER TABLE `stamp_variant_bonuses`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `states`
--
ALTER TABLE `states`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `stats`
--
ALTER TABLE `stats`
  MODIFY `id` smallint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `text_notes`
--
ALTER TABLE `text_notes`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Ограничения внешнего ключа сохраненных таблиц
--

--
-- Ограничения внешнего ключа таблицы `cards`
--
ALTER TABLE `cards`
  ADD CONSTRAINT `fk_cards_set` FOREIGN KEY (`set_id`) REFERENCES `card_sets` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_cards_state` FOREIGN KEY (`state_id`) REFERENCES `states` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_cards_type` FOREIGN KEY (`card_type_id`) REFERENCES `card_types` (`id`) ON DELETE RESTRICT;

--
-- Ограничения внешнего ключа таблицы `card_allowed_grades`
--
ALTER TABLE `card_allowed_grades`
  ADD CONSTRAINT `fk_cag_card` FOREIGN KEY (`card_id`) REFERENCES `cards` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_cag_grade` FOREIGN KEY (`grade`) REFERENCES `grades` (`code`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `card_allowed_item_types`
--
ALTER TABLE `card_allowed_item_types`
  ADD CONSTRAINT `fk_ait_card` FOREIGN KEY (`card_id`) REFERENCES `cards` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ait_item_type` FOREIGN KEY (`item_type_id`) REFERENCES `item_types` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `card_bonuses`
--
ALTER TABLE `card_bonuses`
  ADD CONSTRAINT `fk_card_bonus_card` FOREIGN KEY (`card_id`) REFERENCES `cards` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_card_bonus_depends` FOREIGN KEY (`depends_on_card_id`) REFERENCES `cards` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_card_bonus_feature` FOREIGN KEY (`feature_id`) REFERENCES `features` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_card_bonus_stat` FOREIGN KEY (`stat_id`) REFERENCES `stats` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_card_bonuses_stat_source` FOREIGN KEY (`stat_source_id`) REFERENCES `stats` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_card_bonuses_state` FOREIGN KEY (`state_id`) REFERENCES `states` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_card_bonuses_text_note` FOREIGN KEY (`text_note_id`) REFERENCES `text_notes` (`id`) ON DELETE SET NULL;

--
-- Ограничения внешнего ключа таблицы `card_bonus_class_restrictions`
--
ALTER TABLE `card_bonus_class_restrictions`
  ADD CONSTRAINT `fk_cbcr_bonus` FOREIGN KEY (`card_bonus_id`) REFERENCES `card_bonuses` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_cbcr_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `card_bonus_item_type_conditions`
--
ALTER TABLE `card_bonus_item_type_conditions`
  ADD CONSTRAINT `fk_cbitc_bonus` FOREIGN KEY (`card_bonus_id`) REFERENCES `card_bonuses` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_cbitc_item_type` FOREIGN KEY (`item_type_id`) REFERENCES `item_types` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `card_bonus_item_type_overrides`
--
ALTER TABLE `card_bonus_item_type_overrides`
  ADD CONSTRAINT `fk_cbiv_bonus` FOREIGN KEY (`card_bonus_id`) REFERENCES `card_bonuses` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_cbiv_item_type` FOREIGN KEY (`item_type_id`) REFERENCES `item_types` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `card_bonus_item_type_restrictions`
--
ALTER TABLE `card_bonus_item_type_restrictions`
  ADD CONSTRAINT `fk_cbitr_bonus` FOREIGN KEY (`card_bonus_id`) REFERENCES `card_bonuses` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_cbitr_item_type` FOREIGN KEY (`item_type_id`) REFERENCES `item_types` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `card_bonus_stat_sources`
--
ALTER TABLE `card_bonus_stat_sources`
  ADD CONSTRAINT `fk_card_bonus_stat_sources_bonus` FOREIGN KEY (`card_bonus_id`) REFERENCES `card_bonuses` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_card_bonus_stat_sources_stat` FOREIGN KEY (`stat_id`) REFERENCES `stats` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `card_set_bonuses`
--
ALTER TABLE `card_set_bonuses`
  ADD CONSTRAINT `fk_csb_set` FOREIGN KEY (`set_id`) REFERENCES `card_sets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_csb_stat` FOREIGN KEY (`stat_id`) REFERENCES `stats` (`id`) ON DELETE RESTRICT;

--
-- Ограничения внешнего ключа таблицы `classes`
--
ALTER TABLE `classes`
  ADD CONSTRAINT `fk_classes_parent` FOREIGN KEY (`parent_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `features`
--
ALTER TABLE `features`
  ADD CONSTRAINT `fk_features_tab` FOREIGN KEY (`tab_id`) REFERENCES `modal_tabs` (`id`) ON DELETE SET NULL;

--
-- Ограничения внешнего ключа таблицы `items`
--
ALTER TABLE `items`
  ADD CONSTRAINT `fk_items_description_feature` FOREIGN KEY (`description_feature_id`) REFERENCES `features` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_items_grade` FOREIGN KEY (`grade`) REFERENCES `grades` (`code`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_items_state` FOREIGN KEY (`state_id`) REFERENCES `states` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_items_type` FOREIGN KEY (`item_type_id`) REFERENCES `item_types` (`id`) ON DELETE RESTRICT;

--
-- Ограничения внешнего ключа таблицы `item_bonuses`
--
ALTER TABLE `item_bonuses`
  ADD CONSTRAINT `fk_item_bonuses_feature` FOREIGN KEY (`feature_id`) REFERENCES `features` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_item_bonuses_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_item_bonuses_stat` FOREIGN KEY (`stat_id`) REFERENCES `stats` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_item_bonuses_stat_source` FOREIGN KEY (`stat_source_id`) REFERENCES `stats` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_item_bonuses_state` FOREIGN KEY (`state_id`) REFERENCES `states` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_item_bonuses_text_note` FOREIGN KEY (`text_note_id`) REFERENCES `text_notes` (`id`) ON DELETE SET NULL;

--
-- Ограничения внешнего ключа таблицы `item_bonus_class_restrictions`
--
ALTER TABLE `item_bonus_class_restrictions`
  ADD CONSTRAINT `fk_ibcr_bonus` FOREIGN KEY (`item_bonus_id`) REFERENCES `item_bonuses` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ibcr_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `item_bonus_item_type_conditions`
--
ALTER TABLE `item_bonus_item_type_conditions`
  ADD CONSTRAINT `fk_ibitc_bonus` FOREIGN KEY (`item_bonus_id`) REFERENCES `item_bonuses` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ibitc_item_type` FOREIGN KEY (`item_type_id`) REFERENCES `item_types` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `item_cards`
--
ALTER TABLE `item_cards`
  ADD CONSTRAINT `fk_ic_card` FOREIGN KEY (`card_id`) REFERENCES `cards` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_ic_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `item_classes`
--
ALTER TABLE `item_classes`
  ADD CONSTRAINT `fk_item_classes_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_item_classes_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `item_slots`
--
ALTER TABLE `item_slots`
  ADD CONSTRAINT `fk_item_slots_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_item_slots_slot` FOREIGN KEY (`slot_number`) REFERENCES `slots` (`slot_number`) ON DELETE RESTRICT;

--
-- Ограничения внешнего ключа таблицы `item_stamps`
--
ALTER TABLE `item_stamps`
  ADD CONSTRAINT `fk_is_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_is_variant` FOREIGN KEY (`stamp_variant_id`) REFERENCES `stamp_variants` (`id`) ON DELETE RESTRICT;

--
-- Ограничения внешнего ключа таблицы `item_type_tabs`
--
ALTER TABLE `item_type_tabs`
  ADD CONSTRAINT `fk_itt_tab` FOREIGN KEY (`tab_id`) REFERENCES `modal_tabs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_itt_type` FOREIGN KEY (`item_type_id`) REFERENCES `item_types` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `slots`
--
ALTER TABLE `slots`
  ADD CONSTRAINT `fk_slots_column` FOREIGN KEY (`column_no`) REFERENCES `slot_columns` (`column_no`) ON DELETE RESTRICT;

--
-- Ограничения внешнего ключа таблицы `slot_rules`
--
ALTER TABLE `slot_rules`
  ADD CONSTRAINT `fk_slot_rules_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_slot_rules_slot` FOREIGN KEY (`slot_number`) REFERENCES `slots` (`slot_number`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_slot_rules_type` FOREIGN KEY (`item_type_id`) REFERENCES `item_types` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `stamp_allowed_item_types`
--
ALTER TABLE `stamp_allowed_item_types`
  ADD CONSTRAINT `fk_sait_stamp` FOREIGN KEY (`stamp_id`) REFERENCES `stamps` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sait_type` FOREIGN KEY (`item_type_id`) REFERENCES `item_types` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `stamp_bonus_class_restrictions`
--
ALTER TABLE `stamp_bonus_class_restrictions`
  ADD CONSTRAINT `fk_sbcr_bonus` FOREIGN KEY (`stamp_bonus_id`) REFERENCES `stamp_variant_bonuses` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sbcr_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `stamp_bonus_item_type_conditions`
--
ALTER TABLE `stamp_bonus_item_type_conditions`
  ADD CONSTRAINT `fk_sbitc_bonus` FOREIGN KEY (`stamp_bonus_id`) REFERENCES `stamp_variant_bonuses` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sbitc_type` FOREIGN KEY (`item_type_id`) REFERENCES `item_types` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `stamp_class_restrictions`
--
ALTER TABLE `stamp_class_restrictions`
  ADD CONSTRAINT `fk_scr_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_scr_stamp` FOREIGN KEY (`stamp_id`) REFERENCES `stamps` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `stamp_variants`
--
ALTER TABLE `stamp_variants`
  ADD CONSTRAINT `fk_sv_color` FOREIGN KEY (`color_id`) REFERENCES `stamp_colors` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_sv_stamp` FOREIGN KEY (`stamp_id`) REFERENCES `stamps` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `stamp_variant_bonuses`
--
ALTER TABLE `stamp_variant_bonuses`
  ADD CONSTRAINT `fk_svb_feature` FOREIGN KEY (`feature_id`) REFERENCES `features` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_svb_note` FOREIGN KEY (`text_note_id`) REFERENCES `text_notes` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_svb_stat` FOREIGN KEY (`stat_id`) REFERENCES `stats` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_svb_state` FOREIGN KEY (`state_id`) REFERENCES `states` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_svb_variant` FOREIGN KEY (`stamp_variant_id`) REFERENCES `stamp_variants` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
