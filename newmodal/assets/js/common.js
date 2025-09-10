/* newmodal/assets/js/common.js */
(function($){
  'use strict';

  function uuidv4(){
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c){
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

  function closeAllModals(){
    $('#nm-overlay').hide();
    $('#nm-modal-root').hide().empty();
    $('#nm-new-ticket-root').hide().empty();
  }

  window.NM_API = { uuidv4, apiGet, apiPost, closeAllModals };
})(jQuery);
