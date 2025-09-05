<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/logger.php';

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
        wp_send_json(['ok' => false, 'code' => 'AJAX_FORBIDDEN', 'reason' => 'nonce', 'message' => 'forbidden', 'took_ms' => $elapsed], 403);
    }

    if (!is_user_logged_in() || !current_user_can('read')) {
        $elapsed = (int) round((microtime(true) - $t0) * 1000);
        gexe_log_action(sprintf('[form-data] source=auth http=403 elapsed=%dms cats=0 locs=0 err="cap"', $elapsed));
        wp_send_json(['ok' => false, 'code' => 'AJAX_FORBIDDEN', 'reason' => 'cap', 'message' => 'forbidden', 'took_ms' => $elapsed], 403);
    }

    $data = wp_cache_get($cache_key, 'glpi');
    if ($data === false) {
        $data = get_transient($cache_key);
    }
    $source = 'cache';

    if (!is_array($data) || empty($data)) {
        global $glpi_db;
        $categories = [];
        $locations  = [];
        $error_msg  = '';
        $source     = 'db';

        try {
            if (!($glpi_db instanceof wpdb)) {
                throw new Exception('DB_CONNECT_FAILED');
            }

            $debug_tests = [];
            if (isset($_GET['debug'])) {
                $tests = [
                    'SELECT 1',
                    'SELECT id FROM glpi.glpi_itilcategories LIMIT 1',
                    'SELECT id FROM glpi.glpi_locations LIMIT 1',
                ];
                foreach ($tests as $sql) {
                    $res = $glpi_db->get_var($sql);
                    $debug_tests[] = ['sql' => $sql, 'result' => $res, 'error' => $glpi_db->last_error];
                }
                $gr = $glpi_db->get_col('SHOW GRANTS FOR CURRENT_USER');
                gexe_log_action('[form-data] grants ' . implode(' || ', $gr));
            }

            $cats = $glpi_db->get_results(
                'SELECT id, name FROM `glpi`.`glpi_itilcategories` WHERE is_deleted = 0 ORDER BY name ASC LIMIT 1000',
                ARRAY_A
            );
            if ($glpi_db->last_error) {
                throw new Exception('SQL_ERROR');
            }
            foreach ($cats as $c) {
                $categories[] = [
                    'id'   => (int) $c['id'],
                    'name' => $c['name'],
                ];
            }

            $locs = $glpi_db->get_results(
                'SELECT id, completename AS name FROM `glpi`.`glpi_locations` WHERE is_deleted = 0 ORDER BY completename ASC LIMIT 2000',
                ARRAY_A
            );
            if ($glpi_db->last_error) {
                throw new Exception('SQL_ERROR');
            }
            foreach ($locs as $l) {
                $locations[] = [
                    'id'   => (int) $l['id'],
                    'name' => $l['name'],
                ];
            }
        } catch (Exception $e) {
            $error_msg   = $glpi_db instanceof wpdb ? $glpi_db->last_error : $e->getMessage();
            $driver_code = ($glpi_db instanceof wpdb && $glpi_db->dbh) ? mysqli_errno($glpi_db->dbh) : 0;
            $driver_msg  = ($glpi_db instanceof wpdb && $glpi_db->dbh) ? mysqli_error($glpi_db->dbh) : '';
            gexe_log_action(sprintf('[form-data] sql_error last_error="%s" last_query="%s" driver=%d:%s', $glpi_db->last_error, $glpi_db->last_query, $driver_code, $driver_msg));
            if ($glpi_db instanceof wpdb) {
                $grants = $glpi_db->get_col('SHOW GRANTS FOR CURRENT_USER');
                gexe_log_action('[form-data] grants ' . implode(' || ', $grants));
            }
            $source = 'api';
        }

        if ($source === 'api') {
            $categories = [];
            $locations  = [];

            $url = gexe_glpi_api_url() . '/ITILCategory/?range=0-1000&order=ASC&sort=name';
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
                        $categories[] = [
                            'id'   => isset($c['id']) ? (int) $c['id'] : 0,
                            'name' => isset($c['name']) ? $c['name'] : '',
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
                gexe_log_action(sprintf('[form-data] source=api http=500 elapsed=%dms cats=%d locs=%d err="%s"', $elapsed, count($categories), count($locations), $err));
                wp_send_json(['ok' => false, 'code' => 'SQL_ERROR', 'message' => $err, 'took_ms' => $elapsed], 500);
            }
        }

        // Жёстко заданные исполнители
        $mapping = [
            ['glpi_id' => 622, 'name' => 'Сушко Валентин'],
            ['glpi_id' => 621, 'name' => 'Скомороха Анастасия'],
            ['glpi_id' => 269, 'name' => 'Смирнов Максим'],
            ['glpi_id' => 180, 'name' => 'Кузнецов Евгений'],
            ['glpi_id' => 2,   'name' => 'Куткин Павел'],
            ['glpi_id' => 632, 'name' => 'Стельмашенко Игнат'],
            ['glpi_id' => 620, 'name' => 'Нечепорук Александр'],
        ];
        $executors = [];
        foreach ($mapping as $m) {
            $executors[] = [
                'id'   => (int) $m['glpi_id'],
                'name' => $m['name'],
            ];
        }

        $data = [
            'categories' => $categories,
            'locations'  => $locations,
            'executors'  => $executors,
        ];

        wp_cache_set($cache_key, $data, 'glpi', 30 * MINUTE_IN_SECONDS);
        set_transient($cache_key, $data, 30 * MINUTE_IN_SECONDS);
    }

    $elapsed = (int) round((microtime(true) - $t0) * 1000);
    gexe_log_action(sprintf('[form-data] source=%s http=200 elapsed=%dms cats=%d locs=%d err=""', $source, $elapsed, count($data['categories']), count($data['locations'])));

    $out = $data;
    $out['ok']      = true;
    $out['source']  = $source;
    $out['took_ms'] = $elapsed;
    if (isset($_GET['debug'])) {
        $out['debug'] = [
            'source'  => $source,
            'elapsed' => $elapsed,
        ];
        if (!empty($debug_tests)) {
            $out['debug']['tests'] = $debug_tests;
        }
    }
    wp_send_json($out);
}

/**
 * Обновление nonce для фронтенда.
 */
add_action('wp_ajax_gexe_refresh_nonce', 'gexe_refresh_nonce');
function gexe_refresh_nonce() {
    if (!is_user_logged_in() || !current_user_can('read')) {
        wp_send_json_error(['code' => 'AJAX_FORBIDDEN', 'reason' => 'cap'], 403);
    }
    wp_send_json_success(['nonce' => wp_create_nonce('gexe_form_data')]);
}

/**
 * Удаление кэша, используется в админке.
 */
function gexe_glpi_flush_cache() {
    wp_cache_delete('glpi_form_data_v1', 'glpi');
    delete_transient('glpi_form_data_v1');
}

