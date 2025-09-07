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
/* legacy loader (rollback)
add_action('wp_ajax_glpi_get_categories', 'glpi_ajax_get_categories');
function glpi_ajax_get_categories() { old implementation }

add_action('wp_ajax_glpi_get_locations', 'glpi_ajax_get_locations');
function glpi_ajax_get_locations() { old implementation }

add_action('wp_ajax_glpi_get_executors', 'glpi_ajax_get_executors');
function glpi_ajax_get_executors() { old implementation }

function glpi_db_get_executors() { old implementation }
*/

add_action('wp_ajax_glpi_load_dicts', 'glpi_ajax_load_dicts');

function glpi_get_wp_executors(): array {
    global $wpdb, $glpi_db;
    $rows = $wpdb->get_results(
        "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key='glpi_user_id' AND meta_value <> ''",
        ARRAY_A
    );
    if (!$rows) {
        return [];
    }
    $map = [];
    $ids = [];
    foreach ($rows as $r) {
        $gid = (int) ($r['meta_value'] ?? 0);
        if ($gid > 0) {
            $map[$gid] = (int) ($r['user_id'] ?? 0);
            $ids[] = $gid;
        }
    }
    if (empty($ids)) {
        return [];
    }
    $place = implode(',', array_fill(0, count($ids), '%d'));
    $sql = $glpi_db->prepare(
        "SELECT u.id, u.name, u.realname, u.firstname FROM glpi_users u WHERE u.id IN ($place) ORDER BY u.realname COLLATE utf8mb4_unicode_ci ASC, u.firstname COLLATE utf8mb4_unicode_ci ASC",
        ...$ids
    );
    $grows = $glpi_db->get_results($sql, ARRAY_A);
    $out = [];
    foreach ($grows as $g) {
        $gid = (int) ($g['id'] ?? 0);
        if (!$gid || !isset($map[$gid])) continue;
        $label = trim(($g['realname'] ?? '') . ' ' . ($g['firstname'] ?? ''));
        $uname = $g['name'] ?? '';
        if ($uname === 'vks_m5_local' || $label === '') {
            $label = 'Куткин Павел';
        }
        $out[] = [
            'user_id'      => $map[$gid],
            'display_name' => $label,
            'glpi_user_id' => $gid,
        ];
    }
    usort($out, function ($a, $b) {
        return strcmp(mb_strtolower($a['display_name'], 'UTF-8'), mb_strtolower($b['display_name'], 'UTF-8'));
    });
    return $out;
}

function glpi_ajax_load_dicts() {
    glpi_nt_verify_nonce();
    if (!is_user_logged_in()) {
        wp_send_json_error([
            'error' => [
                'type'    => 'SECURITY',
                'scope'   => 'all',
                'code'    => 'NO_AUTH',
                'message' => 'Пользователь не авторизован',
            ]
        ]);
    }
    try {
        $pdo = glpi_get_pdo();
        $pdo->beginTransaction();

        // Entity-based filtering temporarily disabled. Legacy code preserved below for future restoration.
        /*
        $use_filter = defined('WP_GLPI_FILTER_CATALOGS_BY_ENTITY') && WP_GLPI_FILTER_CATALOGS_BY_ENTITY;
        $allowed = [];
        if ($use_filter) {
            // ... previous entity filtering logic ...
        }
        */

        $categories = $pdo->query(
            "SELECT c.id, c.name, c.completename FROM glpi_itilcategories AS c WHERE c.is_helpdeskvisible = 1 ORDER BY c.completename ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $locations = $pdo->query(
            "SELECT l.id, l.name, l.completename FROM glpi_locations AS l ORDER BY l.completename ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $pdo->commit();

        $executors = glpi_get_wp_executors();
        $meta = ['empty' => ['categories' => empty($categories), 'locations' => empty($locations)]];

        error_log('[wp-glpi:new-task] catalogs loaded: cats=' . count($categories) . ', locs=' . count($locations));

        wp_send_json_success([
            'categories' => $categories,
            'locations'  => $locations,
            'executors'  => $executors,
            'meta'       => $meta,
        ]);
    } catch (PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[wp-glpi:new-task] SQL locations: ' . $e->getMessage());
        wp_send_json_error([
            'type'    => 'SQL',
            'scope'   => 'locations',
            'message' => 'Ошибка SQL при загрузке локаций',
            'details' => $e->getMessage(),
        ]);
    }
}

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
    $executors = glpi_get_wp_executors();
    $allowed = [];
    foreach ($executors as $e) {
        $allowed[$e['user_id']] = $e['glpi_user_id'];
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
