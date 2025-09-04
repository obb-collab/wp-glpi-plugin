<?php
/**
 * Shared GLPI utility functions.
 */

/**
 * Returns GLPI users.id associated with the current WP user.
 */
function gexe_get_current_glpi_uid() {
    if (!is_user_logged_in()) return 0;

    $wp_uid = get_current_user_id();

    $glpi_uid = intval(get_user_meta($wp_uid, 'glpi_user_id', true));
    if ($glpi_uid > 0) return $glpi_uid;

    $key = get_user_meta($wp_uid, 'glpi_user_key', true);
    if (preg_match('~^\d+$~', (string)$key)) {
        return (int)$key;
    }
    return 0;
}
