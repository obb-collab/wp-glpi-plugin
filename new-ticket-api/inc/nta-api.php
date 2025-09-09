<?php
if (!defined('ABSPATH')) exit;

// GLPI REST API config
// Adjust to your GLPI REST API endpoint and App-Token
define('NTA_GLPI_API_URL', 'http://192.168.100.12/glpi/apirest.php');
define('NTA_GLPI_APP_TOKEN', 'nqubXrD6j55bgLRuD1mrrtz5D69cXz94HHPvgmac');

function nta_api_headers($user_token, $session_token = '') {
    $h = [
        'App-Token'    => NTA_GLPI_APP_TOKEN,
        'Content-Type' => 'application/json',
        'Accept'       => 'application/json',
        'Authorization'=> 'user_token ' . $user_token,
    ];
    if ($session_token) $h['Session-Token'] = $session_token;
    return $h;
}

function nta_api_request($method, $path, $headers, $body = null) {
    $url = rtrim(NTA_GLPI_API_URL,'/') . '/' . ltrim($path,'/');
    $args = [
        'method'  => $method,
        'headers' => $headers,
        'timeout' => 15,
    ];
    if ($body !== null) $args['body'] = is_string($body) ? $body : wp_json_encode($body);
    $res = wp_remote_request($url, $args);
    if (is_wp_error($res)) {
        return ['ok'=>false, 'code'=>'network_error', 'message'=>$res->get_error_message()];
    }
    $code = (int) wp_remote_retrieve_response_code($res);
    $raw  = wp_remote_retrieve_body($res);
    $json = json_decode($raw, true);
    if ($code >= 200 && $code < 300) {
        return ['ok'=>true, 'data'=>$json];
    }
    $msg = $json['message'] ?? ('HTTP '.$code);
    return ['ok'=>false, 'code'=>'api_error', 'message'=>$msg, 'http'=>$code, 'raw'=>$raw];
}

function nta_api_open_session($user_token) {
    $headers = nta_api_headers($user_token);
    $r = nta_api_request('POST', 'initSession', $headers);
    if (!$r['ok']) return $r;
    $sess = $r['data']['session_token'] ?? '';
    if (!$sess) return ['ok'=>false,'code'=>'api_error','message'=>'No session token'];
    return ['ok'=>true,'session'=>$sess];
}

/**
 * Compute due date as 17:30 today (site timezone); if now > 17:30, then next day 17:30.
 * If the calculated day is weekend (Sat/Sun), move to next Monday 17:30.
 */
function nta_compute_due_1730() {
    try {
        $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
    } catch (Throwable $e) {
        $tz = new DateTimeZone('UTC');
    }
    $now = new DateTime('now', $tz);
    $due = clone $now;
    // set to 17:30:00 of today
    $due->setTime(17, 30, 0);
    // if already past 17:30 → next day
    if ($now > $due) {
        $due->modify('+1 day');
        $due->setTime(17, 30, 0);
    }
    // If falls on weekend, roll to Monday 17:30
    // N: 1=Mon ... 6=Sat, 7=Sun
    $dow = (int)$due->format('N');
    if ($dow === 6) {          // Saturday
        $due->modify('+2 days');
    } elseif ($dow === 7) {    // Sunday
        $due->modify('+1 day');
    }
    return $due->format('Y-m-d H:i:s');
}

function nta_api_kill_session($user_token, $session_token) {
    $headers = nta_api_headers($user_token, $session_token);
    return nta_api_request('DELETE', 'killSession', $headers);
}

function nta_api_create_ticket($user_token, $input, $requester_glpi_id, $assignee_glpi_id) {
    // 1) open session
    $s = nta_api_open_session($user_token);
    if (!$s['ok']) return $s;
    $sess = $s['session'];
    $headers = nta_api_headers($user_token, $sess);

    // compute planned due date (17:30 rule)
    $due = nta_compute_due_1730();

    try {
        // 2) create ticket
        $payload = [
            'input' => [
                'name'              => $input['title'],
                'content'           => $input['content'],
                'status'            => 1,
                'itilcategories_id' => (int)$input['category_id'],
                'locations_id'      => (int)$input['location_id'],
                'due_date'          => $due,
            ]
        ];
        $r1 = nta_api_request('POST', 'Ticket', $headers, $payload);
        if (!$r1['ok']) return $r1;
        $tid = (int)($r1['data']['id'] ?? 0);
        if ($tid <= 0) return ['ok'=>false,'code'=>'api_error','message'=>'Ticket ID not returned'];

        // 3) add requester (type=1)
        $r2 = nta_api_request('POST', 'Ticket_User', $headers, [
            'input' => [
                'tickets_id' => $tid,
                'users_id'   => (int)$requester_glpi_id,
                'type'       => 1
            ]
        ]);
        if (!$r2['ok']) return $r2;

        // 4) add assignee (type=2)
        $r3 = nta_api_request('POST', 'Ticket_User', $headers, [
            'input' => [
                'tickets_id' => $tid,
                'users_id'   => (int)$assignee_glpi_id,
                'type'       => 2
            ]
        ]);
        if (!$r3['ok']) return $r3;

        return ['ok'=>true, 'ticket_id'=>$tid];
    } finally {
        // 5) close session (best-effort)
        nta_api_kill_session($user_token, $sess);
    }
}
