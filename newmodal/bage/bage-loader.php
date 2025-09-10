<?php
/**
 * BAGE (cards page) – isolated loader for cards grid, conflict-free with new modal.
 * - Reuses existing template via output buffering, then strips old modal triggers.
 * - Preserves look & features; only removes classes/attributes that launch old modals.
 * - Registers/overrides shortcode so only the cleaned version is used.
 *
 * No SQL/API logic is changed here; listing stays as is. Only HTML is sanitized.
 */
if (!defined('ABSPATH')) exit;

// Handle to original template (expected location)
if (!defined('GEXE_CARDS_TEMPLATE')) {
    define('GEXE_CARDS_TEMPLATE', __DIR__ . '/../../templates/glpi-cards-template.php');
}

/**
 * Render cleaned cards page.
 * This function includes the original template into a buffer,
 * then removes old-modal trigger classes and conflicting attributes.
 */
function gexe_bage_render_cards($atts = []) {
    // Fallback if template not found — clear error to UI per project policy (no logging)
    if (!file_exists(GEXE_CARDS_TEMPLATE)) {
        return '<div class="gexe-error">Шаблон карточек не найден: ' . esc_html(GEXE_CARDS_TEMPLATE) . '</div>';
    }
    // Collect output from original template
    ob_start();
    // Make sure helper functions/vars used by the original template are available in scope.
    // We deliberately do not modify their logic.
    include GEXE_CARDS_TEMPLATE;
    $html = (string) ob_get_clean();

    if ($html === '') {
        return '<div class="gexe-error">Пустой вывод шаблона карточек.</div>';
    }

    // Strip old modal triggers: classes and data-attrs which bind legacy JS
    //  - .gexe-modal_open, .gexe-cmnt_open, .glpi-card-in-modal
    //  - data-open="comment|ticket" (legacy)
    $replacements = [
        '/\bgexe-modal_open\b/'        => '',
        '/\bgexe-cmnt_open\b/'         => '',
        '/\bglpi-card-in-modal\b/'     => '',
        '/\sdata-open="(?:comment|ticket)"/' => '',
    ];
    $clean = $html;
    foreach ($replacements as $rx => $to) {
        $clean = preg_replace($rx, $to, $clean);
    }

    // Ensure cards have a selector that new modal listens to:
    // if there's a root card element missing data-ticket-id but having data-id, duplicate it.
    // This is conservative: we don't change existing attributes, only add when helpful.
    $clean = preg_replace_callback(
        '/(<[^>]+class="[^"]*(?:\bgexe-card\b|\bticket-card\b)[^"]*"[^>]*)(>)/i',
        function ($m) {
            $tag = $m[1];
            // do not touch if data-ticket-id already present
            if (strpos($tag, 'data-ticket-id=') !== false) return $m[0];
            // try to reuse existing data-id or data-ticket
            if (preg_match('/\sdata-id="(\d+)"/i', $tag, $mm)) {
                return str_replace($m[1], $m[1] . ' data-ticket-id="' . esc_attr($mm[1]) . '"', $m[0]);
            }
            if (preg_match('/\sdata-ticket="(\d+)"/i', $tag, $mm)) {
                return str_replace($m[1], $m[1] . ' data-ticket-id="' . esc_attr($mm[1]) . '"', $m[0]);
            }
            return $m[0];
        },
        $clean
    );

    // Namespacing hook: mark container so our tiny CSS can scope fixes without touching global CSS.
    // Wrap the whole block into a scoped div if not already wrapped.
    if (strpos($clean, 'gexe-bage-scope') === false) {
        $clean = '<div class="gexe-bage-scope">' . $clean . '</div>';
    }
    return $clean;
}

/**
 * Register/override cards shortcode with conflict-free renderer.
 * We try to replace the same shortcode id the project uses for cards.
 * If the shortcode name differs in your build, update $shortcode_names accordingly.
 */
add_action('init', function () {
    if (!defined('GEXE_USE_NEWMODAL') || !GEXE_USE_NEWMODAL) {
        return; // keep legacy behavior when new modal is disabled
    }
    $shortcode_names = [
        'glpi_cards',       // common
        'gexe_glpi_cards',  // alt
    ];
    foreach ($shortcode_names as $tag) {
        if (shortcode_exists($tag)) {
            remove_shortcode($tag);
        }
        add_shortcode($tag, 'gexe_bage_render_cards');
    }

    // Дополнительно регистрируем новый явный шорткод для тестов
    // Он не конфликтует со старым и всегда выводит очищенную версию
    if (!shortcode_exists('glpi_cards_new')) {
        add_shortcode('glpi_cards_new', function($atts = []) {
            return gexe_bage_render_cards($atts);
        });
    }
});

/**
 * Enqueue tiny CSS/JS shim for cards page (namespaced; no conflicts).
 * We keep it minimal: only helpers that cannot be expressed via sanitizing HTML.
 */
add_action('wp_enqueue_scripts', function () {
    if (!defined('GEXE_USE_NEWMODAL') || !GEXE_USE_NEWMODAL) return;
    $ver = defined('GEXE_TRIGGERS_VERSION') ? GEXE_TRIGGERS_VERSION : '1.0.0';
    $base = plugin_dir_url(__FILE__);
    wp_enqueue_style('gexe-bage', $base . 'bage.css', [], $ver);
    wp_enqueue_script('gexe-bage', $base . 'bage.js', [], $ver, true);
}, 11);
