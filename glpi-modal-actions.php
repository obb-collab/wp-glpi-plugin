<?php
/**
 * GLPI Modal + Actions (standalone module)
 * Подключается из gexe-copy.php. Использует gexe_glpi_db() для соединения.
 * Поддержка AJAX для: загрузки комментариев, проверки "Принято в работу",
 * добавления комментария, действий "start/done", счетчика комментариев.
 */
require_once __DIR__ . '/glpi-utils.php';
global $glpi_db, $glpi_db_ro;
$glpi_db    = gexe_glpi_db();      // master
$glpi_db_ro = gexe_glpi_db('ro');  // replica for reads

add_action('wp_enqueue_scripts', function () {
    wp_localize_script('gexe-filter', 'glpiAjax', [
        'url'          => admin_url('admin-ajax.php'),
        'nonce'        => wp_create_nonce('glpi_modal_actions'),
        'user_glpi_id' => gexe_get_current_glpi_uid(),
        'rest'         => esc_url_raw(rest_url('glpi/v1/')),
        'restNonce'    => wp_create_nonce('wp_rest'),
    ]);
});

/** Права: глобальные + назначенный исполнитель */
function gexe_can_touch_glpi_ticket($ticket_id) {
    if (!is_user_logged_in()) return false;

    $glpi_uid = gexe_get_current_glpi_uid();
    if ($glpi_uid <= 0) return false;

    global $glpi_db_ro;

    // Глобальные права (UPDATE)
    $has_right = $glpi_db_ro->get_var($glpi_db_ro->prepare(
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
    $is_assignee = $glpi_db_ro->get_var($glpi_db_ro->prepare(
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
    // Стараемся использовать встроенные функции вместо множества регулярок
    $html = wp_strip_all_tags($html);
    $lines = array_filter(array_map('trim', explode("\n", $html)));
    $out = [];
    foreach ($lines as $line) {
        $out[] = '<p class="glpi-txt">' . esc_html($line) . '</p>';
    }
    if (empty($out)) return '<div class="glpi-empty">Нет комментариев</div>';
    return implode("\n", $out);
}

/**
 * Получить данные для проверки актуальности кэша комментариев.
 * Используем статус тикета и дату последнего комментария.
 */
function gexe_get_ticket_comments_signature($ticket_id) {
    global $glpi_db_ro;
    $row = $glpi_db_ro->get_row($glpi_db_ro->prepare(
        "SELECT status, last_followup_at AS last_comment FROM glpi_tickets WHERE id = %d",
        $ticket_id
    ), ARRAY_A);
    return [
        'status'       => isset($row['status']) ? (int)$row['status'] : 0,
        'last_comment' => $row['last_comment'] ?? null,
    ];
}

/** Построение ключа кэша комментариев */
function gexe_comments_cache_key($ticket_id, $page, $per_page) {
    return 'glpi_comments_' . $ticket_id . '_' . $page . '_' . $per_page;
}

/** Сохранение HTML комментариев в кэш и ведение индекса ключей */
function gexe_store_comments_cache($ticket_id, $page, $per_page, $data) {
    $key = gexe_comments_cache_key($ticket_id, $page, $per_page);
    wp_cache_set($key, $data, 'glpi', DAY_IN_SECONDS);

    $index_key = 'glpi_comments_keys_' . $ticket_id;
    $keys = wp_cache_get($index_key, 'glpi');
    if (!is_array($keys)) $keys = [];
    if (!in_array($key, $keys, true)) {
        $keys[] = $key;
        wp_cache_set($index_key, $keys, 'glpi', DAY_IN_SECONDS);
    }
}

/** Очистка кэша комментариев конкретного тикета */
function gexe_clear_comments_cache($ticket_id) {
    $index_key = 'glpi_comments_keys_' . $ticket_id;
    $keys = wp_cache_get($index_key, 'glpi');
    if (is_array($keys)) {
        foreach ($keys as $k) {
            wp_cache_delete($k, 'glpi');
        }
        wp_cache_delete($index_key, 'glpi');
    }
    gexe_clear_comment_count_cache($ticket_id);
}

/** Построение ключа кэша количества комментариев */
function gexe_comment_count_cache_key($ticket_id) {
    return 'glpi_comment_count_' . $ticket_id;
}

/** Очистка кэша количества комментариев */
function gexe_clear_comment_count_cache($ticket_id) {
    wp_cache_delete(gexe_comment_count_cache_key($ticket_id), 'glpi');
}

/** Получение количества комментариев с кэшированием */
function gexe_get_comment_count($ticket_id) {
    $key = gexe_comment_count_cache_key($ticket_id);
    $cached = wp_cache_get($key, 'glpi');
    if ($cached !== false) return (int)$cached;
    global $glpi_db_ro;
    $cnt = (int)$glpi_db_ro->get_var($glpi_db_ro->prepare(
        "SELECT COUNT(*) FROM glpi_itilfollowups WHERE itemtype='Ticket' AND items_id=%d",
        $ticket_id
    ));
    wp_cache_set($key, $cnt, 'glpi', DAY_IN_SECONDS);
    return $cnt;
}

/** Прогрев кэша комментариев популярных тикетов */
function gexe_warm_comments_cache() {
    global $glpi_db;
    $ids = $glpi_db->get_col("SELECT id FROM glpi_tickets WHERE status IN (1,2,3,4) ORDER BY date DESC LIMIT 5");
    if ($ids) {
        foreach ($ids as $id) {
            gexe_render_comments((int)$id, 1, 20);
        }
    }
}
add_action('gexe_warm_comments_cache', 'gexe_warm_comments_cache');

/* -------- AJAX: загрузка комментариев тикета -------- */
add_action('wp_ajax_glpi_get_comments', 'gexe_glpi_get_comments');
add_action('wp_ajax_nopriv_glpi_get_comments', 'gexe_glpi_get_comments');


function gexe_render_comments($ticket_id, $page = 1, $per_page = 20) {
    if ($ticket_id <= 0) return ['html' => '', 'count' => 0];
    $page = max(1, (int)$page);
    $per_page = max(1, (int)$per_page);

    $signature = gexe_get_ticket_comments_signature($ticket_id);
    $cache_key = gexe_comments_cache_key($ticket_id, $page, $per_page);
    $cached    = wp_cache_get($cache_key, 'glpi');

    if (is_array($cached) && isset($cached['html'], $cached['signature']) && $cached['signature'] === $signature) {
        $cached['time_ms'] = 0;
        return $cached;
    }

    global $glpi_db_ro;
    $offset = ($page - 1) * $per_page;
    $t0   = microtime(true);
    $rows = $glpi_db_ro->get_results($glpi_db_ro->prepare(
        "SELECT f.id, f.users_id, f.date, f.content, u.realname, u.firstname"
         . " FROM glpi_itilfollowups AS f"
         . " LEFT JOIN glpi_users AS u ON u.id = f.users_id"
         . " WHERE f.itemtype = 'Ticket'"
         . "   AND f.items_id = %d"
         . " ORDER BY f.date DESC"
         . " LIMIT %d OFFSET %d",
        $ticket_id, $per_page, $offset
    ), ARRAY_A);
    $elapsed_ms = (int)round((microtime(true) - $t0) * 1000);

    if (!$rows) {
        $empty = '<div class="glpi-empty">Нет комментариев</div>';
        $data = [
            'html'      => $empty,
            'count'     => 0,
            'signature' => $signature,
            'time_ms'   => $elapsed_ms,
        ];
        gexe_store_comments_cache($ticket_id, $page, $per_page, $data);
        return $data;
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
              .     '<span class="glpi-comment-date" data-date="' . $when . '"></span>'
              .   '</div>'
              .   '<div class="text">' . $txt . '</div>'
              . '</div>';
    }

    $data = [
        'html'      => $out,
        'count'     => count($rows),
        'signature' => $signature,
        'time_ms'   => $elapsed_ms,
    ];
    gexe_store_comments_cache($ticket_id, $page, $per_page, $data);
    return $data;
}

function gexe_glpi_get_comments() {
    check_ajax_referer('glpi_modal_actions');
    $ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;
    $page      = isset($_POST['page']) ? intval($_POST['page']) : 1;
    $per_page  = isset($_POST['per_page']) ? intval($_POST['per_page']) : 20;
    wp_send_json(gexe_render_comments($ticket_id, $page, $per_page));
}

add_action('rest_api_init', function () {
    register_rest_route('glpi/v1', '/comments', [
        'methods'  => 'GET',
        'callback' => function (WP_REST_Request $req) {
            $ticket_id = (int)$req->get_param('ticket_id');
            $page      = (int)$req->get_param('page') ?: 1;
            $per_page  = (int)$req->get_param('per_page') ?: 20;
            return new WP_REST_Response(gexe_render_comments($ticket_id, $page, $per_page));
        },
        'permission_callback' => '__return_true',
    ]);
});

/* -------- AJAX: количество комментариев для нескольких тикетов -------- */
add_action('wp_ajax_glpi_count_comments_batch', 'gexe_glpi_count_comments_batch');
function gexe_glpi_count_comments_batch() {
    check_ajax_referer('glpi_modal_actions');

    $ids_raw = isset($_POST['ticket_ids']) ? (string)$_POST['ticket_ids'] : '';
    $ids = array_filter(array_map('intval', explode(',', $ids_raw)));
    if (empty($ids)) {
        wp_send_json(['counts' => []]);
    }

    $out = [];
    $missing = [];
    foreach ($ids as $id) {
        $key = gexe_comment_count_cache_key($id);
        $cached = wp_cache_get($key, 'glpi');
        if ($cached !== false) {
            $out[$id] = (int)$cached;
        } else {
            $missing[] = $id;
        }
    }

    if ($missing) {
        global $glpi_db_ro;
        $placeholders = implode(',', array_fill(0, count($missing), '%d'));
        $sql = "SELECT items_id, COUNT(*) AS cnt FROM glpi_itilfollowups "
             . "WHERE itemtype='Ticket' AND items_id IN ($placeholders) GROUP BY items_id";
        $rows = $glpi_db_ro->get_results($glpi_db_ro->prepare($sql, $missing), ARRAY_A);

        if ($rows) {
            foreach ($rows as $r) {
                $id  = (int)$r['items_id'];
                $cnt = (int)$r['cnt'];
                $out[$id] = $cnt;
                wp_cache_set(gexe_comment_count_cache_key($id), $cnt, 'glpi', DAY_IN_SECONDS);
            }
        }
        foreach ($missing as $id) {
            if (!isset($out[$id])) {
                $out[$id] = 0;
                wp_cache_set(gexe_comment_count_cache_key($id), 0, 'glpi', DAY_IN_SECONDS);
            }
        }
    }

    wp_send_json(['counts' => $out]);
}

/* -------- AJAX: проверка "Принято в работу" -------- */
add_action('wp_ajax_glpi_ticket_started', 'gexe_glpi_ticket_started');
function gexe_glpi_ticket_started() {
    check_ajax_referer('glpi_modal_actions');

    $ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;

    if ($ticket_id <= 0) {
        wp_send_json(['ok' => true, 'started' => false]);
    }

    global $glpi_db_ro;
    $like1 = '%' . $glpi_db_ro->esc_like('Принято в работу') . '%';

    $started = $glpi_db_ro->get_var($glpi_db_ro->prepare(
        "SELECT 1
         FROM glpi_itilfollowups
         WHERE itemtype = 'Ticket'
           AND items_id = %d
           AND content LIKE %s
         LIMIT 1",
        $ticket_id, $like1
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
            $now = current_time('mysql');
            // Комментарий "Принято в работу"
            $ok = (false !== $glpi_db->insert($tFollowups, [
                'itemtype'   => 'Ticket',
                'items_id'   => $ticket_id,
                'date'       => $now,
                'content'    => 'Принято в работу',
                'users_id'   => gexe_get_current_glpi_uid(),
                'is_private' => 0
            ], ['%s','%d','%s','%s','%d','%d']));
            if ($ok) {
                $glpi_db->update($tTickets, ['last_followup_at' => $now], ['id' => $ticket_id], ['%s'], ['%d']);
            }
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

    $comment_html  = '';
    $comment_count = 0;
    if ($ok) {
        gexe_clear_comments_cache($ticket_id);
        $data = gexe_render_comments($ticket_id);
        $comment_html  = $data['html'];
        $comment_count = $data['count'];
    }

    wp_send_json([
        'ok'           => (bool)$ok,
        'new_status'   => $new_status,
        'comment_html' => $comment_html,
        'comment_count'=> $comment_count,
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
    $now = current_time('mysql');
    $ok = (false !== $glpi_db->insert($tFollowups, [
        'itemtype'   => 'Ticket',
        'items_id'   => $ticket_id,
        'date'       => $now,
        'content'    => $content,
        'users_id'   => $uid,
        'is_private' => 0
    ], ['%s','%d','%s','%s','%d','%d']));

    $count = 0;
    if ($ok) {
        $glpi_db->update('glpi_tickets', ['last_followup_at' => $now], ['id' => $ticket_id], ['%s'], ['%d']);
        gexe_clear_comments_cache($ticket_id);
        $count = gexe_get_comment_count($ticket_id);
    }

    wp_send_json(['ok' => (bool)$ok, 'count' => $count]);
}
