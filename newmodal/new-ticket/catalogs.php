<?php
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../common/sql.php';

function nm_rate_limit($key){
    $k='nm_rl_'.md5($key.'|'.get_current_user_id());
    $cnt=(int)get_transient($k);
    $cnt++;
    set_transient($k,$cnt,10);
    if($cnt>15) nm_json_error('Слишком много запросов. Попробуйте позже.');
}

add_action('wp_ajax_nm_catalog_categories', 'nm_catalog_categories');
function nm_catalog_categories(){
    try {
        nm_rate_limit('nm_catalog_categories');
        nm_require_nonce();
        $db = nm_glpi_db();
        if (is_wp_error($db)) throw new RuntimeException($db->get_error_message());
        $q = isset($_REQUEST['q']) ? trim((string)wp_unslash($_REQUEST['q'])) : '';
        $limit = 100;
        if ($q === '') {
            $rows = $db->get_results(
                "SELECT id, name
                 FROM glpi_itilcategories
                 ORDER BY name ASC
                 LIMIT {$limit}", ARRAY_A
            );
        } else {
            $like = '%' . $db->esc_like($q) . '%';
            $rows = $db->get_results(
                $db->prepare(
                    "SELECT id, name
                     FROM glpi_itilcategories
                     WHERE name LIKE %s
                     ORDER BY name ASC
                     LIMIT {$limit}", $like
                ), ARRAY_A
            );
        }
        if (!is_array($rows)) $rows = [];
        nm_json_ok(['items'=>$rows]);
    } catch (Throwable $e) {
        nm_json_error('server_error', null, ['error'=>$e->getMessage()]);
    }
}

add_action('wp_ajax_nm_catalog_locations', 'nm_catalog_locations');
function nm_catalog_locations(){
    try {
        nm_rate_limit('nm_catalog_locations');
        nm_require_nonce();
        $db = nm_glpi_db();
        if (is_wp_error($db)) throw new RuntimeException($db->get_error_message());
        $q = isset($_REQUEST['q']) ? trim((string)wp_unslash($_REQUEST['q'])) : '';
        $limit = 100;
        if ($q === '') {
            $rows = $db->get_results(
                "SELECT id, name
                 FROM glpi_locations
                 ORDER BY name ASC
                 LIMIT {$limit}", ARRAY_A
            );
        } else {
            $like = '%' . $db->esc_like($q) . '%';
            $rows = $db->get_results(
                $db->prepare(
                    "SELECT id, name
                     FROM glpi_locations
                     WHERE name LIKE %s
                     ORDER BY name ASC
                     LIMIT {$limit}", $like
                ), ARRAY_A
            );
        }
        if (!is_array($rows)) $rows = [];
        nm_json_ok(['items'=>$rows]);
    } catch (Throwable $e) {
        nm_json_error('server_error', null, ['error'=>$e->getMessage()]);
    }
}

add_action('wp_ajax_nm_catalog_users', 'nm_catalog_users');
function nm_catalog_users(){
    try {
        nm_rate_limit('nm_catalog_users');
        nm_require_nonce();
        $db = nm_glpi_db();
        if (is_wp_error($db)) throw new RuntimeException($db->get_error_message());
        $q = isset($_REQUEST['q']) ? trim((string)wp_unslash($_REQUEST['q'])) : '';
        $like = '%' . $db->esc_like($q) . '%';
        $limit = 30;
        $sql = "SELECT id, name, realname, firstname
                FROM glpi_users
                WHERE is_active = 1
                  AND (name LIKE %s OR realname LIKE %s OR firstname LIKE %s)
                ORDER BY realname ASC
                LIMIT %d";
        $rows = $db->get_results($db->prepare($sql, $like, $like, $like, $limit), ARRAY_A);
        if (!is_array($rows)) $rows = [];
        foreach ($rows as &$r){
            $label = trim(($r['realname'] ?? '') . ' ' . ($r['firstname'] ?? ''));
            $r['label'] = $label !== '' ? $label : ($r['name'] ?? '');
        }
        nm_json_ok(['items'=>$rows]);
    } catch (Throwable $e) {
        nm_json_error('server_error', null, ['error'=>$e->getMessage()]);
    }
}
