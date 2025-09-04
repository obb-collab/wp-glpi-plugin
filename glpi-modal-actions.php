<?php
/**
 * GLPI Modal + Actions (standalone module)
 * Подключается из gexe-copy.php. Использует существующее соединение $glpi_db.
 * Поддержка AJAX для: загрузки комментариев, проверки "Принято в работу",
 * добавления комментария, действий "start/done", счетчика комментариев.
 */
require_once __DIR__ . '/glpi-utils.php';

add_action('wp_enqueue_scripts', function () {
    wp_localize_script('gexe-filter', 'glpiAjax', [
        'url'          => admin_url('admin-ajax.php'),
        'nonce'        => wp_create_nonce('glpi_modal_actions'),
        'user_glpi_id' => gexe_get_current_glpi_uid(),
    ]);
});

/** Права: глобальные + назначенный исполнитель */
function gexe_can_touch_glpi_ticket($ticket_id) {
    if (!is_user_logged_in()) return false;

    $glpi_uid = gexe_get_current_glpi_uid();
    if ($glpi_uid <= 0) return false;

    global $glpi_db;

    // Глобальные права (UPDATE)
    $has_right = $glpi_db->get_var($glpi_db->prepare(
        "SELECT 1
         FROM glpi_profiles_users pu
         JOIN glpi_profilerights pr ON pu.profiles_id = pr.profiles_id
         WHERE pu.users_id = %d
           AND pr.name = 'ticket'
           AND (pr.rights & 2) > 0
         LIMIT 1",
        $glpi_uid
    ));
    if ($has_right) return true;

    // Исполнитель тикета
    $is_assignee = $glpi_db->get_var($glpi_db->prepare(
        "SELECT 1
         FROM glpi_tickets_users
         WHERE tickets_id = %d
           AND users_id  = %d
           AND type      = 2
         LIMIT 1",
        $ticket_id,
        $glpi_uid
    ));

    return (bool)$is_assignee;
}

/** Короткое ФИО: «Фамилия И.» */
function gexe_compose_short_name($realname, $firstname) {
    $realname  = trim((string)$realname);
    $firstname = trim((string)$firstname);
    if ($realname && $firstname) return $realname . ' ' . mb_substr($firstname, 0, 1) . '.';
    if ($realname) return $realname;
    if ($firstname) return $firstname;
    return '';
}

/** Очистка HTML комментария (текст в карточке модалки) */
function gexe_clean_comment_html($html) {
    if (!is_string($html) || $html === '') return '';
    $html = str_replace(["\r\n", "\r"], "\n", $html);
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $html = preg_replace_callback('~<img\b[^>]*>~i', function ($m) {
        $tag = $m[0];
        if (preg_match('~\balt\s*=\s*([\'"])(.*?)\1~i', $tag, $mm)) return $mm[2];
        return '';
    }, $html);
    $html = preg_replace('~<a\b[^>]*>(.*?)</a>~is', '$1', $html);
    $html = preg_replace('~<\s*br\s*/?\s*>~i', "\n", $html);
    $html = preg_replace('~</\s*p\s*>~i', "\n\n", $html);
    $html = preg_replace('~<\s*p\b[^>]*>~i', '', $html);
    $html = wp_strip_all_tags($html, true);
    $html = preg_replace('~[ \t]+\n~', "\n", $html);
    $html = preg_replace('~\n[ \t]+~', "\n", $html);
    $html = preg_replace('~\n{3,}~', "\n\n", trim($html));

    $parts = preg_split('~\n{2,}~', $html);
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') continue;
        $lines = preg_split('~\n+~', $p);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $out[] = '<p class="glpi-txt">' . esc_html($line) . '</p>';
        }
    }
    if (empty($out)) return '<div class="glpi-empty">Нет комментариев</div>';
    return implode("\n", $out);
}

/* -------- AJAX: загрузка комментариев тикета -------- */
add_action('wp_ajax_glpi_get_comments', 'gexe_glpi_get_comments');
add_action('wp_ajax_nopriv_glpi_get_comments', 'gexe_glpi_get_comments');

function gexe_render_comments($ticket_id) {
    if ($ticket_id <= 0) return '';

    // кэшируем HTML комментариев, чтобы ускорить повторные открытия карточки
    $cache_key = 'glpi_comments_' . $ticket_id;
    $cached    = get_transient($cache_key);
    if ($cached !== false) return $cached;

    global $glpi_db;
    $rows = $glpi_db->get_results($glpi_db->prepare(
        "SELECT f.id, f.users_id, f.date, f.content, u.realname, u.firstname
         FROM glpi_itilfollowups AS f
         LEFT JOIN glpi_users AS u ON u.id = f.users_id
         WHERE f.itemtype = 'Ticket'
           AND f.items_id = %d
         ORDER BY f.date ASC",
        $ticket_id
    ), ARRAY_A);

    if (!$rows) {
        $empty = '<div class="glpi-empty">Нет комментариев</div>';
        set_transient($cache_key, $empty, 60);
        return $empty;
    }

    $out = '';
    foreach ($rows as $r) {
        $when = esc_html($r['date']);
        $uid  = intval($r['users_id']);
        $txt  = gexe_clean_comment_html((string)$r['content']);
        $who  = gexe_compose_short_name($r['realname'] ?? '', $r['firstname'] ?? '');
        if ($who === '') $who = 'Автор ID ' . $uid;

        $out .= '<div class="glpi-comment">'
              .   '<div class="meta">'
              .     '<span class="glpi-comment-author"><i class="fa-regular fa-user"></i> ' . esc_html($who) . '</span>'
              .     '<span class="glpi-comment-date">' . $when . '</span>'
              .   '</div>'
              .   '<div class="text">' . $txt . '</div>'
              . '</div>';
    }

    set_transient($cache_key, $out, 60);
    return $out;
}
function gexe_glpi_get_comments() {
    check_ajax_referer('glpi_modal_actions');
    $ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;
    wp_die(gexe_render_comments($ticket_id));
}

/* -------- AJAX: количество комментариев -------- */
add_action('wp_ajax_glpi_count_comments', 'gexe_glpi_count_comments');
function gexe_glpi_count_comments() {
    check_ajax_referer('glpi_modal_actions');
    $ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;
    if ($ticket_id <= 0) wp_send_json(['count' => 0]);
    global $glpi_db;
    $n = (int)$glpi_db->get_var($glpi_db->prepare(
        "SELECT COUNT(*) FROM glpi_itilfollowups WHERE itemtype='Ticket' AND items_id=%d",
        $ticket_id
    ));
    wp_send_json(['count' => $n]);
}

/* -------- AJAX: проверка "Принято в работу" текущим исполнителем -------- */
add_action('wp_ajax_glpi_ticket_started_by_me', 'gexe_glpi_ticket_started_by_me');
function gexe_glpi_ticket_started_by_me() {
    check_ajax_referer('glpi_modal_actions');

    $ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;
    $glpi_uid  = gexe_get_current_glpi_uid();

    if ($ticket_id <= 0 || $glpi_uid <= 0) {
        wp_send_json(['ok' => true, 'started' => false]);
    }

    global $glpi_db;
    $like1 = '%' . $glpi_db->esc_like('Принято в работу') . '%';

    $started = $glpi_db->get_var($glpi_db->prepare(
        "SELECT 1
         FROM glpi_itilfollowups
         WHERE itemtype = 'Ticket'
           AND items_id = %d
           AND users_id = %d
           AND content LIKE %s
         LIMIT 1",
        $ticket_id, $glpi_uid, $like1
    )) ? true : false;

    wp_send_json(['ok' => true, 'started' => $started]);
}

/* -------- AJAX: действия по тикету (принять/закрыть) -------- */
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

    global $glpi_db;

    $tTickets   = 'glpi_tickets';
    $tFollowups = 'glpi_itilfollowups';
    $tSolutions = 'glpi_itilsolutions';

    $ok = false;
    $new_status = null;

    if ($type === 'start') {
        // Обновляем статус на "In progress"
        $new_status = 2;
        $ok = (false !== $glpi_db->update($tTickets, ['status' => $new_status], ['id' => $ticket_id], ['%d'], ['%d']));
        if ($ok) {
            // Комментарий "Принято в работу"
            $ok = (false !== $glpi_db->insert($tFollowups, [
                'itemtype'   => 'Ticket',
                'items_id'   => $ticket_id,
                'date'       => current_time('mysql'),
                'content'    => 'Принято в работу',
                'users_id'   => gexe_get_current_glpi_uid(),
                'is_private' => 0
            ], ['%s','%d','%s','%s','%d','%d']));
        }

    } elseif ($type === 'done') {
        // Решение — через таблицу glpi_itilsolutions (как в интерфейсе GLPI)
        $solution = isset($payload['solution_text']) ? trim((string)$payload['solution_text']) : '';
        if ($solution === '') $solution = 'Выполнено';

        $ok = (false !== $glpi_db->insert($tSolutions, [
            'itemtype'         => 'Ticket',
            'items_id'         => $ticket_id,
            'solutiontypes_id' => 0,
            'content'          => $solution,
            'date'             => current_time('mysql'),
            'users_id'         => gexe_get_current_glpi_uid(),
        ], ['%s','%d','%d','%s','%s','%d']));

        // Меняем статус тикета на «решён» (GLPI: 5)
        if ($ok) {
            $new_status = 5;
            $ok = (false !== $glpi_db->update($tTickets, ['status' => $new_status], ['id' => $ticket_id], ['%d'], ['%d']));
        }
    }

    $comment_html = '';
    if ($ok) { $comment_html = gexe_render_comments($ticket_id); }

    wp_send_json([
        'ok'           => (bool)$ok,
        'new_status'   => $new_status,
        'comment_html' => $comment_html
    ]);
}

/* -------- AJAX: добавить комментарий -------- */
add_action('wp_ajax_glpi_add_comment', 'gexe_glpi_add_comment');
function gexe_glpi_add_comment() {
    check_ajax_referer('glpi_modal_actions');

    $ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;
    $content   = isset($_POST['content']) ? (string) $_POST['content'] : '';

    $uid = gexe_get_current_glpi_uid();
    if ($ticket_id <= 0 || $uid <= 0 || trim($content) === '') {
        wp_send_json(['ok' => false, 'error' => 'bad_request']);
    }

    global $glpi_db;
    $tFollowups = 'glpi_itilfollowups';
    $ok = (false !== $glpi_db->insert($tFollowups, [
        'itemtype'   => 'Ticket',
        'items_id'   => $ticket_id,
        'date'       => current_time('mysql'),
        'content'    => $content,
        'users_id'   => $uid,
        'is_private' => 0
    ], ['%s','%d','%s','%s','%d','%d']));

    $count = 0;
    if ($ok) {
        $count = (int)$glpi_db->get_var($glpi_db->prepare(
            "SELECT COUNT(*) FROM glpi_itilfollowups WHERE itemtype='Ticket' AND items_id=%d",
            $ticket_id
        ));
        // очищаем кэш комментариев, чтобы при следующем открытии загрузились свежие данные
        delete_transient('glpi_comments_' . $ticket_id);
    }

    wp_send_json(['ok' => (bool)$ok, 'count' => $count]);
}
