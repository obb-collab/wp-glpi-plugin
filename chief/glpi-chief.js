document.addEventListener('DOMContentLoaded', function () {
  if (typeof glpiChief === 'undefined') return;
  if (parseInt(glpiChief.isManager, 10) !== 1) return;
  var host = document.querySelector('.glpi-top-left');
  if (!host) return;
  var select = document.createElement('select');
  select.className = 'chief-executors';
  var optAll = document.createElement('option');
  optAll.value = 'all';
  optAll.textContent = 'Без фильтров';
  select.appendChild(optAll);
  if (Array.isArray(glpiChief.executors)) {
    glpiChief.executors.forEach(function (ex) {
      var opt = document.createElement('option');
      opt.value = ex.id;
      opt.textContent = ex.name;
      select.appendChild(opt);
    });
  }
  if (glpiChief.viewAs) {
    var val = String(glpiChief.viewAs);
    Array.from(select.options).forEach(function (o) {
      if (o.value === val) select.value = val;
    });
  }
  select.addEventListener('change', function () {
    var val = select.value;
    select.disabled = true;
    var url = new URL(window.location.href);
    url.searchParams.set('view_as', val);
    window.location.href = url.toString();
  });
  host.appendChild(select);
  var url2 = new URL(window.location.href);
  if (url2.searchParams.has('view_as')) {
    history.replaceState(null, '', location.pathname + location.hash);
  }
});
