<?php
/**
 * Название файла:      proxy.php
 * Назначение:          Функции для работы с прокси через API:
 *                      — получение прокси (getProxy)
 *                      — обновление статистики прокси (updateProxy)
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-04-07
 */

/**
 * Получает доступный прокси-сервер через API.
 *
 * @param  string $apiKey  API-ключ авторизации
 * @param  int    $userId  ID пользователя
 * @return array|null      Массив с данными прокси или null при ошибке.
 *                         Формат: ['id'=>int, 'protocol'=>string, 'host'=>string,
 *                                  'port'=>int, 'login'=>string, 'password'=>string]
 */
function getProxy(string $apiKey, int $userId): ?array
{
    $apiUrl = 'https://servero.space/plugins/proxy-plugin/api/get_proxy.php';

    $payload = [
        'api_key'     => $apiKey,
        'user_id'     => $userId,
        'timeout_min' => 0,
        'timeout_max' => 600,
    ];

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err) {
        echo "⚠️  cURL ошибка при получении прокси: {$err}\n";
        return null;
    }

    $result = json_decode($response, true);

    if ($result && ($result['ok'] ?? false) === true && isset($result['proxy'])) {
        return $result['proxy'];
    }

    echo "⚠️  Прокси не получен: " . ($result['error'] ?? 'Неизвестная ошибка') . "\n";
    return null;
}

/**
 * Отправляет результат использования прокси обратно в API.
 *
 * @param  string $apiKey     API-ключ авторизации
 * @param  int    $userId     ID пользователя
 * @param  int    $proxyId    ID использованного прокси
 * @param  bool   $success    true — успешно, false — ошибка
 * @param  int    $durationMs Время выполнения запроса (мс)
 * @return void
 */
function updateProxy(string $apiKey, int $userId, int $proxyId, bool $success, int $durationMs = 0): void
{
    $apiUrl = 'https://servero.space/plugins/proxy-plugin/api/update_proxy.php';

    $payload = [
        'api_key'     => $apiKey,
        'user_id'     => $userId,
        'proxy_id'    => $proxyId,
        'success'     => $success,
        'duration_ms' => $durationMs,
    ];

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);

    if (!$result || ($result['ok'] ?? false) !== true) {
        echo "⚠️  Не удалось обновить статистику прокси: " . ($result['error'] ?? 'Неизвестная ошибка') . "\n";
    }
}
