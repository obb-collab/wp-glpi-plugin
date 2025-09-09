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
    ?>
    <div id="gexe-newmodal-backdrop" class="gexe-nm-backdrop" hidden></div>
    <div id="gexe-newmodal" class="gexe-nm" hidden aria-hidden="true" role="dialog" aria-modal="true">
      <div class="gexe-nm__card">
        <div class="gexe-nm__header">
          <h3 id="gexe-nm-title" class="gexe-nm__title">Загрузка…</h3>
          <div class="gexe-nm__controls">
            <button class="gexe-nm__btn" id="gexe-nm-assign-self" title="Назначить на себя">👤</button>
            <div class="gexe-nm__status-group">
              <button class="gexe-nm__btn status-action" data-status="2">В работе</button>
              <button class="gexe-nm__btn status-action" data-status="3">В плане</button>
              <button class="gexe-nm__btn status-action" data-status="4">В стопе</button>
              <button class="gexe-nm__btn gexe-nm__btn--resolve status-action" data-status="6">Решить</button>
            </div>
            <button class="gexe-nm__close" id="gexe-nm-close" aria-label="Закрыть">×</button>
          </div>
        </div>
        <div class="gexe-nm__meta" id="gexe-nm-meta">
          <!-- filled by JS -->
        </div>
        <div class="gexe-nm__body" id="gexe-nm-comments">
          <!-- comments filled by JS -->
        </div>
        <div class="gexe-nm__footer">
          <div class="gexe-nm__error" id="gexe-nm-error" hidden></div>
          <textarea id="gexe-nm-text" class="gexe-nm__textarea" placeholder="Ваш комментарий…"></textarea>
          <div class="gexe-nm__actions">
            <button id="gexe-nm-send" class="gexe-nm__btn gexe-nm__btn--primary">Отправить</button>
            <button id="gexe-nm-accept" class="gexe-nm__btn" data-status="2">Принято в работу</button>
          </div>
        </div>
      </div>
    </div>
    <?php
    return (string)ob_get_clean();
}
