<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_options_page('GLPI Settings', 'GLPI', 'manage_options', 'gexe-glpi', 'gexe_glpi_settings_page');
});

add_action('admin_init', function () {
    register_setting('gexe_glpi', 'glpi_api_base');
    register_setting('gexe_glpi', 'glpi_app_token');
    register_setting('gexe_glpi', 'glpi_user_token');
    register_setting('gexe_glpi', 'glpi_solved_status');

    add_settings_section('gexe_glpi_main', 'GLPI API', function () {
        echo '<p>Настройки подключения к GLPI REST API.</p>';
    }, 'gexe-glpi');

    add_settings_field('glpi_api_base', 'API Base URL', 'gexe_glpi_field_api_base', 'gexe-glpi', 'gexe_glpi_main');
    add_settings_field('glpi_app_token', 'Application Token', 'gexe_glpi_field_app_token', 'gexe-glpi', 'gexe_glpi_main');
    add_settings_field('glpi_user_token', 'User Token', 'gexe_glpi_field_user_token', 'gexe-glpi', 'gexe_glpi_main');
    add_settings_field('glpi_solved_status', 'Solved Status', 'gexe_glpi_field_solved_status', 'gexe-glpi', 'gexe_glpi_main');
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

function gexe_glpi_settings_page() {
    if (!current_user_can('manage_options')) return;
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
    </div>
    <?php
}
