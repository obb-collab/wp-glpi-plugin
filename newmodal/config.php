<?php
if (!defined('ABSPATH')) { exit; }

// Version for cache-busting
if (!defined('NM_VER')) {
    define('NM_VER', '1.1.0');
}

// Base paths
if (!defined('NM_BASE_DIR')) {
    define('NM_BASE_DIR', plugin_dir_path(__FILE__));
}
if (!defined('NM_BASE_URL')) {
    define('NM_BASE_URL', plugin_dir_url(__FILE__));
}

// GLPI REST API base (adjust if needed)
if (!defined('NM_GLPI_API_BASE')) {
    // Example: http://192.168.100.12/glpi/apirest.php
    define('NM_GLPI_API_BASE', 'http://192.168.100.12/glpi/apirest.php');
}

// Toggle: enforce SQL reads / API writes
if (!defined('NM_READS_VIA_SQL')) {
    define('NM_READS_VIA_SQL', true);
}
if (!defined('NM_WRITES_VIA_API')) {
    define('NM_WRITES_VIA_API', true);
}
