<?php
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/../helpers.php';

function nm_rate_limit($key){
    $k='nm_rl_'.md5($key.'|'.get_current_user_id());
    $cnt=(int)get_transient($k);
    $cnt++;
    set_transient($k,$cnt,10);
    if($cnt>15) nm_json_error('Слишком много запросов. Попробуйте позже.');
}

add_action('wp_ajax_nm_catalog_categories', 'nm_catalog_categories');
function nm_catalog_categories(){
    nm_rate_limit('nm_catalog_categories');
    nm_require_nonce();
    global $wpdb;
    $q = sanitize_text_field($_POST['q'] ?? '');
    if (mb_strlen($q) > 64) $q = mb_substr($q, 0, 64);
    $limit = 20;
    $prefix = NM_DB_PREFIX;
    $like = '%' . $wpdb->esc_like($q) . '%';
    $sql = "SELECT id, name, completename FROM {$prefix}itilcategories WHERE is_helpdeskvisible=1 AND (name LIKE %s OR completename LIKE %s) ORDER BY name ASC LIMIT %d";
    $rows = $wpdb->get_results($wpdb->prepare($sql, $like, $like, $limit), ARRAY_A);
    if (!is_array($rows)) $rows = [];
    nm_json_ok(['items'=>$rows]);
}

add_action('wp_ajax_nm_catalog_locations', 'nm_catalog_locations');
function nm_catalog_locations(){
    nm_rate_limit('nm_catalog_locations');
    nm_require_nonce();
    global $wpdb;
    $q = sanitize_text_field($_POST['q'] ?? '');
    if (mb_strlen($q) > 64) $q = mb_substr($q, 0, 64);
    $limit = 20;
    $prefix = NM_DB_PREFIX;
    $like = '%' . $wpdb->esc_like($q) . '%';
    $sql = "SELECT id, name, completename FROM {$prefix}locations WHERE (name LIKE %s OR completename LIKE %s) ORDER BY name ASC LIMIT %d";
    $rows = $wpdb->get_results($wpdb->prepare($sql, $like, $like, $limit), ARRAY_A);
    if (!is_array($rows)) $rows = [];
    nm_json_ok(['items'=>$rows]);
}

add_action('wp_ajax_nm_catalog_assignees', 'nm_catalog_assignees');
function nm_catalog_assignees(){
    nm_rate_limit('nm_catalog_assignees');
    nm_require_nonce();
    global $wpdb;
    $q = sanitize_text_field($_POST['q'] ?? '');
    if (mb_strlen($q) > 64) $q = mb_substr($q, 0, 64);
    $limit = 30;
    $prefix = NM_DB_PREFIX;
    $like = '%' . $wpdb->esc_like($q) . '%';
    $sql = "SELECT id, name, realname, firstname FROM {$prefix}users WHERE is_active=1 AND (name LIKE %s OR realname LIKE %s OR firstname LIKE %s) ORDER BY realname ASC LIMIT %d";
    $rows = $wpdb->get_results($wpdb->prepare($sql, $like, $like, $like, $limit), ARRAY_A);
    if (!is_array($rows)) $rows = [];
    foreach ($rows as &$r){
        $r['label'] = trim(($r['realname'] ?? '') . ' ' . ($r['firstname'] ?? ''));
        if (!$r['label']) $r['label'] = $r['name'];
    }
    nm_json_ok(['items'=>$rows]);
}


