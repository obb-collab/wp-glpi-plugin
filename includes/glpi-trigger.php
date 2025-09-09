<?php
/**
 * Universal GLPI trigger kicker:
 *  - Prefer REST: POST /initSession -> POST /CronTask/run (queuednotification, mailgate, etc.)
 *  - Fallback: GET {GLPI_BASE}/front/cron.php (GLPI-mode auto actions)
 *
 * This file is required from glpi-db-setup.php and must not alter existing flows.
 */
if (!defined('ABSPATH')) exit;

if (!function_exists('gexe_glpi_trigger')) {
    /**
     * Kick GLPI cron/notifications after SQL mutations.
     *
     * @param array $args {
     *   @type int|null    $ticket_id Optional ticket ID for logging.
     *   @type string[]|null $tasks    List of task names. Defaults to ['queuednotification'].
     * }
     * @return array { ok:bool, method:string, detail:string, http:int|null }
     */
    function gexe_glpi_trigger(array $args = []) {
        $defaults = [
            'ticket_id' => null,
            'tasks'     => ['queuednotification'],
        ];
        $p = array_merge($defaults, $args);
        $tasks = array_values(array_unique(array_filter(array_map('strval', (array)$p['tasks']))));
        if (!$tasks) {
            $tasks = ['queuednotification'];
        }

        // --- Try REST first ---
        try {
            $rest = gexe_glpi_trigger_via_rest($tasks);
            if (!empty($rest['ok'])) {
                return $rest;
            }
            if (!empty($rest['detail'])) {
                error_log('[gexe_glpi_trigger][rest] failed: ' . $rest['detail']);
            }
        } catch (\Throwable $e) {
            error_log('[gexe_glpi_trigger][rest][ex] ' . $e->getMessage());
        }

        // --- Fallback to front/cron.php ---
        try {
            $fallback = gexe_glpi_trigger_via_cronphp($tasks);
            if (!empty($fallback['ok'])) {
                return $fallback;
            }
            if (!empty($fallback['detail'])) {
                error_log('[gexe_glpi_trigger][cronphp] failed: ' . $fallback['detail']);
            }
        } catch (\Throwable $e) {
            error_log('[gexe_glpi_trigger][cronphp][ex] ' . $e->getMessage());
        }

        return [
            'ok'     => false,
            'method' => 'none',
            'detail' => 'Unable to trigger GLPI tasks by REST or cron.php',
            'http'   => null,
        ];
    }
}

if (!function_exists('gexe_glpi_trigger_via_rest')) {
    /**
     * Run crontasks via REST API: /CronTask/run with a valid Session-Token.
     *
     * @param string[] $tasks
     * @return array
     */
    function gexe_glpi_trigger_via_rest(array $tasks) {
        if (!defined('GEXE_GLPI_API_URL') || !defined('GEXE_GLPI_APP_TOKEN')) {
            return ['ok' => false, 'method' => 'rest', 'detail' => 'GLPI API constants not defined', 'http' => null];
        }
        $user_token = function_exists('gexe_glpi_get_current_user_token')
            ? gexe_glpi_get_current_user_token()
            : (defined('GEXE_GLPI_USER_TOKEN') ? GEXE_GLPI_USER_TOKEN : '');
        if (!$user_token) {
            return ['ok' => false, 'method' => 'rest', 'detail' => 'No GLPI user token available', 'http' => null];
        }

        // 1) initSession
        $sess = wp_remote_post(rtrim(GEXE_GLPI_API_URL, '/') . '/initSession', [
            'headers' => [
                'Content-Type' => 'application/json',
                'App-Token'    => GEXE_GLPI_APP_TOKEN,
            ],
            'timeout' => 8,
            'body'    => wp_json_encode(['user_token' => $user_token]),
        ]);
        if (is_wp_error($sess)) {
            return ['ok' => false, 'method' => 'rest', 'detail' => 'initSession WP_Error: ' . $sess->get_error_message(), 'http' => null];
        }
        $code = (int) wp_remote_retrieve_response_code($sess);
        $body = (string) wp_remote_retrieve_body($sess);
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'method' => 'rest', 'detail' => "initSession HTTP $code: $body", 'http' => $code];
        }
        $json = json_decode($body, true);
        $session_token = is_array($json) && !empty($json['session_token']) ? (string) $json['session_token'] : '';
        if (!$session_token) {
            return ['ok' => false, 'method' => 'rest', 'detail' => 'initSession: no session_token', 'http' => $code];
        }

        $ok_any = false;
        $last_detail = '';
        foreach ($tasks as $task) {
            $name = strtolower($task);
            if ($name === 'queuedmail' || $name === 'mail') {
                $name = 'queuednotification';
            }
            $resp = wp_remote_post(rtrim(GEXE_GLPI_API_URL, '/') . '/CronTask/run', [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'App-Token'     => GEXE_GLPI_APP_TOKEN,
                    'Session-Token' => $session_token,
                ],
                'timeout' => 8,
                'body'    => wp_json_encode(['name' => $name]),
            ]);
            if (is_wp_error($resp)) {
                $last_detail = 'CronTask/run WP_Error: ' . $resp->get_error_message();
                error_log('[gexe_glpi_trigger][rest] ' . $last_detail);
                continue;
            }
            $c = (int) wp_remote_retrieve_response_code($resp);
            $b = (string) wp_remote_retrieve_body($resp);
            if ($c >= 200 && $c < 300) {
                $ok_any = true;
            } else {
                $last_detail = "CronTask/run($name) HTTP $c: $b";
                error_log('[gexe_glpi_trigger][rest] ' . $last_detail);
            }
        }

        // 2) killSession (best effort)
        wp_remote_post(rtrim(GEXE_GLPI_API_URL, '/') . '/killSession', [
            'headers' => [
                'Content-Type'  => 'application/json',
                'App-Token'     => GEXE_GLPI_APP_TOKEN,
                'Session-Token' => $session_token,
            ],
            'timeout' => 4,
        ]);

        if ($ok_any) {
            return ['ok' => true, 'method' => 'rest', 'detail' => 'CronTask/run executed', 'http' => 200];
        }
        return ['ok' => false, 'method' => 'rest', 'detail' => ($last_detail ?: 'CronTask/run failed'), 'http' => null];
    }
}

if (!function_exists('gexe_glpi_trigger_via_cronphp')) {
    /**
     * Run GLPI auto actions by hitting front/cron.php (GLPI mode).
     *
     * @param string[] $tasks
     * @return array
     */
    function gexe_glpi_trigger_via_cronphp(array $tasks) {
        if (!defined('GEXE_GLPI_BASE_URL')) {
            return ['ok' => false, 'method' => 'cronphp', 'detail' => 'GLPI base URL not defined', 'http' => null];
        }
        $base = rtrim(GEXE_GLPI_BASE_URL, '/');
        $ok_any = false;
        $last_detail = '';

        // 1) Generic poke without task
        $resp = wp_remote_get($base . '/front/cron.php', ['timeout' => 6]);
        if (!is_wp_error($resp)) {
            $code = (int) wp_remote_retrieve_response_code($resp);
            if ($code >= 200 && $code < 400) {
                $ok_any = true;
            } else {
                $last_detail = "cron.php generic HTTP $code";
                error_log('[gexe_glpi_trigger][cronphp] ' . $last_detail);
            }
        } else {
            $last_detail = 'cron.php generic WP_Error: ' . $resp->get_error_message();
            error_log('[gexe_glpi_trigger][cronphp] ' . $last_detail);
        }

        // 2) Explicit tasks
        foreach ($tasks as $t) {
            $url = $base . '/front/cron.php?task=' . rawurlencode($t);
            $r = wp_remote_get($url, ['timeout' => 6]);
            if (is_wp_error($r)) {
                $last_detail = 'cron.php task WP_Error: ' . $r->get_error_message();
                error_log('[gexe_glpi_trigger][cronphp] ' . $last_detail);
                continue;
            }
            $c = (int) wp_remote_retrieve_response_code($r);
            if ($c >= 200 && $c < 400) {
                $ok_any = true;
            } else {
                $last_detail = "cron.php task($t) HTTP $c";
                error_log('[gexe_glpi_trigger][cronphp] ' . $last_detail);
            }
        }

        if ($ok_any) {
            return ['ok' => true, 'method' => 'cronphp', 'detail' => 'front/cron.php triggered', 'http' => 200];
        }
        return ['ok' => false, 'method' => 'cronphp', 'detail' => ($last_detail ?: 'cron.php failed'), 'http' => null];
    }
}
