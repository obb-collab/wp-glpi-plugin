<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/logger.php';
require_once dirname(__DIR__) . '/glpi-utils.php';
require_once __DIR__ . '/executors-cache.php';
require_once dirname(__DIR__) . '/glpi-db-setup.php';

/**
 * AJAX: выдаёт списки категорий и местоположений.
 */
add_action('wp_ajax_gexe_get_form_data', 'gexe_get_form_data');
function gexe_get_form_data() {
    $t0        = microtime(true);
    $nonce_ok  = check_ajax_referer('gexe_form_data', 'nonce', false);
    $cache_key = 'glpi_form_data_v1';

    if (!$nonce_ok) {
        $elapsed = (int) round((microtime(true) - $t0) * 1000);
        gexe_log_action(sprintf('[form-data] source=auth http=403 elapsed=%dms cats=0 locs=0 err="nonce"', $elapsed));
        gexe_ajax_error_compat('NONCE_EXPIRED', 'nonce_failed', ['took_ms' => $elapsed], 403);
    }

    if (!is_user_logged_in() || !current_user_can('read')) {
        $elapsed = (int) round((microtime(true) - $t0) * 1000);
        gexe_log_action(sprintf('[form-data] source=auth http=403 elapsed=%dms cats=0 locs=0 err="cap"', $elapsed));
        gexe_ajax_error_compat('NO_PERMISSION', 'forbidden', ['took_ms' => $elapsed], 403);
    }

    $data       = wp_cache_get($cache_key, 'glpi');
    if ($data === false) {
        $data = get_transient($cache_key);
    }
    $source      = 'cache';
    $error_msg   = '';
    $debug_tests = [];
    $cat_sql     = '';
    $loc_sql     = '';

    if (!is_array($data) || empty($data['categories']) || empty($data['locations'])) {
        $categories = [];
        $locations  = [];
        $source     = 'db';
        $cat_sql    = '';
        $loc_sql    = '';
        try {
            $pdo = glpi_get_pdo();
            $pdo->beginTransaction();

            $cat_where = [];
            if (gexe_glpi_table_has_column($pdo, 'glpi_itilcategories', 'is_helpdeskvisible')) {
                $cat_where[] = 'is_helpdeskvisible = 1';
            }
            $cat_sql = 'SELECT id, name, completename FROM glpi_itilcategories';
            if ($cat_where) {
                $cat_sql .= ' WHERE ' . implode(' AND ', $cat_where);
            }
            $cat_sql .= ' ORDER BY completename ASC LIMIT 1000';
            $cats = $pdo->query($cat_sql)->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cats as $c) {
                $name = isset($c['name']) ? trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($c['name']))) : '';
                $path = isset($c['completename']) ? $c['completename'] : $name;
                $path = trim(preg_replace('/\s+[\/\>]\s+/u', ' › ', wp_strip_all_tags($path)));
                $categories[] = [
                    'id'   => (int) $c['id'],
                    'name' => $name,
                    'path' => $path,
                ];
            }

            $loc_where = [];
            if (gexe_glpi_table_has_column($pdo, 'glpi_locations', 'is_deleted')) {
                $loc_where[] = 'is_deleted = 0';
            }
            $loc_sql = 'SELECT id, name, completename FROM glpi_locations';
            if ($loc_where) {
                $loc_sql .= ' WHERE ' . implode(' AND ', $loc_where);
            }
            $loc_sql .= ' ORDER BY completename ASC LIMIT 2000';
            $locs = $pdo->query($loc_sql)->fetchAll(PDO::FETCH_ASSOC);
            foreach ($locs as $l) {
                $locations[] = [
                    'id'   => (int) $l['id'],
                    'name' => $l['completename'] ?? ($l['name'] ?? ''),
                ];
            }

            if (empty($categories) || empty($locations)) {
                throw new Exception('EMPTY_RESULT');
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error_msg = mb_substr(wp_strip_all_tags($e->getMessage()), 0, 200);
            $source    = 'api';
        }

        if ($source === 'api') {
            $categories = [];
            $locations  = [];

            $url = gexe_glpi_api_url() . '/ITILCategory/?range=0-1000&order=ASC&sort=completename';
            $t_api = microtime(true);
            $resp = wp_remote_get($url, [
                'timeout' => 10,
                'headers' => gexe_glpi_api_headers(),
            ]);
            gexe_glpi_log('form-data ITILCategory', $url, $resp, $t_api);
            if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) < 300) {
                $body = json_decode(wp_remote_retrieve_body($resp), true);
                if (is_array($body)) {
                    foreach ($body as $c) {
                        $name = isset($c['name']) ? trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($c['name']))) : '';
                        $path = isset($c['completename']) ? $c['completename'] : $name;
                        $path = trim(preg_replace('/\s+[\/\>]\s+/u', ' › ', wp_strip_all_tags($path)));
                        $categories[] = [
                            'id'   => isset($c['id']) ? (int) $c['id'] : 0,
                            'name' => $name,
                            'path' => $path,
                        ];
                    }
                }
            }

            $url = gexe_glpi_api_url() . '/Location/?range=0-2000&order=ASC&sort=completename';
            $t_api = microtime(true);
            $resp = wp_remote_get($url, [
                'timeout' => 10,
                'headers' => gexe_glpi_api_headers(),
            ]);
            gexe_glpi_log('form-data Location', $url, $resp, $t_api);
            if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) < 300) {
                $body = json_decode(wp_remote_retrieve_body($resp), true);
                if (is_array($body)) {
                    foreach ($body as $l) {
                        $locations[] = [
                            'id'   => isset($l['id']) ? (int) $l['id'] : 0,
                            'name' => isset($l['completename']) ? $l['completename'] : (isset($l['name']) ? $l['name'] : ''),
                        ];
                    }
                }
            }

            if (empty($categories) || empty($locations)) {
                $elapsed = (int) round((microtime(true) - $t0) * 1000);
                $err = $error_msg !== '' ? $error_msg : 'API_UNAVAILABLE';
                gexe_log_action(sprintf('[form-data] source=db http=500 elapsed=%dms cats=%d locs=%d err="%s"', $elapsed, count($categories), count($locations), $err));
                gexe_ajax_error_compat('SQL_OP_FAILED', $err, ['took_ms' => $elapsed], 500);
            }
        }

        $data = [
            'categories' => $categories,
            'locations'  => $locations,
        ];

        wp_cache_set($cache_key, $data, 'glpi', 30 * MINUTE_IN_SECONDS);
        set_transient($cache_key, $data, 30 * MINUTE_IN_SECONDS);
    }

    [$exec_raw, $exec_cache, $exec_more] = gexe_get_wp_executors_cached();
    $executors = [];
    foreach ($exec_raw as $e) {
        $executors[] = [
            'id'   => (int) ($e['glpi_user_id'] ?? 0),
            'name' => (string) ($e['label'] ?? ''),
        ];
    }
    $data['executors']       = $executors;
    $data['executors_cache'] = $exec_cache;
    $data['executors_more']  = $exec_more;

    $elapsed = (int) round((microtime(true) - $t0) * 1000);
    $err_log = isset($error_msg) ? $error_msg : '';
    gexe_log_action(sprintf('[form-data] source=%s tables="itilcategories,locations" sql="%s" sql2="%s" elapsed=%dms cats=%d locs=%d err="%s"', $source, $cat_sql, $loc_sql, $elapsed, count($data['categories']), count($data['locations']), $err_log));

    $out = $data;
    $out['source']  = $source;
    $out['took_ms'] = $elapsed;
    $out['nonce']   = wp_create_nonce('gexe_form_data');
    if (isset($_GET['debug'])) {
        $out['debug'] = [
            'source'  => $source,
            'elapsed' => $elapsed,
        ];
        if (!empty($debug_tests)) {
            $out['debug']['tests'] = $debug_tests;
        }
    }
    gexe_ajax_success_compat($out);
}

/**
 * Обновление nonce для фронтенда.
 */
add_action('wp_ajax_gexe_refresh_nonce', 'gexe_refresh_nonce');
function gexe_refresh_nonce() {
    if (!is_user_logged_in() || !current_user_can('read')) {
        gexe_ajax_error_compat('NO_PERMISSION', 'forbidden', [], 403);
    }
    gexe_ajax_success_compat(['nonce' => wp_create_nonce('gexe_form_data')]);
}

/**
 * Удаление кэша, используется в админке.
 */
function gexe_glpi_flush_cache() {
    wp_cache_delete('glpi_form_data_v1', 'glpi');
    delete_transient('glpi_form_data_v1');
}

