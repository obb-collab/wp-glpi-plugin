<?php
/**
 * AJAX endpoints for the "New ticket" modal.
 *
 * Provides separate endpoints for loading dictionaries and creating a ticket.
 * All responses use JSON (HTTP 200).
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/glpi-db-setup.php';
require_once __DIR__ . '/inc/user-map.php';
require_once __DIR__ . '/includes/glpi-form-data.php';
require_once __DIR__ . '/includes/executors-cache.php';

add_action('wp_enqueue_scripts', function () {
    wp_register_style('glpi-new-task', plugin_dir_url(__FILE__) . 'glpi-new-task.css', [], '1.0.0');
    wp_enqueue_style('glpi-new-task');

    wp_register_script('gexe-new-task-js', plugin_dir_url(__FILE__) . 'assets/js/gexe-new-task.js', [], '1.0.0', true);
    wp_enqueue_script('gexe-new-task-js');

    $data = [
        'url'          => admin_url('admin-ajax.php'),
        'nonce'        => wp_create_nonce('gexe_actions'),
        'user_glpi_id' => (int) gexe_get_current_glpi_uid(),
        'assignees'    => function_exists('gexe_get_assignee_options') ? gexe_get_assignee_options() : [],
        'debug'        => defined('WP_GLPI_DEBUG') && WP_GLPI_DEBUG,
    ];
    wp_localize_script('gexe-new-task-js', 'glpiAjax', $data);
});

/** Verify AJAX nonce. */
function glpi_nt_verify_nonce() {
    if (!check_ajax_referer('gexe_actions', 'nonce', false)) {
        wp_send_json_error([
            'error' => [
                'type'    => 'SECURITY',
                'scope'   => 'all',
                'code'    => 'NO_CSRF',
                'message' => 'Ошибка безопасности запроса',
            ]
        ]);
    }
}

// -------- Dictionaries --------
add_action('wp_ajax_glpi_load_dicts', 'gexe_get_form_data');
add_action('wp_ajax_glpi_get_categories', 'gexe_get_form_data');
add_action('wp_ajax_glpi_get_locations', 'gexe_get_form_data');
add_action('wp_ajax_glpi_get_executors', 'gexe_get_form_data');

// -------- Create ticket --------
add_action('wp_ajax_glpi_create_ticket', 'glpi_ajax_create_ticket');
function glpi_ajax_create_ticket() {
    glpi_nt_verify_nonce();
    if (!is_user_logged_in()) {
        wp_send_json(['success' => false, 'error' => ['type' => 'SECURITY', 'message' => 'Пользователь не авторизован']]);
    }
    $wp_uid = get_current_user_id();
    $map = gexe_require_glpi_user($wp_uid);
    if (!$map['ok']) {
        wp_send_json(['success' => false, 'error' => ['type' => 'MAPPING', 'message' => 'Профиль WordPress не привязан к GLPI пользователю']]);
    }
    $glpi_uid = (int) $map['id'];

    $subject = sanitize_text_field($_POST['subject'] ?? '');
    $desc = sanitize_textarea_field($_POST['description'] ?? '');
    $cat = isset($_POST['category_id']) ? (int) $_POST['category_id'] : 0;
    $loc = isset($_POST['location_id']) ? (int) $_POST['location_id'] : 0;
    $assign_me = !empty($_POST['assign_me']);
    $executor_wp = isset($_POST['executor_id']) ? (int) $_POST['executor_id'] : 0;

    $errors = [];
    if (mb_strlen($subject, 'UTF-8') < 3 || mb_strlen($subject, 'UTF-8') > 255) {
        $errors['name'] = 'Тема 3-255 символов';
    }
    if (mb_strlen($desc, 'UTF-8') === 0 || mb_strlen($desc, 'UTF-8') > 5000) {
        $errors['content'] = 'Описание 1-5000 символов';
    }
    [$execs] = gexe_get_wp_executors_cached();
    $allowed = [];
    foreach ($execs as $e) {
        $allowed[$e['id']] = $e['glpi_user_id'];
    }
    $executor_glpi = 0;
    if ($executor_wp > 0) {
        if (!isset($allowed[$executor_wp])) {
            $errors['assignee'] = 'Недопустимый исполнитель';
        } else {
            $executor_glpi = (int) $allowed[$executor_wp];
        }
    }
    if (!$assign_me && $executor_glpi === 0) {
        $errors['assignee'] = 'Обязательное поле';
    }
    if (!empty($errors)) {
        wp_send_json(['success' => false, 'error' => ['type' => 'VALIDATION', 'message' => 'Validation failed', 'details' => $errors]]);
    }
    if ($assign_me) {
        $executor_glpi = $glpi_uid;
    }

    $api_url = defined('GEXE_GLPI_API_URL') ? GEXE_GLPI_API_URL : (defined('GLPI_API_URL') ? GLPI_API_URL : '');
    $app_token = defined('GEXE_GLPI_APP_TOKEN') ? GEXE_GLPI_APP_TOKEN : (defined('GLPI_APP_TOKEN') ? GLPI_APP_TOKEN : '');
    $user_token = defined('GEXE_GLPI_USER_TOKEN') ? GEXE_GLPI_USER_TOKEN : (defined('GLPI_USER_TOKEN') ? GLPI_USER_TOKEN : '');
    if (!$api_url || !$app_token || !$user_token) {
        wp_send_json(['success' => false, 'error' => ['type' => 'CONFIG', 'message' => 'GLPI API not configured']]);
    }

    $lock_key = 'gexe_ticket_' . sha1($wp_uid . '|' . $subject . '|' . $cat . '|' . $loc);
    $cached = get_transient($lock_key);
    if ($cached !== false) {
        wp_send_json($cached);
    }
    set_transient($lock_key, ['error' => ['type' => 'LOCK']], 10);

    $headers = [
        'Content-Type' => 'application/json',
        'App-Token'    => $app_token,
        'Authorization'=> 'user_token ' . $user_token,
    ];
    $payload = [
        'input' => [
            'name' => $subject,
            'content' => $desc,
            'itilcategories_id' => $cat ?: null,
            'locations_id' => $loc ?: null,
            '_users_id_requester' => $glpi_uid,
        ],
    ];
    $resp = wp_remote_post(rtrim($api_url, '/') . '/Ticket', [
        'headers' => $headers,
        'body'    => wp_json_encode($payload),
        'timeout' => 15,
    ]);
    if (is_wp_error($resp)) {
        $data = ['success' => false, 'error' => ['type' => 'API', 'message' => $resp->get_error_message()]];
        set_transient($lock_key, $data, 10);
        error_log('[wp-glpi:create-ticket] user=' . $wp_uid . ' glpi=' . $glpi_uid . ' result=fail:api');
        wp_send_json($data);
    }
    $code = wp_remote_retrieve_response_code($resp);
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code >= 400 || !isset($body['id'])) {
        $msg = 'Ошибка API: ' . $code;
        if (isset($body['message'])) {
            $msg .= ' ' . $body['message'];
        }
        $data = ['success' => false, 'error' => ['type' => 'API', 'code' => $code, 'message' => $msg]];
        set_transient($lock_key, $data, 10);
        error_log('[wp-glpi:create-ticket] user=' . $wp_uid . ' glpi=' . $glpi_uid . ' result=fail:' . $code);
        wp_send_json($data);
    }
    $ticket_id = (int) $body['id'];

    $warning = false;
    if ($executor_glpi > 0) {
        $resp2 = wp_remote_post(rtrim($api_url, '/') . '/Ticket/' . $ticket_id . '/Ticket_User', [
            'headers' => $headers,
            'body'    => wp_json_encode(['input' => ['tickets_id' => $ticket_id, 'users_id' => $executor_glpi, 'type' => 2]]),
            'timeout' => 15,
        ]);
        if (is_wp_error($resp2) || wp_remote_retrieve_response_code($resp2) >= 400) {
            $warning = true;
        }
    }
    $out = ['success' => true, 'id' => $ticket_id];
    if ($warning) {
        $out['warning'] = 'assign_failed';
        $out['message'] = 'Назначение исполнителя не выполнено';
    }
    set_transient($lock_key, $out, 10);
    error_log('[wp-glpi:create-ticket] user=' . $wp_uid . ' glpi=' . $glpi_uid . ' result=ok#' . $ticket_id);
    wp_send_json($out);
}
