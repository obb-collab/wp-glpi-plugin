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
          <button type="button" class="gnt-submit">Создать заявку</button>
          <div id="gnt-form-alert" class="form-alert" hidden></div>
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
        const glpiId = Number((gexeAjax && gexeAjax.user_glpi_id) || 0);
        if (glpiId > 0) {
          const opt = assigneeSel.querySelector('option[data-glpi-id="' + glpiId + '"]');
          if (opt) {
            assigneeSel.value = opt.value;
          }
        }
        setFieldError('assignee');
      } else {
        assigneeSel.value = '';
      }
    });
    [['#gnt-name','name'],['#gnt-content','content'],['#gnt-category','category'],['#gnt-location','location'],['#gnt-assignee','assignee']].forEach(function(pair){
      const sel = pair[0];
      const field = pair[1];
      modal.querySelector(sel).addEventListener('input', function(){
        setFieldError(field);
        if (field === 'category' || field === 'location') updatePaths();
      });
    });
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

  function showFormAlert(message, details){
    const box = modal.querySelector('#gnt-form-alert');
    if (!box) return;
    let html = esc(message);
    if (details) html += '<details><code>' + esc(details) + '</code></details>';
    box.innerHTML = html;
    box.hidden = false;
  }

  function clearFormAlert(){
    const box = modal.querySelector('#gnt-form-alert');
    if (!box) return;
    box.hidden = true;
    box.innerHTML = '';
  }

  function showSubmitError(message){
    showSubmitStatus(message, 'error');
  }

  function showSubmitStatus(message, type){
    const box = modal.querySelector('.gexe-dict-status');
    if (!box) return;
    const cls = type === 'error' ? 'error' : (type === 'success' ? 'success' : 'loading');
    box.className = 'gexe-dict-status ' + cls;
    if (cls === 'loading') {
      box.innerHTML = '<span class="spinner"></span>' + (message || '');
    } else {
      box.textContent = message || '';
    }
    box.hidden = false;
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
      return Promise.resolve({ ok: true, categories: d.categories, locations: d.locations, executors: d.executors, meta: d.meta });
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
          return { ok: true, categories: resp.data.categories, locations: resp.data.locations, executors: resp.data.executors, meta: resp.data.meta };
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
      fillDropdowns({ categories: d.categories, locations: d.locations, executors: d.executors });
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
        fillDropdowns({ categories: res.categories, locations: res.locations, executors: res.executors });
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
    loadCategories();
    loadLocations();
    loadExecutors();
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
    updatePaths();
    clearFormAlert();
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
    if (!executorsLoaded) {
      const sel = modal.querySelector('#gnt-assignee');
      if (data.executors && data.executors.length) {
        sel.innerHTML = '<option value="">—</option>';
        data.executors.forEach(function(u){
          const opt = document.createElement('option');
          opt.value = u.user_id;
          opt.textContent = u.display_name;
          if (u.glpi_user_id) {
            opt.setAttribute('data-glpi-id', u.glpi_user_id);
          }
          sel.appendChild(opt);
        });
        executorsLoaded = true;
        sel.disabled = modal.querySelector('#gnt-assign-me').checked;
        const assignChk = modal.querySelector('#gnt-assign-me');
        if (assignChk) assignChk.dispatchEvent(new Event('change'));
      } else {
        sel.innerHTML = '';
        const opt = document.createElement('option');
        opt.value = '';
        opt.textContent = 'Список исполнителей временно недоступен. Обновите страницу или попробуйте позже';
        opt.disabled = true;
        opt.selected = true;
        sel.appendChild(opt);
        sel.disabled = true;
        executorsLoaded = false;
      }
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

  function submit(){
    if (!window.gexeAjax || submitLock) return;
    submitLock = true;
    setTimeout(()=>{ submitLock = false; }, 3000);
    clearFormAlert();
    const name = modal.querySelector('#gnt-name').value.trim();
    const content = modal.querySelector('#gnt-content').value.trim();
    const catInput = modal.querySelector('#gnt-category');
    const locInput = modal.querySelector('#gnt-location');
    const catId = getSelectedId('gnt-category-list', catInput.value);
    const locId = getSelectedId('gnt-location-list', locInput.value);
    const assignMe = modal.querySelector('#gnt-assign-me').checked;
    const assigneeSel = modal.querySelector('#gnt-assignee');
    const assigneeId = assigneeSel.disabled ? 0 : parseInt(assigneeSel.value,10) || 0;
    const errors = {};
    if (name.length < 3 || name.length > 255) errors.name = 'Тема 3-255 символов';
    if (!content || content.length > 5000) errors.content = 'Описание 1-5000 символов';
    if (!assignMe && !assigneeId) errors.assignee = 'Обязательное поле';
    if (Object.keys(errors).length) {
      Object.keys(errors).forEach(function(f){ setFieldError(f, errors[f]); });
      showFormAlert('Заполните обязательные поля');
      return;
    }
    const payload = {
      subject: name,
      description: content,
      category_id: catId,
      location_id: locId,
      assign_me: assignMe ? 1 : 0,
      executor_id: assigneeId
    };
    const makeBody = () => {
      const params = new URLSearchParams();
      params.append('action','wpglpi_create_ticket_api');
      params.append('nonce', gexeAjax.nonce);
      Object.keys(payload).forEach(k=>{ if(payload[k] !== undefined && payload[k] !== null) params.append(k, payload[k]); });
      return params.toString();
    };
    const btn = modal.querySelector('.gnt-submit');
    const oldText = btn.textContent;
    btn.disabled = true;
    btn.classList.add('is-loading');
    btn.textContent = 'Создаю...';
    const send = (retry) => {
      return fetch(gexeAjax.url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: makeBody()
      }).then(r=>r.json().then(data=>({status:r.status,data:data}))).then(resp=>{
        if (resp.status === 403 && resp.data && resp.data.error === 'AJAX_FORBIDDEN' && !retry) {
          return refreshNonce().then(()=>send(true));
        }
        return resp;
      });
    };
    send(false).then(resp=>{
      const data = resp.data || resp;
      if (data && data.success) {
        close();
        if (data.warning) showFormAlert(data.message || 'Назначение исполнителя не выполнено');
        if (data.id) {
          showSuccessModal(data.id);
          window.dispatchEvent(new CustomEvent('gexe:tickets:refresh', {detail:{ticketId:data.id}}));
        }
      } else {
        const msg = data && data.message ? data.message : (data && data.error && data.error.message ? data.error.message : 'Ошибка отправки');
        const details = data && data.details ? (typeof data.details === 'string' ? data.details : JSON.stringify(data.details)) : null;
        if (data && data.details && data.type === 'VALIDATION') {
          Object.keys(data.details).forEach(function(f){ setFieldError(f, data.details[f]); });
        }
        showFormAlert('Ошибка отправки: '+msg, details);
      }
    }).catch(err=>{
      logClientError((err && err.code ? err.code + ': ' : '') + (err && err.message ? err.message : String(err)));
      showFormAlert('Ошибка отправки', err && err.message ? err.message : String(err));
    }).finally(()=>{
      btn.disabled = false;
      btn.classList.remove('is-loading');
      btn.textContent = oldText;
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

  function loadCategories(){
    const status = modal.querySelector('#gnt-category-status');
    const list = modal.querySelector('#gnt-category-list');
    if (!status || !list || !gexeAjax) return;
    status.innerHTML = '<span class="spinner"></span>';
    const fd = new FormData();
    fd.append('action','wpglpi_load_categories');
    if (gexeAjax.nonce) fd.append('nonce', gexeAjax.nonce);
    fetch(gexeAjax.url,{method:'POST',body:fd}).then(r=>r.json()).then(function(resp){
      if (resp && resp.success && resp.data && resp.data.categories){
        list.innerHTML = '';
        resp.data.categories.forEach(function(c){
          const opt = document.createElement('option');
          opt.value = c.name;
          opt.setAttribute('data-id', c.id);
          opt.setAttribute('data-path', c.completename || '');
          list.appendChild(opt);
        });
        status.innerHTML = '';
        updatePaths();
      } else {
        status.innerHTML = '<span class="error">Ошибка SQL при загрузке категорий</span> <button type="button" class="gnt-retry">Повторить</button>';
        const btn = status.querySelector('.gnt-retry');
        if (btn) btn.addEventListener('click', function(){ loadCategories(); });
      }
    }).catch(function(){
      status.innerHTML = '<span class="error">Ошибка загрузки</span> <button type="button" class="gnt-retry">Повторить</button>';
      const btn = status.querySelector('.gnt-retry');
      if (btn) btn.addEventListener('click', function(){ loadCategories(); });
    });
  }

  function loadLocations(){
    const status = modal.querySelector('#gnt-location-status');
    const list = modal.querySelector('#gnt-location-list');
    if (!status || !list || !gexeAjax) return;
    status.innerHTML = '<span class="spinner"></span>';
    const fd = new FormData();
    fd.append('action','wpglpi_load_locations');
    if (gexeAjax.nonce) fd.append('nonce', gexeAjax.nonce);
    fetch(gexeAjax.url,{method:'POST',body:fd}).then(r=>r.json()).then(function(resp){
      if (resp && resp.success && resp.data && resp.data.locations){
        list.innerHTML = '';
        resp.data.locations.forEach(function(l){
          const opt = document.createElement('option');
          opt.value = l.name;
          opt.setAttribute('data-id', l.id);
          opt.setAttribute('data-path', l.completename || '');
          list.appendChild(opt);
        });
        status.innerHTML = '';
        updatePaths();
      } else {
        status.innerHTML = '<span class="error">Ошибка SQL при загрузке локаций</span> <button type="button" class="gnt-retry">Повторить</button>';
        const btn = status.querySelector('.gnt-retry');
        if (btn) btn.addEventListener('click', function(){ loadLocations(); });
      }
    }).catch(function(){
      status.innerHTML = '<span class="error">Ошибка загрузки</span> <button type="button" class="gnt-retry">Повторить</button>';
      const btn = status.querySelector('.gnt-retry');
      if (btn) btn.addEventListener('click', function(){ loadLocations(); });
    });
  }

  function loadExecutors(){
    const status = modal.querySelector('#gnt-assignee-status');
    const sel = modal.querySelector('#gnt-assignee');
    if (!status || !sel || !gexeAjax) return;
    status.innerHTML = '<span class="spinner"></span>';
    const fd = new URLSearchParams();
    fd.append('action','wpglpi_load_executors');
    if (gexeAjax.nonce) fd.append('nonce', gexeAjax.nonce);
    fetch(gexeAjax.url,{method:'POST',body:fd}).then(r=>r.json()).then(function(resp){
      if (resp && resp.success && resp.data && resp.data.executors){
        sel.innerHTML = '<option value="">—</option>';
        resp.data.executors.forEach(function(e){
          if (!e.id) return;
          const opt = document.createElement('option');
          opt.value = e.id;
          opt.textContent = e.label;
          opt.setAttribute('data-glpi-id', e.id);
          sel.appendChild(opt);
        });
        executorsLoaded = true;
        status.innerHTML = '';
        const assignChk = modal.querySelector('#gnt-assign-me');
        if (assignChk && !assignChk.checked) sel.disabled = false;
      } else {
        status.innerHTML = '<span class="error">Ошибка SQL при загрузке исполнителей</span> <button type="button" class="gnt-retry">Повторить</button>';
        const btn = status.querySelector('.gnt-retry');
        if (btn) btn.addEventListener('click', function(){ loadExecutors(); });
      }
    }).catch(function(){
      status.innerHTML = '<span class="error">Ошибка загрузки</span> <button type="button" class="gnt-retry">Повторить</button>';
      const btn = status.querySelector('.gnt-retry');
      if (btn) btn.addEventListener('click', function(){ loadExecutors(); });
    });
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
