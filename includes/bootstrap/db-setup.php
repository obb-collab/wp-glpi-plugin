<?php
if (!defined('ABSPATH')) exit;

global $glpi_db;
if (!isset($glpi_db) || !($glpi_db instanceof wpdb)) {
    $glpi_db = new wpdb(
        'wp_glpi',            // db user
        'xapetVD4OWZqw8f',    // db password
        'glpi',               // db name
        '192.168.100.12'      // db host
    );
}

if (!defined('WP_GLPI_DEBUG')) {
    define('WP_GLPI_DEBUG', false);
}

// Entity filtering mode: 'profiles', 'user_fallback' or 'all'
if (!defined('WP_GLPI_ENTITY_MODE')) {
    define('WP_GLPI_ENTITY_MODE', 'user_fallback');
}

// Whether category/location dictionaries should respect entity restrictions.
if (!defined('WP_GLPI_FILTER_CATALOGS_BY_ENTITY')) {
    define('WP_GLPI_FILTER_CATALOGS_BY_ENTITY', false);
}

function glpi_get_pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = 'mysql:host=192.168.100.12;dbname=glpi;charset=utf8mb4';
    $pdo = new PDO($dsn, 'wp_glpi', 'xapetVD4OWZqw8f', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 5,
    ]);
    return $pdo;
}

if (!function_exists('wp_glpi_get_connection')) {
    function wp_glpi_get_connection(): PDO {
        return glpi_get_pdo();
    }
}

define('GEXE_TRIGGERS_VERSION', '2');

define('GEXE_GLPI_API_URL', 'http://192.168.100.12/glpi/apirest.php');
define('GEXE_GLPI_APP_TOKEN', 'nqubXrD6j55bgLRuD1mrrtz5D69cXz94HHPvgmac');
// Legacy single user token (kept for backward compatibility with existing callers).
define('GEXE_GLPI_USER_TOKEN', '8ffMQJvkcgi8V5OMWrh89Xvr97jEzK4ddrkdL6pw');

/**
 * Registry of personal GLPI user tokens.
 * Mapping is strictly by numeric IDs; display names are only in comments.
 *
 * Columns (from user's table):
 *  - wp_user_id (WordPress)
 *  - glpi_user_id (GLPI)
 *  - token (GLPI personal token)
 */
function gexe_glpi_token_registry(): array {
    static $REG = null;
    if ($REG !== null) return $REG;
    $REG = [
        // Куткин П.;  WP=1;  GLPI=2
        ['wp_user_id' => 1,  'glpi_user_id' => 2,   'token' => '8ffMQJvkcgi8V5OMWrh89Xvr97jEzK4ddrkdL6pw'],
        // Скомороха А.; WP=4;  GLPI=621
        ['wp_user_id' => 4,  'glpi_user_id' => 621, 'token' => 'VMgcyxmkHWAGXASOF0yj1eFZTrHmMGU4ynDBcGjU'],
        // Смирнов М.;  WP=5;  GLPI=269
        ['wp_user_id' => 5,  'glpi_user_id' => 269, 'token' => 'CkhkElVbncwXEEaIpMeh8iiiDu8mlcMY0QkWeeYK'],
        // Сушко В.;    WP=6;  GLPI=622
        ['wp_user_id' => 6,  'glpi_user_id' => 622, 'token' => 'LjmZ3pLcuXo4KgUbbarGgif0CNQkGr7GXHvkZMHw'],
        // Кузнецов Е.; WP=7;  GLPI=180
        ['wp_user_id' => 7,  'glpi_user_id' => 180, 'token' => 'IjZXRUKUXKSXaKzgJoavf1tfWNYTnMsACLm96Mkz'],
        // Нечепорук А.; WP=8;  GLPI=620
        ['wp_user_id' => 8,  'glpi_user_id' => 620, 'token' => '25GEMIsIf8etMerlsPlmpsRc2P4NTz19qwf6Pvgb'],
        // Стельмашенко И.; WP=10; GLPI=632
        ['wp_user_id' => 10, 'glpi_user_id' => 632, 'token' => 'bBh4kSmjkNeHrw1mNEeLNOt2Nkmekceen0bn1O1i'],
    ];
    return $REG;
}

/**
 * Token lookup by WordPress user id.
 */
function gexe_glpi_get_token_by_wp_user_id(int $wp_user_id): ?string {
    foreach (gexe_glpi_token_registry() as $row) {
        if ((int)$row['wp_user_id'] === $wp_user_id) {
            return (string)$row['token'];
        }
    }
    return null;
}

/**
 * Token lookup by GLPI user id.
 */
function gexe_glpi_get_token_by_glpi_user_id(int $glpi_user_id): ?string {
    foreach (gexe_glpi_token_registry() as $row) {
        if ((int)$row['glpi_user_id'] === $glpi_user_id) {
            return (string)$row['token'];
        }
    }
    return null;
}

/**
 * Resolve current user's token:
 *  1) Try direct WP user id mapping.
 *  2) Try GLPI user id from existing user meta ('glpi_user_id' or 'gexe_glpi_user_id').
 *  3) Fallback to legacy GEXE_GLPI_USER_TOKEN.
 */
function gexe_glpi_get_current_user_token(): ?string {
    $uid = function_exists('get_current_user_id') ? (int)get_current_user_id() : 0;
    if ($uid > 0) {
        $tok = gexe_glpi_get_token_by_wp_user_id($uid);
        if (is_string($tok) && $tok !== '') return $tok;
    }
    if ($uid > 0 && function_exists('get_user_meta')) {
        foreach (['glpi_user_id', 'gexe_glpi_user_id'] as $meta_key) {
            $val = get_user_meta($uid, $meta_key, true);
            if (is_numeric($val)) {
                $tok = gexe_glpi_get_token_by_glpi_user_id((int)$val);
                if (is_string($tok) && $tok !== '') return $tok;
            }
        }
    }
    return defined('GEXE_GLPI_USER_TOKEN') ? GEXE_GLPI_USER_TOKEN : null;
}

/**
 * Build API headers for a specific token (helper).
 */
function gexe_glpi_api_headers_for_token(string $user_token, array $extra = []): array {
    $base = [
        'Content-Type'  => 'application/json',
        'Authorization' => 'user_token ' . $user_token,
        'App-Token'     => GEXE_GLPI_APP_TOKEN,
    ];
    return array_merge($base, $extra);
}

/**
 * Build API headers for current WP user using per-user tokens.
 * This is non-breaking – existing gexe_glpi_api_headers() stays intact.
 */
function gexe_glpi_api_headers_current(array $extra = []): array {
    $tok = gexe_glpi_get_current_user_token();
    return gexe_glpi_api_headers_for_token($tok ?: GEXE_GLPI_USER_TOKEN, $extra);
}

function gexe_glpi_api_url(): string {
    return rtrim(GEXE_GLPI_API_URL, '/');
}

function gexe_glpi_api_headers(array $extra = []): array {
    // Use per-user token if available; fallback to legacy constant.
    $tok = function_exists('gexe_glpi_get_current_user_token')
        ? gexe_glpi_get_current_user_token()
        : null;
    if (!$tok || $tok === '') {
        $tok = defined('GEXE_GLPI_USER_TOKEN') ? GEXE_GLPI_USER_TOKEN : '';
    }
    $base = [
        'Content-Type'  => 'application/json',
        'Authorization' => 'user_token ' . $tok,
        'App-Token'     => GEXE_GLPI_APP_TOKEN,
    ];
    return array_merge($base, $extra);
}

function gexe_glpi_triggers_present() {
    global $glpi_db;
    $sql = "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='glpi' AND TRIGGER_NAME IN ('glpi_followups_ai','glpi_followups_ad')";
    return (int)$glpi_db->get_var($sql) === 2;
}

/** Whether followups_count column is available. */
function gexe_glpi_use_followups_count() {
    static $cached = null;
    if ($cached !== null) return $cached;
    $opt = get_option('glpi_use_followups_count');
    if ($opt !== false) {
        $cached = ((int)$opt === 1);
        return $cached;
    }
    global $glpi_db;
    $col = $glpi_db->get_var("SHOW COLUMNS FROM glpi.glpi_tickets LIKE 'followups_count'");
    $cached = (bool)$col;
    update_option('glpi_use_followups_count', $cached ? 1 : 0);
    return $cached;
}

function gexe_glpi_install_triggers($force = false) {
    global $glpi_db;

    if (!$force && get_option('glpi_triggers_version') === GEXE_TRIGGERS_VERSION) {
        return;
    }

    $existing = gexe_glpi_triggers_present();

    $grants = $glpi_db->get_col('SHOW GRANTS');
    $has_trigger = false;
    $has_alter   = false;
    if ($grants) {
        foreach ($grants as $g) {
            if (preg_match('~GRANT (ALL PRIVILEGES|.*TRIGGER.*) ON `?glpi`?\.\\*~i', $g)) {
                $has_trigger = true;
            }
            if (preg_match('~GRANT (ALL PRIVILEGES|.*ALTER.*) ON `?glpi`?\.\\*~i', $g)) {
                $has_alter = true;
            }
        }
    }
    if (!$has_trigger) {
        error_log('gexe/triggers: missing TRIGGER privilege on glpi schema');
        update_option('glpi_triggers_version', GEXE_TRIGGERS_VERSION);
        update_option('glpi_use_followups_count', 0);
        return;
    }

    $glpi_db->query('SET sql_notes=0');
    $glpi_db->query('START TRANSACTION');
    $ok = true;

    $col = $glpi_db->get_var("SHOW COLUMNS FROM glpi.glpi_tickets LIKE 'last_followup_at'");
    if (!$col) {
        $glpi_db->query("ALTER TABLE glpi.glpi_tickets ADD COLUMN last_followup_at DATETIME NULL AFTER date_mod");
        if ($glpi_db->last_error) $ok = false;
        if ($ok) {
            $glpi_db->query("UPDATE glpi.glpi_tickets t LEFT JOIN (SELECT items_id, MAX(date) AS d FROM glpi.glpi_itilfollowups WHERE itemtype='Ticket' GROUP BY items_id) f ON t.id = f.items_id SET t.last_followup_at = f.d");
            if ($glpi_db->last_error) $ok = false;
        }
    }

    $use_counter = (bool)$glpi_db->get_var("SHOW COLUMNS FROM glpi.glpi_tickets LIKE 'followups_count'");
    if (!$use_counter && $has_alter && $ok) {
        $glpi_db->query("ALTER TABLE glpi.glpi_tickets ADD COLUMN followups_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_followup_at");
        if ($glpi_db->last_error) {
            $ok = false;
        } else {
            $glpi_db->query("UPDATE glpi.glpi_tickets t LEFT JOIN (SELECT items_id, COUNT(*) c FROM glpi.glpi_itilfollowups WHERE itemtype='Ticket' GROUP BY items_id) f ON f.items_id = t.id SET t.followups_count = COALESCE(f.c,0)");
            if ($glpi_db->last_error) $ok = false; else $use_counter = true;
        }
    }
    if (!$use_counter && !$has_alter) {
        error_log('gexe/triggers: no ALTER privilege, falling back to COUNT(*)');
    }
    if ($use_counter && $ok) {
        $idx = $glpi_db->get_var("SHOW INDEX FROM glpi.glpi_itilfollowups WHERE Key_name='idx_followups_item'");
        if (!$idx) {
            $glpi_db->query("CREATE INDEX idx_followups_item ON glpi.glpi_itilfollowups (itemtype, items_id)");
            if ($glpi_db->last_error) $ok = false;
        }
    }

    if ($ok) {
        if ($use_counter) {
            $glpi_db->query("CREATE OR REPLACE TRIGGER glpi.glpi_followups_ai AFTER INSERT ON glpi.glpi_itilfollowups FOR EACH ROW BEGIN IF NEW.itemtype='Ticket' THEN UPDATE glpi.glpi_tickets SET last_followup_at = NEW.date, followups_count = followups_count + 1 WHERE id = NEW.items_id; END IF; END;");
        } else {
            $glpi_db->query("CREATE OR REPLACE TRIGGER glpi.glpi_followups_ai AFTER INSERT ON glpi.glpi_itilfollowups FOR EACH ROW BEGIN IF NEW.itemtype='Ticket' THEN UPDATE glpi.glpi_tickets SET last_followup_at = NEW.date WHERE id = NEW.items_id; END IF; END;");
        }
        if ($glpi_db->last_error) $ok = false;
    }
    if ($ok) {
        if ($use_counter) {
            $glpi_db->query("CREATE OR REPLACE TRIGGER glpi.glpi_followups_ad AFTER DELETE ON glpi.glpi_itilfollowups FOR EACH ROW BEGIN IF OLD.itemtype='Ticket' THEN UPDATE glpi.glpi_tickets t SET last_followup_at = (SELECT MAX(f.date) FROM glpi.glpi_itilfollowups f WHERE f.itemtype='Ticket' AND f.items_id = t.id), followups_count = (SELECT COUNT(*) FROM glpi.glpi_itilfollowups f WHERE f.itemtype='Ticket' AND f.items_id = t.id) WHERE t.id = OLD.items_id; END IF; END;");
        } else {
            $glpi_db->query("CREATE OR REPLACE TRIGGER glpi.glpi_followups_ad AFTER DELETE ON glpi.glpi_itilfollowups FOR EACH ROW BEGIN IF OLD.itemtype='Ticket' THEN UPDATE glpi.glpi_tickets t SET last_followup_at = (SELECT MAX(f.date) FROM glpi.glpi_itilfollowups f WHERE f.itemtype='Ticket' AND f.items_id = t.id) WHERE t.id = OLD.items_id; END IF; END;");
        }
        if ($glpi_db->last_error) $ok = false;
    }

    if ($ok) {
        $glpi_db->query('COMMIT');
        update_option('glpi_triggers_installed', time());
        update_option('glpi_triggers_version', GEXE_TRIGGERS_VERSION);
        update_option('glpi_use_followups_count', $use_counter ? 1 : 0);
        error_log('gexe/triggers: installation completed');
    } else {
        $glpi_db->query('ROLLBACK');
        error_log('gexe/triggers: install failed: ' . $glpi_db->last_error);
        update_option('glpi_triggers_version', GEXE_TRIGGERS_VERSION);
        update_option('glpi_use_followups_count', $use_counter ? 1 : 0);
    }
}

function gexe_glpi_remove_triggers() {
    global $glpi_db;
    $glpi_db->query('SET sql_notes=0');
    $glpi_db->query('DROP TRIGGER IF EXISTS glpi.glpi_followups_ai');
    $glpi_db->query('DROP TRIGGER IF EXISTS glpi.glpi_followups_ad');
    delete_option('glpi_triggers_installed');
    delete_option('glpi_triggers_version');
}

function gexe_glpi_triggers_status() {
    global $glpi_db;
    return $glpi_db->get_results("SELECT TRIGGER_NAME, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='glpi' AND TRIGGER_NAME IN ('glpi_followups_ai','glpi_followups_ad')");
}

/**
 * Insert a followup for a ticket.
 *
 * @param int    $ticket_id
 * @param int    $glpi_user_id
 * @param string $content
 * @return array{ok:bool,code?:string,msg?:string,followup?:array}
 */
function sql_insert_followup($ticket_id, $glpi_user_id, $content) {
    global $glpi_db;

    $ticket_id    = (int) $ticket_id;
    $glpi_user_id = (int) $glpi_user_id;
    $content      = trim((string) $content);
    if ($ticket_id <= 0 || $glpi_user_id <= 0 || $content === '') {
        return ['ok' => false, 'code' => 'validation'];
    }

    $sql = $glpi_db->prepare(
        "INSERT INTO glpi_itilfollowups (items_id,itemtype,users_id,date,content,is_private) VALUES (%d,'Ticket',%d,NOW(),%s,0)",
        $ticket_id,
        $glpi_user_id,
        $content
    );
    if (!$glpi_db->query($sql)) {
        $err = $glpi_db->last_error;
        return ['ok' => false, 'code' => 'sql_error', 'msg' => $err];
    }
    $fid = (int) $glpi_db->insert_id;
    return [
        'ok'       => true,
        'followup' => [
            'id'       => $fid,
            'items_id' => $ticket_id,
            'users_id' => $glpi_user_id,
            'content'  => $content,
            'date'     => date('c'),
        ],
    ];
}

/**
 * Fetch list of helpdesk-visible categories.
 *
 * @return array{ok:bool,code?:string,which?:string,list?:array<int,array{id:int,name:string}>}
 */
function glpi_db_get_categories($entity_id = 0) {
    global $glpi_db;

    $sql = "SELECT c.id, c.name, c.completename, c.level, c.ancestors_cache\n"
         . "FROM glpi_itilcategories c\n"
         . "WHERE c.is_helpdeskvisible = 1";

    if ($entity_id > 0) {
        $sql .= $glpi_db->prepare(" AND (c.entities_id = %d OR c.is_recursive = 1)", $entity_id);
    }

    $sql .= "\nORDER BY c.completename ASC";

    $rows = $glpi_db->get_results($sql, ARRAY_A);
    if ($glpi_db->last_error) {
        return ['ok' => false, 'code' => 'dict_failed', 'which' => 'categories'];
    }
    if (!$rows) {
        return ['ok' => false, 'code' => 'dict_empty', 'which' => 'categories'];
    }

    $list = array_map(function ($r) {
        return [
            'id'              => (int) ($r['id'] ?? 0),
            'name'            => $r['name'] ?? '',
            'completename'    => $r['completename'] ?? '',
            'level'           => isset($r['level']) ? (int) $r['level'] : null,
            'ancestors_cache' => $r['ancestors_cache'] ?? '',
        ];
    }, $rows);

    return ['ok' => true, 'code' => 'ok', 'list' => $list];
}

/**
 * Fetch list of available locations.
 *
 * @return array{ok:bool,code?:string,which?:string,list?:array}
 */
function glpi_db_get_locations() {
    global $glpi_db;

    $sql = "SELECT l.id, l.name, l.completename\n"
         . "FROM glpi_locations l\n"
         . "WHERE l.is_deleted = 0\n"
         . "ORDER BY l.completename ASC";

    $rows = $glpi_db->get_results($sql, ARRAY_A);
    if ($glpi_db->last_error) {
        return ['ok' => false, 'code' => 'dict_failed', 'which' => 'locations'];
    }
    if (!$rows) {
        return ['ok' => false, 'code' => 'dict_empty', 'which' => 'locations'];
    }

    $list = array_map(function ($r) {
        return [
            'id'           => (int) ($r['id'] ?? 0),
            'name'         => $r['name'] ?? '',
            'completename' => $r['completename'] ?? '',
        ];
    }, $rows);

    return ['ok' => true, 'code' => 'ok', 'list' => $list];
}

/**
 * Fetch list of executors (users).
 *
 * @return array{ok:bool,code?:string,which?:string,list?:array}
 */

/**
 * Create ticket transaction.
 *
 * @param array $payload
 * @return array{ok:bool,code?:string,msg?:string,ticket_id?:int,assigned?:int|null,message?:string}
 */
function glpi_db_create_ticket(array $payload) {
    global $glpi_db;

    $name   = trim((string)($payload['name'] ?? ''));
    $desc   = trim((string)($payload['content'] ?? ''));
    $cat    = (int)($payload['category_id'] ?? 0);
    $loc    = (int)($payload['location_id'] ?? 0);
    $author = (int)($payload['requester_id'] ?? 0);
    $exec   = (int)($payload['executor_glpi_id'] ?? 0);
    $assign_me = !empty($payload['assign_me']);

    if ($name === '' || mb_strlen($name) > 255) {
        return ['ok' => false, 'code' => 'validation', 'field' => 'name'];
    }
    if ($desc === '' || mb_strlen($desc) > 20000) {
        return ['ok' => false, 'code' => 'validation', 'field' => 'content'];
    }
    if ($cat <= 0 || $author <= 0) {
        return ['ok' => false, 'code' => 'validation'];
    }

    $is_leaf = (int)$glpi_db->get_var($glpi_db->prepare(
        "SELECT COUNT(*) FROM glpi_itilcategories c WHERE c.id=%d AND c.is_helpdeskvisible=1 AND NOT EXISTS (SELECT 1 FROM glpi_itilcategories ch WHERE ch.completename LIKE CONCAT(c.completename, ' > %%'))",
        $cat
    ));
    if (!$is_leaf) {
        return ['ok' => false, 'code' => 'invalid_category'];
    }

    if ($loc > 0) {
        $loc_leaf = (int)$glpi_db->get_var($glpi_db->prepare(
            "SELECT COUNT(*) FROM glpi_locations l WHERE l.id=%d AND l.is_deleted=0 AND NOT EXISTS (SELECT 1 FROM glpi_locations ch WHERE ch.is_deleted=0 AND ch.completename LIKE CONCAT(l.completename, ' > %%'))",
            $loc
        ));
        if (!$loc_leaf) {
            return ['ok' => false, 'code' => 'invalid_location'];
        }
    } else {
        $loc = null;
    }

    // Определить назначенного исполнителя (как было в SQL-версии).
    $assigned = $assign_me ? $author : $exec;
    $user_row = $glpi_db->get_row($glpi_db->prepare(
        'SELECT id, entities_id FROM glpi_users WHERE id=%d AND is_deleted=0',
        $assigned
    ), ARRAY_A);
    if (!$user_row) {
        return ['ok' => false, 'code' => 'invalid_executor'];
    }
    $entities_id = (int)$user_row['entities_id'];

    $tz = wp_timezone();
    $now = new DateTime('now', $tz);
    $due = clone $now;
    if ((int)$now->format('H') > 18 || ((int)$now->format('H') === 18 && (int)$now->format('i') > 0)) {
        $due->modify('+1 day');
    }
    $due->setTime(18, 0, 0);
    $due_str = $due->format('Y-m-d H:i:s');

    /**
     * -------- NEW: create via GLPI REST API --------
     * Поля соответствуют прежней SQL-логике.
     */
    $endpoint = gexe_glpi_api_url() . '/Ticket';
    $input = [
        'name'                 => $name,
        'content'              => $desc,
        'status'               => 1,
        'itilcategories_id'    => $cat,
        'locations_id'         => ($loc > 0 ? $loc : null),
        'entities_id'          => $entities_id,
        '_users_id_requester'  => $author,
        '_users_id_assign'     => $assigned,
        'due_date'             => $due_str,
    ];

    // Анти-дубликат: лёгкая проверка перед API-вставкой (как было).
    $dup_id = $glpi_db->get_var($glpi_db->prepare(
        'SELECT id FROM glpi_tickets WHERE users_id_recipient=%d AND name=%s AND content=%s AND TIMESTAMPDIFF(SECOND,date,NOW())<=300 LIMIT 1',
        $author, $name, $desc
    ));
    if ($dup_id) {
        return ['ok' => true, 'ticket_id' => (int)$dup_id, 'message' => 'already_exists'];
    }

    $args = [
        'headers' => gexe_glpi_api_headers_current(),
        'timeout' => 10,
        'body'    => wp_json_encode(['input' => $input], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
    $resp = wp_remote_post($endpoint, $args);
    if (is_wp_error($resp)) {
        return ['ok' => false, 'code' => 'api_network', 'msg' => $resp->get_error_message()];
    }
    $code = (int) wp_remote_retrieve_response_code($resp);
    $body = wp_remote_retrieve_body($resp);
    $json = json_decode($body, true);
    if ($code === 201 && is_array($json) && !empty($json['id'])) {
        $ticket_id = (int)$json['id'];
        // Добавим приватный followup «Создано через WordPress» (как раньше), неблокирующе.
        $fu = [
            'itemtype'  => 'Ticket',
            'items_id'  => $ticket_id,
            'content'   => 'Создано через WordPress',
            'is_private'=> 1,
            'users_id'  => $author,
        ];
        $fu_args = [
            'headers' => gexe_glpi_api_headers_current(),
            'timeout' => 6,
            'body'    => wp_json_encode(['input' => $fu], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        $fu_ep = gexe_glpi_api_url() . '/ITILFollowup';
        $fu_resp = wp_remote_post($fu_ep, $fu_args);
        // Ошибку followup не считаем фатальной: продолжаем, даже если не удалось.
        return ['ok' => true, 'ticket_id' => $ticket_id, 'message' => 'created'];
    }
    // Диагностика кода ответа
    if ($code === 401 || $code === 403) {
        return ['ok' => false, 'code' => 'api_auth', 'msg' => 'GLPI auth denied'];
    }
    if ($code >= 500) {
        return ['ok' => false, 'code' => 'api_server', 'msg' => 'GLPI server error'];
    }
    return ['ok' => false, 'code' => 'api_failed', 'msg' => (string)$body];

    // --- END REST path ---
}


/**
 * Load status IDs from glpi_itilstatuses and map common names.
 *
 * @return array{work:int,planned:int,onhold:int,resolved:int}
 */
function gexe_glpi_status_map() {
    static $cache = null;
    if ($cache !== null) return $cache;
    global $glpi_db;
    $cache = ['work' => 2, 'planned' => 3, 'onhold' => 4, 'resolved' => 6];
    $rows = $glpi_db->get_results('SELECT id,name FROM glpi_itilstatuses', ARRAY_A);
    if ($rows) {
        $names = [];
        foreach ($rows as $r) {
            $names[mb_strtolower(trim($r['name']))] = (int) $r['id'];
        }
        $cache['work'] = $names['в работе'] ?? $names['in progress'] ?? $cache['work'];
        $cache['planned'] = $names['в плане'] ?? $names['planned'] ?? $cache['planned'];
        $cache['onhold'] = $names['в стопе'] ?? $names['on hold'] ?? $names['ожидание'] ?? $names['等待'] ?? $cache['onhold'];
        $cache['resolved'] = $names['решено'] ?? $names['resolved'] ?? $names['solved'] ?? $cache['resolved'];
    }
    return $cache;
}

/**
 * Change ticket status and insert followup.
 *
 * @param int $ticket_id
 * @param int $glpi_user_id
 * @param int $status_id
 * @param string $followup_text
 * @return array{ok:bool,code?:string,msg?:string,extra?:array}
 */
function sql_ticket_set_status($ticket_id, $glpi_user_id, $status_id, $followup_text) {
    global $glpi_db;

    $ticket_id    = (int) $ticket_id;
    $glpi_user_id = (int) $glpi_user_id;
    $status_id    = (int) $status_id;
    $followup_text = (string) $followup_text;
    if ($ticket_id <= 0 || $glpi_user_id <= 0 || $status_id <= 0) {
        return ['ok' => false, 'code' => 'validation'];
    }

    $glpi_db->query('START TRANSACTION');

    $has = $glpi_db->get_var($glpi_db->prepare(
        'SELECT 1 FROM glpi_tickets_users WHERE tickets_id=%d AND users_id=%d AND type IN (2) FOR UPDATE',
        $ticket_id,
        $glpi_user_id
    ));
    if (!$has) {
        $glpi_db->query('ROLLBACK');
        return ['ok' => false, 'code' => 'no_rights'];
    }

    $status = $glpi_db->get_var($glpi_db->prepare('SELECT status FROM glpi_tickets WHERE id=%d FOR UPDATE', $ticket_id));
    if ($status === null) {
        $glpi_db->query('ROLLBACK');
        return ['ok' => false, 'code' => 'not_found'];
    }
    if ((int) $status === $status_id) {
        $glpi_db->query('ROLLBACK');
        return ['ok' => false, 'code' => 'already_done', 'extra' => ['status' => $status_id]];
    }

    $sql = $glpi_db->prepare('UPDATE glpi_tickets SET status=%d WHERE id=%d', $status_id, $ticket_id);
    if (!$glpi_db->query($sql)) {
        $err = $glpi_db->last_error;
        $glpi_db->query('ROLLBACK');
        return ['ok' => false, 'code' => 'sql_error', 'msg' => $err];
    }

    $sql = $glpi_db->prepare(
        "INSERT INTO glpi_itilfollowups (items_id,itemtype,users_id,date,content,is_private) VALUES (%d,'Ticket',%d,NOW(),%s,0)",
        $ticket_id,
        $glpi_user_id,
        $followup_text
    );
    if (!$glpi_db->query($sql)) {
        $err = $glpi_db->last_error;
        $glpi_db->query('ROLLBACK');
        return ['ok' => false, 'code' => 'sql_error', 'msg' => $err];
    }
    $fid  = (int) $glpi_db->insert_id;
    $date = $glpi_db->get_var($glpi_db->prepare('SELECT date FROM glpi_itilfollowups WHERE id=%d', $fid));

    $glpi_db->query('COMMIT');
    return [
        'ok'    => true,
        'extra' => [
            'status'   => $status_id,
            'followup' => [
                'id'      => $fid,
                'content' => $followup_text,
                'date'    => $date,
            ],
        ],
    ];
}

/**
 * Resolve ticket by setting status to 6 and adding a followup.
 *
 * Similar to a regular followup insertion but checks current status and
 * returns `already_done` when the ticket is already resolved. The followup
 * text is fixed to "Заявка решена".
 *
 * @param int $ticket_id
 * @param int $glpi_user_id
 * @return array{ok:bool,code?:string,msg?:string,extra?:array}
 */
function sql_ticket_resolve($ticket_id, $glpi_user_id) {
    $map = gexe_glpi_status_map();
    return sql_ticket_set_status($ticket_id, $glpi_user_id, $map['resolved'], 'Заявка решена');
}

if (defined('WP_CLI') && WP_CLI) {
    class Gexe_Triggers_CLI {
        public function install() {
            gexe_glpi_install_triggers(true);
            if (gexe_glpi_triggers_present()) {
                WP_CLI::success('Triggers installed');
            } else {
                WP_CLI::error('Trigger installation failed');
            }
        }
        public function remove() {
            gexe_glpi_remove_triggers();
            WP_CLI::success('Triggers removed');
        }
        public function status() {
            $rows = gexe_glpi_triggers_status();
            if (!$rows) {
                WP_CLI::line('No triggers found');
                return;
            }
            foreach ($rows as $r) {
                WP_CLI::line(sprintf('%s: %s %s', $r->TRIGGER_NAME, $r->ACTION_TIMING, $r->EVENT_MANIPULATION));
                WP_CLI::line($r->ACTION_STATEMENT);
            }
        }
    }
    WP_CLI::add_command('gexe:triggers', 'Gexe_Triggers_CLI');
}
