<?php
if (!defined('ABSPATH')) exit;

/**
 * Retrieve GLPI user identifier from WordPress user meta.
 *
 * Historically some integrations stored the identifier in various formats
 * (hashed strings, empty values, etc.).  The plugin expects a positive
 * integer and falls back to `0` when the meta value does not represent a
 * valid mapping (which later results in a `not_mapped` error code). A small
 * in‑memory cache is used to avoid repeated lookups for the same user during a
 * single request.
 *
 * @param int $wp_user_id WordPress user identifier.
 * @return int GLPI users.id or 0 when not mapped.
 */
function gexe_get_glpi_user_id($wp_user_id) {
    static $cache = [];

    $wp_user_id = (int) $wp_user_id;
    if ($wp_user_id <= 0) {
        return 0;
    }

    if (isset($cache[$wp_user_id])) {
        return $cache[$wp_user_id];
    }

    // Read raw meta, cast to integer and ensure positivity.
    $raw = get_user_meta($wp_user_id, 'glpi_user_id', true);
    $id  = (int) $raw;
    $id  = $id > 0 ? $id : 0;

    $cache[$wp_user_id] = $id;
    return $id;
}

/**
 * Resolve GLPI mapping for a given WordPress user.
 *
 * Returns a structured array so callers can easily branch on the
 * `not_mapped` condition without replicating the lookup logic.
 *
 * @param int $wp_user_id
 * @return array{ok:bool,id?:int,code?:string}
 */
function gexe_require_glpi_user($wp_user_id) {
    $id = gexe_get_glpi_user_id($wp_user_id);
    if ($id > 0) {
        return ['ok' => true, 'id' => $id];
    }
    return ['ok' => false, 'code' => 'not_mapped'];
}
