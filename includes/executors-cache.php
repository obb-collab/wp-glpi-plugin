<?php
if (!defined('ABSPATH')) exit;

function gexe_executors_cache_enabled() {
    return (bool) get_option('executors_cache_enabled', true);
}

function gexe_wp_executors_cache_key() {
    $key = 'gexe_wp_executors_v1';
    if (is_multisite()) {
        $key .= '_' . get_current_blog_id();
    }
    return $key;
}

function gexe_query_wp_executors($limit = 1000) {
    $query = new WP_User_Query([
        'meta_query' => [
            [
                'key'     => 'glpi_user_id',
                'value'   => 0,
                'compare' => '>',
                'type'    => 'NUMERIC',
            ],
        ],
        'fields'      => ['ID', 'display_name'],
        'orderby'     => 'display_name',
        'order'       => 'ASC',
        'number'      => $limit,
        'count_total' => true,
    ]);
    $users = $query->get_results();
    $total = (int) $query->get_total();
    $seen = [];
    foreach ($users as $u) {
        $label = trim($u->display_name);
        if ($label === '') continue;
        if (isset($seen[$label])) continue;
        $seen[$label] = [
            'id'    => (int) $u->ID,
            'label' => $label,
        ];
    }
    $executors = array_values($seen);
    usort($executors, function ($a, $b) {
        return strcasecmp($a['label'], $b['label']);
    });
    $more = max(0, $total - count($executors));
    return [$executors, $more];
}

function gexe_get_wp_executors_cached() {
    $enabled = gexe_executors_cache_enabled();
    $meta = ['enabled' => $enabled];
    $more = 0;
    $cache_key = gexe_wp_executors_cache_key();

    $payload = wp_cache_get($cache_key, 'glpi');
    if ($payload === false) {
        $payload = get_transient($cache_key);
    }
    $cached = $payload;

    if (!$enabled) {
        [$executors, $more] = gexe_query_wp_executors();
        return [$executors, $meta, $more];
    }

    if (is_array($payload) && isset($payload['data'], $payload['stored_at'])) {
        $ttl = 300 - (time() - (int) $payload['stored_at']);
        if ($ttl < 0) $ttl = 0;
        $meta = [
            'enabled'             => true,
            'source'              => 'cache',
            'ttlSecondsRemaining' => $ttl,
        ];
        $executors = $payload['data'];
        $more      = isset($payload['more']) ? (int) $payload['more'] : 0;
        return [$executors, $meta, $more];
    }

    [$executors, $more] = gexe_query_wp_executors();
    if (!empty($executors)) {
        $payload = [
            'data'      => $executors,
            'stored_at' => time(),
            'more'      => $more,
        ];
        wp_cache_set($cache_key, $payload, 'glpi', 300);
        set_transient($cache_key, $payload, 300);
        $meta = ['enabled' => true, 'source' => 'rebuild'];
        return [$executors, $meta, $more];
    }

    if (is_array($cached) && isset($cached['data']) && !empty($cached['data'])) {
        $ttl = 300 - (time() - (int) $cached['stored_at']);
        if ($ttl < 0) $ttl = 0;
        $meta = [
            'enabled'             => true,
            'source'              => 'cache',
            'ttlSecondsRemaining' => $ttl,
        ];
        $executors = $cached['data'];
        $more      = isset($cached['more']) ? (int) $cached['more'] : 0;
        return [$executors, $meta, $more];
    }

    $meta = ['enabled' => true, 'source' => 'rebuild'];
    return [[], $meta, 0];
}

function gexe_flush_executors_cache() {
    $key = gexe_wp_executors_cache_key();
    wp_cache_delete($key, 'glpi');
    delete_transient($key);
    // also flush form data cache
    wp_cache_delete('glpi_form_data_v1', 'glpi');
    delete_transient('glpi_form_data_v1');
}

function gexe_maybe_flush_executors_cache($meta_id, $user_id, $meta_key) {
    if (!gexe_executors_cache_enabled()) return;
    $keys = ['glpi_user_id', 'first_name', 'last_name', 'display_name'];
    if (in_array($meta_key, $keys, true)) {
        gexe_flush_executors_cache();
    }
}
add_action('updated_user_meta', 'gexe_maybe_flush_executors_cache', 10, 3);
add_action('added_user_meta', 'gexe_maybe_flush_executors_cache', 10, 3);
add_action('deleted_user_meta', 'gexe_maybe_flush_executors_cache', 10, 3);

function gexe_flush_executors_cache_on_user($user_id) {
    if (!gexe_executors_cache_enabled()) return;
    gexe_flush_executors_cache();
}
add_action('profile_update', 'gexe_flush_executors_cache_on_user', 10, 2);
add_action('user_register', 'gexe_flush_executors_cache_on_user');
add_action('delete_user', 'gexe_flush_executors_cache_on_user');
