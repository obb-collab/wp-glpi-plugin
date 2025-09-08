<?php
/**
 * Standalone "chief" clone of the main GLPI cards shortcode.
 *
 * Registers [glpi_cards_chief] shortcode which renders the ticket list using
 * only assets from the /chief directory. The implementation intentionally does
 * not rely on front‑end pieces of the main plugin to avoid conflicts.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CHIEF_DEBUG')) {
    define('CHIEF_DEBUG', false);
}

require_once dirname(__DIR__) . '/glpi-db-setup.php';
require_once dirname(__DIR__) . '/glpi-icon-map.php';

/** Check if current user is a manager. */
function chief_is_manager(): bool {
    $u = wp_get_current_user();
    if (!$u || !$u->ID) {
        return false;
    }
    $login   = isset($u->user_login) ? (string) $u->user_login : '';
    $glpi_id = (int) get_user_meta($u->ID, 'glpi_user_id', true);
    return ($login === 'vks_m5_local') || ($glpi_id === 2);
}

/** Current user's GLPI ID. */
function chief_current_glpi_id(): int {
    $u = wp_get_current_user();
    if (!$u || !$u->ID) return 0;
    return (int) get_user_meta($u->ID, 'glpi_user_id', true);
}

/** Compose short name for executors. */
function chief_compose_short_name($realname, $firstname): string {
    $realname  = trim((string) $realname);
    $firstname = trim((string) $firstname);
    if ($realname && $firstname) return $realname . ' ' . mb_substr($firstname, 0, 1) . '.';
    if ($realname) return $realname;
    if ($firstname) return $firstname;
    return '';
}

/** List of executors {id, name}. */
function chief_get_assignee_options(): array {
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

/** Autoname executor from real/first names with local overrides. */
function chief_autoname($realname, $firstname): string {
    $realname  = trim((string) $realname);
    $firstname = trim((string) $firstname);
    $joined = mb_strtolower($realname . $firstname);
    switch ($joined) {
        case 'кузнецовен': return 'Кузнецов Е.';
        case 'смирновмо':  return 'Смирнов М.';
    }
    if ($realname && $firstname) return $realname . ' ' . mb_substr($firstname, 0, 1) . '.';
    if ($realname) return $realname;
    if ($firstname) return $firstname;
    return 'Без исполнителя';
}

/** Slugify helper for categories. */
function chief_slugify($text): string {
    $text = (string) $text;
    if (function_exists('transliterator_transliterate')) {
        $text = transliterator_transliterate('Any-Latin; Latin-ASCII', $text);
    }
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/u', '-', $text);
    return trim($text, '-');
}

/** Flag used to enqueue assets only when shortcode is present. */
$GLOBALS['chief_needs_assets'] = false;

/** Enqueue JS/CSS for the chief page. */
function chief_enqueue_assets() {
    if (empty($GLOBALS['chief_needs_assets'])) {
        return;
    }

    $base_dir = dirname(__DIR__);
    $base_url = plugin_dir_url($base_dir . '/gexe-copy.php');
    $css_path = __DIR__ . '/glpi-chief.css';
    $js_path  = __DIR__ . '/glpi-chief.js';
    $css_ver  = file_exists($css_path) ? filemtime($css_path) : null;
    $js_ver   = file_exists($js_path)  ? filemtime($js_path)  : null;

    wp_enqueue_style('glpi-chief', $base_url . 'chief/glpi-chief.css', [], $css_ver);
    wp_enqueue_script('glpi-chief', $base_url . 'chief/glpi-chief.js', [], $js_ver, true);

    $view_as = isset($_GET['view_as']) ? (string) $_GET['view_as'] : '';
    wp_localize_script('glpi-chief', 'glpiChief', [
        'executors' => chief_get_assignee_options(),
        'isManager' => chief_is_manager() ? 1 : 0,
        'viewAs'    => $view_as,
    ]);
}
add_action('wp_enqueue_scripts', 'chief_enqueue_assets');

/** Shortcode handler for [glpi_cards_chief]. */
function chief_glpi_cards_shortcode($atts = []): string {
    global $glpi_db;

    $GLOBALS['chief_needs_assets'] = true; // ensure assets are loaded

    $current_gid = chief_current_glpi_id();
    $is_manager  = chief_is_manager();
    $view_as_raw = isset($_GET['view_as']) ? (string) $_GET['view_as'] : '';

    $where_assignee = '';
    $branch = 'self';

    if ($is_manager) {
        if ($view_as_raw === 'all') {
            $branch = 'all';
        } elseif (ctype_digit($view_as_raw)) {
            $branch = 'user';
            $where_assignee = $glpi_db->prepare(' AND tu.users_id = %d ', (int) $view_as_raw);
        } elseif ($current_gid > 0) {
            $where_assignee = $glpi_db->prepare(' AND tu.users_id = %d ', $current_gid);
        }
    } else {
        if ($current_gid > 0) {
            $where_assignee = $glpi_db->prepare(' AND tu.users_id = %d ', $current_gid);
        }
    }

    if (CHIEF_DEBUG) {
        error_log('[chief] view_as=' . json_encode($view_as_raw) . '; branch=' . $branch);
    }

    $sql = "SELECT t.id, t.status, t.name, t.content, t.date, t.time_to_resolve,\n"
        . "       tu.users_id AS assignee_id, u.realname, u.firstname,\n"
        . "       c.completename AS category_name, l.completename AS location_name\n"
        . "FROM glpi_tickets t\n"
        . "LEFT JOIN glpi_tickets_users tu ON tu.tickets_id = t.id AND tu.type = 2\n"
        . "LEFT JOIN glpi_users u ON tu.users_id = u.id\n"
        . "LEFT JOIN glpi_itilcategories c ON t.itilcategories_id = c.id\n"
        . "LEFT JOIN glpi_locations l ON t.locations_id = l.id\n"
        . "WHERE t.is_deleted = 0 AND t.status IN (1,2,3,4)" . $where_assignee . "\n"
        . "ORDER BY t.date_mod DESC\n"
        . "LIMIT 500";

    $rows = $glpi_db->get_results($sql);
    if (!$rows) {
        return '<p>Нет активных заявок.</p>';
    }

    $tickets = [];
    foreach ($rows as $r) {
        $id = (int) $r->id;
        if (!isset($tickets[$id])) {
            $tickets[$id] = [
                'id'           => $id,
                'status'       => (int) $r->status,
                'name'         => (string) $r->name,
                'content'      => (string) $r->content,
                'date'         => (string) $r->date,
                'category'     => (string) $r->category_name,
                'location'     => (string) $r->location_name,
                'executors'    => [],
                'assignee_ids' => [],
                'late'         => ($r->time_to_resolve && strtotime($r->time_to_resolve) < time()),
            ];
        }

        $exec_name = chief_autoname($r->realname, $r->firstname);
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

    $status_counts = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
    foreach ($tickets as $t) {
        $s = (int) $t['status'];
        if (isset($status_counts[$s])) {
            $status_counts[$s]++;
        }
    }
    $total_count = array_sum($status_counts);

    $category_counts = [];
    $category_slugs  = [];
    foreach ($tickets as $t) {
        $full = (string) $t['category'];
        $parts = preg_split('/\s*>\s*/u', $full);
        $leaf = trim((string) end($parts));
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

    $tpl = plugin_dir_path(__DIR__ . '/gexe-copy.php') . 'templates/glpi-cards-template.php';
    if (!file_exists($tpl)) {
        return '<div style="padding:10px;background:#fee;border:1px solid #f99;">Отсутствует шаблон: templates/glpi-cards-template.php</div>';
    }

    // Preserve existing globals from the main shortcode to avoid conflicts.
    $globals = ['gexe_tickets','gexe_status_counts','gexe_total_count','gexe_show_all','gexe_category_counts','gexe_category_slugs','gexe_prefetched_comments'];
    $backup = [];
    foreach ($globals as $g) {
        if (isset($GLOBALS[$g])) {
            $backup[$g] = $GLOBALS[$g];
        }
    }

    $GLOBALS['gexe_tickets']             = $tickets;
    $GLOBALS['gexe_status_counts']       = $status_counts;
    $GLOBALS['gexe_total_count']         = $total_count;
    $GLOBALS['gexe_show_all']            = ($is_manager && $view_as_raw === 'all');
    $GLOBALS['gexe_category_counts']     = $category_counts;
    $GLOBALS['gexe_category_slugs']      = $category_slugs;
    $GLOBALS['gexe_prefetched_comments'] = [];

    ob_start();
    include $tpl;
    $html = ob_get_clean();

    foreach ($globals as $g) {
        if (array_key_exists($g, $backup)) {
            $GLOBALS[$g] = $backup[$g];
        } else {
            unset($GLOBALS[$g]);
        }
    }

    return $html;
}

add_shortcode('glpi_cards_chief', 'chief_glpi_cards_shortcode');

