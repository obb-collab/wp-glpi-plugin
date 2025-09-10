<?php
/**
 * BAGE (изолированная страница карточек под новую модалку).
 * - Полный клон: свой шаблон, свой JS, свой CSS.
 * - Никаких подключений старых шорткодов/шаблонов/скриптов.
 * - Данные и действия — только через GLPI REST API.
 */
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../newmodal-api.php'; // используем общий API-обёртку (без SQL)

/**
 * Шорткод [glpi_cards_new]
 * Рендерит чистый контейнер и подключает ассеты только для этой страницы.
 */
add_shortcode('glpi_cards_new', function ($atts = []) {
    // ассеты
    $ver  = defined('GEXE_TRIGGERS_VERSION') ? GEXE_TRIGGERS_VERSION : '1.0.0';
    $base = plugin_dir_url(__FILE__);
    wp_enqueue_style('gexe-bage', $base . 'bage.css', [], $ver);
    wp_enqueue_script('gexe-bage', $base . 'bage.js', [], $ver, true);
    wp_localize_script('gexe-bage', 'gexeBage', [
        'ajaxUrl'      => admin_url('admin-ajax.php'),
        'nonce'        => wp_create_nonce('gexe_bage_nonce'),
        'solvedStatus' => (int) get_option('glpi_solved_status', 6),
        'statuses'     => [
            ['id' => 0, 'name' => 'Все задачи'],
            ['id' => 2, 'name' => 'В работе'],
            ['id' => 3, 'name' => 'В плане'],
            ['id' => 4, 'name' => 'В стопе'],
            ['id' => 1, 'name' => 'Новые'],
            ['id' => (int) get_option('glpi_overdue_status', 0), 'name' => 'Просрочены'],
        ],
        'perPage'      => 20,
    ]);
    // HTML-шаблон
    ob_start();
    include __DIR__ . '/bage-template.php';
    return (string) ob_get_clean();
});

/**
 * AJAX: список заявок (фильтры/поиск/пагинация).
 * Вход: page, per_page, status, category, q
 */
add_action('wp_ajax_gexe_bage_list_tickets', function () {
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'gexe_bage_nonce')) {
        wp_send_json_error(['message' => 'Security check failed: invalid nonce.']);
    }
    $page     = max(1, (int)($_POST['page'] ?? 1));
    $per_page = min(100, max(5, (int)($_POST['per_page'] ?? 20)));
    $status   = (int)($_POST['status'] ?? 0);
    $cat      = (int)($_POST['category'] ?? 0);
    $q        = trim((string)($_POST['q'] ?? ''));

    try {
        $offset = ($page - 1) * $per_page;
        $query  = [
            'range' => $offset . '-' . ($offset + $per_page - 1),
        ];
        // Поиск через /search/Ticket
        $criteria = [];
        // Текущий исполнитель
        $ctx = gexe_newmodal_current_glpi_context();
        // Ticket_User.type=2 (назначенный техник)
        $criteria[] = ['field' => 'assign', 'searchtype' => 'equals', 'value' => $ctx['glpi_user_id']];
        // Статус (если задан)
        if ($status > 0) {
            $criteria[] = ['field' => 12, 'searchtype' => 'equals', 'value' => $status]; // 12 = status
        }
        // Категория (если задана)
        if ($cat > 0) {
            $criteria[] = ['field' => 7, 'searchtype' => 'equals', 'value' => $cat]; // 7 = itilcategories_id
        }
        // Текстовый поиск
        if ($q !== '') {
            $criteria[] = ['field' => 1, 'searchtype' => 'contains', 'value' => $q]; // 1 = name
        }
        $payload = [
            'criteria' => $criteria,
            'forcedisplay' => [2, 1, 12, 7, 30, 15, 16], // id, name, status, category, date_mod, sla, location
            'order' => 'DESC',
            'sort'  => 30 // date_mod
        ];
        $data = gexe_newmodal_api_call('POST', 'search/Ticket', $payload, $query);
        // Приводим в удобный фронту формат
        $rows = isset($data['data']) && is_array($data['data']) ? $data['data'] : [];
        $totalcount = isset($data['totalcount']) ? (int)$data['totalcount'] : count($rows);
        $tickets = [];
        foreach ($rows as $row) {
            $map = [];
            foreach ($row as $cell) {
                $map[$cell['name']] = $cell['value'];
            }
            $tickets[] = [
                'id'        => (int)($map['id'] ?? 0),
                'name'      => (string)($map['name'] ?? ''),
                'status'    => (int)($map['status'] ?? 0),
                'category'  => (string)($map['itilcategories_id'] ?? ''),
                'date_mod'  => (string)($map['date_mod'] ?? ''),
                'location'  => (string)($map['locations_id'] ?? ''),
            ];
        }
        wp_send_json_success([
            'page'   => $page,
            'per'    => $per_page,
            'total'  => $totalcount,
            'items'  => $tickets
        ]);
    } catch (Exception $e) {
        wp_send_json_error(['message' => 'Ошибка загрузки списка: ' . $e->getMessage()]);
    }
});

/**
 * AJAX: счётчики по статусам (для бейджей).
 */
add_action('wp_ajax_gexe_bage_counters', function () {
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'gexe_bage_nonce')) {
        wp_send_json_error(['message' => 'Security check failed: invalid nonce.']);
    }
    try {
        $ctx = gexe_newmodal_current_glpi_context();
        $counts = [];
        $statuses = [1,2,3,4,6]; // 6 — «решено/закрыто» по брифу
        foreach ($statuses as $st) {
            $payload = [
                'criteria' => [
                    ['field' => 'assign', 'searchtype' => 'equals', 'value' => $ctx['glpi_user_id']],
                    ['field' => 12, 'searchtype' => 'equals', 'value' => $st],
                ],
            ];
            $res = gexe_newmodal_api_call('POST', 'search/Ticket', $payload, ['range' => '0-0']);
            $counts[$st] = isset($res['totalcount']) ? (int)$res['totalcount'] : 0;
        }
        // «Все» — сумма открытых (без 6)
        $counts[0] = ($counts[1] ?? 0) + ($counts[2] ?? 0) + ($counts[3] ?? 0) + ($counts[4] ?? 0);
        wp_send_json_success(['counts' => $counts]);
    } catch (Exception $e) {
        wp_send_json_error(['message' => 'Ошибка подсчёта: ' . $e->getMessage()]);
    }
});

