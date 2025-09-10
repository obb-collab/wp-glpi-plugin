<?php
/**
 * newmodal/common/compat.php
 * Совместимость: мост FRGLPI_* → NM_* и значения по умолчанию.
 */
if (!defined('ABSPATH')) { exit; }

// База путей/URL для ассетов
if (defined('FRGLPI_NEWMODAL_DIR') && !defined('NM_BASE_DIR')) {
    define('NM_BASE_DIR', rtrim(FRGLPI_NEWMODAL_DIR, '/\\') . '/');
}
if (defined('FRGLPI_NEWMODAL_URL') && !defined('NM_BASE_URL')) {
    define('NM_BASE_URL', rtrim(FRGLPI_NEWMODAL_URL, '/\\') . '/');
}

// Версия ассетов
if (!defined('NM_VER')) {
    define('NM_VER', defined('FRGLPI_NEWMODAL_VER') ? FRGLPI_NEWMODAL_VER : '2.0.0');
}

// Ключи опций/мета (стандартизировано под текущий код)
if (!defined('NM_META_APP_TOKEN')) define('NM_META_APP_TOKEN', 'glpi_app_token');     // App-Token хранится в options
if (!defined('NM_META_USER_TOKEN')) define('NM_META_USER_TOKEN', 'glpi_user_token');  // Session-Token в usermeta
if (!defined('NM_OPT_BASE_URL'))    define('NM_OPT_BASE_URL',    'glpi_api_base_url');

// Подсказки/утилиты
if (!function_exists('nm_trailingslashit')) {
    function nm_trailingslashit($s) { return rtrim($s, "/\\") . '/'; }
}

// Контрольная подсветка проблем базовых путей (не фаталим, только предупреждаем админа)
if (!defined('NM_BASE_DIR') || !defined('NM_BASE_URL')) {
    add_action('admin_notices', function(){
        if (!current_user_can('activate_plugins')) return;
        echo '<div class="notice notice-error"><p><strong>_FrGLPI Isolated Clone:</strong> '
           . esc_html__('Base constants are not defined (NM_BASE_DIR / NM_BASE_URL). Check bootloader.', 'wp-glpi-plugin')
           . '</p></div>';
    });
}
