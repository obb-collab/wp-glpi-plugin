(function($){
  'use strict';
  const R = window.gexeNm || {};

  let $wrap;

  function ajax(payload){
    return $.ajax({
      url: R.ajaxUrl,
      method: 'POST',
      data: Object.assign({_ajax_nonce: R.nonce}, payload),
      dataType: 'json'
    });
  }
  function notifyError(msg){ alert(msg || 'Ошибка'); }

  function loadCatalogs(){
    return $.ajax({
      url: R.ajaxUrl,
      method: 'POST',
      data: {_ajax_nonce: R.nonce, action:'gexe_nm_catalogs'},
      dataType: 'json'
    });
  }

  function render(){
    $wrap = $('<div class="nm-new"></div>');
    $wrap.append(`
      <div class="nm-new__row">
        <div class="nm-new__col">
          <label class="nm-label">Тема</label>
          <input type="text" id="nm-n-name" class="nm-input" />
        </div>
        <div class="nm-new__col">
          <label class="nm-label">Срок (до)</label>
          <input type="datetime-local" id="nm-n-due" class="nm-input" />
        </div>
      </div>
      <div class="nm-new__row">
        <div class="nm-new__col">
          <label class="nm-label">Категория</label>
          <select id="nm-n-cat" class="nm-select"></select>
        </div>
        <div class="nm-new__col">
          <label class="nm-label">Местоположение</label>
          <select id="nm-n-loc" class="nm-select"></select>
        </div>
        <div class="nm-new__col">
          <label class="nm-label">Исполнитель</label>
          <input type="number" id="nm-n-assignee" class="nm-input" placeholder="GLPI user id"/>
        </div>
      </div>
      <div class="nm-new__row">
        <div class="nm-new__col" style="flex:1 1 100%">
          <label class="nm-label">Описание</label>
          <textarea id="nm-n-content" class="nm-textarea"></textarea>
        </div>
      </div>
      <div class="nm-actions">
        <button class="nm-btn nm-btn--primary" id="nm-n-submit">Создать</button>
        <button class="nm-btn" id="nm-n-cancel">Отмена</button>
      </div>
      <div class="nm-error" id="nm-n-error" hidden></div>
    `);
    return $wrap;
  }

  function showError(message){
    const $err = $('#nm-n-error');
    $err.text(message || '');
    $err.prop('hidden', !message);
  }

  function open(){
    const $modalRoot = $('#nm-modal-root');
    $modalRoot.empty().append(render());
    $modalRoot.parent('.nm-modal').show();
    bind();
    fillCatalogs();
  }

  function close(){
    const $modalRoot = $('#nm-modal-root');
    $modalRoot.empty();
    $modalRoot.parent('.nm-modal').hide();
  }

  function bind(){
    $('#nm-n-cancel').on('click', function(e){ e.preventDefault(); close(); });
    $('#nm-n-submit').on('click', function(e){
      e.preventDefault();
      submit();
    });
  }

  function fillCatalogs(){
    loadCatalogs().done(function(res){
      if (!res || !res.ok) return notifyError('Ошибка загрузки справочников');
      const $cat = $('#nm-n-cat'), $loc = $('#nm-n-loc');
      $cat.empty(); $loc.empty();
      (res.categories || []).forEach(function(c){
        $('<option>').val(c.id).text(c.name).appendTo($cat);
      });
      (res.locations || []).forEach(function(l){
        $('<option>').val(l.id).text(l.name).appendTo($loc);
      });
    }).fail(function(){ notifyError('Ошибка загрузки справочников'); });
  }

  function submit(){
    showError('');
    const payload = {
      action: 'gexe_nm_new_ticket',
      name: $('#nm-n-name').val(),
      content: $('#nm-n-content').val(),
      category: $('#nm-n-cat').val(),
      location: $('#nm-n-loc').val(),
      due: $('#nm-n-due').val(),
      assignee: $('#nm-n-assignee').val()
    };
    ajax(payload).done(function(res){
      if (!res || !res.ok) {
        return showError(res && res.message ? res.message : 'Ошибка создания');
      }
      close();
      alert('Заявка #' + res.ticket_id + ' создана');
    }).fail(function(){
      showError('Ошибка создания');
    });
  }

  // Глобалка, чтобы вызывать из списка
  window.gexeNmOpenNewTicket = function(){ open(); };
})(jQuery);

