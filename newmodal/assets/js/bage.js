(function(){
  const root = document.getElementById('nm-root');
  if (!root) return;
  const state = { page: 0, loading: false, done: false, q: '', items: [], scope: 'mine', seen: new Set() };
  const counters = document.createElement('div'); counters.className='nm-counters'; root.appendChild(counters);
  const list = document.createElement('div'); list.className='nm-list'; root.appendChild(list);
  const loader = document.createElement('div'); loader.className='nm-loader'; loader.textContent='Загрузка...'; root.appendChild(loader);
  const sentinel = document.createElement('div'); root.appendChild(sentinel);
  function showSkeletons(){ if(state.page===0){ for(let i=0;i<3;i++){ const d=document.createElement('div'); d.className='nm-skeleton nm-skeleton-card'; list.appendChild(d);} } }
  function clearSkeletons(){ list.querySelectorAll('.nm-skeleton-card').forEach(e=>e.remove()); }
  function escapeHtml(s){ return (s||'').replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])); }
  function renderItems(items){ for(const it of items){ if(state.seen.has(it.id)) continue; state.seen.add(it.id); const card=document.createElement('div'); card.className='nm-card'; card.setAttribute('data-id', it.id); card.innerHTML='<div class="nm-card__title">'+escapeHtml(it.name||'')+'</div><div class="nm-card__meta">#'+it.id+'</div>'; list.appendChild(card);} }
  async function fetchPage(){ if(state.loading || state.done) return; state.loading=true; loader.style.display='block'; showSkeletons(); const body=new URLSearchParams(); body.append('action','nm_get_cards'); body.append('nm_nonce', nmAjax.nonce); body.append('page', String(state.page)); body.append('scope', state.scope); body.append('q', state.q); const resp=await fetch(nmAjax.url,{method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body}); const data=await resp.json().catch(()=>({ok:false})); if(!data.ok){ loader.textContent=data.message||'Ошибка'; state.done=true; state.loading=false; clearSkeletons(); return;} if((data.items||[]).length===0 && state.page===0){ list.innerHTML='<div class="nm-empty">Нет заявок</div>'; } else { renderItems(data.items||[]); } state.page++; if(!data.items || data.items.length===0){ state.done=true; loader.textContent='Конец списка'; } state.loading=false; loader.style.display='none'; clearSkeletons(); }
  const io=new IntersectionObserver(entries=>{ entries.forEach(e=>{ if(e.isIntersecting) fetchPage(); }); }, {rootMargin:'600px'});
  io.observe(sentinel);
  fetchPage();
  async function fetchCounts(){ const b=new URLSearchParams(); b.append('action','nm_get_counts'); b.append('nm_nonce', nmAjax.nonce); b.append('scope', state.scope); const r=await fetch(nmAjax.url,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:b}); const d=await r.json().catch(()=>({ok:false})); if(d&&d.ok){ counters.textContent=`Все ${d.total} · В работе ${d.work} · В плане ${d.plan} · В стопе ${d.stop} · Новые ${d.new} · Просрочены ${d.overdue}`; } }
  fetchCounts();
  function resetAndFetch(){ state.page=0; state.done=false; state.seen.clear(); list.innerHTML=''; fetchPage(); }
  window.refreshListAfterAction=function(){ try{fetchCounts();}catch(e){} resetAndFetch(); };
})();
