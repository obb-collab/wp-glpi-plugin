<?php
/**
 * GLPI Modal + Actions (standalone module)
 * Не ломает основную логику плагина. Подключается из gexe-copy.php одной строкой.
 * Все комментарии сохраняем.
 */

// -------- Enqueue (CSS/JS) + локализация AJAX --------
add_action('wp_enqueue_scripts', function () {
    // Регистрируем стили/скрипт отдельными файлами
    $base = plugin_dir_url(__FILE__);

    wp_register_style(
        'glpi-modal-css',
        $base . 'glpi-modal.css',
        [],
        '1.0.0'
    );
    wp_register_script(
        'glpi-modal-js',
        $base . 'glpi-modal.js',
        [],
        '1.0.0',
        true
    );

    // Локализация AJAX (WP admin-ajax)
    wp_localize_script('glpi-modal-js', 'glpiAjax', [
        'url'   => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('glpi_modal_actions'),
    ]);

    // Подключаем на странице с карточками — если надо, можешь ограничить по шорткоду/условию
    wp_enqueue_style('glpi-modal-css');
    wp_enqueue_script('glpi-modal-js');
});

// -------- ПРАВА: решай здесь, кому можно менять тикеты --------
function gexe_can_touch_glpi_ticket($ticket_id) {
    if (!is_user_logged_in()) {
        return false;
    }

    $wp_uid   = get_current_user_id();
    $glpi_uid = intval(get_user_meta($wp_uid, 'glpi_user_id', true));
    if ($glpi_uid <= 0) {
        return false;
    }

    global $wpdb;

    $tProfilesUsers = $wpdb->prefix . 'glpi_profiles_users';
    $tProfilerights = $wpdb->prefix . 'glpi_profilerights';

    // Проверяем глобальные права на обновление тикетов
    $has_right = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT 1 FROM {$tProfilesUsers} pu
             JOIN {$tProfilerights} pr ON pu.profiles_id = pr.profiles_id
             WHERE pu.users_id = %d AND pr.name = 'ticket' AND (pr.rights & 2) > 0
             LIMIT 1",
            $glpi_uid
        )
    );
    if ($has_right) {
        return true;
    }

    // Проверяем, является ли пользователь участником тикета
    $tTicketsUsers = $wpdb->prefix . 'glpi_tickets_users';
    $is_member = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT 1 FROM {$tTicketsUsers}
             WHERE tickets_id = %d AND users_id = %d LIMIT 1",
            $ticket_id,
            $glpi_uid
        )
    );

    return (bool) $is_member;
}

// -------- AJAX: загрузка комментариев тикета --------
add_action('wp_ajax_glpi_get_comments', 'gexe_glpi_get_comments');
add_action('wp_ajax_nopriv_glpi_get_comments', 'gexe_glpi_get_comments');
function gexe_render_comments($ticket_id) {
    global $wpdb;
    if ($ticket_id <= 0) {
        return '';
    }

    // !!! ВАЖНО: проверь имена таблиц. Ниже — типичные для GLPI 9.x.
    $tFollowups = $wpdb->prefix . 'glpi_ticketfollowups'; // при другом префиксе подставь свой

    // Тянем комментарии по возрастанию даты
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, users_id, date, content
             FROM {$tFollowups}
             WHERE tickets_id = %d
             ORDER BY date ASC", $ticket_id
        ), ARRAY_A
    );

    if (!$rows) {
        return '<div class="glpi-empty">Нет комментариев</div>';
    }

    // Рендерим компактный HTML (GLPI часто хранит HTML в content — сохраняем)
    $out = '';
    foreach ($rows as $r) {
        $when = esc_html($r['date']);
        $uid  = intval($r['users_id']);
        $txt  = wp_kses_post($r['content']);
        $out .= '<div class="glpi-comment">'
              . '<div class="meta">Автор ID ' . $uid . ' • ' . $when . '</div>'
              . '<div class="text">' . $txt . '</div>'
              . '</div>';
    }
    return $out;
}

function gexe_glpi_get_comments() {
    check_ajax_referer('glpi_modal_actions');
    $ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;
    wp_die(gexe_render_comments($ticket_id));
}

// -------- AJAX: действия по тикету (принять, закрыть, сменить исполнителя/статус) --------
add_action('wp_ajax_glpi_card_action', 'gexe_glpi_card_action');
function gexe_glpi_card_action() {
    check_ajax_referer('glpi_modal_actions');

    $ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;
    $type      = isset($_POST['type']) ? sanitize_key($_POST['type']) : '';
    $payload   = isset($_POST['payload']) ? json_decode(stripslashes($_POST['payload']), true) : [];

    if ($ticket_id <= 0 || !$type) {
        wp_send_json(['ok' => false, 'error' => 'bad_request']);
    }
    if (!gexe_can_touch_glpi_ticket($ticket_id)) {
        wp_send_json(['ok' => false, 'error' => 'forbidden']);
    }

    // Если GLPI в другой БД — используй свой объект $glpi вместо $wpdb
    global $wpdb;

    // !!! ПРОВЕРЬ названия таблиц под свою схему
    $tTickets   = $wpdb->prefix . 'glpi_tickets';
    $tAssignees = $wpdb->prefix . 'glpi_tickets_users'; // type: 2 = assigned
    $tFollowups = $wpdb->prefix . 'glpi_ticketfollowups';

    $ok = false;
    $new_status = null;

    if ($type === 'start') {
        // Принято в работу (подстрой код статуса под свои правила)
        $new_status = 2; // In progress / Assigned
        $ok = (false !== $wpdb->update($tTickets, ['status'=>$new_status], ['id'=>$ticket_id], ['%d'], ['%d']));
        if ($ok) {
            $wpdb->insert($tFollowups, [
                'tickets_id'=>$ticket_id,'date'=>current_time('mysql'),
                'content'=>'Статус изменён через WP: Принято в работу',
                'users_id'=>0
            ], ['%d','%s','%s','%d']);
        }

    } elseif ($type === 'done') {
        // Завершено (часто: 4 — solved, 5 — closed)
        $new_status = 4;
        $ok = (false !== $wpdb->update($tTickets, ['status'=>$new_status], ['id'=>$ticket_id], ['%d'], ['%d']));
        if ($ok) {
            $wpdb->insert($tFollowups, [
                'tickets_id'=>$ticket_id,'date'=>current_time('mysql'),
                'content'=>'Статус изменён через WP: Задача выполнена',
                'users_id'=>0
            ], ['%d','%s','%s','%d']);
        }

    } elseif ($type === 'assignee') {
        // Смена исполнителя
        $user_id = isset($payload['user_id']) ? intval($payload['user_id']) : 0;
        if ($user_id > 0) {
            $wpdb->delete($tAssignees, ['tickets_id'=>$ticket_id, 'type'=>2], ['%d','%d']); // очистим текущих исполнителей
            $ok = (false !== $wpdb->insert($tAssignees, [
                'tickets_id'=>$ticket_id,'users_id'=>$user_id,'type'=>2
            ], ['%d','%d','%d']));
            if ($ok) {
                $wpdb->insert($tFollowups, [
                    'tickets_id'=>$ticket_id,'date'=>current_time('mysql'),
                    'content'=>'Исполнитель изменён через WP на users_id=' . $user_id,
                    'users_id'=>0
                ], ['%d','%s','%s','%d']);
            }
        }

    } elseif ($type === 'status') {
        // Смена статуса на произвольный код
        $new_status = isset($payload['status']) ? intval($payload['status']) : null;
        if ($new_status !== null) {
            $ok = (false !== $wpdb->update($tTickets, ['status'=>$new_status], ['id'=>$ticket_id], ['%d'], ['%d']));
            if ($ok) {
                $wpdb->insert($tFollowups, [
                    'tickets_id'=>$ticket_id,'date'=>current_time('mysql'),
                    'content'=>'Статус изменён через WP на: ' . $new_status,
                    'users_id'=>0
                ], ['%d','%s','%s','%d']);
            }
        }
    }

    // Вернём обновлённые комментарии (для моментальной перерисовки)
    $comment_html = '';
    if ($ok) {
        $comment_html = gexe_render_comments($ticket_id);
    }

    wp_send_json([
        'ok' => (bool)$ok,
        'new_status' => $new_status,
        'comment_html' => $comment_html
    ]);
}
