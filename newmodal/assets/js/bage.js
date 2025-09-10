/* newmodal/assets/js/bage.js */
(function($){
  'use strict';

  function renderCards(items){
    var html = '';
    items.forEach(function(it){
      var tags = '';
      tags += '<span class="nm-tag status-'+it.status+'">'+ (NM.statuses[String(it.status)]||('Status '+it.status)) +'</span>';
      if (it.category_name) tags += '<span class="nm-tag">'+ it.category_name +'</span>';
      html += '<div class="nm-card" data-id="'+it.id+'">' +
                '<div class="nm-title" data-ticket="'+it.id+'">#'+it.id+' — '+$('<div/>').text(it.name).html()+'</div>' +
                '<div class="nm-meta"><span>'+ (it.date||'') +'</span><span>'+ (it.assignee_id?'#'+it.assignee_id:'') +'</span></div>'+
                '<div class="nm-tags">'+ tags +'</div>'+
              '</div>';
    });
    if (!items.length) html = '<div class="nm-empty">Нет заявок</div>';
    $('#nm-cards').html(html);
  }

  function updateCounts(counts){
    $('.nm-badge[data-status]').each(function(){
      var st = String($(this).data('status'));
      var n = counts.by_status && counts.by_status[st] ? counts.by_status[st] : 0;
      $(this).find('.nm-count').text(n);
    });
  }

  function loadCards(){
    var params = window.NM_STATE || {};
    NM_API.apiGet('nm_get_cards', params).done(function(resp){
      if (!resp || !resp.ok){ console.warn(resp); return; }
      renderCards(resp.items||[]);
      if (resp.counts) updateCounts(resp.counts);
      $(document).trigger('nm:cards:loaded', resp);
    });
  }

  function applyStatusFilter(st){
    $('.nm-badge').removeClass('active');
    if (st) {
      $('.nm-badge[data-status="'+st+'"]').addClass('active');
      window.NM_STATE.status = [st];
    } else {
      window.NM_STATE.status = [];
    }
    loadCards();
  }

  function openNewTicket(){
    $('#nm-overlay').show();
    $('#nm-new-ticket-root').show().html('<div class="nm-modal"><div class="nm-modal-body">...</div></div>');
    NM_API.apiGet('nm_new_ticket_form').done(function(html){
      $('#nm-new-ticket-root').html(html);
      $(document).trigger('nm:newTicket:open');
    });
  }

  function openTicketModal(id){
    $('#nm-overlay').show();
    $('#nm-modal-root').show().html('<div class="nm-modal"><div class="nm-modal-body">Загрузка...</div></div>');
    NM_API.apiGet('nm_get_card', { ticket_id: id }).done(function(resp){
      if (!resp || !resp.ok){ $('#nm-modal-root').html('<div class="nm-modal"><div class="nm-modal-body">Ошибка</div></div>'); return; }
      var t = resp.ticket, f = resp.followups||[];
      var html = '<div class="nm-modal">'+
                   '<div class="nm-modal-header"><div class="nm-modal-title">#'+t.id+' — '+$('<div/>').text(t.name).html()+'</div><button class="nm-modal-close">&times;</button></div>'+\
                   '<div class="nm-modal-body">'+
                     '<div class="nm-ticket-meta"><span class="nm-tag status-'+t.status+'">'+(NM.statuses[String(t.status)]||('Status '+t.status))+'</span>'+
                     (t.category_name?'<span class="nm-tag">'+t.category_name+'</span>':'')+
                     '</div>'+\
                     '<div class="nm-ticket-content">'+ (t.content?$('<div/>').text(t.content).html():'') +'</div>'+\
                     '<div class="nm-followups"><div class="nm-followups-title">Комментарии</div><div class="nm-followups-list">';
      f.forEach(function(x){
        html += '<div class="nm-followup"><div class="nm-followup-head">'+ (x.user_name||'') +' — '+ (x.date||'') +'</div><div class="nm-followup-body">'+$('<div/>').text(x.content||'').html()+'</div></div>';
      });
      html +=        '</div>'+\
                     '<form id="nm-followup-form"><textarea name="body" placeholder="Добавить комментарий" required></textarea>'+\
                     '<div class="nm-actions">'+\
                       '<button type="submit" class="nm-btn" id="nm-send-comment">Отправить</button>'+\
                       '<button type="button" class="nm-btn" id="nm-take-in-work" data-status="2">Принято в работу</button>'+\
                     '</div>'+\
                     '</form>'+\
                   '</div>'+\
                 '</div>';
      $('#nm-modal-root').html(html).data('ticket', t.id);
      $(document).trigger('nm:modal:open', t);
    });
  }

  $(document).on('click', '.nm-badge', function(){
    var st = $(this).data('status');
    applyStatusFilter(st);
  });

  $(document).on('click', '#nm-new-ticket', function(e){
    e.preventDefault();
    openNewTicket();
  });

  $(document).on('click', '.nm-card .nm-title', function(){
    var id = $(this).data('ticket');
    openTicketModal(id);
  });

  $(document).on('click', '.nm-modal-close, #nm-overlay', function(){
    NM_API.closeAllModals();
  });

  $(document).on('input', '#nm-search', function(){
    var q = $(this).val();
    window.NM_STATE.search = q;
    clearTimeout(window.__nmSearchTimer);
    window.__nmSearchTimer = setTimeout(loadCards, 250);
  });

  $(function(){
    if (!document.getElementById('nm-root')) return;
    window.NM_STATE = { status: [], search: '', page: 1, per_page: 20 };
    loadCards();
  });

  window.NM_API = $.extend({}, window.NM_API, { loadCards: loadCards });
})(jQuery);
