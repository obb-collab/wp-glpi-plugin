<?php
/**
 * AJAX для формы новой заявки (создание через SQL, затем пинг API).
 */
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../common/helpers.php';
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/notify-api.php';

function gexe_nm_ajax_catalogs() {
    try {
        gexe_nm_check_nonce();
        // Здесь можно фильтровать по энтити при необходимости
        $pdo = glpi_get_pdo();
        $cats = $pdo->query('SELECT id, name FROM glpi_itilcategories ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        $locs = $pdo->query('SELECT id, name FROM glpi_locations ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        wp_send_json(['ok'=>true,'code'=>'ok','message'=>'','categories'=>$cats,'locations'=>$locs]);
    } catch (Throwable $e) {
        wp_send_json(['ok'=>false,'code'=>'exception','message'=>$e->getMessage()]);
    }
}
add_action('wp_ajax_gexe_nm_catalogs', 'gexe_nm_ajax_catalogs');

function gexe_nm_ajax_create_ticket_newform() {
    try {
        gexe_nm_check_nonce();
        $payload = [
            'name'     => isset($_POST['name']) ? wp_unslash((string)$_POST['name']) : '',
            'content'  => isset($_POST['content']) ? wp_unslash((string)$_POST['content']) : '',
            'category' => isset($_POST['category']) ? (int) $_POST['category'] : 0,
            'location' => isset($_POST['location']) ? (int) $_POST['location'] : 0,
            'due'      => isset($_POST['due']) ? wp_unslash((string)$_POST['due']) : '',
            'assignee' => isset($_POST['assignee']) ? (int) $_POST['assignee'] : 0,
        ];
        $res = nm_sql_create_ticket($payload);
        if (!$res['ok']) {
            return wp_send_json(['ok'=>false,'code'=>$res['code'] ?? 'sql_error','message'=>$res['message'] ?? 'Ошибка SQL']);
        }
        nm_api_trigger_notifications();
        wp_send_json(['ok'=>true,'code'=>'ok','message'=>'Создано','ticket_id'=>$res['ticket_id'] ?? 0]);
    } catch (Throwable $e) {
        wp_send_json(['ok'=>false,'code'=>'exception','message'=>$e->getMessage()]);
    }
}
add_action('wp_ajax_gexe_nm_new_ticket', 'gexe_nm_ajax_create_ticket_newform');

add_action('wp_ajax_nopriv_gexe_nm_catalogs', function(){ wp_send_json(['ok'=>false,'code'=>'unauth','message'=>'Требуется авторизация']); });
add_action('wp_ajax_nopriv_gexe_nm_new_ticket', function(){ wp_send_json(['ok'=>false,'code'=>'unauth','message'=>'Требуется авторизация']); });

