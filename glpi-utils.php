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

/**
 * Obtain connection to GLPI database.
 *
 * @param string $mode 'ro' for read-only replica, anything else for master
 * @return wpdb
 */
function gexe_glpi_db($mode = 'rw') {
    static $rw = null;
    static $ro = null;

    if ($mode === 'ro') {
        if ($ro instanceof wpdb) {
            return $ro;
        }
        // Local replica for read operations
        $ro = new wpdb(
            'wp_glpi',            // db user
            'xapetVD4OWZqw8f',    // db password
            'glpi',               // db name
            '127.0.0.1'           // local replica host
        );
        return $ro;
    }

    if ($rw instanceof wpdb) {
        return $rw;
    }

    // Master server for write operations
    $rw = new wpdb(
        'wp_glpi',            // db user
        'xapetVD4OWZqw8f',    // db password
        'glpi',               // db name
        '192.168.100.12'      // master host
    );
    return $rw;
}
