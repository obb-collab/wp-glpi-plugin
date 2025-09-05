/**
 * gexe-filter.js — панель фильтров, поиск, карточки, модалки и действия
 * Требования:
 *  - window.glpiAjax локализуется из glpi-modal-actions.php (url, nonce, user_glpi_id)
 *  - HTML карточек и шапки — как в шаблоне gexe-copy.php
 */

(function () {
  'use strict';

  // Ensure AJAX settings are available under both legacy and new globals
  const ajaxConfig = window.gexeAjax || window.glpiAjax || {};
  window.glpiAjax = ajaxConfig;
  window.gexeAjax = ajaxConfig;
  const glpiAjax = ajaxConfig;

  /* ========================= УТИЛИТЫ ========================= */
  const $  = (s, p) => (p || document).querySelector(s);
  const $$ = (s, p) => Array.from((p || document).querySelectorAll(s));
  const on = (el, ev, fn, opt) => el && el.addEventListener(ev, fn, opt || false);

  const debounce = (fn, wait) => {
    let t;
    return function () {
      clearTimeout(t);
      const ctx = this, args = arguments;
      t = setTimeout(() => fn.apply(ctx, args), wait);
    };
  };

  // --- Анти-спам: загрузка и блокировки действий ---
  window.__glpiInFlight = window.__glpiInFlight || {};
  function lockAction(ticketId, action, state) {
    window.__glpiInFlight[ticketId] = window.__glpiInFlight[ticketId] || {};
    if (state) {
      window.__glpiInFlight[ticketId][action] = true;
    } else if (window.__glpiInFlight[ticketId]) {
      delete window.__glpiInFlight[ticketId][action];
    }
  }
  function isActionLocked(ticketId, action) {
    return !!(window.__glpiInFlight[ticketId] && window.__glpiInFlight[ticketId][action]);
  }
  function setActionLoading(el, state) {
    if (!el) return;
    if (state) {
      el.setAttribute('disabled', 'disabled');
      el.setAttribute('aria-disabled', 'true');
      el.classList.add('is-loading');
    } else {
      el.removeAttribute('disabled');
      el.removeAttribute('aria-disabled');
      el.classList.remove('is-loading');
    }
  }

  function refreshActionsNonce() {
    const ajax = window.gexeAjax || window.glpiAjax;
    if (!ajax) return Promise.reject(new Error('no_ajax'));
    const params = new URLSearchParams();
    params.append('action', 'gexe_refresh_actions_nonce');
    return fetch(ajax.url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params.toString(),
    })
      .then(r => r.json())
      .then(data => {
        if (data && data.success && data.data && data.data.nonce) {
          ajax.nonce = data.data.nonce;
          return ajax.nonce;
        }
        throw new Error('nonce_refresh_failed');
      });
  }

  function ticketIdFromEl(el) {
    const card = el.closest('.glpi-card');
    if (card) return parseInt(card.getAttribute('data-ticket-id') || '0', 10);
    if (modalEl && modalEl.contains(el)) {
      return parseInt(modalEl.getAttribute('data-ticket-id') || '0', 10);
    }
    return 0;
  }

  function handleActionClick(e) {
    const btn = e.target.closest('.gexe-action-btn');
    if (!btn) return;
    const ticketId = ticketIdFromEl(btn);
    if (!ticketId) return;
    e.preventDefault();
    e.stopPropagation();

    if (btn.classList.contains('gexe-open-comment')) {
      const cardEl = btn.closest('.glpi-card');
      const title = (cardEl && cardEl.querySelector('.glpi-topic') ? cardEl.querySelector('.glpi-topic').textContent : '')
        || ('Задача #' + ticketId);
      openCommentModal(title.trim(), ticketId);
      return;
    }

    if (btn.classList.contains('gexe-open-close')) {
      openDoneModal(ticketId);
      return;
    }

    if (!btn.classList.contains('gexe-open-accept')) return;
    if (btn.disabled || isActionLocked(ticketId, 'accept')) return;

    const ajax = window.gexeAjax || window.glpiAjax;
    if (!ajax || !ajax.url) return;

    lockAction(ticketId, 'accept', true);
    btn.setAttribute('disabled', 'disabled');
    btn.setAttribute('aria-disabled', 'true');
    btn.classList.add('is-loading');
    if (window.glpiToast) glpiToast('Принимаем в работу…');

    const fd = new FormData();
    fd.append('action', 'glpi_ticket_accept_sql');
    fd.append('ticket_id', String(ticketId));
    fd.append('assignee_glpi_id', String(ajax.user_glpi_id || 0));
    fd.append('nonce', ajax.nonce);

    const send = retry => fetch(ajax.url, { method: 'POST', body: fd })
      .then(r => r.json().then(data => ({ status: r.status, data })))
      .then(resp => {
        if (resp.status === 403 && resp.data && resp.data.code === 'AJAX_FORBIDDEN' && !retry) {
          return refreshActionsNonce().then(() => { fd.set('nonce', ajax.nonce); return send(true); });
        }
        return resp;
      });

    send(false).then(resp => {
      if (resp.status === 200 && resp.data && resp.data.ok) {
        btn.classList.remove('is-loading');
        const cardEl = document.querySelector('.glpi-card[data-ticket-id="'+ticketId+'"]');
        if (cardEl) {
          cardEl.setAttribute('data-status', '2');
          const cardBtn = cardEl.querySelector('.gexe-open-accept');
          if (cardBtn) {
            cardBtn.disabled = true;
            cardBtn.classList.remove('is-loading');
            cardBtn.setAttribute('aria-disabled', 'true');
          }
        }
        if (modalEl && modalEl.getAttribute('data-ticket-id') === String(ticketId)) {
          const mb = modalEl.querySelector('.gexe-open-accept');
          if (mb) {
            mb.disabled = true;
            mb.classList.remove('is-loading');
            mb.setAttribute('aria-disabled', 'true');
          }
        }
        if (resp.data.followup_id) {
          const actionId = crypto.randomUUID();
          addPendingComment(ticketId, 'Принято в работу', actionId);
          const info = pendingComments[ticketId];
          if (info) {
            const meta = $('.meta', info.el);
            if (meta) {
              meta.innerHTML = '<span class="glpi-comment-date" data-date="'+(resp.data.created_at || new Date().toISOString())+'"></span>';
              updateAgeFooters();
            }
            info.el.classList.remove('glpi-comment--pending');
            const st = info.el.querySelector('.glpi-comment-status');
            if (st) st.remove();
            delete pendingComments[ticketId];
          }
        }
        lockAction(ticketId, 'accept', false);
        recalcStatusCounts(); filterCards(); refreshTicketMeta(ticketId);
        if (window.glpiToast) glpiToast('Принято в работу');
      } else {
        btn.classList.remove('is-loading');
        btn.removeAttribute('disabled');
        btn.removeAttribute('aria-disabled');
        lockAction(ticketId, 'accept', false);
        const code = resp.data && resp.data.code ? resp.data.code : 'ERROR';
        const msg = resp.data && resp.data.message ? resp.data.message : '';
        if (window.glpiToast) glpiToast(code + (msg ? ': ' + msg : ''));
      }
    }).catch(() => {
      btn.classList.remove('is-loading');
      btn.removeAttribute('disabled');
      btn.removeAttribute('aria-disabled');
      lockAction(ticketId, 'accept', false);
      if (window.glpiToast) glpiToast('Ошибка сети');
    });
  }

  document.addEventListener('click', handleActionClick, true);

  function refreshTicketMeta(ticketId) {
    const ajax = window.gexeAjax || window.glpiAjax;
    const url = ajax && ajax.url;
    const nonce = ajax && ajax.nonce;
    if (!url || !nonce || !ticketId) return;
    const fd = new FormData();
    fd.append('action', 'glpi_ticket_meta');
    fd.append('_ajax_nonce', nonce);
    fd.append('ticket_id', String(ticketId));
    fetch(url, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(resp => {
        if (!(resp && resp.success && resp.data)) return;
        const data = resp.data;
        const card = document.querySelector('.glpi-card[data-ticket-id="'+ticketId+'"]');
        const cnt = card && card.querySelector('.gexe-cmnt-count');
        if (cnt) cnt.textContent = String(data.followups_count || 0);
        const modalCnt = modalEl && modalEl.getAttribute('data-ticket-id') === String(ticketId)
          ? modalEl.querySelector('.glpi-modal__comments-title .gexe-cmnt-count')
          : null;
        if (modalCnt) modalCnt.textContent = String(data.followups_count || 0);
        if (card) {
          const footer = card.querySelector('.glpi-date-footer');
          if (footer) footer.setAttribute('data-date', data.last_followup_at || '');
        }
        updateAgeFooters();
      })
      .catch(()=>{});
  }

  /* ========================= ПРЕДЗАГРУЖЕННЫЕ КОММЕНТАРИИ ========================= */
  // Комментарии подгружаются на сервере и передаются через window.gexePrefetchedComments

  /* ========================= ВСПОМОГАТЕЛЬНОЕ: slug для категорий (с кириллицей) ========================= */
  function slugify(txt){
    const map = {
      'а':'a','б':'b','в':'v','г':'g','д':'d','е':'e','ё':'e','ж':'zh','з':'z','и':'i','й':'y','к':'k','л':'l','м':'m','н':'n','о':'o','п':'p','р':'r','с':'s','т':'t','у':'u','ф':'f','х':'h','ц':'c','ч':'ch','ш':'sh','щ':'sch','ъ':'','ы':'y','ь':'','э':'e','ю':'yu','я':'ya'
    };
    return (txt||'').toString().toLowerCase()
      .split('').map(ch => (Object.prototype.hasOwnProperty.call(map, ch) ? map[ch] : ch)).join('')
      .replace(/[^a-z0-9]+/g,'-')
      .replace(/^-+|-+$/g,'');
  }
  function prepareCategoryTags(){
    document.querySelectorAll('.category-filter-btn').forEach(tag=>{
      let label = tag.getAttribute('data-label') || '';
      let count = tag.getAttribute('data-count') || '';

      if (!label) {
        const txt = tag.textContent || '';
        const m = txt.match(/^(.*?)[\s—-]+(\d+)\s*$/);
        if (m) {
          label = m[1].trim();
          count = count || m[2];
        } else {
          label = txt.trim();
        }
      }

      // rebuild text to use parentheses for counts
      const icon = tag.querySelector('i');
      const iconHTML = icon ? icon.outerHTML + ' ' : '';
      if (count) {
        tag.innerHTML = iconHTML + label + ' (' + count + ')';
        tag.setAttribute('data-count', count);
      } else {
        tag.innerHTML = iconHTML + label;
      }
      tag.setAttribute('data-label', label);

      if(!tag.hasAttribute('data-cat')){
        tag.setAttribute('data-cat', slugify(label));
      }
      tag.classList.add('category-filter-btn');
    });
  }

  /* ========================= ДОП. ФИЛЬТР «ПРОСРОЧЕНЫ» ========================= */
  function ensureOverdueBlock() {
    const row = document.querySelector('.glpi-status-blocks');
    if (!row) return;

    if (!document.querySelector('.glpi-newfilter-block')) {
      const btn = document.createElement('button');
      btn.className = 'glpi-status-block glpi-newfilter-block';
      btn.innerHTML = '<div class="status-count">0</div><div class="status-label">Просрочены</div>';
      btn.addEventListener('click', () => {
        btn.classList.toggle('active');
        document.dispatchEvent(new CustomEvent('gexe:filters:changed'));
      });
      row.appendChild(btn);
    }
  }
  function openNewTaskModal() {
    try {
      // если модуль экспонирует функцию
      if (window.GNT && typeof window.GNT.open === 'function') { window.GNT.open(); return; }
      if (typeof window.gexeOpenNewTask === 'function') { window.gexeOpenNewTask(); return; }
      // клик по скрытой кнопке, к которой уже привязан glpi-new-task.php
      let btn = document.getElementById('glpi-btn-new-ticket')
             || document.querySelector('.gnt-open')
             || document.getElementById('glpi-open-new-task')
             || document.querySelector('[data-open="glpi-new-task"]');
      if (!btn) {
        btn = document.createElement('button');
        btn.id = 'glpi-btn-new-ticket';
        btn.className = 'gnt-open';
        btn.style.display = 'none';
        document.body.appendChild(btn);
      }
      btn.dispatchEvent(new Event('click', {bubbles:true}));
      window.dispatchEvent(new CustomEvent('gexe:newtask:open'));
    } catch (e) {}
  }

  function bindNewTaskButton(){
    const btn = document.querySelector('.glpi-newtask-btn');
    if (btn) btn.addEventListener('click', openNewTaskModal);
  }

  /* ========================= ИНЛАЙН КАТЕГОРИИ ========================= */
  let selectedCategories = [];
  let categoriesLoaded = false;

  function renderInlineCategories(){
    const box = document.getElementById('glpi-categories-inline');
    if (!box || !Array.isArray(window.gexeCategories)) return;
    box.innerHTML = '';
    window.gexeCategories.forEach(cat => {
      const btn = document.createElement('button');
      btn.className = 'glpi-cat-chip';
      btn.setAttribute('data-cat', cat.slug);
      btn.setAttribute('role','button');
      btn.setAttribute('aria-pressed','false');
      btn.innerHTML = (cat.icon || '') + '<span class="glpi-cat-label">' + cat.label + '</span>' + (cat.count ? ' <span class="glpi-cat-count">' + cat.count + '</span>' : '');
      box.appendChild(btn);
    });
    const reset = document.createElement('button');
    reset.className = 'glpi-cat-reset';
    reset.textContent = 'Сбросить';
    reset.hidden = true;
    box.appendChild(reset);

    // Клики по бейджам категорий
    box.addEventListener('click', e => {
      const chip = e.target.closest('.glpi-cat-chip');
      if (!chip) return;
      const cat = chip.getAttribute('data-cat');
      const multi = e.ctrlKey || e.metaKey;
      if (multi) {
        const i = selectedCategories.indexOf(cat);
        if (i >= 0) { selectedCategories.splice(i,1); chip.classList.remove('active'); chip.setAttribute('aria-pressed','false'); }
        else { selectedCategories.push(cat); chip.classList.add('active'); chip.setAttribute('aria-pressed','true'); }
      } else {
        const isActive = chip.classList.contains('active');
        $$('.glpi-cat-chip', box).forEach(c => { c.classList.remove('active'); c.setAttribute('aria-pressed','false'); });
        selectedCategories = [];
        if (!isActive) { selectedCategories = [cat]; chip.classList.add('active'); chip.setAttribute('aria-pressed','true'); }
      }
      reset.hidden = selectedCategories.length === 0;
      localStorage.setItem('glpi.categories.selected', JSON.stringify(selectedCategories));
      recalcStatusCounts();
      filterCards();
    }, true);

    // Кнопка сброса
    reset.addEventListener('click', () => {
      selectedCategories = [];
      $$('.glpi-cat-chip', box).forEach(c => { c.classList.remove('active'); c.setAttribute('aria-pressed','false'); });
      reset.hidden = true;
      localStorage.removeItem('glpi.categories.selected');
      recalcStatusCounts();
      filterCards();
    });

    // Восстановление выбора
    const saved = JSON.parse(localStorage.getItem('glpi.categories.selected') || '[]');
    if (saved.length) {
      selectedCategories = saved;
      $$('.glpi-cat-chip', box).forEach(c => {
        if (saved.includes(c.getAttribute('data-cat'))) {
          c.classList.add('active');
          c.setAttribute('aria-pressed','true');
        }
      });
      reset.hidden = false;
    }

    categoriesLoaded = true;
    recalcCategoryVisibility();
  }

  function initCategoryToggle(){
    const toggle = document.querySelector('.glpi-cat-toggle');
    const box = document.getElementById('glpi-categories-inline');
    if (!toggle || !box) return;

    toggle.addEventListener('click', e => {
      e.preventDefault();
      const hidden = box.hasAttribute('hidden');
      if (hidden) {
        if (!categoriesLoaded) renderInlineCategories();
        box.removeAttribute('hidden');
      } else {
        box.setAttribute('hidden','');
      }
      toggle.setAttribute('aria-expanded', hidden ? 'true' : 'false');
      localStorage.setItem('glpi.categories.expanded', hidden ? 'true' : 'false');
    });

    // Восстановление состояния
    if (localStorage.getItem('glpi.categories.expanded') === 'true') {
      renderInlineCategories();
      box.removeAttribute('hidden');
      toggle.setAttribute('aria-expanded','true');
    }
  }

  /* ========================= МОДАЛКА ПРОСМОТРА КАРТОЧКИ ========================= */
  let modalEl = null;
  let commentsController = null;
  const pendingComments = {};
  function ensureViewerModal() {
    if (modalEl) return modalEl;
    modalEl = document.createElement('div');
    modalEl.className = 'gexe-modal';
    modalEl.innerHTML =
      '<div class="gexe-modal__backdrop"></div>' +
      '<div class="gexe-modal__dialog" role="dialog" aria-modal="true">' +
        '<button class="gexe-modal__close" aria-label="Закрыть"><i class="fa-solid fa-xmark"></i></button>' +
        '<div class="gexe-modal__content">' +
          '<div class="gexe-modal__cardwrap"></div>' +
          '<div class="glpi-modal__comments">' +
            '<div class="glpi-modal__comments-title">Комментарии (<span class="gexe-cmnt-count">0</span>)</div>' +
            '<div id="gexe-comments" class="glpi-modal__comments-body"></div>' +
          '</div>' +
        '</div>' +
      '</div>';
    document.body.appendChild(modalEl);
    $('.gexe-modal__backdrop', modalEl).addEventListener('click', closeViewerModal);
    $('.gexe-modal__close', modalEl).addEventListener('click', closeViewerModal);
    return modalEl;
  }
  function openViewerModal()  { ensureViewerModal(); modalEl.classList.add('gexe-modal--open'); document.body.classList.add('glpi-modal-open'); }
  function closeViewerModal() {
    if (!modalEl) return;
    modalEl.classList.remove('gexe-modal--open');
    document.body.classList.remove('glpi-modal-open');
    Object.values(pendingComments).forEach(p => { if (p.poller) clearInterval(p.poller); });
  }

  function renderModalCard(cardEl) {
    const wrap = $('.gexe-modal__cardwrap', modalEl);
    if (!wrap) return;
    wrap.innerHTML = '';
    const clone = cardEl.cloneNode(true);
    clone.classList.add('glpi-card--in-modal');
    const act = $('.gexe-card-actions', clone); if (act) act.remove();
    const desc = $('.glpi-desc', clone);
    if (desc) {
      const full = desc.getAttribute('data-full');
      if (full) desc.textContent = full;
    }
    const chip = $('.glpi-comments-chip', clone); if (chip) chip.remove();

    const id = Number(clone.getAttribute('data-ticket-id') || '0');
    const bar = document.createElement('div');
    bar.className = 'gexe-card-actions';
    bar.innerHTML =
      '<button class="gexe-action-btn gexe-open-comment" data-action="comment" title="Комментарии"><i class="fa-regular fa-comment"></i></button>' +
      '<button class="gexe-action-btn gexe-open-accept"  data-action="accept"  title="Принять в работу"><i class="fa-solid fa-play"></i></button>' +
      '<button class="gexe-action-btn gexe-open-close"   data-action="resolve" title="Завершить"><i class="fa-solid fa-check"></i></button>';
    clone.insertBefore(bar, clone.firstChild);

    const btnAccept = $('.gexe-open-accept', bar);

    // Синхронизируем состояние кнопки с исходной карточкой
    const origAccept = cardEl.querySelector('.gexe-open-accept');
    if (origAccept && origAccept.disabled && btnAccept) {
      btnAccept.disabled = true;
    }

    wrap.appendChild(clone);
    applyActionVisibility();
  }

  function loadComments(ticketId, page = 1) {
    const box = $('#gexe-comments');
    const modalCntInit = modalEl && modalEl.querySelector('.glpi-modal__comments-title .gexe-cmnt-count');
    if (modalCntInit) modalCntInit.textContent = '0';

    const preloaded = window.gexePrefetchedComments && window.gexePrefetchedComments[ticketId];
    if (preloaded) {
      if (box) box.innerHTML = '';
      applyCommentsData(ticketId, preloaded);
      return;
    }

    if (box) {
      box.innerHTML = '';
      for (let i = 0; i < 3; i++) {
        const sk = document.createElement('div');
        sk.className = 'glpi-comment glpi-comment--skeleton';
        sk.innerHTML = '<div class="meta"></div><div class="text"></div>';
        box.appendChild(sk);
      }
    }

    const base = window.glpiAjax && glpiAjax.rest;
    const nonce = window.glpiAjax && glpiAjax.restNonce;
    if (!base || !nonce) return;
    const url = base + 'comments?ticket_id=' + encodeURIComponent(ticketId) + '&page=' + page + '&_=' + Date.now();
    if (commentsController) commentsController.abort();
    commentsController = new AbortController();
    const t0 = performance.now();
    fetch(url, { headers: { 'X-WP-Nonce': nonce, 'Cache-Control': 'no-cache' }, signal: commentsController.signal })
      .then(r => {
        const elapsed = Math.round(performance.now() - t0);
        console.debug('comments load', ticketId, { status: r.status, ms: elapsed });
        return r.json();
      })
      .then(data => {
        window.gexePrefetchedComments = window.gexePrefetchedComments || {};
        window.gexePrefetchedComments[ticketId] = data;
        applyCommentsData(ticketId, data);
      })
      .catch(()=>{});
  }

  function applyCommentsData(ticketId, data) {
    const box = $('#gexe-comments');
    if (box) {
      box.innerHTML = data && data.html ? data.html : '';
      updateAgeFooters();
    }
    if (data && typeof data.count === 'number') {
      const modalCnt = modalEl && modalEl.querySelector('.glpi-modal__comments-title .gexe-cmnt-count');
      if (modalCnt) modalCnt.textContent = String(data.count);
      const cardCnt = document.querySelector('.glpi-card[data-ticket-id="'+ticketId+'"] .gexe-cmnt-count');
      if (cardCnt) cardCnt.textContent = String(data.count);
    }
    if (data && data.html && data.html.includes('Принято в работу')) {
      const cardBtn  = document.querySelector('.glpi-card[data-ticket-id="'+ticketId+'"] .gexe-open-accept');
      const modalBtn = modalEl && modalEl.querySelector('.gexe-open-accept');
      if (cardBtn)  cardBtn.disabled  = true;
      if (modalBtn) modalBtn.disabled = true;
    }
    if (data && typeof data.time_ms === 'number') {
      const stat = document.getElementById('glpi-comments-time');
      if (stat) stat.textContent = String(data.time_ms);
    }
  }

  function addPendingComment(ticketId, text, actionId) {
    const box = $('#gexe-comments');
    if (!box) return;
    const el = document.createElement('div');
    el.className = 'glpi-comment glpi-comment--pending';
    el.innerHTML = '<div class="meta"><span class="glpi-comment-status">Отправка...</span></div><div class="text glpi-txt"></div>';
    const txtEl = $('.text', el); if (txtEl) txtEl.textContent = text;
    box.appendChild(el);
    pendingComments[ticketId] = { el, actionId, poller: null, followupId: 0, start: Date.now() };
  }

  function markPendingSent(ticketId, followupId, createdAt) {
    const info = pendingComments[ticketId];
    if (!info) return;
    info.followupId = followupId;
    const meta = $('.meta', info.el);
    if (meta) {
      meta.innerHTML = '<span class="glpi-comment-date" data-date="'+createdAt+'"></span><span class="glpi-comment-status"><span class="glpi-comment-spinner"></span> Отправлено</span>';
      updateAgeFooters();
    }
    startPolling(ticketId);
  }

  function startPolling(ticketId) {
    const info = pendingComments[ticketId];
    if (!info || info.poller) return;
    const base = window.glpiAjax && glpiAjax.rest;
    const nonce = window.glpiAjax && glpiAjax.restNonce;
    if (!base || !nonce || !info.followupId) return;
    let attempts = 0;
    info.poller = setInterval(() => {
      attempts++;
      fetch(base + 'followup?id=' + info.followupId + '&_=' + Date.now(), { headers: { 'X-WP-Nonce': nonce, 'Cache-Control': 'no-cache' } })
        .then(r => r.json())
        .then(data => {
          if (data && data.html) {
            info.el.innerHTML = data.html;
            info.el.classList.remove('glpi-comment--pending');
            clearInterval(info.poller);
            delete pendingComments[ticketId];
            lockAction(ticketId, 'comment', false);
            updateAgeFooters();
          } else if (attempts >= 2) {
            const st = $('.glpi-comment-status', info.el);
            if (st) st.textContent = 'Отправлено, будет видно позже';
            info.el.classList.add('glpi-comment--stopped');
            clearInterval(info.poller);
            delete pendingComments[ticketId];
            lockAction(ticketId, 'comment', false);
          }
        })
        .catch(() => {
          if (attempts >= 2) {
            const st = $('.glpi-comment-status', info.el);
            if (st) st.textContent = 'Отправлено, будет видно позже';
            info.el.classList.add('glpi-comment--stopped');
            clearInterval(info.poller);
            delete pendingComments[ticketId];
            lockAction(ticketId, 'comment', false);
          }
        });
    }, 1500);
  }

  /* ========================= МОДАЛКА КОММЕНТАРИЯ ========================= */
  let cmntModal = null;
  let doneModal = null;
  function ensureCommentModal() {
    if (cmntModal) return cmntModal;
    cmntModal = document.createElement('div');
    cmntModal.className = 'gexe-cmnt';
    cmntModal.innerHTML =
      '<div class="gexe-cmnt__backdrop"></div>' +
      '<div class="gexe-cmnt__dialog" role="dialog" aria-modal="true">' +
        '<div class="gexe-cmnt__head">' +
          '<div class="gexe-cmnt__title">Комментарий</div>' +
          '<button class="gexe-cmnt__close" aria-label="Закрыть"><i class="fa-solid fa-xmark"></i></button>' +
        '</div>' +
        '<div class="gexe-cmnt__body">' +
          '<textarea id="gexe-cmnt-text" placeholder="Ваш комментарий..." rows="3"></textarea>' +
        '</div>' +
        '<div class="gexe-cmnt__foot">' +
          '<button id="gexe-cmnt-send" class="glpi-act">Отправить</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(cmntModal);
    $('.gexe-cmnt__backdrop', cmntModal).addEventListener('click', closeCommentModal);
    $('.gexe-cmnt__close', cmntModal).addEventListener('click', closeCommentModal);
    const sendBtn = $('#gexe-cmnt-send', cmntModal);
    const txtArea = $('#gexe-cmnt-text', cmntModal);
    if (sendBtn) sendBtn.disabled = true;
    if (txtArea) {
      txtArea.addEventListener('input', () => {
        if (sendBtn) sendBtn.disabled = txtArea.value.trim() === '';
      });
    }
    if (sendBtn) sendBtn.addEventListener('click', sendComment);
    return cmntModal;
  }
  function openCommentModal(title, ticketId) {
    ensureCommentModal();
    $('.gexe-cmnt__title', cmntModal).textContent = title || 'Комментарий';
    cmntModal.setAttribute('data-ticket-id', String(ticketId || 0));
    const ta = $('#gexe-cmnt-text', cmntModal); if (ta) { ta.value = ''; ta.focus(); }
    const sendBtn = $('#gexe-cmnt-send', cmntModal); if (sendBtn) sendBtn.disabled = true;
    cmntModal.classList.add('is-open'); document.body.classList.add('glpi-modal-open');
  }
  function closeCommentModal() {
    if (!cmntModal) return;
    cmntModal.classList.remove('is-open'); document.body.classList.remove('glpi-modal-open');
  }
  function sendComment(){
    if (!cmntModal) return;
    const id  = Number(cmntModal.getAttribute('data-ticket-id') || '0');
    const txtEl = document.querySelector('#gexe-cmnt-text');
    const txt = (txtEl && txtEl.value ? txtEl.value : '').trim();
    if (!id || !txt) return;
    // закрываем окно сразу (важно на мобилках)
    closeCommentModal();
    const url = window.glpiAjax && glpiAjax.url;
    const nonce = window.glpiAjax && glpiAjax.nonce;
    if (!url || !nonce) return;
    lockAction(id, 'comment', true);
    const actionId = crypto.randomUUID();
    addPendingComment(id, txt, actionId);
    const fd = new FormData();
    fd.append('action', 'glpi_comment_add');
    fd.append('nonce', nonce);
    fd.append('ticket_id', String(id));
    fd.append('content', txt);
    fd.append('action_id', actionId);
    fetch(url, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(resp => {
        if (resp && resp.ok) {
          if (window.gexePrefetchedComments) delete window.gexePrefetchedComments[id];
          applyActionVisibility();
          refreshTicketMeta(id);
          markPendingSent(id, resp.followup_id, resp.created_at);
        } else {
          const pend = pendingComments[id];
          if (pend) { pend.el.remove(); delete pendingComments[id]; }
          lockAction(id, 'comment', false);
        }
      })
      .catch(()=>{
        const pend = pendingComments[id];
        if (pend) { pend.el.remove(); delete pendingComments[id]; }
        lockAction(id, 'comment', false);
      });
  }

  /* ========================= МОДАЛКА ПОДТВЕРЖДЕНИЯ ЗАВЕРШЕНИЯ ========================= */
  function ensureDoneModal() {
    if (doneModal) return doneModal;
    doneModal = document.createElement('div');
    doneModal.className = 'gexe-done';
    doneModal.innerHTML =
      '<div class="gexe-done__backdrop"></div>' +
      '<div class="gexe-done__dialog" role="dialog" aria-modal="true">' +
        '<div class="gexe-done__head">' +
          '<div class="gexe-done__title">Подтверждение</div>' +
          '<button class="gexe-done__close" aria-label="Закрыть"><i class="fa-solid fa-xmark"></i></button>' +
        '</div>' +
        '<div class="gexe-done__body">' +
          '<button id="gexe-done-confirm" class="glpi-act">Задача решена</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(doneModal);
    $('.gexe-done__backdrop', doneModal).addEventListener('click', closeDoneModal);
    $('.gexe-done__close', doneModal).addEventListener('click', closeDoneModal);
    $('#gexe-done-confirm', doneModal).addEventListener('click', sendDone);
    return doneModal;
  }
  function openDoneModal(ticketId) {
    ensureDoneModal();
    doneModal.setAttribute('data-ticket-id', String(ticketId || 0));
    doneModal.classList.add('is-open'); document.body.classList.add('glpi-modal-open');
  }
  function closeDoneModal() {
    if (!doneModal) return;
    doneModal.classList.remove('is-open'); document.body.classList.remove('glpi-modal-open');
  }
  function sendDone() {
    if (!doneModal) return;
    const id = Number(doneModal.getAttribute('data-ticket-id') || '0');
    if (!id) return;
    const btn = $('#gexe-done-confirm', doneModal);
    if (btn && isActionLocked(id, 'done')) return;
    if (btn) setActionLoading(btn, true);
    lockAction(id, 'done', true);
    const fd = new FormData();
    fd.append('action', 'glpi_ticket_resolve');
    fd.append('_ajax_nonce', (window.glpiAjax && glpiAjax.nonce) || '');
    fd.append('ticket_id', String(id));
    fd.append('solution_text', 'Задача решена');
    const timeout = setTimeout(() => { lockAction(id, 'done', false); if (btn) setActionLoading(btn, false); }, 10000);
    fetch(glpiAjax.url, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(resp => {
        clearTimeout(timeout);
        if (resp && resp.success) {
          if (btn) {
            btn.classList.remove('is-loading');
            btn.disabled = true;
            btn.setAttribute('aria-disabled', 'true');
          }
          closeDoneModal();
          const card = document.querySelector('.glpi-card[data-ticket-id="'+id+'"]');
          if (card) {
            card.setAttribute('data-status', String(glpiAjax.solvedStatus || 6));
            let badge = card.querySelector('.glpi-solved-badge');
            if (!badge) {
              badge = document.createElement('div');
              badge.className = 'glpi-solved-badge';
              badge.textContent = 'Решено';
              card.appendChild(badge);
            }
            card.classList.add('gexe-hide');
            recalcStatusCounts(); filterCards();
          }
          refreshTicketMeta(id);
          lockAction(id, 'done', false);
        } else {
          if (btn) setActionLoading(btn, false);
          lockAction(id, 'done', false);
          alert('Не удалось отметить задачу как решённую');
        }
      })
      .catch(() => {
        clearTimeout(timeout);
        if (btn) setActionLoading(btn, false);
        lockAction(id, 'done', false);
        alert('Ошибка сети');
      });
  }

  /* ========================= ДЕЙСТВИЯ ПО КАРТОЧКЕ (AJAX) ========================= */
  function doCardAction(type, ticketId, payload, actionId) {
    return new Promise(resolve => {
      const url = window.glpiAjax && glpiAjax.url;
      const nonce = window.glpiAjax && glpiAjax.nonce;
      if (!url || !nonce) { resolve({ success: false }); return; }
      const fd = new FormData();
      fd.append('action', 'glpi_card_action');
      fd.append('_ajax_nonce', nonce);
      fd.append('ticket_id', String(ticketId));
      fd.append('type', type);
      fd.append('payload', JSON.stringify(payload || {}));
      if (actionId) fd.append('action_id', actionId);
      fetch(url, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(resp => resolve(resp || { success: false }))
        .catch(() => resolve({ success: false }));
    });
  }

  function verifyStartComment(ticketId) {
    const url = window.glpiAjax && glpiAjax.url;
    const nonce = window.glpiAjax && glpiAjax.nonce;
    if (!url || !nonce) return;
    const fd = new FormData();
    fd.append('action', 'glpi_ticket_started');
    fd.append('_ajax_nonce', nonce);
    fd.append('ticket_id', String(ticketId));
    fetch(url, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(resp => {
        if (!(resp && resp.success && resp.data && resp.data.started)) {
          if (window.glpiToast) {
            window.glpiToast('Комментарий пока не виден, попробуйте обновить');
          } else {
            console.warn('Комментарий пока не виден, попробуйте обновить');
          }
        }
      })
      .catch(()=>{});
  }

  /* ========================= КНОПКИ ДЕЙСТВИЙ НА КАРТОЧКЕ ========================= */
  function injectCardActionButtons() {
    const idsToFetch = [];
    const chips = {};
    const preloaded = window.gexePrefetchedComments || {};
    $$('.glpi-card').forEach(card => {
      if ($('.gexe-card-actions', card)) return;
      const id = Number(card.getAttribute('data-ticket-id') || '0');
      if (!id) return;

      const bar = document.createElement('div');
      bar.className = 'gexe-card-actions';
      bar.innerHTML =
        '<button class="gexe-action-btn gexe-open-accept" title="Принять в работу"><i class="fa-solid fa-play"></i></button>';
      card.insertBefore(bar, card.firstChild);

      // чип со счётчиком комментариев (в левом футере)
      const foot = card.querySelector('.glpi-executor-footer');
      if (foot && !foot.querySelector('.glpi-comments-chip')) {
        const chip = document.createElement('span');
        chip.className = 'glpi-comments-chip';
        chip.innerHTML = '<i class="fa-regular fa-comment"></i> <span class="gexe-cmnt-count">0</span>';
        foot.appendChild(chip);
        chips[id] = chip;
        if (preloaded[id] && typeof preloaded[id].count === 'number') {
          chip.querySelector('.gexe-cmnt-count').textContent = String(preloaded[id].count);
        } else {
          idsToFetch.push(id);
        }
      }

    });

    if (idsToFetch.length) {
      fetchCommentCounts(idsToFetch, map => {
        idsToFetch.forEach(id => {
          const chip = chips[id];
          if (chip) {
            const n = map && typeof map[id] === 'number' ? map[id] : 0;
            chip.querySelector('.gexe-cmnt-count').textContent = String(n);
          }
        });
      });
    }
  }

  // Права/видимость
  function applyActionVisibility() {
    const uid = Number((window.glpiAjax && glpiAjax.user_glpi_id) || 0);
    $$('.glpi-card').forEach(card => {
      const bar = $('.gexe-card-actions', card);
      if (!bar) return;
      const btnComment = $('.gexe-open-comment', bar);
      const btnAccept  = $('.gexe-open-accept',  bar);
      const btnClose   = $('.gexe-open-close',   bar);

      if (uid <= 0) { // не авторизован — прячем все
        if (btnComment) btnComment.style.display = 'none';
        if (btnAccept)  btnAccept.style.display  = 'none';
        if (btnClose)   btnClose.style.display   = 'none';
        return;
      }

      const assignees = (card.getAttribute('data-assignees') || '')
        .split(',').map(s => parseInt(s, 10)).filter(n => n > 0);
      const isAssignee = assignees.includes(uid);

      // «Завершить» и «Принять» — только исполнителю
      if (!isAssignee) {
        if (btnAccept) btnAccept.style.display = 'none';
        if (btnClose)  btnClose.style.display  = 'none';
      }

      // Если уже есть «Принято в работу» — блокируем «Принять в работу»
      const url = window.glpiAjax && glpiAjax.url;
      const nonce = window.glpiAjax && glpiAjax.nonce;
      const ticketId = Number(card.getAttribute('data-ticket-id') || '0');
      if (btnAccept && url && nonce && ticketId) {
        const fd = new FormData();
        fd.append('action', 'glpi_ticket_started');
        fd.append('_ajax_nonce', nonce);
        fd.append('ticket_id', String(ticketId));
        fetch(url, { method: 'POST', body: fd })
          .then(r => r.json())
          .then(resp => {
            if (resp && resp.success && resp.data && resp.data.started) {
              btnAccept.disabled = true;
            }
          })
          .catch(()=>{});
      }
    });
  }

  /* ========================= КОЛ-ВО КОММЕНТАРИЕВ ========================= */
  function fetchCommentCounts(ids, cb){
    const url = window.glpiAjax && glpiAjax.url;
    const nonce = window.glpiAjax && glpiAjax.nonce;
    if (!url || !nonce || !Array.isArray(ids) || ids.length === 0) { cb({}); return; }
    const fd = new FormData();
    fd.append('action', 'glpi_count_comments_batch');
    fd.append('_ajax_nonce', nonce);
    fd.append('ticket_ids', ids.join(','));
    fetch(url, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(j => cb(j && typeof j.counts === 'object' ? j.counts : {}))
      .catch(()=>cb({}));
  }

  /* ========================= ОТКРЫТИЕ МОДАЛКИ ПО КЛИКУ НА КАРТОЧКУ ========================= */
  function bindCardOpen() {
    $$('.glpi-card').forEach(card => {
      const id = Number(card.getAttribute('data-ticket-id') || '0');
      if (!id) return;
      card.addEventListener('click', function (e) {
        // игнорируем клики по действиям/ссылкам
        if (e.target.closest('a,button,.gexe-card-actions')) return;
        ensureViewerModal();
        renderModalCard(card);
        openViewerModal();
        modalEl.setAttribute('data-ticket-id', String(id));
        loadComments(id);
      }, true);
    });
  }

  /* ========================= ДАТЫ: «N дней» и цвет-возраста ========================= */
  function ageInfo(iso) {
    if (!iso) return { text: '', cls: 'age-black' };
    const d = new Date(iso); if (isNaN(d.getTime())) return { text: '', cls: 'age-black' };
    const now = new Date();
    const days = Math.max(0, Math.floor((now - d) / (24 * 3600 * 1000)));
    const plural = n => (n % 10 === 1 && n % 100 !== 11) ? 'день' :
                       (n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 10 || n % 100 >= 20)) ? 'дня' : 'дней';
    let cls = 'age-blue';
    if (days <= 1) cls = 'age-blue';
    else if (days <= 7) cls = 'age-green';
    else if (days <= 30) cls = 'age-orange';
    else cls = 'age-red';
    return { text: days + ' ' + plural(days), cls };
  }
  function updateAgeFooters() {
    $$('.glpi-date-footer, .glpi-comment-date').forEach(el => {
      const r = ageInfo(el.getAttribute('data-date') || '');
      el.textContent = r.text;
      el.classList.remove('age-green','age-red','age-orange','age-blue','age-black');
      el.classList.add(r.cls);
    });
  }

  /* ========================= ФИЛЬТРАЦИЯ ========================= */
  function activeStatus() {
    const btn = document.querySelector('.status-filter-btn.active');
    return btn ? (btn.getAttribute('data-status') || 'all') : 'all';
  }

  function cardMatchesCategory(card) {
    if (!selectedCategories.length) return true;
    const cat = (card.getAttribute('data-category') || '').toLowerCase();
    return selectedCategories.includes(cat);
  }
  function cardMatchesExecutor(card) {
    const exec = document.querySelector('.executor-filter-btn.active');
    const v = exec ? (exec.getAttribute('data-exec') || 'all') : 'all';
    if (v === 'all') return true;
    const slugs = (card.getAttribute('data-executors') || '').toLowerCase().split(',').map(s => s.trim()).filter(Boolean);
    return slugs.includes(v);
  }
  function cardMatchesStatus(card) {
    const st = activeStatus();
    if (st === 'all') return true;
    return String(card.getAttribute('data-status') || '') === String(st);
  }
  function cardMatchesSearch(card) {
    const inp = document.getElementById('glpi-unified-search');
    const q = (inp && inp.value || '').trim().toLowerCase();
    if (!q) return true;
    return (card.textContent || '').toLowerCase().includes(q);
  }

  function filterCards() {
    const lateOn = !!document.querySelector('.glpi-newfilter-block.active');
    let visible = 0;
    $$('.glpi-card').forEach(card => {
      let hide = false;
      if (!cardMatchesStatus(card))   hide = true;
      if (!cardMatchesExecutor(card)) hide = true;
      if (!cardMatchesCategory(card)) hide = true;
      if (!cardMatchesSearch(card))   hide = true;
      if (lateOn && card.getAttribute('data-late') !== '1') hide = true;
      card.classList.toggle('gexe-hide', hide);
      if (!hide) visible++;
    });
    const c = document.getElementById('glpi-counter'); if (c) c.textContent = 'В фильтре: ' + visible;
    recalcLateCount();
  }

  function recalcStatusCounts() {
    const counts = { '1': 0, '2': 0, '3': 0, '4': 0 };
    let total = 0;
    $$('.glpi-card').forEach(card => {
      if (!cardMatchesExecutor(card)) return;
      if (!cardMatchesCategory(card)) return;
      const s = String(card.getAttribute('data-status') || '');
      if (counts.hasOwnProperty(s)) counts[s]++;
      total++;
    });
    const set = (st, n) => { const el = document.querySelector('.status-filter-btn[data-status="'+st+'"] .status-count'); if (el) el.textContent = String(n); };
    set('all', total); set('1', counts['1']); set('2', counts['2']); set('3', counts['3']); set('4', counts['4']);
  }

  function recalcCategoryVisibility() {
    const st = activeStatus();
    const visibleCats = new Set();
    $$('.glpi-card').forEach(card => {
      const s = String(card.getAttribute('data-status') || '');
      if (st !== 'all' && s !== st) return;
      const cat = (card.getAttribute('data-category') || '').toLowerCase();
      if (cat) visibleCats.add(cat);
    });
    $$('.glpi-cat-chip').forEach(tag => {
      const cat = (tag.getAttribute('data-cat') || '').toLowerCase();
      tag.style.display = (st !== 'all' && !visibleCats.has(cat)) ? 'none' : '';
    });
  }

  function recalcLateCount() {
    const el = document.querySelector('.glpi-newfilter-block .status-count'); if (!el) return;
    const st = activeStatus();
    const inp = document.getElementById('glpi-unified-search');
    const q = (inp && inp.value || '').trim().toLowerCase();
    let n = 0;
    $$('.glpi-card').forEach(card => {
      if (st !== 'all' && String(card.getAttribute('data-status') || '') !== st) return;
      if (q && !(card.textContent || '').toLowerCase().includes(q)) return;
      if (card.getAttribute('data-late') === '1') n++;
    });
    el.textContent = String(n);
  }

  function bindStatusAndSearch() {
    $$('.status-filter-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        $$('.status-filter-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        // при выборе статуса сбрасываем выбранные категории
        selectedCategories = [];
        $$('.glpi-cat-chip').forEach(b => { b.classList.remove('active'); b.setAttribute('aria-pressed','false'); });
        const box = document.getElementById('glpi-categories-inline');
        const reset = box ? box.querySelector('.glpi-cat-reset') : null;
        if (reset) reset.hidden = true;
        localStorage.removeItem('glpi.categories.selected');
        recalcStatusCounts(); recalcCategoryVisibility(); filterCards();
      });
    });
    const inp = document.getElementById('glpi-unified-search');
    if (inp) inp.addEventListener('input', debounce(() => { filterCards(); }, 120));
  }

  /* ========================= ИНИЦИАЛИЗАЦИЯ ========================= */
  document.addEventListener('DOMContentLoaded', function () {
    ensureOverdueBlock();
    initCategoryToggle();
    bindNewTaskButton();

    injectCardActionButtons();
    applyActionVisibility();
    bindCardOpen();

    bindStatusAndSearch();
    recalcStatusCounts();
    recalcCategoryVisibility();
    filterCards();
    updateAgeFooters();
    setInterval(updateAgeFooters, 30 * 60 * 1000); // каждые 30 минут

    // рефильтровать по внешнему сигналу
    document.addEventListener('gexe:filters:changed', () => { recalcStatusCounts(); filterCards(); });
  });
})();
