<?php
if (!defined('ABSPATH')) exit;

/**
 * Try to resolve GLPI User Token using several sources:
 *  1) usermeta 'glpi_user_token'
 *  2) external integration via filter 'nt_glpi_user_token' (return non-empty string to use it)
 *  3) existing project glue (optional, non-fatal):
 *     - function glpi_get_user_token( $wp_user_id, $glpi_user_id ) or gexe_get_glpi_user_token(...)
 *     - constant/array GEXE_GLPI_USER_TOKENS keyed by login/email/user_id/glpi_id
 */
function nta_resolve_glpi_token($wp_user, $glpi_uid) {
    // 1) usermeta
    $tok = (string) get_user_meta($wp_user->ID, 'glpi_user_token', true);
    $tok = trim($tok);
    if ($tok !== '') {
        return $tok;
    }

    // 2) allow integrators to provide token
    $filtered = apply_filters('nt_glpi_user_token', '', $wp_user, (int)$glpi_uid);
    if (is_string($filtered) && $filtered !== '') {
        return trim($filtered);
    }

    // 3) optional: use existing project helpers if present (keeps isolation; no hard require)
    // 3.1 functions
    if (function_exists('glpi_get_user_token')) {
        $t = (string) glpi_get_user_token($wp_user->ID, (int)$glpi_uid);
        if ($t !== '') return trim($t);
    }
    if (function_exists('gexe_get_glpi_user_token')) {
        $t = (string) gexe_get_glpi_user_token($wp_user->ID, (int)$glpi_uid);
        if ($t !== '') return trim($t);
    }
    // 3.2 array constant map
    if (defined('GEXE_GLPI_USER_TOKENS') && is_array(GEXE_GLPI_USER_TOKENS)) {
        $map = GEXE_GLPI_USER_TOKENS;
        $candidates = [];
        // common keys that might be used in the project
        $candidates[] = (string)$wp_user->user_login;
        $candidates[] = (string)$wp_user->user_email;
        $candidates[] = 'wp:' . (int)$wp_user->ID;
        $candidates[] = 'glpi:' . (int)$glpi_uid;
        foreach ($candidates as $k) {
            if (isset($map[$k]) && is_string($map[$k]) && trim($map[$k]) !== '') {
                return trim($map[$k]);
            }
        }
    }
    return '';
}

function nta_get_current_glpi_uid() {
    $u = wp_get_current_user();
    if (!$u || empty($u->ID)) return 0;
    return (int) get_user_meta($u->ID, 'glpi_user_id', true);
}

function nta_get_current_glpi_token() {
    $u = wp_get_current_user();
    if (!$u || empty($u->ID)) return '';
    $glpi_uid = nta_get_current_glpi_uid();
    return nta_resolve_glpi_token($u, $glpi_uid);
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
        // Give integrators a hint how to wire the token
        nta_response_error(
            'no_glpi_token',
            'У пользователя не задан GLPI user token. Задайте usermeta glpi_user_token, ' .
            'или верните токен фильтром nt_glpi_user_token, или добавьте в GEXE_GLPI_USER_TOKENS (login/email/wp:id/glpi:id).'
        );
    }
    return [$uid, $tok];
}
