<?php
/**
 * GLPI — создание новой заявки из WordPress UI.
 * Регистрирует AJAX:
 *  - glpi_dropdowns     : выдаёт списки категорий и местоположений
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

// -------- AJAX: списки категорий и местоположений --------
add_action('wp_ajax_glpi_dropdowns', 'gexe_glpi_dropdowns');
function gexe_glpi_dropdowns() {
    check_ajax_referer('glpi_modal_actions');

    $db = gexe_glpi_db('ro');

    // Категории (полное имя)
    $cats = $db->get_results(
        "SELECT id, completename
         FROM glpi_itilcategories
         ORDER BY completename ASC",
        ARRAY_A
    );
    $categories = [];
    if ($cats) {
        foreach ($cats as $c) {
            $categories[] = [
                'id'   => intval($c['id']),
                'name' => $c['completename'],
            ];
        }
    }

    // Местоположения
    $locs = $db->get_results(
        "SELECT id, completename
         FROM glpi_locations
         ORDER BY completename ASC",
        ARRAY_A
    );
    $locations = [];
    if ($locs) {
        foreach ($locs as $l) {
            $locations[] = [
                'id'   => intval($l['id']),
                'name' => $l['completename'],
            ];
        }
    }

    wp_send_json([
        'ok'         => true,
        'categories' => $categories,
        'locations'  => $locations,
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

    if ($name === '' || $content === '') {
        wp_send_json(['ok' => false, 'error' => 'bad_request']);
    }

    $db = gexe_glpi_db();

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

    $ok = (false !== $db->insert('glpi_tickets', $ticket_data, $formats));
    if (!$ok) {
        wp_send_json(['ok' => false, 'error' => 'insert_ticket_failed']);
    }

    $ticket_id = intval($db->insert_id);

    // Добавляем заявителя (requester, type=1), если есть пользователь GLPI
    if ($glpi_uid > 0) {
        $db->insert('glpi_tickets_users', [
            'tickets_id' => $ticket_id,
            'users_id'   => $glpi_uid,
            'type'       => 1
        ], ['%d','%d','%d']);
    }

    // Исполнитель по флажку (assignee, type=2)
    if ($assign_me && $glpi_uid > 0) {
        $db->insert('glpi_tickets_users', [
            'tickets_id' => $ticket_id,
            'users_id'   => $glpi_uid,
            'type'       => 2
        ], ['%d','%d','%d']);
    }

    wp_send_json([
        'ok'        => true,
        'ticket_id' => $ticket_id
    ]);
}
