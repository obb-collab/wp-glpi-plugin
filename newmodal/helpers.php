<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Ensure current WP user is mapped to GLPI user (per project rules)
 */
function nm_assert_user_mapping(): int {
    if (!function_exists('glpi_get_current_glpi_user_id')) {
        throw new RuntimeException('GLPI mapping helpers not loaded.');
    }
    $gid = glpi_get_current_glpi_user_id();
    if ($gid <= 0) {
        throw new RuntimeException('No GLPI mapping for current WP user.');
    }
    return $gid;
}

/**
 * Get GLPI REST tokens for current user.
 * Uses WP usermeta:
 *  - glpi_app_token (application token)
 *  - glpi_api_token (user token)
 */
function nm_get_glpi_tokens(): array {
    $uid = get_current_user_id();
    if (!$uid) return ['', ''];
    $app = get_user_meta($uid, 'glpi_app_token', true);
    $usr = get_user_meta($uid, 'glpi_api_token', true);
    return [is_string($app) ? trim($app) : '', is_string($usr) ? trim($usr) : ''];
}

/**
 * Minimal GLPI REST client:
 *  - Opens a session per request (App-Token + Authorization: user_token)
 *  - Sends JSON
 *  - Closes session
 */
function nm_glpi_api(string $method, string $endpoint, array $payload = []): array {
    if (!NM_WRITES_VIA_API) {
        throw new RuntimeException('API writes are disabled.');
    }
    [$appToken, $userToken] = nm_get_glpi_tokens();
    if (!$appToken || !$userToken) {
        throw new RuntimeException('Missing GLPI API tokens in usermeta.');
    }
    $base = rtrim(NM_GLPI_API_BASE, '/');
    $url  = $base . '/' . ltrim($endpoint, '/');

    // Open session
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $base . '/initSession',
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'App-Token: ' . $appToken,
            'Authorization: user_token ' . $userToken,
        ],
    ]);
    $sessionResp = curl_exec($ch);
    if ($sessionResp === false) {
        throw new RuntimeException('GLPI API: initSession failed');
    }
    $session = json_decode($sessionResp, true);
    $sessionToken = $session['session_token'] ?? '';
    if (!$sessionToken) {
        throw new RuntimeException('GLPI API: no session token returned');
    }
    curl_close($ch);

    // Main request
    $ch = curl_init();
    $headers = [
        'Content-Type: application/json',
        'App-Token: ' . $appToken,
        'Session-Token: ' . $sessionToken,
    ];
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
    ];
    if (!empty($payload)) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('GLPI API error: ' . $err);
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException('GLPI API HTTP ' . $code . ': ' . $resp);
    }
    $data = json_decode($resp, true);

    // Close session (best effort)
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $base . '/killSession',
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'App-Token: ' . $appToken,
            'Session-Token: ' . $sessionToken,
        ],
    ]);
    curl_exec($ch);
    curl_close($ch);

    return is_array($data) ? $data : [];
}

/**
 * Helper: safe JSON response
 */
function nm_send_json($ok, $payload = [], $http = 200) {
    wp_send_json([
        'ok'   => (bool)$ok,
        'data' => $payload,
    ], $http);
}
