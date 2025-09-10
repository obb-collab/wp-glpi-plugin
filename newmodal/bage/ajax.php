<?php
// newmodal/bage/ajax.php
if (!defined('ABSPATH')) { exit; }

add_action('wp_ajax_nm_get_cards', 'nm_ajax_get_cards');
add_action('wp_ajax_nm_get_counts', 'nm_ajax_get_counts');

function nm_ajax_get_cards() {
    try {
        nm_require_logged_in();
        nm_check_nonce_or_fail();
        $wp_uid   = nm_current_wp_user_id();
        $glpi_uid = nm_glpi_user_id_from_wp($wp_uid);
        if (!$glpi_uid && !nm_is_manager()) {
            nm_json_error('forbidden', __('GLPI mapping not found', 'wp-glpi-plugin'), 403);
        }
        $page = isset($_POST['page']) ? intval($_POST['page']) : (isset($_GET['page']) ? intval($_GET['page']) : 0);
        $q    = isset($_POST['q']) ? sanitize_text_field($_POST['q']) : (isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '');
        $status = isset($_POST['status']) ? (array)$_POST['status'] : (isset($_GET['status']) ? (array)$_GET['status'] : []);
        $status = array_filter(array_map('intval', $status));
        $assignee = isset($_POST['assignee']) ? intval($_POST['assignee']) : (isset($_GET['assignee']) ? intval($_GET['assignee']) : 0);
        $args = [
            'page'     => max(0,$page),
            'per_page' => 20,
            'q'        => $q,
            'status'   => $status,
            'assignee' => $assignee ?: null,
        ];
        list($items, $has_more) = nm_sql_fetch_cards($args);
        nm_json_ok(['items'=>$items, 'has_more'=>$has_more]);
    } catch (Exception $e){
        nm_json_error('server_error', null, ['ex'=>$e->getMessage()]);
    }
}

function nm_ajax_get_counts() {
    try {
        nm_require_logged_in();
        nm_check_nonce_or_fail();
        $glpi_uid = nm_glpi_user_id_from_wp();
        $assignee = isset($_REQUEST['assignee']) ? (int)$_REQUEST['assignee'] : null;
        $counts = nm_sql_counts_by_status($glpi_uid, $assignee);
        nm_json_ok(['counts' => $counts]);
    } catch (Exception $e) {
        nm_json_error('server_error', null, ['ex'=>$e->getMessage()]);
    }
}
