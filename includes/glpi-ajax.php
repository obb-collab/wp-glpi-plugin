<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/glpi-api-client.php';
require_once __DIR__ . '/glpi-new-task.php';

add_action('wp_ajax_gexe_create_ticket_api', 'gexe_create_ticket_api');
function gexe_create_ticket_api() {
    if (!check_ajax_referer('gexe_actions', 'nonce', false)) {
        wp_send_json_error(['error' => ['type' => 'SECURITY', 'code' => 'NO_CSRF', 'message' => 'Ошибка безопасности']], 200);
    }
    if (!is_user_logged_in()) {
        wp_send_json_error(['error' => ['type' => 'AUTH', 'code' => 'NO_AUTH', 'message' => 'Пользователь не авторизован']], 200);
    }
    $wp_uid = get_current_user_id();
    $glpi_uid = (int) get_user_meta($wp_uid, 'glpi_user_id', true);
    if ($glpi_uid <= 0) {
        wp_send_json_error(['error' => ['type' => 'MAPPING', 'code' => 'MAPPING_NOT_SET', 'message' => 'Профиль WordPress не привязан к GLPI пользователю']], 200);
    }

    $subject = trim((string)($_POST['subject'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $category_id = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
    $location_id = isset($_POST['location_id']) ? (int)$_POST['location_id'] : 0;
    $assign_me   = !empty($_POST['assign_me']);
    $executor_id = isset($_POST['executor_id']) ? (int)$_POST['executor_id'] : 0;

    if (mb_strlen($subject) < 3 || mb_strlen($subject) > 255) {
        wp_send_json_error(['error' => ['type' => 'VALIDATION', 'code' => 'BAD_SUBJECT', 'message' => 'Неверная тема']], 200);
    }
    if (mb_strlen($description) > 5000) {
        wp_send_json_error(['error' => ['type' => 'VALIDATION', 'code' => 'BAD_DESCRIPTION', 'message' => 'Описание слишком длинное']], 200);
    }

    if ($assign_me) {
        $executor_id = $glpi_uid;
    }
    if ($executor_id) {
        $allowed = wp_list_pluck(gexe_get_assignee_options(), 'id');
        if (!in_array($executor_id, $allowed, true)) {
            wp_send_json_error(['error' => ['type' => 'VALIDATION', 'code' => 'BAD_EXECUTOR', 'message' => 'Исполнитель недоступен']], 200);
        }
    }

    $hash = md5($wp_uid . '|' . $subject . '|' . $category_id . '|' . $location_id);
    $lock_key = 'gexe_ticket_' . $hash;
    $cached = get_transient($lock_key);
    if (is_array($cached)) {
        wp_send_json($cached);
    }

    $input = [
        'name'                 => $subject,
        'content'              => $description,
        '_users_id_requester'  => $glpi_uid,
    ];
    if ($category_id > 0) $input['itilcategories_id'] = $category_id;
    if ($location_id > 0) $input['locations_id'] = $location_id;

    $result = gexe_glpi_api_create_ticket($input);
    if (!$result['ok']) {
        delete_transient($lock_key);
        error_log('[wp-glpi:create-ticket] user=' . $wp_uid . ' glpi=' . $glpi_uid . ' result=' . ($result['code'] ?? 'fail'));
        wp_send_json_error(['error' => ['type' => 'API', 'code' => $result['code'], 'message' => $result['message'] ?? 'API error']], 200);
    }

    $ticket_id = (int)$result['id'];
    $assign_warning = null;
    if ($executor_id > 0) {
        $a = gexe_glpi_api_assign_ticket($ticket_id, $executor_id);
        if (!$a['ok']) {
            $assign_warning = $a['message'] ?? 'assign_failed';
        }
    }

    $out = ['success' => true, 'id' => $ticket_id];
    if ($assign_warning) {
        $out['assign_warning'] = $assign_warning;
    }
    set_transient($lock_key, $out, 10);

    error_log('[wp-glpi:create-ticket] user=' . $wp_uid . ' glpi=' . $glpi_uid . ' result=' . $ticket_id);
    wp_send_json($out);
}
