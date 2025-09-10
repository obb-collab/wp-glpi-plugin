<?php
/**
 * Рендер контейнера модалки (скрыт, наполняется JS)
 */
if (!defined('ABSPATH')) exit;

function gexe_nm_render_modal_container(): string {
    ob_start();
    ?>
    <div class="nm-modal" style="display:none" aria-hidden="true"><div id="nm-modal-root"></div></div>
    <?php
    return ob_get_clean();
}

