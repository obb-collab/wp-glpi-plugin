<?php
/*
Plugin Name: WP GLPI Plugin
Description: Интерфейс заявок GLPI для WordPress.
Version: 1.0.2
GitHub Plugin URI: obb-collab/wp-glpi-plugin
Primary Branch: main
Release Asset: true
Update URI: https://github.com/obb-collab/wp-glpi-plugin
*/

if (!defined('ABSPATH')) exit;

/**
 * Включаем новый модальный слой (API-only) глобально.
 * Старая логика остаётся в коде, но не активируется.
 */
if (!defined('GEXE_USE_NEWMODAL')) {
    define('GEXE_USE_NEWMODAL', true);
}
if (!defined('GEXE_NEWMODAL_QS')) {
    define('GEXE_NEWMODAL_QS', 'use_newmodal');
}

require_once __DIR__ . '/glpi-utils.php';
require_once __DIR__ . '/includes/glpi-profile-fields.php';
require_once __DIR__ . '/chief/glpi-chief.php';

// [manager-switcher] local helper to detect manager account
function gexe_is_manager_local() {
    $u = wp_get_current_user();
    if (!$u || !$u->ID) {
        return false;
    }
    $login   = isset($u->user_login) ? (string) $u->user_login : '';
    $glpi_id = (int) get_user_meta($u->ID, 'glpi_user_id', true);
    return ($login === 'vks_m5_local') || ($glpi_id === 2);
}

// ----- Capabilities -----
register_activation_hook(__FILE__, function () {
    $role = get_role('administrator');
    if ($role) {
        $role->add_cap('create_glpi_ticket');
    }
    if (!wp_next_scheduled('gexe_warm_comments_cache')) {
        wp_schedule_event(time() + MINUTE_IN_SECONDS, 'hourly', 'gexe_warm_comments_cache');
    }
    gexe_glpi_install_triggers();
});

register_deactivation_hook(__FILE__, function () {
    $role = get_role('administrator');
    if ($role) {
        $role->remove_cap('create_glpi_ticket');
    }
    $ts = wp_next_scheduled('gexe_warm_comments_cache');
    if ($ts) {
        wp_unschedule_event($ts, 'gexe_warm_comments_cache');
    }
});

register_uninstall_hook(__FILE__, 'gexe_glpi_uninstall');


// ====== СТАТИКА (CSS/JS) с принудительным обновлением версий ======
add_action('wp_enqueue_scripts', function () {
    $css_path = plugin_dir_path(__FILE__) . 'assets/css/gee.css';
    $js_path  = plugin_dir_path(__FILE__) . 'assets/js/gexe-filter.js';

    $css_ver = file_exists($css_path) ? filemtime($css_path) : null;
    $new_task_css_ver = file_exists(plugin_dir_path(__FILE__) . 'assets/css/glpi-new-tasks.css') ? filemtime(plugin_dir_path(__FILE__) . 'assets/css/glpi-new-tasks.css') : null;
    $js_ver  = file_exists($js_path)  ? filemtime($js_path)  : null;

    wp_enqueue_style('gexe-gee', plugin_dir_url(__FILE__) . 'assets/css/gee.css', [], $css_ver);
    wp_enqueue_style('gexe-fa', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css', [], '6.5.0');
    // стили новой заявки — единый файл внутри assets
    wp_enqueue_style('gexe-new-task', plugin_dir_url(__FILE__) . 'assets/css/glpi-new-tasks.css', [], $new_task_css_ver);
    wp_enqueue_script('gexe-filter', plugin_dir_url(__FILE__) . 'assets/js/gexe-filter.js', [], $js_ver, true);

    wp_localize_script('gexe-filter', 'glpiAjax', [
        'url'               => admin_url('admin-ajax.php'),
        'nonce'             => wp_create_nonce('gexe_actions'),
        'user_glpi_id'      => (int) gexe_get_current_glpi_uid(),
        'current_wp_user_id'=> (int) get_current_user_id(),
        'rest'              => esc_url_raw(rest_url('glpi/v1/')),
        'restNonce'         => wp_create_nonce('wp_rest'),
        'solvedStatus'      => (int) get_option('glpi_solved_status', 6),
        'webBase'           => gexe_glpi_web_base(),
        'assignees'         => gexe_get_assignee_options(),
        'planned_status_id' => (int) gexe_glpi_status_map()['planned'],
        // [manager-switcher]
        'is_manager'        => gexe_is_manager_local() ? 1 : 0,
        'executors'         => gexe_get_assignee_options(),
    ]);
});

// ====== ПОДКЛЮЧЕНИЕ К БД GLPI ======
require_once __DIR__ . '/glpi-db-setup.php';
 
// New modal isolated module (safe to require; it is inert unless enabled)
require_once __DIR__ . '/newmodal/newmodal-loader.php';

function gexe_glpi_uninstall() {
    gexe_glpi_remove_triggers();
}

add_action('admin_init', function () {
    if (get_option('glpi_triggers_version') !== GEXE_TRIGGERS_VERSION) {
        gexe_glpi_install_triggers();
    }
});

add_action('admin_notices', function () {
    if (current_user_can('manage_options') && !gexe_glpi_triggers_present()) {
        echo '<div class="notice notice-warning"><p>GLPI triggers are missing or not installed. Run wp gexe:triggers install.</p></div>';
    }
});

// ====== УТИЛИТЫ ======
function gexe_autoname($realname, $firstname) {
    $realname  = trim((string)$realname);
    $firstname = trim((string)$firstname);
    $joined = mb_strtolower($realname . $firstname);
    switch ($joined) {
        // Локальные ручные исключения форматирования имён
        case 'кузнецовен': return 'Кузнецов Е.';
        case 'смирновмо':  return 'Смирнов М.';
    }
    if ($realname && $firstname) return $realname . ' ' . mb_substr($firstname, 0, 1) . '.';
    if ($realname) return $realname;
    if ($firstname) return $firstname;
    return 'Без исполнителя';
}

/** Транслитерация и slug для категорий */
function gexe_slugify($text) {
    $text = (string)$text;
    if (function_exists('transliterator_transliterate')) {
        $text = transliterator_transliterate('Any-Latin; Latin-ASCII', $text);
    }
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/u', '-', $text);
    $text = trim($text, '-');
    if ($text === '') $text = substr(md5((string)$text), 0, 8);
    return $text;
}

// ====== ШОРТКОД ======
add_shortcode('glpi_cards_exe', 'gexe_glpi_cards_shortcode');

function gexe_glpi_cards_shortcode($atts) {
    global $glpi_db;

    // ---- Получаем привязку WP → GLPI ----
    $current_user   = wp_get_current_user();
    $glpi_user_id   = '';
    $glpi_show_all  = false;

    if ($current_user && $current_user->ID) {
        $glpi_user_id  = trim((string) get_user_meta($current_user->ID, 'glpi_user_id', true));
        $glpi_show_all = (get_user_meta($current_user->ID, 'glpi_show_all_cards', true) === '1');
    }

    $view_as_raw = isset($_GET['view_as']) ? (string) $_GET['view_as'] : '';
    $join_filter = '';
    $branch = 'self';

    if (gexe_is_manager_local()) {
        if ($view_as_raw === 'all') {
            $branch = 'all';
        } elseif (ctype_digit($view_as_raw)) {
            $branch = 'user';
            $join_filter = $glpi_db->prepare(
                ' JOIN glpi_tickets_users tu_view ON tu_view.tickets_id = t.id AND tu_view.type = 2 AND tu_view.users_id = %d ',
                (int) $view_as_raw
            );
        } elseif (!$glpi_show_all && $glpi_user_id !== '' && ctype_digit($glpi_user_id)) {
            $join_filter = $glpi_db->prepare(
                ' JOIN glpi_tickets_users tu_view ON tu_view.tickets_id = t.id AND tu_view.type = 2 AND tu_view.users_id = %d ',
                (int) $glpi_user_id
            );
        }
    } else {
        if (!$glpi_show_all && $glpi_user_id !== '' && ctype_digit($glpi_user_id)) {
            $join_filter = $glpi_db->prepare(
                ' JOIN glpi_tickets_users tu_view ON tu_view.tickets_id = t.id AND tu_view.type = 2 AND tu_view.users_id = %d ',
                (int) $glpi_user_id
            );
        }
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[manager-switcher] branch=' . $branch . '; view_as=' . json_encode($view_as_raw));
    }

    // ---- Базовый запрос по активным тикетам ----
    $where_status  = ' t.status IN (1,2,3,4) AND t.is_deleted = 0 ';
    $join_assignee = ' LEFT JOIN glpi_tickets_users tu_ass ON t.id = tu_ass.tickets_id AND tu_ass.type = 2 ';
    $join_req      = ' LEFT JOIN glpi_tickets_users tu_req ON t.id = tu_req.tickets_id AND tu_req.type = 1 ';
    $join_user     = ' LEFT JOIN glpi_users u ON tu_ass.users_id = u.id ';
    $join_cat      = ' LEFT JOIN glpi_itilcategories c ON t.itilcategories_id = c.id ';
    $join_loc      = ' LEFT JOIN glpi_locations l ON t.locations_id = l.id ';

    $sql = "
        SELECT  t.id, t.status, t.time_to_resolve,
                t.name, t.content, t.date,
                tu_ass.users_id AS assignee_id,
                tu_req.users_id AS author_id,
                u.realname, u.firstname,
                c.completename AS category_name,
                l.completename AS location_name
        FROM glpi_tickets t
        $join_filter
        $join_assignee
        $join_req
        $join_user
        $join_cat
        $join_loc
        WHERE $where_status
        ORDER BY t.date DESC
        LIMIT 500
    ";

    $t0   = microtime(true);
    $rows = $glpi_db->get_results($sql);
    $GLOBALS['gexe_query_times']['tickets'] = (int)round((microtime(true) - $t0) * 1000);

    if (!$rows) {
        return '<p>Нет активных заявок.</p>';
    }

    // ---- Сборка карточек ----
    $tickets = [];
    foreach ($rows as $r) {
        $id = (int)$r->id;
        if (!isset($tickets[$id])) {
            $tickets[$id] = [
                'id'           => $id,
                'status'       => (int)$r->status,
                'name'         => (string)$r->name,
                'content'      => (string)$r->content,
                'date'         => (string)$r->date,
                'category'     => (string)$r->category_name, // полное «Родитель > Дочерняя»
                'location'     => (string)$r->location_name,
                'executors'    => [],
                'assignee_ids' => [],
                'author_id'    => (int)$r->author_id,
                'late'         => ($r->time_to_resolve && strtotime($r->time_to_resolve) < time()),
            ];
        }

        // Имя исполнителя
        $exec_name = gexe_autoname($r->realname, $r->firstname);
        if ($exec_name && !in_array($exec_name, $tickets[$id]['executors'], true)) {
            $tickets[$id]['executors'][] = $exec_name;
        }

        // ID исполнителя (без дублей и без пустых)
        if ($r->assignee_id !== null && $r->assignee_id !== '') {
            $aid = (int)$r->assignee_id;
            if ($aid && !in_array($aid, $tickets[$id]['assignee_ids'], true)) {
                $tickets[$id]['assignee_ids'][] = $aid;
            }
        }
    }

    // ---- Статистика по статусам (для меню) ----
    $status_counts = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
    foreach ($tickets as $t) {
        $s = (int)$t['status'];
        if (isset($status_counts[$s])) $status_counts[$s]++;
    }
    $total_count = array_sum($status_counts);

    // ---- Предзагрузка комментариев для задач текущего исполнителя ----
    $prefetched_comments = [];
    $current_glpi_id = gexe_get_current_glpi_uid();
    if ($current_glpi_id > 0) {
        foreach ($tickets as $t) {
            if (in_array($current_glpi_id, $t['assignee_ids'], true)) {
                $data = gexe_render_comments((int)$t['id']);
                $data['count'] = gexe_get_comment_count((int)$t['id']);
                $prefetched_comments[$t['id']] = $data;
            }
        }
    }

    // ---- Группировка по дочерним категориям (когда show_all = false) ----
    $category_counts = [];   // 'Ремонт' => 12
    $category_slugs  = [];   // 'Ремонт' => 'remont'
    foreach ($tickets as $t) {
        $full = (string)$t['category'];
        // делим по ">" или " > "
        $parts = preg_split('/\s*>\s*/u', $full);
        $leaf  = trim((string)end($parts));
        if ($leaf === '') $leaf = $full ?: '—';
        if (!isset($category_counts[$leaf])) $category_counts[$leaf] = 0;
        $category_counts[$leaf]++;

        if (!isset($category_slugs[$leaf])) {
            $category_slugs[$leaf] = gexe_slugify($leaf);
        }
    }
    // сортировка категорий по алфавиту
    if (!empty($category_counts)) {
        uksort($category_counts, function($a,$b){
            return strnatcasecmp($a, $b);
        });
    }

    // ---- Рендер шаблона ----
    $tpl = plugin_dir_path(__FILE__) . 'templates/glpi-cards-template.php';
    if (!file_exists($tpl)) {
        return '<div style="padding:10px;background:#fee;border:1px solid #f99;">Отсутствует шаблон: templates/glpi-cards-template.php</div>';
    }

    // Передаём переменные в область видимости инклюда
    $GLOBALS['gexe_tickets']          = $tickets;
    $GLOBALS['gexe_status_counts']    = $status_counts;
    $GLOBALS['gexe_total_count']      = $total_count;
    $GLOBALS['gexe_show_all']         = $glpi_show_all;
    $GLOBALS['gexe_category_counts']  = $category_counts;
    $GLOBALS['gexe_category_slugs']   = $category_slugs;
    $GLOBALS['gexe_prefetched_comments'] = $prefetched_comments;

    ob_start();
    include $tpl;
    return ob_get_clean();
}

// ====== ПРОЧИЕ ФАЙЛЫ ПЛАГИНА ======
require_once __DIR__ . '/gexe-executor-lock.php';
require_once __DIR__ . '/glpi-categories-shortcode.php';
require_once __DIR__ . '/glpi-modal-actions.php';
require_once __DIR__ . '/glpi-api.php';
require_once __DIR__ . '/glpi-solve.php';
require_once __DIR__ . '/glpi-icon-map.php';
require_once __DIR__ . '/glpi-new-task.php';
require_once __DIR__ . '/glpi-settings.php';
require_once __DIR__ . '/new-ticket-api/new-ticket-api.php';

