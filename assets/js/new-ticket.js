// Lightweight binder for "New ticket (API)" modal submit
// Uses the same visual components and error placeholders as existing form.
(function(){
  'use strict';
  function qs(s, r){ return (r||document).querySelector(s); }
  function setError(el, msg){
    if (!el) return;
    el.textContent = msg || '';
    el.hidden = !msg;
  }
  function setLoading(btn, on){
    if (!btn) return;
    btn.disabled = !!on;
    btn.classList.toggle('is-loading', !!on);
  }
  async function postAjax(params){
    const res = await fetch(gexeAjax.ajax_url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: new URLSearchParams(params).toString()
    });
    const text = await res.text();
    let json = {};
    try { json = JSON.parse(text); } catch(_){ }
    if (res.status < 200 || res.status >= 300) {
      if (json && json.ok) return json;
      throw new Error('HTTP ' + res.status + (json && json.message ? (': ' + json.message) : ''));
    }
    return json;
  }

  document.addEventListener('click', async function(e){
    const btn = e.target.closest('[data-gexe-newticket-submit]');
    if (!btn) return;
    const form = btn.closest('form');
    if (!form) return;
    const err = qs('.gnt-submit-error', form);
    setError(err, '');
    setLoading(btn, true);
    try{
      const subject  = qs('[name="subject"]', form)?.value?.trim() || '';
      const content  = qs('[name="content"]', form)?.value?.trim() || '';
      const category = qs('[name="category_id"]', form)?.value || '';
      const location = qs('[name="location_id"]', form)?.value || '';
      const assignee = qs('[name="assignee_id"]', form)?.value || '';
      if (!subject || !content || !category || !location) {
        setError(err, 'Заполните тему, описание, категорию и местоположение');
        setLoading(btn, false);
        return;
      }
      const resp = await postAjax({
        action: 'gexe_create_ticket_api',
        nonce: gexeAjax.nonce,
        subject: subject,
        content: content,
        category_id: category,
        location_id: location,
        assignee_id: assignee
      });
      if (!resp || !resp.ok) {
        throw new Error(resp && resp.message ? resp.message : 'Не удалось создать заявку');
      }
      // Show success
      btn.textContent = 'Создано: #' + resp.ticket_id;
      btn.disabled = true;
      setError(err, '');
      // Dispatch event for list refreshers
      document.dispatchEvent(new CustomEvent('gexe:newticket:created', {detail: resp}));
    }catch(ex){
      setError(qs('.gnt-submit-error', form), ex && ex.message ? ex.message : String(ex));
    }finally{
      setLoading(btn, false);
    }
  });
})();
