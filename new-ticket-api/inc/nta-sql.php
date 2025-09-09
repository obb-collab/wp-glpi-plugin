<?php
if (!defined('ABSPATH')) exit;

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
    $db = nta_db();
    $sql = "SELECT id, name, realname, firstname FROM glpi_users WHERE is_active=1 ORDER BY realname, firstname LIMIT 2000";
    $rows = $db->get_results($sql, ARRAY_A) ?: [];
    return array_map(function($r){
        $label = trim(($r['realname'] ?? '').' '.($r['firstname'] ?? ''));
        if($label===''){ $label = $r['name'] ?? ''; }
        return ['id'=>(int)$r['id'],'label'=>$label];
    }, $rows);
}

function nta_sql_find_duplicate($uid, $title, $content){
    $db = nta_db();
    $sql = "SELECT id FROM glpi_tickets WHERE users_id_recipient=%d AND name=%s AND content=%s AND TIMESTAMPDIFF(SECOND,date,NOW())<=3 LIMIT 1";
    $id = $db->get_var($db->prepare($sql, $uid, $title, $content));
    return $id ? (int)$id : 0;
}
