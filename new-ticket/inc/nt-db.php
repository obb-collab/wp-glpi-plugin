<?php
if (!defined('ABSPATH')) exit;

function nt_db() {
    static $db = null;
    if ($db === null) {
        // Connect directly to GLPI database (isolated from WP DB)
        // Adjust if your GLPI DB settings differ.
        $glpi_user = 'wp_glpi';
        $glpi_pass = 'xapetVD4OWZqw8f';
        $glpi_name = 'glpi';
        $glpi_host = '192.168.100.12';

        require_once ABSPATH . 'wp-includes/wp-db.php';
        $db = new wpdb($glpi_user, $glpi_pass, $glpi_name, $glpi_host);
        $charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';
        $db->set_charset($db->dbh, $charset);
    }
    return $db;
}

function nt_db_begin() {
    nt_db()->query('START TRANSACTION');
}

function nt_db_commit() {
    nt_db()->query('COMMIT');
}

function nt_db_rollback() {
    nt_db()->query('ROLLBACK');
}

// Simple helper for escaping error messages into responses if needed
function nt_db_last_error(){
    $e = nt_db()->last_error;
    return is_string($e) ? $e : '';
}
