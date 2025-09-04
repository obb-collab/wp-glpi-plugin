<?php
/*
Plugin Name: WP GLPI Plugin
Version: 1.0.0
GitHub Plugin URI: obb-collab/wp-glpi-plugin
Primary Branch: main
*/

if (!defined('ABSPATH')) exit;

// ----- Capabilities -----
register_activation_hook(__FILE__, function () {
    $role = get_role('administrator');
    if ($role) {
        $role->add_cap('create_glpi_ticket');
    }
});

register_deactivation_hook(__FILE__, function () {
    $role = get_role('administrator');
    if ($role) {
        $role->remove_cap('create_glpi_ticket');
    }
});

// ====== СТАТИКА (CSS/JS) с принудительным обновлением версий ======
add_action('wp_enqueue_scripts', function () {
    $css_path = plugin_dir_path(__FILE__) . 'gee.css';
    $js_path  = plugin_dir_path(__FILE__) . 'gexe-filter.js';

    $css_ver = file_exists($css_path) ? filemtime($css_path) : null;
    $js_ver  = file_exists($js_path)  ? filemtime($js_path)  : null;

    wp_enqueue_style('gexe-gee', plugin_dir_url(__FILE__) . 'gee.css', [], $css_ver);
    wp_enqueue_style('gexe-fa', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css', [], '6.5.0');
    wp_enqueue_script('gexe-filter', plugin_dir_url(__FILE__) . 'gexe-filter.js', [], $js_ver, true);
});

// ====== ПОДКЛЮЧЕНИЕ К БД GLPI ======
require_once __DIR__ . '/glpi-db-setup.php';

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
    $glpi_user_key  = '';
    $glpi_show_all  = false;

    if ($current_user && $current_user->ID) {
        $glpi_user_key = trim((string)get_user_meta($current_user->ID, 'glpi_user_key', true));
        $glpi_show_all = (get_user_meta($current_user->ID, 'glpi_show_all_cards', true) === '1');
        // Фоллбеки совместимости
        if ($glpi_user_key === '') { $glpi_user_key = trim((string)get_user_meta($current_user->ID, 'glpi_token', true)); }
        if ($glpi_user_key === '') { $glpi_user_key = trim((string)get_user_meta($current_user->ID, 'glpi_executor_id', true)); }
    }

    // ---- Базовый запрос по активным тикетам ----
    $where_status  = ' t.status IN (1,2,3,4) AND t.is_deleted = 0 ';
    $join_assignee = ' LEFT JOIN glpi_tickets_users tu_ass ON t.id = tu_ass.tickets_id AND tu_ass.type = 2 ';
    $join_req      = ' LEFT JOIN glpi_tickets_users tu_req ON t.id = tu_req.tickets_id AND tu_req.type = 1 ';
    $join_user     = ' LEFT JOIN glpi_users u ON tu_ass.users_id = u.id ';
    $join_cat      = ' LEFT JOIN glpi_itilcategories c ON t.itilcategories_id = c.id ';
    $join_loc      = ' LEFT JOIN glpi_locations l ON t.locations_id = l.id ';

    $where_assignee = '';
    $mode = 'all';

    if (!$glpi_show_all && $glpi_user_key !== '') {
        if (preg_match('~^\d+$~', $glpi_user_key)) {
            // Жёсткая фильтрация по users_id (GLPI)
            $mode = 'assignee_id';
            $where_assignee = $glpi_db->prepare(' AND tu_ass.users_id = %d ', (int)$glpi_user_key);
        } elseif (preg_match('~^[a-f0-9]{32}$~i', $glpi_user_key)) {
            // Фильтрация по md5 от форматированного имени — выполняем далее в PHP
            $mode = 'assignee_md5';
        }
    }

    $sql = "
        SELECT  t.id, t.status, t.time_to_resolve,
                t.name, t.content, t.date,
                tu_ass.users_id AS assignee_id,
                tu_req.users_id AS author_id,
                u.realname, u.firstname,
                c.completename AS category_name,
                l.completename AS location_name
        FROM glpi_tickets t
        $join_assignee
        $join_req
        $join_user
        $join_cat
        $join_loc
        WHERE $where_status
        $where_assignee
        ORDER BY t.date DESC
        LIMIT 500
    ";
    $rows = $glpi_db->get_results($sql);

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

    // ---- Фильтрация по md5, если задан этот режим ----
    if ($mode === 'assignee_md5') {
        $uk = strtolower($glpi_user_key);
        $tickets = array_filter($tickets, function ($t) use ($uk) {
            foreach ($t['executors'] as $nm) {
                if (md5($nm) === $uk) return true;
            }
            return false;
        });
    }

    // ---- Статистика по статусам (для меню) ----
    $status_counts = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
    foreach ($tickets as $t) {
        $s = (int)$t['status'];
        if (isset($status_counts[$s])) $status_counts[$s]++;
    }
    $total_count = array_sum($status_counts);

    // ---- Map исполнителей (для фильтра «Сегодня в программе», когда show_all = true) ----
    $executors_map = [];
    foreach ($tickets as $t) {
        foreach ($t['executors'] as $e) {
            $executors_map[$e] = md5($e);
        }
    }
    ksort($executors_map, SORT_NATURAL | SORT_FLAG_CASE);

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
    $GLOBALS['gexe_executors_map']    = $executors_map;
    $GLOBALS['gexe_status_counts']    = $status_counts;
    $GLOBALS['gexe_total_count']      = $total_count;
    $GLOBALS['gexe_show_all']         = $glpi_show_all;
    $GLOBALS['gexe_category_counts']  = $category_counts;
    $GLOBALS['gexe_category_slugs']   = $category_slugs;

    ob_start();
    include $tpl;
    return ob_get_clean();
}

// ====== ПОЛЯ ПРОФИЛЯ WP-ПОЛЬЗОВАТЕЛЯ ======
add_action('show_user_profile',  'gexe_show_glpi_profile_fields');
add_action('edit_user_profile',  'gexe_show_glpi_profile_fields');
add_action('personal_options_update', 'gexe_save_glpi_profile_fields');
add_action('edit_user_profile_update', 'gexe_save_glpi_profile_fields');

function gexe_show_glpi_profile_fields($user) {
    if (!($user instanceof WP_User)) return;
    $glpi_user_key = get_user_meta($user->ID, 'glpi_user_key', true);
    $glpi_show_all = (get_user_meta($user->ID, 'glpi_show_all_cards', true) === '1'); ?>
    <h2>GLPI ↔ WordPress</h2>
    <table class="form-table" role="presentation">
        <tr>
            <th><label for="glpi_user_key">Ключ пользователя GLPI</label></th>
            <td>
                <input type="text" name="glpi_user_key" id="glpi_user_key" class="regular-text" value="<?php echo esc_attr($glpi_user_key); ?>" />
                <p class="description">Укажите <strong>числовой users.id</strong> из GLPI (предпочтительно) или <strong>MD5 от имени исполнителя</strong> в формате «Фамилия И.».</p>
            </td>
        </tr>
        <tr>
            <th><label for="glpi_show_all_cards">Показывать все карточки</label></th>
            <td>
                <label><input type="checkbox" name="glpi_show_all_cards" id="glpi_show_all_cards" value="1" <?php checked($glpi_show_all); ?> /> Отключить фильтрацию по сопоставленному пользователю GLPI</label>
            </td>
        </tr>
    </table>
<?php }

function gexe_save_glpi_profile_fields($user_id) {
    if (!current_user_can('edit_user', $user_id)) return;
    $key = isset($_POST['glpi_user_key']) ? sanitize_text_field(wp_unslash($_POST['glpi_user_key'])) : '';
    update_user_meta($user_id, 'glpi_user_key', $key);
    $show = isset($_POST['glpi_show_all_cards']) ? '1' : '0';
    update_user_meta($user_id, 'glpi_show_all_cards', $show);
}

// ====== ПРОЧИЕ ФАЙЛЫ ПЛАГИНА ======
require_once __DIR__ . '/gexe-executor-lock.php';
require_once __DIR__ . '/glpi-categories-shortcode.php';
require_once __DIR__ . '/glpi-modal-actions.php';
require_once __DIR__ . '/glpi-icon-map.php';
require_once __DIR__ . '/glpi-new-task.php';

