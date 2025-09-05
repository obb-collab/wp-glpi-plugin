(function(){
  'use strict';

  let modal = null;
  let categoriesLoaded = false;
  let executorsLoaded = false;
  // Promise для первого запроса справочников
  let dataPromise = null;

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
    });
    ['#gnt-name','#gnt-content','#gnt-category','#gnt-location','#gnt-assignee'].forEach(function(sel){
      modal.querySelector(sel).addEventListener('input', updateSubmitState);
    });
    modal.querySelector('#gnt-assignee').addEventListener('change', updateSubmitState);
    modal.querySelector('#gnt-assign-me').addEventListener('change', updateSubmitState);
  }

  function open(){
    buildModal();
    modal.classList.add('open');
    document.body.classList.add('glpi-modal-open');
    loadFormData();
    updateSubmitState();
  }

  function close(){
    if (!modal) return;
    modal.classList.remove('open');
    document.body.classList.remove('glpi-modal-open');
  }

  function loadFormData(){
    // Если данные уже получены – используем их
    if (window.__glpiFormData) {
      fillDropdowns(window.__glpiFormData);
      return;
    }
    if (!window.glpiAjax) return;
    if (!dataPromise) {
      const params = new URLSearchParams();
      params.append('action','glpi_get_form_data');
      params.append('_ajax_nonce', glpiAjax.nonce);
      dataPromise = fetch(glpiAjax.url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
      }).then(r=>r.json()).then(function(data){
        window.__glpiFormData = data;
        fillDropdowns(data);
        return data;
      }).catch(function(){ dataPromise = null; });
    } else {
      dataPromise.then(fillDropdowns).catch(()=>{});
    }
  }

  function fillDropdowns(data){
    if (!data) return;
    if (data.categories && !categoriesLoaded) {
      const list = modal.querySelector('#gnt-category-list');
      const counts = {};
      data.categories.forEach(function(c){
        counts[c.name] = (counts[c.name] || 0) + 1;
      });
      data.categories.forEach(function(c){
        const opt = document.createElement('option');
        let val = c.name;
        if (counts[c.name] > 1 && c.path) {
          const parts = c.path.split(/\s[\/\>]\s/);
          parts.pop();
          const parent = parts.join(' / ');
          if (parent) val = c.name + ' (' + parent + ')';
        }
        opt.value = val;
        opt.setAttribute('data-id', c.id);
        if (c.path) opt.setAttribute('data-path', c.path);
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
    if (!window.glpiAjax) return;
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
    const body = 'action=glpi_create_ticket&_ajax_nonce='+encodeURIComponent(glpiAjax.nonce)+'&payload='+encodeURIComponent(JSON.stringify(payload));
    const btn = modal.querySelector('.gnt-submit');
    btn.disabled = true;
    fetch(glpiAjax.url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body
    }).then(r=>r.json()).then(data=>{
      if (data && data.ok) {
        close();
      }
    }).catch(()=>{}).finally(()=>{btn.disabled = false;});
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
