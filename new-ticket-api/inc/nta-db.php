<?php
if (!defined('ABSPATH')) exit;

function nta_db() {
    static $db = null;
    if ($db === null) {
        // GLPI DB connection for fast dictionaries and anti-duplicate
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

function nta_db_last_error(){
    $e = nta_db()->last_error;
    return is_string($e) ? $e : '';
}
