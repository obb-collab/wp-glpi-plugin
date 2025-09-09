<?php
if (!defined('ABSPATH')) exit;

function nta_response($data){ wp_send_json($data); }

function nta_response_error($code, $message = '', $extra = []) {
    nta_response(array_merge(['ok'=>false,'code'=>$code,'message'=>$message], $extra));
}

function nta_check_nonce_and_auth() {
    if (!check_ajax_referer('new_ticket_api_actions', 'nonce', false)) { nta_response_error('csrf','Invalid nonce'); }
}
