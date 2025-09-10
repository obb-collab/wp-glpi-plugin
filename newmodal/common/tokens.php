<?php
/**
 * Newmodal: GLPI personal tokens registry and helpers.
 *
 * ВАЖНО: это реестр именно ОТВЕТСТВЕННЫХ ИСПОЛНИТЕЛЕЙ.
 * Каждая запись = соответствие WP-пользователя конкретному GLPI-исполнителю + его персональный токен.
 */
if (!defined('ABSPATH')) { exit; }

/**
 * Registry of personal GLPI user tokens.
 * Mapping is strictly by numeric IDs; display names are only in comments.
 *
 * Columns:
 *  - wp_user_id (WordPress)
 *  - glpi_user_id
 *  - token (personal GLPI API token)
 */
function nm_glpi_token_registry(): array {
    static $REG = null;
    if ($REG !== null) return $REG;
    $REG = [
        // Куткин П.;  WP=1;  GLPI=2
        ['wp_user_id' => 1,  'glpi_user_id' => 2,   'token' => '8ffMQJvkcgi8V5OMWrh89Xvr97jEzK4ddrkdL6pw'],
        // Скомороха А.; WP=4;  GLPI=621
        ['wp_user_id' => 4,  'glpi_user_id' => 621, 'token' => 'VMgcyxmkHWAGXASOF0yj1eFZTrHmMGU4ynDBcGjU'],
        // Орлов А.;    WP=5;  GLPI=622
        ['wp_user_id' => 5,  'glpi_user_id' => 622, 'token' => '4cMwnNvxaQ6wBPWU5iFg7V7eGhCmeGtGVaq0cTfR'],
        // Царёв С.;    WP=6;  GLPI=623
        ['wp_user_id' => 6,  'glpi_user_id' => 623, 'token' => 's213NnSMYyVtkt1p55Tfvz85oxSzMuYeHBtghRt3'],
    ];
    return $REG;
}

/**
 * Вернуть связку ИСПОЛНИТЕЛЯ для WP-пользователя.
 *
 * @param int|null $wp_user_id null -> current user
 * @return array{wp_user_id:int,glpi_user_id:int,token:string}|null
 */
function nm_glpi_executor_for_wp(?int $wp_user_id = null): ?array {
    if ($wp_user_id === null && is_user_logged_in()) {
        $wp_user_id = get_current_user_id();
    }
    foreach (nm_glpi_token_registry() as $r) {
        if ((int)$r['wp_user_id'] === (int)$wp_user_id) {
            return [
                'wp_user_id'   => (int)$r['wp_user_id'],
                'glpi_user_id' => (int)$r['glpi_user_id'],
                'token'        => (string)$r['token'],
            ];
        }
    }
    return null;
}

/**
 * Получить GLPI user id ответственного исполнителя по WP-пользователю.
 *
 * @param int|null $wp_user_id null -> current user
 * @return int 0 если не найден
 */
function nm_glpi_glpi_user_id_for_wp(?int $wp_user_id = null): int {
    $row = nm_glpi_executor_for_wp($wp_user_id);
    return $row ? (int)$row['glpi_user_id'] : 0;
}

/**
 * Получить персональный токен исполнителя по WP-пользователю.
 *
 * @param int|null $wp_user_id null -> current user
 * @return string
 */
function nm_glpi_token_for_wp_user(?int $wp_user_id = null): string {
    $row = nm_glpi_executor_for_wp($wp_user_id);
    return $row ? (string)$row['token'] : '';
}

/**
 * Получить персональный токен по GLPI user id.
 */
function nm_glpi_token_for_glpi_user(int $glpi_user_id): string {
    foreach (nm_glpi_token_registry() as $r) {
        if ((int)$r['glpi_user_id'] === (int)$glpi_user_id) {
            return (string)$r['token'];
        }
    }
    return '';
}

/**
 * Compose GLPI headers (App-Token + Session-Token).
 * Если session_token не передан — используем токен текущего WP-пользователя.
 */
function nm_glpi_api_headers(?string $session_token = null): array {
    $headers = [
        'App-Token'    => defined('GEXE_GLPI_APP_TOKEN') ? GEXE_GLPI_APP_TOKEN : '',
        'Content-Type' => 'application/json',
    ];
    if ($session_token === null) {
        $session_token = nm_glpi_token_for_wp_user(null); // current WP user (ответственный)
        if (!$session_token && defined('GEXE_GLPI_USER_TOKEN')) {
            // legacy fallback
            $session_token = GEXE_GLPI_USER_TOKEN;
        }
    }
    if ($session_token) {
        $headers['Session-Token'] = $session_token;
    }
    return $headers;
}

