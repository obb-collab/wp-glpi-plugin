<?php
if (!defined('ABSPATH')) { exit; }

// Общие константы newmodal (могли быть заданы выше)
if (!defined('NM_VER'))          define('NM_VER', '1.0.0');
if (!defined('NM_BASE_URL'))     define('NM_BASE_URL', plugins_url('newmodal/', __FILE__));
if (!defined('NM_BASE_DIR'))     define('NM_BASE_DIR', plugin_dir_path(__FILE__) . 'newmodal/');

// API (могут переопределяться через wp-config.php)
if (!defined('NM_OPT_BASE_URL'))  define('NM_OPT_BASE_URL',  'nm_base_url');   // http://<glpi>/apirest.php
if (!defined('NM_OPT_APP_TOKEN')) define('NM_OPT_APP_TOKEN', 'nm_app_token');

/**
 * SQL-подключение к GLPI (строго отдельно от БД WordPress).
 *
 * Источники значений (по приоритету):
 *   1) Константы в wp-config.php:
 *      NM_GLPI_DB_HOST, NM_GLPI_DB_USER, NM_GLPI_DB_PASS, NM_GLPI_DB_NAME
 *   2) Опции WP:
 *      nm_glpi_db_host, nm_glpi_db_user, nm_glpi_db_pass, nm_glpi_dbname
 *   3) Дефолты для host/name (user/pass не задаём по умолчанию).
 */
if (!defined('NM_GLPI_DB_HOST')) {
    $h = get_option('nm_glpi_db_host');
    define('NM_GLPI_DB_HOST', $h ? $h : '192.168.100.12');
}
if (!defined('NM_GLPI_DB_NAME')) {
    $n = get_option('nm_glpi_dbname');
    define('NM_GLPI_DB_NAME', $n ? $n : 'glpi');
}
if (!defined('NM_GLPI_DB_USER')) {
    $u = get_option('nm_glpi_db_user');
    if ($u) define('NM_GLPI_DB_USER', $u);
}
if (!defined('NM_GLPI_DB_PASS')) {
    $p = get_option('nm_glpi_db_pass');
    if ($p) define('NM_GLPI_DB_PASS', $p);
}
// Подключаем базовые части
require_once __DIR__ . '/common/api.php';
require_once __DIR__ . '/common/sql.php';
