(function($){
  'use strict';
  const R = window.gexeNm || {};

  const $overlay = $('<div class="nm-modal" id="nm-modal"></div>').appendTo('body');
  const $dlg = $(`
    <div class="nm-modal__dialog">
      <div class="nm-modal__hd">
        <div class="nm-modal__title" id="nm-title">Заявка</div>
        <div class="nm-meta" id="nm-meta"></div>
        <div><button class="nm-btn" id="nm-close">✕</button></div>
      </div>
      <div class="nm-modal__body">
        <div class="nm-comments" id="nm-comments"></div>
        <div class="nm-form">
          <textarea class="nm-textarea" id="nm-comment" placeholder="Комментарий..."></textarea>
        </div>
      </div>
      <div class="nm-modal__ft">
        <button class="nm-btn nm-btn--primary" id="nm-send">Отправить</button>
        <button class="nm-btn" id="nm-work" data-status="2">В работе</button>
        <button class="nm-btn" id="nm-plan" data-status="3">В плане</button>
        <button class="nm-btn" id="nm-stop" data-status="4">В стопе</button>
        <button class="nm-btn" id="nm-res" data-status="6">Решить</button>
      </div>
    </div>
  `).appendTo($overlay);

  let currentId = 0;

  function open(id){
    currentId = id;
    $overlay.show();
    $('#nm-title').text('Заявка #' + id);
    loadComments(id);
  }
  function close(){
    $overlay.hide();
    currentId = 0;
    $('#nm-comments').empty();
    $('#nm-comment').val('');
  }
  $('#nm-close').on('click', close);
  $overlay.on('click', function(e){ if (e.target === e.currentTarget) close(); });

  function ajax(payload){
    return $.ajax({
      url: R.ajaxUrl,
      method: 'POST',
      data: Object.assign({_ajax_nonce: R.nonce}, payload),
      dataType: 'json'
    });
  }

  function notifyError(msg){ alert(msg || 'Ошибка'); }

  function loadComments(id){
    // Для краткости — читаем из API напрямую (в изолированном модуле допустимо)
    ajax({action:'gexe_nm_list_tickets', page:1, q:'#'+id}).done(function(){
      // Ничего — заглушка списка, реально можно добавить отдельный экшн для followups
    });
  }

  $('#nm-send').on('click', function(){
    const txt = $('#nm-comment').val();
    if (!currentId || !txt) return;
    ajax({action:'gexe_nm_add_comment', ticket_id: currentId, text: txt}).done(function(res){
      if (!res || !res.ok) return notifyError(R.i18n && R.i18n.commentError);
      $('#nm-comment').val('');
      loadComments(currentId);
    }).fail(function(){
      notifyError(R.i18n && R.i18n.commentError);
    });
  });

  function changeStatus(st){
    if (!currentId || !st) return;
    ajax({action:'gexe_nm_change_status', ticket_id: currentId, status: st}).done(function(res){
      if (!res || !res.ok) return notifyError(R.i18n && R.i18n.statusError);
      loadComments(currentId);
    }).fail(function(){
      notifyError(R.i18n && R.i18n.statusError);
    });
  }
  $('#nm-work,#nm-plan,#nm-stop,#nm-res').on('click', function(){
    const st = parseInt($(this).data('status'),10);
    changeStatus(st);
  });

  // Глобальные функции для списка
  window.gexeNmOpenModal = function(id){ open(id); };
})(jQuery);

