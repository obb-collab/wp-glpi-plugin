<?php
if (!defined('ABSPATH')) { exit; }

// Общие константы newmodal (могли быть заданы выше)
if (!defined('NM_VER'))          define('NM_VER', '1.0.0');
if (!defined('NM_BASE_URL'))     define('NM_BASE_URL', plugins_url('newmodal/', __FILE__));
if (!defined('NM_BASE_DIR'))     define('NM_BASE_DIR', plugin_dir_path(__FILE__) . 'newmodal/');

// API (могут переопределяться через wp-config.php)
if (!defined('NM_OPT_BASE_URL'))  define('NM_OPT_BASE_URL',  'nm_base_url');   // http://<glpi>/apirest.php
if (!defined('NM_OPT_APP_TOKEN')) define('NM_OPT_APP_TOKEN', 'nm_app_token');

// === GLPI API: жёстко зафиксированные значения из рабочей инсталляции ===
if (!defined('GEXE_GLPI_API_URL')) {
    define('GEXE_GLPI_API_URL', 'http://192.168.100.12/glpi/apirest.php');
}
if (!defined('GEXE_GLPI_APP_TOKEN')) {
    define('GEXE_GLPI_APP_TOKEN', 'nqubXrD6j55bgLRuD1mrrtz5D69cXz94HHPvgmac');
}

// === SQL-подключение к GLPI (строго отдельно от БД WordPress) ===
// Данные взяты из твоего примера и заданы константами, чтобы исключить fallback.
if (!defined('NM_GLPI_DB_HOST')) define('NM_GLPI_DB_HOST', '192.168.100.12');
if (!defined('NM_GLPI_DB_NAME')) define('NM_GLPI_DB_NAME', 'glpi');
if (!defined('NM_GLPI_DB_USER')) define('NM_GLPI_DB_USER', 'wp_glpi');
if (!defined('NM_GLPI_DB_PASS')) define('NM_GLPI_DB_PASS', 'xapetVD4OWZqw8f');

// Включаем строгий режим: никакого использования $wpdb для GLPI.
if (!defined('NM_SQL_STRICT_GLPI')) {
    define('NM_SQL_STRICT_GLPI', true);
}
// Подключаем базовые части
require_once __DIR__ . '/common/api.php';
require_once __DIR__ . '/common/sql.php';
