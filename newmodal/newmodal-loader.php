<?php
/**
 * New Modal (API-only) – isolated loader
 * - Renders hidden modal container into footer
 * - Registers AJAX endpoints (SQL mutating ops + API ping)
 * - Enqueues JS/CSS and passes runtime flags (изолированно)
 *
 * IMPORTANT:
 *  - Изменения — через SQL (see common/db.php), затем пингуем API (see common/notify-api.php)
 *  - Errors are returned to frontend; no logging to files/DB.
 *  - Idempotent actions on buttons; frontend updated without reload.
 */
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../glpi-db-setup.php';
require_once __DIR__ . '/newmodal-api.php';
require_once __DIR__ . '/newmodal-template.php';
require_once __DIR__ . '/common/helpers.php';
require_once __DIR__ . '/common/db.php';
require_once __DIR__ . '/common/notify-api.php';
require_once __DIR__ . '/modal/ticket-modal.php';
require_once __DIR__ . '/new-ticket/ajax.php';

/**
 * Регистрируем вывод скрытого контейнера модалки в футере.
 */
add_action('wp_footer', function(){
    if (!gexe_nm_is_shortcode_present('glpi_cards_new')) return;
    echo gexe_nm_render_modal_container();
}, 20);

