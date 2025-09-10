<?php
/**
 * New Modal (API-only) – isolated loader
 * - Renders hidden modal container into footer
 * - Registers AJAX endpoints (all via GLPI REST API)
 * - Enqueues JS/CSS and passes runtime flags
 *
 * IMPORTANT:
 *  - No SQL. Only GLPI REST API calls (see newmodal-api.php)
 *  - Errors are returned to frontend; no logging to files/DB.
 *  - Idempotent actions on buttons; frontend updated without reload.
 */
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../glpi-db-setup.php';
require_once __DIR__ . '/newmodal-api.php';
require_once __DIR__ . '/newmodal-template.php';

/**
 * Determine whether new modal is enabled for current request
 */
function gexe_newmodal_is_enabled(): bool {
    // Глобальное включение через define — приоритетнее всего
    if (defined('GEXE_USE_NEWMODAL') && GEXE_USE_NEWMODAL) {
        return true;
    }
    // Опционально — включение через query string
    $qs_key = defined('GEXE_NEWMODAL_QS') ? GEXE_NEWMODAL_QS : 'use_newmodal';
    if (isset($_GET[$qs_key])) {
        return ((string) $_GET[$qs_key]) === '1';
    }
    return false;
}

/**
 * Enqueue assets and inject modal root into footer
 */
add_action('wp_enqueue_scripts', function () {
    if (!gexe_newmodal_is_enabled()) return;
    $ver  = defined('GEXE_TRIGGERS_VERSION') ? GEXE_TRIGGERS_VERSION : '1.0.0';
    $base = plugin_dir_url(__FILE__); // корректная база URL для подпапки newmodal/

    wp_register_style('gexe-newmodal', $base . 'newmodal.css', [], $ver);
    wp_enqueue_style('gexe-newmodal');

    // Без зависимостей, чтобы повесить capture-обработчик максимально рано
    wp_register_script('gexe-newmodal', $base . 'newmodal.js', [], $ver, true);
    wp_enqueue_script('gexe-newmodal');

    // Nonce + flags
    wp_localize_script('gexe-newmodal', 'gexeNewModal', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('gexe_newmodal_nonce'),
        'enabled' => true,
        'qs'      => defined('GEXE_NEWMODAL_QS') ? GEXE_NEWMODAL_QS : 'use_newmodal',
    ]);
}, 1); // грузим раньше прочих скриптов

add_action('wp_footer', function () {
    if (!gexe_newmodal_is_enabled()) return;
    // Inject hidden modal container (HTML markup kept minimal; visual parity in CSS)
    echo gexe_newmodal_render_container();
}, 100);
// Никаких классов в body на сервере — блокировку старых модалок включает JS только при открытии нового.

/**
 * AJAX: Fetch ticket with followups (comments) via API
 */
add_action('wp_ajax_gexe_newmodal_get_ticket', function () {
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'gexe_newmodal_nonce')) {
        wp_send_json_error(['message' => 'Security check failed: invalid nonce.']);
    }
    $ticket_id = isset($_POST['ticket_id']) ? (int)$_POST['ticket_id'] : 0;
    if ($ticket_id <= 0) {
        wp_send_json_error(['message' => 'Ticket ID is required.']);
    }
    try {
        $data = gexe_newmodal_api_get_ticket($ticket_id);
        wp_send_json_success($data);
    } catch (Exception $e) {
        wp_send_json_error(['message' => 'Failed to load ticket: ' . $e->getMessage()]);
    }
});

/**
 * AJAX: Add followup (comment)
 */
add_action('wp_ajax_gexe_newmodal_add_followup', function () {
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'gexe_newmodal_nonce')) {
        wp_send_json_error(['message' => 'Security check failed: invalid nonce.']);
    }
    $ticket_id = isset($_POST['ticket_id']) ? (int)$_POST['ticket_id'] : 0;
    $content   = trim((string)($_POST['content'] ?? ''));
    if ($ticket_id <= 0 || $content === '') {
        wp_send_json_error(['message' => 'Both ticket ID and comment text are required.']);
    }
    try {
        $res = gexe_newmodal_api_add_followup($ticket_id, $content);
        wp_send_json_success($res);
    } catch (Exception $e) {
        wp_send_json_error(['message' => 'Failed to add comment: ' . $e->getMessage()]);
    }
});

/**
 * AJAX: Mark as "in progress" (Принято в работу) – status=2
 */
add_action('wp_ajax_gexe_newmodal_mark_in_progress', function () {
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'gexe_newmodal_nonce')) {
        wp_send_json_error(['message' => 'Security check failed: invalid nonce.']);
    }
    $ticket_id = isset($_POST['ticket_id']) ? (int)$_POST['ticket_id'] : 0;
    if ($ticket_id <= 0) {
        wp_send_json_error(['message' => 'Ticket ID is required.']);
    }
    try {
        $res = gexe_newmodal_api_set_status($ticket_id, 2);
        wp_send_json_success($res);
    } catch (Exception $e) {
        wp_send_json_error(['message' => 'Failed to set status: ' . $e->getMessage()]);
    }
});

/**
 * AJAX: Change status (generic)
 * Expected POST: ticket_id, status (int)
 */
add_action('wp_ajax_gexe_newmodal_change_status', function () {
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'gexe_newmodal_nonce')) {
        wp_send_json_error(['message' => 'Security check failed: invalid nonce.']);
    }
    $ticket_id = isset($_POST['ticket_id']) ? (int)$_POST['ticket_id'] : 0;
    $status    = isset($_POST['status']) ? (int)$_POST['status'] : 0;
    if ($ticket_id <= 0 || $status <= 0) {
        wp_send_json_error(['message' => 'Ticket ID and status are required.']);
    }
    try {
        $res = gexe_newmodal_api_set_status($ticket_id, $status);
        wp_send_json_success($res);
    } catch (Exception $e) {
        wp_send_json_error(['message' => 'Failed to change status: ' . $e->getMessage()]);
    }
});

/**
 * OPTIONAL: Assign to current user via API (upper-right control)
 */
add_action('wp_ajax_gexe_newmodal_assign_self', function () {
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'gexe_newmodal_nonce')) {
        wp_send_json_error(['message' => 'Security check failed: invalid nonce.']);
    }
    $ticket_id = isset($_POST['ticket_id']) ? (int)$_POST['ticket_id'] : 0;
    if ($ticket_id <= 0) {
        wp_send_json_error(['message' => 'Ticket ID is required.']);
    }
    try {
        $res = gexe_newmodal_api_assign_self($ticket_id);
        wp_send_json_success($res);
    } catch (Exception $e) {
        wp_send_json_error(['message' => 'Failed to assign: ' . $e->getMessage()]);
    }
});
