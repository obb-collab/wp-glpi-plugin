<?php
if (!defined('ABSPATH')) exit;

function nta_get_current_glpi_uid() {
    $u = wp_get_current_user();
    if (!$u || empty($u->ID)) return 0;
    return (int) get_user_meta($u->ID, 'glpi_user_id', true);
}

function nta_get_current_glpi_token() {
    $u = wp_get_current_user();
    if (!$u || empty($u->ID)) return '';
    $tok = (string) get_user_meta($u->ID, 'glpi_user_token', true);
    return trim($tok);
}

function nta_require_glpi_user_and_token() {
    if (!is_user_logged_in()) {
        nta_response_error('auth', 'Auth required');
    }
    $uid = nta_get_current_glpi_uid();
    if ($uid <= 0) {
        nta_response_error('no_glpi_id', 'У пользователя не задан GLPI ID');
    }
    $tok = nta_get_current_glpi_token();
    if ($tok === '') {
        nta_response_error('no_glpi_token', 'У пользователя не задан GLPI user token');
    }
    return [$uid, $tok];
}
