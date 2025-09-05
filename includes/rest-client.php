<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/logger.php';

/**
 * Simple REST client helper for GLPI requests.
 * Provides basic logging so API calls can be traced in the actions log.
 */
function gexe_glpi_rest_request($method, $url, $args = []) {
    $defaults = [
        'method'  => strtoupper($method),
        'timeout' => 15,
    ];
    $response = wp_remote_request($url, array_merge($defaults, $args));
    if (is_wp_error($response)) {
        gexe_log_action('[rest-error] ' . $response->get_error_message());
        return $response;
    }
    $code = wp_remote_retrieve_response_code($response);
    gexe_log_action('[rest-request] ' . strtoupper($method) . ' ' . $url . ' ' . $code);
    return $response;
}

/**
 * Submit a comment to the GLPI API while logging the attempt.
 *
 * @param string $url  Endpoint URL.
 * @param array  $data Comment payload.
 * @return array|WP_Error Response object or WP_Error on failure.
 */
function gexe_glpi_submit_comment($url, $data) {
    gexe_log_action('[pending-comment] ' . $url);
    $args = [
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => wp_json_encode($data),
    ];
    $resp = gexe_glpi_rest_request('POST', $url, $args);
    if (!is_wp_error($resp)) {
        $code = wp_remote_retrieve_response_code($resp);
        gexe_log_action('[pending-comment-response] ' . $code);
    }
    return $resp;
}
