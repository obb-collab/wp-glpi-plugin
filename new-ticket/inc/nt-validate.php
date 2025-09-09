<?php
if (!defined('ABSPATH')) exit;

function nt_validate_ticket_input($src) {
    $title   = isset($src['title']) ? trim((string)$src['title']) : '';
    $content = isset($src['content']) ? trim((string)$src['content']) : '';
    $cat_id  = isset($src['category_id']) ? (int)$src['category_id'] : 0;
    $loc_id  = isset($src['location_id']) ? (int)$src['location_id'] : 0;
    $ass_id  = isset($src['assignee_id']) ? (int)$src['assignee_id'] : 0;
    $self    = !empty($src['self_assign']);

    if (mb_strlen($title) < 3 || mb_strlen($title) > 255) {
        return new WP_Error('validation', 'Invalid title');
    }
    if (mb_strlen($content) < 3 || mb_strlen($content) > 65535) {
        return new WP_Error('validation', 'Invalid content');
    }
    if ($cat_id <= 0) {
        return new WP_Error('validation', 'Invalid category');
    }
    if ($loc_id <= 0) {
        return new WP_Error('validation', 'Invalid location');
    }
    if (!$self && $ass_id <= 0) {
        return new WP_Error('validation', 'Invalid assignee');
    }

    return [
        'title'       => $title,
        'content'     => $content,
        'category_id' => $cat_id,
        'location_id' => $loc_id,
        'assignee_id' => $ass_id,
        'self_assign' => $self,
    ];
}
