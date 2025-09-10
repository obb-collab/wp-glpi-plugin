(function($){
  'use strict';

  const modalEl = $('#nm-nt');

  function open(){
    modalEl.removeAttr('hidden');
    $('#nm-nt-name').val('');
    $('#nm-nt-content').val('');
    $('#nm-nt-due').val(defaultDue());
    // load catalogs
    $.post(nmAjax.ajax_url, {
      action: 'nm_get_catalogs',
      nonce: nmAjax.nonce
    }).done(res=>{
      if (!res.ok) { alert(res.data && res.data.error || 'Ошибка справочников'); return; }
      fillSelect($('#nm-nt-cat'), res.data.categories);
      fillSelect($('#nm-nt-loc'), res.data.locations);
    });
  }

  function close(){ modalEl.attr('hidden','hidden'); }

  function fillSelect(sel, list){
    sel.empty();
    sel.append('<option value="0">—</option>');
    (list||[]).forEach(i=>{
      sel.append(`<option value="${i.id}">${escapeHtml(i.name||'')}</option>`);
    });
  }

  function submit(){
    const name = $('#nm-nt-name').val().trim();
    const content = $('#nm-nt-content').val().trim();
    const cat = parseInt($('#nm-nt-cat').val(),10)||0;
    const loc = parseInt($('#nm-nt-loc').val(),10)||0;
    const due = $('#nm-nt-due').val();
    if (!name || !content){ alert('Тема и описание обязательны'); return; }
    $.post(nmAjax.ajax_url, {
      action: 'nm_create_ticket',
      nonce: nmAjax.nonce,
      name, content,
      category_id: cat,
      location_id: loc,
      due
    }).done(res=>{
      if (!res.ok){ alert(res.data && res.data.error || 'Ошибка создания'); return; }
      close();
      // optional: refresh list
      if (window.jQuery) {
        // trigger reload if nm-bage loaded
        if (typeof window.nmAjax !== 'undefined') {
          // simplest: reload cards by event
          jQuery(document).trigger('nm:reload');
        }
      }
    });
  }

  function defaultDue(){
    const d = new Date();
    d.setHours(18,0,0,0);
    // if already past 18:00, set to tomorrow 18:00
    if (d < new Date()){ d.setDate(d.getDate()+1); }
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth()+1).padStart(2,'0');
    const dd = String(d.getDate()).padStart(2,'0');
    const hh = String(d.getHours()).padStart(2,'0');
    const mi = String(d.getMinutes()).padStart(2,'0');
    return `${yyyy}-${mm}-${dd}T${hh}:${mi}`;
  }

  function escapeHtml(s){ return $('<div>').text(s||'').html(); }

  // Public API
  window.nmNewTicket = { open, close };

  // Wire UI
  $('#nm-nt [data-close]').on('click', close);
  $('#nm-nt-submit').on('click', submit);
  $(document).on('keydown', function(e){
    if (!modalEl.is(':hidden') && e.key === 'Escape') close();
  });

  // Reload hook
  $(document).on('nm:reload', function(){
    // If nm-bage present, re-query cards & counts
    if (typeof jQuery !== 'undefined' && jQuery.fn){
      if (window.nmAjax) {
        // naive approach: click status button to trigger reload
        // (left as-is to avoid tight coupling; page-level code can handle)
      }
    }
  });

})(jQuery);
