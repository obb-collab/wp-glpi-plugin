<?php
/**
 * Shared GLPI utility functions.
 */

require_once __DIR__ . '/includes/logger.php';

/**
 * Determine GLPI user ID for a WordPress user based on stored meta.
 *
 * The primary source of mapping is the `glpi_user_key` user meta which may
 * contain either a numeric `users_id` or an MD5 hash composed from the user's
 * realname and firstname (as used by GLPI 9.5.x). When the resulting identifier
 * is numeric it is cached in `glpi_user_id` for faster subsequent lookups.
 *
 * @param int $wp_user_id WordPress user identifier.
 * @return int GLPI `users.id` or 0 when not found.
 */
function gexe_get_current_glpi_user_id($wp_user_id) {
    $wp_user_id = (int) $wp_user_id;
    if ($wp_user_id <= 0) {
        return 0;
    }

    $key   = get_user_meta($wp_user_id, 'glpi_user_key', true);
    $key   = is_string($key) ? trim($key) : '';
    $glpi  = 0;

    if (preg_match('/^\d+$/', $key)) {
        // Direct numeric mapping
        $glpi = (int) $key;
    } elseif (preg_match('/^[a-f0-9]{32}$/i', $key)) {
        // MD5 hash -> lookup in glpi_users
        global $glpi_db;
        if ($glpi_db instanceof wpdb) {
            $sql = $glpi_db->prepare(
                "SELECT id FROM glpi_users WHERE MD5(CONCAT(realname,' ',LEFT(firstname,1),'.')) = %s LIMIT 1",
                $key
            );
            $glpi = (int) $glpi_db->get_var($sql);
        }
    }

    if ($glpi > 0) {
        // Cache numeric identifiers for faster future lookups
        update_user_meta($wp_user_id, 'glpi_user_id', $glpi);
        return $glpi;
    }

    return 0;
}

/**
 * Returns GLPI users.id associated with the current logged-in WP user.
 *
 * @return int
 */
function gexe_get_current_glpi_uid() {
    if (!is_user_logged_in()) {
        return 0;
    }

    return gexe_get_current_glpi_user_id(get_current_user_id());
}

/**
 * Stub for legacy logging – no-op.
 */
function gexe_glpi_log($action, $url, $response, $start_time) {
    // Logging disabled
}

/**
 * Send unified AJAX success response.
 */
function gexe_ajax_success(array $data = [], $status = 200) {
    wp_send_json([
        'success' => true,
        'data'    => $data,
    ], $status);
}

/**
 * Send unified AJAX error response.
 */
function gexe_ajax_error($code, $message, $status = 400, $details = null) {
    $error = [
        'code'    => (string) $code,
        'message' => (string) $message,
    ];
    if ($details !== null) {
        $error['details'] = $details;
    }
    wp_send_json([
        'success' => false,
        'error'   => $error,
    ], $status);
}

/**
 * Determine base URL of GLPI web interface for building document links.
 *
 * When the `glpi_web_base` option is not set, attempts to derive it from
 * `glpi_api_base` by stripping a trailing `/apirest.php` segment.
 *
 * @return string Base URL without trailing slash.
 */
function gexe_glpi_web_base() {
    $base = trim((string) get_option('glpi_web_base', ''));
    if ($base === '') {
        $api = trim((string) get_option('glpi_api_base', ''));
        if ($api !== '') {
            $base = preg_replace('~/apirest\.php$~', '', $api);
        }
    }
    return rtrim($base, '/');
}

/**
 * Normalize a date/time string to ISO-8601 with timezone.
 *
 * Returns null when the value is empty, equals "0000-00-00 00:00:00" or
 * cannot be parsed by DateTime.
 *
 * @param string $raw Original date/time string.
 * @return string|null ISO-8601 formatted string or null on failure.
 */
function gexe_iso_datetime($raw) {
    $raw = trim((string) $raw);
    if ($raw === '' || $raw === '0000-00-00 00:00:00') {
        return null;
    }
    try {
        $dt = new DateTime($raw);
        return $dt->format('c');
    } catch (Exception $e) {
        return null;
    }
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

/**
 * Insert ITIL followup directly into GLPI via SQL.
 */
function gexe_add_followup_sql($ticket_id, $content, $glpi_user_id_override = null) {
    global $glpi_db;

    $start = microtime(true);

    $ticket_id = (int)$ticket_id;
    $content   = trim((string)$content);

    if ($ticket_id <= 0 || $content === '') {
        return ['ok' => false, 'code' => 'VALIDATION', 'message' => 'Bad ticket or content'];
    }
    if (mb_strlen($content) > 65535) {
        $content = mb_substr($content, 0, 65535);
    }

    // Determine author
    $author_id = (int)$glpi_user_id_override;
    $assignees = $glpi_db->get_col(
        $glpi_db->prepare(
            "SELECT users_id FROM glpi_tickets_users WHERE tickets_id = %d AND type = 2 ORDER BY id DESC",
            $ticket_id
        )
    );
    if ($author_id <= 0) {
        // Prefer the current mapped GLPI user even if they are not yet assigned
        // to the ticket. This allows operators with global permissions to
        // comment on unassigned tickets. Fall back to the latest assignee if
        // present.
        $current_glpi = gexe_get_current_glpi_uid();
        if ($current_glpi > 0) {
            $author_id = $current_glpi;
        } elseif (!empty($assignees)) {
            $author_id = (int) $assignees[0];
        }
    }
    if ($author_id <= 0) {
        return ['ok' => false, 'code' => 'ASSIGNEE_NOT_FOUND', 'message' => 'No assignee'];
    }

    // Ticket entity
    $entities_id = (int) $glpi_db->get_var(
        $glpi_db->prepare('SELECT entities_id FROM glpi_tickets WHERE id = %d', $ticket_id)
    );
    if ($entities_id <= 0) {
        return ['ok' => false, 'code' => 'VALIDATION', 'message' => 'Ticket not found'];
    }

    // Optional columns
    $has_priv   = gexe_glpi_has_column('glpi_itilfollowups', 'is_private');
    $has_req    = gexe_glpi_has_column('glpi_itilfollowups', 'requesttypes_id');
    $has_edit   = gexe_glpi_has_column('glpi_itilfollowups', 'users_id_editor');
    $has_mod    = gexe_glpi_has_column('glpi_itilfollowups', 'date_mod');
    $has_tpos   = gexe_glpi_has_column('glpi_itilfollowups', 'timeline_position');

    $columns = ['entities_id', 'itemtype', 'items_id', 'users_id'];
    $place   = ['%d', '%s', '%d', '%d'];
    $values  = [$entities_id, 'Ticket', $ticket_id, $author_id];
    if ($has_edit) {
        $columns[] = 'users_id_editor';
        $place[]   = '%d';
        $values[]  = $author_id;
    }
    $columns[] = 'content';
    $place[]   = '%s';
    $values[]  = $content;
    if ($has_priv) {
        $columns[] = 'is_private';
        $place[]   = '%d';
        $values[]  = 0;
    }
    if ($has_req) {
        $columns[] = 'requesttypes_id';
        $place[]   = '%d';
        $values[]  = 0;
    }
    if ($has_tpos) {
        $columns[] = 'timeline_position';
        $place[]   = '%d';
        $values[]  = 0;
    }
    $columns[] = 'date';
    $place[]   = 'NOW()';
    if ($has_mod) {
        $columns[] = 'date_mod';
        $place[]   = 'NOW()';
    }

    $sql_tmpl = 'INSERT INTO `glpi`.`glpi_itilfollowups` (' . implode(',', $columns) . ') VALUES (' . implode(',', $place) . ')';
    $sql = $glpi_db->prepare($sql_tmpl, $values);

    $glpi_db->query('START TRANSACTION');
    $ok = $glpi_db->query($sql);
    if (!$ok) {
        $err = $glpi_db->last_error;
        $glpi_db->query('ROLLBACK');
        $elapsed_ms = (int) round((microtime(true) - $start) * 1000);
        gexe_log_action(sprintf(
            '[comment.sql] ticket=%d author=%d followup=0 elapsed=%dms result=fail err="%s" sql="%s"',
            $ticket_id,
            $author_id,
            $elapsed_ms,
            $err,
            $sql_tmpl
        ));
        return ['ok' => false, 'code' => 'SQL_ERROR', 'message' => $err];
    }
    $fid = (int) $glpi_db->insert_id;
    $glpi_db->query('COMMIT');
    $elapsed_ms = (int) round((microtime(true) - $start) * 1000);
    gexe_log_action(sprintf(
        '[comment.sql] ticket=%d author=%d followup=%d elapsed=%dms result=ok',
        $ticket_id,
        $author_id,
        $fid,
        $elapsed_ms
    ));
    return ['ok' => true, 'followup_id' => $fid, 'users_id' => $author_id, 'ticket_id' => $ticket_id];
}

