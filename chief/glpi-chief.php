<?php
if (!defined('ABSPATH')) exit;

if (!defined('CHIEF_DEBUG')) {
    define('CHIEF_DEBUG', false);
}

require_once __DIR__ . '/../glpi-db-setup.php';
require_once __DIR__ . '/../glpi-icon-map.php';

if (!function_exists('chief_is_manager')) {
    function chief_is_manager(): bool {
        $u = wp_get_current_user();
        if (!$u || !$u->ID) {
            return false;
        }
        $login = isset($u->user_login) ? (string)$u->user_login : '';
        $gid   = (int) get_user_meta($u->ID, 'glpi_user_id', true);
        return ($login === 'vks_m5_local') || ($gid === 2);
    }
}

if (!function_exists('chief_compose_short_name')) {
    function chief_compose_short_name($realname, $firstname): string {
        $realname  = trim((string)$realname);
        $firstname = trim((string)$firstname);
        if ($realname && $firstname) {
            return $realname . ' ' . mb_substr($firstname, 0, 1) . '.';
        }
        if ($realname) return $realname;
        if ($firstname) return $firstname;
        return '';
    }
}

if (!function_exists('chief_get_executors')) {
    function chief_get_executors(): array {
        $users = get_users(['meta_key' => 'glpi_user_id']);
        $out   = [];
        foreach ($users as $u) {
            $gid = (int) get_user_meta($u->ID, 'glpi_user_id', true);
            if ($gid <= 0) continue;
            $out[] = [
                'id'   => $gid,
                'name' => chief_compose_short_name($u->last_name ?? '', $u->first_name ?? ''),
            ];
        }
        return $out;
    }
}

if (!function_exists('chief_autoname')) {
    function chief_autoname($realname, $firstname): string {
        return chief_compose_short_name($realname, $firstname) ?: 'Без исполнителя';
    }
}

if (!function_exists('chief_slugify')) {
    function chief_slugify($text): string {
        $text = (string)$text;
        if (function_exists('transliterator_transliterate')) {
            $text = transliterator_transliterate('Any-Latin; Latin-ASCII', $text);
        }
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/u', '-', $text);
        $text = trim($text, '-');
        if ($text === '') $text = substr(md5((string)$text), 0, 8);
        return $text;
    }
}

if (!function_exists('chief_glpi_cards_shortcode')) {
    function chief_glpi_cards_shortcode($atts): string {
        global $glpi_db;

        $view_as = isset($_GET['view_as']) ? (string) $_GET['view_as'] : '';
        $current_gid = (int) get_user_meta(get_current_user_id(), 'glpi_user_id', true);
        $user = wp_get_current_user();
        $is_manager = ($user && (($user->user_login === 'vks_m5_local') || ($current_gid === 2)));

        $where_assignee = '';
        $branch = 'self';

        if ($is_manager) {
            if ($view_as === 'all') {
                $branch = 'all';
            } elseif (ctype_digit($view_as)) {
                $branch = 'user';
                $where_assignee = $glpi_db->prepare(' AND tu.users_id = %d ', (int)$view_as);
            } elseif ($current_gid > 0) {
                $where_assignee = $glpi_db->prepare(' AND tu.users_id = %d ', $current_gid);
            }
        } else {
            if ($current_gid > 0) {
                $where_assignee = $glpi_db->prepare(' AND tu.users_id = %d ', $current_gid);
            }
        }

        if (CHIEF_DEBUG) {
            error_log('[chief] view_as=' . json_encode($view_as) . '; branch=' . $branch);
        }

        $css_url = plugin_dir_url(__FILE__) . 'glpi-chief.css';
        $js_url  = plugin_dir_url(__FILE__) . 'glpi-chief.js';
        wp_enqueue_style('glpi-chief', $css_url, [], file_exists(__DIR__ . '/glpi-chief.css') ? filemtime(__DIR__ . '/glpi-chief.css') : null);
        wp_enqueue_script('glpi-chief', $js_url, [], file_exists(__DIR__ . '/glpi-chief.js') ? filemtime(__DIR__ . '/glpi-chief.js') : null, true);
        wp_localize_script('glpi-chief', 'glpiChief', [
            'executors' => chief_get_executors(),
            'isManager' => $is_manager ? 1 : 0,
            'viewAs'    => ($view_as === 'all' || ctype_digit($view_as)) ? $view_as : '',
        ]);

        $tickets = [];
        $status_counts = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
        $category_counts = [];
        $category_slugs = [];

        $sql = "SELECT t.id, t.status, t.name, t.content, t.date, t.time_to_resolve,\n" .
               "       tu.users_id AS assignee_id, u.realname, u.firstname,\n" .
               "       c.completename AS category_name, l.completename AS location_name\n" .
               "FROM glpi_tickets t\n" .
               "LEFT JOIN glpi_tickets_users tu ON tu.tickets_id = t.id AND tu.type = 2\n" .
               "LEFT JOIN glpi_users u ON tu.users_id = u.id\n" .
               "LEFT JOIN glpi_itilcategories c ON t.itilcategories_id = c.id\n" .
               "LEFT JOIN glpi_locations l ON t.locations_id = l.id\n" .
               "WHERE t.is_deleted = 0 AND t.status IN (1,2,3,4)" . $where_assignee . "\n" .
               "ORDER BY t.date_mod DESC\n" .
               "LIMIT 500";

        $glpi_db->query('START TRANSACTION');
        $rows = $glpi_db->get_results($sql);
        if ($glpi_db->last_error) {
            $glpi_db->query('ROLLBACK');
            return '<div class="glpi-error">Ошибка базы данных.</div>';
        }
        $glpi_db->query('COMMIT');

        if (!$rows) {
            return '<p>Нет активных заявок.</p>';
        }

        foreach ($rows as $r) {
            $id = (int) $r->id;
            if (!isset($tickets[$id])) {
                $tickets[$id] = [
                    'id'           => $id,
                    'status'       => (int) $r->status,
                    'name'         => (string) $r->name,
                    'content'      => (string) $r->content,
                    'date'         => (string) $r->date,
                    'time_to_resolve' => (string) $r->time_to_resolve,
                    'category'     => (string) $r->category_name,
                    'location'     => (string) $r->location_name,
                    'executors'    => [],
                    'assignee_ids' => [],
                    'author_id'    => 0,
                    'late'         => ($r->time_to_resolve && strtotime($r->time_to_resolve) < time()),
                ];
            }

            $exec_name = chief_autoname($r->realname ?? '', $r->firstname ?? '');
            if ($exec_name && !in_array($exec_name, $tickets[$id]['executors'], true)) {
                $tickets[$id]['executors'][] = $exec_name;
            }
            if ($r->assignee_id !== null && $r->assignee_id !== '') {
                $aid = (int) $r->assignee_id;
                if ($aid && !in_array($aid, $tickets[$id]['assignee_ids'], true)) {
                    $tickets[$id]['assignee_ids'][] = $aid;
                }
            }
        }

        foreach ($tickets as $t) {
            $s = (int) $t['status'];
            if (isset($status_counts[$s])) $status_counts[$s]++;
            $full = (string) $t['category'];
            $parts = preg_split('/\s*>\s*/u', $full);
            $leaf  = trim((string) end($parts));
            if ($leaf === '') $leaf = $full ?: '—';
            if (!isset($category_counts[$leaf])) $category_counts[$leaf] = 0;
            $category_counts[$leaf]++;
            if (!isset($category_slugs[$leaf])) {
                $category_slugs[$leaf] = chief_slugify($leaf);
            }
        }
        if (!empty($category_counts)) {
            uksort($category_counts, function ($a, $b) {
                return strnatcasecmp($a, $b);
            });
        }
        $total_count = array_sum($status_counts);

        $base_file = __DIR__ . '/../gexe-copy.php';
        $tpl = plugin_dir_path($base_file) . 'templates/glpi-cards-template.php';
        if (!file_exists($tpl)) {
            return '<div style="padding:10px;background:#fee;border:1px solid #f99;">Отсутствует шаблон: templates/glpi-cards-template.php</div>';
        }

        $backup = [];
        $keys = ['gexe_tickets','gexe_status_counts','gexe_total_count','gexe_show_all','gexe_category_counts','gexe_category_slugs','gexe_prefetched_comments'];
        foreach ($keys as $k) {
            $backup[$k] = $GLOBALS[$k] ?? null;
        }
        $GLOBALS['gexe_tickets']          = $tickets;
        $GLOBALS['gexe_status_counts']    = $status_counts;
        $GLOBALS['gexe_total_count']      = $total_count;
        $GLOBALS['gexe_show_all']         = false;
        $GLOBALS['gexe_category_counts']  = $category_counts;
        $GLOBALS['gexe_category_slugs']   = $category_slugs;
        $GLOBALS['gexe_prefetched_comments'] = [];

        ob_start();
        include $tpl;
        $html = ob_get_clean();

        foreach ($keys as $k) {
            if ($backup[$k] === null) {
                unset($GLOBALS[$k]);
            } else {
                $GLOBALS[$k] = $backup[$k];
            }
        }

        return $html;
    }
}

if (!shortcode_exists('glpi_cards_chief')) {
    add_shortcode('glpi_cards_chief', 'chief_glpi_cards_shortcode');
}
