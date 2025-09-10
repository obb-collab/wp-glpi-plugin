(function(){
  if (!window.gexeBage) return;
  const ajax = gexeBage.ajaxUrl;
  const nonce = gexeBage.nonce;
  const perPage = gexeBage.perPage || 20;
  let loading = false;

  const els = {
    grid: document.getElementById('gexe-bage-grid'),
    status: document.getElementById('gexe-bage-status'),
    search: document.getElementById('gexe-bage-search'),
    pager: document.getElementById('gexe-bage-pager'),
    prev: document.getElementById('gexe-bage-prev'),
    next: document.getElementById('gexe-bage-next'),
    page: document.getElementById('gexe-bage-page'),
    err: document.getElementById('gexe-bage-err'),
    counters: {
      0: document.getElementById('c0'),
      1: document.getElementById('c1'),
      2: document.getElementById('c2'),
      3: document.getElementById('c3'),
      4: document.getElementById('c4')
    }
  };

  let state = {
    page: 1,
    status: 0,
    category: 0,
    q: ''
  };

  function showError(msg){
    els.err.textContent = msg || '';
    els.err.hidden = !msg;
  }
  function post(action, data){
    const f = new FormData();
    f.append('action', action);
    f.append('nonce', nonce);
    Object.keys(data||{}).forEach(k => f.append(k, data[k]));
    return fetch(ajax, { method:'POST', body:f, credentials:'same-origin' }).then(r=>r.json());
  }

  function setLoading(on){
    loading = !!on;
    els.grid.classList.toggle('is-loading', loading);
  }

  function formatDate(s){
    if (!s) return '';
    try { return new Date(s).toLocaleString(); } catch(e){ return s; }
  }
  function statusName(id){
    const map = {1:'Новые',2:'В работе',3:'В плане',4:'В стопе',6:'Решено'};
    return map[id] || id;
  }

  function renderCards(items){
    els.grid.innerHTML = '';
    if (!items || !items.length){
      const emp = document.createElement('div');
      emp.className = 'gexe-bage__empty';
      emp.textContent = 'Заявок нет';
      els.grid.appendChild(emp);
      return;
    }
    items.forEach(t => {
      const card = document.createElement('div');
      card.className = 'gexe-bage__card';
      card.setAttribute('data-ticket-id', t.id);
      card.innerHTML = `
        <div class="gexe-bage__title">#${t.id} — ${t.name || ''}</div>
        <div class="gexe-bage__meta">
          <span>Статус: ${statusName(t.status)}</span>
          <span>Категория: ${t.category || '-'}</span>
        </div>
        <div class="gexe-bage__footer">
          <span>Обновлено: ${formatDate(t.date_mod)}</span>
          <span>${t.location || ''}</span>
        </div>
      `;
      els.grid.appendChild(card);
    });
  }

  function setActiveStatus(){
    els.status.querySelectorAll('.gexe-bage__pill').forEach(b => {
      b.classList.toggle('is-active', parseInt(b.getAttribute('data-status'),10) === state.status);
    });
  }

  function loadCounters(){
    return post('gexe_bage_counters', {}).then(res=>{
      if (!res || !res.success) throw new Error(res?.data?.message || 'Не удалось получить счётчики');
      const c = res.data.counts || {};
      Object.keys(els.counters).forEach(k=>{
        if (els.counters[k]) els.counters[k].textContent = c[k] != null ? c[k] : '0';
      });
    }).catch(e=>showError(e.message || 'Ошибка счётчиков'));
  }

  function loadList(){
    showError('');
    if (loading) return Promise.resolve();
    setLoading(true);
    return post('gexe_bage_list_tickets', {
      page: state.page,
      per_page: perPage,
      status: state.status,
      category: state.category,
      q: state.q
    }).then(res=>{
      if (!res || !res.success) throw new Error(res?.data?.message || 'Не удалось загрузить список');
      renderCards(res.data.items || []);
      const total = res.data.total || 0;
      els.page.textContent = state.page;
      els.prev.disabled = state.page <= 1;
      els.next.disabled = (state.page * perPage) >= total;
    }).catch(e=>showError(e.message || 'Ошибка загрузки списка'))
      .finally(()=>setLoading(false));
  }

  // events
  els.status.addEventListener('click', (ev)=>{
    const btn = ev.target.closest('.gexe-bage__pill');
    if (!btn) return;
    state.status = parseInt(btn.getAttribute('data-status') || '0', 10);
    state.page = 1;
    setActiveStatus();
    loadList().then(loadCounters);
  });
  els.search.addEventListener('input', ()=>{
    state.q = (els.search.value || '').trim();
    state.page = 1;
    loadList();
  });
  els.prev.addEventListener('click', ()=>{
    if (state.page > 1){ state.page--; loadList(); }
  });
  els.next.addEventListener('click', ()=>{
    state.page++; loadList();
  });

  // init
  setActiveStatus();
  loadList().then(loadCounters);
})();

