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
        'debug'        => defined('WP_GLPI_DEBUG') && WP_GLPI_DEBUG,
    ];
    wp_localize_script('glpi-new-task-js', 'glpiAjax', $data);
});

/** Verify AJAX nonce. */
function glpi_nt_verify_nonce() {
    if (!check_ajax_referer('gexe_actions', 'nonce', false)) {
        wp_send_json_error([
            'error' => [
                'type'    => 'SECURITY',
                'scope'   => 'all',
                'code'    => 'NO_CSRF',
                'message' => 'Ошибка безопасности запроса',
            ]
        ]);
    }
}

// -------- Dictionaries --------
/* legacy loader (rollback)
add_action('wp_ajax_glpi_get_categories', 'glpi_ajax_get_categories');
function glpi_ajax_get_categories() { old implementation }

add_action('wp_ajax_glpi_get_locations', 'glpi_ajax_get_locations');
function glpi_ajax_get_locations() { old implementation }

add_action('wp_ajax_glpi_get_executors', 'glpi_ajax_get_executors');
function glpi_ajax_get_executors() { old implementation }

function glpi_db_get_executors() { old implementation }
*/

add_action('wp_ajax_glpi_load_dicts', 'glpi_ajax_load_dicts');

function glpi_get_wp_executors(): array {
    global $wpdb;
    $um = $wpdb->usermeta;
    $u  = $wpdb->users;
    $rows = $wpdb->get_results(
        "SELECT u.ID AS user_id, u.display_name, um.meta_value AS glpi_user_id FROM $u u INNER JOIN $um um ON um.user_id = u.ID AND um.meta_key = 'glpi_user_id' AND um.meta_value <> '' ORDER BY u.display_name ASC",
        ARRAY_A
    );
    if (!$rows) {
        return [];
    }
    return array_map(function ($r) {
        return [
            'user_id'      => (int) ($r['user_id'] ?? 0),
            'display_name' => $r['display_name'] ?? '',
            'glpi_user_id' => (int) ($r['glpi_user_id'] ?? 0),
        ];
    }, $rows);
}

function glpi_ajax_load_dicts() {
    glpi_nt_verify_nonce();
    if (!is_user_logged_in()) {
        wp_send_json_error([
            'error' => [
                'type'    => 'SECURITY',
                'scope'   => 'all',
                'code'    => 'NO_AUTH',
                'message' => 'Пользователь не авторизован',
            ]
        ]);
    }
    try {
        $pdo = glpi_get_pdo();
        $pdo->beginTransaction();
        $scope = 'all';
        $note = null;

        $use_filter = defined('WP_GLPI_FILTER_CATALOGS_BY_ENTITY') && WP_GLPI_FILTER_CATALOGS_BY_ENTITY;
        $allowed = [];
        if ($use_filter) {
            $glpi_user_id = gexe_get_glpi_user_id(get_current_user_id());
            if ($glpi_user_id > 0) {
                $stmt = $pdo->prepare('SELECT u.entities_id, u.is_active FROM glpi_users u WHERE u.id = :id LIMIT 1');
                $stmt->execute([':id' => $glpi_user_id]);
                $user_row = $stmt->fetch();
                if ($user_row && (int)($user_row['is_active'] ?? 0) === 1) {
                    $user_eid = (int) ($user_row['entities_id'] ?? 0);
                    $entity_mode = defined('WP_GLPI_ENTITY_MODE') ? WP_GLPI_ENTITY_MODE : 'user_fallback';
                    if ($entity_mode !== 'all') {
                        $cache_key = 'wp_glpi_allowed_' . $glpi_user_id . '_' . $entity_mode;
                        $cached = get_transient($cache_key);
                        if (is_array($cached) && $cached) {
                            $allowed = array_map('intval', $cached);
                        } else {
                            $stmtP = $pdo->prepare('SELECT pu.entities_id, pu.is_recursive FROM glpi_profiles_users pu WHERE pu.users_id = :uid');
                            $stmtP->execute([':uid' => $glpi_user_id]);
                            $profiles = $stmtP->fetchAll();
                            $profiles_count = count($profiles);
                            if ($profiles_count > 0 || $entity_mode === 'profiles') {
                                foreach ($profiles as $p) {
                                    $eid = (int) ($p['entities_id'] ?? 0);
                                    if ($eid <= 0) continue;
                                    $stmtE = $pdo->prepare('SELECT e2.id FROM glpi_entities e2 WHERE e2.id = :eid OR e2.ancestors_cache LIKE CONCAT(\'%$\', :eid, \'$%\')');
                                    $stmtE->bindValue(':eid', $eid, PDO::PARAM_INT);
                                    $stmtE->execute();
                                    $allowed = array_merge($allowed, array_map('intval', $stmtE->fetchAll(PDO::FETCH_COLUMN)));
                                }
                            } else {
                                $stmtE = $pdo->prepare('SELECT e2.id FROM glpi_entities e2 WHERE e2.id = :eid OR e2.ancestors_cache LIKE CONCAT(\'%$\', :eid, \'$%\')');
                                $stmtE->bindValue(':eid', $user_eid, PDO::PARAM_INT);
                                $stmtE->execute();
                                $allowed = array_map('intval', $stmtE->fetchAll(PDO::FETCH_COLUMN));
                            }
                            $allowed = array_values(array_unique($allowed));
                            set_transient($cache_key, $allowed, 5 * MINUTE_IN_SECONDS);
                        }
                    }
                }
            }
            if (!$allowed) {
                $use_filter = false;
                $note = 'fallback_no_entities';
            }
        }

        $scope = 'categories';
        $sqlC = 'SELECT c.id, c.name, c.completename, c.level FROM glpi_itilcategories c WHERE c.is_deleted = 0 AND c.is_helpdeskvisible = 1';
        if ($use_filter) {
            $ph = implode(',', array_map(function ($i) { return ':e' . $i; }, array_keys($allowed)));
            $sqlC .= ' AND c.entities_id IN (' . $ph . ')';
        }
        $sqlC .= ' ORDER BY c.completename ASC';
        $stmtC = $pdo->prepare($sqlC);
        if ($use_filter) {
            foreach ($allowed as $i => $id) {
                $stmtC->bindValue(':e' . $i, $id, PDO::PARAM_INT);
            }
        }
        $stmtC->execute();
        $categories = $stmtC->fetchAll();
        $meta_empty = ['categories' => empty($categories), 'locations' => false];

        $scope = 'locations';
        $sqlL = 'SELECT l.id, l.name, l.completename FROM glpi_locations l WHERE l.is_deleted = 0';
        if ($use_filter) {
            $ph = implode(',', array_map(function ($i) { return ':e' . $i; }, array_keys($allowed)));
            $sqlL .= ' AND l.entities_id IN (' . $ph . ')';
        }
        $sqlL .= ' ORDER BY l.completename ASC';
        $stmtL = $pdo->prepare($sqlL);
        if ($use_filter) {
            foreach ($allowed as $i => $id) {
                $stmtL->bindValue(':e' . $i, $id, PDO::PARAM_INT);
            }
        }
        $stmtL->execute();
        $locations = $stmtL->fetchAll();
        $meta_empty['locations'] = empty($locations);

        $pdo->commit();

        $executors = glpi_get_wp_executors();
        $meta = ['entity_filter' => $use_filter ? 'on' : 'off', 'empty' => $meta_empty];
        if ($note) {
            $meta['note'] = $note;
        }
        if (WP_GLPI_DEBUG) {
            $meta['allowed_entities'] = $allowed;
        }
        error_log('[wp-glpi:new-task catalogs] mode=' . ($use_filter ? 'on' : 'off') . ' cats=' . count($categories) . ' locs=' . count($locations));

        wp_send_json_success([
            'categories' => $categories,
            'locations'  => $locations,
            'executors'  => $executors,
            'meta'       => $meta,
        ]);
    } catch (PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[wp-glpi:new-task] [' . ($scope ?? 'all') . '] ' . $e->getMessage());
        wp_send_json_error([
            'error' => [
                'type'    => 'SQL',
                'scope'   => $scope ?? 'all',
                'code'    => $e->getCode(),
                'message' => 'Ошибка SQL при загрузке ' . ($scope === 'categories' ? 'категорий' : ($scope === 'locations' ? 'местоположений' : 'справочников')),
                'details' => WP_GLPI_DEBUG ? $e->getMessage() : null,
            ]
        ]);
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[wp-glpi:new-task] [all] ' . $e->getMessage());
        wp_send_json_error([
            'error' => [
                'type'    => 'UNKNOWN',
                'scope'   => 'all',
                'code'    => 'UNHANDLED',
                'message' => 'Не удалось загрузить справочники',
                'details' => WP_GLPI_DEBUG ? $e->getMessage() : null,
            ]
        ]);
    }
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
