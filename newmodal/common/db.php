<?php
/**
 * nm_* SQL операции для GLPI, с подготовленными выражениями и транзакциями.
 * Автор действий — сопоставленный GLPI-исполнитель (если явно не задан).
 */
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../../glpi-db-setup.php';
require_once __DIR__ . '/../../glpi-utils.php';
require_once __DIR__ . '/../../includes/glpi-sql.php';
require_once __DIR__ . '/helpers.php';

/**
 * Определить GLPI-пользователя для текущего WP-пользователя.
 */
function nm_current_glpi_user_id(): int {
    $u = wp_get_current_user();
    if (!$u || !$u->ID) {
        throw new RuntimeException('User is not authenticated.');
    }
    $map = gexe_resolve_glpi_mapping($u->ID);
    $gid = (int)($map['glpi_user_id'] ?? 0);
    if ($gid <= 0) {
        throw new RuntimeException('Current user is not linked to GLPI user.');
    }
    return $gid;
}

/**
 * Добавить комментарий (followup) через SQL.
 */
function nm_sql_add_followup(int $ticket_id, string $content, ?int $author_id = null): array {
    $author = $author_id ?: nm_current_glpi_user_id();
    return gexe_add_followup_sql($ticket_id, $content, $author);
}

/**
 * Обновить статус заявки через SQL.
 */
function nm_sql_update_status(int $ticket_id, int $status): array {
    return gexe_update_ticket_status_sql($ticket_id, $status);
}

/**
 * Назначить исполнителя (glpi_tickets_users) через SQL,
 * в транзакции: снимаем прежние назначения "Ассигнед", ставим новое.
 */
function nm_sql_assign_user(int $ticket_id, int $assignee_glpi_id): array {
    global $glpi_db;
    $ticket_id = (int)$ticket_id;
    $assignee_glpi_id = (int)$assignee_glpi_id;
    if ($ticket_id <= 0 || $assignee_glpi_id <= 0) {
        return ['ok'=>false,'code'=>'bad_request','message'=>'Invalid ticket/assignee'];
    }
    $pdo = glpi_get_pdo();
    try {
        $pdo->beginTransaction();
        // role = 2 (assigned) — очистить старые
        $pdo->prepare('DELETE FROM glpi_tickets_users WHERE tickets_id = ? AND type = 2')->execute([$ticket_id]);
        // вставить новое назначение
        $stmt = $pdo->prepare('INSERT INTO glpi_tickets_users (tickets_id, users_id, type) VALUES (?, ?, 2)');
        $stmt->execute([$ticket_id, $assignee_glpi_id]);
        $pdo->commit();
        return ['ok'=>true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok'=>false,'code'=>'sql_error','message'=>$e->getMessage()];
    }
}

/**
 * Список заявок для текущего исполнителя.
 * Возвращает массив упрощённых карточек:
 *  - id, name, status, date, date_mod
 * Фильтрация по тексту ($q) — по id (#123), по части name/content.
 */
function nm_sql_list_tickets_for_current(int $limit = 25, int $offset = 0, string $q = ''): array {
    $assignee = nm_current_glpi_user_id();
    $pdo = glpi_get_pdo();

    $where = 'tu.users_id = :uid AND tu.type = 2';
    $params = [':uid' => $assignee];

    // поиск по "#id" или тексту
    if ($q !== '') {
        if (preg_match('~^\s*#?(\d+)\s*$~u', $q, $m)) {
            $where .= ' AND t.id = :id';
            $params[':id'] = (int)$m[1];
        } else {
            $where .= ' AND (t.name LIKE :q OR t.content LIKE :q)';
            $params[':q'] = '%'.$q.'%';
        }
    }

    $sql = "
        SELECT t.id, t.name, t.status, t.date, t.date_mod
        FROM glpi_tickets t
        INNER JOIN glpi_tickets_users tu ON tu.tickets_id = t.id
        WHERE $where
        ORDER BY t.date_mod DESC
        LIMIT :lim OFFSET :off
    ";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Создать новую заявку через SQL, с назначением и сроком.
 * $payload: name, content, category, location, due (Y-m-d H:i:s), assignee
 */
function nm_sql_create_ticket(array $payload): array {
    $name     = trim((string)($payload['name'] ?? ''));
    $content  = trim((string)($payload['content'] ?? ''));
    $cat      = (int)($payload['category'] ?? 0);
    $loc      = (int)($payload['location'] ?? 0);
    $due      = trim((string)($payload['due'] ?? ''));
    $assignee = (int)($payload['assignee'] ?? 0);
    if ($name === '' || $content === '') {
        return ['ok'=>false,'code'=>'bad_request','message'=>'Name/content required'];
    }
    $requester = nm_current_glpi_user_id();
    $pdo = glpi_get_pdo();
    try {
        $pdo->beginTransaction();
        // glpi_tickets
        $stmt = $pdo->prepare('INSERT INTO glpi_tickets (name, content, status, date, date_mod, entities_id, itilcategories_id, locations_id, due_date)
                               VALUES (?, ?, 1, NOW(), NOW(), 0, ?, ?, NULLIF(?, ""))');
        $stmt->execute([$name, $content, $cat ?: null, $loc ?: null, $due]);
        $ticket_id = (int)$pdo->lastInsertId();
        if ($ticket_id <= 0) throw new RuntimeException('Failed to create ticket');

        // requester (type=1)
        $pdo->prepare('INSERT INTO glpi_tickets_users (tickets_id, users_id, type) VALUES (?, ?, 1)')
            ->execute([$ticket_id, $requester]);

        // assignee (type=2) если задан
        if ($assignee > 0) {
            $pdo->prepare('INSERT INTO glpi_tickets_users (tickets_id, users_id, type) VALUES (?, ?, 2)')
                ->execute([$ticket_id, $assignee]);
        }

        $pdo->commit();
        return ['ok'=>true,'ticket_id'=>$ticket_id];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok'=>false,'code'=>'sql_error','message'=>$e->getMessage()];
    }
}

