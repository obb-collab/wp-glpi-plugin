<?php
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../common/api.php';
require_once __DIR__ . '/../modal/ajax.php'; // reuse assign helper if needed

add_action('wp_ajax_nm_create_ticket', 'nm_create_ticket');
function nm_create_ticket(){
    nm_require_nonce();
    $u = wp_get_current_user();
    if (!$u || !$u->ID) nm_json_error('Необходимо войти.');

    $title = sanitize_text_field($_POST['title'] ?? '');
    $content = sanitize_textarea_field($_POST['content'] ?? '');
    $cat_id = intval($_POST['category_id'] ?? 0);
    $loc_id = intval($_POST['location_id'] ?? 0);
    $assignee = intval($_POST['assignee_id'] ?? 0);
    $iam = intval($_POST['iam'] ?? 0) === 1;

    if (strlen($title) < 3) nm_json_error('Укажите тему', 'title');

    $due = new DateTime('now', new DateTimeZone('Europe/Moscow'));
    if ((int)$due->format('H') < 18) { $due->setTime(18,0,0); }
    else { $due->modify('+1 day')->setTime(18,0,0); }

    $payload = [
        'name' => $title,
        'content' => $content . "\n\nПринято в работу",
        'itilcategories_id' => $cat_id ?: null,
        'locations_id' => $loc_id ?: null,
        'due_date' => $due->format('Y-m-d H:i:s'),
    ];

    list($ok, $data) = nm_api_create_ticket($payload);
    if (!$ok) nm_json_error(nm_humanize_api_error($data), null, ['api'=>$data]);
    /*post-assign*/
    if (!$iam && $assignee>0 && !empty($data['id'])){ nm_api_assign_user((int)$data['id'], $assignee); }
    nm_json_ok(['ticket'=>$data]);
}


