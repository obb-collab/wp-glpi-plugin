<?php
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../common/sql.php';

/**
 * ВНИМАНИЕ:
 * Ранее файл использовал $wpdb (БД WordPress) и абстракцию {NM_DB_PREFIX},
 * из-за чего запросы уходили не в GLPI. Ниже — строгое использование nm_glpi_db().
 */

add_action('wp_ajax_nm_get_counts', 'nm_get_counts');
function nm_get_counts(){
    try {
        nm_require_nonce();
        $db   = nm_glpi_db();               // wpdb к БД GLPI
        if (is_wp_error($db)) throw new RuntimeException($db->get_error_message());
        $gid  = nm_glpi_user_id_from_wp();  // текущий GLPI user id
        $now  = current_time('mysql');
        $is_mgr = nm_is_manager();

        // Подсчёт по статусам
        if ($is_mgr) {
            $sql = "SELECT t.status, COUNT(*) AS cnt\n                    FROM glpi_tickets t\n                    GROUP BY t.status";
            $rows = $db->get_results($sql, ARRAY_A);
        } else {
            $sql = "SELECT t.status, COUNT(*) AS cnt\n                    FROM glpi_tickets t\n                    JOIN glpi_tickets_users tu\n                      ON tu.tickets_id = t.id AND tu.type = 2 AND tu.users_id = %d\n                    GROUP BY t.status";
            $rows = $db->get_results($db->prepare($sql, $gid), ARRAY_A);
        }
        $by = [];
        foreach ((array)$rows as $r) {
            $by[(int)$r['status']] = (int)$r['cnt'];
        }

        // Просроченные (в работе/ожидании с time_to_resolve < now)
        if ($is_mgr) {
            $overdue = (int)$db->get_var(
                $db->prepare(
                    "SELECT COUNT(*) FROM glpi_tickets t\n                     WHERE t.status NOT IN (6,7) AND t.time_to_resolve IS NOT NULL AND t.time_to_resolve < %s",
                    $now
                )
            );
        } else {
            $overdue = (int)$db->get_var(
                $db->prepare(
                    "SELECT COUNT(*) FROM glpi_tickets t\n                     JOIN glpi_tickets_users tu\n                       ON tu.tickets_id = t.id AND tu.type = 2 AND tu.users_id = %d\n                     WHERE t.status NOT IN (6,7) AND t.time_to_resolve IS NOT NULL AND t.time_to_resolve < %s",
                    $gid, $now
                )
            );
        }

        $total = array_sum($by);
        $out = [
            'all'     => $total,
            'new'     => $by[1] ?? 0,
            'work'    => $by[2] ?? 0,
            'stop'    => $by[4] ?? 0,
            'solved'  => $by[6] ?? 0,
            'closed'  => $by[7] ?? 0,
            'overdue' => $overdue,
        ];
        nm_json_ok($out);
    } catch (Throwable $e) {
        nm_json_error('server_error', null, ['error' => $e->getMessage()]);
    }
}

/**
 * Подсказка по пользователям (для назначения)
 */
add_action('wp_ajax_nm_suggest_users', 'nm_suggest_users');
function nm_suggest_users(){
    try {
        nm_require_nonce();
        $db = nm_glpi_db();
        if (is_wp_error($db)) throw new RuntimeException($db->get_error_message());
        $q  = isset($_REQUEST['q']) ? trim((string)wp_unslash($_REQUEST['q'])) : '';
        $like = '%' . $db->esc_like($q) . '%';
        $limit = 30;
        $sql = "SELECT id, name, realname, firstname\n                FROM glpi_users\n                WHERE is_active = 1\n                  AND (name LIKE %s OR realname LIKE %s OR firstname LIKE %s)\n                ORDER BY realname ASC\n                LIMIT %d";
        $rows = $db->get_results($db->prepare($sql, $like, $like, $like, $limit), ARRAY_A);
        if (!is_array($rows)) $rows = [];
        foreach ($rows as &$r) {
            $label = trim(($r['realname'] ?? '') . ' ' . ($r['firstname'] ?? ''));
            $r['label'] = $label !== '' ? $label : ($r['name'] ?? '');
        }
        nm_json_ok(['items' => $rows]);
    } catch (Throwable $e) {
        nm_json_error('server_error', null, ['error' => $e->getMessage()]);
    }
}

/**
 * Данные карточки для модалки — строго из GLPI.
 */
add_action('wp_ajax_nm_get_card_extra', 'nm_get_card_extra');
function nm_get_card_extra(){
    nm_require_nonce();
    $db = nm_glpi_db();
    if (is_wp_error($db)) { nm_json_error('DB error: '.$db->get_error_message()); }
    $id = (int)($_REQUEST['id'] ?? 0);
    if ($id<=0) nm_json_error('Некорректный ID');
    $gid = nm_glpi_user_id_from_wp();
    $can = nm_is_manager()
        ? 1
        : (int)$db->get_var($db->prepare("SELECT 1 FROM glpi_tickets_users tu WHERE tu.tickets_id=%d AND tu.type=2 AND tu.users_id=%d", $id, $gid));
    if (!$can) nm_json_error('Нет доступа к заявке');
    $ticket = $db->get_row($db->prepare("SELECT id, name, content, status, priority, date, time_to_resolve, solvedate, locations_id FROM glpi_tickets WHERE id=%d", $id), ARRAY_A);
    if (!$ticket) nm_json_error(__('Заявка не найдена', 'wp-glpi-plugin'));
    $assignee = $db->get_row($db->prepare("SELECT u.id, u.name, u.realname, u.firstname FROM glpi_tickets_users tu JOIN glpi_users u ON u.id=tu.users_id WHERE tu.tickets_id=%d AND tu.type=2 ORDER BY tu.id DESC LIMIT 1", $id), ARRAY_A);
    $fups = $db->get_results($db->prepare("SELECT f.id, f.content, f.date, f.users_id FROM glpi_itilfollowups f WHERE f.items_id=%d AND f.itemtype='Ticket' ORDER BY f.date DESC", $id), ARRAY_A);
    if (!is_array($fups)) $fups = [];
    nm_json_ok(['ticket'=>$ticket, 'assignee'=>$assignee, 'followups'=>$fups]);
}
