<?php
/**
 * BAGE (изолированная страница карточек под новую модалку).
 * - Полный клон: свой шаблон, свой JS, свой CSS.
 * - Никаких подключений старых шорткодов/скриптов.
 * - Данные читаем через SQL (видимость: только «свои» заявки), изменения — через SQL, затем пингуем API триггеры.
 */
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../common/helpers.php';
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/notify-api.php';
require_once __DIR__ . '/../modal/ticket-modal.php';

// Шорткод [glpi_cards_new]
// Рендерит контейнер карточек и инициализирует изолированные ассеты.
function gexe_new_bage_shortcode($atts = []) {
    if (!is_user_logged_in()) {
        return '<div class="gexe-bage gexe-bage--error">Требуется авторизация.</div>';
    }
    ob_start();
    require __DIR__ . '/bage-template.php';
    // Контейнер модалки рендерится скрытым (изолированная версия)
    echo gexe_nm_render_modal_container();
    return ob_get_clean();
}
add_shortcode('glpi_cards_new', 'gexe_new_bage_shortcode');

// Подключение ассетов только на страницах, где присутствует шорткод.
function gexe_new_bage_maybe_enqueue_assets() {
    if (!gexe_nm_is_shortcode_present('glpi_cards_new')) return;
    // CSS
    wp_enqueue_style('gexe-bage-css', plugins_url('bage.css', __FILE__), [], '1.0.1');
    wp_enqueue_style('gexe-newmodal-css', plugins_url('../newmodal.css', __FILE__), [], '1.0.1');
    wp_enqueue_style('gexe-newmodal-modal-css', plugins_url('../modal/modal.css', __FILE__), [], '1.0.1');

    // JS
    wp_enqueue_script('gexe-bage-js', plugins_url('bage.js', __FILE__), ['jquery'], '1.0.1', true);
    wp_enqueue_script('gexe-newmodal-js', plugins_url('../newmodal.js', __FILE__), ['jquery'], '1.0.1', true);
    wp_enqueue_script('gexe-newmodal-modal-js', plugins_url('../modal/modal.js', __FILE__), ['jquery'], '1.0.1', true);

    // Рантайм-параметры и nonce
    wp_localize_script('gexe-bage-js', 'gexeNewBage', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('gexe_nm'),
        'i18n'    => [
            'loadError' => 'Ошибка загрузки заявок',
            'unauth'    => 'Нет прав доступа',
        ],
    ]);
    wp_localize_script('gexe-newmodal-modal-js', 'gexeNm', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('gexe_nm'),
        'i18n'    => [
            'commentError' => 'Не удалось отправить комментарий',
            'statusError'  => 'Не удалось изменить статус',
            'assignError'  => 'Не удалось назначить исполнителя',
        ],
    ]);
}
add_action('wp_enqueue_scripts', 'gexe_new_bage_maybe_enqueue_assets');

// ============ AJAX (SQL-операции + API-пинг) ============
// Добавление комментария
function gexe_nm_ajax_add_comment() {
    try {
        gexe_nm_check_nonce();
        $ticket_id = isset($_POST['ticket_id']) ? (int) $_POST['ticket_id'] : 0;
        $text      = isset($_POST['text']) ? wp_unslash((string)$_POST['text']) : '';
        if ($ticket_id <= 0 || $text === '') {
            return gexe_nm_json(false, 'bad_request', 'Некорректные параметры', []);
        }
        $res = nm_sql_add_followup($ticket_id, $text, null);
        if (!$res['ok']) {
            return gexe_nm_json(false, $res['code'] ?? 'sql_error', $res['message'] ?? 'Ошибка SQL', []);
        }
        nm_api_trigger_notifications(); // пингуем триггеры
        return gexe_nm_json(true, 'ok', 'Комментарий добавлен', ['followup_id' => $res['followup_id'] ?? 0]);
    } catch (Throwable $e) {
        return gexe_nm_json(false, 'exception', $e->getMessage(), []);
    }
}
add_action('wp_ajax_gexe_nm_add_comment', 'gexe_nm_ajax_add_comment');

// Смена статуса
function gexe_nm_ajax_change_status() {
    try {
        gexe_nm_check_nonce();
        $ticket_id = isset($_POST['ticket_id']) ? (int) $_POST['ticket_id'] : 0;
        $status    = isset($_POST['status']) ? (int) $_POST['status'] : 0;
        if ($ticket_id <= 0 || $status <= 0) {
            return gexe_nm_json(false, 'bad_request', 'Некорректные параметры', []);
        }
        $res = nm_sql_update_status($ticket_id, $status);
        if (!$res['ok']) {
            return gexe_nm_json(false, $res['code'] ?? 'sql_error', $res['message'] ?? 'Ошибка SQL', []);
        }
        nm_api_trigger_notifications();
        return gexe_nm_json(true, 'ok', 'Статус обновлён', []);
    } catch (Throwable $e) {
        return gexe_nm_json(false, 'exception', $e->getMessage(), []);
    }
}
add_action('wp_ajax_gexe_nm_change_status', 'gexe_nm_ajax_change_status');

// Назначение исполнителя
function gexe_nm_ajax_assign_user() {
    try {
        gexe_nm_check_nonce();
        $ticket_id = isset($_POST['ticket_id']) ? (int) $_POST['ticket_id'] : 0;
        $assignee  = isset($_POST['assignee']) ? (int) $_POST['assignee'] : 0;
        if ($ticket_id <= 0 || $assignee <= 0) {
            return gexe_nm_json(false, 'bad_request', 'Некорректные параметры', []);
        }
        $res = nm_sql_assign_user($ticket_id, $assignee);
        if (!$res['ok']) {
            return gexe_nm_json(false, $res['code'] ?? 'sql_error', $res['message'] ?? 'Ошибка SQL', []);
        }
        nm_api_trigger_notifications();
        return gexe_nm_json(true, 'ok', 'Исполнитель назначен', []);
    } catch (Throwable $e) {
        return gexe_nm_json(false, 'exception', $e->getMessage(), []);
    }
}
add_action('wp_ajax_gexe_nm_assign_user', 'gexe_nm_ajax_assign_user');

// Создание заявки (форма "Новая заявка" из модуля new-ticket)
function gexe_nm_ajax_create_ticket() {
    try {
        gexe_nm_check_nonce();
        $payload = [
            'name'        => isset($_POST['name']) ? wp_unslash((string)$_POST['name']) : '',
            'content'     => isset($_POST['content']) ? wp_unslash((string)$_POST['content']) : '',
            'category'    => isset($_POST['category']) ? (int) $_POST['category'] : 0,
            'location'    => isset($_POST['location']) ? (int) $_POST['location'] : 0,
            'due'         => isset($_POST['due']) ? wp_unslash((string)$_POST['due']) : '',
            'assignee'    => isset($_POST['assignee']) ? (int) $_POST['assignee'] : 0,
        ];
        $res = nm_sql_create_ticket($payload);
        if (!$res['ok']) {
            return gexe_nm_json(false, $res['code'] ?? 'sql_error', $res['message'] ?? 'Ошибка SQL', []);
        }
        nm_api_trigger_notifications();
        return gexe_nm_json(true, 'ok', 'Заявка создана', ['ticket_id' => $res['ticket_id'] ?? 0]);
    } catch (Throwable $e) {
        return gexe_nm_json(false, 'exception', $e->getMessage(), []);
    }
}
add_action('wp_ajax_gexe_nm_create_ticket', 'gexe_nm_ajax_create_ticket');

// Чтение списка карточек (SQL, только «свои» заявки текущего исполнителя)
function gexe_nm_ajax_list_tickets() {
    try {
        gexe_nm_check_nonce();
        $page   = isset($_REQUEST['page']) ? max(1, (int) $_REQUEST['page']) : 1;
        $query  = isset($_REQUEST['q']) ? wp_unslash((string)$_REQUEST['q']) : '';
        $limit  = 25;
        $offset = ($page - 1) * $limit;

        $items = nm_sql_list_tickets_for_current($limit, $offset, $query);
        return wp_send_json(['ok' => true, 'items' => $items, 'code' => 'ok', 'message' => '']);
    } catch (Throwable $e) {
        return wp_send_json(['ok' => false, 'code' => 'exception', 'message' => $e->getMessage()]);
    }
}
add_action('wp_ajax_gexe_nm_list_tickets', 'gexe_nm_ajax_list_tickets');

// ============ Конец AJAX ============

// Безопасное отключение старых хуков/ассетов на страницах «клона» не требуется —
// мы не подключаем ничего лишнего. Главное — не инклюдить старые файлы отсюда.

// Фолбек для неавторизованных (всегда JSON с ошибкой)
add_action('wp_ajax_nopriv_gexe_nm_add_comment', function(){ wp_send_json(['ok'=>false,'code'=>'unauth','message'=>'Требуется авторизация']); });
add_action('wp_ajax_nopriv_gexe_nm_change_status', function(){ wp_send_json(['ok'=>false,'code'=>'unauth','message'=>'Требуется авторизация']); });
add_action('wp_ajax_nopriv_gexe_nm_assign_user', function(){ wp_send_json(['ok'=>false,'code'=>'unauth','message'=>'Требуется авторизация']); });
add_action('wp_ajax_nopriv_gexe_nm_create_ticket', function(){ wp_send_json(['ok'=>false,'code'=>'unauth','message'=>'Требуется авторизация']); });
add_action('wp_ajax_nopriv_gexe_nm_list_tickets', function(){ wp_send_json(['ok'=>false,'code'=>'unauth','message'=>'Требуется авторизация']); });

