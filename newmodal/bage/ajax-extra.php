<?php
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/../helpers.php';

add_action('wp_ajax_nm_get_counts', 'nm_get_counts');
function nm_get_counts(){
    nm_require_nonce();
    global $wpdb;
    $prefix = NM_DB_PREFIX;
    $gid = nm_glpi_user_id_from_wp();
    $where = nm_is_manager() ? "1=1" : $wpdb->prepare("EXISTS (SELECT 1 FROM {$prefix}tickets_users tu WHERE tu.tickets_id=t.id AND tu.type=2 AND tu.users_id=%d)", $gid);
    $now = current_time('mysql');

    $sql = "SELECT COUNT(*) FROM {$prefix}tickets t WHERE {$where}";
    $total = (int)$wpdb->get_var($sql);

    $count_by_status = function($status) use ($wpdb,$prefix,$where){
        $sql = "SELECT COUNT(*) FROM {$prefix}tickets t WHERE {$where} AND t.status=%d";
        return (int)$wpdb->get_var($wpdb->prepare($sql, $status));
    };

    $work = $count_by_status(2);
    $plan = $count_by_status(3);
    $stop = $count_by_status(4);
    $new  = $count_by_status(1);
    $sql_over = "SELECT COUNT(*) FROM {$prefix}tickets t WHERE {$where} AND t.status <> 6 AND t.due_date IS NOT NULL AND t.due_date < %s";
    $overdue = (int)$wpdb->get_var($wpdb->prepare($sql_over, $now));

    nm_json_ok([
        'total'=>$total,'work'=>$work,'plan'=>$plan,'stop'=>$stop,'new'=>$new,'overdue'=>$overdue
    ]);
}

add_action('wp_ajax_nm_get_card', 'nm_get_card');
function nm_get_card(){
    nm_require_nonce();
    global $wpdb;
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) nm_json_error('Некорректный ID');

    $prefix = NM_DB_PREFIX;
    $gid = nm_glpi_user_id_from_wp();
    $can = nm_is_manager() ? 1 : (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}tickets_users tu WHERE tu.tickets_id=%d AND tu.type=2 AND tu.users_id=%d", $id, $gid));
    if (!$can) nm_json_error('Нет доступа к заявке');

    $ticket = $wpdb->get_row($wpdb->prepare("SELECT id, name, content, status, date, date_mod, due_date, itilcategories_id, locations_id FROM {$prefix}tickets WHERE id=%d", $id), ARRAY_A);
    if (!$ticket) nm_json_error(__('Заявка не найдена', 'wp-glpi-plugin'));

    $assignee = $wpdb->get_row($wpdb->prepare("SELECT u.id, u.name FROM {$prefix}tickets_users tu JOIN {$prefix}users u ON u.id=tu.users_id WHERE tu.tickets_id=%d AND tu.type=2 ORDER BY tu.id DESC LIMIT 1", $id), ARRAY_A);
    $fups = $wpdb->get_results($wpdb->prepare("SELECT f.id, f.content, f.date, u.name AS author FROM {$prefix}itilfollowups f LEFT JOIN {$prefix}users u ON u.id=f.users_id WHERE f.items_id=%d AND f.itemtype='Ticket' ORDER BY f.date DESC", $id), ARRAY_A);
    if (!is_array($fups)) $fups = [];

    nm_json_ok(['ticket'=>$ticket, 'assignee'=>$assignee, 'followups'=>$fups]);
}
