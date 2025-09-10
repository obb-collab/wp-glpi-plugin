<?php
// newmodal/common/ping.php
if (!defined('ABSPATH')) { exit; }

/**
 * Universal GLPI notification ping after SQL operations.
 * Strategy: call GLPI cron endpoint to trigger notifications queue.
 * Optionally, future: call specific REST endpoints with user token.
 */

function nm_glpi_base_url() {
    $url = rtrim((string)get_option('nm_glpi_base_url'), '/');
    return $url;
}

function nm_glpi_app_token() {
    return (string)get_option('nm_glpi_app_token');
}

/**
 * Trigger notifications by calling GLPI cron.
 * Retries synchronously up to $retries; avoid blocking UI too long.
 */
function nm_notify_ping_cron($retries = 1) {
    $base = nm_glpi_base_url();
    if (!$base) { return; }
    $url = $base . '/front/cron.php';
    $args = [
        'timeout' => 5,
        'headers' => [],
    ];
    for ($i=0; $i<$retries; $i++) {
        $res = wp_remote_get($url, $args);
        if (!is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200) {
            return;
        }
        // small backoff
        usleep(200000);
    }
}

/**
 * Public wrapper: call after successful SQL write.
 */
function nm_notify_after_write($ticket_id, $event = 'generic', $as_glpi_user_id = null) {
    // Future: use user_token if needed
    nm_notify_ping_cron(1);
}
