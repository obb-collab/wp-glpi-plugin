<?php
/*
Plugin Name: G-Exe-Copy
Description: Отображение заявок GLPI с фильтрацией и иконками.
Version: 1.2
*/

// Защита от прямого вызова
if (!defined('ABSPATH')) {
    exit;
}

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

/**
 * Рендерит HTML-шаблон карточек.
 * Попытки поиска шаблона в теме (override) по Codex -> fallback на шаблон плагина.
 *
 * @param array $tickets
 * @param array $executors_map
 * @return string HTML
 */
function glpi_cards_exe_render_template($tickets, $executors_map) {
    // 1) Пути/имена шаблонов, которые будем искать в теме (в этом порядке)
    $candidates = array(
        'glpi/glpi-cards-template.php', // recommended: theme/glpi/glpi-cards-template.php
        'glpi-cards-template.php'        // fallback: theme/glpi-cards-template.php
    );

    /**
     * Позволяем другим плагинам/темам изменить список мест для поиска.
     * Хук ожидает массив строк (относительные пути, которые передаются в locate_template).
     */
    $candidates = apply_filters('glpi_cards_template_locations', $candidates);

    $template = '';

    // 2) Ищем в дочерней/родительской теме (locate_template проверяет child -> parent)
    foreach ($candidates as $candidate) {
        $found = locate_template($candidate, false, false);
        if ($found && file_exists($found)) {
            $template = $found;
            break;
        }
    }

    // 3) Если не нашли — используем шаблон, встроенный в плагин
    if (!$template) {
        $plugin_tpl = plugin_dir_path(__FILE__) . 'templates/glpi-cards-template.php';
        if (file_exists($plugin_tpl)) {
            $template = $plugin_tpl;
        }
    }

    // 4) Позволяем полностью переопределить путь к шаблону (возвращаемый путь должен быть полным)
    $template = apply_filters('glpi_cards_template', $template, $tickets, $executors_map);

    // 5) Если нет шаблона — аккуратное сообщение (чтобы не ломать страницу молча)
    if (!$template || !file_exists($template)) {
        return '<div class="glpi-template-missing" style="padding:10px;background:#fee;border:1px solid #f99;"><strong>Ошибка:</strong> шаблон не найден.</div>';
    }

    // 6) Подключаем шаблон и возвращаем результат. Шаблону доступны $tickets и $executors_map.
    ob_start();
    include $template;
    return ob_get_clean();
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

    // Рендерим шаблон, передав подготовленные данные
    return glpi_cards_exe_render_template($tickets, $executors_map);
}

add_shortcode('glpi_cards_exe', 'glpi_cards_exe_shortcode');

// Подключаем модуль с полем профиля и серверной фильтрацией
require_once plugin_dir_path(__FILE__) . 'glpi-icon-map.php';
require_once plugin_dir_path(__FILE__) . 'gexe-executor-lock.php';
require_once __DIR__ . '/glpi-categories-shortcode.php';
require_once __DIR__ . '/glpi-modal-actions.php';
