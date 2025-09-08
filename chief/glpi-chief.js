(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    if (typeof glpiChief === 'undefined' || glpiChief.isManager !== 1) {
      return;
    }

    var host = document.querySelector('.glpi-top-left');
    if (!host) {
      return;
    }

    var select = document.createElement('select');
    select.className = 'chief-executors';

    var optAll = document.createElement('option');
    optAll.value = 'all';
    optAll.textContent = 'Без фильтров';
    select.appendChild(optAll);

    (glpiChief.executors || []).forEach(function (ex) {
      var opt = document.createElement('option');
      opt.value = String(ex.id);
      opt.textContent = ex.name;
      select.appendChild(opt);
    });

    if (glpiChief.viewAs) {
      var cur = String(glpiChief.viewAs);
      if (select.querySelector('option[value="' + cur + '"]')) {
        select.value = cur;
      }
    }

    select.addEventListener('change', function () {
      var val = select.value;
      var url = new URL(window.location.href);
      url.searchParams.set('view_as', val);
      select.disabled = true;
      window.location.assign(url.toString());
    });

    host.prepend(select);

    if (glpiChief.viewAs) {
      history.replaceState(null, '', location.pathname + location.hash);
    }
  });
})();

