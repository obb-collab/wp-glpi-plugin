/* global gexeNewModal */
/* eslint-disable no-underscore-dangle */
(function gexeNewModalInit() {
  if (!window.gexeNewModal || !gexeNewModal.enabled) {
    return;
  }

  const qsKey = gexeNewModal.qs || 'use_newmodal';
  const ajax = gexeNewModal.ajaxUrl;
  const { nonce } = gexeNewModal;

  const dom = {
    backdrop: document.getElementById('gexe-newmodal-backdrop'),
    modal: document.getElementById('gexe-newmodal'),
    title: document.getElementById('gexe-nm-title'),
    meta: document.getElementById('gexe-nm-meta'),
    comments: document.getElementById('gexe-nm-comments'),
    close: document.getElementById('gexe-nm-close'),
    send: document.getElementById('gexe-nm-send'),
    textarea: document.getElementById('gexe-nm-text'),
    accept: document.getElementById('gexe-nm-accept'),
    assignSelf: document.getElementById('gexe-nm-assign-self'),
    error: document.getElementById('gexe-nm-error'),
  };

  let openedTicketId = null;
  let busy = false;

  function showError(msg) {
    if (!dom.error) return;
    dom.error.textContent = msg || '';
    dom.error.hidden = !msg;
  }

  function openModal() {
    dom.backdrop.hidden = false;
    dom.modal.hidden = false;
  }
  function closeModal() {
    dom.backdrop.hidden = true;
    dom.modal.hidden = true;
    openedTicketId = null;
    dom.comments.innerHTML = '';
    dom.meta.innerHTML = '';
    dom.textarea.value = '';
    showError('');
  }

  if (dom.backdrop) {
    dom.backdrop.addEventListener('click', closeModal);
  }
  if (dom.close) {
    dom.close.addEventListener('click', closeModal);
  }

  function post(action, data) {
    const form = new FormData();
    form.append('action', action);
    form.append('nonce', nonce);
    Object.keys(data || {}).forEach((k) => form.append(k, data[k]));
    return fetch(ajax, { method: 'POST', body: form, credentials: 'same-origin' })
      .then((r) => r.json());
  }

  function formatDate(dt) {
    try { return new Date(dt).toLocaleString(); } catch (e) { return dt; }
  }

  function renderMeta(t) {
    const id = t?.id;
    const status = t?.status;
    const cat = t?.itilcategories_id ? (t._itilcategories_id?.completename || t.itilcategories_id) : '';
    const loc = t?.locations_id ? (t._locations_id?.completename || t.locations_id) : '';
    dom.title.textContent = (t?.name ? `#${id} — ${t.name}` : `#${id}`);
    dom.meta.innerHTML = `
      <div class="gexe-nm__meta-row">
        <div><b>Статус:</b> ${status}</div>
        <div><b>Категория:</b> ${cat}</div>
        <div><b>Местоположение:</b> ${loc}</div>
      </div>
    `;
  }

  function renderComments(list) {
    dom.comments.innerHTML = '';
    (list || []).forEach((f) => {
      const el = document.createElement('div');
      el.className = 'gexe-nm__comment';
      el.innerHTML = `
        <div class="gexe-nm__comment-head">
          <span class="gexe-nm__comment-user">${(f._users_id ?? f.users_id) || ''}</span>
          <span class="gexe-nm__comment-date">${formatDate(f.date)}</span>
        </div>
        <div class="gexe-nm__comment-body">${(f.content || '').replace(/\n/g, '<br>')}</div>
      `;
      dom.comments.appendChild(el);
    });
    dom.comments.scrollTop = dom.comments.scrollHeight;
  }

  function loadTicket(id) {
    busy = true; showError(''); dom.title.textContent = `#${id} — загрузка…`;
    post('gexe_newmodal_get_ticket', { ticket_id: id }).then((res) => {
      if (!res || !res.success) throw new Error(res?.data?.message || 'Не удалось загрузить карточку');
      renderMeta(res.data.ticket);
      renderComments(res.data.followups);
      openedTicketId = id;
    }).catch((err) => {
      showError(err.message || 'Ошибка загрузки карточки.');
    }).finally(() => { busy = false; });
  }

  // Hijack existing ticket clicks only when query string enables it
  function enabledByQS() {
    const sp = new URLSearchParams(window.location.search);
    return sp.get(qsKey) === '1';
  }
  function attachDelegatedOpener() {
    if (!enabledByQS()) return;
    document.addEventListener('click', (ev) => {
      const a = ev.target.closest('[data-ticket-id], .gexe-ticket-card, .ticket-card');
      if (!a) return;
      const idAttr = a.getAttribute('data-ticket-id') || (a.dataset && a.dataset.ticketId);
      const titleAttr = a.getAttribute('data-ticket-title') || '';
      const id = parseInt(idAttr, 10);
      if (!id || Number.isNaN(id)) return;
      ev.preventDefault();
      openModal();
      dom.title.textContent = titleAttr ? (`#${id} — ${titleAttr}`) : `#${id}`;
      loadTicket(id);
    }, true);
  }
  attachDelegatedOpener();

  // Send comment
  if (dom.send) {
    dom.send.addEventListener('click', () => {
      if (busy) return;
      if (!openedTicketId) {
        showError('Карточка не загружена.');
        return;
      }
      const txt = (dom.textarea.value || '').trim();
      if (!txt) {
        showError('Введите текст комментария.');
        return;
      }
      busy = true; showError('');
      post('gexe_newmodal_add_followup', { ticket_id: openedTicketId, content: txt }).then((res) => {
        if (!res || !res.success) throw new Error(res?.data?.message || 'Не удалось отправить комментарий');
        dom.textarea.value = '';
        // Reload followups to keep order consistent
        return post('gexe_newmodal_get_ticket', { ticket_id: openedTicketId });
      }).then((res) => {
        if (res && res.success) {
          renderComments(res.data.followups);
        }
      }).catch((err) => {
        showError(err.message || 'Ошибка отправки комментария.');
      })
        .finally(() => { busy = false; });
    });
  }

  // Accept to work (status=2) or generic status buttons
  function changeStatus(s) {
    if (busy) return;
    if (!openedTicketId) {
      showError('Карточка не загружена.');
      return;
    }
    busy = true; showError('');
    post('gexe_newmodal_change_status', { ticket_id: openedTicketId, status: s }).then((res) => {
      if (!res || !res.success) throw new Error(res?.data?.message || 'Не удалось изменить статус');
      // Reload meta quickly
      return post('gexe_newmodal_get_ticket', { ticket_id: openedTicketId });
    }).then((res) => {
      if (res && res.success) {
        renderMeta(res.data.ticket);
      }
    }).catch((err) => {
      showError(err.message || 'Ошибка изменения статуса.');
    })
      .finally(() => { busy = false; });
  }

  if (dom.accept) {
    dom.accept.addEventListener('click', function onAcceptClick() {
      changeStatus(parseInt(this.getAttribute('data-status') || '2', 10));
    });
  }
  document.addEventListener('click', (ev) => {
    const btn = ev.target.closest('.status-action');
    if (!btn || !dom.modal || dom.modal.hidden) return;
    const s = parseInt(btn.getAttribute('data-status') || '0', 10);
    if (s > 0) changeStatus(s);
  });

  // Assign to self
  if (dom.assignSelf) {
    dom.assignSelf.addEventListener('click', () => {
      if (busy) return;
      if (!openedTicketId) {
        showError('Карточка не загружена.');
        return;
      }
      busy = true; showError('');
      post('gexe_newmodal_assign_self', { ticket_id: openedTicketId }).then((res) => {
        if (!res || !res.success) throw new Error(res?.data?.message || 'Не удалось назначить исполнителя');
        return post('gexe_newmodal_get_ticket', { ticket_id: openedTicketId });
      }).then((res) => {
        if (res && res.success) {
          renderMeta(res.data.ticket);
        }
      }).catch((err) => {
        showError(err.message || 'Ошибка назначения.');
      })
        .finally(() => { busy = false; });
    });
  }
}());
