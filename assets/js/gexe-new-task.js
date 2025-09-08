(function(){
  'use strict';

  const gexeAjax = window.gexeAjax || window.glpiAjax || {};
  window.gexeAjax = gexeAjax;
  window.glpiAjax = window.glpiAjax || gexeAjax;

  let modal = null;
  let categoriesLoaded = false;
  let executorsLoaded = false;
  let loadingPromise = null;
  let loadSeq = 0;
  window.__gexeFormDataLoading = null;
  let dictCache = null;
  let isLoading = false;

  let successModal = null;
  let successTimer = null;
  let submitLock = false;

  function buildModal(){
    if (modal) return;
    modal = document.createElement('div');
    modal.className = 'glpi-create-modal';
    modal.innerHTML = `
      <div class="gnt-backdrop"></div>
      <div class="gnt-dialog">
      <div class="gnt-header">
        <div class="gnt-title">Новая заявка</div>
        <button type="button" class="gnt-close" aria-label="Закрыть">×</button>
      </div>
        <div class="gnt-body">
          <div class="gexe-dict-status" role="status" aria-live="polite" hidden></div>
          <label for="gnt-name" class="gnt-label">Тема</label>
          <textarea id="gnt-name" class="gnt-textarea"></textarea>
          <div id="gnt-name-err" class="gnt-field-error" hidden></div>
          <label for="gnt-content" class="gnt-label">Описание</label>
          <textarea id="gnt-content" class="gnt-textarea"></textarea>
          <div id="gnt-content-err" class="gnt-field-error" hidden></div>
          <div class="gnt-row">
            <div>
              <label for="gnt-category" class="gnt-label">Категория</label>
              <input id="gnt-category" list="gnt-category-list" class="gnt-input" />
              <datalist id="gnt-category-list"></datalist>
              <div id="gnt-category-path" class="gnt-path"></div>
              <div id="gnt-category-status" class="gnt-inline-status"></div>
              <div id="gnt-category-err" class="gnt-field-error" hidden></div>
            </div>
            <div>
              <label for="gnt-location" class="gnt-label">Местоположение</label>
              <input id="gnt-location" list="gnt-location-list" class="gnt-input" />
              <datalist id="gnt-location-list"></datalist>
              <div id="gnt-location-path" class="gnt-path"></div>
              <div id="gnt-location-status" class="gnt-inline-status"></div>
              <div id="gnt-location-err" class="gnt-field-error" hidden></div>
            </div>
          </div>
          <div class="gnt-row gnt-assign-row">
            <label class="gnt-check"><input type="checkbox" id="gnt-assign-me" checked /> Я исполнитель</label>
            <div>
              <label for="gnt-assignee" class="gnt-label">Исполнитель</label>
              <select id="gnt-assignee" class="gnt-select" disabled><option value="">—</option></select>
              <div id="gnt-assignee-status" class="gnt-inline-status"></div>
              <div id="gnt-assignee-err" class="gnt-field-error" hidden></div>
            </div>
          </div>
        </div>
        <div class="gnt-footer">
          <button type="button" class="gnt-submit" disabled>Создать заявку</button>
          <div class="gnt-submit-error" aria-live="polite" hidden></div>
        </div>
      </div>
    `;
    document.body.appendChild(modal);

    modal.querySelector('.gnt-backdrop').addEventListener('click', close);
    modal.querySelector('.gnt-close').addEventListener('click', close);
    modal.querySelector('.gnt-submit').addEventListener('click', submit);

    const assignChk = modal.querySelector('#gnt-assign-me');
    const assigneeSel = modal.querySelector('#gnt-assignee');
    assignChk.addEventListener('change', function(){
      assigneeSel.disabled = this.checked || !executorsLoaded;
      if (this.checked) {
        const gid = Number((gexeAjax && gexeAjax.user_glpi_id) || 0);
        assigneeSel.value = gid > 0 ? String(gid) : '';
        setFieldError('assignee');
      } else {
        assigneeSel.value = '';
      }
      validateAll(false);
    });
    [['#gnt-name','name'],['#gnt-content','content'],['#gnt-category','category'],['#gnt-location','location'],['#gnt-assignee','assignee']].forEach(function(pair){
      const sel = pair[0];
      const field = pair[1];
      modal.querySelector(sel).addEventListener('input', function(){
        setFieldError(field);
        if (field === 'category' || field === 'location') updatePaths();
        validateAll(false);
      });
    });
    initAssignees();
    validateAll(false);
  }

  function lockForm(state){
    const dialog = modal.querySelector('.gnt-dialog');
    if (!dialog) return;
    if (state) { dialog.setAttribute('aria-busy', 'true'); }
    else { dialog.removeAttribute('aria-busy'); }
    ['#gnt-name','#gnt-content','#gnt-category','#gnt-location','#gnt-assign-me','#gnt-assignee','.gnt-submit'].forEach(function(sel){
      const el = modal.querySelector(sel);
      if (!el) return;
      if (state) {
        el.disabled = true;
      } else if (sel === '#gnt-assignee') {
        el.disabled = modal.querySelector('#gnt-assign-me').checked || !executorsLoaded;
      } else {
        el.disabled = false;
      }
    });
  }

  function showLoading(){
    const box = modal.querySelector('.gexe-dict-status');
    if (!box) return;
    box.innerHTML = '<span class="spinner"></span><span>Загрузка справочников…</span>';
    box.hidden = false;
  }

  function hideStatus(){
    const box = modal.querySelector('.gexe-dict-status');
    if (!box) return;
    box.hidden = true;
    box.innerHTML = '';
  }

  function esc(str){
    return String(str).replace(/[&<>"']/g, function(s){
      switch (s) {
        case '&': return '&amp;';
        case '<': return '&lt;';
        case '>': return '&gt;';
        case '"': return '&quot;';
        case "'": return '&#39;';
        default: return s;
      }
    });
  }

  function showError(message, retry, details){
    const box = modal.querySelector('.gexe-dict-status');
    if (!box) return;
    let html = '<span class="error">' + esc(message);
    if (details) html += ': ' + esc(details);
    html += '</span>';
    if (retry) html += ' <button type="button" class="gnt-retry">Повторить</button>';
    box.innerHTML = html;
    box.hidden = false;
    if (retry) {
      const btn = box.querySelector('.gnt-retry');
      if (btn) btn.addEventListener('click', function(){
        if (isLoading) return;
        btn.disabled = true;
        box.innerHTML = '';
        box.hidden = true;
        setTimeout(retry, 0);
      });
    }
  }

  function setFieldError(field, message){
    const input = modal.querySelector('#gnt-' + field);
    const err = modal.querySelector('#gnt-' + field + '-err');
    if (input) {
      if (message) input.classList.add('gnt-invalid');
      else input.classList.remove('gnt-invalid');
    }
    if (err) {
      err.textContent = message || '';
      err.hidden = !message;
    }
  }

  function logClientError(msg){
    if (!window.gexeAjax) return;
    const params = new URLSearchParams();
    params.append('action','gexe_log_client_error');
    params.append('nonce', gexeAjax.nonce);
    params.append('message', msg);
    fetch(gexeAjax.url, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: params.toString() });
  }

  function refreshNonce(){
    if (!window.gexeAjax) return Promise.reject(new Error('no_ajax'));
    const params = new URLSearchParams();
    params.append('action','gexe_refresh_nonce');
    return fetch(gexeAjax.url, {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: params.toString()
    }).then(r=>r.json()).then(function(data){
      if (data && data.success && data.data && data.data.nonce){
        gexeAjax.nonce = data.data.nonce;
        return gexeAjax.nonce;
      }
      throw new Error('nonce_refresh_failed');
    });
  }

  function fetchDicts(force){
    const now = Date.now();
    if (!force && dictCache && (now - dictCache.ts < 5 * 60 * 1000)) {
      const d = dictCache.data;
      return Promise.resolve({ ok: true, categories: d.categories, locations: d.locations, meta: d.meta });
    }
    if (!gexeAjax || !gexeAjax.url) {
      return Promise.resolve({ ok: false, error: { type: 'NETWORK', message: 'Ошибка соединения с сервером' } });
    }
    const fd = new FormData();
    fd.append('action', 'glpi_load_dicts');
    if (gexeAjax.nonce) fd.append('nonce', gexeAjax.nonce);
    return fetch(gexeAjax.url, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(resp => {
        if (resp && resp.success && resp.data) {
          dictCache = { ts: now, data: resp.data };
          return { ok: true, categories: resp.data.categories, locations: resp.data.locations, meta: resp.data.meta };
        }
        const err = (resp && resp.data) || (resp && resp.error);
        return { ok: false, error: err || { type: 'UNKNOWN', message: 'Не удалось загрузить справочники' } };
      })
      .catch(() => ({ ok: false, error: { type: 'NETWORK', message: 'Ошибка соединения с сервером' } }));
  }

  function startDictLoad(force){
    if (isLoading) return;
    const now = Date.now();
    if (!force && dictCache && (now - dictCache.ts < 5 * 60 * 1000)) {
      const d = dictCache.data;
      fillDropdowns({ categories: d.categories, locations: d.locations });
      lockForm(false);
      const warns = [];
      if (d.meta && d.meta.empty) {
        if (d.meta.empty.categories) warns.push('Справочник «Категории» пуст');
        if (d.meta.empty.locations) warns.push('Справочник «Местоположения» пуст');
      }
      if (d.meta && d.meta.note === 'fallback_no_entities' && gexeAjax && gexeAjax.debug) {
        console.warn('wp-glpi:new-task', 'entity filter fallback (no entities)');
      }
      if (warns.length) showError(warns.join('. '));
      else hideStatus();
      validateAll(false);
      return;
    }
    isLoading = true;
    lockForm(true);
    showLoading();
    fetchDicts(force).then(res => {
      isLoading = false;
      if (res.ok) {
        (res.categories || []).forEach(c => { c.path = c.completename || c.path || ''; });
        (res.locations || []).forEach(l => { l.path = l.completename || l.path || ''; });
        fillDropdowns({ categories: res.categories, locations: res.locations });
        lockForm(false);
        const warns = [];
        if (res.meta && res.meta.empty) {
          if (res.meta.empty.categories) warns.push('Справочник «Категории» пуст');
          if (res.meta.empty.locations) warns.push('Справочник «Местоположения» пуст');
        }
        if (res.meta && res.meta.note === 'fallback_no_entities' && gexeAjax && gexeAjax.debug) {
          console.warn('wp-glpi:new-task', 'entity filter fallback (no entities)');
        }
        if (warns.length) showError(warns.join('. '));
        else hideStatus();
        validateAll(false);
      } else {
        const err = res.error || {};
        if (gexeAjax && gexeAjax.debug) {
          console.error('wp-glpi:new-task', err);
        }
        let msg = 'Не удалось загрузить справочники';
        switch (err.type) {
          case 'MAPPING_NOT_SET':
            msg = 'Ваш профиль не привязан к GLPI пользователю';
            break;
          case 'MAPPING_NONINT':
            msg = 'Некорректный GLPI user ID в профиле (должно быть число)';
            break;
          case 'MAPPING_BROKEN':
            msg = 'GLPI пользователь не найден';
            break;
          case 'ENTITY_ACCESS':
            msg = 'Нет доступа к сущности';
            break;
          default:
            if (err.message) msg = err.message;
        }
        let details = null;
        if (err && err.details) {
          try { details = typeof err.details === 'string' ? err.details : JSON.stringify(err.details); } catch(e) { details = String(err.details); }
        }
        showError(msg, () => startDictLoad(true), details);
      }
    });
  }

  function open(){
    buildModal();
    modal.classList.add('open');
    document.body.classList.add('glpi-modal-open');
    startDictLoad(false);
    updatePaths();
  }

  function close(){
    if (!modal) return;
    modal.classList.remove('open');
    document.body.classList.remove('glpi-modal-open');
  }

  function resetForm(){
    if (!modal) return;
    modal.querySelector('#gnt-name').value = '';
    modal.querySelector('#gnt-content').value = '';
    modal.querySelector('#gnt-category').value = '';
    modal.querySelector('#gnt-location').value = '';
    modal.querySelector('#gnt-category-path').textContent = '';
    modal.querySelector('#gnt-location-path').textContent = '';
    const assignChk = modal.querySelector('#gnt-assign-me');
    const assigneeSel = modal.querySelector('#gnt-assignee');
    assignChk.checked = true;
    assigneeSel.value = '';
    assigneeSel.disabled = true;
    ['name','content','category','location','assignee'].forEach(function(f){ setFieldError(f); });
    setSubmitError('');
    updatePaths();
    validateAll(false);
  }

  function ensureSuccessModal(){
    if (successModal) return;
    successModal = document.createElement('div');
    successModal.className = 'gnt-success-modal';
    successModal.innerHTML = `
      <div class="gnt-success-backdrop"></div>
      <div class="gnt-success-dialog" role="dialog" aria-modal="true">
        <div class="gnt-success-title">Заявка создана #<span class="gnt-ticket-id"></span></div>
        <div class="gnt-success-actions">
          <button type="button" class="gnt-open-ticket">Открыть заявку</button>
          <button type="button" class="gnt-copy-ticket">Скопировать номер</button>
          <button type="button" class="gnt-close-success">Закрыть</button>
        </div>
      </div>`;
    document.body.appendChild(successModal);
    successModal.querySelector('.gnt-success-backdrop').addEventListener('click', closeSuccessModal);
    successModal.querySelector('.gnt-close-success').addEventListener('click', closeSuccessModal);
    successModal.querySelector('.gnt-open-ticket').addEventListener('click', function(){
      const tid = Number(successModal.getAttribute('data-ticket-id') || '0');
      window.dispatchEvent(new CustomEvent('gexe:ticket:open', {detail:{ticketId:tid}}));
      closeSuccessModal();
    });
    const copyBtn = successModal.querySelector('.gnt-copy-ticket');
    copyBtn.addEventListener('click', function(){
      const tid = successModal.getAttribute('data-ticket-id') || '';
      if (navigator.clipboard) {
        navigator.clipboard.writeText(String(tid));
      }
      copyBtn.textContent = 'Скопировано';
      setTimeout(()=>{ copyBtn.textContent = 'Скопировать номер'; }, 2000);
    });
    successModal.addEventListener('keydown', handleSuccessKey);
    ['mousemove','mousedown','touchstart'].forEach(ev=>{
      successModal.addEventListener(ev, startSuccessTimer);
    });
  }

  function handleSuccessKey(e){
    if (e.key === 'Escape') { e.preventDefault(); closeSuccessModal(); return; }
    if (e.key === 'Tab') { trapSuccessFocus(e); }
    startSuccessTimer();
  }

  function trapSuccessFocus(e){
    const focusable = successModal.querySelectorAll('button');
    if (focusable.length === 0) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    const active = document.activeElement;
    if (e.shiftKey && active === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && active === last) {
      e.preventDefault();
      first.focus();
    }
  }

  function startSuccessTimer(){
    clearTimeout(successTimer);
    successTimer = setTimeout(closeSuccessModal, 5000);
  }

  function showSuccessModal(ticketId){
    ensureSuccessModal();
    successModal.setAttribute('data-ticket-id', String(ticketId));
    successModal.querySelector('.gnt-ticket-id').textContent = ticketId;
    successModal.classList.add('open');
    document.body.classList.add('glpi-modal-open');
    const openBtn = successModal.querySelector('.gnt-open-ticket');
    if (openBtn) openBtn.focus();
    startSuccessTimer();
  }

  function closeSuccessModal(){
    if (!successModal) return;
    successModal.classList.remove('open');
    document.body.classList.remove('glpi-modal-open');
    clearTimeout(successTimer);
    resetForm();
    const btn = document.querySelector('.glpi-newtask-btn');
    if (btn) btn.focus();
  }

  // deprecated loadFormData removed

  function fillDropdowns(data){
    if (!data) return;
    if (data.categories && !categoriesLoaded) {
      const list = modal.querySelector('#gnt-category-list');
      const counts = {};
      data.categories.forEach(function(c){
        const name = (c.name || '').trim();
        counts[name] = (counts[name] || 0) + 1;
      });
      data.categories.forEach(function(c){
        const opt = document.createElement('option');
        const name = (c.name || '').trim();
        let path = (c.path || '').replace(/\s[\/\>]\s/g, ' › ').replace(/\s+/g, ' ').trim();
        let val = name;
        if (counts[name] > 1 && path) {
          val = path;
        }
        opt.value = val;
        opt.setAttribute('data-id', c.id);
        if (path) opt.setAttribute('data-path', path);
        list.appendChild(opt);
      });
      categoriesLoaded = true;
    }
    if (data.locations) {
      const list = modal.querySelector('#gnt-location-list');
      if (!list.childElementCount) {
        const counts = {};
        data.locations.forEach(function(l){
          counts[l.name] = (counts[l.name] || 0) + 1;
        });
        data.locations.forEach(function(l){
          const opt = document.createElement('option');
          let val = l.name;
          if (counts[l.name] > 1 && l.path) {
            const parts = l.path.split(/\s[\/\>]\s/);
            parts.pop();
            const parent = parts.join(' / ');
            if (parent) val = l.name + ' (' + parent + ')';
          }
          opt.value = val;
          opt.setAttribute('data-id', l.id);
          if (l.path) opt.setAttribute('data-path', l.path);
          list.appendChild(opt);
        });
      }
    }
  }

  function getSelectedId(listId, value){
    const list = modal.querySelector('#'+listId);
    if (!list) return 0;
    const opt = Array.from(list.options).find(o=>o.value===value);
    return opt ? parseInt(opt.getAttribute('data-id'),10) : 0;
  }

  function initAssignees(){
    const sel = modal.querySelector('#gnt-assignee');
    const list = (gexeAjax && Array.isArray(gexeAjax.assignees)) ? gexeAjax.assignees : [];
    sel.innerHTML = '<option value="">—</option>';
    list.forEach(function(a){
      const opt = document.createElement('option');
      opt.value = a.id;
      opt.textContent = a.name;
      sel.appendChild(opt);
    });
    executorsLoaded = list.length > 0;
    sel.disabled = modal.querySelector('#gnt-assign-me').checked || !executorsLoaded;
  }

  function setSubmitError(message){
    const box = modal.querySelector('.gnt-submit-error');
    if (!box) return;
    box.textContent = message || '';
    box.hidden = !message;
  }

  function mapError(code, id){
    const map = {
      'SECURITY/NO_CSRF': 'Сессия устарела. Обновите страницу.',
      not_logged_in: 'Сессия неактивна. Войдите в систему.',
      not_mapped: 'Профиль не настроен: нет GLPI-ID.',
      validation: 'Заполните обязательные поля корректно.',
      rate_limit_client: 'Слишком часто. Попробуйте ещё раз через несколько секунд.',
      api_unreachable: 'Сервер GLPI недоступен. Повторите позже.',
      api_auth: 'Нет доступа к API. Проверьте токен.',
      api_validation: 'GLPI отклонил данные. Проверьте поля.',
      assign_failed: id ? 'Заявка создана, но исполнитель не назначен (ID: #' + id + '). Назначьте вручную.' : 'Заявка создана, но исполнитель не назначен. Назначьте вручную.'
    };
    return map[code] || 'Ошибка сервера. Повторите позже.';
  }

  function validateAll(show){
    const nameEl = modal.querySelector('#gnt-name');
    const contentEl = modal.querySelector('#gnt-content');
    const catId = getSelectedId('gnt-category-list', modal.querySelector('#gnt-category').value);
    const locId = getSelectedId('gnt-location-list', modal.querySelector('#gnt-location').value);
    const assignMe = modal.querySelector('#gnt-assign-me').checked;
    const assigneeSel = modal.querySelector('#gnt-assignee');
    const assigneeId = assignMe ? Number(gexeAjax.user_glpi_id || 0) : (parseInt(assigneeSel.value,10) || 0);
    const errors = {};
    const subj = nameEl.value.trim();
    const cont = contentEl.value.trim();
    if (subj.length < 3 || subj.length > 255) errors.name = 'Тема 3-255 символов';
    if (cont.length < 3 || cont.length > 4096) errors.content = 'Описание 3-4096 символов';
    if (catId <= 0) errors.category = 'Обязательное поле';
    if (locId <= 0) errors.location = 'Обязательное поле';
    if (!assignMe && assigneeId <= 0) errors.assignee = 'Обязательное поле';
    if (show) {
      ['name','content','category','location','assignee'].forEach(function(f){ setFieldError(f, errors[f]); });
    } else {
      ['name','content','category','location','assignee'].forEach(function(f){ setFieldError(f); });
    }
    const valid = Object.keys(errors).length === 0;
    const btn = modal.querySelector('.gnt-submit');
    if (btn) btn.disabled = !valid;
    return { valid: valid, data: { subject: subj, content: cont, category_id: catId, location_id: locId, assignee_glpi_id: assigneeId, is_self_assignee: assignMe } };
  }

  function showToast(text){
    const el = document.createElement('div');
    el.className = 'gnt-toast';
    el.textContent = text;
    document.body.appendChild(el);
    requestAnimationFrame(()=>{ el.classList.add('show'); });
    setTimeout(()=>{ el.classList.remove('show'); setTimeout(()=>el.remove(),300); },3000);
  }

  function submit(){
    if (!window.gexeAjax || submitLock) return;
    const v = validateAll(true);
    if (!v.valid) {
      setSubmitError('Заполните обязательные поля корректно.');
      return;
    }
    submitLock = true;
    setTimeout(()=>{ submitLock = false; }, 700);
    setSubmitError('');
    const btn = modal.querySelector('.gnt-submit');
    btn.disabled = true;
    btn.classList.add('is-loading');
    const oldCursor = btn.style.cursor;
    btn.style.cursor = 'progress';
    const params = new URLSearchParams();
    params.append('action','glpi_create_ticket_api');
    params.append('nonce', gexeAjax.nonce);
    params.append('subject', v.data.subject);
    params.append('content', v.data.content);
    params.append('category_id', v.data.category_id);
    params.append('location_id', v.data.location_id);
    params.append('assignee_glpi_id', v.data.assignee_glpi_id);
    params.append('is_self_assignee', v.data.is_self_assignee ? 1 : 0);
    fetch(gexeAjax.url, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: params.toString() })
      .then(r=>r.json().catch(()=>({ok:false,code:'api_unreachable'})))
      .then(data=>{
        if (data.ok) {
          close();
          const msg = data.code === 'already_exists' ? 'Заявка #' + data.ticket_id + ' уже создана' : 'Заявка #' + data.ticket_id + ' создана, исполнитель назначен';
          showToast(msg);
          window.dispatchEvent(new CustomEvent('gexe:tickets:refresh', {detail:{ticketId:data.ticket_id}}));
        } else {
          setSubmitError(mapError(data.code, data.ticket_id) + (data.detail ? (' — ' + String(data.detail)) : ''));
        }
      })
      .catch(()=>{ setSubmitError(mapError('api_unreachable')); })
      .finally(()=>{
        btn.style.cursor = oldCursor;
        btn.classList.remove('is-loading');
        validateAll(false);
      });
  }

  function updatePaths(){
    const catVal = modal.querySelector('#gnt-category').value;
    const catOpt = Array.from(modal.querySelector('#gnt-category-list').options).find(o=>o.value===catVal);
    modal.querySelector('#gnt-category-path').textContent = catOpt ? catOpt.getAttribute('data-path') : '';
    const locVal = modal.querySelector('#gnt-location').value;
    const locOpt = Array.from(modal.querySelector('#gnt-location-list').options).find(o=>o.value===locVal);
    modal.querySelector('#gnt-location-path').textContent = locOpt ? locOpt.getAttribute('data-path') : '';
  }

  document.addEventListener('DOMContentLoaded', function(){
    document.addEventListener('click', function(e){
      if (e.target.closest('.gnt-open')) {
        e.preventDefault();
        open();
      }
    });
  });

  window.GNT = { open: open, close: close };
})();
