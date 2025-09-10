<?php
// newmodal/bage/shortcode.php
if (!defined('ABSPATH')) { exit; }

function nm_shortcode_glpi_cards_new($atts = [], $content = '') {
    $statuses = nm_default_status_map();
    ob_start();
    ?>
    <div id="nm-root" class="nm-root">
      <div class="nm-toolbar">
        <div class="nm-badges">
          <?php foreach ($statuses as $k => $label): ?>
            <div class="nm-badge" data-status="<?php echo esc_attr($k); ?>">
              <span class="nm-label"><?php echo esc_html($label); ?></span>
              <span class="nm-count">0</span>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="nm-search">
          <input id="nm-search" type="text" placeholder="<?php esc_attr_e('Search tickets...', 'nm'); ?>">
          <button id="nm-new-ticket" class="nm-btn"><?php esc_html_e('New ticket', 'nm'); ?></button>
        </div>
      </div>
      <div id="nm-cards" class="nm-cards"></div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('glpi_cards_new', 'nm_shortcode_glpi_cards_new');
