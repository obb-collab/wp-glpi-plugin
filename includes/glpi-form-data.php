<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/logger.php';

/**
 * AJAX: выдаёт списки категорий и местоположений.
 */
add_action('wp_ajax_gexe_get_form_data', 'gexe_get_form_data');
function gexe_get_form_data() {
    $t0       = microtime(true);
    $user_id  = get_current_user_id();
    $nonce_ok = check_ajax_referer('gexe_form_data', 'nonce', false);
    $cache_key = 'glpi_form_data_v1';

    if (!$nonce_ok) {
        $elapsed = (int) round((microtime(true) - $t0) * 1000);
        gexe_log_action(sprintf('[form-data] user=%d cap=read nonce=bad http=403 elapsed=%dms cats=0 locs=0', $user_id, $elapsed));
        wp_send_json(['ok' => false, 'code' => 'AJAX_FORBIDDEN', 'reason' => 'nonce', 'message' => 'forbidden', 'took_ms' => $elapsed], 403);
    }

    if (!is_user_logged_in() || !current_user_can('read')) {
        $elapsed = (int) round((microtime(true) - $t0) * 1000);
        gexe_log_action(sprintf('[form-data] user=%d cap=read nonce=ok http=403 elapsed=%dms cats=0 locs=0', $user_id, $elapsed));
        wp_send_json(['ok' => false, 'code' => 'AJAX_FORBIDDEN', 'reason' => 'cap', 'message' => 'forbidden', 'took_ms' => $elapsed], 403);
    }

    $data = wp_cache_get($cache_key, 'glpi');
    if ($data === false) {
        $data = get_transient($cache_key);
    }
    $source = 'cache';

    if (!is_array($data) || empty($data)) {
        global $glpi_db;
        $error_code = '';
        $categories = [];
        $locations  = [];
        $source = 'db';

        try {
            if (!($glpi_db instanceof wpdb)) {
                throw new Exception('DB_CONNECT_FAILED');
            }

            $cats = $glpi_db->get_results(
                "SELECT id, name, completename AS path
                 FROM glpi_itilcategories
                 WHERE is_deleted = 0
                 ORDER BY name ASC",
                ARRAY_A
            );
            if ($glpi_db->last_error) {
                throw new Exception('SQL_ERROR');
            }
            foreach ($cats as $c) {
                $parts = preg_split('/\\s[\\/\\>]\\s/', $c['path']);
                $short = end($parts);
                $categories[] = [
                    'id'   => (int) $c['id'],
                    'name' => $short,
                    'path' => $c['path'],
                ];
            }

            $locs = $glpi_db->get_results(
                "SELECT id, completename AS path
                 FROM glpi_locations
                 WHERE is_deleted = 0
                 ORDER BY completename ASC",
                ARRAY_A
            );
            if ($glpi_db->last_error) {
                throw new Exception('SQL_ERROR');
            }
            foreach ($locs as $l) {
                $parts = preg_split('/\\s[\\/\\>]\\s/', $l['path']);
                $short = end($parts);
                $locations[] = [
                    'id'   => (int) $l['id'],
                    'name' => $short,
                    'path' => $l['path'],
                ];
            }
        } catch (Exception $e) {
            $error_code = $e->getMessage();
            $last_err   = $glpi_db instanceof wpdb ? $glpi_db->last_error : $e->getMessage();
        }

        if ($error_code !== '' || empty($categories) || empty($locations)) {
            // Фолбэк через REST API
            $source = 'api';
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
                        $path = isset($c['completename']) ? $c['completename'] : '';
                        $parts = preg_split('/\\s[\\/\\>]\\s/', $path);
                        $short = end($parts);
                        $categories[] = [
                            'id'   => isset($c['id']) ? (int) $c['id'] : 0,
                            'name' => $short ?: (isset($c['name']) ? $c['name'] : ''),
                            'path' => $path,
                        ];
                    }
                }
            }

            $url = gexe_glpi_api_url() . '/Location/?range=0-1000&order=ASC&sort=completename';
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
                        $path = isset($l['completename']) ? $l['completename'] : '';
                        $parts = preg_split('/\\s[\\/\\>]\\s/', $path);
                        $short = end($parts);
                        $locations[] = [
                            'id'   => isset($l['id']) ? (int) $l['id'] : 0,
                            'name' => $short ?: (isset($l['name']) ? $l['name'] : ''),
                            'path' => $path,
                        ];
                    }
                }
            }

            if (empty($categories) || empty($locations)) {
                $elapsed = (int) round((microtime(true) - $t0) * 1000);
                $code = $error_code ? $error_code : 'API_UNAVAILABLE';
                $log = sprintf('[form-data] user=%d cap=read nonce=ok http=500 error=%s elapsed=%dms', $user_id, $code, $elapsed);
                if (!empty($last_err)) $log .= ' details=' . $last_err;
                gexe_log_action($log);
                wp_send_json(['ok' => false, 'code' => $code, 'message' => 'Unable to load data', 'took_ms' => $elapsed], 500);
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
    gexe_log_action(sprintf('[form-data] user=%d cap=read nonce=ok http=200 elapsed=%dms cats=%d locs=%d', $user_id, $elapsed, count($data['categories']), count($data['locations'])));

    $out = $data;
    $out['ok']      = true;
    $out['source']  = $source;
    $out['took_ms'] = $elapsed;
    if (current_user_can('manage_options') && isset($_GET['debug'])) {
        $out['debug'] = ['source' => $source, 'elapsed' => $elapsed];
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
