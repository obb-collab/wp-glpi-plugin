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
// Единая версия ассетов newmodal (используется в enqueue и внутри compat)
if (!defined('FRGLPI_NEWMODAL_VER')) {
    define('FRGLPI_NEWMODAL_VER', '2.0.0');
}
if (!defined('FRGLPI_NEWMODAL_DIR')) {
    define('FRGLPI_NEWMODAL_DIR', __DIR__ . '/newmodal');
}
if (!defined('FRGLPI_NEWMODAL_URL')) {
    // Точный базовый URL каталога /newmodal (с завершающим слэшем)
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

// Пробный пинг соединения к GLPI БД (ранний, только для админов)
add_action('init', function () {
    if (!current_user_can('manage_options')) {
        return;
    }
    if (function_exists('nm_glpi_db')) {
        $dbi = nm_glpi_db();
        if (is_wp_error($dbi)) {
            // Сообщение выводится через admin_notices в sql.php
        }
    }
}, 1);

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------
if (!function_exists('frglpi_nm_has_merge_conflict')) {
    /**
     * Быстрая проверка файла на маркеры незавершённого git-мержа.
     * Возвращает true, если внутри есть <<<<<<<, =======, >>>>>>>.
     */
    function frglpi_nm_has_merge_conflict($path) {
        $c = @file_get_contents($path);
        if ($c === false) {
            return false;
        }
        return (strpos($c, '<<<<<<<') !== false)
            || (strpos($c, '=======') !== false)
            || (strpos($c, '>>>>>>>') !== false);
    }
}

if (!function_exists('frglpi_nm_relpath')) {
    function frglpi_nm_relpath($abs) {
        return str_replace(ABSPATH, '', $abs);
    }
}

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
        // Compat layer — объявляет NM_* константы/ключи (ДОЛЖЕН идти первым)
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

    $missing   = [];
    $conflicts = [];
    foreach ($required_files as $file) {
        if (!file_exists($file)) {
            $missing[] = $file;
            continue;
        }
        // Не подключаем файлы с маркерами конфликта, чтобы не положить сайт
        if (frglpi_nm_has_merge_conflict($file)) {
            $conflicts[] = $file;
            continue;
        }
        require_once $file;
    }

    if (!empty($missing) || !empty($conflicts)) {
        // Defer notice to admin UI to avoid white screen for non-admins.
        add_action('admin_notices', function () use ($missing, $conflicts) {
            if (!current_user_can('activate_plugins')) {
                return;
            }
            echo '<div class="notice notice-error"><p><strong>_FrGLPI Isolated Clone:</strong> ';
            if (!empty($missing)) {
                echo esc_html__('Some required files are missing. The add-on loaded partially.', 'frglpi');
                echo '</p><ul style="margin-left:1.5em">';
                foreach ($missing as $m) {
                    echo '<li><code>' . esc_html(frglpi_nm_relpath($m)) . '</code></li>';
                }
                echo '</ul><p>';
            }
            if (!empty($conflicts)) {
                echo esc_html__('Some files contain unresolved merge conflicts and were skipped:', 'frglpi');
                echo '</p><ul style="margin-left:1.5em">';
                foreach ($conflicts as $c) {
                    echo '<li><code>' . esc_html(frglpi_nm_relpath($c)) . '</code> ' .
                         esc_html__('(fix <<<<<<< / ======= / >>>>>>> and try again)', 'frglpi') . '</li>';
                }
                echo '</ul><p>';
            }
            echo '</p></div>';
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
