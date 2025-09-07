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
    $raw = function_exists('get_user_meta') ? get_user_meta($wp_user_id, 'glpi_user_id', true) : 0;
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

/**
 * Return GLPI user id associated with the current WordPress user.
 *
 * Read-only helper. Falls back to `0` when the mapping is missing or invalid.
 * The function is intentionally defensive: it performs capability checks and
 * avoids throwing exceptions so template code can safely call it without
 * additional guards.
 *
 * @return int GLPI users.id or 0 when not mapped or user not logged in.
 */
if (!function_exists('glpi_current_user_id')) {
    function glpi_current_user_id() {
        if (!function_exists('is_user_logged_in') || !is_user_logged_in()) {
            return 0;
        }
        if (!function_exists('get_current_user_id')) {
            return 0;
        }
        try {
            $gid = gexe_get_glpi_user_id(get_current_user_id());
            return ($gid > 0) ? $gid : 0;
        } catch (Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('glpi_current_user_id: ' . $e->getMessage());
            }
            return 0;
        }
    }
}

/**
 * Fetch list of executors from WordPress users mapped to GLPI.
 *
 * Returns a unified JSON-style response used by AJAX handlers. Each list
 * item contains the WordPress user identifier (`wp_id`), display name and the
 * mapped GLPI `users_id`.
 *
 * Historically this helper was named `gexe_get_executors_list()`.  The new
 * modal expects `gexe_get_executors_wp()` so we keep the old name as an alias
 * below for backwards compatibility.
 *
 * @return array{ok:bool,code:string,which?:string,list?:array}
 */
function gexe_get_executors_wp() {
    global $wpdb;

    $sql = "SELECT u.ID AS wp_id, u.display_name, "
         . "CAST(um.meta_value AS UNSIGNED) AS glpi_user_id "
         . "FROM {$wpdb->users} u "
         . "JOIN {$wpdb->usermeta} um ON um.user_id=u.ID AND um.meta_key='glpi_user_id' "
         . "WHERE CAST(um.meta_value AS UNSIGNED) > 0 "
         . "ORDER BY u.display_name";

    $rows = $wpdb->get_results($sql, ARRAY_A);
    if ($wpdb->last_error) {
        return ['ok' => false, 'code' => 'dict_failed', 'which' => 'executors'];
    }
    if (!$rows) {
        return ['ok' => false, 'code' => 'dict_empty', 'which' => 'executors'];
    }

    $list = array_map(function ($r) {
        return [
            'wp_id'        => (int) $r['wp_id'],
            'display_name' => $r['display_name'],
            'glpi_user_id' => (int) $r['glpi_user_id'],
        ];
    }, $rows);

    return ['ok' => true, 'code' => 'ok', 'list' => $list];
}

// Old name kept for compatibility with legacy code paths.
if (!function_exists('gexe_get_executors_list')) {
    function gexe_get_executors_list() {
        return gexe_get_executors_wp();
    }
}
