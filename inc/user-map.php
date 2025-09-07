<?php
if (!defined('ABSPATH')) exit;

/**
 * Retrieve GLPI user identifier from WordPress user meta.
 *
 * @param int $wp_user_id WordPress user identifier.
 * @return int GLPI users.id or 0 when not mapped.
 */
function gexe_get_glpi_user_id($wp_user_id) {
    $wp_user_id = (int) $wp_user_id;
    if ($wp_user_id <= 0) {
        return 0;
    }
    $id = (int) get_user_meta($wp_user_id, 'glpi_user_id', true);
    return $id > 0 ? $id : 0;
}
