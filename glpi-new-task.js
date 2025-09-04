(function(){
  'use strict';

  let modal = null;
  let loaded = false;

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
              <select id="gnt-category" class="gnt-select"><option value="">—</option></select>
            </div>
            <div>
              <label for="gnt-location" class="gnt-label">Местоположение</label>
              <select id="gnt-location" class="gnt-select"><option value="">—</option></select>
            </div>
          </div>
          <label class="gnt-check"><input type="checkbox" id="gnt-assign-me" /> Назначить меня исполнителем</label>
        </div>
        <div class="gnt-footer">
          <button type="button" class="gnt-submit">Создать</button>
        </div>
      </div>
    `;
    document.body.appendChild(modal);

    modal.querySelector('.gnt-backdrop').addEventListener('click', close);
    modal.querySelector('.gnt-close').addEventListener('click', close);
    modal.querySelector('.gnt-submit').addEventListener('click', submit);
  }

  function open(){
    buildModal();
    modal.classList.add('open');
    document.body.classList.add('glpi-modal-open');
    if (!loaded) { loadDropdowns(); loaded = true; }
  }

  function close(){
    if (!modal) return;
    modal.classList.remove('open');
    document.body.classList.remove('glpi-modal-open');
  }

  function loadDropdowns(){
    if (!window.glpiAjax) return;
    const body = 'action=glpi_dropdowns&_ajax_nonce='+encodeURIComponent(glpiAjax.nonce);
    fetch(glpiAjax.url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body
    }).then(r=>r.json()).then(data=>{
      if (data && data.categories) {
        const sel = modal.querySelector('#gnt-category');
        data.categories.forEach(function(c){
          const opt = document.createElement('option');
          opt.value = c.id;
          opt.textContent = c.name;
          sel.appendChild(opt);
        });
      }
      if (data && data.locations) {
        const sel = modal.querySelector('#gnt-location');
        data.locations.forEach(function(l){
          const opt = document.createElement('option');
          opt.value = l.id;
          opt.textContent = l.name;
          sel.appendChild(opt);
        });
      }
    }).catch(()=>{});
  }

  function submit(){
    if (!window.glpiAjax) return;
    const name = modal.querySelector('#gnt-name').value.trim();
    const content = modal.querySelector('#gnt-content').value.trim();
    const catId = modal.querySelector('#gnt-category').value;
    const locId = modal.querySelector('#gnt-location').value;
    const assignMe = modal.querySelector('#gnt-assign-me').checked;
    if (!name || !content) return;
    const payload = {
      name: name,
      content: content,
      category_id: catId ? parseInt(catId,10) : 0,
      location_id: locId ? parseInt(locId,10) : 0,
      assign_me: assignMe ? 1 : 0
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
