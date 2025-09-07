<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../glpi-db-setup.php';

/**
 * Build list of executors from GLPI users based on WP mappings.
 * @return array<int,array{id:int,label:string}>
 */
function gexe_get_assignee_options() {
    global $wpdb, $glpi_db;

    // Fetch mapped GLPI user ids from WP usermeta
    $rows = $wpdb->get_results(
        "SELECT DISTINCT meta_value FROM {$wpdb->usermeta} WHERE meta_key='glpi_user_id' AND meta_value<>''",
        ARRAY_A
    );
    if (!$rows) return [];
    $ids = [];
    foreach ($rows as $r) {
        $gid = (int)$r['meta_value'];
        if ($gid > 0) $ids[] = $gid;
    }
    if (empty($ids)) return [];

    $place = implode(',', array_fill(0, count($ids), '%d'));
    $sql = $glpi_db->prepare(
        "SELECT id, name, realname, firstname FROM glpi_users WHERE id IN ($place) ORDER BY realname COLLATE utf8mb4_unicode_ci ASC, firstname COLLATE utf8mb4_unicode_ci ASC",
        ...$ids
    );
    $users = $glpi_db->get_results($sql, ARRAY_A);
    if (!$users) return [];

    $out = [];
    foreach ($users as $u) {
        $id   = (int)($u['id'] ?? 0);
        $login = trim($u['name'] ?? '');
        $real  = trim($u['realname'] ?? '');
        $first = trim($u['firstname'] ?? '');
        $label = trim($real . ' ' . $first);
        if ($login === 'vks_m5_local' || $label === '') {
            $label = 'Куткин Павел';
        } elseif ($label === '') {
            $label = $login;
        }
        if ($label === '') continue;
        $out[] = ['id' => $id, 'label' => $label];
    }
    return $out;
}
