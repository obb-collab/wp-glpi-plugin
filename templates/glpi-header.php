<?php
if (!defined('ABSPATH')) exit;
?>
<div class="glpi-header-row">
  <?php if ($is_logged_in && $gexe_greeting !== ''): ?>
    <div class="gexe-greeting" aria-live="polite"><?php echo esc_html($gexe_greeting); ?></div>
  <?php endif; ?>

  <div class="glpi-top-row">
    <div class="glpi-top-left">
      <div class="glpi-category-block">
        <button type="button" class="glpi-cat-toggle" aria-expanded="false" aria-controls="glpi-categories-inline">Категории</button>
      </div>
      <button type="button" class="glpi-newtask-btn"><i class="fa-regular fa-file-lines"></i> Новая заявка</button>
    </div>

    <div class="glpi-search-block">
      <div class="glpi-search-wrap is-empty">
        <input type="text" id="glpi-unified-search" class="glpi-search-input" placeholder="Поиск...">
        <button type="button" class="glpi-search-clear" aria-label="Очистить поиск">&times;</button>
      </div>
    </div>
  </div>

  <div class="glpi-status-row">
    <div class="glpi-status-blocks">
      <div class="glpi-status-block status-filter-btn" data-status="all" data-label="Все задачи">
        <span class="status-count"><?php echo intval($total_count); ?></span>
        <span class="status-label">Все задачи</span>
      </div>
      <div class="glpi-status-block status-filter-btn active" data-status="2" data-label="В работе">
        <span class="status-count"><?php echo intval($status_counts[2] ?? 0); ?></span>
        <span class="status-label">В работе</span>
      </div>
      <div class="glpi-status-block status-filter-btn" data-status="3" data-label="В плане">
        <span class="status-count"><?php echo intval($status_counts[3] ?? 0); ?></span>
        <span class="status-label">В плане</span>
      </div>
      <div class="glpi-status-block status-filter-btn" data-status="4" data-label="В стопе">
        <span class="status-count"><?php echo intval($status_counts[4] ?? 0); ?></span>
        <span class="status-label">В стопе</span>
      </div>
      <div class="glpi-status-block status-filter-btn" data-status="1" data-label="Новые">
        <span class="status-count"><?php echo intval($status_counts[1] ?? 0); ?></span>
        <span class="status-label">Новые</span>
      </div>
    </div>
  </div>
</div>

