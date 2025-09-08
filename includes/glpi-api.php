<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/rest-client.php';
require_once __DIR__ . '/../glpi-db-setup.php';

/**
 * Perform a request to the GLPI REST API.
 *
 * @param string     $method  HTTP method.
 * @param string     $path    API path starting with '/'.
 * @param array|null $payload Optional JSON payload.
 * @return array|WP_Error {code:int, body:array, raw:array} or WP_Error on failure.
 */
function gexe_glpi_api_request($method, $path, $payload = null) {
    $url = rtrim(gexe_glpi_api_url(), '/') . '/' . ltrim($path, '/');
    $headers = gexe_glpi_api_headers();
    $args = [
        'headers' => $headers,
        'timeout' => 15,
    ];
    if (null !== $payload) {
        $args['body'] = wp_json_encode($payload);
    }
    $response = gexe_glpi_rest_request($method, $url, $args);
    if (is_wp_error($response)) {
        return $response;
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);
    return [
        'code' => $code,
        'body' => $body,
        'raw'  => $response,
    ];
}
