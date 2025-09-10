<?php
/**
 * Newmodal compat helpers
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

// Статусы берём через безопасный слой из sql.php,
// чтобы не падать при отсутствии таблицы / другой БД GLPI.
if (!function_exists('nm_sql_get_statuses')) {
    require_once __DIR__ . '/sql.php';
}

/**
 * Получить карту статусов id=>name для UI.
 *
 * @return array
 */
function nm_get_status_map() {
    $map  = [];
    $rows = nm_sql_get_statuses(); // уже безопасно, вернёт [] при проблемах
    foreach ($rows as $r) {
        if (isset($r['id'], $r['name'])) {
            $map[(int)$r['id']] = (string)$r['name'];
        }
    }

    // Мягкая деградация: если ничего не получили, покажем минимум,
    // чтоб интерфейс продолжал работать (подписи можно заменить в i18n).
    if (!$map) {
        if (function_exists('nm_default_status_map')) {
            $map = nm_default_status_map();
        } else {
            // В проекте статус "решено" = 6.
            $map = [
                1 => 'New',
                2 => 'Processing (assigned)',
                3 => 'Processing (planned)',
                4 => 'Pending',
                5 => 'Closed',
                6 => 'Solved',
            ];
        }
    }
    return $map;
}

/**
 * Хелпер для места, где ожидался прямой SQL.
 * Возвращает массив со столбцами id,name — совместимо с прежней логикой.
 *
 * @return array
 */
function nm_get_status_rows() {
    $rows = [];
    foreach (nm_get_status_map() as $id => $name) {
        $rows[] = ['id' => (int)$id, 'name' => (string)$name];
    }
    return $rows;
}
