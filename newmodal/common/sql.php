<?php
/**
 * Newmodal SQL helpers (STRICT, no WordPress DB)
 * Всегда работаем только с отдельным подключением к БД GLPI на 192.168.100.12.
 */
if (!defined('ABSPATH')) { exit; }

// Кэш отдельного подключения к GLPI
global $nm_glpi_wpdb; // wpdb|WP_Error

/** Имя БД GLPI (константа уже определена в config.php). */
function nm_glpi_dbname() {
    return defined('NM_GLPI_DB_NAME') ? NM_GLPI_DB_NAME : (defined('NM_GLPI_DB') ? NM_GLPI_DB : 'glpi');
}

/**
 * Экранирование идентификатора (БД/таблица/колонка).
 */
function nm_sql_ident($ident) {
    $ident = (string)$ident;
    $ident = str_replace('`', '``', $ident);
    return '`' . $ident . '`';
}

/**
 * Полное имя таблицы GLPI: `dbname`.`table`
 */
function nm_glpi_table($table) {
    return nm_sql_ident(nm_glpi_dbname()) . '.' . nm_sql_ident($table);
}

/**
 * Получить отдельное wpdb-подключение к GLPI (без использования $wpdb).
 * Константы задаются в config.php.
 *
 * @return wpdb|WP_Error
 */
function nm_glpi_db() {
    global $wpdb, $nm_glpi_wpdb;
    if ($nm_glpi_wpdb instanceof wpdb && $nm_glpi_wpdb->dbh) {
        return $nm_glpi_wpdb;
    }
    $host = defined('NM_GLPI_DB_HOST') ? NM_GLPI_DB_HOST : null;
    $name = defined('NM_GLPI_DB_NAME') ? NM_GLPI_DB_NAME : null;
    $user = defined('NM_GLPI_DB_USER') ? NM_GLPI_DB_USER : null;
    $pass = defined('NM_GLPI_DB_PASS') ? NM_GLPI_DB_PASS : null;
    if (!$host || !$name || !$user || $pass === null) {
        return new WP_Error('glpi_db_creds_missing', 'GLPI DB credentials not configured (NM_GLPI_DB_*).');
    }
    $glpi = new wpdb($user, $pass, $name, $host);
    if (!empty($glpi->error)) {
        return new WP_Error('glpi_db_connect_failed', $glpi->error);
    }
    if (method_exists($glpi, 'set_charset') && isset($wpdb->charset, $wpdb->collate)) {
        $glpi->set_charset($glpi->dbh, $wpdb->charset, $wpdb->collate);
    }
    $nm_glpi_wpdb = $glpi;
    return $nm_glpi_wpdb;
}

/**
 * Проверка существования таблицы в БД GLPI.
 */
function nm_glpi_table_exists($tableRaw) {
    $dbi = nm_glpi_db();
    if (is_wp_error($dbi)) return false;
    $db  = nm_glpi_dbname();
    $sql = $dbi->prepare('SHOW TABLES FROM ' . nm_sql_ident($db) . ' LIKE %s', $tableRaw);
    $res = $dbi->get_var($sql);
    return (bool)$res;
}

/**
 * Универсальный SELECT по GLPI (без падений на WP-БД).
 * Возвращает массив строк или WP_Error.
 */
function nm_glpi_select($tableRaw, $columns = '*', $where = '', array $params = [], $output = ARRAY_A) {
    $dbi = nm_glpi_db();
    if (is_wp_error($dbi)) return $dbi;
    if (!nm_glpi_table_exists($tableRaw)) {
        return new WP_Error('glpi_table_missing', sprintf('GLPI table "%s" not found in database "%s".', $tableRaw, nm_glpi_dbname()));
    }
    $table = nm_glpi_table($tableRaw);
    $sql   = "SELECT {$columns} FROM {$table}";
    if ($where) {
        $sql .= ' WHERE ' . $where;
    }
    if ($params) {
        $sql = $dbi->prepare($sql, $params);
    }
    $rows = $dbi->get_results($sql, $output);
    if ($dbi->last_error) {
        return new WP_Error('glpi_sql_error', $dbi->last_error);
    }
    return $rows;
}

/**
 * Список статусов (id, name) из GLPI. Без фатала.
 */
function nm_sql_get_statuses() {
    $res = nm_glpi_select('glpi_itilstatuses', 'id,name');
    if (is_wp_error($res) || !is_array($res)) return [];
    return $res;
}

// Админ-подсказка, если соединение к GLPI не настроено/падает
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    $dbi = nm_glpi_db();
    if (is_wp_error($dbi)) {
        echo '<div class="notice notice-error"><p><strong>GLPI DB connection error:</strong> '
            . esc_html($dbi->get_error_message())
            . ' (host=' . esc_html(defined('NM_GLPI_DB_HOST') ? NM_GLPI_DB_HOST : '?')
            . ', name=' . esc_html(nm_glpi_dbname())
            . ').</p></div>';
    }
});

