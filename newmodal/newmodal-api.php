<?php
/**
 * New Modal – GLPI REST API wrapper
 * Uses per-user personal token mapping configured in glpi-db-setup.php
 * No SQL calls here.
 */
if (!defined('ABSPATH')) exit;

// Expect these to be defined in project:
// GEXE_GLPI_API_URL, GEXE_GLPI_APP_TOKEN

/**
 * Resolve current WP user -> GLPI user and personal token
 */
function gexe_newmodal_current_glpi_context(): array {
    $u = wp_get_current_user();
    if (!$u || !$u->ID) {
        throw new RuntimeException('User is not authenticated.');
    }
    $glpi_user_id = (int) get_user_meta($u->ID, 'glpi_user_id', true);
    if ($glpi_user_id <= 0) {
        throw new RuntimeException('Current user is not linked to a GLPI user.');
    }
    // Token registry should be available from glpi-db-setup.php
    if (!function_exists('gexe_get_personal_token_by_glpi_id')) {
        throw new RuntimeException('Token registry is not available.');
    }
    $token = (string) gexe_get_personal_token_by_glpi_id($glpi_user_id);
    if ($token === '') {
        throw new RuntimeException('Personal GLPI token is missing for this user.');
    }
    return [
        'glpi_user_id' => $glpi_user_id,
        'user_token'   => $token
    ];
}

/**
 * Perform GLPI REST API request with app & personal tokens.
 * Automatically opens a session when needed.
 */
function gexe_newmodal_api_call(string $method, string $path, array $payload = [], array $query = []): array {
    if (!defined('GEXE_GLPI_API_URL') || !defined('GEXE_GLPI_APP_TOKEN')) {
        throw new RuntimeException('GLPI API configuration is missing.');
    }
    $ctx = gexe_newmodal_current_glpi_context();
    $base = rtrim(GEXE_GLPI_API_URL, '/');

    // Build URL with query
    $url = $base . '/' . ltrim($path, '/');
    if ($query) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
    }

    $args = [
        'headers' => [
            'App-Token'   => GEXE_GLPI_APP_TOKEN,
            'Authorization' => 'user_token ' . $ctx['user_token'],
            'Content-Type'  => 'application/json'
        ],
        'method'  => strtoupper($method),
        'timeout' => 15
    ];
    if (in_array($args['method'], ['POST','PUT','PATCH'], true)) {
        $args['body'] = wp_json_encode($payload);
    }

    $res = wp_remote_request($url, $args);
    if (is_wp_error($res)) {
        throw new RuntimeException($res->get_error_message());
    }
    $code = wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);
    $data = json_decode($body, true);
    if ($code < 200 || $code >= 300) {
        $msg = is_array($data) && isset($data['message']) ? $data['message'] : ('HTTP ' . $code);
        throw new RuntimeException('GLPI API error: ' . $msg);
    }
    return is_array($data) ? $data : ['raw' => $body];
}

/**
 * Load ticket details with followups/comments
 */
function gexe_newmodal_api_get_ticket(int $ticket_id): array {
    // Ticket
    $ticket = gexe_newmodal_api_call('GET', "Ticket/$ticket_id", [], ['expand_dropdowns' => true]);
    // Followups
    $fups = gexe_newmodal_api_call('GET', "Ticket/$ticket_id/ITILFollowup", [], ['range' => '0-200']);
    // Sort followups by date ASC (GLPI returns newest first sometimes)
    if (is_array($fups)) {
        usort($fups, function ($a, $b) {
            return strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? ''));
        });
    }
    return [
        'ticket'    => $ticket,
        'followups' => $fups
    ];
}

/**
 * Add comment (ITILFollowup) to ticket
 */
function gexe_newmodal_api_add_followup(int $ticket_id, string $content): array {
    $payload = [
        'input' => [
            'itemtype'   => 'Ticket',
            'items_id'   => $ticket_id,
            'content'    => $content
        ]
    ];
    return gexe_newmodal_api_call('POST', 'ITILFollowup', $payload);
}

/**
 * Change ticket status
 * Common statuses in GLPI 9.5: 1=new, 2=processing (assigned), 3=planned, 4=waiting, 5=solved, 6=closed (in some setups 6=solved)
 * Project note: status "resolved" = 6 per user brief.
 */
function gexe_newmodal_api_set_status(int $ticket_id, int $status): array {
    $payload = [
        'input' => [
            'id'     => $ticket_id,
            'status' => $status
        ]
    ];
    return gexe_newmodal_api_call('PUT', "Ticket/$ticket_id", $payload);
}

/**
 * Assign current user as technician to the ticket
 */
function gexe_newmodal_api_assign_self(int $ticket_id): array {
    $ctx = gexe_newmodal_current_glpi_context();
    $payload = [
        'input' => [
            'itemtype'    => 'Ticket',
            'items_id'    => $ticket_id,
            'type'        => 2, // assigned_to
            'users_id'    => $ctx['glpi_user_id'],
            'use_notification' => 1
        ]
    ];
    return gexe_newmodal_api_call('POST', 'Ticket_User', $payload);
}
