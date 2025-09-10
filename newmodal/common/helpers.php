<?php
if (!defined('ABSPATH')) exit;

/**
 * Проверка nonce для AJAX.
 */
function gexe_nm_check_nonce(): void {
    $nonce = isset($_REQUEST['_ajax_nonce']) ? (string) $_REQUEST['_ajax_nonce'] : '';
    if (!wp_verify_nonce($nonce, 'gexe_nm')) {
        wp_send_json(['ok'=>false,'code'=>'forbidden','message'=>'Bad nonce']);
        exit;
    }
}

/**
 * Базовый JSON-ответ.
 */
function gexe_nm_json(bool $ok, string $code, string $message, array $extra) {
    return wp_send_json(array_merge(['ok'=>$ok,'code'=>$code,'message'=>$message], $extra));
}

/**
 * Проверка наличия шорткода на странице.
 */
function gexe_nm_is_shortcode_present(string $tag): bool {
    global $post;
    if (!$post) return false;
    if (has_shortcode($post->post_content, $tag)) return true;
    return false;
}

/**
 * Удобный фильтр статуса "решено"
 */
function gexe_nm_is_resolved_status(int $status): bool {
    return $status === 6;
}

