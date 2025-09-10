<?php
if (!defined('ABSPATH')) exit;

if (!defined('NM_META_APP_TOKEN')) define('NM_META_APP_TOKEN', 'glpi_app_token');
if (!defined('NM_META_USER_TOKEN')) define('NM_META_USER_TOKEN', 'glpi_user_token');
if (!defined('NM_DB_PREFIX')) define('NM_DB_PREFIX', 'glpi_');
if (!defined('NM_STATUS_SOLVED')) define('NM_STATUS_SOLVED', 6);
if (!defined('NM_OPT_BASE_URL')) define('NM_OPT_BASE_URL', 'glpi_api_base_url');

function nm_default_status_map() {
    return [
        '1' => 'New',
        '2' => 'Processing (assigned)',
        '3' => 'Processing (planned)',
        '4' => 'Pending',
        '5' => 'Solved',
        '6' => 'Closed',
    ];
}


