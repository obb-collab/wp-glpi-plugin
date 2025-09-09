<?php
if (!defined('ABSPATH')) exit;

/**
 * Chief-only AJAX endpoints.
 * Все изменения изолированы в подпапке /chief.
 */

// Регистрация эндпоинтов
add_action('wp_ajax_gexe_chief_accept_sql', 'gexe_chief_accept_sql');
add_action('wp_ajax_gexe_chief_update_status_sql', 'gexe_chief_update_status_sql');
add_action('wp_ajax_gexe_chief_assign_sql', 'gexe_chief_assign_sql');
add_action('wp_ajax_gexe_chief_comment_sql', 'gexe_chief_comment_sql');
add_action('wp_ajax_gexe_chief_state_bulk', 'gexe_chief_state_bulk');

// Авторизация и проверка входных данных
function gexe_chief_require($keys = []) {
    if (!function_exists('chief_is_chief_user') || !chief_is_chief_user()) {
        wp_send_json(['ok' => false, 'code' => 'forbidden', 'detail' => 'Chief only.']);
    }
    check_ajax_referer('gexe_chief_nonce');
    foreach ($keys as $k) {
        if (!isset($_POST[$k])) {
            wp_send_json(['ok' => false, 'code' => 'bad_request', 'detail' => "Missing field: {$k}"]);
        }
    }
}

// Принять в работу: назначение, статус=2, комментарий "Принято в работу" (идемпотентно 10 мин)
function gexe_chief_accept_sql() {
    gexe_chief_require(['ticket_id', 'acting_as']);
    $tid = (int) $_POST['ticket_id'];
    $acting_as = (int) $_POST['acting_as'];
    if ($tid <= 0 || $acting_as <= 0) {
        wp_send_json(['ok' => false, 'code' => 'bad_request', 'detail' => 'Invalid ids']);
    }
    global $glpi_db;
    $glpi_db->query('START TRANSACTION');
    try {
        // ensure assignment (type=2)
        $exists = (int) $glpi_db->get_var($glpi_db->prepare(
            "SELECT 1 FROM glpi_tickets_users WHERE tickets_id=%d AND users_id=%d AND type=2 LIMIT 1",
            $tid, $acting_as
        ));
        if (!$exists) {
            $glpi_db->query($glpi_db->prepare("DELETE FROM glpi_tickets_users WHERE tickets_id=%d AND type=2", $tid));
            $glpi_db->query($glpi_db->prepare(
                "INSERT INTO glpi_tickets_users (tickets_id, users_id, type) VALUES (%d, %d, 2)",
                $tid, $acting_as
            ));
        }
        // status -> in progress (2)
        $cur = (int) $glpi_db->get_var($glpi_db->prepare("SELECT status FROM glpi_tickets WHERE id=%d FOR UPDATE", $tid));
        if ($cur !== 2) {
            $glpi_db->query($glpi_db->prepare("UPDATE glpi_tickets SET status=2 WHERE id=%d", $tid));
        }
        // accept comment (idempotent 10m)
        $accept_text = 'Принято в работу';
        $dup = (int) $glpi_db->get_var($glpi_db->prepare(
            "SELECT id FROM glpi_itilfollowups\n             WHERE items_id=%d AND users_id=%d AND content=%s\n               AND date >= (NOW() - INTERVAL 10 MINUTE)\n             LIMIT 1",
            $tid, $acting_as, $accept_text
        ));
        if (!$dup) {
            $glpi_db->query($glpi_db->prepare(
                "INSERT INTO glpi_itilfollowups (items_id, is_private, requesttypes_id, users_id, date, content)\n                 VALUES (%d, 0, 1, %d, NOW(), %s)",
                $tid, $acting_as, $accept_text
            ));
        }
        $glpi_db->query('COMMIT');
        wp_send_json(['ok' => true]);
    } catch (Throwable $e) {
        $glpi_db->query('ROLLBACK');
        if (defined('CHIEF_DEBUG') && CHIEF_DEBUG) error_log('chief_accept_sql: '.$e->getMessage());
        wp_send_json(['ok' => false, 'code' => 'sql_error', 'detail' => 'DB error']);
    }
}

// Сменить статус (опционально добавить "Принято в работу")
function gexe_chief_update_status_sql() {
    gexe_chief_require(['ticket_id', 'acting_as', 'new_status']);
    $tid = (int) $_POST['ticket_id'];
    $acting_as = (int) $_POST['acting_as'];
    $new_status = (int) $_POST['new_status'];
    $add_accept = isset($_POST['add_accept_comment']) ? (int) $_POST['add_accept_comment'] : 0;
    if ($tid <= 0 || $acting_as <= 0 || $new_status <= 0) {
        wp_send_json(['ok' => false, 'code' => 'bad_request', 'detail' => 'Invalid fields']);
    }
    global $glpi_db;
    $glpi_db->query('START TRANSACTION');
    try {
        $cur = (int) $glpi_db->get_var($glpi_db->prepare("SELECT status FROM glpi_tickets WHERE id=%d FOR UPDATE", $tid));
        if ($cur !== $new_status) {
            $glpi_db->query($glpi_db->prepare("UPDATE glpi_tickets SET status=%d WHERE id=%d", $new_status, $tid));
        }
        if ($add_accept) {
            $accept_text = 'Принято в работу';
            $dup = (int) $glpi_db->get_var($glpi_db->prepare(
                "SELECT id FROM glpi_itilfollowups\n                 WHERE items_id=%d AND users_id=%d AND content=%s\n                   AND date >= (NOW() - INTERVAL 10 MINUTE)\n                 LIMIT 1",
                $tid, $acting_as, $accept_text
            ));
            if (!$dup) {
                $glpi_db->query($glpi_db->prepare(
                    "INSERT INTO glpi_itilfollowups (items_id, is_private, requesttypes_id, users_id, date, content)\n                     VALUES (%d, 0, 1, %d, NOW(), %s)",
                     $tid, $acting_as, $accept_text
                ));
            }
        }
        $glpi_db->query('COMMIT');
        wp_send_json(['ok' => true]);
    } catch (Throwable $e) {
        $glpi_db->query('ROLLBACK');
        if (defined('CHIEF_DEBUG') && CHIEF_DEBUG) error_log('chief_update_status_sql: '.$e->getMessage());
        wp_send_json(['ok' => false, 'code' => 'sql_error', 'detail' => 'DB error']);
    }
}

// Переназначение исполнителя
function gexe_chief_assign_sql() {
    gexe_chief_require(['ticket_id', 'acting_as', 'new_assignee']);
    $tid = (int) $_POST['ticket_id'];
    $assignee = (int) $_POST['new_assignee'];
    if ($tid <= 0 || $assignee <= 0) {
        wp_send_json(['ok' => false, 'code' => 'bad_request', 'detail' => 'Invalid ids']);
    }
    global $glpi_db;
    $glpi_db->query('START TRANSACTION');
    try {
        $exists = (int) $glpi_db->get_var($glpi_db->prepare(
            "SELECT 1 FROM glpi_tickets_users WHERE tickets_id=%d AND users_id=%d AND type=2 LIMIT 1",
            $tid, $assignee
        ));
        if (!$exists) {
            $glpi_db->query($glpi_db->prepare("DELETE FROM glpi_tickets_users WHERE tickets_id=%d AND type=2", $tid));
            $glpi_db->query($glpi_db->prepare(
                "INSERT INTO glpi_tickets_users (tickets_id, users_id, type) VALUES (%d, %d, 2)",
                $tid, $assignee
            ));
        }
        $glpi_db->query('COMMIT');
        wp_send_json(['ok' => true]);
    } catch (Throwable $e) {
        $glpi_db->query('ROLLBACK');
        if (defined('CHIEF_DEBUG') && CHIEF_DEBUG) error_log('chief_assign_sql: '.$e->getMessage());
        wp_send_json(['ok' => false, 'code' => 'sql_error', 'detail' => 'DB error']);
    }
}

// Комментарий от имени acting_as
function gexe_chief_comment_sql() {
    gexe_chief_require(['ticket_id', 'acting_as', 'comment']);
    $tid = (int) $_POST['ticket_id'];
    $acting_as = (int) $_POST['acting_as'];
    $comment = wp_kses_post(wp_unslash($_POST['comment']));
    if ($tid <= 0 || $acting_as <= 0 || $comment === '') {
        wp_send_json(['ok' => false, 'code' => 'bad_request', 'detail' => 'Invalid input']);
    }
    global $glpi_db;
    try {
        $glpi_db->query($glpi_db->prepare(
            "INSERT INTO glpi_itilfollowups (items_id, is_private, requesttypes_id, users_id, date, content)\n             VALUES (%d, 0, 1, %d, NOW(), %s)",
            $tid, $acting_as, $comment
        ));
        wp_send_json(['ok' => true]);
    } catch (Throwable $e) {
        if (defined('CHIEF_DEBUG') && CHIEF_DEBUG) error_log('chief_comment_sql: '.$e->getMessage());
        wp_send_json(['ok' => false, 'code' => 'sql_error', 'detail' => 'DB error']);
    }
}

/**
 * Батч-инициализация состояния "Принято в работу" для списка тикетов.
 * accepted = (status=2)
 */
function gexe_chief_state_bulk() {
    gexe_chief_require(['ticket_ids']);
    $ids = $_POST['ticket_ids'];
    if (is_string($ids)) {
        $ids = array_filter(array_map('intval', explode(',', $ids)));
    } elseif (is_array($ids)) {
        $ids = array_filter(array_map('intval', $ids));
    } else {
        $ids = [];
    }
    if (!$ids) {
        wp_send_json(['ok' => true, 'states' => []]);
    }
    global $glpi_db;
    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $sql = "SELECT id, status FROM glpi_tickets WHERE id IN ($placeholders)";
    $prepared = call_user_func_array([$glpi_db, 'prepare'], array_merge([$sql], $ids));
    $rows = $glpi_db->get_results($prepared, ARRAY_A);
    $states = [];
    foreach ($rows as $r) {
        $states[(int)$r['id']] = [
            'accepted' => ((int)$r['status'] === 2),
            'status'   => (int)$r['status'],
        ];
    }
    wp_send_json(['ok' => true, 'states' => $states]);
}
