<?php
if (!defined('ABSPATH')) exit;

/**
 * Try to read assignees map from project files via filter/constant;
 * fallback to DB list if nothing provided.
 * Expected format: array of ['id'=>int,'label'=>string]
 */
function nta_resolve_assignees_from_project(){
    // filter first
    $from_filter = apply_filters('nt_glpi_assignees', null);
    if (is_array($from_filter) && !empty($from_filter)) {
        return array_values(array_filter(array_map(function($r){
            if (isset($r['id']) && isset($r['label'])) return ['id'=>(int)$r['id'],'label'=>(string)$r['label']];
            return null;
        }, $from_filter)));
    }
    // constant map (id=>label or array entries)
    if (defined('GEXE_GLPI_EXECUTORS') && is_array(GEXE_GLPI_EXECUTORS)) {
        $out=[];
        foreach (GEXE_GLPI_EXECUTORS as $k=>$v){
            if (is_array($v) && isset($v['id'],$v['label'])) $out[]=['id'=>(int)$v['id'],'label'=>(string)$v['label']];
            elseif (is_numeric($k) && is_string($v)) $out[]=['id'=>(int)$k,'label'=>$v];
        }
        if ($out) return $out;
    }
    // project helper: gexe_get_executors_list() → [{display_name,glpi_user_id}]
    if (function_exists('gexe_get_executors_list')) {
        $res = gexe_get_executors_list();
        if (is_array($res) && !empty($res['ok']) && !empty($res['list']) && is_array($res['list'])) {
            return array_map(function($r){
                return [
                    'id'    => (int) ($r['glpi_user_id'] ?? 0),
                    'label' => (string) ($r['display_name'] ?? ''),
                ];
            }, $res['list']);
        }
    }
    return null;
}

function nta_sql_get_categories(){
    $db = nta_db();
    $sql = "SELECT id, name, completename FROM glpi_itilcategories WHERE is_helpdeskvisible=1 ORDER BY completename ASC LIMIT 2000";
    $rows = $db->get_results($sql, ARRAY_A) ?: [];
    return array_map(function($r){
        return ['id'=>(int)$r['id'],'name'=>(string)$r['name'],'completename'=>(string)$r['completename']];
    }, $rows);
}

function nta_sql_get_locations(){
    $db = nta_db();
    $sql = "SELECT id, name, completename FROM glpi_locations ORDER BY completename ASC LIMIT 3000";
    $rows = $db->get_results($sql, ARRAY_A) ?: [];
    return array_map(function($r){
        return ['id'=>(int)$r['id'],'name'=>(string)$r['name'],'completename'=>(string)$r['completename']];
    }, $rows);
}

function nta_sql_get_assignees(){
    // try project-provided list first (фильтр/константа/функция)
    $provided = nta_resolve_assignees_from_project();
    if (is_array($provided) && !empty($provided)) {
        return $provided;
    }
    $db = nta_db();
    $sql = "SELECT id, name, realname, firstname FROM glpi_users WHERE is_active=1 ORDER BY realname, firstname LIMIT 2000";
    $rows = $db->get_results($sql, ARRAY_A) ?: [];
    return array_map(function($r){
        $label = trim(($r['realname'] ?? '').' '.($r['firstname'] ?? ''));
        if($label===''){ $label = $r['name'] ?? ''; }
        if (($r['name'] ?? '') === 'vks_m5_local') { $label = 'Павел Куткин'; }
        return ['id'=>(int)$r['id'],'label'=>$label];
    }, $rows);
}

function nta_sql_find_duplicate($uid, $title, $content){
    $db = nta_db();
    $sql = "SELECT id FROM glpi_tickets WHERE users_id_recipient=%d AND name=%s AND content=%s AND TIMESTAMPDIFF(SECOND,date,NOW())<=3 LIMIT 1";
    $id = $db->get_var($db->prepare($sql, $uid, $title, $content));
    return $id ? (int)$id : 0;
}
