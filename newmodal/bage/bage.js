(function($){
  'use strict';
  const S = window.gexeNewBage || {};
  const $root = $('.gexe-bage');
  const $list = $('<div class="gexe-bage__list" id="gexe-bage-list"></div>').appendTo($root);

  function showError(msg){
    alert(msg || 'Ошибка');
  }

  function fetchTickets(page=1, q=''){
    return $.ajax({
      url: S.ajaxUrl,
      method: 'GET',
      data: { action: 'gexe_nm_list_tickets', _ajax_nonce: S.nonce, page, q },
      dataType: 'json'
    });
  }

  function renderItem(it){
    const id = it.id || it.ID || 0;
    const title = it.name || it.subject || ('Заявка #' + id);
    return $(
      `<div class="gexe-bage__card" data-id="${id}">
         <div class="gexe-bage__title">${title}</div>
         <div class="gexe-bage__meta">#${id}</div>
       </div>`
    );
  }

  function bindCardClicks(){
    $list.on('click', '.gexe-bage__card', function(){
      const id = parseInt($(this).data('id'),10);
      if (!id) return;
      window.gexeNmOpenModal(id);
    });
  }

  function initSearch(){
    const $s = $('#gexe-bage-search');
    $s.on('input', function(){
      load(1, $(this).val() || '');
    });
  }

  function load(page=1, q=''){
    fetchTickets(page, q).done(function(res){
      if (!res || !res.ok) return showError(S.i18n && S.i18n.loadError);
      $list.empty();
      (res.items || []).forEach(function(it){
        $list.append(renderItem(it));
      });
    }).fail(function(){
      showError(S.i18n && S.i18n.loadError);
    });
  }

  function initNewButton(){
    $('#gexe-bage-new').on('click', function(e){
      e.preventDefault();
      if (window.gexeNmOpenNewTicket) window.gexeNmOpenNewTicket();
    });
  }

  $(function(){
    bindCardClicks();
    initSearch();
    initNewButton();
    load(1,'');
  });
})(jQuery);

