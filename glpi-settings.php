<?php
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/includes/glpi-form-data.php';

add_action('admin_menu', function () {
    add_options_page('GLPI Settings', 'GLPI', 'manage_options', 'gexe-glpi', 'gexe_glpi_settings_page');
});

add_action('admin_init', function () {
    register_setting('gexe_glpi', 'glpi_api_base');
    register_setting('gexe_glpi', 'glpi_app_token');
    register_setting('gexe_glpi', 'glpi_user_token');
    register_setting('gexe_glpi', 'glpi_solved_status');
    register_setting('gexe_glpi', 'glpi_comment_method');
    register_setting('gexe_glpi', 'glpi_comment_fallback_rest');

    add_settings_section('gexe_glpi_main', 'GLPI API', function () {
        echo '<p>Настройки подключения к GLPI REST API.</p>';
    }, 'gexe-glpi');

    add_settings_field('glpi_api_base', 'API Base URL', 'gexe_glpi_field_api_base', 'gexe-glpi', 'gexe_glpi_main');
    add_settings_field('glpi_app_token', 'Application Token', 'gexe_glpi_field_app_token', 'gexe-glpi', 'gexe_glpi_main');
    add_settings_field('glpi_user_token', 'User Token', 'gexe_glpi_field_user_token', 'gexe-glpi', 'gexe_glpi_main');
    add_settings_field('glpi_solved_status', 'Solved Status', 'gexe_glpi_field_solved_status', 'gexe-glpi', 'gexe_glpi_main');
    add_settings_field('glpi_comment_method', 'Способ создания комментариев', 'gexe_glpi_field_comment_method', 'gexe-glpi', 'gexe_glpi_main');
    add_settings_field('glpi_comment_fallback_rest', 'Разрешить фолбэк на REST', 'gexe_glpi_field_comment_fallback', 'gexe-glpi', 'gexe_glpi_main');
});

function gexe_glpi_field_api_base() {
    $v = esc_attr(get_option('glpi_api_base', ''));
    echo '<input type="text" name="glpi_api_base" value="' . $v . '" class="regular-text" />';
}
function gexe_glpi_field_app_token() {
    $v = esc_attr(get_option('glpi_app_token', ''));
    echo '<input type="text" name="glpi_app_token" value="' . $v . '" class="regular-text" />';
}
function gexe_glpi_field_user_token() {
    $v = esc_attr(get_option('glpi_user_token', ''));
    echo '<input type="text" name="glpi_user_token" value="' . $v . '" class="regular-text" />';
}
function gexe_glpi_field_solved_status() {
    $v = esc_attr(get_option('glpi_solved_status', '6'));
    echo '<input type="number" name="glpi_solved_status" value="' . $v . '" class="small-text" />';
}
function gexe_glpi_field_comment_method() {
    $v = esc_attr(get_option('glpi_comment_method', 'REST'));
    echo '<select name="glpi_comment_method">'
        . '<option value="REST"' . selected($v, 'REST', false) . '>REST</option>'
        . '<option value="SQL"' . selected($v, 'SQL', false) . '>SQL</option>'
        . '</select>';
}
function gexe_glpi_field_comment_fallback() {
    $v = get_option('glpi_comment_fallback_rest', 0);
    echo '<label><input type="checkbox" name="glpi_comment_fallback_rest" value="1" ' . checked(1, $v, false) . ' /> Включить</label>';
}

function gexe_glpi_settings_page() {
    if (!current_user_can('manage_options')) return;
    if (isset($_POST['glpi_flush_cache']) && check_admin_referer('glpi_flush_cache')) {
        gexe_glpi_flush_cache();
        echo '<div class="updated"><p>Кэш сброшен.</p></div>';
    }
    ?>
    <div class="wrap">
        <h1>GLPI Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('gexe_glpi');
            do_settings_sections('gexe-glpi');
            submit_button();
            ?>
        </form>
        <form method="post">
            <?php wp_nonce_field('glpi_flush_cache'); ?>
            <p><input type="submit" name="glpi_flush_cache" class="button" value="Сбросить кэш справочников" /></p>
        </form>
    </div>
    <?php
}
