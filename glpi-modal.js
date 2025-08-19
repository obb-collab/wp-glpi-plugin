// GLPI modal + actions (standalone)
// Не трогает существующие обработчики. Открывается по клику на .glpi-topic или .glpi-card-body.

(function(){
  if (document.querySelector('.glpi-modal')) return;

  const modal = document.createElement('div');
  modal.className = 'glpi-modal';
  modal.innerHTML = `
    <div class="glpi-modal__backdrop"></div>
    <div class="glpi-modal__dialog" role="dialog" aria-modal="true" aria-label="Детали заявки">
      <div class="glpi-modal__actions">
        <button class="glpi-act glpi-act--start" data-action="start" type="button">Принято в работу</button>
        <button class="glpi-act glpi-act--done" data-action="done" type="button">Задача выполнена</button>
        <button class="glpi-act glpi-act--assignee" data-action="assignee" type="button">Смена исполнителя</button>
        <button class="glpi-act glpi-act--status" data-action="status" type="button">Смена статуса</button>
        <button class="glpi-modal__close" type="button" aria-label="Закрыть">×</button>
      </div>
      <div class="glpi-modal__content"></div>
      <div class="glpi-modal__comments">
        <div class="glpi-modal__comments-title">Комментарии</div>
        <div class="glpi-modal__comments-body" data-state="idle">
          <div class="glpi-spinner">Загрузка…</div>
        </div>
      </div>
    </div>
  `;
  document.body.appendChild(modal);

  const backdrop = modal.querySelector('.glpi-modal__backdrop');
  const content  = modal.querySelector('.glpi-modal__content');
  const btnClose = modal.querySelector('.glpi-modal__close');
  const commentsBody = modal.querySelector('.glpi-modal__comments-body');

  let currentTicketId = null;

  function getTicketIdFromCard(cardEl) {
    const fromAttr = cardEl.getAttribute('data-ticket-id');
    if (fromAttr) return parseInt(fromAttr, 10);
    const idEl = cardEl.querySelector('.glpi-ticket-id');
    if (!idEl) return null;
    const m = idEl.textContent && idEl.textContent.match(/#(\d+)/);
    return m ? parseInt(m[1], 10) : null;
  }

  function renderCardInModal(cardEl) {
    const clone = cardEl.cloneNode(true);
    clone.classList.add('glpi-card--in-modal');

    const body = clone.querySelector('.glpi-card-body');
    if (body) {
      body.style.maxHeight = 'none';
      body.style.overflow = 'visible';
      body.style.display = 'block';
      body.style.webkitLineClamp = 'unset';
      body.style.whiteSpace = 'pre-wrap';
    }

    const exec = clone.querySelector('.glpi-executor-footer');
    const date = clone.querySelector('.glpi-date-footer');
    if (exec) exec.classList.add('glpi-footer--modal');
    if (date) date.classList.add('glpi-footer--modal');

    content.innerHTML = '';
    content.appendChild(clone);
  }

  async function loadComments(ticketId) {
    commentsBody.setAttribute('data-state','loading');
    commentsBody.innerHTML = '<div class="glpi-spinner">Загрузка…</div>';
    try {
      const resp = await fetch((window.glpiAjax && window.glpiAjax.url) || (window.ajaxurl || '/wp-admin/admin-ajax.php'),{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
        body:new URLSearchParams({
          action:'glpi_get_comments',
          ticket_id:String(ticketId),
          _ajax_nonce: (window.glpiAjax && window.glpiAjax.nonce) || ''
        })
      });
      const html = await resp.text();
      commentsBody.setAttribute('data-state','ready');
      commentsBody.innerHTML = html || '<div class="glpi-empty">Нет комментариев</div>';
    } catch(err){
      commentsBody.setAttribute('data-state','error');
      commentsBody.innerHTML = '<div class="glpi-error">Ошибка загрузки комментариев</div>';
    }
  }

  function openFromCard(cardEl) {
    currentTicketId = getTicketIdFromCard(cardEl);
    renderCardInModal(cardEl);
    modal.classList.add('is-open');
    document.body.classList.add('glpi-modal-open');
    if (currentTicketId) loadComments(currentTicketId);
  }

  function closeModal() {
    modal.classList.remove('is-open');
    document.body.classList.remove('glpi-modal-open');
    content.innerHTML = '';
    commentsBody.innerHTML = '';
    currentTicketId = null;
  }

  // Открытие по клику на заголовок или текст карточки
  document.addEventListener('click', (e) => {
    if (e.target.closest('.glpi-filter-btn, .status-filter-btn, .glpi-filter-toggle')) return;
    const hit = e.target.closest('.glpi-card .glpi-topic, .glpi-card .glpi-card-body');
    if (hit) {
      const card = hit.closest('.glpi-card');
      if (card) openFromCard(card);
    }
  });

  // Закрытие
  backdrop.addEventListener('click', closeModal);
  btnClose.addEventListener('click', closeModal);
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
  });

  // Кнопки действий
  modal.addEventListener('click', async (e) => {
    const actBtn = e.target.closest('.glpi-act');
    if (!actBtn || !currentTicketId) return;

    const action = actBtn.dataset.action;
    let extra = {};
    if (action === 'assignee') {
      const val = prompt('ID исполнителя (GLPI users_id):');
      if (!val) return;
      extra.user_id = String(val).trim();
    }
    if (action === 'status') {
      const val = prompt('Новый статус (число GLPI):');
      if (!val) return;
      extra.status = String(val).trim();
    }

    actBtn.disabled = true;
    try {
      const resp = await fetch((window.glpiAjax && window.glpiAjax.url) || (window.ajaxurl || '/wp-admin/admin-ajax.php'),{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
        body:new URLSearchParams({
          action:'glpi_card_action',
          type: action,
          ticket_id: String(currentTicketId),
          payload: JSON.stringify(extra),
          _ajax_nonce: (window.glpiAjax && window.glpiAjax.nonce) || ''
        })
      });
      const data = await resp.json();
      if (data && data.ok) {
        if (typeof data.new_status !== 'undefined' && data.new_status !== null) {
          const gridCard = document.querySelector(`.glpi-card[data-ticket-id="${currentTicketId}"]`);
          if (gridCard) gridCard.setAttribute('data-status', String(data.new_status));
        }
        if (data.comment_html) commentsBody.innerHTML = data.comment_html;
        alert('Готово');
      } else {
        alert((data && data.error) || 'Ошибка выполнения');
      }
    } catch(err){
      alert('Сбой сети / AJAX');
    } finally {
      actBtn.disabled = false;
    }
  });
})();
