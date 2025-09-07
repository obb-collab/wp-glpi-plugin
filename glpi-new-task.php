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

class CatalogEmpty extends Exception {
    public $scope;
    public function __construct($scope) {
        $this->scope = $scope;
        parent::__construct($scope);
    }
}

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

    $user_id = get_current_user_id();
    $raw_map = get_user_meta($user_id, 'glpi_user_id', true);
    $skip_map = defined('WP_GLPI_DISABLE_MAPPING_CHECK') && WP_GLPI_DISABLE_MAPPING_CHECK;
    $skip_entity = defined('WP_GLPI_DISABLE_ENTITY_CHECK') && WP_GLPI_DISABLE_ENTITY_CHECK;
    if ($skip_map) {
        $skip_entity = true;
    }

    if (!$skip_map) {
        if ($raw_map === null || trim((string)$raw_map) === '') {
            error_log('[wp-glpi:mapping] type=MAPPING_NOT_SET wp=' . $user_id . ' glpi_id=0');
            wp_send_json_error([
                'error' => [
                    'type'    => 'MAPPING_NOT_SET',
                    'scope'   => 'all',
                    'code'    => 'MAPPING_NOT_SET',
                    'message' => 'Ваш профиль не привязан к GLPI пользователю',
                ]
            ]);
        }
        $raw_trim = trim((string)$raw_map);
        if ($raw_trim === '' || !ctype_digit($raw_trim)) {
            error_log('[wp-glpi:mapping] type=MAPPING_NONINT wp=' . $user_id . ' glpi_id=' . $raw_trim);
            wp_send_json_error([
                'error' => [
                    'type'    => 'MAPPING_NONINT',
                    'scope'   => 'all',
                    'code'    => 'MAPPING_NONINT',
                    'message' => 'GLPI user ID должен быть числом',
                ]
            ]);
        }
        $glpi_user_id = (int) $raw_trim;
        if ($glpi_user_id <= 0) {
            error_log('[wp-glpi:mapping] type=MAPPING_NONINT wp=' . $user_id . ' glpi_id=' . $raw_trim);
            wp_send_json_error([
                'error' => [
                    'type'    => 'MAPPING_NONINT',
                    'scope'   => 'all',
                    'code'    => 'MAPPING_NONINT',
                    'message' => 'GLPI user ID должен быть числом',
                ]
            ]);
        }
    } else {
        $glpi_user_id = (int) trim((string) $raw_map);
    }

    try {
        $pdo = glpi_get_pdo();
        $pdo->beginTransaction();

        $default_entity = 0;
        if (!$skip_map && $glpi_user_id > 0) {
            $stmt = $pdo->prepare('SELECT id, entities_id FROM glpi_users WHERE id = :id');
            $stmt->execute([':id' => $glpi_user_id]);
            $user_row = $stmt->fetch();
            if (!$user_row) {
                $pdo->rollBack();
                error_log('[wp-glpi:mapping] type=MAPPING_BROKEN wp=' . $user_id . ' glpi_id=' . $glpi_user_id);
                wp_send_json_error([
                    'error' => [
                        'type'    => 'MAPPING_BROKEN',
                        'scope'   => 'all',
                        'code'    => 'MAPPING_BROKEN',
                        'message' => 'GLPI пользователь не найден',
                        'details' => WP_GLPI_DEBUG ? ['glpi_user_id' => $glpi_user_id] : null,
                    ]
                ]);
            }
            $default_entity = (int) ($user_row['entities_id'] ?? 0);
        }

        $allowed = [];
        $profiles = [];
        if (!$skip_entity && $glpi_user_id > 0) {
            $stmtP = $pdo->prepare('SELECT entities_id, is_recursive FROM glpi_profiles_users WHERE users_id = :uid');
            $stmtP->execute([':uid' => $glpi_user_id]);
            $profiles = $stmtP->fetchAll();
            foreach ($profiles as $p) {
                $eid = (int) ($p['entities_id'] ?? 0);
                if ($eid <= 0) continue;
                if ((int) ($p['is_recursive'] ?? 0) === 1) {
                    $stmtE = $pdo->prepare('SELECT e2.id FROM glpi_entities e2 WHERE e2.id = :id OR FIND_IN_SET(:id, e2.ancestors_cache)');
                    $stmtE->execute([':id' => $eid]);
                    $allowed = array_merge($allowed, array_map('intval', $stmtE->fetchAll(PDO::FETCH_COLUMN)));
                } else {
                    $allowed[] = $eid;
                }
            }
            if (empty($allowed) && $default_entity > 0) {
                $allowed[] = $default_entity;
            }
            $allowed = array_values(array_unique(array_map('intval', $allowed)));
            if (empty($allowed)) {
                $pdo->rollBack();
                error_log('[wp-glpi:new-task] ENTITY_ACCESS user=' . $user_id . ' glpi=' . $glpi_user_id . ' allowed=0');
                wp_send_json_error([
                    'error' => [
                        'type'    => 'ENTITY_ACCESS',
                        'scope'   => 'all',
                        'code'    => 'NO_ENTITY',
                        'message' => 'Нет доступа к сущности',
                        'details' => WP_GLPI_DEBUG ? ['glpi_user_id' => $glpi_user_id, 'profiles_count' => count($profiles)] : null,
                    ]
                ]);
            }
        }

        $scope = 'categories';
        if ($skip_entity) {
            $stmtC = $pdo->prepare('SELECT c.id, c.name, c.completename, c.level, c.ancestors_cache FROM glpi_itilcategories c WHERE c.is_deleted = 0 AND c.is_helpdeskvisible = 1 ORDER BY c.completename ASC');
            $stmtC->execute();
        } else {
            $placeholders = implode(',', array_fill(0, count($allowed), '?'));
            $sqlC = 'SELECT c.id, c.name, c.completename, c.level, c.ancestors_cache FROM glpi_itilcategories c WHERE c.is_deleted = 0 AND c.is_helpdeskvisible = 1 AND c.entities_id IN (' . $placeholders . ') ORDER BY c.completename ASC';
            $stmtC = $pdo->prepare($sqlC);
            $stmtC->execute($allowed);
        }
        $categories = $stmtC->fetchAll();
        if (!$categories) {
            throw new CatalogEmpty('categories');
        }

        $scope = 'locations';
        if ($skip_entity) {
            $stmtL = $pdo->prepare('SELECT l.id, l.name, l.completename FROM glpi_locations l WHERE l.is_deleted = 0 ORDER BY l.completename ASC');
            $stmtL->execute();
        } else {
            $placeholders = implode(',', array_fill(0, count($allowed), '?'));
            $sqlL = 'SELECT l.id, l.name, l.completename FROM glpi_locations l WHERE l.is_deleted = 0 AND l.entities_id IN (' . $placeholders . ') ORDER BY l.completename ASC';
            $stmtL = $pdo->prepare($sqlL);
            $stmtL->execute($allowed);
        }
        $locations = $stmtL->fetchAll();
        if (!$locations) {
            throw new CatalogEmpty('locations');
        }

        $scope      = 'executors';
        $executors  = glpi_get_wp_executors();
        if (!$executors) {
            throw new CatalogEmpty('executors');
        }

        $pdo->commit();
        $resp = [
            'categories' => $categories,
            'locations'  => $locations,
            'executors'  => $executors,
        ];
        if (WP_GLPI_DEBUG) {
            $resp['meta'] = ['allowed_entities' => $allowed];
        }
        wp_send_json_success($resp);
    } catch (CatalogEmpty $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        wp_send_json_error([
            'error' => [
                'type'    => 'EMPTY',
                'scope'   => $e->scope,
                'code'    => 'EMPTY_CATALOG',
                'message' => 'Справочник «' . ($e->scope === 'categories' ? 'Категории' : ($e->scope === 'locations' ? 'Местоположения' : 'Исполнители')) . '» пуст',
            ]
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
                'message' => 'Ошибка SQL при загрузке ' . ($scope === 'categories' ? 'категорий' : ($scope === 'locations' ? 'местоположений' : 'исполнителей')),
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
