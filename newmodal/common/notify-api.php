<?php
/**
 * nm_api_trigger_notifications
 * Пингуем GLPI REST API после SQL операций, чтобы сработали уведомления.
 * Реализация «мягкая»: если API недоступно, действия не откатываем.
 */
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../../glpi-api.php';
require_once __DIR__ . '/../../glpi-db-setup.php';

/**
 * Получить API-клиент от имени текущего пользователя, если включены токены.
 */
function nm_get_api_client(): ?Gexe_GLPI_API {
    if (!defined('GEXE_GLPI_API_URL') || !defined('GEXE_GLPI_APP_TOKEN')) return null;
    if (!defined('GEXE_GLPI_USER_TOKEN')) return null; // общий фолбек
    try {
        return new Gexe_GLPI_API(GEXE_GLPI_API_URL, GEXE_GLPI_APP_TOKEN, GEXE_GLPI_USER_TOKEN);
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Дёргаем механизм уведомлений.
 * Вариант А (рекомендованный): запрос к /QueuedNotification (или специальной точке),
 * который инициирует обработку очереди.
 */
function nm_api_trigger_notifications(): void {
    $api = nm_get_api_client();
    if (!$api) return;
    try {
        // Пытаемся «пнуть» обработчик уведомлений.
        // Если в проекте есть спец. эндпоинт для этого — используем его.
        // Иначе — безопасный GET к «легковесной» точке, чтобы инициировать cron/планировщик на стороне GLPI.
        $api->get('/QueuedNotification');
    } catch (Throwable $e) {
        // Ошибку не поднимаем во фронт: SQL уже отработал, уведомления — best-effort.
    }
}

