<?php
if (!defined('ABSPATH')) exit;

function nt_get_current_glpi_uid() {
    $u = wp_get_current_user();
    if (!$u || !isset($u->ID) || !$u->ID) {
        return 0;
    }
    return (int) get_user_meta($u->ID, 'glpi_user_id', true);
}

function nt_require_glpi_user() {
    if (!is_user_logged_in()) {
        nt_response_error('auth', 'Auth required');
    }
    $id = nt_get_current_glpi_uid();
    if ($id <= 0) {
        nt_response_error('no_glpi_id', 'У пользователя не задан GLPI ID');
    }
    return $id;
}
