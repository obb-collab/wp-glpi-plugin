<?php
/*
Plugin Name: G-Exe-Copy
Description: Отображение заявок GLPI с фильтрацией и иконками.
Version: 1.2
*/

require_once plugin_dir_path(__FILE__) . 'glpi-icon-map.php';

// === Подключаем стили и скрипты плагина ===
add_action('wp_enqueue_scripts', function() {
    wp_enqueue_style('glpi-gexe', plugin_dir_url(__FILE__) . 'gee.css');
    wp_enqueue_style('font-awesome-gexe', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css');
    wp_enqueue_script('glpi-gexe-filter', plugin_dir_url(__FILE__) . 'gexe-filter.js', [], null, true);
});

// === Подключение к базе GLPI ===
global $glpi_db;
$glpi_db = new wpdb(
    'wp_glpi',
    'xapetVD4OWZqw8f',
    'glpi',
    '192.168.100.12'
);

// === Форматирование имени исполнителя ===
function glpi_autoname($realname, $firstname) {
    $realname = trim($realname);
    $firstname = trim($firstname);
    $joined = mb_strtolower($realname . $firstname);
    switch ($joined) {
        case 'кузнецовен': return 'Кузнецов Е.';
        case 'смирновмо': return 'Смирнов М.';
    }
    if ($realname && $firstname) return $realname . ' ' . mb_substr($firstname, 0, 1) . '.';
    if ($realname) return $realname;
    if ($firstname) return $firstname;
    return 'Без исполнителя';
}

// === Шорткод для вывода карточек заявок ===
function glpi_cards_exe_shortcode($atts) {
    global $glpi_db;

    // === Получаем данные из базы ===
    $rows = $glpi_db->get_results("
        SELECT t.id, t.status, t.time_to_resolve, tu.users_id, u.realname, u.firstname,
               c.completename, t.name, t.content, t.date
        FROM glpi_tickets t
        LEFT JOIN glpi_tickets_users tu ON t.id = tu.tickets_id AND tu.type = 2
        LEFT JOIN glpi_users u ON tu.users_id = u.id
        LEFT JOIN glpi_itilcategories c ON t.itilcategories_id = c.id
        WHERE t.status IN (1,2,3,4) AND t.is_deleted = 0
        ORDER BY t.date DESC
        LIMIT 300
    ");

    if (!$rows) return '<p>Нет активных заявок.</p>';

    $tickets = [];
    $executors_map = [];

    // === Обработка данных: группировка по заявкам и исполнителям ===
    foreach ($rows as $row) {
        $id = $row->id;
        $real = $row->realname ?: '';
        $first = $row->firstname ?: '';
        $executor = glpi_autoname($real, $first);
        $slug = md5($executor);

        if (!isset($tickets[$id])) {
            $tickets[$id] = [
                'id' => $id,
                'status' => $row->status,
                'name' => $row->name,
                'content' => $row->content,
                'date' => $row->date,
                'category' => $row->completename,
                'executors' => [],
                'late' => ($row->time_to_resolve && strtotime($row->time_to_resolve) < time()),
            ];
        }

        if ($executor && !in_array($executor, $tickets[$id]['executors'])) {
            $tickets[$id]['executors'][] = $executor;
            $executors_map[$executor] = $slug;
        }
    }

    ksort($executors_map, SORT_NATURAL | SORT_FLAG_CASE);
    ob_start();

    echo '<div class="glpi-container">';

    // === Панель фильтрации: единый ряд со всеми фильтрами ===
    echo '<div class="glpi-filtering-panel">';
    echo '<div class="glpi-header-row">';

    // === СНАЧАЛА: Выпадающее меню исполнителей ===
    echo '<div class="glpi-filter-dropdown">';
    echo '<div class="glpi-filter-toggle">Сегодня в программе <i class="fa-solid fa-angle-down"></i>';
    echo '<div class="glpi-filter-menu">';
    echo '<button class="glpi-filter-btn active" data-filter="all" id="glpi-counter">Всего: ' . count($tickets) . '</button>';
    foreach ($executors_map as $name => $slug) {
        echo '<button class="glpi-filter-btn" data-filter="' . esc_attr($slug) . '">' . esc_html($name) . '</button>';
    }
    echo '<button class="glpi-filter-btn" data-filter="late"><i class="fa-solid fa-bomb"></i> Пора тушить</button>';
    echo '</div></div></div>';

    // === ЗАТЕМ: Статусы ===
    echo '<div class="glpi-filter-dropdown">';
    echo '<div class="glpi-filter-toggle">Статусы <i class="fa-solid fa-angle-down"></i>';
    echo '<div class="glpi-filter-menu">';

    echo '<button class="glpi-filter-btn status-filter-btn active" data-status="2">';
    echo '<span class="glpi-status-dot status-2"></span> Назначенные</button>';

    echo '<button class="glpi-filter-btn status-filter-btn" data-status="1">';
    echo '<span class="glpi-status-dot status-1"></span> Новые (ЭП)</button>';

    echo '<button class="glpi-filter-btn status-filter-btn" data-status="3">';
    echo '<span class="glpi-status-dot status-3"></span> Запланированы</button>';

    echo '<button class="glpi-filter-btn status-filter-btn" data-status="4">';
    echo '<span class="glpi-status-dot status-4"></span> В стопе</button>';

    echo '<button class="glpi-filter-btn status-filter-btn" data-unassigned="1">';
    echo '<span class="glpi-status-dot status-late"></span> Без исполнителя</button>';
// Кнопка "Показать все"
echo '<button class="glpi-filter-btn status-filter-btn" data-status="all">';
echo '<span class="glpi-status-dot" style="background:#facc15;"></span> Показать все';
echo '</button>';


    echo '</div></div></div>';

    // === В КОНЦЕ: Поиск ===
    echo '<div class="glpi-search-block">';
    echo '<input type="text" id="glpi-unified-search" class="glpi-search-input" placeholder="Поиск...">';
    echo '</div>';

    echo '</div>'; // .glpi-header-row
    echo '</div>'; // .glpi-filtering-panel

    // === Сетка карточек ===
    echo '<div class="glpi-wrapper">';
    foreach ($tickets as $t) {
        $slug_list = array_map('md5', $t['executors']);
        $slug_str = implode(',', $slug_list);
        $is_late = $t['late'];
        $is_unassigned = empty($t['executors']);

        $desc = wp_trim_words(strip_tags(html_entity_decode($t['content'])), 40, '...');
        $name = esc_html(mb_strimwidth(trim($t['name']), 0, 100, '…'));
        $category = $t['category'] ?: '—';
        $cat_parts = explode('>', $category);
        $cat_trimmed = trim(end($cat_parts));
        $cat_text = mb_strtolower($cat_trimmed);
        $icon = glpi_get_icon_by_category($cat_text);
        $cat_slug = preg_replace('/[^a-z0-9]+/u', '', strtolower(transliterator_transliterate('Any-Latin; Latin-ASCII', $cat_trimmed)));

        $link = 'http://192.168.100.12/glpi/front/ticket.form.php?id=' . $t['id'];
        $executors_html = implode(', ', array_map(function ($e) {
            return '<i class="fa-solid fa-user"></i> ' . esc_html($e);
        }, $t['executors']));

        echo '<div class="glpi-card" data-executors="' . esc_attr($slug_str) . '" data-late="' . ($is_late ? '1' : '0') . '" data-status="' . $t['status'] . '" data-unassigned="' . ($is_unassigned ? '1' : '0') . '">';
        echo '<div class="glpi-badge ' . esc_attr($cat_slug) . '">' . $icon . ' ' . esc_html($cat_trimmed) . '</div>';
        echo '<div class="glpi-card-header' . ($is_late ? ' late' : '') . '">';
        echo '<a href="' . esc_url($link) . '" class="glpi-topic" target="_blank" style="text-decoration: none;">' . ($is_late ? '<span style="color:#d1242f;">' . $name . '</span>' : $name) . '</a>';
        echo '<div class="glpi-ticket-id">#' . $t['id'] . '</div>';
        echo '</div>';
        echo '<div class="glpi-card-body"><p class="glpi-desc">' . esc_html($desc) . '</p></div>';
        echo '<div class="glpi-executor-footer">' . $executors_html . '</div>';
        echo '<div class="glpi-date-footer" data-date="' . $t['date'] . '"></div>';
        echo '</div>';
    }
    echo '</div>'; // .glpi-wrapper
    echo '</div>'; // .glpi-container

    return ob_get_clean();
}

add_shortcode('glpi_cards_exe', 'glpi_cards_exe_shortcode');

// Подключаем модуль с полем профиля и серверной фильтрацией
require_once plugin_dir_path(__FILE__) . 'gexe-executor-lock.php';
require_once __DIR__ . '/glpi-categories-shortcode.php';
require_once __DIR__ . '/glpi-modal-actions.php';


