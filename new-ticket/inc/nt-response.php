<?php
if (!defined('ABSPATH')) exit;

function nt_response($data) {
    wp_send_json($data);
}

function nt_response_error($code, $message = '', $extra = []) {
    nt_response(array_merge(['ok' => false, 'code' => $code, 'message' => $message], $extra));
}
