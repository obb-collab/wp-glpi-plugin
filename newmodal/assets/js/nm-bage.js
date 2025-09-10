(function($){
  'use strict';

  const state = {
    status: 0,
    q: ''
  };

  function errBox(message){
    alert(message || 'Ошибка');
  }

  function renderCards(items){
    const root = $('#nm-cards').empty();
    if (!items || !items.length){
      root.append('<div class="nm-empty">Заявок нет</div>');
      return;
    }
    items.forEach(t => {
      const overdue = t.time_to_resolve && (new Date(t.time_to_resolve) < new Date()) ? ' nm-card--overdue' : '';
      const html = `
        <div class="nm-card${overdue}" data-id="${t.id}">
          <div class="nm-card__head">
            <span class="nm-card__id">#${t.id}</span>
            <span class="nm-card__status s-${t.status}">S${t.status}</span>
          </div>
          <div class="nm-card__title">${escapeHtml(t.name||'Без темы')}</div>
          <div class="nm-card__desc">${escapeHtml((t.content||'').slice(0,160))}</div>
          <div class="nm-card__meta">
            <span>${t.date||''}</span>
            ${t.time_to_resolve ? `<span title="Срок">${t.time_to_resolve}</span>`:''}
          </div>
          <button class="nm-card__open" data-action="open" data-id="${t.id}">Открыть</button>
        </div>`;
      root.append(html);
    });
  }

  function escapeHtml(s){ return $('<div>').text(s||'').html(); }

  function loadCounts(){
    $.post(nmAjax.ajax_url, {
      action: 'nm_get_counts',
      nonce: nmAjax.nonce
    }).done(res=>{
      if (!res.ok) return;
      const map = res.data.counts || {};
      $('.nm-counts .nm-badge').each(function(){
        const st = $(this).data('status');
        $(this).text(map[st] || 0);
      });
    });
  }

  function loadCards(){
    $.post(nmAjax.ajax_url, {
      action: 'nm_get_cards',
      nonce: nmAjax.nonce,
      status: state.status,
      q: state.q
    }).done(res=>{
      if (!res.ok){ errBox(res.data && res.data.error); return; }
      renderCards(res.data.items || []);
      bindOpenButtons();
    }).fail(()=>errBox('Не удалось загрузить список'));
  }

  function bindOpenButtons(){
    $('#nm-cards [data-action="open"]').off('click').on('click', function(){
      const id = parseInt($(this).data('id'), 10);
      if (!id) return;
      window.nmModal.open(id);
    });
  }

  function initFilters(){
    // Status dropdown
    $('[data-dd="status"]').on('click', ()=>$('#nm-dd-status').toggle());
    $('#nm-dd-status button').on('click', function(){
      state.status = parseInt($(this).data('status'),10)||0;
      $('#nm-dd-status').hide();
      loadCards(); loadCounts();
    });
    // Search
    $('#nm-search').on('input', function(){
      state.q = $(this).val().trim();
      loadCards();
    });
    // New Ticket
    $('#nm-open-new-ticket').on('click', function(){
      window.nmNewTicket.open();
    });
  }

  $(function(){
    initFilters();
    loadCounts();
    loadCards();
  });

})(jQuery);
