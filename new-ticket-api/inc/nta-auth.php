<?php
if (!defined('ABSPATH')) exit;

/**
 * Try to resolve GLPI User Token using several sources:
 *  1) usermeta 'glpi_user_token'
 *  2) project function gexe_glpi_get_current_user_token() (основной путь в вашем проекте)
 *  3) external integration via filter 'nt_glpi_user_token'
 *  4) optional constants/arrays GEXE_GLPI_USER_TOKENS
 */
function nta_resolve_glpi_token($wp_user, $glpi_uid) {
    // 2) основной путь: встроенная в проект функция (реестр токенов)
    if (function_exists('gexe_glpi_get_current_user_token')) {
        $t = (string) gexe_glpi_get_current_user_token();
        if ($t !== '') return trim($t);
    }
    // 1) usermeta
    $tok = (string) get_user_meta($wp_user->ID, 'glpi_user_token', true);
    $tok = trim($tok);
    if ($tok !== '') {
        return $tok;
    }

    // 3) allow integrators to provide token
    $filtered = apply_filters('nt_glpi_user_token', '', $wp_user, (int)$glpi_uid);
    if (is_string($filtered) && $filtered !== '') {
        return trim($filtered);
    }

    // 4) array constant map
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
    // В проекте уже есть готовая функция, используем её при наличии.
    if (function_exists('gexe_get_current_glpi_uid')) {
        $id = (int) gexe_get_current_glpi_uid();
        if ($id > 0) return $id;
    }
    // Фолбэк: читаем из usermeta
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
        // Подсказка интеграторам, куда подключиться
        nta_response_error(
            'no_glpi_token',
            'У пользователя не найден GLPI user token. В проекте обычно используется gexe_glpi_get_current_user_token(); ' .
            'также можно задать usermeta glpi_user_token, вернуть через фильтр nt_glpi_user_token, ' .
            'или добавить в GEXE_GLPI_USER_TOKENS (login/email/wp:id/glpi:id).'
        );
    }
    return [$uid, $tok];
}
