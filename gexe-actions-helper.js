(function(){
  'use strict';

  function showToast(type, text){
    if (window.gexeShowNotice) window.gexeShowNotice(type, text);
    else if (type === 'error') alert(text);
  }

  function showError(code, details, status){
    if (window.gexeShowError) {
      window.gexeShowError(code, details, status);
    } else {
      showToast('error', code);
    }
  }

  function setButtonState(btn, state){
    if (!btn) return;
    btn.dataset.state = state;
    if (state === 'loading'){
      btn.disabled = true;
      btn.classList.add('is-loading');
    } else {
      btn.classList.remove('is-loading');
      btn.disabled = (state === 'done');
    }
  }

  function refreshAjaxNonce(){
    const ajax = window.gexeAjax || window.glpiAjax;
    if (!ajax) return Promise.reject(new Error('no_ajax'));
    const params = new URLSearchParams();
    params.append('action','gexe_refresh_actions_nonce');
    return fetch(ajax.url, {
      method: 'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: params.toString()
    }).then(r=>r.json()).then(data=>{
      if (data && data.success && data.data && data.data.nonce){
        ajax.nonce = data.data.nonce;
        return ajax.nonce;
      }
      throw new Error('nonce_refresh_failed');
    });
  }

  const ACTION_MAP = {
    accept: 'glpi_ticket_accept_sql',
    comment: 'glpi_comment_add',
    done: 'glpi_ticket_resolve',
    create: 'gexe_create_ticket'
  };

  async function performAction(type, payload){
    const ajax = window.gexeAjax || window.glpiAjax;
    if (!ajax || !ajax.url){
      showError('network_error');
      return { ok: false };
    }
    const data = Object.assign({}, payload || {});
    const btn = data.button || null;
    delete data.button;
    const nonceKey = ajax.nonce_key || 'nonce';
    const body = new FormData();
    body.append('action', ACTION_MAP[type] || type);
    body.append(nonceKey, ajax.nonce || '');
    body.append('nonce', ajax.nonce || '');
    body.append('_ajax_nonce', ajax.nonce || '');
    Object.keys(data).forEach(k=>body.append(k, data[k]));

    setButtonState(btn, 'loading');

    const send = async (retry) => {
      const res = await fetch(ajax.url, { method:'POST', body });
      let json = null;
      try { json = await res.clone().json(); }
      catch(e){ try { await res.clone().text(); } catch(e2){} }
      if (res.status === 403 && json && (json.error === 'nonce_failed' || json.error === 'AJAX_FORBIDDEN') && !retry){
        await refreshAjaxNonce();
        body.set(nonceKey, ajax.nonce || '');
        body.set('nonce', ajax.nonce || '');
        body.set('_ajax_nonce', ajax.nonce || '');
        return send(true);
      }
      return { res, json };
    };

    try {
      const { res, json } = await send(false);
      const ok = res.ok && json && (json.ok || json.success);
      if (!ok){
        const code = json && (json.error || json.code) || 'bad_response';
        showError(code, json && json.details, res.status);
        setButtonState(btn, 'error');
        return { ok:false, data: json };
      }
      setButtonState(btn, 'done');
      return { ok:true, data: json.payload || json.data || json };
    } catch(e){
      showError('network_error');
      setButtonState(btn, 'error');
      return { ok:false, error:e };
    } finally {
      if (btn && btn.dataset.state !== 'done') setButtonState(btn, 'idle');
    }
  }

  window.performAction = performAction;
  window.gexeRefreshNonce = refreshAjaxNonce;
  window.gexeSetButtonState = setButtonState;
})();
