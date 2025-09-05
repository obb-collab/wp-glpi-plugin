<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/logger.php';

// AJAX: выдаёт списки категорий и местоположений
add_action('wp_ajax_glpi_get_form_data', 'gexe_glpi_get_form_data');
function gexe_glpi_get_form_data() {
    check_ajax_referer('glpi_modal_actions');
    $t0 = microtime(true);
    $cache_key = 'glpi_cached_form_data';

    // Пытаемся получить из объектного кэша или транзиента
    $data = wp_cache_get($cache_key, 'glpi');
    if ($data === false) {
        $data = get_transient($cache_key);
    }
    $source = 'cache';

    if (!is_array($data) || empty($data)) {
        $source = 'db';
        global $glpi_db;

        // Категории: id, name + полный путь
        $cats = $glpi_db->get_results(
            "SELECT id, name, completename AS path
             FROM glpi_itilcategories
             WHERE is_deleted = 0
             ORDER BY name ASC",
            ARRAY_A
        );
        $categories = [];
        if ($cats) {
            foreach ($cats as $c) {
                $parts = preg_split('/\\s[\\/\\>]\\s/', $c['path']);
                $short = end($parts);
                $categories[] = [
                    'id'   => (int) $c['id'],
                    'name' => $short,
                    'path' => $c['path'],
                ];
            }
        }

        // Местоположения: id + полный путь
        $locs = $glpi_db->get_results(
            "SELECT id, completename AS path
             FROM glpi_locations
             WHERE is_deleted = 0
             ORDER BY completename ASC",
            ARRAY_A
        );
        $locations = [];
        if ($locs) {
            foreach ($locs as $l) {
                $parts = preg_split('/\\s[\\/\\>]\\s/', $l['path']);
                $short = end($parts);
                $locations[] = [
                    'id'   => (int) $l['id'],
                    'name' => $short,
                    'path' => $l['path'],
                ];
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

        // Сохраняем в кэш на 30 минут
        wp_cache_set($cache_key, $data, 'glpi', 30 * MINUTE_IN_SECONDS);
        set_transient($cache_key, $data, 30 * MINUTE_IN_SECONDS);
    }

    $elapsed = (int) round((microtime(true) - $t0) * 1000);
    gexe_log_action(sprintf('[form-data] source=%s elapsed=%dms count={cat:%d, loc:%d}', $source, $elapsed, count($data['categories']), count($data['locations'])));

    if (current_user_can('manage_options') && isset($_GET['debug'])) {
        $data['debug'] = ['source' => $source, 'elapsed' => $elapsed];
    }

    wp_send_json($data);
}

// Удаление кэша, используется в админке
function gexe_glpi_flush_cache() {
    wp_cache_delete('glpi_cached_form_data', 'glpi');
    delete_transient('glpi_cached_form_data');
}
