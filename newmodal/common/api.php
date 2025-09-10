<?php
/**
 * Newmodal common API helpers (safe baseline).
 *
 * This file intentionally contains only defensive helpers and constants,
 * without any backticks or ambiguous syntax that could break PHP parsing.
 * Keep dependencies minimal: pure PHP + WordPress core functions.
 *
 * @package wp-glpi-plugin/newmodal
 */

if (!defined('ABSPATH')) {
    exit;
}

// Prevent double inclusion.
if (defined('NM_COMMON_API_LOADED')) {
    return;
}
define('NM_COMMON_API_LOADED', true);

/**
 * Returns standardized JSON to AJAX caller and exits.
 *
 * @param array $payload
 * @param int   $code
 */
function nm_api_send_json(array $payload, $code = 200) {
    // Do not echo notices/warnings to screen.
    if (!headers_sent()) {
        status_header((int)$code);
        header('Content-Type: application/json; charset=' . get_option('blog_charset'));
        // Disable caching for AJAX.
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }
    echo wp_json_encode($payload);
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    wp_die();
}

/**
 * Success wrapper.
 *
 * @param mixed $data
 * @param array $extra
 */
function nm_api_ok($data = null, array $extra = []) {
    $resp = array_merge(
        [
            'ok'   => true,
            'data' => $data,
        ],
        $extra
    );
    nm_api_send_json($resp, 200);
}

/**
 * Error wrapper.
 *
 * @param string $message
 * @param int    $code
 * @param array  $extra
 */
function nm_api_error($message = 'Unexpected error', $code = 400, array $extra = []) {
    $resp = array_merge(
        [
            'ok'      => false,
            'message' => (string)$message,
            'code'    => (int)$code,
        ],
        $extra
    );
    nm_api_send_json($resp, $code);
}

/**
 * Validate WP nonce from request. Returns void on success (continues execution),
 * otherwise sends error JSON and exits.
 *
 * @param string $action
 * @param string $field
 */
function nm_api_require_nonce($action, $field = 'nonce') {
    $nonce = isset($_REQUEST[$field]) ? sanitize_text_field(wp_unslash($_REQUEST[$field])) : '';
    if (!$nonce || !wp_verify_nonce($nonce, $action)) {
        nm_api_error('Invalid or missing nonce', 403, ['where' => 'nonce']);
    }
}

/**
 * Ensure current user is logged in and (optionally) has capability.
 * On failure returns error JSON and exits.
 *
 * @param string|null $cap
 */
function nm_api_require_auth($cap = null) {
    if (!is_user_logged_in()) {
        nm_api_error('Auth required', 401);
    }
    if ($cap && !current_user_can($cap)) {
        nm_api_error('Insufficient privileges', 403, ['cap' => $cap]);
    }
}

/**
 * Read integer from request safely.
 *
 * @param string $key
 * @param int    $default
 * @return int
 */
function nm_req_int($key, $default = 0) {
    return isset($_REQUEST[$key]) ? (int) $_REQUEST[$key] : (int) $default;
}

/**
 * Read trimmed string from request safely.
 *
 * @param string $key
 * @param string $default
 * @return string
 */
function nm_req_str($key, $default = '') {
    if (!isset($_REQUEST[$key])) {
        return (string)$default;
    }
    return sanitize_text_field(wp_unslash((string)$_REQUEST[$key]));
}

/**
 * Simple guard ping (debug).
 */
function nm_api_ping() {
    nm_api_ok(['pong' => true, 'time' => time()]);
}

// End of file.
