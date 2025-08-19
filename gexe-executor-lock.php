<?php
/**
 * GEXE Executor Lock — агрессивная версия
 * Полностью заменяет/усиливает фронтовый инжект: CSS + JS в footer
 *
 * Заменяй файл целиком. Выполняется в wp_footer (как и раньше).
 */

if (!defined('ABSPATH')) exit;

// Собираем токены как в оригинале (этот блок повторяет логику, которая уже была)
add_action('wp_footer', function () {
  if (!is_user_logged_in()) return;

  $u       = wp_get_current_user();
  $profile = (string) get_user_meta($u->ID, 'glpi_executor_id', true);
  $login   = (string) $u->user_login;
  $skip    = get_user_meta($u->ID, 'glpi_executor_lock_disable', true) === '1';
  if ($skip) return;

  $raw = trim($profile.' '.$login);
  if ($raw === '') return;

  $set = [];
  foreach (preg_split('~[,\s]+~', $raw) as $v){
    $v = trim($v); if ($v === '') continue;
    $low = strtolower($v);
    $set[$v] = 1; $set[$low] = 1; $set[md5($v)] = 1; $set[md5($low)] = 1;
  }
  $TOKENS = array_keys($set);
  ?>
  <style>
    /* Скрытие чужих карточек (как было) */
    .glpi-card.gexe-hide{display:none!important;}
    .gexe-hide-executors{display:none!important;}

    /* Перебиваем правое выравнивание темы и якорим меню к кнопке */
    .glpi-status-dropdown,
    .status-filter-dropdown {
      position: relative !important;
      overflow: visible !important;
    }
    .glpi-status-dropdown .glpi-filter-menu,
    .glpi-status-dropdown .status-filter-menu,
    .status-filter-dropdown .glpi-filter-menu,
    .status-filter-dropdown .status-filter-menu,
    .glpi-status-dropdown .glpi-filter-menu.is-open,
    .status-filter-dropdown .glpi-filter-menu.is-open,
    .glpi-status-dropdown .status-filter-menu.is-open,
    .status-filter-dropdown .status-filter-menu.is-open {
      position: absolute !important;
      top: 100% !important;
      left: 0 !important;           /* перебиваем right:0 из темы */
      right: auto !important;
      transform: translateX(0) !important;
      min-width: 220px !important;
      max-width: 420px !important;
      white-space: nowrap !important;
      z-index: 999999 !important;
      display: flex !important;
      flex-direction: column !important;
      background: #0f172a !important;
      border: 1px solid #334155 !important;
      border-radius: 8px !important;
      box-shadow: 0 6px 12px rgba(0,0,0,.3) !important;
      overflow-y: auto !important;
    }

    /* Подстраховка: гарантируем флекс на header контейнере
       и задаём порядок (поиск первее, статусы после) — только как запас */
    .glpi-header-row, .glpi-status-search-combo {
      display: flex !important;
      align-items: center !important;
      gap: 12px !important;
      flex-wrap: wrap !important;
    }
    .glpi-search-block { order: 1 !important; flex: 1 1 360px !important; min-width: 220px !important; }
    .glpi-status-dropdown { order: 2 !important; flex: 0 0 auto !important; margin-left: 12px !important; }

    /* Запрет горизонтального скролла страницы */
    body { overflow-x: hidden !important; }
  </style>

  <script>
  (function(){
    'use strict';

    // Токены для фильтрации карточек (как ранее)
    var TOKENS = <?php echo wp_json_encode($TOKENS); ?>.map(function(x){ return String(x||'').toLowerCase(); });

    // ---- Утилиты --------------------------------
    function q(sel, root){ return (root||document).querySelector(sel); }
    function qa(sel, root){ return Array.prototype.slice.call((root||document).querySelectorAll(sel)); }
    function hasTextNode(el, text){
      if(!el) return false;
      return (el.textContent||'').trim().toLowerCase().indexOf((text||'').toLowerCase()) !== -1;
    }

    // ---- Кламп меню в вьюпорт -------------------
    function clampMenu(menu){
      if(!menu) return;
      try {
        menu.style.right = 'auto';
        menu.style.left = '0';
        menu.style.transform = 'translateX(0px)';
        // временное раскрытие для измерения
        var cs = getComputedStyle(menu);
        var forced = false, prev = {d: menu.style.display, v: menu.style.visibility, o: menu.style.opacity};
        if (cs.display === 'none' || cs.visibility === 'hidden') {
          forced = true;
          menu.style.display = 'flex';
          menu.style.visibility = 'hidden';
          menu.style.opacity = '0';
        }
        var rect = menu.getBoundingClientRect();
        var pad = 8;
        var shift = 0;
        var overflowRight = rect.right - (window.innerWidth - pad);
        if (overflowRight > 0) shift -= overflowRight;
        var overflowLeft = pad - rect.left;
        if (overflowLeft > 0) shift += overflowLeft;
        if (shift !== 0) menu.style.transform = 'translateX(' + Math.round(shift) + 'px)';
        var spaceBelow = Math.max(140, Math.floor(window.innerHeight - rect.top - pad));
        menu.style.maxHeight = spaceBelow + 'px';
        menu.style.overflowY = 'auto';

        if (forced) {
          menu.style.display = prev.d || '';
          menu.style.visibility = prev.v || '';
          menu.style.opacity = prev.o || '';
        }
      } catch(e){ console.warn('gexe-lock clampMenu err', e); }
    }

    // ---- Поиск/Статус: обнаружение блоков ------------
    function findSearchBlock(){
      var candidates = [
        '.glpi-search-block',
        '#glpi-unified-search',
        '.glpi-search-input',
        'input[placeholder*=\"Поиск\"]'
      ];
      for(var i=0;i<candidates.length;i++){
        var el = q(candidates[i]);
        if(el){
          // если нашли input — поднимем до контейнера .glpi-search-block
          if (el.tagName && el.tagName.toLowerCase() === 'input') {
            var p = el.closest('.glpi-search-block') || el.parentElement;
            if (p) return p;
          }
          return el;
        }
      }
      return null;
    }

    function findStatusBlock(){
      var selectors = [
        '.glpi-status-dropdown',
        '.status-filter-dropdown',
        '.glpi-filter-dropdown[data-type=\"status\"]',
        '.glpi-filter-dropdown'
      ];
      for(var i=0;i<selectors.length;i++){
        var el = q(selectors[i]);
        if (el) return el;
      }
      // fallback: найти кнопку по тексту "Статус" или "Статусы"
      var all = qa('button, a, div, span');
      for(var j=0;j<all.length;j++){
        var t = (all[j].textContent||'').trim();
        if (/^Статус|Статусы|Статус$|Статусы$/i.test(t) || /\bСтатус\b/i.test(t)){
          var p = all[j].closest('.glpi-filter-dropdown') || all[j].closest('.glpi-status-dropdown') || all[j].parentElement;
          if (p) return p;
          return all[j];
        }
      }
      return null;
    }

    // ---- Гарантированная перестановка (status после search) ----
    function moveStatusAfterSearch(){
      try {
        var search = findSearchBlock();
        var status = findStatusBlock();
        if (!search || !status) return false;

        // если status уже после search в одном родителе — ok
        if (status.parentElement === search.parentElement) {
          var kids = Array.prototype.slice.call(search.parentElement.children);
          if (kids.indexOf(search) < kids.indexOf(status)) {
            return true;
          }
        }

        // целевой контейнер — предпочтительно родитель search
        var target = search.parentElement || document.querySelector('.glpi-header-row') || document.body;

        // вставляем статус непосредственно после search
        target.insertBefore(status, search.nextSibling || null);

        // inline-подстраховки
        try { search.style.order = '1'; status.style.order = '2'; status.style.marginLeft = status.style.marginLeft || '12px'; }catch(e){}
        return true;
      } catch(e){ console.error('gexe-lock move error', e); return false; }
    }

    // ---- Наблюдатель: следим за перерисовками шапки и за инлайнами меню ----
    function setupObservers(){
      // 1) MutationObserver на документ — при изменениях — пытаемся восстановить порядок
      var mo = new MutationObserver(function(m){
        moveStatusAfterSearch();
        // клампим все обнаруженные меню
        qa('.glpi-filter-menu, .status-filter-menu').forEach(function(menu){ clampMenu(menu); });
      });
      mo.observe(document.documentElement, { childList: true, subtree: true });

      // 2) наблюдатель на существующие меню — если чей-то скрипт ставит inline right/transform — сразу сбрасываем
      function watchMenus(){
        qa('.glpi-filter-menu, .status-filter-menu').forEach(function(menu){
          // простая защита: если menu уже имеет observer, skip
          if (menu.__gexe_obs) return;
          try {
            var innerMo = new MutationObserver(function(muts){
              muts.forEach(function(mut){
                if (mut.type === 'attributes' && (mut.attributeName === 'style' || mut.attributeName === 'class')){
                  // Если стиль содержит right или transform — убираем
                  try {
                    var s = menu.style;
                    if (s.right && s.right !== 'auto') s.right = 'auto';
                    if (s.left === '') s.left = '0';
                    if (s.transform && s.transform.indexOf('translate') !== -1) {
                      // оставляем transform только если мы сами его установили
                      // иначе заменим на корректированный translateX
                      clampMenu(menu);
                    }
                  } catch(e){}
                }
              });
            });
            innerMo.observe(menu, { attributes: true, attributeFilter: ['style','class'] });
            menu.__gexe_obs = innerMo;
          } catch(e){}
        });
      }

      // периодическая подстраховка: через 800ms пытаемся найти меню и прикрепить observer
      var attachInterval = setInterval(function(){
        watchMenus();
      }, 800);

      // также пересчитываем на resize/scroll
      ['resize','scroll'].forEach(function(ev){
        window.addEventListener(ev, function(){
          qa('.glpi-filter-menu, .status-filter-menu').forEach(function(menu){ clampMenu(menu); });
        }, { passive: true });
      });
    }

    // ---- Инициализация: жёсткая последовательность -----------------
    function initOnce(){
      // 1) сразу переместить, если возможно
      moveStatusAfterSearch();

      // 2) клампим меню, если уже видны
      qa('.glpi-filter-menu, .status-filter-menu').forEach(function(menu){ clampMenu(menu); });

      // 3) навесим observers
      setupObservers();

      // 4) страховка: повторная попытка через интервалы (пока приложение/фреймворк не перерисует)
      var tries = 0, maxTries = 18;
      var tId = setInterval(function(){
        tries++;
        moveStatusAfterSearch();
        qa('.glpi-filter-menu, .status-filter-menu').forEach(function(menu){ clampMenu(menu); });
        if (tries >= maxTries) clearInterval(tId);
      }, 700);
    }

    // Запускаем как можно позже
    if (document.readyState === 'complete' || document.readyState === 'interactive') {
      setTimeout(initOnce, 30);
    } else {
      document.addEventListener('DOMContentLoaded', function(){ setTimeout(initOnce, 30); });
      window.addEventListener('load', function(){ setTimeout(initOnce, 30); });
    }

    // ---- Доп. функции (оставлены исходные фичи: скрытие "Сегодня..." и подсчёт) ---
    function isMine(card){
      var ex = (card.getAttribute('data-executors')||'').toLowerCase();
      for (var i=0;i<TOKENS.length;i++){ var t=TOKENS[i]; if(t && ex.indexOf(t)!==-1) return true; }
      return false;
    }
    function lockMyCards(){
      var cards = qa('.glpi-card');
      if (!cards.length) return;
      cards.forEach(function(c){ c.classList.toggle('gexe-hide', !isMine(c)); });
      updateStatusCounts();
    }
    function updateStatusCounts(){
      var mine = qa('.glpi-card').filter(function(c){ return !c.classList.contains('gexe-hide'); });
      var total = mine.length, by = {};
      mine.forEach(function(c){
        var st = c.getAttribute('data-status') || '';
        var un = c.getAttribute('data-unassigned') === '1';
        by[st] = (by[st] || 0) + 1;
        if (un) by.unassigned = (by.unassigned || 0) + 1;
      });
      qa('.status-filter-btn').forEach(function(btn){
        var code = btn.getAttribute('data-status') || '';
        var base = btn.getAttribute('data-gexe-base') || (btn.textContent||'').trim();
        btn.setAttribute('data-gexe-base', base);
        var n = (code === 'all') ? total : (code === 'unassigned' ? (by.unassigned || 0) : (by[code] || 0));
        btn.textContent = base + ' — ' + n;
      });
    }

    // запускаем фильтрацию по умолчанию и скрытие "Сегодня..."
    setTimeout(function(){ try { lockMyCards(); } catch(e){} }, 400);
  })();
  </script>
  <?php
});
?>
