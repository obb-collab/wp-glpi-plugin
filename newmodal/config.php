<?php
if (!defined('ABSPATH')) { exit; }

// Общие константы newmodal (могли быть заданы выше)
if (!defined('NM_VER'))          define('NM_VER', '1.0.0');
if (!defined('NM_BASE_URL'))     define('NM_BASE_URL', plugins_url('newmodal/', __FILE__));
if (!defined('NM_BASE_DIR'))     define('NM_BASE_DIR', plugin_dir_path(__FILE__) . 'newmodal/');
if (!defined('NM_DB_PREFIX'))    define('NM_DB_PREFIX', 'glpi_');

// API (могут переопределяться через wp-config.php)
if (!defined('NM_OPT_BASE_URL'))  define('NM_OPT_BASE_URL',  'glpi_api_base_url');   // http://<glpi>/apirest.php
if (!defined('NM_OPT_APP_TOKEN')) define('NM_OPT_APP_TOKEN', 'glpi_app_token');

/**
 * SQL-подключение к GLPI.
 * Источники значений (по приоритету):
 *   1) Константы в wp-config.php:
 *      NM_GLPI_DB_HOST, NM_GLPI_DB_USER, NM_GLPI_DB_PASS, NM_GLPI_DB_NAME
 *   2) Опции WP:
 *      nm_glpi_db_host, nm_glpi_db_user, nm_glpi_db_pass, nm_glpi_dbname
 *   3) Безопасные дефолты: только HOST/NAME, чтобы явно требовать user/pass.
 */
if (!defined('NM_GLPI_DB_HOST')) {
    $h = get_option('nm_glpi_db_host');
    define('NM_GLPI_DB_HOST', $h ? $h : '192.168.100.12');
}
if (!defined('NM_GLPI_DB_NAME')) {
    $n = get_option('nm_glpi_dbname');
    define('NM_GLPI_DB_NAME', $n ? $n : 'glpi');
}
// user/pass без дефолтов: должны быть заданы (константами или опциями)
if (!defined('NM_GLPI_DB_USER')) {
    $u = get_option('nm_glpi_db_user');
    if ($u) define('NM_GLPI_DB_USER', $u);
}
if (!defined('NM_GLPI_DB_PASS')) {
    $p = get_option('nm_glpi_db_pass');
    if ($p) define('NM_GLPI_DB_PASS', $p);
}

// Жёстко запрещаем использование WP-подключения как «запасного».
if (!defined('NM_SQL_STRICT_GLPI')) {
    define('NM_SQL_STRICT_GLPI', true);
}



