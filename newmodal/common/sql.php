<?php
/**
 * Newmodal SQL helpers (safe)
 *
 * Всегда используем отдельное соединение к БД GLPI и НИКОГДА не падаем на WP-подключение.
 * Хост по умолчанию: 192.168.100.12 (можно переопределить константами/опциями).
 */
if (!defined('ABSPATH')) { exit; }

// Кэш отдельного подключения к GLPI
global $nm_glpi_wpdb; // wpdb|WP_Error

/**
 * Получить wpdb-подключение к GLPI.
 * @return wpdb|WP_Error
 */
function nm_glpi_db() {
    global $wpdb, $nm_glpi_wpdb;

    // Повторно используем успешное соединение
    if ($nm_glpi_wpdb instanceof wpdb && $nm_glpi_wpdb->dbh) {
        return $nm_glpi_wpdb;
    }
    // Проверяем креды (константы определяются в config.php из констант/опций)
    $host = defined('NM_GLPI_DB_HOST') ? NM_GLPI_DB_HOST : null;
    $name = defined('NM_GLPI_DB_NAME') ? NM_GLPI_DB_NAME : null;
    $user = defined('NM_GLPI_DB_USER') ? NM_GLPI_DB_USER : null;
    $pass = defined('NM_GLPI_DB_PASS') ? NM_GLPI_DB_PASS : null;

    if (!$host || !$name || !$user || $pass === null) {
        return new WP_Error('glpi_db_creds_missing', 'GLPI DB credentials not configured (NM_GLPI_DB_*).');
    }

    // Открываем отдельное соединение к GLPI, независимо от WP
    $glpi = new wpdb($user, $pass, $name, $host);
    if (!empty($glpi->error)) {
        return new WP_Error('glpi_db_connect_failed', $glpi->error);
    }
    // Устанавливаем кодировку, аналогичную WP
    if (method_exists($glpi, 'set_charset') && isset($wpdb->charset, $wpdb->collate)) {
        $glpi->set_charset($glpi->dbh, $wpdb->charset, $wpdb->collate);
    }
    $nm_glpi_wpdb = $glpi;
    return $nm_glpi_wpdb;
}

/**
 * Имя БД GLPI.
 *
 * Приоритет:
 * 1) константа NM_GLPI_DB или NM_GLPI_DB_NAME
 * 2) опция WordPress 'nm_glpi_dbname'
 * 3) значение по умолчанию 'glpi'
 */
function nm_glpi_dbname() {
    if (defined('NM_GLPI_DB') && NM_GLPI_DB !== '') {
        return NM_GLPI_DB;
    }
    if (defined('NM_GLPI_DB_NAME') && NM_GLPI_DB_NAME !== '') {
        return NM_GLPI_DB_NAME;
    }
    $opt = get_option('nm_glpi_dbname');
    if (is_string($opt) && $opt !== '') {
        return $opt;
    }
    return 'glpi';
}

/** Экранировать идентификатор (БД/таблица/колонка) */
function nm_sql_ident($ident) {
    $ident = (string)$ident;
    $ident = str_replace('`', '``', $ident);
    return '`' . $ident . '`';
}

/** Полное имя таблицы GLPI: `dbname`.`table` */
function nm_glpi_table($table) {
    return nm_sql_ident(nm_glpi_dbname()) . '.' . nm_sql_ident($table);
}

// Любые попытки «подстраховаться» WP-подключением запрещены:
if (!defined('NM_SQL_STRICT_GLPI') || NM_SQL_STRICT_GLPI !== true) {
    define('NM_SQL_STRICT_GLPI', true);
}

/**
 * Проверка существования таблицы.
 */
function nm_glpi_table_exists($tableRaw) {
    $dbi = nm_glpi_db();
    if (is_wp_error($dbi)) {
        return false;
    }
    $db  = nm_glpi_dbname();
    $sql = $dbi->prepare(
        'SHOW TABLES FROM ' . nm_sql_ident($db) . ' LIKE %s',
        $tableRaw
    );
    $found = $dbi->get_var($sql);
    return (bool)$found;
}

/**
 * Универсальный SELECT по GLPI с проверкой существования таблицы.
 * Возвращает массив строк или WP_Error — но не приводит к фаталу.
 */
function nm_glpi_select($tableRaw, $columns = '*', $where = '', array $params = [], $output = ARRAY_A) {
    $dbi = nm_glpi_db();
    if (is_wp_error($dbi)) {
        return $dbi;
    }

    if (!nm_glpi_table_exists($tableRaw)) {
        return new WP_Error(
            'glpi_table_missing',
            sprintf('GLPI table "%s" not found in database "%s".', $tableRaw, nm_glpi_dbname())
        );
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
 * Получить список статусов (id, name) из GLPI.
 *
 * @return array|WP_Error
 */
function nm_sql_get_statuses() {
    return nm_glpi_select('glpi_itilstatuses', 'id,name');
}

/**
 * Яркое админ-уведомление, если нет соединения к GLPI — чтобы не было
 * тихих падений на попытках сходить в WP-БД.
 */
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    $dbi = nm_glpi_db();
    if (is_wp_error($dbi)) {
        echo '<div class="notice notice-error"><p><strong>GLPI DB connection error:</strong> '
            . esc_html($dbi->get_error_message())
            . ' (host=' . esc_html(defined('NM_GLPI_DB_HOST')?NM_GLPI_DB_HOST:'?')
            . ', name=' . esc_html(nm_glpi_dbname())
            . ').</p></div>';
    }
});

