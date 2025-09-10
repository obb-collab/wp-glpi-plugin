<?php
// newmodal/config.php
if (!defined('ABSPATH')) { exit; }

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
