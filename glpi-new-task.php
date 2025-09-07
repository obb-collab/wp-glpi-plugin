<?php
/**
 * GLPI — создание новой заявки из WordPress UI.
 * Регистрирует AJAX:
 *  - gexe_get_form_data : выдаёт списки категорий, местоположений и исполнителей
 *  - gexe_create_ticket : создаёт заявку в glpi_tickets (+ заявители/исполнители)
 *
 * Также подключает CSS для окна создания заявки.
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/glpi-utils.php';
require_once __DIR__ . '/includes/glpi-form-data.php';
require_once __DIR__ . '/includes/glpi-auth-map.php';
require_once __DIR__ . '/includes/glpi-sql.php';

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
    wp_localize_script('glpi-new-task-js', 'gexeAjax', [
        'url'   => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('gexe_form_data'),
    ]);
});

// -------- AJAX: создание новой заявки --------
add_action('wp_ajax_gexe_create_ticket', 'gexe_create_ticket');
function gexe_create_ticket() {
    if (!check_ajax_referer('gexe_form_data', 'nonce', false)) {
        wp_send_json(['ok' => false, 'error' => 'AJAX_FORBIDDEN'], 403);
    }

    if (!is_user_logged_in()) {
        wp_send_json(['error' => 'not_logged_in'], 401);
    }

    $wp_uid   = get_current_user_id();
    $glpi_uid = gexe_get_current_glpi_user_id($wp_uid);
    if (!$glpi_uid) {
        wp_send_json(['error' => 'no_glpi_id_for_current_user'], 422);
    }

    $payload_raw = isset($_POST['payload']) ? stripslashes((string)$_POST['payload']) : '';
    $payload = json_decode($payload_raw, true);
    if (!is_array($payload)) $payload = [];

    $name        = isset($payload['name'])        ? sanitize_text_field($payload['name']) : '';
    $content     = isset($payload['content'])     ? sanitize_textarea_field($payload['content']) : '';
    $cat_id      = isset($payload['category_id']) ? intval($payload['category_id']) : 0;
    $loc_id      = isset($payload['location_id']) ? intval($payload['location_id']) : 0;
    $due_raw     = isset($payload['due_date'])    ? sanitize_text_field($payload['due_date']) : '';
    $assign_me   = !empty($payload['assign_me']);
    $assignee_wp_id = isset($payload['assignee_id']) ? intval($payload['assignee_id']) : 0;

    $due_date = null;
    if ($due_raw !== '') {
        try {
            $due_date = (new DateTime($due_raw))->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            $due_date = null;
        }
    }

    $errors = [];
    if ($name === '') { $errors['name'] = 'required'; }
    if ($content === '') { $errors['content'] = 'required'; }
    if ($cat_id <= 0) { $errors['category'] = 'required'; }
    if ($loc_id <= 0) { $errors['location'] = 'required'; }
    if (!$due_date) { $errors['due'] = 'required'; }
    if (!$assign_me && $assignee_wp_id <= 0) { $errors['assignee'] = 'required'; }
    if (!empty($errors)) {
        wp_send_json(['ok' => false, 'error' => 'VALIDATION', 'details' => $errors], 400);
    }

    $assignee_glpi = $glpi_uid;
    if (!$assign_me) {
        $assignee_glpi = gexe_get_current_glpi_user_id($assignee_wp_id);
    }

    $resp = create_ticket_sql([
        'name'             => $name,
        'content'          => $content,
        'requester_id'     => $glpi_uid,
        'assignee_id'      => $assignee_glpi,
        'itilcategories_id'=> $cat_id,
        'locations_id'     => $loc_id,
        'due_date'         => $due_date,
    ]);

    if (!empty($resp['ok'])) {
        wp_send_json(['ok' => true, 'ticket_id' => $resp['ticket_id']]);
    }

    wp_send_json(['ok' => false, 'error' => $resp['code'] ?? 'SQL_OP_FAILED', 'message' => $resp['message'] ?? 'Не удалось создать заявку'], 500);
}
