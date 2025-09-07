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
  window.GEXE_DEBUG = window.GEXE_DEBUG || false;

  const ERROR_MAP = {
    not_logged_in: 'Сессия истекла. Войдите в систему.',
    nonce_failed: 'Обновите страницу (просрочен ключ безопасности).',
    NONCE_EXPIRED: 'Обновите страницу (просрочен ключ безопасности).',
    NO_PERMISSION: 'Недостаточно прав.',
    no_glpi_id_for_current_user: 'В профиле WP не указан GLPI ID.',
    assignee_not_mapped_to_glpi: 'Выбранный исполнитель не привязан к GLPI.',
    ticket_not_found: 'Заявка не найдена.',
    SQL_OP_FAILED: details => 'Ошибка записи в GLPI. Код: ' + (details || '') + '.',
    EMPTY_CONTENT: 'Введите комментарий',
    bad_response: 'Не удалось обработать ответ сервера.',
    network_error: 'Ошибка сети',
    NO_PERMISSION: 'Нет прав на заявку',
    SQL_OP_FAILED: 'Операция не выполнена',
  };

  function ensureNoticeHost() {
    let parent = null;
    if (cmntModal && cmntModal.classList.contains('is-open')) {
      parent = $('.gexe-cmnt__dialog', cmntModal);
    } else if (doneModal && doneModal.classList.contains('is-open')) {
      parent = $('.gexe-done__dialog', doneModal);
    } else if (modalEl && modalEl.classList.contains('gexe-modal--open')) {
      parent = $('.gexe-modal__dialog', modalEl);
    } else {
      parent = document.body;
    }
    if (!parent) return null;
    let host = $('.gexe-notices', parent);
    if (!host) {
      host = document.createElement('div');
      host.className = 'gexe-notices';
      parent.prepend(host);
    }
    return host;
  }

  function showNotice(type, text) {
    const host = ensureNoticeHost();
    if (!host) return;
    host.innerHTML = '<div class="gexe-notice gexe-notice-' + type + '">' +
      '<span class="gexe-notice__text"></span>' +
      '<button class="gexe-notice__close" aria-label="Закрыть">&times;</button>' +
      '</div>';
    const notice = host.firstElementChild;
    const closeBtn = $('.gexe-notice__close', notice);
    if (closeBtn) closeBtn.addEventListener('click', () => { host.innerHTML = ''; });
    const escHandler = (e) => {
      if (e.key === 'Escape') {
        host.innerHTML = '';
        document.removeEventListener('keydown', escHandler);
      }
    };
    document.addEventListener('keydown', escHandler);
    $('.gexe-notice__text', notice).textContent = text;
    notice.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function showError(code, details, status) {
    let msg;
    const m = ERROR_MAP[code];
    if (typeof m === 'function') msg = m(details);
    else if (typeof m === 'string') msg = m;
    if (!msg) msg = 'Неизвестная ошибка' + (status ? ' (' + status + ')' : '');
    showNotice('error', msg);
  }

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

  function markAccepted(ticketId, btn, payload) {
    if (!btn) return;
    btn.innerHTML = '<i class="fa-solid fa-check"></i>';
    btn.disabled = true;
    btn.setAttribute('aria-disabled', 'true');
    btn.setAttribute('title', 'Уже принято');

    const cardEl = document.querySelector('.glpi-card[data-ticket-id="' + ticketId + '"]');
    if (cardEl) {
      cardEl.setAttribute('data-status', '2');
      cardEl.setAttribute('data-unassigned', '0');
      if (payload && payload.assigned_glpi_id) {
        cardEl.setAttribute('data-assignees', String(payload.assigned_glpi_id));
      }
      const cardBtn = cardEl.querySelector('.gexe-open-accept');
      if (cardBtn && cardBtn !== btn) {
        setActionLoading(cardBtn, false);
        cardBtn.disabled = true;
        cardBtn.innerHTML = '<i class="fa-solid fa-check"></i>';
        cardBtn.setAttribute('aria-disabled', 'true');
        cardBtn.setAttribute('title', 'Уже принято');
      }
      const footer = cardEl.querySelector('.glpi-executor-footer');
      if (footer && !footer.querySelector('.glpi-executors')) {
        const span = document.createElement('span');
        span.className = 'glpi-executors';
        span.innerHTML = '<i class="fa-solid fa-user-tie glpi-executor"></i> Вы';
        footer.appendChild(span);
      }
    }

    if (modalEl && modalEl.getAttribute('data-ticket-id') === String(ticketId)) {
      const mb = modalEl.querySelector('.gexe-open-accept');
      if (mb && mb !== btn) {
        setActionLoading(mb, false);
        mb.disabled = true;
        mb.innerHTML = '<i class="fa-solid fa-check"></i>';
        mb.setAttribute('aria-disabled', 'true');
        mb.setAttribute('title', 'Уже принято');
      }
    }
  }

  function debugRequest(url, payload) {
    if (!window.GEXE_DEBUG) return;
    const safe = {};
    Object.keys(payload || {}).forEach(k => {
      if (/pass|nonce|token/i.test(k)) return;
      safe[k] = payload[k];
    });
    console.debug('AJAX request:', url, safe);
  }
  async function debugResponse(res) {
    if (!window.GEXE_DEBUG) return;
    console.debug('AJAX response status:', res.status);
    try { console.debug('AJAX json:', await res.clone().json()); }
    catch (e) { console.debug('AJAX text:', await res.clone().text()); }
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

    if (btn.classList.contains('is-error')) {
      btn.classList.remove('is-error');
      btn.innerHTML = '<i class="fa-solid fa-play"></i>';
      btn.setAttribute('title', 'Принять в работу');
    }

    const ajax = window.gexeAjax || window.glpiAjax;
    if (!ajax || !ajax.url) return;
    lockAction(ticketId, 'accept', true);
    setActionLoading(btn, true);

    const fd = new FormData();
    fd.append('action', 'glpi_ticket_accept_sql');
    fd.append('ticket_id', String(ticketId));
    fd.append('assignee_glpi_id', String(ajax.user_glpi_id || 0));
    fd.append('add_comment', '1');
    const nonceKey = glpiAjax.nonce_key || 'nonce';
    fd.append(nonceKey, glpiAjax.nonce || '');
    fd.append('nonce', glpiAjax.nonce || '');
    fd.append('_ajax_nonce', glpiAjax.nonce || '');

    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 15000);

    const send = async retry => {
      debugRequest(ajax.url, Object.fromEntries(fd.entries()));
      const res = await fetch(ajax.url, { method: 'POST', body: fd, signal: controller.signal });
      await debugResponse(res);
      let data = null;
      try { data = await res.clone().json(); }
      catch (e) { try { await res.clone().text(); } catch (e2) {} }
      if (res.status === 403 && data && data.error === 'nonce_failed' && !retry) {
        await refreshActionsNonce();
        fd.set(nonceKey, ajax.nonce || '');
        fd.set('nonce', ajax.nonce || '');
        fd.set('_ajax_nonce', ajax.nonce || '');
        return send(true);
      }
      return { res, data };
    };

    send(false).then(({ res, data }) => {
      setActionLoading(btn, false);
      if (!res.ok) {
        const errCode = data && data.error && data.error.code ? data.error.code : (data && data.error) ? data.error : 'bad_response';
        showError(errCode, data && data.details, res.status);
        return;
      }
      if (!data) {
        showError('bad_response', null, res.status);
        return;
      }
      const ok = data.ok || data.success;
      if (!ok) {
        const err = data.error || {};
        const code = err.code || err;
        if (code === 'ALREADY_ACCEPTED') {
          markAccepted(ticketId, btn);
          refreshTicketMeta(ticketId);
          recalcStatusCounts(); filterCards();
          showNotice('success', 'Уже в работе');
          return;
        }
        if (code === 'NO_PERMISSION') {
          showNotice('error', 'Нет прав на заявку');
          return;
        }
        if (code === 'SQL_OP_FAILED') {
          showNotice('error', 'Операция не выполнена');
          btn.classList.add('is-error');
          btn.innerHTML = 'Повторить';
          return;
        }
        showError(code, err.details, res.status);
        return;
      }
      markAccepted(ticketId, btn, data.payload);
      insertFollowup(ticketId, data.payload && data.payload.followup);
      refreshTicketMeta(ticketId);
      recalcStatusCounts(); filterCards();
      const msg = (data.payload && data.payload.already) ? 'Уже в работе' : 'Заявка принята в работу';
      showNotice('success', msg);
    }).catch(err => {
      setActionLoading(btn, false);
      if (err && err.name === 'AbortError') showNotice('error','Таймаут запроса');
      else showError('network_error');
    }).finally(() => {
      clearTimeout(timeoutId);
      lockAction(ticketId, 'accept', false);
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
    const nonceKey = ajax.nonce_key || 'nonce';
    fd.append(nonceKey, nonce);
    fd.append('nonce', nonce);
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

  function resetCategories(){
    selectedCategories = [];
    const box = document.getElementById('glpi-categories-inline');
    if (box){
      $$('.glpi-cat-chip', box).forEach(c => { c.classList.remove('active'); c.setAttribute('aria-pressed','false'); });
      const resetBtn = box.querySelector('.glpi-cat-reset');
      if (resetBtn) resetBtn.hidden = true;
    }
    localStorage.removeItem('glpi.categories.selected');
    recalcStatusCounts();
    filterCards();
  }

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
    reset.addEventListener('click', resetCategories);

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

  function reloadComments(ticketId) {
    const url = window.glpiAjax && glpiAjax.url;
    const nonce = window.glpiAjax && glpiAjax.nonce;
    if (!url || !nonce) return;
    const fd = new FormData();
    fd.append('action', 'glpi_get_comments');
    const nonceKey = glpiAjax.nonce_key || 'nonce';
    fd.append(nonceKey, nonce);
    fd.append('nonce', nonce);
    fd.append('_ajax_nonce', nonce);
    fd.append('ticket_id', String(ticketId));
    fetch(url, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(data => applyCommentsData(ticketId, data))
      .catch(()=>{});
  }

  function insertFollowup(ticketId, f) {
    if (!f) return;
    const box = $('#gexe-comments');
    if (box) {
      const wrap = document.createElement('div');
      wrap.className = 'glpi-comment';
      const meta = document.createElement('div');
      meta.className = 'meta';
      const author = document.createElement('span');
      author.className = 'glpi-comment-author';
      author.innerHTML = '<i class="fa-regular fa-user"></i> Вы';
      const date = document.createElement('span');
      date.className = 'glpi-comment-date';
      date.setAttribute('data-date', f.date || '');
      meta.appendChild(author); meta.appendChild(date);
      const text = document.createElement('div');
      text.className = 'text';
      const raw = String(f.content || '').trim();
      if (raw) {
        raw.split(/\n+/).map(s => s.trim()).filter(Boolean).forEach(line => {
          const p = document.createElement('p');
          p.className = 'glpi-txt';
          p.textContent = line;
          text.appendChild(p);
        });
      } else if (Array.isArray(f.documents) && f.documents.length) {
        const p = document.createElement('p');
        p.className = 'glpi-txt';
        const base = (window.glpiAjax && glpiAjax.webBase ? glpiAjax.webBase.replace(/\/$/, '') : '');
        const pref = f.documents.length > 1 ? 'Приложены документы: ' : 'Приложен документ: ';
        p.append(document.createTextNode(pref));
        f.documents.forEach((d, idx) => {
          const a = document.createElement('a');
          const ext = d.extension ? ' ' + d.extension : '';
          a.textContent = 'документ' + ext;
          a.href = base + '/front/document.send.php?docid=' + d.document_id + '&tickets_id=' + ticketId;
          a.target = '_blank';
          a.rel = 'noopener';
          p.appendChild(a);
          if (idx < f.documents.length - 1) p.appendChild(document.createTextNode(', '));
        });
        text.appendChild(p);
      }
      wrap.appendChild(meta); wrap.appendChild(text);
      box.insertBefore(wrap, box.firstChild);
      updateAgeFooters();
    }
    const modalCnt = modalEl && modalEl.querySelector('.glpi-modal__comments-title .gexe-cmnt-count');
    if (modalCnt) modalCnt.textContent = String((parseInt(modalCnt.textContent,10) || 0) + 1);
    const cardCnt = document.querySelector('.glpi-card[data-ticket-id="'+ticketId+'"] .gexe-cmnt-count');
    if (cardCnt) cardCnt.textContent = String((parseInt(cardCnt.textContent,10) || 0) + 1);
  }

  /* ========================= МОДАЛКА КОММЕНТАРИЯ ========================= */
  const MAX_COMMENT_LEN = 4000;
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
          '<textarea id="gexe-cmnt-text" placeholder="Ваш комментарий..." rows="3" maxlength="4000"></textarea>' +
          '<div id="gexe-cmnt-err" class="gexe-cmnt__err" aria-live="polite"></div>' +
        '</div>' +
        '<div class="gexe-cmnt__foot">' +
          '<div class="gexe-cmnt__counter"><span id="gexe-cmnt-count">0</span>/4000</div>' +
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
      txtArea.addEventListener('input', updateCommentValidation);
    }
    if (sendBtn) sendBtn.addEventListener('click', () => sendComment(false));
    return cmntModal;
  }
  function updateCommentValidation(force) {
    const txtArea = $('#gexe-cmnt-text', cmntModal);
    const sendBtn = $('#gexe-cmnt-send', cmntModal);
    const cntEl = $('#gexe-cmnt-count', cmntModal);
    const errEl = $('#gexe-cmnt-err', cmntModal);
    const raw = txtArea && txtArea.value ? txtArea.value : '';
    const len = raw.length;
    if (cntEl) cntEl.textContent = String(len);
    let msg = '';
    if (len > MAX_COMMENT_LEN) msg = 'Слишком длинный комментарий';
    else if (force && len === 0) msg = ERROR_MAP.EMPTY_CONTENT;
    if (errEl) errEl.textContent = msg;
    if (sendBtn) sendBtn.disabled = len === 0 || len > MAX_COMMENT_LEN;
    return !msg && len > 0;
  }
  function showCommentError(code, details, status) {
    const errEl = $('#gexe-cmnt-err', cmntModal);
    let msg;
    const m = ERROR_MAP[code];
    if (typeof m === 'function') msg = m(details);
    else if (typeof m === 'string') msg = m;
    if (!msg) msg = 'Неизвестная ошибка' + (status ? ' (' + status + ')' : '');
    if (errEl) errEl.textContent = msg;
  }
  function clearCommentError() {
    const errEl = $('#gexe-cmnt-err', cmntModal);
    if (errEl) errEl.textContent = '';
  }
  function openCommentModal(title, ticketId) {
    ensureCommentModal();
    $('.gexe-cmnt__title', cmntModal).textContent = title || 'Комментарий';
    cmntModal.setAttribute('data-ticket-id', String(ticketId || 0));
    const ta = $('#gexe-cmnt-text', cmntModal); if (ta) { ta.value = ''; ta.focus(); }
    updateCommentValidation();
    cmntModal.classList.add('is-open'); document.body.classList.add('glpi-modal-open');
  }
  function closeCommentModal() {
    if (!cmntModal) return;
    cmntModal.classList.remove('is-open'); document.body.classList.remove('glpi-modal-open');
  }
  async function sendComment(retried) {
    if (!cmntModal) return;
    const id  = Number(cmntModal.getAttribute('data-ticket-id') || '0');
    const txtEl = $('#gexe-cmnt-text', cmntModal);
    const btn = $('#gexe-cmnt-send', cmntModal);
    if (!id || !txtEl || !btn) return;
    if (!updateCommentValidation(true)) return;
    const txt = txtEl.value.trim();
    const url = window.glpiAjax && glpiAjax.url;
    const nonce = window.glpiAjax && glpiAjax.nonce;
    if (!url || !nonce) return;
    lockAction(id, 'comment', true);
    setActionLoading(btn, true);
    clearCommentError();
    const fd = new FormData();
    fd.append('action', 'glpi_comment_add');
    const nonceKey = glpiAjax.nonce_key || 'nonce';
    fd.append(nonceKey, nonce);
    fd.append('nonce', nonce);
    fd.append('_ajax_nonce', nonce);
    fd.append('ticket_id', String(id));
    fd.append('content', txt);
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 15000);
    debugRequest(url, Object.fromEntries(fd.entries()));
    try {
      const res = await fetch(url, { method: 'POST', body: fd, signal: controller.signal });
      await debugResponse(res);
      let data = null;
      try { data = await res.clone().json(); }
      catch (e) { try { await res.clone().text(); } catch (e2) {} }
      if (!res.ok || !data || !data.ok) {
        const code = data && data.error ? data.error : 'bad_response';
        if (code === 'NONCE_EXPIRED' && !retried) {
          try {
            await refreshActionsNonce();
            showNotice('success', 'Обновили доступ');
            return sendComment(true);
          } catch (e) {
            showCommentError('network_error');
            return;
          }
        }
        showCommentError(code, data && data.details, res.status);
        return;
      }
      txtEl.value = '';
      updateCommentValidation();
      insertFollowup(id, data.payload && data.payload.followup);
      refreshTicketMeta(id);
      setActionLoading(btn, false);
      btn.classList.add('is-done');
      setTimeout(() => { btn.classList.remove('is-done'); }, 1500);
      closeCommentModal();
    } catch (err) {
      showCommentError('network_error');
    } finally {
      clearTimeout(timeoutId);
      lockAction(id, 'comment', false);
      setActionLoading(btn, false);
    }
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
  async function sendDone() {
    if (!doneModal) return;
    const id = Number(doneModal.getAttribute('data-ticket-id') || '0');
    if (!id) return;
    const btn = $('#gexe-done-confirm', doneModal);
    if (btn && isActionLocked(id, 'done')) return;
    if (btn) setActionLoading(btn, true);
    lockAction(id, 'done', true);
    const fd = new FormData();
    fd.append('action', 'glpi_ticket_resolve');
    const nonce = (window.glpiAjax && glpiAjax.nonce) || '';
    const nonceKey = glpiAjax.nonce_key || 'nonce';
    fd.append(nonceKey, nonce);
    fd.append('nonce', nonce);
    fd.append('_ajax_nonce', nonce);
    fd.append('ticket_id', String(id));
    fd.append('solution_text', 'Завершено');
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 15000);
    debugRequest(glpiAjax.url, Object.fromEntries(fd.entries()));
    try {
      const res = await fetch(glpiAjax.url, { method: 'POST', body: fd, signal: controller.signal });
      await debugResponse(res);
      let data = null;
      try { data = await res.clone().json(); }
      catch (e) { try { await res.clone().text(); } catch (e2) {} }
      if (!res.ok) {
        showError(data && data.error ? data.error : 'bad_response', data && data.details, res.status);
        return;
      }
      if (!data) {
        showError('bad_response', null, res.status);
        return;
      }
      if (!data.ok) {
        showError(data.error, data.details, res.status);
        return;
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
      insertFollowup(id, data.payload && data.payload.followup);
      refreshTicketMeta(id);
      showNotice('success','Задача закрыта');
    } catch (err) {
      if (err && err.name === 'AbortError') showNotice('error','Таймаут запроса');
      else showError('network_error');
    } finally {
      clearTimeout(timeoutId);
      if (btn) setActionLoading(btn, false);
      lockAction(id, 'done', false);
    }
  }

  /* ========================= ДЕЙСТВИЯ ПО КАРТОЧКЕ (AJAX) ========================= */
  function doCardAction(type, ticketId, payload, actionId) {
    return new Promise(resolve => {
      const url = window.glpiAjax && glpiAjax.url;
      const nonce = window.glpiAjax && glpiAjax.nonce;
      const nonceKey = (window.glpiAjax && glpiAjax.nonce_key) || 'nonce';
      if (!url || !nonce) { resolve({ success: false }); return; }
      const fd = new FormData();
      fd.append('action', 'glpi_card_action');
      fd.append(nonceKey, nonce);
      fd.append('nonce', nonce);
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
        const nonceKey = glpiAjax.nonce_key || 'nonce';
        fd.append(nonceKey, nonce);
        fd.append('nonce', nonce);
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
    const nonceKey = glpiAjax.nonce_key || 'nonce';
    fd.append(nonceKey, nonce);
    fd.append('nonce', nonce);
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
        const wasActive = btn.classList.contains('active');
        $$('.status-filter-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        if (wasActive) {
          resetCategories();
          recalcCategoryVisibility();
        } else {
          recalcStatusCounts();
          recalcCategoryVisibility();
          filterCards();
        }
      });
    });
    const inp = document.getElementById('glpi-unified-search');
    const clearBtn = document.querySelector('.gexe-search-clear');
    if (inp) {
      const toggleClear = () => { if (clearBtn) clearBtn.hidden = !(inp.value && inp.value.length); };
      const debouncedFilter = debounce(() => { filterCards(); }, 120);
      inp.addEventListener('input', () => { toggleClear(); debouncedFilter(); });
      inp.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
          inp.value = '';
          toggleClear();
          filterCards();
        }
      });
      if (clearBtn) {
        clearBtn.addEventListener('click', () => {
          inp.value = '';
          inp.focus();
          toggleClear();
          filterCards();
        });
      }
      toggleClear();
    }
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
