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
