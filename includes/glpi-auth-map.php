<?php
if (!defined('ABSPATH')) exit;

// file is in /includes; helpers in /includes/helpers
require_once __DIR__ . '/helpers/utils.php';

/**
 * Map WordPress users to GLPI user identifiers.
 *
 * Uses {@see gexe_get_current_glpi_user_id()} for the actual mapping and
 * caches results in-memory to avoid repeated lookups during a single request.
 */
function get_mapped_glpi_user_id($wp_user_id) {
    static $cache = [];
    $wp_user_id = (int) $wp_user_id;
    if ($wp_user_id <= 0) {
        return 0;
    }
    if (array_key_exists($wp_user_id, $cache)) {
        return $cache[$wp_user_id];
    }
    $mapped = gexe_get_current_glpi_user_id($wp_user_id);
    $cache[$wp_user_id] = $mapped > 0 ? $mapped : 0;
    return $cache[$wp_user_id];
}
