<?php
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../common/api.php';

if (!function_exists('nm_can_act_on_ticket')){
    function nm_can_act_on_ticket($ticket_id){ return true; }
}

add_action('wp_ajax_nm_add_comment', 'nm_add_comment');
function nm_add_comment(){
    $rid = sanitize_text_field($_POST['rid'] ?? ''); if (nm_idempotent_check_and_set($rid)) nm_json_ok(['duplicate'=>true]);
    nm_require_nonce();
    $id = (int) filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT); if (!nm_can_act_on_ticket($id)) nm_json_error('Недостаточно прав для действия с этой заявкой.');
    $text = sanitize_textarea_field($_POST['text'] ?? '');
    if ($id<=0) nm_json_error('Некорректный ID');
    if (mb_strlen($text) < 1) nm_json_error('Введите комментарий','text');

    list($ok,$data) = nm_api_add_followup($id, ['content'=>$text]);
    if (!$ok) nm_json_error(nm_humanize_api_error($data), null, ['api'=>$data]);
    nm_json_ok(['followup'=>$data]);
}

add_action('wp_ajax_nm_change_status', 'nm_change_status');
function nm_change_status(){
    $rid = sanitize_text_field($_POST['rid'] ?? ''); if (nm_idempotent_check_and_set($rid)) nm_json_ok(['duplicate'=>true]);
    nm_require_nonce();
    $id = (int) filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT); if (!nm_can_act_on_ticket($id)) nm_json_error('Недостаточно прав для действия с этой заявкой.');
    $status = (int) filter_var($_POST['status'] ?? 0, FILTER_VALIDATE_INT); if ($id<=0 || $status<=0) nm_json_error('Некорректные параметры');

    list($ok,$data) = nm_api_update_ticket($id, ['status'=>$status]);
    if (!$ok) nm_json_error(nm_humanize_api_error($data), null, ['api'=>$data]);
    nm_json_ok(['ticket'=>$data]);
}

add_action('wp_ajax_nm_assign_user', 'nm_assign_user');
function nm_assign_user(){
    $rid = sanitize_text_field($_POST['rid'] ?? ''); if (nm_idempotent_check_and_set($rid)) nm_json_ok(['duplicate'=>true]);
    nm_require_nonce();
    $id = (int) filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT); if (!nm_can_act_on_ticket($id)) nm_json_error('Недостаточно прав для действия с этой заявкой.');
    $uid = intval($_POST['users_id'] ?? 0);
    if ($id<=0 || $uid<=0) nm_json_error('Некорректные параметры');

    list($ok,$data) = nm_api_assign_user($id, $uid);
    if (!$ok) nm_json_error(nm_humanize_api_error($data), null, ['api'=>$data]);
    nm_json_ok(['ticket'=>$data]);
}

add_action('wp_ajax_nm_notify_ticket','nm_notify_ticket');
function nm_notify_ticket(){
    nm_require_nonce();
    if (!nm_is_manager()) nm_json_error('Нет прав');
    $id = (int) filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT); if ($id<=0) nm_json_error('Некорректный ID');
    list($ok,$data)=nm_api_update_ticket($id, ['id'=>$id]);
    if(!$ok) nm_json_error(nm_humanize_api_error($data));
    nm_json_ok(['ticket'=>$data]);
}


