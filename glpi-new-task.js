(function(){
  'use strict';

  let modal = null;
  let categoriesLoaded = false;
  let executorsLoaded = false;
  let loadingPromise = null;
  let loadSeq = 0;
  window.__gexeFormDataLoading = null;

  let successModal = null;
  let successTimer = null;

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
          <div class="glpi-form-loader" role="status" aria-live="polite" hidden></div>
          <label for="gnt-name" class="gnt-label">Тема</label>
          <input id="gnt-name" type="text" class="gnt-input" />
          <label for="gnt-content" class="gnt-label">Описание</label>
          <textarea id="gnt-content" class="gnt-textarea"></textarea>
          <div class="gnt-row">
            <div>
              <label for="gnt-category" class="gnt-label">Категория</label>
              <input id="gnt-category" list="gnt-category-list" class="gnt-input" />
              <datalist id="gnt-category-list"></datalist>
              <div id="gnt-category-path" class="gnt-path"></div>
            </div>
            <div>
              <label for="gnt-location" class="gnt-label">Местоположение</label>
              <input id="gnt-location" list="gnt-location-list" class="gnt-input" />
              <datalist id="gnt-location-list"></datalist>
              <div id="gnt-location-path" class="gnt-path"></div>
            </div>
          </div>
          <div class="gnt-row gnt-assign-row">
            <label class="gnt-check"><input type="checkbox" id="gnt-assign-me" checked /> Я исполнитель</label>
            <div>
              <label for="gnt-assignee" class="gnt-label">Исполнитель</label>
              <select id="gnt-assignee" class="gnt-select" disabled><option value="">—</option></select>
            </div>
          </div>
        </div>
        <div class="gnt-footer">
          <button type="button" class="gnt-submit" disabled>Создать</button>
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
      assigneeSel.disabled = this.checked;
      if (this.checked) {
        assigneeSel.value = '';
      }
      updateSubmitState();
    });
    ['#gnt-name','#gnt-content','#gnt-category','#gnt-location','#gnt-assignee'].forEach(function(sel){
      modal.querySelector(sel).addEventListener('input', updateSubmitState);
    });
    modal.querySelector('#gnt-assignee').addEventListener('change', updateSubmitState);
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
        el.disabled = modal.querySelector('#gnt-assign-me').checked;
      } else {
        el.disabled = false;
      }
    });
  }

  function showLoading(){
    const box = modal.querySelector('.glpi-form-loader');
    if (!box) return;
    box.innerHTML = '<span class="spinner"></span><span>Загружаем справочники…</span>';
    box.hidden = false;
  }

  function hideLoader(){
    const box = modal.querySelector('.glpi-form-loader');
    if (!box) return;
    box.hidden = true;
    box.innerHTML = '';
  }

  function showError(message){
    const box = modal.querySelector('.glpi-form-loader');
    if (!box) return;
    const msg = 'Не удалось загрузить справочники категорий и местоположений. ' + (message || 'Попробуйте ещё раз.');
    box.innerHTML = '<span class="error">' + msg + '</span><button type="button" class="gnt-retry">Повторить</button>';
    box.hidden = false;
    const btn = box.querySelector('.gnt-retry');
    btn.addEventListener('click', function(){
      hideLoader();
      lockForm(true);
      showLoading();
      fetchFormData(true).then(function(data){
        hideLoader();
        lockForm(false);
        fillDropdowns(data);
        updateSubmitState();
      }).catch(function(err){
        logClientError((err && err.code ? err.code + ': ' : '') + (err && err.message ? err.message : String(err)));
        showError(err && err.message ? err.message : 'Ошибка загрузки');
      });
    });
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

  function fetchFormData(retry){
    if (!retry && window.__gexeFormData && window.__gexeFormData.ok) {
      return Promise.resolve(window.__gexeFormData);
    }
    if (!window.gexeAjax) return Promise.reject(new Error('no_ajax'));
    if (loadingPromise) return loadingPromise;

    const seq = ++loadSeq;
    window.__gexeFormDataLoading = seq;
    const params = new URLSearchParams();
    params.append('action','gexe_get_form_data');
    params.append('nonce', gexeAjax.nonce);
    const controller = new AbortController();
    const t = setTimeout(() => controller.abort(), 10000);
    loadingPromise = fetch(gexeAjax.url, {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: params.toString(),
      signal: controller.signal
    }).then(function(r){
      return r.json().then(function(data){ return { status: r.status, data: data }; });
    }).then(function(resp){
      const status = resp.status;
      const data = resp.data;
      if (status === 403 && data && data.code === 'AJAX_FORBIDDEN' && data.reason === 'nonce') {
        const e = new Error('AJAX_FORBIDDEN');
        e.code = 'AJAX_FORBIDDEN';
        e.reason = 'nonce';
        throw e;
      }
      if (status >= 400 || !data || !data.ok){
        const e = new Error(data && data.message ? data.message : 'server_error');
        e.code = data && data.code ? data.code : ('HTTP_'+status);
        e.reason = data && data.reason;
        throw e;
      }
      if (seq !== loadSeq) {
        const e = new Error('stale');
        e.code = 'STALE';
        throw e;
      }
      window.__gexeFormData = data;
      if (window.glpiDev) {
        console.info('[glpi] form data', data.source, data.took_ms + 'ms', 'cats:' + (data.categories ? data.categories.length : 0), 'locs:' + (data.locations ? data.locations.length : 0));
      }
      return data;
    }).catch(function(err){
      if (err && err.code === 'AJAX_FORBIDDEN' && err.reason === 'nonce' && !retry) {
        loadingPromise = null;
        return refreshNonce().then(function(){
          return fetchFormData(true);
        });
      }
      throw err;
    }).finally(function(){
      clearTimeout(t);
      loadingPromise = null;
      if (window.__gexeFormDataLoading === seq) window.__gexeFormDataLoading = null;
    });
    return loadingPromise;
  }

  function open(){
    buildModal();
    modal.classList.add('open');
    document.body.classList.add('glpi-modal-open');
    if (window.__gexeFormData && window.__gexeFormData.ok && window.__gexeFormData.categories && window.__gexeFormData.locations) {
      fillDropdowns(window.__gexeFormData);
      lockForm(false);
    } else {
      lockForm(true);
      showLoading();
      fetchFormData().then(function(data){
        hideLoader();
        lockForm(false);
        fillDropdowns(data);
        updateSubmitState();
      }).catch(function(err){
        logClientError((err && err.code ? err.code + ': ' : '') + (err && err.message ? err.message : String(err)));
        showError(err && err.message ? err.message : 'Ошибка загрузки');
      });
    }
    updateSubmitState();
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
    updateSubmitState();
  }

  function ensureSuccessModal(){
    if (successModal) return;
    successModal = document.createElement('div');
    successModal.className = 'gnt-success-modal';
    successModal.innerHTML = `
      <div class="gnt-success-backdrop"></div>
      <div class="gnt-success-dialog" role="dialog" aria-modal="true">
        <div class="gnt-success-title">Успешно создана заявка</div>
        <div class="gnt-success-text">Успешно создана заявка № <span class="gnt-ticket-id"></span></div>
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
    if (data.executors && !executorsLoaded) {
      const sel = modal.querySelector('#gnt-assignee');
      data.executors.forEach(function(u){
        const opt = document.createElement('option');
        opt.value = u.id;
        opt.textContent = u.name;
        sel.appendChild(opt);
      });
      executorsLoaded = true;
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
    if (!window.gexeAjax) return;
    hideLoader();
    const name = modal.querySelector('#gnt-name').value.trim();
    const content = modal.querySelector('#gnt-content').value.trim();
    const catInput = modal.querySelector('#gnt-category');
    const locInput = modal.querySelector('#gnt-location');
    const catId = getSelectedId('gnt-category-list', catInput.value);
    const locId = getSelectedId('gnt-location-list', locInput.value);
    const assignMe = modal.querySelector('#gnt-assign-me').checked;
    const assigneeSel = modal.querySelector('#gnt-assignee');
    const assigneeId = assigneeSel.disabled ? 0 : parseInt(assigneeSel.value,10) || 0;
    if (!name || !content || !catId || !locId) return;
    const payload = {
      name: name,
      content: content,
      category_id: catId,
      location_id: locId,
      assign_me: assignMe ? 1 : 0,
      assignee_id: assigneeId
    };
    const btn = modal.querySelector('.gnt-submit');
    window.performAction('create', {
      payload: JSON.stringify(payload),
      button: btn
    }).then(resp => {
      if (!resp || !resp.ok) return;
      const data = resp.data || {};
      close();
      if (data.ticket_id) {
        showSuccessModal(data.ticket_id);
      }
    }).catch(err => {
      logClientError((err && err.message) ? err.message : String(err));
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

  function updateSubmitState(){
    if (!modal) return;
    updatePaths();
    const name = modal.querySelector('#gnt-name').value.trim();
    const content = modal.querySelector('#gnt-content').value.trim();
    const catId = getSelectedId('gnt-category-list', modal.querySelector('#gnt-category').value);
    const locId = getSelectedId('gnt-location-list', modal.querySelector('#gnt-location').value);
    const assignMe = modal.querySelector('#gnt-assign-me').checked;
    const assigneeSel = modal.querySelector('#gnt-assignee');
    const assigneeId = assigneeSel.disabled ? 0 : parseInt(assigneeSel.value,10) || 0;
    const ready = name && content && catId && locId && (assignMe || assigneeId);
    modal.querySelector('.gnt-submit').disabled = !ready;
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
