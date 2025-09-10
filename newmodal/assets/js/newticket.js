/* newmodal/new-ticket/assets/new-ticket.js */
(function($){
  'use strict';

  function disableBtn($btn, on){ if ($btn && $btn.length) $btn.prop('disabled', !!on); }
  function errUnder($el, msg){
    var $c = $('<div class="nm-error"/>').text(msg||'');
    $el.next('.nm-error').remove();
    $el.after($c);
  }
  function clearErr($el){ $el.next('.nm-error').remove(); }

  function dropdown($input, endpoint){
    var $list = $input.siblings('.nm-dd');
    if (!$list.length) {
      $list = $('<div class="nm-dd" style="position:relative;"></div>');
      $input.after($list);
    }
    var q = $input.val() || '';
    NM_API.apiGet(endpoint, { q:q, page:1 }).done(function(resp){
      if (!resp || !resp.ok){ return; }
      var items = resp.items||[];
      var html = '<div class="nm-dd-list" style="position:absolute; z-index:10000; background:#0f1317; border:1px solid #2b3138; border-radius:8px; width:100%; max-height:200px; overflow:auto;">';
      items.forEach(function(it){
        html += '<div class="nm-dd-item" data-id="'+it.id+'" style="padding:8px; cursor:pointer;">'+$('<div/>').text(it.name).html()+'</div>';
      });
      html += '</div>';
      $list.html(html);
    });
  }

  $(document).on('focus', '#nm-nt-category-input', function(){ dropdown($(this), 'nm_catalog_categories'); })
             .on('input', '#nm-nt-category-input', function(){ dropdown($(this), 'nm_catalog_categories'); });
  $(document).on('focus', '#nm-nt-location-input', function(){ dropdown($(this), 'nm_catalog_locations'); })
             .on('input', '#nm-nt-location-input', function(){ dropdown($(this), 'nm_catalog_locations'); });
  $(document).on('focus', '#nm-nt-assignee-input', function(){
                if ($(this).prop('disabled')) return;
                dropdown($(this), 'nm_catalog_users');
              })
             .on('input', '#nm-nt-assignee-input', function(){ if (!$(this).prop('disabled')) dropdown($(this), 'nm_catalog_users'); });

  $(document).on('click', '.nm-dd-item', function(){
    var id = $(this).data('id');
    var name = $(this).text();
    var $wrap = $(this).closest('.nm-field');
    var input = $wrap.find('input[type="hidden"]')[0] || $wrap.find('input[type="text"]')[0];
    var $text = $wrap.find('input[type="text"]');
    $(input).val(id);
    $text.val(name);
    $wrap.find('.nm-dd').empty();
  });

  $(document).on('change', '#nm-nt-iamexec', function(){
    var on = $(this).is(':checked');
    var $ass = $('#nm-nt-assignee-input');
    $ass.prop('disabled', on);
    if (on){ $ass.val(''); $('#nm-nt-assignee').val(''); }
  });

  $(document).on('click', '#nm-nt-cancel', function(){
    NM_API.closeAllModals();
  });

  $(document).on('submit', '#nm-new-ticket-form', function(e){
    e.preventDefault();
    var $btn = $('#nm-nt-submit');
    disableBtn($btn, true);
    // collect
    var subject = $('#nm-nt-subject').val();
    var content = $('#nm-nt-content').val();
    var category_id = $('#nm-nt-category').val() || '';
    var location_id = $('#nm-nt-location').val() || '';
    var i_am_executor = $('#nm-nt-iamexec').is(':checked') ? 1 : 0;
    var assignee_id = $('#nm-nt-assignee').val() || '';
    var reqId = (NM_API && NM_API.uuidv4)? NM_API.uuidv4(): (Date.now()+'');

    // simple client validation
    var ok = true;
    if (!subject){ errUnder($('#nm-nt-subject'), 'Укажите тему'); ok = false; } else { clearErr($('#nm-nt-subject')); }
    if (!content){ errUnder($('#nm-nt-content'), 'Укажите описание'); ok = false; } else { clearErr($('#nm-nt-content')); }
    if (!ok){ disableBtn($btn, false); return; }

    var data = {
      subject: subject, content: content,
      category_id: category_id, location_id: location_id,
      i_am_executor: i_am_executor, assignee_id: assignee_id,
      request_id: reqId
    };
    NM_API.apiPost('nm_create_ticket', data).then(function(resp){
      if (!resp.ok){
        alert(resp.message||'Ошибка');
        disableBtn($btn, false);
        return;
      }
      // close and refresh list
      NM_API.closeAllModals();
      NM_API.loadCards();
      $(document).trigger('nm:newTicket:success', resp);
    }).catch(function(){
      alert('Network error');
      disableBtn($btn, false);
    });
  });

})(jQuery);
