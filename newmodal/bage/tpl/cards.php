<?php if (!defined('ABSPATH')) { exit; } ?>
<div id="nm-root" class="nm-root">
  <div class="glpi-header-row">
    <div class="nm-filters-row">
      <div class="nm-filter nm-filter--status">
        <button class="nm-dd__btn" data-dd="status">Статус</button>
        <div class="nm-dd" id="nm-dd-status">
          <button data-status="1">Новая</button>
          <button data-status="2">В работе</button>
          <button data-status="4">Ожидание</button>
          <button data-status="5">Решено (ожид.)</button>
          <button data-status="6">Решено</button>
          <button data-status="7">Закрыто</button>
          <button data-status="0">Любой</button>
        </div>
      </div>
      <div class="nm-search">
        <input type="text" id="nm-search" placeholder="Поиск…">
      </div>
      <div class="nm-actions">
        <button class="nm-btn nm-btn--primary" id="nm-open-new-ticket">Новая заявка</button>
      </div>
    </div>
    <div class="nm-counts">
      <span class="nm-badge" data-status="1">0</span>
      <span class="nm-badge" data-status="2">0</span>
      <span class="nm-badge" data-status="4">0</span>
      <span class="nm-badge" data-status="5">0</span>
      <span class="nm-badge" data-status="6">0</span>
      <span class="nm-badge" data-status="7">0</span>
    </div>
  </div>
  <div id="nm-cards" class="nm-cards"></div>
</div>

<!-- Modal: Ticket -->
<div id="nm-modal" class="nm-modal" hidden>
  <div class="nm-modal__backdrop" data-close="1"></div>
  <div class="nm-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="nm-modal-title">
    <div class="nm-modal__header">
      <h3 id="nm-modal-title">Заявка</h3>
      <button class="nm-modal__close" data-close="1" aria-label="Закрыть">×</button>
    </div>
    <div class="nm-modal__body" id="nm-modal-body">
      <!-- filled via JS -->
    </div>
    <div class="nm-modal__footer">
      <div class="nm-modal__actions">
        <select id="nm-status-select">
          <option value="2">В работе</option>
          <option value="4">Ожидание</option>
          <option value="6">Решено</option>
          <option value="7">Закрыто</option>
        </select>
        <button id="nm-btn-update-status" class="nm-btn">Изменить статус</button>
      </div>
      <div class="nm-modal__comment">
        <textarea id="nm-followup-text" rows="2" placeholder="Комментарий…"></textarea>
        <button id="nm-btn-add-followup" class="nm-btn nm-btn--primary">Отправить</button>
      </div>
    </div>
  </div>
  <div class="nm-modal__spinner" hidden>Загрузка…</div>
</div>

<!-- Modal: New Ticket -->
<div id="nm-nt" class="nm-modal" hidden>
  <div class="nm-modal__backdrop" data-close="1"></div>
  <div class="nm-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="nm-nt-title">
    <div class="nm-modal__header">
      <h3 id="nm-nt-title">Новая заявка</h3>
      <button class="nm-modal__close" data-close="1" aria-label="Закрыть">×</button>
    </div>
    <div class="nm-modal__body">
      <div class="nm-nt-row"><label>Тема</label><input id="nm-nt-name" type="text" placeholder="Короткая суть"/></div>
      <div class="nm-nt-row"><label>Описание</label><textarea id="nm-nt-content" rows="4" placeholder="Подробности…"></textarea></div>
      <div class="nm-nt-row"><label>Категория</label><select id="nm-nt-cat"></select></div>
      <div class="nm-nt-row"><label>Локация</label><select id="nm-nt-loc"></select></div>
      <div class="nm-nt-row"><label>Срок (до)</label><input id="nm-nt-due" type="datetime-local"/></div>
    </div>
    <div class="nm-modal__footer"><button id="nm-nt-submit" class="nm-btn nm-btn--primary">Создать</button></div>
  </div>
</div>
