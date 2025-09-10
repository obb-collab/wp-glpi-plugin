<?php
/**
 * New Modal – server-rendered HTML skeleton.
 * Visual parity achieved via CSS; structure mirrors original modal:
 * - header with title + controls (assign, status buttons)
 * - body with ticket meta and comments list
 * - footer with comment form and "Принято в работу"/status buttons
 */
if (!defined('ABSPATH')) exit;

function gexe_newmodal_render_container(): string {
    ob_start();
    // Контейнер для формы новой заявки; основной контейнер создаётся в ticket-modal.php
    echo '<div id="nm-modal-root" aria-hidden="true"></div>';
    return ob_get_clean();
}

