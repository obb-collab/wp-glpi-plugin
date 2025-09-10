/* newmodal/bage/assets/bage.js */
(function($){
  'use strict';

  function uuidv4(){
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    // fallback
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
      var r = Math.random()*16|0, v = c === 'x' ? r : (r&0x3|0x8);
      return v.toString(16);
    });
  }

  function apiGet(action, params){
    params = params || {};
    params.action = action;
    params._ajax_nonce = NM.nonce;
    return $.get(NM.ajaxUrl, params);
  }
  function apiPost(action, data){
    var fd = new FormData();
    fd.append('action', action);
    fd.append('_ajax_nonce', NM.nonce);
    for (var k in data){ if (data.hasOwnProperty(k)) fd.append(k, data[k]); }
    return fetch(NM.ajaxUrl, { method:'POST', body: fd }).then(r=>r.json());
  }

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
    apiGet('nm_get_cards', params).done(function(resp){
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
    // show root + load form (controller echoes template)
    $('#nm-overlay').show();
    $('#nm-new-ticket-root').show().html('<div class="nm-modal"><div class="nm-modal-body">...</div></div>');
    apiGet('nm_new_ticket_form').done(function(html){
      $('#nm-new-ticket-root').html(html);
      $(document).trigger('nm:newTicket:open');
    });
  }

  function openTicketModal(id){
    $('#nm-overlay').show();
    $('#nm-modal-root').show().html('<div class="nm-modal"><div class="nm-modal-body">Загрузка...</div></div>');
    apiGet('nm_get_card', { ticket_id: id }).done(function(resp){
      if (!resp || !resp.ok){ $('#nm-modal-root').html('<div class="nm-modal"><div class="nm-modal-body">Ошибка</div></div>'); return; }
      var t = resp.ticket, f = resp.followups||[];
      var html = '<div class="nm-modal">'+
                   '<div class="nm-modal-header"><div class="nm-modal-title">#'+t.id+' — '+$('<div/>').text(t.name).html()+'</div><button class="nm-modal-close">&times;</button></div>'+\
                   '<div class="nm-modal-body">'+
                     '<div class="nm-ticket-meta"><span class="nm-tag status-'+t.status+'">'+(NM.statuses[String(t.status)]||('Status '+t.status))+'</span>'+\
                     (t.category_name?'<span class="nm-tag">'+t.category_name+'</span>':'')+\
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

  function closeAllModals(){
    $('#nm-overlay').hide();
    $('#nm-modal-root').hide().empty();
    $('#nm-new-ticket-root').hide().empty();
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
    closeAllModals();
  });

  // Search
  $(document).on('input', '#nm-search', function(){
    var q = $(this).val();
    window.NM_STATE.search = q;
    // small debounce
    clearTimeout(window.__nmSearchTimer);
    window.__nmSearchTimer = setTimeout(loadCards, 250);
  });

  // Initialize
  $(function(){
    if (!document.getElementById('nm-root')) return;
    window.NM_STATE = { status: [], search: '', page: 1, per_page: 20 };
    loadCards();
  });

  // Expose actions from modal/new-ticket scripts
  window.NM_API = {
    apiGet, apiPost, uuidv4, loadCards, closeAllModals
  };
})(jQuery);
