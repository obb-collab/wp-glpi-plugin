<?php
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/../helpers.php';

function nm_glpi_baseurl(){
    $opt = get_option(NM_OPT_BASE_URL);
    $url = is_string($opt) && $opt ? $opt : 'http://192.168.100.12/glpi/apirest.php';
    return apply_filters('nm_glpi_baseurl', rtrim($url,'/'));
}

function nm_glpi_request($method, $path, $body = null, $user_token = ''){
    $base = nm_glpi_baseurl();
    $app  = nm_get_app_token();
    if (!$app) return [false, ['message'=>'Не настроен App-Token']];

    $headers = [
        'App-Token: ' . $app,
        'Content-Type: application/json',
    ];
    if ($user_token){
        $headers[] = 'Session-Token: ' . $user_token;
    }

    $args = ['method'=>$method, 'timeout'=>15, 'headers'=>$headers];
    if ($body !== null) {
        $args['body'] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $url = rtrim($base,'/') . '/' . ltrim($path,'/');
    $res = wp_remote_request($url, $args);
    if (is_wp_error($res)) return [false, ['message'=>$res->get_error_message()]];
    $code = wp_remote_retrieve_response_code($res);
    $data = json_decode(wp_remote_retrieve_body($res), true);
    if ($code >= 200 && $code < 300) return [true, $data ?: []];
    return [false, is_array($data) ? $data : ['message'=>'HTTP '.$code]];
}

// High-level ops
function nm_api_create_ticket($payload){
    $tok = nm_get_current_glpi_user_token();
    return nm_glpi_request('POST', 'Ticket', $payload, $tok);
}
function nm_api_add_followup($ticket_id, $payload){
    $tok = nm_get_current_glpi_user_token();
    return nm_glpi_request('POST', 'Ticket/'.$ticket_id.'/Followup', $payload, $tok);
}
function nm_api_assign_user($ticket_id, $users_id){
    $tok = nm_get_current_glpi_user_token();
    return nm_glpi_request('POST', 'Ticket/'.$ticket_id.'/Ticket_User', ['users_id'=>$users_id,'type'=>2], $tok);
}
function nm_api_update_ticket($ticket_id, $payload){
    $tok = nm_get_current_glpi_user_token();
    return nm_glpi_request('PUT', 'Ticket/'.$ticket_id, $payload, $tok);
}


