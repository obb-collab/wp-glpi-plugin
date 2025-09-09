<?php
if (!defined('ABSPATH')) exit;

if (!defined('CHIEF_DEBUG')) {
    define('CHIEF_DEBUG', false);
}

require_once __DIR__ . '/../glpi-db-setup.php';
require_once __DIR__ . '/../glpi-icon-map.php';

if (!function_exists('chief_is_manager')) {
    function chief_is_manager() {
        // legacy helper kept as-is
        return true;
    }
}

// --- Chief mode constants (isolated to /chief only) ---
if (!defined('CHIEF_WP_USER_ID')) {
    define('CHIEF_WP_USER_ID', 1); // WP id начальника (vks_m5_local)
}
if (!defined('CHIEF_GLPI_USER_ID')) {
    define('CHIEF_GLPI_USER_ID', 1); // GLPI id начальника (Куткин П.)
}

function chief_is_chief_user(): bool {
    return (int) get_current_user_id() === (int) CHIEF_WP_USER_ID;
}

/**
 * Получить список исполнителей для селекта начальника.
 * Берём пользователей, которые фигурируют как назначенные (type=2) в текущих тикетах,
 * и добавляем начальника, если его нет в списке.
 *
 * @return array<int, array{id:int,name:string}>
 */
function chief_fetch_executors(): array {
    global $glpi_db;
    $rows = $glpi_db->get_results("\n        SELECT DISTINCT u.id AS id, u.name AS name\n        FROM glpi_tickets_users tu\n        INNER JOIN glpi_users u ON u.id = tu.users_id\n        WHERE tu.type = 2\n        ORDER BY u.name ASC\n    ", ARRAY_A);
    if (!is_array($rows)) $rows = [];
    $has_chief = false;
    foreach ($rows as $r) {
        if ((int)$r['id'] === (int)CHIEF_GLPI_USER_ID) { $has_chief = true; break; }
    }
    if (!$has_chief) {
        // Try to fetch chief name from DB, fallback to "Куткин П."
        $chief_name = $glpi_db->get_var($glpi_db->prepare("SELECT name FROM glpi_users WHERE id=%d", CHIEF_GLPI_USER_ID));
        if (!$chief_name) $chief_name = 'Куткин П.';
        array_unshift($rows, ['id' => (int)CHIEF_GLPI_USER_ID, 'name' => $chief_name]);
    }
    return $rows;
}

// Localize data for chief front-end (nonce, defaults, ajaxurl)
add_action('wp_enqueue_scripts', function () {
    wp_localize_script('glpi-chief-js', 'GEXE_CHIEF', [
        'isChief'     => chief_is_chief_user(),
        'chiefWpId'   => (int) CHIEF_WP_USER_ID,
        'chiefGlpiId' => (int) CHIEF_GLPI_USER_ID,
        'nonce'       => wp_create_nonce('gexe_chief_nonce'),
        'ajaxurl'     => admin_url('admin-ajax.php'),
    ]);
}, 100);

// Подключаем эндпоинты ТОЛЬКО из подпапки chief
require_once __DIR__ . '/glpi-chief-actions.php';

if (!function_exists('glpi_chief_shortcode')) {
    function glpi_chief_shortcode() {
        ob_start();
        ?>
        <div class="glpi-chief-root">
            <div class="glpi-header-row" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                <!-- Выпадающий список исполнителей для режима начальника -->
                <label class="gexe-executor-label" style="color:#9fb3c8;font-size:13px;">Сегодня в программе</label>
                <select class="gexe-executor-select" style="min-width:220px;padding:8px 10px;border-radius:8px;background:#10151c;color:#e6eef8;border:1px solid rgba(255,255,255,.08);">
                    <option value="">Без фильтров</option>
                    <?php foreach (chief_fetch_executors() as $ex): ?>
                        <option value="<?php echo (int)$ex['id']; ?>"><?php echo esc_html($ex['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <!-- Остальные элементы хедера страницы (категории/новая заявка/поиск) выводятся основным шаблоном -->
            </div>
            <?php // ниже рендер основного списка заявок (существующий вывод плагина, не трогаем) ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
add_shortcode('glpi_chief', 'glpi_chief_shortcode');
