<?php
/**
 * Plugin Name: WP GLPI Newmodal Addon
 * Description: Isolated clone (newmodal/bage): GLPI cards & modal UI, независимые ассеты и AJAX. Шорткод: [glpi_cards_new].
 * Version: 1.1.1
 * Author: obb-collab
 */

// Hard stop on direct access
if ( ! defined('ABSPATH') ) { exit; }

/**
 * Безопасные константы путей/URL. Не используем их до plugins_loaded.
 */
if ( ! defined('NM_PLUGIN_FILE') ) {
    define('NM_PLUGIN_FILE', __FILE__);
}
if ( ! defined('NM_PLUGIN_DIR') ) {
    define('NM_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if ( ! defined('NM_PLUGIN_URL') ) {
    define('NM_PLUGIN_URL', plugin_dir_url(__FILE__));
}

/**
 * Отложенная инициализация — исключаем ранние require/вызовы.
 */
add_action('plugins_loaded', function () {
    // Локализация плагина — не раньше plugins_loaded (WP 6.7+)
    load_plugin_textdomain('wp-glpi-newmodal', false, dirname(plugin_basename(__FILE__)) . '/languages/');
});

/**
 * Бутстрап модуля newmodal после init.
 */
add_action('init', function () {
    // Константы newmodal
    if ( ! defined('NM_BASE_DIR') ) {
        define('NM_BASE_DIR', trailingslashit(NM_PLUGIN_DIR . 'newmodal/'));
    }
    if ( ! defined('NM_BASE_URL') ) {
        define('NM_BASE_URL', trailingslashit(NM_PLUGIN_URL . 'newmodal/'));
    }
    if ( ! defined('NM_VER') ) {
        // Меняем версию при правках ассетов, чтобы сбрасывать кэш
        define('NM_VER', '1.1.1');
    }

    // Обязательные файлы — проверяем наличие, не падаем фатально
    $requires = [
        'glpi-db-setup.php',           // подключение к БД GLPI и маппинги
        'newmodal/config.php',         // конфиг newmodal
        'newmodal/helpers.php',        // утилиты newmodal
        'newmodal/bage/shortcode.php', // шорткоды/рендер карточек
    ];

    foreach ($requires as $rel) {
        $abs = NM_PLUGIN_DIR . $rel;
        if ( file_exists($abs) ) {
            require_once $abs;
        } else {
            // Сообщаем только админам, пользователям не ломаем фронт
            add_action('admin_notices', function () use ($rel) {
                echo '<div class="notice notice-error"><p><strong>WP GLPI Newmodal Addon:</strong> отсутствует файл <code>' .
                     esc_html($rel) . '</code>. Проверьте структуру плагина.</p></div>';
            });
            // Прерываем дальнейшую инициализацию, но без фатала
            return;
        }
    }
});

/**
 * Регистрация и подключение ассетов только на страницах с шорткодом.
 * Набор хендлов должен совпадать с тем, что ждут шаблоны newmodal/bage.
 */
add_action('wp_enqueue_scripts', function () {
    // Регистрируем стили
    wp_register_style('nm-bage',  NM_BASE_URL . 'assets/css/bage.css', [], NM_VER);
    wp_register_style('nm-modal', NM_BASE_URL . 'assets/css/modal.css', [], NM_VER);
    wp_register_style('nm-dark',  NM_BASE_URL . 'assets/css/dark.css', [], NM_VER);

    // Регистрируем скрипты
    wp_register_script('nm-utils',       NM_BASE_URL . 'assets/js/utils.js',        ['jquery'], NM_VER, true);
    wp_register_script('nm-filters',     NM_BASE_URL . 'assets/js/filters.js',      ['jquery','nm-utils'], NM_VER, true);
    wp_register_script('nm-modal-ui',    NM_BASE_URL . 'assets/js/modal-ui.js',     ['jquery','nm-utils'], NM_VER, true);
    wp_register_script('nm-bage',        NM_BASE_URL . 'assets/js/bage.js',         ['jquery','nm-filters','nm-modal-ui'], NM_VER, true);
    wp_register_script('nm-new-ticket',  NM_BASE_URL . 'assets/js/new-ticket.js',   ['jquery','nm-utils'], NM_VER, true);

    // Локализация AJAX и nonce (общая точка)
    $nonce    = wp_create_nonce('nm_ajax');
    $glpi_uid = (int) apply_filters('nm_current_glpi_user_id', 0);
    $payload  = [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => $nonce,
        'glpi_uid' => $glpi_uid,
    ];

    wp_localize_script('nm-bage',       'nmAjax', $payload);
    wp_localize_script('nm-new-ticket', 'nmAjax', $payload);
}, 20);

/**
 * Подключаем ассеты только когда на странице присутствует шорткод [glpi_cards_new].
 */
add_filter('the_posts', function ($posts) {
    if ( empty($posts) ) { return $posts; }
    foreach ($posts as $post) {
        if ( has_shortcode($post->post_content, 'glpi_cards_new') ) {
            // Стили
            wp_enqueue_style('nm-bage');
            wp_enqueue_style('nm-modal');
            wp_enqueue_style('nm-dark');
            // Скрипты
            wp_enqueue_script('nm-bage');
            wp_enqueue_script('nm-new-ticket');
            break;
        }
    }
    return $posts;
});

/**
 * AJAX-эндпоинты фронтенда (только авторизованные).
 * Обработчики реализуются в файлах newmodal/*, здесь — только регистрация.
 */
add_action('init', function () {
    if ( is_admin() ) { return; }
    if ( ! is_user_logged_in() ) { return; }

    // Список карточек, счётчики, загрузка одной карточки
    if ( function_exists('nm_ajax_get_cards') ) {
        add_action('wp_ajax_nm_get_cards',   'nm_ajax_get_cards');
    }
    if ( function_exists('nm_ajax_get_counts') ) {
        add_action('wp_ajax_nm_get_counts',  'nm_ajax_get_counts');
    }
    if ( function_exists('nm_ajax_get_card') ) {
        add_action('wp_ajax_nm_get_card',    'nm_ajax_get_card');
    }
    // Создание/принятие/закрытие заявки и добавление комментария
    if ( function_exists('nm_ajax_new_ticket') ) {
        add_action('wp_ajax_nm_new_ticket',  'nm_ajax_new_ticket');
    }
    if ( function_exists('nm_ajax_ticket_accept_sql') ) {
        add_action('wp_ajax_nm_ticket_accept_sql', 'nm_ajax_ticket_accept_sql');
    }
    if ( function_exists('nm_ajax_ticket_close_sql') ) {
        add_action('wp_ajax_nm_ticket_close_sql',  'nm_ajax_ticket_close_sql');
    }
    if ( function_exists('nm_ajax_ticket_comment_sql') ) {
        add_action('wp_ajax_nm_ticket_comment_sql','nm_ajax_ticket_comment_sql');
    }
}, 20);

/**
 * Защита: ничего не переписываем в теги <script>, просто возвращаем как есть.
 */
add_filter('script_loader_tag', static function ($tag, $handle, $src) {
    return $tag;
}, 10, 3);

