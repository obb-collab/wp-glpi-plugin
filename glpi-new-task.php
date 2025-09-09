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
require_once __DIR__ . '/includes/glpi-sql.php';

add_action('wp_enqueue_scripts', function () {
    // Styles are enqueued globally from main plugin; here only JS is ensured.
    wp_register_style('glpi-new-task', plugin_dir_url(__FILE__) . 'glpi-new-task.css', [], '1.0.0');
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
// === Legacy endpoints used by gexe-filter.js: separate dictionaries ===
add_action('wp_ajax_glpi_get_categories', 'glpi_ajax_get_categories');
function glpi_ajax_get_categories() {
    glpi_nt_verify_nonce();
    if (!is_user_logged_in()) {
        wp_send_json_error(['type' => 'SECURITY', 'scope' => 'all', 'message' => 'Пользователь не авторизован']);
    }
    try {
        $pdo = glpi_get_pdo();
        $rows = $pdo->query("SELECT c.id, c.name, c.completename
                              FROM glpi_itilcategories AS c
                              WHERE c.is_helpdeskvisible = 1
                              ORDER BY c.completename ASC")->fetchAll(PDO::FETCH_ASSOC);
        wp_send_json_success(['list' => array_values($rows)]);
    } catch (Throwable $e) {
        error_log('[wp-glpi:new-task:dicts:categories] ' . $e->getMessage());
        wp_send_json_error(['type' => 'SQL', 'scope' => 'categories', 'message' => 'Ошибка загрузки категорий', 'details' => $e->getMessage()]);
    }
}

add_action('wp_ajax_glpi_get_locations', 'glpi_ajax_get_locations');
function glpi_ajax_get_locations() {
    glpi_nt_verify_nonce();
    if (!is_user_logged_in()) {
        wp_send_json_error(['type' => 'SECURITY', 'scope' => 'all', 'message' => 'Пользователь не авторизован']);
    }
    try {
        $pdo = glpi_get_pdo();
        $rows = $pdo->query("SELECT l.id, l.name, l.completename
                              FROM glpi_locations AS l
                              ORDER BY l.completename ASC")->fetchAll(PDO::FETCH_ASSOC);
        wp_send_json_success(['list' => array_values($rows)]);
    } catch (Throwable $e) {
        error_log('[wp-glpi:new-task:dicts:locations] ' . $e->getMessage());
        wp_send_json_error(['type' => 'SQL', 'scope' => 'locations', 'message' => 'Ошибка загрузки местоположений', 'details' => $e->getMessage()]);
    }
}

add_action('wp_ajax_glpi_get_executors', 'glpi_ajax_get_executors');
function glpi_ajax_get_executors() {
    glpi_nt_verify_nonce();
    if (!is_user_logged_in()) {
        wp_send_json_error(['type' => 'SECURITY', 'scope' => 'all', 'message' => 'Пользователь не авторизован']);
    }
    try {
        // Берём списки исполнителей из существующей мапы WP→GLPI.
        $list = glpi_get_wp_executors(); // возвращает [{'user_id','display_name','glpi_user_id'}...]
        $out = [];
        foreach ($list as $u) {
            $out[] = [
                'id'           => (int)$u['glpi_user_id'],
                'name'         => (string)$u['display_name'],
                'completename' => (string)$u['display_name'],
            ];
        }
        wp_send_json_success(['list' => $out]);
    } catch (Throwable $e) {
        error_log('[wp-glpi:new-task:dicts:executors] ' . $e->getMessage());
        wp_send_json_error(['type' => 'SQL', 'scope' => 'executors', 'message' => 'Ошибка загрузки исполнителей', 'details' => $e->getMessage()]);
    }
}

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

        $meta = ['empty' => ['categories' => empty($categories), 'locations' => empty($locations)]];

        error_log('[wp-glpi:new-task] catalogs loaded: cats=' . count($categories) . ', locs=' . count($locations));

        wp_send_json_success([
            'categories' => $categories,
            'locations'  => $locations,
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

add_action('wp_ajax_glpi_create_ticket_sql', 'glpi_ajax_create_ticket_sql');
function glpi_ajax_create_ticket_sql() {
    if (!check_ajax_referer('gexe_actions', 'nonce', false)) {
        wp_send_json(['ok' => false, 'code' => 'SECURITY/NO_CSRF', 'message' => 'Сессия устарела. Обновите страницу.']);
    }
    if (!is_user_logged_in() || !current_user_can('read')) {
        wp_send_json(['ok' => false, 'code' => 'not_logged_in', 'message' => 'Сессия неактивна. Войдите в систему.']);
    }
    $wp_uid = get_current_user_id();
    $glpi_uid = gexe_get_glpi_user_id($wp_uid);
    if ($glpi_uid <= 0) {
        wp_send_json(['ok' => false, 'code' => 'not_mapped', 'message' => 'Профиль не настроен: нет GLPI-ID.']);
    }

    $lock_key = 'gexe_nt_submit_' . $wp_uid;
    if (get_transient($lock_key)) {
        wp_send_json(['ok' => false, 'code' => 'rate_limit_client', 'message' => 'Слишком часто. Повторите через несколько секунд.']);
    }
    set_transient($lock_key, 1, 10);

    $subject = mb_substr(trim((string) ($_POST['subject'] ?? '')), 0, 255);
    $content = mb_substr(trim((string) ($_POST['content'] ?? '')), 0, 4096);
    $cat_id = absint($_POST['category_id'] ?? 0);
    $loc_id = absint($_POST['location_id'] ?? 0);
    $assignee_glpi_id = absint($_POST['assignee_glpi_id'] ?? 0);
    $is_self = !empty($_POST['is_self_assignee']);

    $errors = [];
    if (mb_strlen($subject) < 3) $errors['subject'] = true;
    if (mb_strlen($content) < 3) $errors['content'] = true;
    if ($cat_id <= 0) $errors['category_id'] = true;
    if ($loc_id <= 0) $errors['location_id'] = true;

    $allowed = wp_list_pluck(function_exists('gexe_get_assignee_options') ? gexe_get_assignee_options() : [], 'id');
    if ($is_self) {
        $assignee_glpi_id = $glpi_uid;
    } elseif ($assignee_glpi_id <= 0 || !in_array($assignee_glpi_id, $allowed, true)) {
        $errors['assignee_glpi_id'] = true;
    }

    if (!empty($errors)) {
        wp_send_json(['ok' => false, 'code' => 'validation', 'message' => 'Заполните обязательные поля корректно.']);
    }

    global $glpi_db;
    $sql = $glpi_db->prepare(
        "SELECT id FROM glpi_tickets WHERE name=%s AND date >= NOW() - INTERVAL 60 SECOND AND status = 1 AND itilcategories_id = %d AND locations_id = %d LIMIT 1",
        $subject,
        $cat_id,
        $loc_id
    );
    $dup = $glpi_db->get_var($sql);
    if ($dup) {
        wp_send_json(['ok' => true, 'code' => 'already_exists', 'message' => 'Заявка уже существует', 'ticket_id' => (int) $dup]);
    }

    $start = microtime(true);
    $res = create_ticket_sql([
        'name' => $subject,
        'content' => $content,
        'itilcategories_id' => $cat_id,
        'locations_id' => $loc_id,
        'requester_id' => $glpi_uid,
        'assignee_id' => $assignee_glpi_id,
        'type' => 1,
    ]);
    $elapsed = (int) round((microtime(true) - $start) * 1000);
    if (empty($res['ok'])) {
        error_log('[new-ticket] user=' . $wp_uid . '/' . $glpi_uid . ' sql=ticket+links err code=sql_error ticket_id=0 elapsed=' . $elapsed);
        wp_send_json(['ok' => false, 'code' => 'sql_error', 'message' => 'Ошибка базы данных. Повторите позже.']);
    }
    $tid = (int) $res['ticket_id'];
    error_log('[new-ticket] user=' . $wp_uid . '/' . $glpi_uid . ' sql=ticket+links ok code=created ticket_id=' . $tid . ' elapsed=' . $elapsed);

    // Пинаем GLPI для выполнения уведомлений по заявке
    $kick = gexe_glpi_trigger([
        'ticket_id' => $tid,
        'tasks'     => ['queuednotification'],
    ]);
    if (empty($kick['ok'])) {
        error_log('[new-ticket][trigger] fail method=' . ($kick['method'] ?? 'n/a') . ' detail=' . ($kick['detail'] ?? ''));
    }

    wp_send_json(['ok' => true, 'code' => 'created', 'message' => 'Заявка создана', 'ticket_id' => $tid]);
}
// NOTE: Fix nonce key to match frontend ('gexe_form_data') to allow ticket creation.
add_action('wp_ajax_glpi_create_ticket_api', 'glpi_ajax_create_ticket_api');
add_action('wp_ajax_nopriv_glpi_create_ticket_api', 'glpi_ajax_create_ticket_api');

function glpi_ajax_create_ticket_api() {
    // Frontend issues nonce for 'gexe_form_data' via gexe_refresh_nonce(); use the same here.
    if (!check_ajax_referer('gexe_form_data', 'nonce', false)) {
        wp_send_json(['ok' => false, 'code' => 'forbidden', 'detail' => 'Invalid or expired nonce']);
    }

    /**
     * Важно: после разбора «кривожопости токенов» — все REST-запросы должны
     * идти с персональным user_token текущего WP-пользователя (см. gexe_glpi_api_headers()).
     * Здесь ловим любые PHP-ошибки и возвращаем JSON, а не 500 HTML.
     */

    // Catch ANY PHP error and always return JSON instead of 500 HTML
    set_error_handler(function($errno,$errstr,$errfile,$errline){
        throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    });
    try {
        if (!is_user_logged_in() || !current_user_can('read')) {
            wp_send_json(['ok' => false, 'code' => 'not_logged_in']);
        }
        $wp_uid  = get_current_user_id();

        $lock_key = 'gexe_nt_submit_' . $wp_uid;
        if (get_transient($lock_key)) {
            wp_send_json(['ok' => false, 'code' => 'rate_limit_client']);
        }
        set_transient($lock_key, 1, 10);

        $subject = trim((string)($_POST['subject'] ?? ''));
        $content = trim((string)($_POST['content'] ?? ''));
        $cat_id  = (int)($_POST['category_id'] ?? 0);
        $loc_id  = (int)($_POST['location_id'] ?? 0);
        $assignee_glpi_id = $_POST['assignee_glpi_id'] ?? 0;
        $is_self = (int)($_POST['is_self_assignee'] ?? 0) === 1;

        $errors = [];
        // requester (GLPI id автора из маппинга)
        $glpi_uid = function_exists('gexe_get_glpi_user_id') ? (int) gexe_get_glpi_user_id() : 0;
        if ($glpi_uid <= 0) { $errors['requester'] = true; }
        if ($subject === '' || mb_strlen($subject) > 255) { $errors['subject'] = true; }
        if ($content === '' || mb_strlen($content) > 10000) { $errors['content'] = true; }
        if ($cat_id <= 0) { $errors['category_id'] = true; }
        if ($loc_id <= 0) { $errors['location_id'] = true; }

        $allowed = wp_list_pluck(function_exists('gexe_get_assignee_options') ? gexe_get_assignee_options() : [], 'id');
        if ($is_self) {
            $assignee_glpi_id = $glpi_uid;
        } elseif ($assignee_glpi_id <= 0 || !in_array((int)$assignee_glpi_id, $allowed, true)) {
            $errors['assignee_glpi_id'] = true;
        } else {
            // нормализуем
            $assignee_glpi_id = (int)$assignee_glpi_id;
        }

        if (!empty($errors)) {
            wp_send_json(['ok' => false, 'code' => 'validation']);
        }

        require_once __DIR__ . '/includes/glpi-api.php';

        $start      = microtime(true);
        $log_init   = 'err:unknown';
        $log_create = 'skip';
        $log_assign = 'skip';
        $ticket_id  = 0;

        // initSession
        $token = gexe_glpi_api_get_session_token();
        if (is_array($token) && isset($token['error'])) {
            // normalize possible non-WP_Error shape
            $token = new WP_Error('api_unreachable', (string)$token['error']);
        }
        if (is_wp_error($token)) {
            $err = $token->get_error_code();
            $log_init = 'err:' . $err;
            $elapsed = (int) round((microtime(true) - $start) * 1000);
            error_log('[new-ticket-api] initSession=' . $log_init . ' create=' . $log_create . ' assign=' . $log_assign . ' tid=0 elapsed=' . $elapsed);
            wp_send_json(['ok' => false, 'code' => $err, 'detail' => $token->get_error_message()]);
        }
        $log_init = 'ok';

        $search = '/search/Ticket?criteria[0][field]=1&criteria[0][searchtype]=contains&criteria[0][value]=' . rawurlencode($subject) .
            '&criteria[1][link]=AND&criteria[1][field]=12&criteria[1][searchtype]=equals&criteria[1][value]=' . $cat_id .
            '&criteria[2][link]=AND&criteria[2][field]=82&criteria[2][searchtype]=equals&criteria[2][value]=' . $loc_id .
            '&forcedisplay[0]=2&range=0-1';
        $dup = gexe_glpi_api_request('GET', $search);
        if (!is_wp_error($dup) && $dup['code'] === 200 && !empty($dup['body']['data'][0])) {
            $first = $dup['body']['data'][0];
            $dup_date = isset($first['date']) ? strtotime($first['date']) : 0;
            if ($dup_date && $dup_date >= time() - 60) {
                $dup_id = isset($first['2']) ? (int) $first['2'] : 0;
                if ($dup_id) {
                    $elapsed = (int) round((microtime(true) - $start) * 1000);
                    error_log('[new-ticket-api] initSession=' . $log_init . ' create=skip assign=skip tid=' . $dup_id . ' elapsed=' . $elapsed);
                    wp_send_json(['ok' => true, 'code' => 'already_exists', 'ticket_id' => $dup_id]);
                }
            }
        }

        /**
         * CREATE ticket (через REST) СРАЗУ с актёрами:
         *  - _users_id_requester  — постановщик (автор формы)
         *  - _users_id_assign     — исполнитель (по чекбоксу/списку)
         *
         * Это устраняет второй POST на /Ticket/{id}/Ticket_User
         * и избегает ошибок назначения при разных токенах.
         */
        $create_input = [
            'name'              => $subject,
            'content'           => $content,
            'itilcategories_id' => $cat_id,
            'locations_id'      => $loc_id,
            'status'            => 1,
            'type'              => 1,
            '_users_id_requester' => $glpi_uid,
        ];
        if ($assignee_glpi_id > 0) {
            $create_input['_users_id_assign'] = $assignee_glpi_id;
        }

        // CREATE ticket
        $r1 = gexe_glpi_api_request('POST', '/Ticket', [
            'input' => $create_input,
        ]);
        if (is_wp_error($r1)) {
            // network/DNS/timeout
            $log_create = 'err:api_unreachable';
            $elapsed = (int) round((microtime(true) - $start) * 1000);
            error_log('[new-ticket-api] initSession=' . $log_init . ' create=' . $log_create . ' assign=' . $log_assign . ' tid=0 elapsed=' . $elapsed);
            wp_send_json(['ok' => false, 'code' => 'api_unreachable', 'detail' => $r1->get_error_message()]);
        }
        $code1 = isset($r1['code']) ? (int)$r1['code'] : 0;
        $body1 = $r1['body'] ?? null;
        if (is_string($body1)) {
            $decoded = json_decode($body1, true);
            if (json_last_error() === JSON_ERROR_NONE) { $body1 = $decoded; }
        }
        if ($code1 === 401 || $code1 === 403) {
            $log_create = 'err:api_auth';
            $elapsed = (int) round((microtime(true) - $start) * 1000);
            error_log('[new-ticket-api] initSession=' . $log_init . ' create=' . $log_create . ' assign=' . $log_assign . ' tid=0 elapsed=' . $elapsed);
            wp_send_json(['ok' => false, 'code' => 'api_auth']);
        }
        if ($code1 >= 400) {
            $log_create = ($code1 === 400) ? 'err:api_validation' : 'err:api_unreachable';
            $elapsed = (int) round((microtime(true) - $start) * 1000);
            error_log('[new-ticket-api] initSession=' . $log_init . ' create=' . $log_create . ' assign=' . $log_assign . ' tid=0 elapsed=' . $elapsed);
            wp_send_json([
                'ok'    => false,
                'code'  => ($code1 === 400) ? 'api_validation' : 'api_unreachable',
                'detail'=> (is_array($body1) && isset($body1['message'])) ? (string)$body1['message'] : ('HTTP ' . $code1),
            ]);
        }
        $ticket_id = (is_array($body1) && isset($body1['id'])) ? (int)$body1['id'] : 0;
        if ($ticket_id <= 0) {
            $log_create = 'err:api_unreachable';
            $elapsed = (int) round((microtime(true) - $start) * 1000);
            error_log('[new-ticket-api] initSession=' . $log_init . ' create=' . $log_create . ' assign=' . $log_assign . ' tid=0 elapsed=' . $elapsed);
            wp_send_json(['ok' => false, 'code' => 'api_unreachable', 'detail' => 'Empty ticket id']);
        }
        $log_create = 'ok';

        // Назначение исполнителя уже сделано в CREATE через _users_id_assign
        $log_assign = $assignee_glpi_id > 0 ? 'ok@create' : 'skip';

        $elapsed = (int) round((microtime(true) - $start) * 1000);
        error_log('[new-ticket-api] initSession=' . $log_init . ' create=' . $log_create . ' assign=' . $log_assign . ' tid=' . $ticket_id . ' elapsed=' . $elapsed);
        wp_send_json(['ok' => true, 'ticket_id' => $ticket_id]);

    } catch (Throwable $e) {
        // Always return JSON on PHP fatals/notices, avoid WP 500 HTML
        error_log('[new-ticket-api] fatal ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
        wp_send_json(['ok'=>false,'code'=>'php_fatal','detail'=>$e->getMessage()]);
    } finally {
        restore_error_handler();
    }
}
