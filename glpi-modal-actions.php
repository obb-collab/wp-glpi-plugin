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

function gexe_action_response($ok, $code, $ticket_id, $action, $msg = '', $extra = []) {
    $payload = array_merge([
        'ok'        => (bool) $ok,
        'code'      => (string) $code,
        'msg'       => (string) $msg,
        'ticket_id' => (int) $ticket_id,
        'action'    => (string) $action,
    ], $extra);
    if ($ok) {
        wp_send_json_success($payload, 200);
    } else {
        wp_send_json_error($payload, 200);
    }
}

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

/** Получить документы для набора followup_id */
function gexe_fetch_followup_documents(array $ids) {
    global $glpi_db;
    $ids = array_map('intval', array_filter($ids));
    if (empty($ids)) return [];
    $place = implode(',', array_fill(0, count($ids), '%d'));
    $sql = $glpi_db->prepare(
        "SELECT di.items_id AS fid, d.id AS docid, d.filename\n"
        . "FROM glpi_documents_items di\n"
        . "JOIN glpi_documents d ON d.id = di.documents_id\n"
        . "WHERE di.itemtype='ITILFollowup' AND di.items_id IN ($place)",
        ...$ids
    );
    $rows = $glpi_db->get_results($sql, ARRAY_A);
    $out = [];
    foreach ($rows as $r) {
        $fid = (int)($r['fid'] ?? 0);
        $ext = strtolower(pathinfo((string)$r['filename'], PATHINFO_EXTENSION));
        $out[$fid][] = [
            'document_id' => (int)($r['docid'] ?? 0),
            'extension'   => $ext,
        ];
    }
    return $out;
}

/** Сформировать HTML блока ссылок на документы */
function gexe_render_documents_block($ticket_id, array $docs) {
    if (empty($docs)) return '';
    $base = gexe_glpi_web_base();
    $cnt  = count($docs);
    $pref = $cnt > 1 ? 'Приложены документы: ' : 'Приложен документ: ';
    $links = [];
    foreach ($docs as $d) {
        $ext   = $d['extension'] ? ' ' . $d['extension'] : '';
        $label = 'документ' . $ext;
        $url   = $base . '/front/document.send.php?docid=' . $d['document_id'] . '&tickets_id=' . $ticket_id;
        $links[] = '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($label) . '</a>';
    }
    return '<p class="glpi-txt">' . $pref . implode(', ', $links) . '</p>';
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

    $doc_map = gexe_fetch_followup_documents(array_column($rows, 'id'));
    $out = '';
    foreach ($rows as $r) {
        $when = esc_html($r['date']);
        $uid  = intval($r['users_id']);
        $raw  = (string)$r['content'];
        $docs = $doc_map[(int)$r['id']] ?? [];
        if (trim($raw) === '' && $docs) {
            $txt = gexe_render_documents_block($ticket_id, $docs);
        } else {
            $txt = gexe_clean_comment_html($raw);
        }
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
        "SELECT f.id, f.users_id, f.items_id, f.date, f.content, u.realname, u.firstname"
         . " FROM glpi_itilfollowups AS f"
         . " LEFT JOIN glpi_users AS u ON u.id = f.users_id"
         . " WHERE f.id = %d",
        $id
    ), ARRAY_A);
    if (!$row) return null;
    $when = esc_html($row['date']);
    $uid  = intval($row['users_id']);
    $docs = gexe_fetch_followup_documents([$row['id']]);
    $raw  = (string)$row['content'];
    if (trim($raw) === '' && !empty($docs[$row['id']])) {
        $txt = gexe_render_documents_block((int)$row['items_id'], $docs[$row['id']]);
    } else {
        $txt = gexe_clean_comment_html($raw);
    }
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


add_action('wp_ajax_glpi_accept', 'gexe_glpi_accept');
function gexe_glpi_accept() {
    gexe_glpi_comment_add('accept', 'Принято в работу');
}

add_action('wp_ajax_glpi_change_status', 'gexe_glpi_change_status');
function gexe_glpi_change_status($legacy = false) {
    $ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;
    $status_id = isset($_POST['status']) ? intval($_POST['status']) : 0;
    $action    = $legacy ? 'resolve' : 'status';
    if (!check_ajax_referer('gexe_actions', 'nonce', false)) {
        gexe_action_response(false, 'csrf', $ticket_id, $action);
    }
    if (!is_user_logged_in()) {
        gexe_action_response(false, 'not_logged_in', $ticket_id, $action);
    }
    $glpi_uid = gexe_get_current_glpi_user_id(get_current_user_id());
    if ($glpi_uid <= 0) {
        gexe_action_response(false, 'not_mapped', $ticket_id, $action);
    }
    if ($ticket_id <= 0 || $status_id <= 0) {
        gexe_action_response(false, 'validation', $ticket_id, $action);
    }
    $map = gexe_glpi_status_map();
    if (!in_array($status_id, array_values($map), true)) {
        gexe_action_response(false, 'validation', $ticket_id, $action);
    }
    global $glpi_db;
    $exists = $glpi_db->get_var($glpi_db->prepare('SELECT status FROM glpi_tickets WHERE id=%d', $ticket_id));
    if ($exists === null) {
        gexe_action_response(false, 'not_found', $ticket_id, $action);
    }
    $texts = [
        $map['work']     => 'Статус: в работе',
        $map['planned']  => 'Статус: в плане',
        $map['onhold']   => 'Статус: в стопе',
        $map['resolved'] => 'Заявка решена',
    ];
    $followup = $texts[$status_id] ?? '';
    $res = sql_ticket_set_status($ticket_id, $glpi_uid, $status_id, $followup);
    if (!$res['ok']) {
        gexe_action_response(false, $res['code'] ?? 'sql_error', $ticket_id, $action, '', ['extra' => $res['extra'] ?? []]);
    }
    gexe_clear_comments_cache($ticket_id);
    gexe_action_response(true, 'ok', $ticket_id, $action, '', ['extra' => $res['extra'] ?? []]);
}

add_action('wp_ajax_glpi_resolve', 'gexe_glpi_resolve');
function gexe_glpi_resolve() {
    $_POST['status'] = gexe_glpi_status_map()['resolved'];
    gexe_glpi_change_status(true);
}

add_action('wp_ajax_gexe_refresh_actions_nonce', 'gexe_refresh_actions_nonce');
function gexe_refresh_actions_nonce() {
    if (!is_user_logged_in()) {
        gexe_ajax_error_compat('NO_PERMISSION', 'not_logged_in', [], 401);
    }
    if (gexe_get_current_glpi_user_id(get_current_user_id()) <= 0) {
        gexe_ajax_error_compat('NO_GLPI_USER', 'no_glpi_id', [], 422);
    }
    gexe_ajax_success_compat(['nonce' => wp_create_nonce('gexe_actions')]);
}

add_action('wp_ajax_gexe_check_mapping', 'gexe_check_mapping');
function gexe_check_mapping() {
    if (!check_ajax_referer('gexe_actions', 'nonce', false)) {
        gexe_ajax_error_compat('NONCE_EXPIRED', 'nonce_failed', [], 403);
    }
    if (!is_user_logged_in()) {
        gexe_ajax_error_compat('NO_PERMISSION', 'not_logged_in', [], 401);
    }

    $info = gexe_resolve_glpi_mapping(get_current_user_id());
    gexe_ajax_success_compat($info);
}

/* -------- AJAX: добавить комментарий -------- */
add_action('wp_ajax_glpi_comment_add', 'gexe_glpi_comment_add');
function gexe_glpi_comment_add($action = 'comment', $content_override = null) {
    $ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;
    if ($content_override !== null) {
        $content = sanitize_textarea_field((string) $content_override);
    } else {
        $content = isset($_POST['content']) ? sanitize_textarea_field((string) $_POST['content']) : '';
    }
    if (!check_ajax_referer('gexe_actions', 'nonce', false)) {
        gexe_action_response(false, 'csrf', $ticket_id, $action);
    }
    if (!is_user_logged_in()) {
        gexe_action_response(false, 'not_logged_in', $ticket_id, $action);
    }
    $glpi_uid = gexe_get_current_glpi_user_id(get_current_user_id());
    if ($glpi_uid <= 0) {
        gexe_action_response(false, 'not_mapped', $ticket_id, $action);
    }
    if ($ticket_id <= 0) {
        gexe_action_response(false, 'validation', $ticket_id, $action);
    }
    $content = trim($content);
    if ($content === '' || mb_strlen($content) > 2000) {
        gexe_action_response(false, 'validation', $ticket_id, $action);
    }
    global $glpi_db;
    $exists = $glpi_db->get_var($glpi_db->prepare('SELECT id FROM glpi_tickets WHERE id=%d', $ticket_id));
    if (!$exists) {
        gexe_action_response(false, 'not_found', $ticket_id, $action);
    }
    $glpi_db->query('START TRANSACTION');
    $has = $glpi_db->get_var($glpi_db->prepare(
        'SELECT 1 FROM glpi_tickets_users WHERE tickets_id=%d AND users_id=%d AND type IN (2) FOR UPDATE',
        $ticket_id,
        $glpi_uid
    ));
    if (!$has) {
        $glpi_db->query('ROLLBACK');
        gexe_action_response(false, 'no_rights', $ticket_id, $action);
    }
    $last = $glpi_db->get_row($glpi_db->prepare(
        "SELECT content, date FROM glpi_itilfollowups WHERE itemtype='Ticket' AND items_id=%d AND users_id=%d ORDER BY id DESC LIMIT 1",
        $ticket_id,
        $glpi_uid
    ), ARRAY_A);
    if ($last && trim((string) $last['content']) === $content) {
        $ts = strtotime($last['date']);
        if ($ts !== false && (time() - $ts) < 10) {
            $glpi_db->query('ROLLBACK');
            gexe_action_response(false, 'rate_limit_client', $ticket_id, $action);
        }
    }
    $res = sql_insert_followup($ticket_id, $glpi_uid, $content);
    if (!$res['ok']) {
        $glpi_db->query('ROLLBACK');
        gexe_action_response(false, $res['code'] ?? 'sql_error', $ticket_id, $action);
    }
    $glpi_db->query('COMMIT');
    gexe_clear_comments_cache($ticket_id);
    gexe_action_response(true, 'ok', $ticket_id, $action, '', ['extra' => ['followup' => $res['followup']]]);
}
