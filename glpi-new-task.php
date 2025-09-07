<?php
/**
 * AJAX endpoints for the "New ticket" modal.
 *
 * Provides separate endpoints for loading dictionaries and creating a ticket.
 * All responses use JSON (HTTP 200).
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/glpi-db-setup.php';
require_once __DIR__ . '/inc/user-map.php';

add_action('wp_enqueue_scripts', function () {
    wp_register_style('glpi-new-task', plugin_dir_url(__FILE__) . 'glpi-new-task.css', [], '1.0.0');
    wp_enqueue_style('glpi-new-task');

    wp_register_script('glpi-new-task-js', plugin_dir_url(__FILE__) . 'glpi-new-task.js', [], '1.0.0', true);
    wp_enqueue_script('glpi-new-task-js');

    $data = [
        'url'          => admin_url('admin-ajax.php'),
        'nonce'        => wp_create_nonce('gexe_actions'),
        'user_glpi_id' => (int) gexe_get_current_glpi_uid(),
        'assignees'    => function_exists('gexe_get_assignee_options') ? gexe_get_assignee_options() : [],
    ];
    wp_localize_script('glpi-new-task-js', 'glpiAjax', $data);
});

/** Verify AJAX nonce. */
function glpi_nt_verify_nonce() {
    if (!check_ajax_referer('gexe_actions', 'nonce', false)) {
        wp_send_json(['ok' => false, 'code' => 'csrf']);
    }
}

// -------- Dictionaries --------
add_action('wp_ajax_glpi_get_categories', 'glpi_ajax_get_categories');
function glpi_ajax_get_categories() {
    glpi_nt_verify_nonce();
    if (!is_user_logged_in()) {
        wp_send_json(['ok' => false, 'error' => 'not_logged_in']);
    }
    $map = gexe_require_glpi_user(get_current_user_id());
    if (!$map['ok']) {
        wp_send_json(['ok' => false, 'error' => 'not_mapped']);
    }
    global $glpi_db;
    $entity_id = (int)$glpi_db->get_var($glpi_db->prepare(
        'SELECT entities_id FROM glpi_users WHERE id=%d AND is_deleted=0',
        $map['id']
    ));
    if ($glpi_db->last_error) {
        error_log('[wp-glpi new-task catalogs] categories SQL error: ' . $glpi_db->last_error);
        wp_send_json(['ok' => false, 'error' => 'sql', 'which' => 'categories', 'sql' => $glpi_db->last_error]);
    }
    if ($entity_id <= 0) {
        wp_send_json(['ok' => false, 'error' => 'entity_access']);
    }

    $res = glpi_db_get_categories($entity_id);
    if (!$res['ok']) {
        if ($res['code'] === 'dict_failed') {
            $err = [
                'ok'   => false,
                'error'=> 'sql',
                'which'=> 'categories'
            ];
            if ($glpi_db->last_error) {
                $err['sql'] = $glpi_db->last_error;
                error_log('[wp-glpi new-task catalogs] categories SQL error: ' . $glpi_db->last_error);
            }
            wp_send_json($err);
        }
        if ($res['code'] === 'dict_empty') {
            wp_send_json(['ok' => false, 'error' => 'empty', 'which' => 'categories']);
        }
    }
    wp_send_json(['ok' => true, 'list' => $res['list']]);
}

add_action('wp_ajax_glpi_get_locations', 'glpi_ajax_get_locations');
function glpi_ajax_get_locations() {
    glpi_nt_verify_nonce();
    if (!is_user_logged_in()) {
        wp_send_json(['ok' => false, 'error' => 'not_logged_in']);
    }
    $map = gexe_require_glpi_user(get_current_user_id());
    if (!$map['ok']) {
        wp_send_json(['ok' => false, 'error' => 'not_mapped']);
    }
    global $glpi_db;
    $res = glpi_db_get_locations();
    if (!$res['ok']) {
        if ($res['code'] === 'dict_failed') {
            $err = [
                'ok'   => false,
                'error'=> 'sql',
                'which'=> 'locations'
            ];
            if ($glpi_db->last_error) {
                $err['sql'] = $glpi_db->last_error;
                error_log('[wp-glpi new-task catalogs] locations SQL error: ' . $glpi_db->last_error);
            }
            wp_send_json($err);
        }
        if ($res['code'] === 'dict_empty') {
            wp_send_json(['ok' => false, 'error' => 'empty', 'which' => 'locations']);
        }
    }
    wp_send_json(['ok' => true, 'list' => $res['list']]);
}

add_action('wp_ajax_glpi_get_executors', 'glpi_ajax_get_executors');
function glpi_ajax_get_executors() {
    glpi_nt_verify_nonce();
    if (!is_user_logged_in()) {
        wp_send_json(['ok' => false, 'error' => 'not_logged_in']);
    }
    $map = gexe_require_glpi_user(get_current_user_id());
    if (!$map['ok']) {
        wp_send_json(['ok' => false, 'error' => 'not_mapped']);
    }
    global $glpi_db;
    $res = glpi_db_get_executors();
    if (!$res['ok']) {
        if ($res['code'] === 'dict_failed') {
            $err = [
                'ok'   => false,
                'error'=> 'sql',
                'which'=> 'executors'
            ];
            if ($glpi_db->last_error) {
                $err['sql'] = $glpi_db->last_error;
                error_log('[wp-glpi new-task catalogs] executors SQL error: ' . $glpi_db->last_error);
            }
            wp_send_json($err);
        }
        if ($res['code'] === 'dict_empty') {
            wp_send_json(['ok' => false, 'error' => 'empty', 'which' => 'executors']);
        }
    }
    wp_send_json(['ok' => true, 'list' => $res['list']]);
}

/**
 * Fetch list of available executors.
 *
 * @return array{ok:bool,code?:string,which?:string,list?:array<int,array{id:int,name:string}>}
 */
function glpi_db_get_executors() {
    global $glpi_db;

    $rows = $glpi_db->get_results(
        "SELECT id, name FROM glpi_users WHERE is_deleted=0 ORDER BY name",
        ARRAY_A
    );
    if ($glpi_db->last_error) {
        return ['ok' => false, 'code' => 'dict_failed', 'which' => 'executors'];
    }
    if (!$rows) {
        return ['ok' => false, 'code' => 'dict_empty', 'which' => 'executors'];
    }

    $list = array_map(function ($r) {
        return [
            'id'           => (int) ($r['id'] ?? 0),
            'label'        => $r['name'] ?? '',
            'glpi_user_id' => (int) ($r['id'] ?? 0),
        ];
    }, $rows);

    return ['ok' => true, 'code' => 'ok', 'list' => $list];
}

// -------- Create ticket --------
add_action('wp_ajax_glpi_create_ticket', 'glpi_ajax_create_ticket');
function glpi_ajax_create_ticket() {
    glpi_nt_verify_nonce();
    if (!is_user_logged_in()) {
        wp_send_json(['ok' => false, 'code' => 'not_logged_in']);
    }
    $map = gexe_require_glpi_user(get_current_user_id());
    if (!$map['ok']) {
        wp_send_json(['ok' => false, 'code' => $map['code']]);
    }

    $name = sanitize_text_field($_POST['name'] ?? '');
    $desc = sanitize_textarea_field($_POST['description'] ?? '');
    $cat  = (int) ($_POST['category_id'] ?? 0);
    $loc  = (int) ($_POST['location_id'] ?? 0);
    $assign_me = !empty($_POST['assign_me']);
    $exec = (int) ($_POST['executor_id'] ?? 0);

    $author = (int) $map['id'];
    $can_assign = ($author === 2);
    $forced = false;
    if (!$can_assign) {
        $forced = (!$assign_me || ($exec && $exec !== $author));
        $exec = $author;
        $assign_me = true;
    } elseif ($assign_me || $exec <= 0) {
        $exec = $author;
        $assign_me = true;
    }

    $payload = [
        'name' => $name,
        'content' => $desc,
        'category_id' => $cat,
        'location_id' => $loc,
        'executor_glpi_id' => $exec,
        'assign_me' => $assign_me,
        'requester_id' => $author,
    ];

    $res = glpi_db_create_ticket($payload);
    if ($forced && isset($res['ok']) && $res['ok']) {
        $res['message'] = 'forced_self_executor';
    }
    wp_send_json($res);
}

