// Chief page: executor switcher, default to chief, actions & accepted-state init
(function () {
  const lsKey = 'chief_executor_filter';
  const cfg = window.GEXE_CHIEF || {};
  const isChief = !!cfg.isChief;
  const chiefGlpiId = parseInt(cfg.chiefGlpiId || 1, 10);
  const nonce = cfg.nonce || '';
  const ajaxURL = cfg.ajaxurl || window.ajaxurl || '/wp-admin/admin-ajax.php';

  function $sel() {
    return document.querySelector('.gexe-executor-select');
  }
  function triggerChange(el) {
    if (!el) return;
    const ev = new Event('change', { bubbles: true });
    el.dispatchEvent(ev);
  }
  function currentActingAs() {
    const el = $sel();
    const v = el && el.value ? parseInt(el.value, 10) : chiefGlpiId;
    return Number.isFinite(v) && v > 0 ? v : chiefGlpiId;
  }
  function withPayload(extra) {
    const base = { acting_as: currentActingAs(), _ajax_nonce: nonce };
    return Object.assign(base, extra || {});
  }

  // 1) Default to chief on first load; remember user choice; trigger built-in filtering
  document.addEventListener('DOMContentLoaded', function () {
    try {
      const select = $sel();
      if (!select) return;
      const saved = localStorage.getItem(lsKey);
      if (!saved && isChief) {
        const opt = select.querySelector(`option[value="${chiefGlpiId}"]`);
        if (opt) {
          select.value = String(chiefGlpiId);
          localStorage.setItem(lsKey, String(chiefGlpiId));
          triggerChange(select);
        }
      } else if (saved) {
        const opt = select.querySelector(`option[value="${saved}"]`);
        if (opt) {
          select.value = String(saved);
          triggerChange(select);
        }
      }
      select.addEventListener('change', () => {
        localStorage.setItem(lsKey, String(select.value || ''));
        // Дальше сработает встроенная логика фильтрации (на change)
      });
    } catch (e) {
      console && console.warn && console.warn('chief init failed', e);
    }
  });

  // 2) Init accepted-state for all visible "accept" buttons
  function collectTicketIds() {
    const nodes = document.querySelectorAll('[data-gexe-action="accept"][data-ticket-id]');
    const ids = [];
    nodes.forEach(n => {
      const v = parseInt(n.getAttribute('data-ticket-id') || '0', 10);
      if (v) ids.push(v);
    });
    return ids;
  }
  function markAccepted(states) {
    const nodes = document.querySelectorAll('[data-gexe-action="accept"][data-ticket-id]');
    nodes.forEach(btn => {
      const tid = parseInt(btn.getAttribute('data-ticket-id') || '0', 10);
      if (!tid) return;
      if (states[tid] && states[tid].accepted) {
        btn.classList.add('is-accepted','gexe-accept-btn');
        btn.setAttribute('disabled', 'disabled');
        btn.innerText = 'Принято в работу';
      }
    });
  }
  function initAcceptedState() {
    if (!isChief) return;
    const ids = collectTicketIds();
    if (!ids.length) return;
    const data = withPayload({ ticket_ids: ids });
    if (window.jQuery) {
      window.jQuery.post(ajaxURL, Object.assign({ action: 'gexe_chief_state_bulk' }, data))
        .done(resp => { if (resp && resp.ok && resp.states) markAccepted(resp.states); });
    } else {
      fetch(ajaxURL, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
        body: new URLSearchParams(Object.assign({ action: 'gexe_chief_state_bulk' }, data))
      }).then(r=>r.json()).then(resp => { if (resp && resp.ok && resp.states) markAccepted(resp.states); });
    }
  }
  document.addEventListener('DOMContentLoaded', initAcceptedState);
  document.addEventListener('gexe:list:updated', initAcceptedState); // если список перерисовывается вашим кодом

  // 3) Delegate action buttons to chief endpoints
  document.addEventListener('click', function (e) {
    const btn = e.target.closest('[data-gexe-action]');
    if (!btn || !isChief) return;
    const act = btn.getAttribute('data-gexe-action'); // accept | update_status | assign | comment
    const tid = parseInt(btn.getAttribute('data-ticket-id') || '0', 10);
    if (!tid) return;

    const ajaxAction = {
      accept: 'gexe_chief_accept_sql',
      update_status: 'gexe_chief_update_status_sql',
      assign: 'gexe_chief_assign_sql',
      comment: 'gexe_chief_comment_sql'
    }[act];
    if (!ajaxAction) return;

    // Build payload
    let data = withPayload({ ticket_id: tid });
    if (act === 'update_status') {
      data.new_status = parseInt(btn.getAttribute('data-new-status') || '2', 10);
      data.add_accept_comment = parseInt(btn.getAttribute('data-accept-comment') || '0', 10);
    }
    if (act === 'assign') {
      data.new_assignee = currentActingAs();
    }
    if (act === 'comment') {
      const area = document.querySelector('.gexe-followup-input');
      data.comment = area ? area.value || '' : '';
    }

    // Guard against repeat
    if (btn.dataset.busy === '1') return;
    btn.dataset.busy = '1';
    btn.disabled = true;

    const onSuccess = function (resp) {
      if (!resp || !resp.ok) {
        const detail = (resp && resp.detail) || 'Unknown error';
        alert('Ошибка: ' + detail);
        return;
      }
      if (act === 'accept' || data.add_accept_comment) {
        btn.classList.add('is-accepted','gexe-accept-btn');
        btn.setAttribute('disabled', 'disabled');
        btn.innerText = 'Принято в работу';
      }
      document.dispatchEvent(new CustomEvent('gexe:chief:updated', { detail: { ticketId: tid, action: act } }));
    };

    if (window.jQuery) {
      window.jQuery.post(ajaxURL, Object.assign({ action: ajaxAction }, data))
        .done(onSuccess)
        .fail(function(){ alert('Сервер недоступен'); })
        .always(function(){ btn.dataset.busy=''; btn.disabled=false; });
    } else {
      fetch(ajaxURL, {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
        body: new URLSearchParams(Object.assign({ action: ajaxAction }, data))
      })
      .then(r=>r.json())
      .then(onSuccess)
      .catch(function(){ alert('Сервер недоступен'); })
      .finally(function(){ btn.dataset.busy=''; btn.disabled=false; });
    }
  });
})();
