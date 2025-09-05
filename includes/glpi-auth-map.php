<?php
/**
 * Map WordPress users to GLPI user identifiers.
 *
 * The mapping is stored in user meta `glpi_user_id`. The function caches
 * results in-memory to avoid repeated lookups during a single request.
 */
function get_mapped_glpi_user_id($wp_user_id) {
    static $cache = [];
    $wp_user_id = (int) $wp_user_id;
    if ($wp_user_id <= 0) {
        return null;
    }
    if (array_key_exists($wp_user_id, $cache)) {
        return $cache[$wp_user_id];
    }
    $mapped = get_user_meta($wp_user_id, 'glpi_user_id', true);
    $mapped = $mapped !== '' ? (int) $mapped : null;
    $cache[$wp_user_id] = $mapped > 0 ? $mapped : null;
    return $cache[$wp_user_id];
}
