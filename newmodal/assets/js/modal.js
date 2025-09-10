(function(){
  const root = document.getElementById('nm-root');
  if (!root) return;
  root.addEventListener('click', e => {
    const card = e.target.closest('.nm-card');
    if (!card) return;
    const id = card.getAttribute('data-id') || card.querySelector('.nm-card__meta')?.textContent?.replace('#','') || '';
    if (!id) return;
    openCommentModal(id);
  });

  function openCommentModal(id){
    const wrap = document.createElement('div');
    wrap.className = 'nm-modal';
    wrap.innerHTML = `
      <div class="nm-modal__box">
        <div class="nm-modal__title">Комментарий к #${id}</div>
        <textarea class="nm-modal__textarea" maxlength="4000" placeholder="Ваш комментарий..."></textarea>
        <div class="nm-modal__actions">
          <button class="nm-btn nm-btn--primary">Отправить</button>
          <button class="nm-btn nm-btn--ghost">Закрыть</button>
        </div>
        <div class="nm-modal__error" hidden></div>
      </div>`;
    document.body.appendChild(wrap);
    const ta = wrap.querySelector('textarea');
    const btnSend = wrap.querySelector('.nm-btn--primary');
    const btnClose = wrap.querySelector('.nm-btn--ghost');
    btnClose.onclick = ()=> wrap.remove();
    btnSend.onclick = async ()=>{
      const body = new URLSearchParams(); const rid=(crypto&&crypto.randomUUID)?crypto.randomUUID():String(Date.now()); body.append('rid', rid);
      body.append('action','nm_add_comment');
      body.append('nm_nonce', nmAjax.nonce);
      body.append('id', id);
      body.append('text', ta.value || '');
      const r = await fetch(nmAjax.url, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body});
      const data = await r.json().catch(()=>({ok:false,message:'Ошибка сети'}));
      const err = wrap.querySelector('.nm-modal__error');
      if (!data.ok){ err.hidden=false; err.textContent = data.message || 'Ошибка'; if(data.extra && data.extra.api){ const d=document.createElement('details'); d.className='nm-error-details'; const s=document.createElement('summary'); s.textContent='Показать детали'; d.appendChild(s); const pre=document.createElement('pre'); pre.textContent=JSON.stringify(data.extra.api,null,2); d.appendChild(pre); err.appendChild(d);} return; }
      wrap.remove();
      if (window.refreshListAfterAction) window.refreshListAfterAction();
    };
  }
})();
