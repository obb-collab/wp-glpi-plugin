<?php
/**
 * GLPI Modal + Actions (cache-only version)
 * Provides AJAX endpoints for reading cached comments and counts.
 */
require_once __DIR__ . '/glpi-utils.php';

add_action('wp_enqueue_scripts', function () {
    wp_localize_script('gexe-filter', 'glpiAjax', [
        'url'   => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('glpi_modal_actions'),
        'user_glpi_id' => gexe_get_current_glpi_uid(),
    ]);
});

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

/** Получение количества комментариев из кэша */
function gexe_get_comment_count($ticket_id) {
    $key = gexe_comment_count_cache_key($ticket_id);
    $cached = wp_cache_get($key, 'glpi');
    if ($cached !== false) return (int)$cached;
    return 0;
}

/** Рендер комментариев из кэша */
function gexe_render_comments($ticket_id, $page = 1, $per_page = 20) {
    if ($ticket_id <= 0) return ['html' => '', 'count' => 0, 'time_ms' => 0];
    $cache_key = gexe_comments_cache_key($ticket_id, $page, $per_page);
    $cached = wp_cache_get($cache_key, 'glpi');
    if (is_array($cached)) {
        $cached['time_ms'] = 0;
        return $cached;
    }
    return [
        'html'    => '<div class="glpi-empty">Нет данных</div>',
        'count'   => 0,
        'time_ms' => 0,
    ];
}

/* -------- AJAX: загрузка комментариев тикета -------- */
add_action('wp_ajax_glpi_get_comments', 'gexe_glpi_get_comments');
add_action('wp_ajax_nopriv_glpi_get_comments', 'gexe_glpi_get_comments');

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
    foreach ($ids as $id) {
        $key = gexe_comment_count_cache_key($id);
        $cached = wp_cache_get($key, 'glpi');
        $out[$id] = ($cached !== false) ? (int)$cached : 0;
    }

    wp_send_json(['counts' => $out]);
}

?>
