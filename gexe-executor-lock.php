<?php
/**
 * GEXE Executor Lock — агрессивная версия (исправленная)
 * Полностью заменяет/усиливает фронтовый инжект: CSS + JS в footer
 *
 * Исправления:
 * - Ликвидированы бесконечные мутации/рекурсия при правке inline-style в наблюдателе.
 * - Добавлены флаги защиты от reentry (menu.__gexe_updating).
 * - Дебаунс/троттлинг для expensive-операций (clampMenu / moveStatusAfterSearch).
 * - Ограничен период attachInterval (остановится после N попыток или когда все меню обработаны).
 * - Добавлены консольные логи для отладки TOKENS (можно убрать).
 *
 * ДОПОЛНЕНО:
 * - Поддержка авторской фильтрации: в профиле WP в мета-ключе 'glpi_user_id' хранится
 *   числовой ID автора в GLPI (из glpi_users).
 *   На фронте фильтрация идёт по data-author карточки.
 */

if (!defined('ABSPATH')) exit;

/* --- Подключаем внешний CSS (перенёс стили в gee.css) --- */
add_action('wp_enqueue_scripts', function() {
  wp_enqueue_style('gexe-gee', plugin_dir_url(__FILE__) . 'assets/css/gee.css', [], null);
});

// Встраиваем токены и служебный JS в footer
add_action('wp_footer', function () {
  if (!is_user_logged_in()) return;

  $u        = wp_get_current_user();

  $show_all = get_user_meta($u->ID, 'glpi_show_all_cards', true) === '1';
  $skip     = ($show_all || get_user_meta($u->ID, 'glpi_executor_lock_disable', true) === '1');
  if ($skip) return;

  // Ключ пользователя GLPI: мета-ключ 'glpi_user_id' (только числовой users.id)
  $raw_key = trim((string) get_user_meta($u->ID, 'glpi_user_id', true));

  if ($raw_key === '' || !ctype_digit($raw_key)) {
    return; // не удалось распознать ключ
  }

  $TOKENS = [ (string) $raw_key ];
  ?>
  <script>
  (function(){
    'use strict';

    if (window.__gexe_executor_lock_initialized) return;
    window.__gexe_executor_lock_initialized = true;

    // Авторские токены (числовые ID из GLPI)
    var TOKENS = <?php echo wp_json_encode($TOKENS); ?>.map(function(x){ return String(x||'').toLowerCase(); });

    try { if (window.console && window.console.info) console.info('[gexe] author tokens:', TOKENS); } catch(e){}

    try { window.__gexe_executor_lock_mode = window.__gexe_executor_lock_mode || 'inline-from-php'; } catch(e){}
    try { window.__gexe_tokens = TOKENS.slice(0); } catch(e){}

    // === GEXE: строгая блокировка карточек по сопоставлению WP↔GLPI ===
    function gexeMatchCard(card) {
      try {
        var ids = (card.getAttribute('data-assignees') || '').split(',').map(function(s){ return String(s||'').trim(); });
        return TOKENS.some(function(t){ return ids.indexOf(String(t)) !== -1; });
      } catch(e) {}
      return true;
    }
    function gexeApplyLock(){
      var cards = Array.prototype.slice.call(document.querySelectorAll('.glpi-card'));
      if (!cards.length) return;
      cards.forEach(function(card){
        var ok = gexeMatchCard(card);
        if (!ok) { card.classList.add('gexe-hide'); card.style.display = 'none'; }
        else { card.classList.remove('gexe-hide'); card.style.display = ''; }
      });
    }
    document.addEventListener('DOMContentLoaded', function(){ setTimeout(gexeApplyLock, 30); });
    document.addEventListener('gexe:refilter', function(){ setTimeout(gexeApplyLock, 0); });

    try {
      window.__gexe_trigger_lock = function() {
        try {
          document.dispatchEvent(new CustomEvent('gexe:tokens:ready', { detail: { tokens: window.__gexe_tokens } }));
        } catch (e) {}
      };
      try { document.dispatchEvent(new CustomEvent('gexe:tokens:ready', { detail: { tokens: window.__gexe_tokens } })); } catch (e) {}
      try { window.__gexe_trigger_lock(); } catch (e) {}
    } catch(e){}

    // Ниже — утилитарные функции позиционирования меню и выравнивания хедера
    function q(sel, root){ return (root||document).querySelector(sel); }
    function qa(sel, root){ return Array.prototype.slice.call((root||document).querySelectorAll(sel || '')); }
    function debounce(fn, wait){ var t=null; return function(){ var c=this,a=arguments; clearTimeout(t); t=setTimeout(function(){ fn.apply(c,a); }, wait); }; }

    function clampMenu(menu){
      if (!menu) return;
      var w = menu.offsetWidth;
      var maxW = Math.max(160, Math.min(320, Math.floor(window.innerWidth * 0.4)));
      if (w > maxW) menu.style.width = maxW + 'px';
    }

    function moveStatusAfterSearch(){
      var row = q('.glpi-header-row');
      if (!row) return;
      var dropdowns = qa('.glpi-filter-dropdown', row);
      if (dropdowns.length < 2) return;
      var searchBlock = q('.glpi-search-block', row);
      if (!searchBlock) return;
      var status = dropdowns[1];
      if (status && status.nextSibling !== searchBlock.nextSibling) {
        row.insertBefore(status, searchBlock.nextSibling);
      }
    }

    function alignHeader(){
      var row = q('.glpi-header-row');
      if (!row) return;
      row.style.display = 'flex';
      row.style.flexWrap = 'wrap';
      row.style.gap = '8px';
      row.style.alignItems = 'center';
    }

    function initOnce(){
      try {
        alignHeader();
        var menus = qa('.glpi-filter-menu');
        menus.forEach(function(menu){
          if (menu.__gexe_updating) return;
          menu.__gexe_updating = true;
          clampMenu(menu);
          menu.__gexe_updating = false;
        });
        moveStatusAfterSearch();

        var tries = 0;
        var maxTries = 20;
        var tId = setInterval(function(){
          tries++;
          var menus2 = qa('.glpi-filter-menu');
          menus2.forEach(function(menu){
            if (menu.__gexe_updating) return;
            menu.__gexe_updating = true;
            clampMenu(menu);
            menu.__gexe_updating = false;
          });
          moveStatusAfterSearch();
          if (tries >= maxTries) clearInterval(tId);
        }, 700);
      } catch(e){}
    }

    if (document.readyState === 'complete' || document.readyState === 'interactive') {
      setTimeout(initOnce, 30);
    } else {
      document.addEventListener('DOMContentLoaded', function(){ setTimeout(initOnce, 30); });
      window.addEventListener('load', function(){ setTimeout(initOnce, 30); });
    }
  })();
  </script>
  <?php
});
