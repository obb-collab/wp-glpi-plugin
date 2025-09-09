(function(){
  const ajax = window.ntAjax || {};
  function $(sel, ctx){ return (ctx||document).querySelector(sel); }
  function $all(sel, ctx){ return Array.from((ctx||document).querySelectorAll(sel)); }

  function setError(el, msg){
    if(!el) return;
    el.textContent = msg || '';
    el.hidden = !msg;
  }

  function setLoading(container, on){
    if(!container) return;
    container.classList.toggle('nt-field--loading', !!on);
  }

  function renderOptions(select, list, placeholder){
    if(!select) return;
    const cur = select.value;
    select.innerHTML = '';
    if(placeholder){
      const opt0 = document.createElement('option');
      opt0.value = '';
      opt0.textContent = placeholder;
      select.appendChild(opt0);
    }
    (list||[]).forEach(item=>{
      const opt = document.createElement('option');
      opt.value = String(item.id);
      opt.textContent = item.completename || item.label || item.name || ('#'+item.id);
      select.appendChild(opt);
    });
    // try keep current if exists
    if(cur && $(`option[value="${CSS.escape(cur)}"]`, select)){
      select.value = cur;
    }
  }

  async function postFormData(data){
    const fd = new FormData();
    Object.keys(data).forEach(k=>fd.append(k, data[k]));
    const r = await fetch(ajax.url, {method:'POST', credentials:'same-origin', body:fd});
    return r.json();
  }

  async function fetchList(action){
    try{
      const res = await postFormData({action, nonce: ajax.nonce});
      return res;
    }catch(e){
      return {ok:false, code:'network_error', message:'Network error'};
    }
  }

  function toggleAssignee(form){
    const selfCb = $('.nt-self', form);
    const assSel = $('select[name="assignee_id"]', form);
    const on = !!(selfCb && selfCb.checked);
    if(assSel){
      assSel.disabled = on;
      if(on){ assSel.value = ''; }
    }
  }

  // Open / close modal
  document.addEventListener('click', function(e){
    if (e.target.closest('.nt-open-modal')){
      const wrap = $('.nt-wrap');
      if(wrap){
        wrap.classList.add('nt-wrap--open');
        // kick off dict loads
        loadDictionaries(wrap);
      }
    }
    if (e.target.closest('.nt-close-modal')){
      const wrap = $('.nt-wrap');
      if(wrap){
        wrap.classList.remove('nt-wrap--open');
      }
    }
  });

  async function loadDictionaries(scope){
    const form = $('form.nt-form', scope);
    if(!form) return;
    const catWrap = $('[data-nt-field="category"]', form);
    const locWrap = $('[data-nt-field="location"]', form);
    const assWrap = $('[data-nt-field="assignee"]', form);
    const catSel = $('select[name="category_id"]', form);
    const locSel = $('select[name="location_id"]', form);
    const assSel = $('select[name="assignee_id"]', form);
    const catErr = $('[data-nt-error="category"]', form);
    const locErr = $('[data-nt-error="location"]', form);
    const assErr = $('[data-nt-error="assignee"]', form);

    setLoading(catWrap, true); setError(catErr,'');
    setLoading(locWrap, true); setError(locErr,'');
    setLoading(assWrap, true); setError(assErr,'');

    const [cats, locs, ass] = await Promise.all([
      fetchList('nt_get_categories'),
      fetchList('nt_get_locations'),
      fetchList('nt_get_assignees')
    ]);

    // categories
    if(cats && cats.ok){
      renderOptions(catSel, cats.list, 'Select category…');
    }else{
      setError(catErr, (cats && cats.message) || 'Failed to load categories');
    }
    setLoading(catWrap,false);

    // locations
    if(locs && locs.ok){
      renderOptions(locSel, locs.list, 'Select location…');
    }else{
      setError(locErr, (locs && locs.message) || 'Failed to load locations');
    }
    setLoading(locWrap,false);

    // assignees
    if(ass && ass.ok){
      renderOptions(assSel, ass.list, 'Select assignee…');
    }else{
      setError(assErr, (ass && ass.message) || 'Failed to load assignees');
    }
    setLoading(assWrap,false);
  }

  // Form handlers
  document.addEventListener('change', function(e){
    const form = e.target && e.target.closest('form.nt-form');
    if(!form) return;
    if(e.target.matches('.nt-self')){
      toggleAssignee(form);
    }
  });

  const form = document.querySelector('.nt-wrap form.nt-form');
  if(form){
    form.addEventListener('submit', async function(ev){
      ev.preventDefault();
      const btn = $('.nt-submit', form);
      const submitErr = $('.nt-submit-error', form);
      const okMsg = $('.nt-submit-success', form);
      setError(submitErr,''); if(okMsg) okMsg.textContent='';

      // basic client validation
      const title = $('input[name="title"]', form).value.trim();
      const content = $('textarea[name="content"]', form).value.trim();
      const category_id = $('select[name="category_id"]', form).value;
      const location_id = $('select[name="location_id"]', form).value;
      const self_assign = $('.nt-self', form).checked ? '1' : '';
      const assignee_id = $('select[name="assignee_id"]', form).value;

      if(title.length<3){ return setError(submitErr,'Введите тему (мин. 3 символа)'); }
      if(content.length<3){ return setError(submitErr,'Введите описание (мин. 3 символа)'); }
      if(!category_id){ return setError(submitErr,'Выберите категорию'); }
      if(!location_id){ return setError(submitErr,'Выберите местоположение'); }
      if(!self_assign && !assignee_id){ return setError(submitErr,'Выберите исполнителя или отметьте «Назначить меня»'); }

      // send
      btn && (btn.disabled = true);
      try{
        const res = await postFormData({
          action:'nt_create_ticket',
          nonce: ajax.nonce,
          title, content, category_id, location_id, assignee_id, self_assign
        });
        if(res && res.ok){
          const tid = res.ticket_id || res.data && res.data.ticket_id;
          if(okMsg){ okMsg.textContent = tid ? ('Заявка #'+tid+' создана') : 'Заявка создана'; }
          // reset and close
          form.reset();
          toggleAssignee(form);
          setTimeout(()=>{ $('.nt-wrap')?.classList.remove('nt-wrap--open'); }, 800);
        }else if(res && res.code === 'already_exists'){
          if(okMsg){ okMsg.textContent = 'Такая заявка уже была создана недавно'+(res.ticket_id?(' (#'+res.ticket_id+')'):''); }
          setTimeout(()=>{ $('.nt-wrap')?.classList.remove('nt-wrap--open'); }, 800);
        }else{
          setError(submitErr, (res && res.message) || 'Ошибка отправки заявки');
        }
      }catch(e){
        setError(submitErr, 'Сетевая ошибка при отправке');
      }finally{
        btn && (btn.disabled = false);
      }
    });
  }
})();
