<?php
// newmodal/bage/shortcode.php
if (!defined('ABSPATH')) { exit; }

/**
 * Регистрация ассетов и локализация AJAX-параметров для фронтенда.
 */
if (!function_exists('nm_bage_register_assets')){
    function nm_bage_register_assets(){
        $base = defined('NM_BASE_URL') ? NM_BASE_URL : (defined('FRGLPI_NEWMODAL_URL') ? FRGLPI_NEWMODAL_URL : plugin_dir_url(__FILE__));
        $ver  = defined('NM_VER') ? NM_VER : '2.0.0';

        // CSS
        wp_register_style('nm-bage',        $base.'assets/css/bage.css',        [], $ver);
        wp_register_style('nm-modal',       $base.'assets/css/modal.css',       [], $ver);
        wp_register_style('nm-modal-extra', $base.'assets/css/modal-extra.css', [], $ver);
        wp_register_style('nm-newticket',   $base.'assets/css/newticket.css',   [], $ver);

        // JS
        wp_register_script('nm-common',   $base.'assets/js/common.js',   [], $ver, true);
        wp_register_script('nm-bage',     $base.'assets/js/bage.js',     [], $ver, true);
        wp_register_script('nm-modal',    $base.'assets/js/modal.js',    [], $ver, true);
        wp_register_script('nm-newticket',$base.'assets/js/newticket.js',[], $ver, true);

        // nmAjax для всех модулей, которые делают POST к admin-ajax.php
        $loc = [
            'url'   => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('nm_ajax'),
        ];
        wp_localize_script('nm-bage',      'nmAjax', $loc);
        wp_localize_script('nm-modal',     'nmAjax', $loc);
        wp_localize_script('nm-newticket', 'nmAjax', $loc);
    }
    add_action('wp_enqueue_scripts', 'nm_bage_register_assets', 5);
}

if (!function_exists('nm_bage_enqueue_assets')){
    function nm_bage_enqueue_assets(){
        wp_enqueue_style('nm-bage');
        wp_enqueue_style('nm-modal');
        wp_enqueue_style('nm-modal-extra');
        wp_enqueue_style('nm-newticket');

        wp_enqueue_script('nm-common');
        wp_enqueue_script('nm-bage');
        wp_enqueue_script('nm-modal');
        wp_enqueue_script('nm-newticket');
    }
}

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
    // Гарантируем подключение ассетов при наличии шорткода
    if (function_exists('nm_bage_enqueue_assets')) nm_bage_enqueue_assets();

    return ob_get_clean();
}
add_shortcode('glpi_cards_new', 'nm_shortcode_glpi_cards_new');
