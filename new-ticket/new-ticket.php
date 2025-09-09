<?php
if (!defined('ABSPATH')) exit;

// New Ticket (API) — create via GLPI REST with proper due_date and consistent JSON response
/**
 * This file registers AJAX endpoints and helpers for creating a ticket via GLPI REST API.
 * It assumes glpi user-token mapping and app token are defined in glpi-db-setup.php.
 *
 * Available helpers:
 * - gexe_json_error($code, $msg, $extra = [], $http = 200)
 * - gexe_api_open_session($user_token)
 * - gexe_api_close_session($session_token)
 */

require_once __DIR__ . '/../glpi-db-setup.php';
require_once __DIR__ . '/../glpi-utils.php';

if (!function_exists('gexe_json_error')) {
    function gexe_json_error($code, $msg, $extra = [], $http = 200) {
        if (!is_array($extra)) {
            $extra = ['extra' => $extra];
        }
        $payload = [
            'ok'      => false,
            'code'    => (string)$code,
            'message' => (string)$msg,
            'details' => $extra,
        ];
        wp_send_json($payload, $http);
    }
}

/**
 * Compose due_date "today 18:00:00" in WP timezone.
 * If current time already past 18:00, still set today 18:00 per project requirement.
 */
if (!function_exists('gexe_compose_due_date_today_18')) {
    function gexe_compose_due_date_today_18(): string {
        $tz_string = get_option('timezone_string');
        if (!$tz_string) {
            $offset = (float)get_option('gmt_offset');
            $tz_string = timezone_name_from_abbr('', (int)($offset * 3600), 0) ?: 'UTC';
        }
        try {
            $tz   = new DateTimeZone($tz_string);
        } catch (Exception $e) {
            $tz   = new DateTimeZone('UTC');
        }
        $now  = new DateTime('now', $tz);
        $due  = clone $now;
        $due->setTime(18, 0, 0);
        return $due->format('Y-m-d H:i:s');
    }
}

if (!function_exists('gexe_api_open_session')) {
    function gexe_api_open_session(string $user_token): array {
        $url = rtrim(GEXE_GLPI_API_URL, '/') . '/initSession';
        $resp = wp_remote_post($url, [
            'timeout' => 15,
            'headers' => [
                'App-Token'    => GEXE_GLPI_APP_TOKEN,
                'Authorization'=> 'user_token ' . $user_token,
            ],
        ]);
        if (is_wp_error($resp)) {
            return ['ok' => false, 'error' => $resp->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        $json = json_decode($body, true);
        if ($code >= 200 && $code < 300 && is_array($json) && !empty($json['session_token'])) {
            return ['ok' => true, 'session_token' => $json['session_token']];
        }
        return ['ok' => false, 'http' => $code, 'body' => $json ?: $body];
    }
}

if (!function_exists('gexe_api_close_session')) {
    function gexe_api_close_session(string $session_token): void {
        $url = rtrim(GEXE_GLPI_API_URL, '/') . '/killSession';
        wp_remote_get($url, [
            'timeout' => 15,
            'headers' => [
                'App-Token'    => GEXE_GLPI_APP_TOKEN,
                'Session-Token'=> $session_token,
            ],
        ]);
    }
}

function gexe_ajax_create_ticket_api() {
    if (!is_user_logged_in()) {
        gexe_json_error('not_logged_in', 'Не авторизован', ['hint' => 'Войдите в систему']);
    }

    $subject     = isset($_POST['subject']) ? sanitize_text_field((string)$_POST['subject']) : '';
    $content     = isset($_POST['content']) ? sanitize_textarea_field((string)$_POST['content']) : '';
    $category_id = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
    $location_id = isset($_POST['location_id']) ? (int)$_POST['location_id'] : 0;
    $assignee_id = isset($_POST['assignee_id']) ? (int)$_POST['assignee_id'] : 0;
    $due_date    = gexe_compose_due_date_today_18();

    if ($subject === '' || $content === '') {
        gexe_json_error('validation_failed', 'Тема и описание обязательны', ['hint' => 'Заполните все поля формы']);
    }

    $user_token = gexe_glpi_get_current_user_token();
    $glpi_uid   = gexe_get_current_glpi_uid();
    if (!$user_token || $glpi_uid <= 0) {
        gexe_json_error('not_mapped', 'Профиль не настроен: нет GLPI-ID или токена');
    }

    $session = gexe_api_open_session($user_token);
    if (!$session['ok']) {
        gexe_json_error('api_error', 'Не удалось открыть сессию GLPI', $session);
    }
    $session_token = $session['session_token'];

    $base = rtrim(GEXE_GLPI_API_URL, '/');
    $ticket = [
        'name'               => $subject,
        'content'            => $content,
        'itilcategories_id'  => $category_id ?: null,
        'locations_id'       => $location_id ?: null,
        // Срок исполнения — до 18:00 текущего дня (локальное WP-время)
        'due_date'           => $due_date,
        '_users_id_requester'=> $glpi_uid,
    ];
    if ($assignee_id > 0) {
        $ticket['assign'] = [['_type' => 'User', 'users_id' => $assignee_id, 'use_notification' => 1]];
    }
    $ticket = array_filter($ticket, function ($v) { return !is_null($v); });
    $payload = ['input' => $ticket];

    $resp = wp_remote_post($base . '/Ticket', [
        'timeout' => 15,
        'headers' => [
            'App-Token'     => GEXE_GLPI_APP_TOKEN,
            'Session-Token' => $session_token,
            'Content-Type'  => 'application/json',
        ],
        'body'    => wp_json_encode($payload),
    ]);
    if (is_wp_error($resp)) {
        gexe_api_close_session($session_token);
        gexe_json_error('api_error', 'GLPI не доступен', ['error' => $resp->get_error_message()]);
    }

    if (empty($session_token)) {
        gexe_json_error('api_error', 'Сессия GLPI не открыта', ['step' => 'no_session']);
    }

    $code = (int) wp_remote_retrieve_response_code($resp);
    $body = wp_remote_retrieve_body($resp);
    $json = json_decode($body, true);

    // GLPI обычно отвечает 201 Created; считаем любые 2xx успешными
    if ($code < 200 || $code >= 300) {
        if (is_array($json) && isset($json['id']) && (int)$json['id'] > 0) {
            $ticket_id = (int)$json['id'];
            gexe_api_close_session($session_token);
            wp_send_json([
                'ok'        => true,
                'code'      => 'created_with_warning',
                'message'   => 'Заявка создана, но GLPI вернул предупреждение',
                'ticket_id' => $ticket_id,
                'http'      => $code,
                'details'   => $json,
            ], 200);
        }
        gexe_api_close_session($session_token);
        gexe_json_error('api_http_' . $code, 'GLPI вернул ошибку при создании заявки', ['http' => $code, 'body' => $json ?: $body]);
    }

    if (!is_array($json) || !isset($json['id'])) {
        gexe_api_close_session($session_token);
        gexe_json_error('api_bad_response', 'Неожиданный ответ GLPI при создании заявки', ['body' => $body]);
    }
    $ticket_id = (int) $json['id'];

    gexe_api_close_session($session_token);

    wp_send_json([
        'ok'        => true,
        'message'   => 'Заявка создана',
        'ticket_id' => $ticket_id,
        'due_date'  => $due_date,
    ], 200);
}

add_action('wp_ajax_gexe_create_ticket_api', 'gexe_ajax_create_ticket_api');
