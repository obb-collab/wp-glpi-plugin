(function($){
  'use strict';

  const modalEl = $('#nm-modal');
  const bodyEl  = $('#nm-modal-body');
  const spinner = $('#nm-modal .nm-modal__spinner');

  function openModal(){
    modalEl.removeAttr('hidden');
    bodyEl.html('<div class="nm-loading">Загрузка…</div>');
  }
  function closeModal(){
    modalEl.attr('hidden', 'hidden');
    bodyEl.empty();
  }

  function fetchCard(id){
    spinner.removeAttr('hidden');
    return $.post(nmAjax.ajax_url, {
      action: 'nm_get_card',
      nonce: nmAjax.nonce,
      ticket_id: id
    }).always(()=>spinner.attr('hidden','hidden'));
  }

  function render(ticket, followups){
    const head = `
      <div class="nm-ticket">
        <div class="nm-ticket__row">
          <span class="nm-ticket__id">#${ticket.id}</span>
          <span class="nm-ticket__status s-${ticket.status}">S${ticket.status}</span>
        </div>
        <div class="nm-ticket__title">${escapeHtml(ticket.name||'Без темы')}</div>
        <div class="nm-ticket__desc">${escapeHtml(ticket.content||'')}</div>
        <div class="nm-ticket__meta">
          <span>Создано: ${ticket.date||''}</span>
          ${ticket.time_to_resolve ? `<span>Срок: ${ticket.time_to_resolve}</span>`:''}
          ${ticket.solvedate ? `<span>Решено: ${ticket.solvedate}</span>`:''}
        </div>
        <div class="nm-ticket__followups" id="nm-followups">
          ${(followups||[]).map(f=>`
            <div class="nm-fu">
              <div class="nm-fu__meta">${f.date||''} · ${f.users_id||''}</div>
              <div class="nm-fu__text">${escapeHtml(f.content||'')}</div>
            </div>
          `).join('')}
        </div>
      </div>
    `;
    bodyEl.html(head);
  }

  function addFollowup(id, msg){
    return $.post(nmAjax.ajax_url, {
      action: 'nm_add_followup',
      nonce: nmAjax.nonce,
      ticket_id: id,
      message: msg
    });
  }
  function updateStatus(id, status){
    return $.post(nmAjax.ajax_url, {
      action: 'nm_update_status',
      nonce: nmAjax.nonce,
      ticket_id: id,
      status: status
    });
  }

  function escapeHtml(s){ return $('<div>').text(s||'').html(); }

  // Public API
  window.nmModal = {
    open(id){
      if (!id) return;
      openModal();
      fetchCard(id).done(res=>{
        if (!res.ok) { bodyEl.html('<div class="nm-error">'+(res.data && res.data.error || 'Ошибка')+'</div>'); return; }
        render(res.data.ticket, res.data.followups);
        $('#nm-btn-add-followup').off('click').on('click', function(){
          const txt = $('#nm-followup-text').val().trim();
          if (!txt) return;
          addFollowup(id, txt).done(r=>{
            if (!r.ok) { alert(r.data && r.data.error || 'Ошибка'); return; }
            // reload followups
            fetchCard(id).done(rr=>{
              if (rr.ok) render(rr.data.ticket, rr.data.followups);
            });
          });
        });
        $('#nm-btn-update-status').off('click').on('click', function(){
          const st = parseInt($('#nm-status-select').val(),10)||0;
          if (!st) return;
          updateStatus(id, st).done(r=>{
            if (!r.ok) { alert(r.data && r.data.error || 'Ошибка'); return; }
            fetchCard(id).done(rr=>{
              if (rr.ok) render(rr.data.ticket, rr.data.followups);
            });
          });
        });
      });
    }
  };

  // Close handlers
  $('#nm-modal [data-close]').on('click', closeModal);
  $(document).on('keydown', function(e){
    if (e.key === 'Escape') closeModal();
  });

})(jQuery);
