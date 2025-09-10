<?php
/**
 * Plugin Name: _FrGLPI Isolated Clone
 * Description: Интерфейс заявок GLPI для WordPress.
 * Version: 2.0.0
 * Author: obb-collab
 * Plugin URI: https://github.com/obb-collab/wp-glpi-plugin
 * GitHub Plugin URI: obb-collab/wp-glpi-plugin
 * Primary Branch: main
 * Release Asset: true
 * Update URI: https://github.com/obb-collab/wp-glpi-plugin
 *
 * This add-on loads the fully isolated front-end clone from /newmodal
 * and exposes the shortcode [glpi_cards_new]. It is intentionally
 * independent from the base plugin activation state.
 */

if (!defined('ABSPATH')) { exit; }

// -----------------------------------------------------------------------------
// Constants
// -----------------------------------------------------------------------------
if (!defined('FRGLPI_NEWMODAL_ADDON_VER')) {
    define('FRGLPI_NEWMODAL_ADDON_VER', '2.0.0');
}
// Единая версия ассетов newmodal (используется и внутри compat)
if (!defined('FRGLPI_NEWMODAL_VER')) {
    define('FRGLPI_NEWMODAL_VER', '2.0.0');
}
if (!defined('FRGLPI_NEWMODAL_DIR')) {
    define('FRGLPI_NEWMODAL_DIR', __DIR__ . '/newmodal');
}
if (!defined('FRGLPI_NEWMODAL_URL')) {
    // Точный URL каталога /newmodal (оканчивается слэшем)
    define('FRGLPI_NEWMODAL_URL', trailingslashit( plugins_url('newmodal', __FILE__) ));
}

// Prevent double bootstrap if included from elsewhere.
if (!defined('FRGLPI_NEWMODAL_BOOTSTRAPPED')) {
    define('FRGLPI_NEWMODAL_BOOTSTRAPPED', true);
}

// -----------------------------------------------------------------------------
// Activation/Deactivation (no DB writes, just sanity checks)
// -----------------------------------------------------------------------------
register_activation_hook(__FILE__, function () {
    // Sanity: ensure newmodal directory exists.
    if (!is_dir(FRGLPI_NEWMODAL_DIR)) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('Activation failed: /newmodal directory not found in plugin root.', 'frglpi'),
            esc_html__('_FrGLPI Isolated Clone', 'frglpi'),
            ['back_link' => true]
        );
    }
});

register_deactivation_hook(__FILE__, function () {
    // Nothing to clean up; add-on is stateless.
});

// -----------------------------------------------------------------------------
// Bootstrap loader
// -----------------------------------------------------------------------------
add_action('plugins_loaded', function () {
    // Load only once.
    if (!defined('FRGLPI_NEWMODAL_READY')) {
        define('FRGLPI_NEWMODAL_READY', true);
    } else {
        return;
    }

    // Minimal required files from /newmodal. We keep includes guarded and
    // fail gracefully with informative admin_notice instead of fatal.
    $required_files = [
        // Compat layer — объявляет NM_* константы/ключи (должен идти первым)
        FRGLPI_NEWMODAL_DIR . '/common/compat.php',

        // Core
        FRGLPI_NEWMODAL_DIR . '/config.php',
        FRGLPI_NEWMODAL_DIR . '/helpers.php',
        FRGLPI_NEWMODAL_DIR . '/common/api.php',
        FRGLPI_NEWMODAL_DIR . '/common/sql.php',
        FRGLPI_NEWMODAL_DIR . '/common/ping.php',

        // Bage (cards list + shortcode)
        FRGLPI_NEWMODAL_DIR . '/bage/shortcode.php',   // registers [glpi_cards_new]
        FRGLPI_NEWMODAL_DIR . '/bage/ajax.php',
        FRGLPI_NEWMODAL_DIR . '/bage/ajax-extra.php',

        // Modal (ticket actions)
        FRGLPI_NEWMODAL_DIR . '/modal/ajax.php',

        // New Ticket (creation + catalogs)
        FRGLPI_NEWMODAL_DIR . '/new-ticket/ajax.php',
        FRGLPI_NEWMODAL_DIR . '/new-ticket/catalogs.php',
    ];

    $missing = [];
    foreach ($required_files as $file) {
        if (file_exists($file)) {
            require_once $file;
        } else {
            $missing[] = $file;
        }
    }

    if (!empty($missing)) {
        // Defer notice to admin UI to avoid white screen for non-admins.
        add_action('admin_notices', function () use ($missing) {
            if (!current_user_can('activate_plugins')) {
                return;
            }
            echo '<div class="notice notice-error"><p><strong>_FrGLPI Isolated Clone:</strong> ';
            echo esc_html__('Some required files are missing. The add-on loaded partially.', 'frglpi');
            echo '</p><ul style="margin-left:1.5em;">';
            foreach ($missing as $m) {
                echo '<li><code>' . esc_html(str_replace(ABSPATH, '', $m)) . '</code></li>';
            }
            echo '</ul></div>';
        });
    }
});

// -----------------------------------------------------------------------------
// Front-end asset helper (optional)
// Note: If newmodal handles assets internally, this stays unused.
// -----------------------------------------------------------------------------
if (!function_exists('frglpi_newmodal_enqueue_assets')) {
    /**
     * Enqueue newmodal assets if needed. Kept minimal; prefer newmodal to enqueue on demand.
     */
    function frglpi_newmodal_enqueue_assets() {
        // Example (guarded): only enqueue if files exist. Leave actual enqueueing to newmodal files.
        $css = FRGLPI_NEWMODAL_DIR . '/assets/css/newmodal.css';
        $js  = FRGLPI_NEWMODAL_DIR . '/assets/js/newmodal.js';

        if (file_exists($css)) {
            wp_enqueue_style(
                'frglpi-newmodal',
                plugins_url('newmodal/assets/css/newmodal.css', __FILE__),
                [],
                FRGLPI_NEWMODAL_VER
            );
        }
        if (file_exists($js)) {
            wp_enqueue_script(
                'frglpi-newmodal',
                plugins_url('newmodal/assets/js/newmodal.js', __FILE__),
                ['jquery'],
                FRGLPI_NEWMODAL_VER,
                true
            );
        }
    }
}

// -----------------------------------------------------------------------------
// Shortcode presence detection: enqueue assets only on pages where shortcode is used
// (safe no-op if newmodal enqueues by itself).
// -----------------------------------------------------------------------------
add_action('wp', function () {
    if (is_admin()) {
        return;
    }
    if (function_exists('has_shortcode')) {
        global $post;
        if ($post && is_object($post) && isset($post->post_content)) {
            if (has_shortcode($post->post_content, 'glpi_cards_new')) {
                // Optional helper; newmodal may already enqueue what it needs.
                add_action('wp_enqueue_scripts', 'frglpi_newmodal_enqueue_assets', 20);
            }
        }
    }
});

// -----------------------------------------------------------------------------
// Admin footer info (debug-friendly, non-intrusive)
// -----------------------------------------------------------------------------
add_filter('plugin_row_meta', function ($links, $file) {
    if ($file === plugin_basename(__FILE__)) {
        $links[] = '<span>' . esc_html__('Branch:', 'frglpi') . ' <code>main</code></span>';
        $links[] = '<a href="https://github.com/obb-collab/wp-glpi-plugin" target="_blank" rel="noopener noreferrer">GitHub</a>';
    }
    return $links;
}, 10, 2);

// -----------------------------------------------------------------------------
// That’s all. The actual business logic lives under /newmodal/*
// -----------------------------------------------------------------------------

