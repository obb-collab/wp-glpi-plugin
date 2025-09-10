/* newmodal/modal/assets/modal.js */
(function($){
  'use strict';

  function disableBtn($btn, on){
    if (!$btn || !$btn.length) return;
    $btn.prop('disabled', !!on);
  }

  $(document).on('submit', '#nm-followup-form', function(e){
    e.preventDefault();
    var $btn = $('#nm-send-comment');
    disableBtn($btn, true);
    var ticketId = $('#nm-modal-root').data('ticket');
    var body = $(this).find('textarea[name="body"]').val();
    var reqId = (NM_API && NM_API.uuidv4)? NM_API.uuidv4(): (Date.now()+'');
    NM_API.apiPost('nm_add_comment', { ticket_id: ticketId, body: body, request_id: reqId }).then(function(resp){
      if (!resp.ok){
        alert(resp.message||'Ошибка');
        disableBtn($btn, false);
        return;
      }
      // Reload card
      NM_API.apiGet('nm_get_card', {ticket_id: ticketId}).done(function(r){
        if (r && r.ok){
          // re-render followups portion
          var f = r.followups||[], html='';
          f.forEach(function(x){
            html += '<div class="nm-followup"><div class="nm-followup-head">'+ (x.user_name||'') +' — '+ (x.date||'') +'</div><div class="nm-followup-body">'+$('<div/>').text(x.content||'').html()+'</div></div>';
          });
          $('.nm-followups-list').html(html);
          $('#nm-followup-form textarea').val('');
          $(document).trigger('nm:modal:updated', {type:'comment'});
        }
        disableBtn($btn, false);
      });
    }).catch(function(){
      alert('Network error');
      disableBtn($btn, false);
    });
  });

  $(document).on('click', '#nm-take-in-work', function(){
    var ticketId = $('#nm-modal-root').data('ticket');
    var status = $(this).data('status') || 2;
    var reqId = (NM_API && NM_API.uuidv4)? NM_API.uuidv4(): (Date.now()+'');
    var $btn = $(this);
    $btn.prop('disabled', true);
    NM_API.apiPost('nm_change_status', { ticket_id: ticketId, status: status, request_id: reqId }).then(function(resp){
      if (!resp.ok){ alert(resp.message||'Ошибка'); $btn.prop('disabled', false); return; }
      // refresh counts & list
      NM_API.loadCards();
      // refresh modal label
      NM_API.apiGet('nm_get_card', {ticket_id: ticketId}).done(function(r){
        if (r && r.ok){
          var t = r.ticket;
          $('.nm-ticket-meta .nm-tag').first().text(NM.statuses[String(t.status)] || ('Status '+t.status)).attr('class','nm-tag status-'+t.status);
        }
        $btn.prop('disabled', false);
      });
    }).catch(function(){ alert('Network error'); $btn.prop('disabled', false); });
  });

})(jQuery);
