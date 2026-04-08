<?php
/**
 * Название файла:      deepseek_price_search.php
 * Назначение:          Функции для поиска цены товара через DeepSeek:
 *                      — очистка HTML от мусора
 *                      — разбиение HTML на чанки
 *                      — отправка чанков в DeepSeek для поиска цены
 *                      — оркестратор поиска цены
 *
 * Используется в:      cron/4.php
 *
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-04-08
 */

/**
 * Очищает HTML от мусора для уменьшения объёма перед отправкой в DeepSeek.
 * Удаляет: <script>, <style>, <!-- -->, <svg>, <noscript>, множественные пробелы.
 *
 * @param  string $html Исходный HTML-код
 * @return string       Очищенный HTML
 */
function cleanHtmlForPriceSearch(string $html): string
{
    // Удаляем содержимое тэгов <script>...</script>
    $html = preg_replace('/<script\b[^>]*>[\s\S]*?<\/script>/i', '', $html);

    // Удаляем содержимое тэгов <style>...</style>
    $html = preg_replace('/<style\b[^>]*>[\s\S]*?<\/style>/i', '', $html);

    // Удаляем HTML-комментарии <!-- ... -->
    $html = preg_replace('/<!--[\s\S]*?-->/', '', $html);

    // Удаляем SVG-блоки <svg>...</svg>
    $html = preg_replace('/<svg\b[^>]*>[\s\S]*?<\/svg>/i', '', $html);

    // Удаляем <noscript>...</noscript>
    $html = preg_replace('/<noscript\b[^>]*>[\s\S]*?<\/noscript>/i', '', $html);

    // Схлопываем множественные пробелы / переводы строк в одинарные
    $html = preg_replace('/\s+/', ' ', $html);

    return trim($html);
}

/**
 * Разбивает очищенный HTML на чанки заданного размера с перекрытием.
 *
 * @param  string $html      Очищенный HTML
 * @param  int    $chunkSize Размер одного чанка в символах (по умолчанию 15000)
 * @param  int    $overlap   Перекрытие между чанками в символах (по умолчанию 500)
 * @return array             Массив строк (чанков)
 */
function splitHtmlIntoChunks(string $html, int $chunkSize = 15000, int $overlap = 500): array
{
    $length = mb_strlen($html);

    if ($length <= $chunkSize) {
        return [$html];
    }

    $chunks = [];
    $offset = 0;

    while ($offset < $length) {
        $chunks[] = mb_substr($html, $offset, $chunkSize);
        $offset += $chunkSize - $overlap;
    }

    return $chunks;
}

/**
 * Отправляет один чанк HTML в DeepSeek для поиска цены товара.
 * Включает retry-логику при timeout/429/502/503 (до 3 попыток).
 *
 * @param  string $deepseekKey  API-ключ DeepSeek
 * @param  string $productInfo  Описание товара
 * @param  string $chunk        Фрагмент HTML
 * @param  int    $chunkNumber  Номер текущего чанка (начиная с 1)
 * @param  int    $totalChunks  Общее количество чанков
 * @param  int    $maxRetries   Максимальное количество попыток (по умолчанию 3)
 * @return array  ['success'=>bool, 'status'=>string|null, 'price'=>float|null,
 *                 'currency'=>string|null, 'product_name'=>string|null, 'error'=>string|null]
 */
function searchPriceInChunk(
    string $deepseekKey,
    string $productInfo,
    string $chunk,
    int $chunkNumber,
    int $totalChunks,
    int $maxRetries = 3
): array {
    $systemPrompt = <<<'PROMPT'
Ты — помощник для поиска цены товара на HTML-странице интернет-магазина.

Тебе дан товар и фрагмент HTML-кода страницы.

Твоя задача — найти цену указанного товара в этом фрагменте HTML.

Правила:
- Ищи только ТОЧНОЕ или БЛИЗКОЕ соответствие названию товара.
- Цена может быть в тэгах: <span>, <div>, <p>, <td>, атрибутах data-price и т.п.
- Цена может быть в формате: "1 234.56", "1234,56", "$1,234.56", "1 234 руб." и т.п.
- Если нашёл цену — верни её числовое значение (только цифры и точка как десятичный разделитель).
- Если вместо цены указано "Цена по запросу", "Звоните", "По запросу", "Call for price" — верни "REQUEST".
- Если товар не найден в этом фрагменте — верни "NOT_FOUND".
- Если товар найден, но цены нет — верни "NO_PRICE".

Верни ТОЛЬКО JSON без комментариев, без пояснений, без markdown-разметки.
Формат ответа:
{"status": "FOUND|NOT_FOUND|NO_PRICE|REQUEST", "price": число или null, "currency": "строка или null", "product_name_on_page": "строка или null"}
PROMPT;

    $userMessage = "Товар: {$productInfo}\nФрагмент HTML (часть {$chunkNumber} из {$totalChunks}):\n{$chunk}";

    $requestBody = [
        'model'    => 'deepseek-chat',
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userMessage],
        ],
        'temperature' => 0,
    ];

    $lastError = null;

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $ch = curl_init('https://api.deepseek.com/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($requestBody, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json; charset=utf-8',
                'Authorization: Bearer ' . $deepseekKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT        => 120,
        ]);

        $response  = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        echo "📡 DeepSeek ответ: HTTP {$httpCode}\n";

        // Проверяем cURL ошибки
        if ($curlError) {
            $lastError = 'cURL: ' . $curlError;
            $isRetryable = str_contains($curlError, 'timed out') || str_contains($curlError, 'timeout');
            if ($isRetryable && $attempt < $maxRetries) {
                echo "⏳ Попытка {$attempt}/{$maxRetries} не удалась (таймаут), повтор через 5 сек...\n";
                sleep(5);
                continue;
            }
            return ['success' => false, 'status' => null, 'price' => null, 'currency' => null, 'product_name' => null, 'error' => $lastError];
        }

        // Проверяем HTTP-код на retryable ошибки
        if (in_array($httpCode, [429, 502, 503], true)) {
            $lastError = "HTTP {$httpCode}";
            if ($attempt < $maxRetries) {
                echo "⏳ Попытка {$attempt}/{$maxRetries} не удалась (HTTP {$httpCode}), повтор через 5 сек...\n";
                sleep(5);
                continue;
            }
            return ['success' => false, 'status' => null, 'price' => null, 'currency' => null, 'product_name' => null, 'error' => $lastError];
        }

        // Проверяем другие HTTP ошибки (не retryable)
        if ($httpCode !== 200) {
            return ['success' => false, 'status' => null, 'price' => null, 'currency' => null, 'product_name' => null, 'error' => "HTTP {$httpCode}"];
        }

        // Парсим ответ DeepSeek
        $result = json_decode($response, true);
        if (!$result || !isset($result['choices'][0]['message']['content'])) {
            return ['success' => false, 'status' => null, 'price' => null, 'currency' => null, 'product_name' => null, 'error' => 'Некорректный ответ от DeepSeek'];
        }

        $content = trim($result['choices'][0]['message']['content']);

        // Очищаем от markdown-обёрток ```json ... ```
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $content, $m)) {
            $content = trim($m[1]);
        }

        $parsed = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'status' => null, 'price' => null, 'currency' => null, 'product_name' => null, 'error' => 'JSON decode: ' . json_last_error_msg()];
        }

        $status      = $parsed['status']               ?? 'NOT_FOUND';
        $price       = $parsed['price']                 ?? null;
        $currency    = $parsed['currency']              ?? null;
        $productName = $parsed['product_name_on_page']  ?? null;

        return [
            'success'      => true,
            'status'       => $status,
            'price'        => ($price !== null) ? (float) $price : null,
            'currency'     => $currency,
            'product_name' => $productName,
            'error'        => null,
        ];
    }

    return ['success' => false, 'status' => null, 'price' => null, 'currency' => null, 'product_name' => null, 'error' => $lastError ?? 'Неизвестная ошибка'];
}

/**
 * Основная функция-оркестратор поиска цены товара в HTML.
 * Очищает HTML → разбивает на чанки → последовательно отправляет в DeepSeek.
 * Останавливается при нахождении цены (FOUND) или обнаружении "Цена по запросу" (REQUEST).
 *
 * @param  string $deepseekKey  API-ключ DeepSeek
 * @param  string $productInfo  Описание товара
 * @param  string $html         Полный HTML-код страницы
 * @return array  ['success'=>bool, 'status'=>string, 'price'=>float|null,
 *                 'currency'=>string|null, 'product_name'=>string|null,
 *                 'chunks_processed'=>int, 'chunks_total'=>int]
 */
function searchPriceInHtml(string $deepseekKey, string $productInfo, string $html): array
{
    // Очистка HTML
    $cleanedHtml = cleanHtmlForPriceSearch($html);
    $originalSize = mb_strlen($html);
    $cleanedSize  = mb_strlen($cleanedHtml);
    $savings      = ($originalSize > 0) ? round((1 - $cleanedSize / $originalSize) * 100) : 0;

    echo "📊 Размер после очистки: {$cleanedSize} символов (экономия {$savings}%)\n";

    // Проверка: HTML после очистки слишком мал
    if ($cleanedSize < 100) {
        echo "⚠️ HTML после очистки слишком мал (< 100 символов)\n";
        return [
            'success'          => false,
            'status'           => 'EMPTY_HTML',
            'price'            => null,
            'currency'         => null,
            'product_name'     => null,
            'chunks_processed' => 0,
            'chunks_total'     => 0,
        ];
    }

    // Разбиение на чанки
    $chunks      = splitHtmlIntoChunks($cleanedHtml);
    $totalChunks = count($chunks);
    echo "📊 Количество чанков: {$totalChunks}\n";

    $foundNoPrice = false;
    $chunksProcessed = 0;

    foreach ($chunks as $index => $chunk) {
        $chunkNumber = $index + 1;
        $chunkSize   = mb_strlen($chunk);

        echo "\n📤 Чанк {$chunkNumber}/{$totalChunks} ({$chunkSize} символов)...\n";

        $result = searchPriceInChunk($deepseekKey, $productInfo, $chunk, $chunkNumber, $totalChunks);
        $chunksProcessed++;

        if (!$result['success']) {
            echo "⚠️ Ошибка: {$result['error']}\n";
            echo "📋 Результат: Ошибка парсинга, переход к следующему чанку\n";
            continue;
        }

        $status = $result['status'];
        echo "📋 Результат: {$status}";
        if ($status === 'FOUND' && $result['price'] !== null) {
            echo " — цена " . number_format($result['price'], 2, '.', ' ');
        }
        echo "\n";

        // FOUND с ценой > 0 — СТОП
        if ($status === 'FOUND' && $result['price'] !== null && $result['price'] > 0) {
            echo "\n🎯 Цена найдена! Остановка перебора.\n";
            return [
                'success'          => true,
                'status'           => 'FOUND',
                'price'            => $result['price'],
                'currency'         => $result['currency'],
                'product_name'     => $result['product_name'],
                'chunks_processed' => $chunksProcessed,
                'chunks_total'     => $totalChunks,
            ];
        }

        // FOUND с ценой <= 0 — трактуем как NOT_FOUND
        if ($status === 'FOUND' && ($result['price'] === null || $result['price'] <= 0)) {
            echo "⚠️ Цена <= 0 или null, трактуем как NOT_FOUND\n";
            continue;
        }

        // REQUEST — СТОП
        if ($status === 'REQUEST') {
            echo "\n📞 Цена по запросу. Остановка перебора.\n";
            return [
                'success'          => true,
                'status'           => 'REQUEST',
                'price'            => null,
                'currency'         => null,
                'product_name'     => $result['product_name'],
                'chunks_processed' => $chunksProcessed,
                'chunks_total'     => $totalChunks,
            ];
        }

        // NO_PRICE — запоминаем, продолжаем
        if ($status === 'NO_PRICE') {
            $foundNoPrice = true;
        }

        // NOT_FOUND — продолжаем
    }

    // Все чанки обработаны, цена не найдена
    $finalStatus = $foundNoPrice ? 'NO_PRICE' : 'NOT_FOUND';

    return [
        'success'          => false,
        'status'           => $finalStatus,
        'price'            => null,
        'currency'         => null,
        'product_name'     => null,
        'chunks_processed' => $chunksProcessed,
        'chunks_total'     => $totalChunks,
    ];
}
