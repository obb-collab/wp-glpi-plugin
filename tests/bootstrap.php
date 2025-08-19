<?php
// Minimal stubs for WordPress functions used in plugin
if (!function_exists('add_action')) { function add_action(...$args) {} }
if (!function_exists('wp_enqueue_script')) { function wp_enqueue_script(...$args) {} }
if (!function_exists('wp_enqueue_style')) { function wp_enqueue_style(...$args) {} }
if (!function_exists('wp_register_style')) { function wp_register_style(...$args) {} }
if (!function_exists('wp_register_script')) { function wp_register_script(...$args) {} }
if (!function_exists('wp_localize_script')) { function wp_localize_script(...$args) {} }
if (!function_exists('plugin_dir_url')) { function plugin_dir_url() { return ''; } }
if (!function_exists('admin_url')) { function admin_url() { return ''; } }
if (!function_exists('wp_create_nonce')) { function wp_create_nonce() { return ''; } }
if (!function_exists('check_ajax_referer')) { function check_ajax_referer(...$args) {} }
if (!function_exists('wp_die')) { function wp_die($msg='') { echo $msg; } }
if (!function_exists('current_time')) { function current_time($t) { return '2020-01-01 00:00:00'; } }
if (!function_exists('esc_html')) { function esc_html($s) { return $s; } }
if (!function_exists('wp_kses_post')) { function wp_kses_post($s) { return $s; } }
if (!function_exists('sanitize_key')) { function sanitize_key($k) { return $k; } }
if (!function_exists('is_user_logged_in')) { function is_user_logged_in() { return $GLOBALS['wp_test_is_user_logged_in'] ?? false; } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return $GLOBALS['wp_test_current_user_id'] ?? 0; } }
if (!function_exists('get_user_meta')) {
    function get_user_meta($user_id, $key, $single = true) {
        return $GLOBALS['wp_test_user_meta'][$user_id][$key] ?? '';
    }
}

class FakeWpdb {
    public $prefix = 'wp_';
    public $can_update = false;
    public $is_assigned = false;
    public function prepare($query, ...$args) {
        return vsprintf($query, $args);
    }
    public function get_var($query) {
        if (strpos($query, 'glpi_profiles_users') !== false) {
            return $this->can_update ? 1 : null;
        }
        if (strpos($query, 'glpi_tickets_users') !== false) {
            return $this->is_assigned ? 1 : null;
        }
        return null;
    }
}

require __DIR__ . '/../glpi-modal-actions.php';
