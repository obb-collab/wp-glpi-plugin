<?php
/**
 * Newmodal SQL helpers (safe)
 *
 * Цель: обращаться к БД GLPI корректно и безопасно,
 * даже если она находится в другой базе, а не там, где WordPress.
 * - Имя БД GLPI берём из константы NM_GLPI_DB (если есть) или опции nm_glpi_dbname.
 * - Имя таблицы формируем как `db`.`table` c экранированием.
 * - Перед запросами проверяем наличие таблицы, чтобы не ронять сайт.
 */
if (!defined('ABSPATH')) { exit; }

/**
 * Имя БД GLPI.
 *
 * Приоритет:
 * 1) константа NM_GLPI_DB (например, 'glpi')
 * 2) опция WordPress 'nm_glpi_dbname'
 * 3) значение по умолчанию 'glpi'
 *
 * @return string
 */
function nm_glpi_dbname() {
    if (defined('NM_GLPI_DB') && is_string(NM_GLPI_DB) && NM_GLPI_DB !== '') {
        return NM_GLPI_DB;
    }
    $opt = get_option('nm_glpi_dbname');
    if (is_string($opt) && $opt !== '') {
        return $opt;
    }
    return 'glpi';
}

/**
 * Экранирование идентификатора (БД/таблица/колонка) обратными кавычками.
 *
 * @param string $ident
 * @return string
 */
function nm_sql_ident($ident) {
    $ident = (string)$ident;
    // двойные бэктики внутри имени запрещаем
    $ident = str_replace('`', '``', $ident);
    return '`' . $ident . '`';
}

/**
 * Полное имя таблицы GLPI:  `dbname`.`table`
 *
 * @param string $table
 * @return string
 */
function nm_glpi_table($table) {
    return nm_sql_ident(nm_glpi_dbname()) . '.' . nm_sql_ident($table);
}

/**
 * Проверить существование таблицы в БД GLPI.
 *
 * @param string $tableRaw  имя таблицы без БД, например 'glpi_itilstatuses'
 * @return bool
 */
function nm_glpi_table_exists($tableRaw) {
    global $wpdb;
    $db  = nm_glpi_dbname();
    $sql = $wpdb->prepare(
        'SHOW TABLES FROM ' . nm_sql_ident($db) . ' LIKE %s',
        $tableRaw
    );
    $found = $wpdb->get_var($sql);
    return (bool)$found;
}

/**
 * Универсальный селект к GLPI c проверкой на существование таблицы.
 *
 * @param string $tableRaw  имя таблицы без БД (например, 'glpi_itilstatuses')
 * @param string $columns   список колонок (например, 'id,name')
 * @param string $where     WHERE без ключевого слова, опционально
 * @param array  $params    параметры для $wpdb->prepare
 * @param int    $output    формат результата WPDB (ARRAY_A по умолчанию)
 * @return array|WP_Error
 */
function nm_glpi_select($tableRaw, $columns = '*', $where = '', array $params = [], $output = ARRAY_A) {
    global $wpdb;

    if (!nm_glpi_table_exists($tableRaw)) {
        return new WP_Error(
            'glpi_table_missing',
            sprintf('GLPI table "%s" not found in database "%s". Check plugin settings.', $tableRaw, nm_glpi_dbname())
        );
    }

    $table = nm_glpi_table($tableRaw);
    $sql   = "SELECT {$columns} FROM {$table}";
    if ($where) {
        $sql .= ' WHERE ' . $where;
    }
    if ($params) {
        $sql = $wpdb->prepare($sql, $params);
    }
    $rows = $wpdb->get_results($sql, $output);
    if ($wpdb->last_error) {
        return new WP_Error('glpi_sql_error', $wpdb->last_error);
    }
    return $rows;
}

/**
 * Получить список статусов (id, name) из GLPI.
 * Возвращает массив или WP_Error. Не бросает фатал.
 *
 * @return array|WP_Error
 */
function nm_sql_get_statuses() {
    return nm_glpi_select('glpi_itilstatuses', 'id,name');
}

