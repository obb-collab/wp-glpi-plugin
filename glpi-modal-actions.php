<?php
/**
 * GLPI Modal + Actions (standalone module)
 * Подключается из gexe-copy.php. Использует существующее соединение $glpi_db.
 * Поддержка AJAX для: загрузки комментариев, проверки "Принято в работу",
 * добавления комментария, действий "start/done", счетчика комментариев.
 */
require_once __DIR__ . '/glpi-utils.php';
require_once __DIR__ . '/includes/glpi-sql.php';
require_once __DIR__ . '/includes/glpi-auth-map.php';

add_action('wp_enqueue_scripts', function () {
    wp_localize_script('gexe-filter', 'glpiAjax', [
        'url'          => admin_url('admin-ajax.php'),
        'nonce'        => wp_create_nonce('gexe_actions'),
        'user_glpi_id' => gexe_get_current_glpi_uid(),
        'rest'         => esc_url_raw(rest_url('glpi/v1/')),
        'restNonce'    => wp_create_nonce('wp_rest'),
        'solvedStatus' => (int) get_option('glpi_solved_status', 6),
    ]);
});

/** Права: глобальные + назначенный исполнитель */
function gexe_can_touch_glpi_ticket($ticket_id) {
    if (!is_user_logged_in()) return false;

    $glpi_uid = gexe_get_current_glpi_user_id(get_current_user_id());
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
    global $glpi_db;
    $row = $glpi_db->get_row($glpi_db->prepare(
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
    wp_cache_set($key, $data, 'glpi', MINUTE_IN_SECONDS);

    $index_key = 'glpi_comments_keys_' . $ticket_id;
    $keys = wp_cache_get($index_key, 'glpi');
    if (!is_array($keys)) $keys = [];
    if (!in_array($key, $keys, true)) {
        $keys[] = $key;
        wp_cache_set($index_key, $keys, 'glpi', MINUTE_IN_SECONDS);
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

/** Получение followups_count и last_followup_at для тикета */
function gexe_get_ticket_meta($ticket_id) {
    global $glpi_db;
    $ticket_id = (int)$ticket_id;
    if ($ticket_id <= 0) {
        return ['followups_count' => 0, 'last_followup_at' => null];
    }
    if (gexe_glpi_use_followups_count()) {
        $row = $glpi_db->get_row($glpi_db->prepare(
            "SELECT followups_count, last_followup_at FROM glpi_tickets WHERE id=%d",
            $ticket_id
        ), ARRAY_A);
        return [
            'followups_count' => isset($row['followups_count']) ? (int)$row['followups_count'] : 0,
            'last_followup_at' => $row['last_followup_at'] ?? null,
        ];
    }
    $last = $glpi_db->get_var($glpi_db->prepare(
        "SELECT MAX(date) FROM glpi_itilfollowups WHERE itemtype='Ticket' AND items_id=%d",
        $ticket_id
    ));
    $cnt = $glpi_db->get_var($glpi_db->prepare(
        "SELECT COUNT(*) FROM glpi_itilfollowups WHERE itemtype='Ticket' AND items_id=%d",
        $ticket_id
    ));
    return [
        'followups_count' => (int)$cnt,
        'last_followup_at' => $last ?: null,
    ];
}

/** Получение количества комментариев с кэшированием */
function gexe_get_comment_count($ticket_id) {
    $key = gexe_comment_count_cache_key($ticket_id);
    $cached = wp_cache_get($key, 'glpi');
    if ($cached !== false) return (int)$cached;
    global $glpi_db;
    if (gexe_glpi_use_followups_count()) {
        $cnt = (int)$glpi_db->get_var($glpi_db->prepare(
            "SELECT followups_count FROM glpi_tickets WHERE id=%d",
            $ticket_id
        ));
    } else {
        $cnt = (int)$glpi_db->get_var($glpi_db->prepare(
            "SELECT COUNT(*) FROM glpi_itilfollowups WHERE itemtype='Ticket' AND items_id=%d",
            $ticket_id
        ));
    }
    wp_cache_set($key, $cnt, 'glpi', MINUTE_IN_SECONDS);
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

    global $glpi_db;
    $offset = ($page - 1) * $per_page;
    $t0   = microtime(true);
    $rows = $glpi_db->get_results($glpi_db->prepare(
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
    $count = $rows ? count($rows) : 0;
    gexe_log_action('[comments.load] ticket=' . $ticket_id . ' source=db elapsed=' . $elapsed_ms . 'ms count=' . $count);

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
        'count'     => $count,
        'signature' => $signature,
        'time_ms'   => $elapsed_ms,
    ];
    gexe_store_comments_cache($ticket_id, $page, $per_page, $data);
    return $data;
}

function gexe_glpi_get_comments() {
    check_ajax_referer('gexe_actions');
    $ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;
    $page      = isset($_POST['page']) ? intval($_POST['page']) : 1;
    $per_page  = isset($_POST['per_page']) ? intval($_POST['per_page']) : 20;
    wp_send_json(gexe_render_comments($ticket_id, $page, $per_page));
}

function gexe_render_followup($id) {
    global $glpi_db;
    $row = $glpi_db->get_row($glpi_db->prepare(
        "SELECT f.id, f.users_id, f.date, f.content, u.realname, u.firstname"
         . " FROM glpi_itilfollowups AS f"
         . " LEFT JOIN glpi_users AS u ON u.id = f.users_id"
         . " WHERE f.id = %d",
        $id
    ), ARRAY_A);
    if (!$row) return null;
    $when = esc_html($row['date']);
    $uid  = intval($row['users_id']);
    $txt  = gexe_clean_comment_html((string)$row['content']);
    $who  = gexe_compose_short_name($row['realname'] ?? '', $row['firstname'] ?? '');
    if ($who === '') $who = 'Автор ID ' . $uid;
    return [
        'html' => '<div class="meta">'
                . '<span class="glpi-comment-author"><i class="fa-regular fa-user"></i> ' . esc_html($who) . '</span>'
                . '<span class="glpi-comment-date" data-date="' . $when . '"></span>'
                . '</div><div class="text">' . $txt . '</div>',
    ];
}

/* -------- AJAX: мета тикета -------- */
add_action('wp_ajax_glpi_ticket_meta', 'gexe_glpi_ticket_meta');
add_action('wp_ajax_nopriv_glpi_ticket_meta', 'gexe_glpi_ticket_meta');
function gexe_glpi_ticket_meta() {
    check_ajax_referer('gexe_actions');
    $ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;
    wp_send_json_success(gexe_get_ticket_meta($ticket_id));
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
    register_rest_route('glpi/v1', '/followup', [
        'methods'  => 'GET',
        'callback' => function (WP_REST_Request $req) {
            $id = (int)$req->get_param('id');
            $data = gexe_render_followup($id);
            if (!$data) {
                return new WP_REST_Response(['error' => 'not_found'], 404);
            }
            return new WP_REST_Response($data);
        },
        'permission_callback' => '__return_true',
    ]);
});

/* -------- AJAX: количество комментариев для нескольких тикетов -------- */
add_action('wp_ajax_glpi_count_comments_batch', 'gexe_glpi_count_comments_batch');
function gexe_glpi_count_comments_batch() {
    check_ajax_referer('gexe_actions');

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
        global $glpi_db;
        $placeholders = implode(',', array_fill(0, count($missing), '%d'));
        if (gexe_glpi_use_followups_count()) {
            $sql = "SELECT id AS items_id, followups_count AS cnt FROM glpi_tickets WHERE id IN ($placeholders)";
        } else {
            $sql = "SELECT items_id, COUNT(*) AS cnt FROM glpi_itilfollowups WHERE itemtype='Ticket' AND items_id IN ($placeholders) GROUP BY items_id";
        }
        $rows = $glpi_db->get_results($glpi_db->prepare($sql, $missing), ARRAY_A);

        if ($rows) {
            foreach ($rows as $r) {
                $id  = (int)$r['items_id'];
                $cnt = (int)$r['cnt'];
                $out[$id] = $cnt;
                wp_cache_set(gexe_comment_count_cache_key($id), $cnt, 'glpi', MINUTE_IN_SECONDS);
            }
        }
        foreach ($missing as $id) {
            if (!isset($out[$id])) {
                $out[$id] = 0;
                wp_cache_set(gexe_comment_count_cache_key($id), 0, 'glpi', MINUTE_IN_SECONDS);
            }
        }
    }

    wp_send_json(['counts' => $out]);
}

/* -------- AJAX: проверка "Принято в работу" -------- */
add_action('wp_ajax_glpi_ticket_started', 'gexe_glpi_ticket_started');
function gexe_glpi_ticket_started() {
    check_ajax_referer('gexe_actions');

    $ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;

    if ($ticket_id <= 0) {
        wp_send_json_success(['started' => false]);
    }

    global $glpi_db;
    $like1 = '%' . $glpi_db->esc_like('Принято в работу') . '%';

    $started = $glpi_db->get_var($glpi_db->prepare(
        "SELECT 1
         FROM glpi_itilfollowups
         WHERE itemtype = 'Ticket'
           AND items_id = %d
           AND content LIKE %s
         LIMIT 1",
        $ticket_id, $like1
    )) ? true : false;

    wp_send_json_success(['started' => $started]);
}

/* -------- AJAX: действия по тикету (принять/закрыть) -------- */
add_action('wp_ajax_glpi_card_action', 'gexe_glpi_card_action');
function gexe_glpi_card_action() {
    check_ajax_referer('gexe_actions');

    $ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;
    $type      = isset($_POST['type']) ? sanitize_key($_POST['type']) : '';
    $action_id = isset($_POST['action_id']) ? sanitize_text_field($_POST['action_id']) : '';
    if ($ticket_id <= 0 || !$type) {
        wp_send_json_error(['message' => 'bad_request', 'action_id' => $action_id]);
    }
    if (!gexe_can_touch_glpi_ticket($ticket_id)) {
        wp_send_json_error(['message' => 'forbidden', 'action_id' => $action_id]);
    }

    if ($type === 'start') {
        $wp_uid   = get_current_user_id();
        $glpi_uid = (int) get_user_meta($wp_uid, 'glpi_users_id', true);
        if ($glpi_uid <= 0) {
            $glpi_uid = (int) get_option('default_glpi_users_id', 0);
        }
        if ($glpi_uid <= 0) {
            wp_send_json_error(['message' => 'user_mapping']);
        }

        $assign = gexe_glpi_rest_request('ticket_assign ' . $ticket_id, 'POST', '/Ticket_User/', [
            'input' => [
                'tickets_id' => $ticket_id,
                'users_id'   => $glpi_uid,
                'type'       => 2,
            ],
        ]);
        if (is_wp_error($assign)) {
            wp_send_json_error(['message' => 'network_error', 'action_id' => $action_id]);
        }
        $code = wp_remote_retrieve_response_code($assign);
        if ($code >= 300) {
            $body  = wp_remote_retrieve_body($assign);
            $short = mb_substr(trim($body), 0, 200);
            wp_send_json_error(['message' => $short, 'action_id' => $action_id]);
        }

        $status = gexe_glpi_rest_request('ticket_status ' . $ticket_id, 'PUT', '/Ticket/' . $ticket_id, [
            'input' => [
                'id'     => $ticket_id,
                'status' => 2,
            ],
        ]);
        if (is_wp_error($status)) {
            wp_send_json_error(['message' => 'network_error', 'action_id' => $action_id]);
        }
        $code = wp_remote_retrieve_response_code($status);
        if ($code >= 300) {
            $body  = wp_remote_retrieve_body($status);
            $short = mb_substr(trim($body), 0, 200);
            wp_send_json_error(['message' => $short, 'action_id' => $action_id]);
        }

        gexe_clear_comments_cache($ticket_id);
        wp_send_json_success(['action_id' => $action_id, 'refresh_meta' => true]);
    }

    wp_send_json_error(['message' => 'unknown_action', 'action_id' => $action_id]);
}


add_action('wp_ajax_glpi_ticket_accept_sql', 'gexe_glpi_ticket_accept_sql');
function gexe_glpi_ticket_accept_sql() {
    $wp_uid = get_current_user_id();
    if (!check_ajax_referer('gexe_actions', 'nonce', false)) {
        error_log('[accept] nonce_failed ticket=' . intval($_POST['ticket_id'] ?? 0) . ' wp=' . $wp_uid . ' glpi=0');
        wp_send_json(['error' => 'nonce_failed'], 403);
    }

    if (!is_user_logged_in()) {
        error_log('[accept] not_logged_in ticket=' . intval($_POST['ticket_id'] ?? 0) . ' wp=' . $wp_uid . ' glpi=0');
        wp_send_json(['error' => 'not_logged_in'], 401);
    }

    $author_glpi = gexe_get_current_glpi_user_id($wp_uid);
    if ($author_glpi <= 0) {
        error_log('[accept] no_glpi_id_for_current_user ticket=' . intval($_POST['ticket_id'] ?? 0) . ' wp=' . $wp_uid . ' glpi=0');
        wp_send_json(['error' => 'no_glpi_id_for_current_user'], 422);
    }

    $ticket_id   = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;
    $assignee    = isset($_POST['assignee_glpi_id']) ? intval($_POST['assignee_glpi_id']) : 0;
    $add_comment = isset($_POST['add_comment']) ? intval($_POST['add_comment']) : 1;
    if ($ticket_id <= 0) {
        error_log('[accept] ticket_not_found ticket=' . $ticket_id . ' wp=' . $wp_uid . ' glpi=' . $author_glpi);
        wp_send_json(['error' => 'ticket_not_found'], 404);
    }

    if ($assignee <= 0) {
        $assignee = $author_glpi;
    }
    if ($assignee <= 0) {
        error_log('[accept] no_glpi_id_for_current_user ticket=' . $ticket_id . ' wp=' . $wp_uid . ' glpi=' . $author_glpi);
        wp_send_json(['error' => 'no_glpi_id_for_current_user'], 422);
    }

    global $glpi_db;
    $start = microtime(true);

    $already_comment = $glpi_db->get_var($glpi_db->prepare(
        "SELECT 1 FROM glpi_itilfollowups WHERE itemtype='Ticket' AND items_id=%d AND content='Принято в работу' AND date > (NOW() - INTERVAL 5 MINUTE) LIMIT 1",
        $ticket_id
    )) ? true : false;

    $row = $glpi_db->get_row(
        $glpi_db->prepare('SELECT status, entities_id FROM glpi_tickets WHERE id=%d', $ticket_id),
        ARRAY_A
    );
    if (!$row) {
        error_log('[accept] ticket_not_found ticket=' . $ticket_id . ' wp=' . $wp_uid . ' glpi=' . $author_glpi);
        wp_send_json(['error' => 'ticket_not_found'], 404);
    }
    $entities_id = (int) $row['entities_id'];
    $status      = (int) $row['status'];
    $already_status = ($status === 2);

    $already_assignment = $glpi_db->get_var($glpi_db->prepare(
        'SELECT 1 FROM glpi_tickets_users WHERE tickets_id=%d AND users_id=%d AND type=2 LIMIT 1',
        $ticket_id, $assignee
    )) ? true : false;

    $glpi_db->query('START TRANSACTION');
    $followup_id = 0;
    $created_at  = date('c');

    if (!$already_assignment) {
        $sql = $glpi_db->prepare(
            'INSERT INTO glpi_tickets_users (tickets_id, users_id, type) VALUES (%d,%d,2)',
            $ticket_id, $assignee
        );
        if (!$glpi_db->query($sql)) {
            $err = $glpi_db->last_error;
            $glpi_db->query('ROLLBACK');
            $elapsed = (int) round((microtime(true) - $start) * 1000);
            gexe_log_action(sprintf('[accept.sql] ticket=%d assignee=%d followup=0 status=2 elapsed=%dms result=fail code=sql_error msg="%s"', $ticket_id, $assignee, $elapsed, $err));
            error_log('[accept] sql_error assignee_insert_failed ticket=' . $ticket_id . ' wp=' . $wp_uid . ' glpi=' . $author_glpi . ' sql=' . $err);
            wp_send_json(['error' => 'sql_error', 'details' => 'assignee_insert_failed'], 500);
        }
    }

    if ($add_comment && !$already_comment) {
        $res = gexe_add_followup_sql($ticket_id, 'Принято в работу', $assignee);
        if (!$res['ok']) {
            $glpi_db->query('ROLLBACK');
            if (($res['code'] ?? '') === 'SQL_ERROR') {
                gexe_log_action(sprintf('[accept.sql] ticket=%d assignee=%d followup=0 status=2 result=fail code=sql_error msg="%s"', $ticket_id, $assignee, $res['message'] ?? ''));
                error_log('[accept] sql_error followup_insert_failed ticket=' . $ticket_id . ' wp=' . $wp_uid . ' glpi=' . $author_glpi . ' sql=' . ($res['message'] ?? ''));
                wp_send_json(['error' => 'sql_error', 'details' => 'followup_insert_failed'], 500);
            }
            error_log('[accept] ' . ($res['code'] ?? 'error') . ' ticket=' . $ticket_id . ' wp=' . $wp_uid . ' glpi=' . $author_glpi);
            wp_send_json(['error' => $res['code'] ?? 'error'], 422);
        }
        $followup_id = (int) ($res['followup_id'] ?? 0);
    }

    if (!$already_status) {
        $sql = $glpi_db->prepare('UPDATE glpi_tickets SET status=2, date_mod=NOW() WHERE id=%d', $ticket_id);
        if (!$glpi_db->query($sql)) {
            $err = $glpi_db->last_error;
            $glpi_db->query('ROLLBACK');
            $elapsed = (int) round((microtime(true) - $start) * 1000);
            gexe_log_action(sprintf('[accept.sql] ticket=%d assignee=%d followup=%d status=2 elapsed=%dms result=fail code=sql_error msg="%s"', $ticket_id, $assignee, $followup_id, $elapsed, $err));
            error_log('[accept] sql_error status_update_failed ticket=' . $ticket_id . ' wp=' . $wp_uid . ' glpi=' . $author_glpi . ' sql=' . $err);
            wp_send_json(['error' => 'sql_error', 'details' => 'status_update_failed'], 500);
        }
    }

    $glpi_db->query('COMMIT');
    gexe_clear_comments_cache($ticket_id);
    $elapsed = (int) round((microtime(true) - $start) * 1000);
    gexe_log_action(sprintf('[accept.sql] ticket=%d assignee=%d followup=%d status=2 elapsed=%dms result=ok', $ticket_id, $assignee, $followup_id, $elapsed));

    $payload = [
        'ticket_id'        => $ticket_id,
        'assigned_glpi_id' => $assignee,
        'status'           => 2,
    ];
    if ($followup_id > 0) {
        $payload['followup'] = [
            'id'       => $followup_id,
            'content'  => 'Принято в работу',
            'date'     => $created_at,
            'users_id' => $assignee,
        ];
    }
    if ($already_comment || $already_status) {
        $payload['already'] = true;
    }
    wp_send_json(['ok' => true, 'payload' => $payload]);
}

add_action('wp_ajax_gexe_refresh_actions_nonce', 'gexe_refresh_actions_nonce');
function gexe_refresh_actions_nonce() {
    if (!is_user_logged_in()) {
        gexe_log_action('[actions.nonce] result=fail code=not_logged_in');
        wp_send_json(['error' => 'not_logged_in'], 401);
    }
    if (gexe_get_current_glpi_user_id(get_current_user_id()) <= 0) {
        gexe_log_action('[actions.nonce] result=fail code=no_glpi_id');
        wp_send_json(['error' => 'no_glpi_id_for_current_user'], 422);
    }
    wp_send_json_success(['nonce' => wp_create_nonce('gexe_actions')]);
}

/* -------- AJAX: добавить комментарий -------- */
add_action('wp_ajax_glpi_comment_add', 'gexe_glpi_comment_add');
function gexe_glpi_comment_add() {
    $wp_uid = get_current_user_id();
    if (!check_ajax_referer('gexe_actions', 'nonce', false)) {
        error_log('[comment] nonce_failed ticket=' . intval($_POST['ticket_id'] ?? 0) . ' wp=' . $wp_uid . ' glpi=0');
        wp_send_json(['error' => 'nonce_failed'], 403);
    }
    if (!is_user_logged_in()) {
        error_log('[comment] not_logged_in ticket=' . intval($_POST['ticket_id'] ?? 0) . ' wp=' . $wp_uid . ' glpi=0');
        wp_send_json(['error' => 'not_logged_in'], 401);
    }
    $author_glpi = gexe_get_current_glpi_user_id($wp_uid);
    if ($author_glpi <= 0) {
        error_log('[comment] no_glpi_id_for_current_user ticket=' . intval($_POST['ticket_id'] ?? 0) . ' wp=' . $wp_uid . ' glpi=0');
        wp_send_json(['error' => 'no_glpi_id_for_current_user'], 422);
    }

    $ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;
    $content   = isset($_POST['content']) ? sanitize_textarea_field((string) $_POST['content']) : '';
    if ($ticket_id <= 0) {
        error_log('[comment] ticket_not_found ticket=' . $ticket_id . ' wp=' . $wp_uid . ' glpi=' . $author_glpi);
        wp_send_json(['error' => 'ticket_not_found'], 404);
    }
    if ($content === '') {
        error_log('[comment] empty_comment ticket=' . $ticket_id . ' wp=' . $wp_uid . ' glpi=' . $author_glpi);
        wp_send_json(['error' => 'empty_comment'], 422);
    }

    global $glpi_db;
    $exists = $glpi_db->get_var($glpi_db->prepare('SELECT 1 FROM glpi_tickets WHERE id=%d', $ticket_id));
    if (!$exists) {
        error_log('[comment] ticket_not_found ticket=' . $ticket_id . ' wp=' . $wp_uid . ' glpi=' . $author_glpi);
        wp_send_json(['error' => 'ticket_not_found'], 404);
    }

    $res = gexe_add_followup_sql($ticket_id, $content, $author_glpi);
    if (!$res['ok']) {
        if (($res['code'] ?? '') === 'SQL_ERROR') {
            gexe_log_action(sprintf('[comment.sql] ticket=%d author=%d result=fail code=sql_error msg="%s"', $ticket_id, $author_glpi, $res['message'] ?? ''));
            error_log('[comment] sql_error followup_insert_failed ticket=' . $ticket_id . ' wp=' . $wp_uid . ' glpi=' . $author_glpi . ' sql=' . ($res['message'] ?? ''));
            wp_send_json(['error' => 'sql_error', 'details' => 'followup_insert_failed'], 500);
        }
        error_log('[comment] ' . ($res['code'] ?? 'error') . ' ticket=' . $ticket_id . ' wp=' . $wp_uid . ' glpi=' . $author_glpi);
        wp_send_json(['error' => $res['code'] ?? 'error'], 422);
    }

    gexe_clear_comments_cache($ticket_id);
    $followup = [
        'id'       => (int) ($res['followup_id'] ?? 0),
        'items_id' => $ticket_id,
        'users_id' => $author_glpi,
        'content'  => wp_kses_post($content),
        'date'     => date('c'),
    ];
    gexe_log_action(sprintf('[comment.sql] ticket=%d author=%d followup=%d result=ok', $ticket_id, $author_glpi, $followup['id']));
    wp_send_json(['ok' => true, 'payload' => ['ticket_id' => $ticket_id, 'followup' => $followup]]);
}
