<?php
/**
 * Название файла:      provider_deepseek.php
 * Назначение:          Получение API-ключа DeepSeek через сервис servero.space
 *                      и отправка запроса в DeepSeek для классификации ссылок
 *                      по релевантности к товару.
 *
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-04-08
 */

/**
 * Получает полный API-ключ DeepSeek через servero.space.
 *
 * @param  string $apiKey  Ключ авторизации servero.space
 * @param  int    $userId  ID пользователя
 * @return array           ['success' => bool, 'key' => string|null, 'error' => string|null]
 */
function getDeepseekApiKey(string $apiKey, int $userId): array
{
    $apiUrl = 'https://servero.space/plugins/api-keys-plugin/api/get_key.php';

    $payload = [
        'api_key'     => $apiKey,
        'users_id'    => $userId,
        'provider'    => 'deepseek.com',
        'return_mode' => 'full',
    ];

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'key' => null, 'error' => 'cURL: ' . $curlError];
    }

    $result = json_decode($response, true);

    if (!$result || empty($result['success'])) {
        $code = $result['code'] ?? 'UNKNOWN';
        $msg  = $result['error'] ?? 'Неизвестная ошибка';
        return ['success' => false, 'key' => null, 'error' => "[$code] $msg"];
    }

    $fullKey = $result['key'] ?? null;
    if (!$fullKey) {
        return ['success' => false, 'key' => null, 'error' => 'Ключ отсутствует в ответе API'];
    }

    return ['success' => true, 'key' => $fullKey, 'error' => null];
}

/**
 * Внутренняя функция: отправляет один запрос к DeepSeek для классификации ссылок.
 * DeepSeek определяет, на какой странице стоит искать товар (priority=1)
 * или не стоит (priority=0), и возвращает JSON с execution_status=1.
 *
 * @param  string $deepseekKey  API-ключ DeepSeek
 * @param  string $productInfo  Описание товара (название | raid | блок питания)
 * @param  array  $links        Массив ссылок из parsed_links
 * @return array                ['success' => bool, 'data' => array|null, 'raw' => string|null, 'error' => string|null]
 */
function _sendDeepseekClassificationRequest(string $deepseekKey, string $productInfo, array $links): array
{
    $linksJson = json_encode($links, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $systemPrompt = <<<PROMPT
Ты — помощник для классификации ссылок интернет-магазинов.
Тебе дан товар и список ссылок (страниц сайта) в формате JSON.
Каждая ссылка содержит поле "html" — текст ссылки со страницы.

Твоя задача — определить, стоит ли искать указанный товар на каждой из этих страниц.

Правила:
- Если по тексту ссылки можно предположить, что на этой странице продаётся или перечисляется данный товар или его категория, установи priority = 1.
- Если страница явно не относится к товару (сервисы, калькуляторы, аутсорсинг, контакты, новости и т.п.), установи priority = 0.
- Для всех записей установи execution_status = 1.

Верни ТОЛЬКО валидный JSON-массив без комментариев, без пояснений, без markdown-разметки.
Формат ответа — массив объектов:
[{"id": число, "html": "строка", "execution_status": 1, "priority": 0 или 1}]
PROMPT;

    $userMessage = "Товар: {$productInfo}\n\nСписок ссылок:\n{$linksJson}";

    $requestBody = [
        'model'    => 'deepseek-chat',
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userMessage],
        ],
        'temperature' => 0,
    ];

    $ch = curl_init('https://api.deepseek.com/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($requestBody, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Bearer ' . $deepseekKey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 120,
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'data' => null, 'raw' => null, 'error' => 'cURL: ' . $curlError];
    }

    if ($httpCode !== 200) {
        return ['success' => false, 'data' => null, 'raw' => $response, 'error' => "HTTP {$httpCode}"];
    }

    $result = json_decode($response, true);
    if (!$result || !isset($result['choices'][0]['message']['content'])) {
        return ['success' => false, 'data' => null, 'raw' => $response, 'error' => 'Некорректный ответ от DeepSeek'];
    }

    $content = trim($result['choices'][0]['message']['content']);

    if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $content, $m)) {
        $content = trim($m[1]);
    }

    $classified = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['success' => false, 'data' => null, 'raw' => $content, 'error' => 'JSON decode: ' . json_last_error_msg()];
    }

    return ['success' => true, 'data' => $classified, 'raw' => $content, 'error' => null];
}

/**
 * Отправляет товар и список ссылок в DeepSeek для классификации с поддержкой повторных попыток.
 * При ошибке таймаута выполняет до $maxRetries попыток с паузой 5 секунд между ними.
 *
 * @param  string $deepseekKey  API-ключ DeepSeek
 * @param  string $productInfo  Описание товара (название | raid | блок питания)
 * @param  array  $links        Массив ссылок из parsed_links
 * @param  int    $maxRetries   Максимальное количество попыток (по умолчанию 3)
 * @return array                ['success' => bool, 'data' => array|null, 'raw' => string|null, 'error' => string|null]
 */
function classifyLinksWithDeepseek(string $deepseekKey, string $productInfo, array $links, int $maxRetries = 3): array
{
    $result = ['success' => false, 'data' => null, 'raw' => null, 'error' => null];

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $result = _sendDeepseekClassificationRequest($deepseekKey, $productInfo, $links);

        if ($result['success']) {
            return $result;
        }

        // При ошибке таймаута — повторить попытку
        if (str_contains($result['error'] ?? '', 'timed out') && $attempt < $maxRetries) {
            echo "⏳ Попытка {$attempt}/{$maxRetries} не удалась (таймаут), повтор через 5 сек...\n";
            sleep(5);
            continue;
        }

        // Для остальных ошибок — вернуть сразу без повтора
        return $result;
    }

    return $result;
}
