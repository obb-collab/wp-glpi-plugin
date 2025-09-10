<?php
/**
 * newmodal/common/sql.php
 * SQL-доступ к БД GLPI для списков и счётчиков (без логов; только информативные ошибки).
 * Подключение вынесено в отдельный PDO, т.к. GLPI на 192.168.100.12 в базе glpi.
 */
if (!defined('ABSPATH')) { exit; }

/**
 * Возвращает singleton PDO для подключения к GLPI.
 * Данные подключения заданы из ТЗ (см. чат), при необходимости можно вынести в опции.
 */
function nm_glpi_pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) { return $pdo; }
    $host = '192.168.100.12';
    $db   = 'glpi';
    $user = 'wp_glpi';
    $pass = 'xapetVD4OWZqw8f';
    $dsn  = "mysql:host={$host};dbname={$db};charset=utf8mb4";
    $opt  = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    try {
        $pdo = new PDO($dsn, $user, $pass, $opt);
        return $pdo;
    } catch (Throwable $e) {
        // Сообщаем во фронт максимально информативно (без логов)
        wp_send_json(['ok'=>false,'message'=>'DB connection error','extra'=>['hint'=>'GLPI DB connect failed','error'=>$e->getMessage()]]);
        exit;
    }
}

/**
 * Чтение карточек заявок (лента).
 * $args: page, per_page, status[], assignee|null, q
 * Возвращает [items[], has_more].
 */
function nm_sql_fetch_cards(array $args) {
    $pdo  = nm_glpi_pdo();
    $page = max(0, intval($args['page'] ?? 0));
    $per  = max(1, min(50, intval($args['per_page'] ?? 20)));
    $off  = $page * $per;
    $status   = array_filter(array_map('intval', (array)($args['status'] ?? [])));
    $assignee = isset($args['assignee']) && $args['assignee'] ? intval($args['assignee']) : null;
    $q = trim((string)($args['q'] ?? ''));

    $where = ["1=1"];
    $bind  = [];
    if ($status) {
        $in = implode(',', array_fill(0, count($status), '?'));
        $where[] = "t.status IN ($in)";
        foreach ($status as $s) { $bind[] = $s; }
    }
    if ($assignee) {
        $where[] = "EXISTS(SELECT 1 FROM glpi_tickets_users tu WHERE tu.tickets_id=t.id AND tu.type=2 AND tu.users_id=?)";
        $bind[] = $assignee;
    }
    if ($q !== '') {
        $where[] = "(t.name LIKE ? OR t.content LIKE ?)";
        $like = '%' . $q . '%';
        $bind[] = $like; $bind[] = $like;
    }

    $sql = "
        SELECT t.id, t.name, t.status, t.priority, t.date, t.date_mod, t.time_to_resolve,
               (SELECT u.users_id FROM glpi_tickets_users u WHERE u.tickets_id=t.id AND u.type=2 ORDER BY u.id DESC LIMIT 1) AS assignee_id
        FROM glpi_tickets t
        WHERE ".implode(' AND ', $where)."
        ORDER BY t.date_mod DESC
        LIMIT ? OFFSET ?
    ";
    $bind2 = array_merge($bind, [$per, $off]);
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind2);
        $rows = $stmt->fetchAll();
        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'id'       => (int)$r['id'],
                'title'    => (string)$r['name'],
                'status'   => (int)$r['status'],
                'priority' => (int)$r['priority'],
                'date'     => (string)$r['date'],
                'date_mod' => (string)$r['date_mod'],
                'ttr'      => (string)($r['time_to_resolve'] ?? ''),
                'assignee' => (int)($r['assignee_id'] ?? 0),
            ];
        }
        $has_more = (count($items) === $per);
        return [$items, $has_more];
    } catch (Throwable $e) {
        wp_send_json(['ok'=>false,'message'=>'db_error','extra'=>['hint'=>'Failed to load cards','error'=>$e->getMessage()]]);
        exit;
    }
}

/**
 * Счётчики по статусам (+ новые, + просроченные).
 * «Новые» = status=1; «Решено» = 6 (по ТЗ).
 */
function nm_sql_counts_by_status(int $glpi_uid = 0, ?int $assignee = null): array {
    $pdo = nm_glpi_pdo();
    $where = ["1=1"];
    $bind  = [];
    if ($assignee) {
        $where[] = "EXISTS(SELECT 1 FROM glpi_tickets_users tu WHERE tu.tickets_id=t.id AND tu.type=2 AND tu.users_id=?)";
        $bind[] = $assignee;
    } elseif ($glpi_uid) {
        // По умолчанию — назначенные на текущего
        $where[] = "EXISTS(SELECT 1 FROM glpi_tickets_users tu WHERE tu.tickets_id=t.id AND tu.type=2 AND tu.users_id=?)";
        $bind[] = $glpi_uid;
    }
    $sql = "
        SELECT t.status, COUNT(*) AS cnt
        FROM glpi_tickets t
        WHERE ".implode(' AND ', $where)."
        GROUP BY t.status
    ";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        $by = [];
        while ($r = $stmt->fetch()) {
            $by[(int)$r['status']] = (int)$r['cnt'];
        }
        // Новые = 1
        $new = $by[1] ?? 0;
        // Просроченные: time_to_resolve < NOW(), статус != 6
        $sql_over = "
            SELECT COUNT(*) AS c FROM glpi_tickets t
            WHERE ".implode(' AND ', $where)."
              AND t.status <> 6
              AND t.time_to_resolve IS NOT NULL
              AND t.time_to_resolve < NOW()
        ";
        $stmt2 = $pdo->prepare($sql_over);
        $stmt2->execute($bind);
        $overdue = (int)($stmt2->fetch()['c'] ?? 0);
        return [
            'by_status' => $by,
            'new'       => $new,
            'overdue'   => $overdue,
            'stop'      => 0,
        ];
    } catch (Throwable $e) {
        wp_send_json(['ok'=>false,'message'=>'db_error','extra'=>['hint'=>'Failed to load counts','error'=>$e->getMessage()]]);
        exit;
    }
}
