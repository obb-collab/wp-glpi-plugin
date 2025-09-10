<?php if (!defined('ABSPATH')) exit; ?>
<div class="gexe-bage">
  <div class="gexe-bage__container">
    <div class="gexe-bage__toolbar">
    <button class="gexe-bage__btn" id="gexe-bage-cat">Категории</button>
    <a class="gexe-bage__btn gexe-bage__btn--primary" href="#" id="gexe-bage-new">Новая заявка</a>
    <input type="search" class="gexe-bage__search" id="gexe-bage-search" placeholder="Поиск..." />
  </div>
    <div class="gexe-bage__filters" id="gexe-bage-status">
    <button class="gexe-bage__pill is-active" data-status="0"><span class="gexe-bage__num" id="c0">0</span><span>Все задачи</span></button>
    <button class="gexe-bage__pill" data-status="2"><span class="gexe-bage__num" id="c2">0</span><span>В работе</span></button>
    <button class="gexe-bage__pill" data-status="3"><span class="gexe-bage__num" id="c3">0</span><span>В плане</span></button>
    <button class="gexe-bage__pill" data-status="4"><span class="gexe-bage__num" id="c4">0</span><span>В стопе</span></button>
    <button class="gexe-bage__pill" data-status="1"><span class="gexe-bage__num" id="c1">0</span><span>Новые</span></button>
  </div>
    <div class="gexe-bage__grid" id="gexe-bage-grid">
    <!-- карты подгружаются AJAX-ом -->
  </div>
    <div class="gexe-bage__pager" id="gexe-bage-pager">
      <button class="gexe-bage__btn" id="gexe-bage-prev" disabled>Назад</button>
      <span id="gexe-bage-page">1</span>
      <button class="gexe-bage__btn" id="gexe-bage-next" disabled>Далее</button>
    </div>
    <div class="gexe-bage__error" id="gexe-bage-err" hidden></div>
  </div>
</div>

