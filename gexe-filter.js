/**
 * gexe-filter.js — панель фильтров, поиск, карточки, модалки и действия
 * Требования:
 *  - window.glpiAjax локализуется из glpi-modal-actions.php (url, nonce, user_glpi_id)
 *  - HTML карточек и шапки — как в шаблоне gexe-copy.php
 */

(function () {
  'use strict';

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

  /* ========================= ПРЕДЗАГРУЖЕННЫЕ КОММЕНТАРИИ ========================= */
  // Комментарии подгружаются на сервере и передаются через window.gexePrefetchedComments

  /* ========================= ПОИСК (СОЗДАТЬ СВЕРХУ) ========================= */
  function ensureSearchOnTop() {
    const root = document.querySelector('.glpi-filtering-panel .glpi-header-row')
      || document.querySelector('.glpi-filtering-panel');
    if (!root) return;

    // берем существующее приветствие, если оно уже есть в DOM
    let greetingHTML = '';
    const oldGreeting = document.querySelector('.glpi-user-greeting');
    if (oldGreeting) greetingHTML = oldGreeting.outerHTML;

    // убираем все предыдущие поля поиска и блоки
    document.querySelectorAll('.glpi-search-block, .glpi-search-row, .glpi-search-input')
      .forEach(el => el.remove());

    // создаём заново и вставляем первым; приветствие (если есть) идёт перед полем
    const wrap = document.createElement('div');
    wrap.className = 'glpi-search-row';
    wrap.innerHTML = (greetingHTML || '') +
      '<input type="text" id="glpi-unified-search" class="glpi-search-input" placeholder="Поиск...">';
    root.insertBefore(wrap, root.firstChild);
    // Подстраховка: кнопка, к которой привязывается glpi-new-task.php
    if (!document.getElementById('glpi-btn-new-ticket')) {
      const b = document.createElement('button');
      b.id = 'glpi-btn-new-ticket';
      b.className = 'gnt-open';
      b.style.display = 'none';
      document.body.appendChild(b);
    }
  }

  /* ========================= ВСПОМОГАТЕЛЬНОЕ: slug для категорий (с кириллицей) ========================= */
  function slugify(txt){
    const map = {
      'а':'a','б':'b','в':'v','г':'g','д':'d','е':'e','ё':'e','ж':'zh','з':'z','и':'i','й':'y','к':'k','л':'l','м':'m','н':'n','о':'o','п':'p','р':'r','с':'s','т':'t','у':'u','ф':'f','х':'h','ц':'c','ч':'ch','ш':'sh','щ':'sch','ъ':'','ы':'y','ь':'','э':'e','ю':'yu','я':'ya'
    };
    return (txt||'').toString().toLowerCase()
      .split('').map(ch => map[ch] ?? ch).join('')
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

  /* ========================= «Пора тушить» и «Новая заявка» ========================= */
  function ensureExtraStatusBlocks() {
    const row = document.querySelector('.glpi-status-blocks,.glpi-status-row,.glpi-header-row');
    if (!row) return;

    // Кнопка «Пора тушить»: действует как фильтр по data-late="1"
    if (!document.querySelector('.glpi-newfilter-block')) {
      const btn = document.createElement('button');
      btn.className = 'glpi-status-block glpi-newfilter-block';
      btn.innerHTML = '<div class="status-count">0</div><div class="status-label">Пора тушить</div>';
      btn.addEventListener('click', () => {
        btn.classList.toggle('active');
        document.dispatchEvent(new CustomEvent('gexe:filters:changed'));
      });
      row.appendChild(btn);
    }

    // Кнопка «Новая заявка» — открываем модалку из glpi-new-task.php
    // Удаляем все существующие кнопки, чтобы не было дублей
    document.querySelectorAll('.glpi-newtask-block').forEach(btn => btn.remove());
    const add = document.createElement('button');
    add.className = 'glpi-status-block glpi-newtask-block';
    add.innerHTML = '<div class="status-count"><i class="fa-regular fa-file-lines"></i></div><div class="status-label">новая заявка</div>';
    add.addEventListener('click', openNewTaskModal);
    row.appendChild(add);
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

  /* ========================= КАТЕГОРИИ: СВОРАЧИВАНИЕ/РАЗВОРАЧИВАНИЕ ========================= */
  function initCategoryCollapser(){
    const row = document.querySelector('.glpi-category-tags');
    if (!row) return;
    if (!document.querySelector('.glpi-cat-toggle')) {
      const tgl = document.createElement('button');
      tgl.className = 'glpi-cat-toggle';
      tgl.setAttribute('aria-expanded','false');
      tgl.innerHTML = '<span class="tw">▸</span> Сегодня в программе';
      const header = document.querySelector('.glpi-header-row');
      const statusRow = header && header.querySelector('.glpi-status-row');
      if (header) {
        header.insertBefore(tgl, statusRow);
      } else {
        row.parentNode.insertBefore(tgl, row);
      }
      tgl.addEventListener('click', () => {
        const opened = row.classList.toggle('collapsed') ? false : true;
        tgl.setAttribute('aria-expanded', String(opened));
        tgl.querySelector('.tw').textContent = opened ? '▾' : '▸';
      });
      // старт — свёрнуты
      row.classList.add('collapsed');
    }
  }

  // Делегированный клик по тегу категории
  let currentCategory = 'all';
  function bindCategoryClicks(){
    prepareCategoryTags();
    document.addEventListener('click', function(e){
      const tgt = e.target.closest('.category-filter-btn, .glpi-category-tag');
      if(!tgt || !tgt.hasAttribute('data-cat')) return;
      e.preventDefault();
      const cat = String(tgt.getAttribute('data-cat') || 'all').toLowerCase();
      const isActive = tgt.classList.contains('active');
      $$('.category-filter-btn, .glpi-category-tag').forEach(b => b.classList.remove('active'));
      if (isActive) {
        currentCategory = 'all';
      } else {
        currentCategory = cat;
        tgt.classList.add('active');
      }
      recalcStatusCounts();
      recalcCategoryVisibility();
      filterCards();
    }, true);
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
  function closeViewerModal() { if (!modalEl) return; modalEl.classList.remove('gexe-modal--open'); document.body.classList.remove('glpi-modal-open'); }

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
      '<button class="gexe-action-btn gexe-open-comment" title="Комментарии"><i class="fa-regular fa-comment"></i></button>' +
      '<button class="gexe-action-btn gexe-open-accept"  title="Принять в работу"><i class="fa-solid fa-play"></i></button>' +
      '<button class="gexe-action-btn gexe-open-close"   title="Завершить"><i class="fa-solid fa-check"></i></button>';
    clone.insertBefore(bar, clone.firstChild);

    const btnComment = $('.gexe-open-comment', bar);
    const btnAccept  = $('.gexe-open-accept',  bar);
    const btnClose   = $('.gexe-open-close',   bar);

    on(btnComment, 'click', e => {
      e.stopPropagation();
      const title = (clone.querySelector('.glpi-topic') || {}).textContent || ('Задача #' + id);
      openCommentModal(title.trim(), id);
    });

    on(btnAccept, 'click', e => {
      e.stopPropagation();
      if (btnAccept.disabled) return;
      doCardAction('start', id).then(ok => {
        if (ok) {
          btnAccept.disabled = true;
          const orig = document.querySelector('.glpi-card[data-ticket-id="'+id+'"]');
          if (orig) {
            orig.setAttribute('data-status','2');
            const oBtn = orig.querySelector('.gexe-open-accept');
            if (oBtn) oBtn.disabled = true;
          }
          recalcStatusCounts(); filterCards();
        }
      });
    });

    on(btnClose, 'click', e => {
      e.stopPropagation();
      openDoneModal(id);
    });

    wrap.appendChild(clone);
    applyActionVisibility();
  }

  function loadComments(ticketId, page = 1) {
    const box = $('#gexe-comments');
    if (box) box.innerHTML = '';
    const modalCntInit = modalEl && modalEl.querySelector('.glpi-modal__comments-title .gexe-cmnt-count');
    if (modalCntInit) modalCntInit.textContent = '0';

    const preloaded = window.gexePrefetchedComments && window.gexePrefetchedComments[ticketId];
    if (preloaded) {
      applyCommentsData(ticketId, preloaded);
      return;
    }

    const base = window.glpiAjax && glpiAjax.rest;
    const nonce = window.glpiAjax && glpiAjax.restNonce;
    if (!base || !nonce) return;
    const url = base + 'comments?ticket_id=' + encodeURIComponent(ticketId) + '&page=' + page + '&_=' + Date.now();
    if (commentsController) commentsController.abort();
    commentsController = new AbortController();
    fetch(url, { headers: { 'X-WP-Nonce': nonce, 'Cache-Control': 'no-cache' }, signal: commentsController.signal })
      .then(r => r.json())
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
    if (data && typeof data.time_ms === 'number') {
      const stat = document.getElementById('glpi-comments-time');
      if (stat) stat.textContent = String(data.time_ms);
    }
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
    const txt = (document.querySelector('#gexe-cmnt-text')?.value || '').trim();
    if (!id || !txt) return;
    // закрываем окно сразу (важно на мобилках)
    closeCommentModal();
    const url = window.glpiAjax && glpiAjax.url;
    const nonce = window.glpiAjax && glpiAjax.nonce;
    if (!url || !nonce) return;
    const fd = new FormData();
    fd.append('action', 'glpi_add_comment');
    fd.append('_ajax_nonce', nonce);
    fd.append('ticket_id', String(id));
    fd.append('content', txt);
    fetch(url, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(resp => {
        if (resp && resp.ok) {
          // обновим счётчики комментариев
          const card = document.querySelector('.glpi-card[data-ticket-id="'+id+'"]');
          const cnt = card && card.querySelector('.gexe-cmnt-count');
          const modalCnt = modalEl && modalEl.querySelector('.glpi-modal__comments-title .gexe-cmnt-count');
          const newCount = (resp && typeof resp.count === 'number')
            ? resp.count
            : parseInt((cnt?.textContent || modalCnt?.textContent || '0'), 10) + 1;
          if (cnt) cnt.textContent = String(newCount);
          if (modalCnt) modalCnt.textContent = String(newCount);
          // обновим комментарии в просмотрщике, если открыт
          if (modalEl && modalEl.classList.contains('gexe-modal--open')) {
            const openedId = Number(modalEl.getAttribute('data-ticket-id') || '0');
            if (openedId === id) {
              if (window.gexePrefetchedComments) delete window.gexePrefetchedComments[id];
              loadComments(id);
            }
          }
          applyActionVisibility();
        }
      })
      .catch(()=>{});
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
    closeDoneModal();
    doCardAction('done', id, { solution_text: 'Выполнено' }).then(ok => {
      if (ok) {
        const card = document.querySelector('.glpi-card[data-ticket-id="'+id+'"]');
        if (card) {
          card.classList.add('gexe-hide');
          recalcStatusCounts(); filterCards();
        }
      }
    });
  }

  /* ========================= ДЕЙСТВИЯ ПО КАРТОЧКЕ (AJAX) ========================= */
  function doCardAction(type, ticketId, payload) {
    return Promise.resolve(false);
  }

  /* ========================= КНОПКИ ДЕЙСТВИЙ НА КАРТОЧКЕ ========================= */
  function injectCardActionButtons() {}

  // Права/видимость
  function applyActionVisibility() {}

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
    if (currentCategory === 'all') return true;
    const cat = (card.getAttribute('data-category') || '').toLowerCase();
    return cat === currentCategory;
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
    $$('.glpi-category-tag.category-filter-btn').forEach(tag => {
      const cat = (tag.getAttribute('data-cat') || '').toLowerCase();
      tag.style.display = (st !== 'all' && !visibleCats.has(cat)) ? 'none' : 'inline-flex';
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
        currentCategory = 'all';
        $$('.category-filter-btn, .glpi-category-tag').forEach(b => b.classList.remove('active'));
        recalcStatusCounts(); recalcCategoryVisibility(); filterCards();
      });
    });
    const inp = document.getElementById('glpi-unified-search');
    if (inp) inp.addEventListener('input', debounce(() => { filterCards(); }, 120));
  }

  /* ========================= ИНИЦИАЛИЗАЦИЯ ========================= */
  document.addEventListener('DOMContentLoaded', function () {
    ensureSearchOnTop();
    ensureExtraStatusBlocks();
    initCategoryCollapser();
    bindCategoryClicks();

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
