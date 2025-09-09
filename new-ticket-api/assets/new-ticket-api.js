(function(){
  const ajax = window.ntaAjax || {};
  function $(sel, ctx){ return (ctx||document).querySelector(sel); }

  function setError(el, msg){ if(!el) return; el.textContent = msg||''; el.hidden = !msg; }
  function setLoading(el, on){ if(!el) return; el.classList.toggle('nta-field--loading', !!on); }

  function renderOptions(select, list, placeholder){
    if(!select) return;
    const cur = select.value;
    select.innerHTML = '';
    if(placeholder){
      const o = document.createElement('option'); o.value=''; o.textContent=placeholder; select.appendChild(o);
    }
    (list||[]).forEach(item=>{
      const opt = document.createElement('option');
      opt.value = String(item.id);
      opt.textContent = item.completename || item.label || item.name || ('#'+item.id);
      select.appendChild(opt);
    });
    if(cur && select.querySelector(`option[value="${CSS.escape(cur)}"]`)){ select.value = cur; }
  }

  async function postForm(data){
    const fd = new FormData();
    Object.keys(data).forEach(k=>fd.append(k, data[k]));
    const r = await fetch(ajax.url, {method:'POST', credentials:'same-origin', body:fd});
    return r.json();
  }

  async function fetchList(action){
    try{ return await postForm({action, nonce: ajax.nonce}); }
    catch(e){ return {ok:false, code:'network_error', message:'Network error'}; }
  }

  function toggleAssignee(form){
    const selfCb = form.querySelector('.nta-self');
    const assSel = form.querySelector('select[name="assignee_id"]');
    const on = !!(selfCb && selfCb.checked);
    if(assSel){ assSel.disabled = on; if(on){ assSel.value=''; } }
  }

  document.addEventListener('click', function(e){
    if(e.target.closest('.nta-open-modal')){
      const wrap = $('.nta-wrap'); if(!wrap) return;
      wrap.classList.add('nta-wrap--open');
      loadDicts(wrap);
    }
    if(e.target.closest('.nta-close-modal')){
      $('.nta-wrap')?.classList.remove('nta-wrap--open');
    }
  });

  async function loadDicts(scope){
    const form = scope.querySelector('form.nta-form'); if(!form) return;
    const catWrap = form.querySelector('[data-nta-field="category"]');
    const locWrap = form.querySelector('[data-nta-field="location"]');
    const assWrap = form.querySelector('[data-nta-field="assignee"]');
    const catSel = form.querySelector('select[name="category_id"]');
    const locSel = form.querySelector('select[name="location_id"]');
    const assSel = form.querySelector('select[name="assignee_id"]');
    const catErr = form.querySelector('[data-nta-error="category"]');
    const locErr = form.querySelector('[data-nta-error="location"]');
    const assErr = form.querySelector('[data-nta-error="assignee"]');

    setLoading(catWrap,true); setLoading(locWrap,true); setLoading(assWrap,true);
    setError(catErr,''); setError(locErr,''); setError(assErr,'');

    const [cats, locs, ass] = await Promise.all([
      fetchList('nta_get_categories'),
      fetchList('nta_get_locations'),
      fetchList('nta_get_assignees')
    ]);

    if(cats && cats.ok){ renderOptions(catSel, cats.list, 'Select category…'); }
    else { setError(catErr, (cats && cats.message) || 'Failed to load categories'); }
    setLoading(catWrap,false);

    if(locs && locs.ok){ renderOptions(locSel, locs.list, 'Select location…'); }
    else { setError(locErr, (locs && locs.message) || 'Failed to load locations'); }
    setLoading(locWrap,false);

    if(ass && ass.ok){ renderOptions(assSel, ass.list, 'Select assignee…'); }
    else { setError(assErr, (ass && ass.message) || 'Failed to load assignees'); }
    setLoading(assWrap,false);
  }

  document.addEventListener('change', function(e){
    const form = e.target && e.target.closest('form.nta-form'); if(!form) return;
    if(e.target.matches('.nta-self')) toggleAssignee(form);
  });

  const form = document.querySelector('.nta-wrap form.nta-form');
  if(form){
    form.addEventListener('submit', async function(ev){
      ev.preventDefault();
      const btn = form.querySelector('.nta-submit');
      const err = form.querySelector('.nta-submit-error');
      const ok = form.querySelector('.nta-submit-success');
      setError(err,''); if(ok) ok.textContent='';

      const title = form.querySelector('input[name="title"]').value.trim();
      const content = form.querySelector('textarea[name="content"]').value.trim();
      const category_id = form.querySelector('select[name="category_id"]').value;
      const location_id = form.querySelector('select[name="location_id"]').value;
      const self_assign = form.querySelector('.nta-self').checked ? '1' : '';
      const assignee_id = form.querySelector('select[name="assignee_id"]').value;

      if(title.length<3) return setError(err,'Введите тему (мин. 3 символа)');
      if(content.length<3) return setError(err,'Введите описание (мин. 3 символа)');
      if(!category_id) return setError(err,'Выберите категорию');
      if(!location_id) return setError(err,'Выберите местоположение');
      if(!self_assign && !assignee_id) return setError(err,'Выберите исполнителя или отметьте «Назначить меня»');

      btn && (btn.disabled = true);
      try{
        const res = await postForm({
          action: 'nta_create_ticket_api',
          nonce: ajax.nonce,
          title, content, category_id, location_id, assignee_id, self_assign
        });
        if(res && res.ok){
          const tid = res.ticket_id || (res.data && res.data.ticket_id);
          if(ok){ ok.textContent = tid ? ('Заявка #'+tid+' создана по API') : 'Заявка создана по API'; }
          form.reset(); toggleAssignee(form);
          setTimeout(()=>{ document.querySelector('.nta-wrap')?.classList.remove('nta-wrap--open'); }, 800);
        }else if(res && res.code === 'already_exists'){
          if(ok){ ok.textContent = 'Такая заявка уже создана недавно'+(res.ticket_id?(' (#'+res.ticket_id+')'):''); }
          setTimeout(()=>{ document.querySelector('.nta-wrap')?.classList.remove('nta-wrap--open'); }, 800);
        }else{
          setError(err, (res && res.message) || 'Ошибка отправки по API');
        }
      }catch(e){
        setError(err, 'Сетевая ошибка при отправке');
      }finally{
        btn && (btn.disabled = false);
      }
    });
  }
})();
