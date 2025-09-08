<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../glpi-db-setup.php';
require_once __DIR__ . '/glpi-auth-map.php';
require_once __DIR__ . '/rest-client.php';

/**
 * Verify AJAX nonce.
 */
function wpglpi_nt_verify_nonce() {
    if (!check_ajax_referer('gexe_actions', 'nonce', false)) {
        wp_send_json_error([
            'type' => 'SECURITY',
            'message' => 'Ошибка безопасности запроса',
        ]);
    }
}

add_action('wp_ajax_wpglpi_load_categories', 'wpglpi_load_categories');
add_action('wp_ajax_wpglpi_load_locations', 'wpglpi_load_locations');
add_action('wp_ajax_wpglpi_load_executors', 'wpglpi_load_executors');
add_action('wp_ajax_wpglpi_create_ticket_api', 'wpglpi_create_ticket_api');

/**
 * Load categories dictionary from GLPI.
 */
function wpglpi_load_categories() {
    wpglpi_nt_verify_nonce();
    if (!is_user_logged_in()) {
        wp_send_json_error(['type' => 'SECURITY', 'message' => 'Пользователь не авторизован']);
    }
    try {
        $pdo = glpi_get_pdo();
        $sql = "SELECT c.id, c.name, c.completename, c.level FROM glpi_itilcategories AS c WHERE c.is_helpdeskvisible = 1 ORDER BY c.completename ASC";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        error_log('[wp-glpi:new-task] cats=' . count($rows));
        wp_send_json_success(['categories' => $rows]);
    } catch (PDOException $e) {
        error_log('[wp-glpi:new-task] SQL error categories: ' . $e->getMessage());
        wp_send_json_error([
            'type' => 'SQL',
            'scope' => 'categories',
            'message' => 'Ошибка SQL при загрузке категорий',
            'details' => $e->getMessage(),
        ]);
    }
}

/**
 * Load locations dictionary from GLPI.
 */
function wpglpi_load_locations() {
    wpglpi_nt_verify_nonce();
    if (!is_user_logged_in()) {
        wp_send_json_error(['type' => 'SECURITY', 'message' => 'Пользователь не авторизован']);
    }
    try {
        $pdo = glpi_get_pdo();
        $sql = "SELECT l.id, l.name, l.completename FROM glpi_locations AS l ORDER BY l.completename ASC";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        error_log('[wp-glpi:new-task] locs=' . count($rows));
        wp_send_json_success(['locations' => $rows]);
    } catch (PDOException $e) {
        error_log('[wp-glpi:new-task] SQL error locations: ' . $e->getMessage());
        wp_send_json_error([
            'type' => 'SQL',
            'scope' => 'locations',
            'message' => 'Ошибка SQL при загрузке локаций',
            'details' => $e->getMessage(),
        ]);
    }
}

/**
 * Helper to fetch executors list from WP and GLPI.
 *
 * @return array{int id,string label}[]
 */
function wpglpi_fetch_executors() {
    global $wpdb;
    $rows = $wpdb->get_col(
        $wpdb->prepare("SELECT CAST(meta_value AS UNSIGNED) AS glpi_user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> ''", 'glpi_user_id')
    );
    if (!$rows) {
        return [];
    }
    $ids = [];
    foreach ($rows as $val) {
        $id = (int) $val;
        if ($id > 0 && !in_array($id, $ids, true)) {
            $ids[] = $id;
        }
    }
    if (empty($ids)) {
        return [];
    }
    try {
        $pdo = glpi_get_pdo();
        $placeholders = [];
        foreach ($ids as $i => $id) {
            $placeholders[] = ':e' . $i;
        }
        $sql = "SELECT u.id, u.name AS login, COALESCE(NULLIF(TRIM(CONCAT(u.realname,' ',u.firstname)),''), u.name) AS label FROM glpi_users u WHERE u.id IN (" . implode(',', $placeholders) . ") ORDER BY u.realname COLLATE utf8mb4_unicode_ci ASC, u.firstname COLLATE utf8mb4_unicode_ci ASC";
        $stmt = $pdo->prepare($sql);
        foreach ($ids as $i => $id) {
            $stmt->bindValue(':e' . $i, $id, PDO::PARAM_INT);
        }
        $stmt->execute();
        $grows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($grows as $g) {
            $gid = (int) ($g['id'] ?? 0);
            $label = trim($g['label'] ?? '');
            $login = $g['login'] ?? '';
            if ($login === 'vks_m5_local' || $label === '') {
                $label = 'Куткин Павел';
            }
            if ($gid > 0) {
                $out[] = ['id' => $gid, 'label' => $label];
            }
        }
        usort($out, function ($a, $b) {
            return strcmp(mb_strtolower($a['label'], 'UTF-8'), mb_strtolower($b['label'], 'UTF-8'));
        });
        array_unshift($out, ['id' => 0, 'label' => '—']);
        error_log('[wp-glpi:new-task] execs=' . (count($out) - 1));
        return $out;
    } catch (PDOException $e) {
        error_log('[wp-glpi:new-task] SQL error executors: ' . $e->getMessage());
        return [];
    }
}

/**
 * Load executors list.
 */
function wpglpi_load_executors() {
    wpglpi_nt_verify_nonce();
    if (!is_user_logged_in()) {
        wp_send_json_error(['type' => 'SECURITY', 'message' => 'Пользователь не авторизован']);
    }
    $execs = wpglpi_fetch_executors();
    if (empty($execs)) {
        wp_send_json_error([
            'type' => 'SQL',
            'scope' => 'executors',
            'message' => 'Ошибка SQL при загрузке исполнителей',
        ]);
    }
    wp_send_json_success(['executors' => $execs]);
}

/**
 * Create ticket via GLPI REST API.
 */
function wpglpi_create_ticket_api() {
    wpglpi_nt_verify_nonce();
    if (!is_user_logged_in()) {
        wp_send_json(['success' => false, 'type' => 'SECURITY', 'message' => 'Пользователь не авторизован']);
    }
    $wp_uid = get_current_user_id();
    $glpi_uid = get_mapped_glpi_user_id($wp_uid);
    if ($glpi_uid <= 0) {
        wp_send_json(['success' => false, 'type' => 'MAPPING', 'message' => 'Профиль WordPress не привязан к GLPI пользователю']);
    }

    $subject = sanitize_text_field($_POST['subject'] ?? '');
    $desc = sanitize_textarea_field($_POST['description'] ?? '');
    $cat = isset($_POST['category_id']) ? (int) $_POST['category_id'] : 0;
    $loc = isset($_POST['location_id']) ? (int) $_POST['location_id'] : 0;
    $executor = isset($_POST['executor_id']) ? (int) $_POST['executor_id'] : 0;
    $assign_me = !empty($_POST['assign_me']);

    $errors = [];
    if (mb_strlen($subject, 'UTF-8') < 3 || mb_strlen($subject, 'UTF-8') > 255) {
        $errors['subject'] = 'Тема 3-255 символов';
    }
    if (mb_strlen($desc, 'UTF-8') === 0 || mb_strlen($desc, 'UTF-8') > 5000) {
        $errors['description'] = 'Описание 1-5000 символов';
    }

    $execs = wpglpi_fetch_executors();
    $allowed = array_map(function ($e) { return (int) $e['id']; }, $execs);
    if ($executor > 0 && !in_array($executor, $allowed, true)) {
        $errors['executor'] = 'Недопустимый исполнитель';
    }
    if (!$assign_me && $executor === 0) {
        $errors['executor'] = 'Обязательное поле';
    }
    if ($assign_me) {
        $executor = $glpi_uid;
    }
    if (!empty($errors)) {
        wp_send_json(['success' => false, 'type' => 'VALIDATION', 'message' => 'Validation failed', 'details' => $errors]);
    }

    $api_url = defined('GLPI_API_URL') ? GLPI_API_URL : '';
    $app_token = defined('GLPI_APP_TOKEN') ? GLPI_APP_TOKEN : '';
    $user_token = defined('GLPI_USER_TOKEN') ? GLPI_USER_TOKEN : '';
    if (!$api_url || !$app_token || !$user_token) {
        wp_send_json(['success' => false, 'type' => 'CONFIG', 'message' => 'GLPI API не настроен (URL/токены)']);
    }

    $lock_key = 'wpglpi_create_' . $wp_uid;
    if (get_transient($lock_key)) {
        wp_send_json(['success' => false, 'type' => 'BUSY', 'message' => 'Заявка уже создаётся']);
    }
    set_transient($lock_key, 1, 10);

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
        delete_transient($lock_key);
        error_log('[wp-glpi:create] user=' . $wp_uid . ' glpi=' . $glpi_uid . ' result=fail:api');
        wp_send_json(['success' => false, 'type' => 'API', 'message' => 'Ошибка API (request)', 'details' => $resp->get_error_message()]);
    }
    $code = wp_remote_retrieve_response_code($resp);
    $body_raw = wp_remote_retrieve_body($resp);
    $body = json_decode($body_raw, true);
    if ($code >= 400 || !isset($body['id'])) {
        delete_transient($lock_key);
        error_log('[wp-glpi:create] user=' . $wp_uid . ' glpi=' . $glpi_uid . ' result=fail:' . $code);
        wp_send_json(['success' => false, 'type' => 'API', 'message' => 'Ошибка API (' . $code . ')', 'details' => $body_raw]);
    }
    $ticket_id = (int) $body['id'];

    $warning = false;
    if ($executor > 0) {
        $resp2 = wp_remote_post(rtrim($api_url, '/') . '/Ticket/' . $ticket_id . '/Ticket_User', [
            'headers' => $headers,
            'body'    => wp_json_encode(['input' => ['tickets_id' => $ticket_id, 'users_id' => $executor, 'type' => 2]]),
            'timeout' => 15,
        ]);
        if (is_wp_error($resp2) || wp_remote_retrieve_response_code($resp2) >= 400) {
            $warning = true;
        }
    }
    delete_transient($lock_key);
    error_log('[wp-glpi:create] user=' . $wp_uid . ' glpi=' . $glpi_uid . ' result=ok#' . $ticket_id);
    $out = ['success' => true, 'id' => $ticket_id];
    if ($warning) {
        $out['warning'] = 'assign_failed';
        $out['message'] = 'Назначение исполнителя не выполнено';
    }
    wp_send_json($out);
}
