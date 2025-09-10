<?php
// newmodal/new-ticket/catalogs.php
if (!defined('ABSPATH')) { exit; }

add_action('wp_ajax_nm_catalog_categories', 'nm_ajax_catalog_categories');
add_action('wp_ajax_nm_catalog_locations', 'nm_ajax_catalog_locations');
add_action('wp_ajax_nm_catalog_assignees', 'nm_ajax_catalog_assignees');

function nm_ajax_catalog_categories() {
    nm_require_logged_in();
    nm_check_nonce_or_fail();
    $q = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 20; $offset = ($page-1)*$per;
    $params = [];
    $where = '';
    if ($q !== '') {
        $where = " WHERE name LIKE %s ";
        $params[] = '%'.$q.'%';
    }
    $sql = "SELECT id, name FROM ".nm_tbl('itilcategories')." $where ORDER BY name ASC LIMIT %d OFFSET %d";
    $params[] = $per; $params[] = $offset;
    try {
        $items = nm_db_get_results($sql, $params);
        nm_json_ok(['items' => $items, 'page' => $page, 'total' => count($items)]);
    } catch (Exception $e) {
        nm_json_error('db_error', __('Failed to load categories', 'nm'));
    }
}

function nm_ajax_catalog_locations() {
    nm_require_logged_in();
    nm_check_nonce_or_fail();
    $q = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 20; $offset = ($page-1)*$per;
    $params = [];
    $where = '';
    if ($q !== '') {
        $where = " WHERE name LIKE %s ";
        $params[] = '%'.$q.'%';
    }
    $sql = "SELECT id, name FROM ".nm_tbl('locations')." $where ORDER BY name ASC LIMIT %d OFFSET %d";
    $params[] = $per; $params[] = $offset;
    try {
        $items = nm_db_get_results($sql, $params);
        nm_json_ok(['items' => $items, 'page' => $page, 'total' => count($items)]);
    } catch (Exception $e) {
        nm_json_error('db_error', __('Failed to load locations', 'nm'));
    }
}

function nm_ajax_catalog_assignees() {
    nm_require_logged_in();
    nm_check_nonce_or_fail();
    $q = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 20; $offset = ($page-1)*$per;
    $params = [];
    $where = " WHERE is_active = 1 ";
    if ($q !== '') {
        $where .= " AND ( name LIKE %s OR realname LIKE %s OR firstname LIKE %s ) ";
        $params[] = '%'.$q.'%';
        $params[] = '%'.$q.'%';
        $params[] = '%'.$q.'%';
    }
    $sql = "SELECT id, name, realname, firstname FROM ".nm_tbl('users')." $where ORDER BY realname ASC LIMIT %d OFFSET %d";
    $params[] = $per; $params[] = $offset;
    try {
        $rows = nm_db_get_results($sql, $params);
        $items = [];
        foreach ($rows as $r) {
            $label = trim(($r['realname'] ?? '').' '.($r['firstname'] ?? ''));
            if (!$label) $label = $r['name'];
            $items[] = ['id' => (int)$r['id'], 'name' => $label];
        }
        nm_json_ok(['items' => $items, 'page' => $page, 'total' => count($items)]);
    } catch (Exception $e) {
        nm_json_error('db_error', __('Failed to load assignees', 'nm'));
    }
}
