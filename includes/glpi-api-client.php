<?php
if (!defined('ABSPATH')) exit;

/**
 * Simple GLPI REST API client for ticket operations.
 */
function gexe_glpi_api_base() {
    if (defined('GLPI_API_URL')) {
        return rtrim(GLPI_API_URL, '/');
    }
    if (defined('GEXE_GLPI_API_URL')) {
        return rtrim(GEXE_GLPI_API_URL, '/');
    }
    return '';
}

function gexe_glpi_api_headers() {
    $app  = defined('GLPI_APP_TOKEN') ? GLPI_APP_TOKEN : (defined('GEXE_GLPI_APP_TOKEN') ? GEXE_GLPI_APP_TOKEN : '');
    $user = defined('GLPI_USER_TOKEN') ? GLPI_USER_TOKEN : (defined('GEXE_GLPI_USER_TOKEN') ? GEXE_GLPI_USER_TOKEN : '');
    return [
        'App-Token'    => $app,
        'Authorization'=> 'user_token ' . $user,
        'Content-Type' => 'application/json',
    ];
}

/**
 * Create ticket via GLPI REST API.
 * @param array $input Ticket payload inside `input`.
 * @return array
 */
function gexe_glpi_api_create_ticket(array $input) {
    $url = gexe_glpi_api_base() . '/Ticket';
    $args = [
        'method'  => 'POST',
        'headers' => gexe_glpi_api_headers(),
        'body'    => wp_json_encode(['input' => $input]),
        'timeout' => 15,
    ];
    $resp = wp_remote_request($url, $args);
    if (is_wp_error($resp)) {
        return ['ok' => false, 'code' => 'HTTP', 'message' => $resp->get_error_message()];
    }
    $code = wp_remote_retrieve_response_code($resp);
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code >= 200 && $code < 300 && isset($body['id'])) {
        return ['ok' => true, 'id' => (int)$body['id']];
    }
    return ['ok' => false, 'code' => $code, 'message' => wp_remote_retrieve_body($resp)];
}

/**
 * Assign executor to ticket via GLPI REST API.
 */
function gexe_glpi_api_assign_ticket($ticket_id, $user_id) {
    $ticket_id = (int) $ticket_id;
    $user_id   = (int) $user_id;
    if ($ticket_id <= 0 || $user_id <= 0) {
        return ['ok' => false, 'code' => 'VALIDATION'];
    }
    $url = gexe_glpi_api_base() . '/Ticket/' . $ticket_id . '/Ticket_User';
    $payload = ['input' => ['tickets_id' => $ticket_id, 'users_id' => $user_id, 'type' => 2]];
    $args = [
        'method'  => 'POST',
        'headers' => gexe_glpi_api_headers(),
        'body'    => wp_json_encode($payload),
        'timeout' => 15,
    ];
    $resp = wp_remote_request($url, $args);
    if (is_wp_error($resp)) {
        return ['ok' => false, 'code' => 'HTTP', 'message' => $resp->get_error_message()];
    }
    $code = wp_remote_retrieve_response_code($resp);
    if ($code >= 200 && $code < 300) {
        return ['ok' => true];
    }
    return ['ok' => false, 'code' => $code, 'message' => wp_remote_retrieve_body($resp)];
}
