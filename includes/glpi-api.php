<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/rest-client.php';
require_once __DIR__ . '/../includes/bootstrap/db-setup.php';

/**
 * Retrieve cached GLPI session token or initialize a new session.
 *
 * @param bool $force Whether to force re-initialization.
 * @return string|WP_Error Session token on success or WP_Error on failure.
 */
function gexe_glpi_api_get_session_token($force = false) {
    static $memory_token = null;

    if (!$force && !empty($memory_token)) {
        return $memory_token;
    }

    if (!$force) {
        $cached = get_transient('gexe_glpi_session_token');
        if (!empty($cached)) {
            $memory_token = $cached;
            return $memory_token;
        }
    }

    $init = gexe_glpi_api_init_session();
    if (is_wp_error($init)) {
        return $init;
    }

    $memory_token = $init;
    set_transient('gexe_glpi_session_token', $memory_token, 12 * MINUTE_IN_SECONDS);
    return $memory_token;
}

/**
 * Initialize GLPI API session and return Session-Token.
 *
 * @return string|WP_Error Session token on success or WP_Error on failure.
 */
function gexe_glpi_api_init_session() {
    $url = rtrim(gexe_glpi_api_url(), '/') . '/initSession';
    $headers = gexe_glpi_api_headers();

    $response = gexe_glpi_rest_request('GET', $url, [
        'headers' => $headers,
        'timeout' => 10,
    ]);
    if (is_wp_error($response)) {
        return new WP_Error('api_unreachable', $response->get_error_message());
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if ($code === 401 || $code === 403) {
        return new WP_Error('api_auth', 'Unauthorized');
    }
    if ($code >= 400) {
        return new WP_Error('api_unreachable', 'HTTP ' . $code);
    }
    if (empty($body['session_token'])) {
        return new WP_Error('api_unreachable', 'No session token');
    }

    return (string) $body['session_token'];
}

/**
 * Perform a request to the GLPI REST API.
 *
 * @param string     $method  HTTP method.
 * @param string     $path    API path starting with '/'.
 * @param array|null $payload Optional JSON payload.
 * @param bool       $retry   Internal flag for retry after session renewal.
 * @return array|WP_Error {code:int, body:array, raw:array} or WP_Error on failure.
 */
function gexe_glpi_api_request($method, $path, $payload = null, $retry = true) {
    $url = rtrim(gexe_glpi_api_url(), '/') . '/' . ltrim($path, '/');
    $args = [
        'timeout' => 10,
    ];

    $is_init = (strpos($path, 'initSession') !== false);

    if ($is_init) {
        $args['headers'] = gexe_glpi_api_headers();
    } else {
        $token = gexe_glpi_api_get_session_token();
        if (is_wp_error($token)) {
            return $token;
        }
        $args['headers'] = [
            'Content-Type' => 'application/json',
            'App-Token'    => GEXE_GLPI_APP_TOKEN,
            'Session-Token'=> $token,
        ];
    }

    if (null !== $payload) {
        $args['body'] = wp_json_encode($payload);
    }

    $response = gexe_glpi_rest_request($method, $url, $args);
    if (is_wp_error($response)) {
        return new WP_Error('api_unreachable', $response->get_error_message());
    }

    $code = (int) wp_remote_retrieve_response_code($response);

    // Retry once if session expired.
    if (($code === 401 || $code === 403) && !$is_init && $retry) {
        gexe_glpi_api_get_session_token(true);
        return gexe_glpi_api_request($method, $path, $payload, false);
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    return [
        'code' => $code,
        'body' => $body,
        'raw'  => $response,
    ];
}

