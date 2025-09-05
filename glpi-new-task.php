<?php
/**
 * GLPI — создание новой заявки из WordPress UI.
 * Регистрирует AJAX:
 *  - glpi_dropdowns     : выдаёт списки категорий, местоположений и исполнителей
 *  - glpi_create_ticket : создаёт заявку в glpi_tickets (+ заявители/исполнители)
 *
 * Также подключает CSS для окна создания заявки.
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/glpi-utils.php';

add_action('wp_enqueue_scripts', function () {
    // Стили окна создания заявки
    wp_register_style(
        'glpi-new-task',
        plugin_dir_url(__FILE__) . 'glpi-new-task.css',
        [],
        '1.0.0'
    );
    wp_enqueue_style('glpi-new-task');

    // Скрипт модального окна создания заявки
    wp_register_script(
        'glpi-new-task-js',
        plugin_dir_url(__FILE__) . 'glpi-new-task.js',
        [],
        '1.0.0',
        true
    );
    wp_enqueue_script('glpi-new-task-js');
});

// -------- AJAX: списки категорий, местоположений и исполнителей --------
add_action('wp_ajax_glpi_dropdowns', 'gexe_glpi_dropdowns');
function gexe_glpi_dropdowns() {
    check_ajax_referer('glpi_modal_actions');

    global $glpi_db;

    // Категории (полное имя)
    $cats = $glpi_db->get_results(
        "SELECT id, completename
         FROM glpi_itilcategories
         ORDER BY completename ASC",
        ARRAY_A
    );
    $categories = [];
    if ($cats) {
        foreach ($cats as $c) {
            $full = str_replace('ЦМСЧ № 120 ФМБА РФ / ', '', $c['completename']);
            $parts = preg_split('/\s>\s/', $full);
            $short = end($parts);
            $categories[] = [
                'id'   => intval($c['id']),
                'name' => $short,
                'path' => $full,
            ];
        }
    }

    // Местоположения (ограничение по организациям)
    $entities = [
        'ЦМСЧ № 120 ФМБА РФ',
        'ЦМСЧ № 120 ФМБА РФ > Детская поликлиника',
        'ЦМСЧ № 120 ФМБА РФ > ОИРиТ внутрненние задачи',
        'ЦМСЧ № 120 ФМБА РФ > !Поликлиника для взрослых',
        'Филиал МСЧ № 5',
        'Филиал МСЧ № 6',
    ];

    $placeholders = implode(',', array_fill(0, count($entities), '%s'));
    $sql = "SELECT l.id, CONCAT(e.completename, ' / ', l.completename) AS fullname"
         . " FROM glpi_locations AS l"
         . " JOIN glpi_entities AS e ON e.id = l.entities_id"
         . " WHERE e.completename IN ($placeholders)"
         . " ORDER BY fullname ASC";
    $params = array_merge([$sql], $entities);
    $prepared = call_user_func_array([$glpi_db, 'prepare'], $params);
    $locs = $glpi_db->get_results($prepared, ARRAY_A);
    $locations = [];
    if ($locs) {
        foreach ($locs as $l) {
            $full = str_replace('ЦМСЧ № 120 ФМБА РФ / ', '', $l['fullname']);
            $full = str_replace('ЦМСЧ № 120 ФМБА РФ > ', '', $full);
            $parts = preg_split('/\s[\/>]\s/', $full);
            $short = end($parts);
            $locations[] = [
                'id'   => intval($l['id']),
                'name' => $short,
                'path' => $full,
            ];
        }
    }

    // Исполнители
    $mapping = [
        ['glpi_id' => 622, 'name' => 'Сушко Валентин'],
        ['glpi_id' => 621, 'name' => 'Скомороха Анастасия'],
        ['glpi_id' => 269, 'name' => 'Смирнов Максим'],
        ['glpi_id' => 180, 'name' => 'Кузнецов Евгений'],
        ['glpi_id' => 2,   'name' => 'Куткин Павел'],
        ['glpi_id' => 632, 'name' => 'Стельмашенко Игнат'],
        ['glpi_id' => 620, 'name' => 'Нечепорук Александр'],
    ];
    $executors = [];
    foreach ($mapping as $m) {
        $executors[] = [
            'id'   => (int)$m['glpi_id'],
            'name' => $m['name'],
        ];
    }

    wp_send_json([
        'ok'         => true,
        'categories' => $categories,
        'locations'  => $locations,
        'executors'  => $executors,
    ]);
}

// -------- AJAX: создание новой заявки --------
add_action('wp_ajax_glpi_create_ticket', 'gexe_glpi_create_ticket');
function gexe_glpi_create_ticket() {
    check_ajax_referer('glpi_modal_actions');

    if (!is_user_logged_in()) {
        wp_send_json(['ok' => false, 'error' => 'not_logged_in']);
    }

    if (!current_user_can('create_glpi_ticket')) {
        wp_send_json(['ok' => false, 'error' => 'forbidden']);
    }

    $payload_raw = isset($_POST['payload']) ? stripslashes((string)$_POST['payload']) : '';
    $payload = json_decode($payload_raw, true);
    if (!is_array($payload)) $payload = [];

    $name        = isset($payload['name'])        ? sanitize_text_field($payload['name']) : '';
    $content     = isset($payload['content'])     ? sanitize_textarea_field($payload['content']) : '';
    $cat_id      = isset($payload['category_id']) ? intval($payload['category_id']) : 0;
    $loc_id      = isset($payload['location_id']) ? intval($payload['location_id']) : 0;
    $assign_me   = !empty($payload['assign_me']);
    $assignee_id = isset($payload['assignee_id']) ? intval($payload['assignee_id']) : 0;

    if ($name === '' || $content === '') {
        wp_send_json(['ok' => false, 'error' => 'bad_request']);
    }

    global $glpi_db;

    $glpi_uid = gexe_get_current_glpi_uid(); // может быть 0

    // Создаём тикет
    $ticket_data = [
        'name'             => $name,
        'content'          => $content,
        'status'           => 1, // Новая
        'date'             => current_time('mysql'),
        'itilcategories_id'=> $cat_id ?: 0,
        'locations_id'     => $loc_id ?: 0,
    ];
    $formats = ['%s','%s','%d','%s','%d','%d'];

    $ok = (false !== $glpi_db->insert('glpi_tickets', $ticket_data, $formats));
    if (!$ok) {
        wp_send_json(['ok' => false, 'error' => 'insert_ticket_failed']);
    }

    $ticket_id = intval($glpi_db->insert_id);

    // Добавляем заявителя (requester, type=1), если есть пользователь GLPI
    if ($glpi_uid > 0) {
        $glpi_db->insert('glpi_tickets_users', [
            'tickets_id' => $ticket_id,
            'users_id'   => $glpi_uid,
            'type'       => 1
        ], ['%d','%d','%d']);
    }

    // Исполнитель по флажку (assignee, type=2)
    if ($assign_me && $glpi_uid > 0) {
        $glpi_db->insert('glpi_tickets_users', [
            'tickets_id' => $ticket_id,
            'users_id'   => $glpi_uid,
            'type'       => 2
        ], ['%d','%d','%d']);
    }
    if ($assignee_id > 0 && $assignee_id !== $glpi_uid) {
        $glpi_db->insert('glpi_tickets_users', [
            'tickets_id' => $ticket_id,
            'users_id'   => $assignee_id,
            'type'       => 2
        ], ['%d','%d','%d']);
    }

    wp_send_json([
        'ok'        => true,
        'ticket_id' => $ticket_id
    ]);
}
