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
 * Log GLPI REST actions into uploads/glpi-plugin/logs/actions.log.
 */
function gexe_glpi_log($action, $url, $response, $start_time) {
    $uploads = wp_upload_dir();
    $dir     = trailingslashit($uploads['basedir']) . 'glpi-plugin/logs';
    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
    }

    $elapsed = (int) round((microtime(true) - $start_time) * 1000);
    if (is_wp_error($response)) {
        $line = sprintf(
            "%s\t%s\t0\t%dms\t%s\n",
            $action,
            $url,
            $elapsed,
            $response->get_error_message()
        );
    } else {
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $short = mb_substr(trim($body), 0, 200);
        $line = sprintf(
            "%s\t%s\t%d\t%dms\t%s\n",
            $action,
            $url,
            $code,
            $elapsed,
            $short
        );
        if ($code >= 400) {
            $line .= $body . "\n"; // full body for errors
        }
    }

    file_put_contents($dir . '/actions.log', $line, FILE_APPEND);
}

/**
 * Check if a column exists in a GLPI table.
 * Result is cached in-memory and via WordPress transient for 1 hour.
 */
function gexe_glpi_has_column($table, $column) {
    static $cache = [];
    $table  = sanitize_key($table);
    $column = sanitize_key($column);

    if (isset($cache[$table][$column])) {
        return $cache[$table][$column];
    }

    $tkey = 'glpi_schema_cols_' . $table;
    $stored = get_transient($tkey);
    if (!is_array($stored)) {
        $stored = [];
    }

    if (array_key_exists($column, $stored)) {
        $cache[$table][$column] = (bool) $stored[$column];
        return $cache[$table][$column];
    }

    global $glpi_db;
    if (!($glpi_db instanceof wpdb)) {
        $cache[$table][$column] = false;
        return false;
    }

    $sql  = $glpi_db->prepare("SHOW COLUMNS FROM `glpi`.`$table` LIKE %s", $column);
    $row  = $glpi_db->get_row($sql, ARRAY_A);
    $has  = !empty($row);

    $stored[$column] = $has;
    set_transient($tkey, $stored, HOUR_IN_SECONDS);
    $cache[$table][$column] = $has;
    return $has;
}

/**
 * Initialize GLPI session for write requests.
 */
function gexe_glpi_init_session() {
    $url  = gexe_glpi_api_url() . '/initSession?get_full_session=true&session_write=true';
    $args = [
        'timeout' => 10,
        'headers' => gexe_glpi_api_headers(),
    ];
    $t0   = microtime(true);
    $resp = wp_remote_get($url, $args);
    gexe_glpi_log('initSession', $url, $resp, $t0);
    if (is_wp_error($resp)) return $resp;
    $code = wp_remote_retrieve_response_code($resp);
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code >= 300 || !is_array($body) || empty($body['session_token'])) {
        return new WP_Error('glpi_session', 'No session token');
    }
    return $body['session_token'];
}

/**
 * Low level GLPI API request with a given Session-Token.
 */
function gexe_glpi_request_with_token($action, $method, $endpoint, $body, $session_token) {
    $url = gexe_glpi_api_url() . $endpoint;
    $sep = strpos($url, '?') === false ? '?' : '&';
    $url .= $sep . 'session_write=true';

    $args = [
        'method'  => $method,
        'timeout' => 10,
        'headers' => gexe_glpi_api_headers([
            'Session-Token' => $session_token,
        ]),
    ];
    if (null !== $body) {
        $args['body'] = wp_json_encode($body);
    }

    $t0   = microtime(true);
    $resp = wp_remote_request($url, $args);
    gexe_glpi_log($action, $url, $resp, $t0);
    return $resp;
}

/**
 * Detect expired or missing session token errors.
 */
function gexe_glpi_session_error($response) {
    if (is_wp_error($response)) return false;
    $code = wp_remote_retrieve_response_code($response);
    if ($code === 401) return true;
    $body = wp_remote_retrieve_body($response);
    return stripos($body, 'ERROR_SESSION_TOKEN') !== false;
}

/**
 * Perform GLPI write request with automatic session handling.
 */
function gexe_glpi_rest_request($action, $method, $endpoint, $body = null) {
    $token = gexe_glpi_init_session();
    if (is_wp_error($token)) return $token;
    $resp = gexe_glpi_request_with_token($action, $method, $endpoint, $body, $token);
    if (gexe_glpi_session_error($resp)) {
        $token = gexe_glpi_init_session();
        if (is_wp_error($token)) return $token;
        $resp = gexe_glpi_request_with_token($action, $method, $endpoint, $body, $token);
    }
    return $resp;
}

