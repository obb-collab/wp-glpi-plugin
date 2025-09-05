<?php
if (!defined('ABSPATH')) exit;

/**
 * Simple file logger for GLPI actions.
 */
function gexe_log_action($message) {
    $upload = wp_upload_dir();
    $dir = trailingslashit($upload['basedir']) . 'glpi-plugin/logs';
    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
    }
    $file = $dir . '/actions.log';
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    file_put_contents($file, $line, FILE_APPEND);
}

// AJAX: log client-side errors into actions.log
add_action('wp_ajax_gexe_log_client_error', 'gexe_log_client_error');
function gexe_log_client_error() {
    if (!check_ajax_referer('gexe_form_data', 'nonce', false)) {
        wp_send_json_error(['code' => 'AJAX_FORBIDDEN'], 403);
    }
    $msg = isset($_POST['message']) ? sanitize_text_field(wp_unslash($_POST['message'])) : '';
    if ($msg !== '') {
        gexe_log_action('[client-error] ' . $msg);
    }
    wp_send_json_success();
}
