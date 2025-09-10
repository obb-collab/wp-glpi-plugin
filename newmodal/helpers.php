<?php
// newmodal/helpers.php
if (!defined('ABSPATH')) { exit; }

/**
 * Common helpers: auth, mapping WP ↔ GLPI, validation, idempotency, JSON responses.
 */

function nm_current_wp_user_id() {
    return get_current_user_id();
}

function nm_glpi_user_id_from_wp($wp_user_id = null) {
    $wp_user_id = $wp_user_id ?: nm_current_wp_user_id();
    if (!$wp_user_id) { return 0; }
    $id = get_user_meta($wp_user_id, 'glpi_user_id', true);
    return (int)$id;
}

function nm_glpi_user_token_from_wp($wp_user_id = null) {
    $wp_user_id = $wp_user_id ?: nm_current_wp_user_id();
    if (!$wp_user_id) { return ''; }
    $token = get_user_meta($wp_user_id, 'glpi_user_token', true);
    return (string)$token;
}

function nm_is_manager() {
    // Policy: admins are managers; or custom meta 'is_manager' == 1
    if (current_user_can('manage_options')) { return true; }
    $flag = get_user_meta(nm_current_wp_user_id(), 'glpi_is_manager', true);
    return (bool)$flag;
}

function nm_require_logged_in() {
    if (!is_user_logged_in()) {
        nm_json_error('auth_required', __('Authentication required', 'nm'), 401);
    }
}

function nm_check_nonce_or_fail($action = 'nm_nonce', $field = 'nonce') {
    $nonce = isset($_REQUEST['_ajax_nonce']) ? $_REQUEST['_ajax_nonce'] : ( $_REQUEST['nonce'] ?? '' );
    if (!wp_verify_nonce($nonce, 'nm_nonce')) {
        nm_json_error('forbidden', __('Invalid security token', 'nm'), 403);
    }
}

function nm_json_ok($payload = []) {
    $payload['ok'] = true;
    wp_send_json($payload);
}

function nm_json_error($code, $message, $http = 200, $extra = []) {
    $resp = array_merge(['ok' => false, 'code' => $code, 'message' => $message], $extra);
    wp_send_json($resp, $http);
}

/**
 * Idempotency key guard based on transients.
 */
function nm_idempotency_check_and_set($request_id, $ttl = 300) {
    if (!$request_id) { return; } // allow non-idempotent calls
    $key = 'nm_req_' . preg_replace('~[^a-zA-Z0-9_\-]~', '', $request_id);
    $existing = get_transient($key);
    if ($existing) {
        nm_json_error('duplicate', __('Duplicate request', 'nm'), 200);
    }
    set_transient($key, 1, $ttl);
}

/**
 * Validation helpers
 */
function nm_expect_int($value, $field) {
    if (!is_numeric($value)) {
        nm_json_error('validation_error', sprintf(__('%s must be numeric', 'nm'), $field));
    }
    return (int)$value;
}

function nm_expect_non_empty($value, $field) {
    if (!isset($value) || trim((string)$value) === '') {
        nm_json_error('validation_error', sprintf(__('%s is required', 'nm'), $field), 200, ['field' => $field]);
    }
    return trim((string)$value);
}

/**
 * Due date calculation: today 18:00 local; if past, next day 18:00.
 */
function nm_calc_due_date_dt() {
    $tz = wp_timezone();
    $now = new DateTime('now', $tz);
    $due = new DateTime('today 18:00', $tz);
    if ($now > $due) {
        $due->modify('+1 day');
    }
    return $due;
}

function nm_calc_due_date_sql() {
    $due = nm_calc_due_date_dt();
    // convert to MySQL datetime in WP timezone; assume GLPI stores local time or convert if needed
    return $due->format('Y-m-d H:i:s');
}

/**
 * ACL checks
 */
function nm_can_view_ticket($ticket_id, $glpi_user_id) {
    if (nm_is_manager()) { return true; }
    // Non-manager: must be assigned technician
    require_once __DIR__ . '/db.php';
    $row = nm_db_get_row("
        SELECT 1 FROM ".nm_tbl('tickets_users')." 
        WHERE tickets_id = %d AND type = 2 AND users_id = %d LIMIT 1
    ", [(int)$ticket_id, (int)$glpi_user_id]);
    return (bool)$row;
}

function nm_require_can_view_ticket($ticket_id, $glpi_user_id) {
    if (!nm_can_view_ticket($ticket_id, $glpi_user_id)) {
        nm_json_error('forbidden', __('You are not allowed to view this ticket', 'nm'), 403);
    }
}

function nm_require_can_assign($assignee_id) {
    // Only managers can assign others; self-assign allowed
    $me = nm_glpi_user_id_from_wp();
    if ($assignee_id != $me && !nm_is_manager()) {
        nm_json_error('forbidden', __('Insufficient rights to assign other users', 'nm'), 403);
    }
}

/**
 * Small helpers
 */
function nm_s($v) { return esc_html($v); }

function nm_get_app_token(){
    $tok = get_option(NM_META_APP_TOKEN);
    return is_string($tok) ? trim($tok) : '';
}

function nm_get_current_glpi_user_token(){
    $tok = get_user_meta(get_current_user_id(), NM_META_USER_TOKEN, true);
    return is_string($tok) ? trim($tok) : '';
}

function nm_require_nonce() {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        nm_json_error('bad_method', __('Неверный метод запроса.', 'nm'));
    }
    if (!isset($_POST['nm_nonce']) || !wp_verify_nonce($_POST['nm_nonce'], 'nm_ajax')) {
        nm_json_error('forbidden', __('Сессия устарела. Обновите страницу.', 'nm'));
    }
}

function nm_humanize_api_error($data){
    $msg = is_array($data) && isset($data['message']) ? $data['message'] : '';
    if (stripos($msg,'App-Token') !== false) $msg .= ' · Проверьте App-Token в настройках WP.';
    if (stripos($msg,'user_token') !== false) $msg .= ' · Проверьте токен пользователя в его профиле.';
    if (stripos($msg,'not found') !== false) $msg .= ' · Проверьте ID заявки.';
    $map = [
        'ERROR_APP_TOKEN_PARAMETERS_MISSING' => 'Не указан App-Token. Укажите его в настройках.',
        'ERROR_NOT_ALLOWED_IP' => 'IP адрес не разрешён для API GLPI.',
        'ERROR_LOGIN_PARAMETERS_MISSING' => 'Не указан user_token.',
        'ERROR_ITEM_NOT_FOUND' => 'Объект не найден.',
        'ERROR_RIGHT_MISSING' => 'Недостаточно прав.',
    ];
    foreach($map as $k=>$hint){ if (stripos($msg,$k)!==false){ $msg .= ' · '.$hint; break; } }
    return $msg ?: 'Неизвестная ошибка GLPI API';
}

function nm_idempotent_check_and_set($rid){
    if (!$rid) return false;
    $key='nm_rid_'.preg_replace('~[^a-zA-Z0-9_-]~','',$rid);
    if (get_transient($key)) return true;
    set_transient($key,1,60);
    return false;
}

function nm_fmt_dt($mysql){
    if (!$mysql) return '';
    try {
        $ts = strtotime($mysql);
        return date_i18n('d.m.Y H:i', $ts);
    } catch (Exception $e){
        return $mysql;
    }
}
