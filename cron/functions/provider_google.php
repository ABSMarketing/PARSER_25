<?php
/**
 * provider_google.php
 * Функция для получения ключа для Google Sheets через API
 * 
 * Функция принимает параметры аутентификации и провайдера, проверяет валидность API-ключа,
 * обращается к Google Apps Script для получения всех данных из таблицы.
 *
 * @param string $apiKey Ключ авторизации (обязательно)
 * @param int $userId ID пользователя (обязательно)
 * @param string $provider Провайдер (обязательно)
 * @param string $sheetName Название вкладки в Google Sheets (по умолчанию 'Платформы')
 * @return array Массив с результатами:
 *               - 'success' => bool: успешность операции
 *               - 'message' => string: текстовое сообщение о результате
 *               - 'data' => array: двумерный массив всех данных из таблицы (если успех)
 *               - 'headers' => array: массив заголовков колонок (если успех)
 */
function getAllDataFromGoogleSheets($apiKey, $userId, $provider, $sheetName = 'Платформы') {
    // Проверяем обязательные параметры
    if (empty($apiKey) || empty($userId) || empty($provider)) {
        return [
            'success' => false,
            'message' => 'Ошибка: отсутствуют обязательные параметры (api_key, users_id, provider)',
            'data' => [],
            'headers' => []
        ];
    }

    // URL для получения полного ключа API
    $apiUrl = 'https://servero.space/plugins/api-keys-plugin/api/get_key.php';

    $payload = [
        'api_key' => $apiKey,         // Ключ авторизации (обязательно)
        'users_id' => $userId,        // ID пользователя (обязательно)
        'provider' => $provider,      // Провайдер (обязательно)
        'return_mode' => 'full',      // Запрашиваем полный ключ
    ];

    // Инициализируем cURL-запрос для получения полного ключа
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Проверяем ошибки cURL
    if ($curlError) {
        return [
            'success' => false,
            'message' => 'Ошибка cURL при получении ключа: ' . $curlError,
            'data' => [],
            'headers' => []
        ];
    }

    $result = json_decode($response, true);

    // Проверяем, был ли успешно получен полный ключ
    if (!$result || !isset($result['success']) || !$result['success']) {
        $errorCode = $result['code'] ?? 'UNKNOWN_ERROR';
        $errorMessage = $result['error'] ?? 'Неизвестная ошибка';
        
        // Обработка специфических ошибок
        if ($errorCode === 'INVALID_API_KEY') {
            return [
                'success' => false,
                'message' => 'Ошибка: неверный API-ключ',
                'data' => [],
                'headers' => []
            ];
        } elseif ($errorCode === 'FULL_KEY_FORBIDDEN') {
            return [
                'success' => false,
                'message' => 'Ошибка: выдача полного ключа запрещена в настройках плагина',
                'data' => [],
                'headers' => []
            ];
        } else {
            return [
                'success' => false,
                'message' => "Ошибка [$errorCode]: $errorMessage",
                'data' => [],
                'headers' => []
            ];
        }
    }

    // Получаем полный ключ из ответа
    $fullKey = $result['key'] ?? null;
    if (!$fullKey) {
        return [
            'success' => false,
            'message' => 'Ошибка: не удалось получить полный ключ из ответа API',
            'data' => [],
            'headers' => []
        ];
    }

    // Формируем URL для запроса к Google Apps Script с параметром sheet
    $appsScriptUrl = 'https://script.google.com/macros/s/' . $fullKey . '/exec';
    
    // Добавляем параметр sheet (название вкладки) к URL
    $appsScriptUrl .= '?sheet=' . urlencode($sheetName);

    // Диагностика: логируем запрашиваемую вкладку и URL (ключ скрыт)
    $safeUrl = 'https://script.google.com/macros/s/***HIDDEN***/exec?sheet=' . urlencode($sheetName);
    error_log("[Google Sheets] Запрос вкладки: '$sheetName', URL: $safeUrl");

    // Выполняем запрос к Google Sheets (без секретного ключа)
    $sheetData = readFromGoogleSheet($appsScriptUrl);

    // Диагностика: логируем первые строки ответа для проверки корректности данных
    if (is_array($sheetData) && !isset($sheetData['error'])) {
        $sampleData = isset($sheetData['data']) ? $sheetData['data'] : $sheetData;
        $firstRows = array_slice((array)$sampleData, 0, 2);
        error_log("[Google Sheets] Вкладка '$sheetName' — первые строки ответа: " . json_encode($firstRows, JSON_UNESCAPED_UNICODE));
    } elseif (isset($sheetData['error'])) {
        error_log("[Google Sheets] Вкладка '$sheetName' — ошибка ответа: " . $sheetData['error']);
    }

    // ========== ИСПРАВЛЕННАЯ ЧАСТЬ ==========
    // Проверяем, есть ли явная ошибка в ответе
    if (isset($sheetData['error'])) {
        return [
            'success' => false,
            'message' => 'Ошибка при получении данных из таблицы (вкладка: ' . $sheetName . '): ' . $sheetData['error'],
            'data' => [],
            'headers' => []
        ];
    }

    // Проверяем формат ответа от Google Apps Script
    // Вариант 1: данные пришли в формате {status: "success", data: [...]}
    if (isset($sheetData['status'])) {
        if ($sheetData['status'] !== 'success') {
            $errorMessage = isset($sheetData['message']) ? $sheetData['message'] : 'Неизвестная ошибка';
            return [
                'success' => false,
                'message' => 'Ошибка при получении данных из таблицы (вкладка: ' . $sheetName . '): ' . $errorMessage,
                'data' => [],
                'headers' => []
            ];
        }
        
        // Данные в формате с оберткой
        $data = $sheetData['data'] ?? [];
        
        if (empty($data)) {
            return [
                'success' => true,
                'message' => 'Данные в таблице (вкладка: ' . $sheetName . ') отсутствуют',
                'data' => [],
                'headers' => []
            ];
        }
        
        return [
            'success' => true,
            'message' => 'Все данные успешно получены из вкладки "' . $sheetName . '" (' . count($data) . ' строк)',
            'data' => $data,
            'headers' => !empty($data) && is_array($data[0]) ? array_keys($data[0]) : []
        ];
    }
    
    // Вариант 2: данные пришли напрямую (массив массивов)
    if (is_array($sheetData) && !isset($sheetData['status'])) {
        if (empty($sheetData)) {
            return [
                'success' => true,
                'message' => 'Данные в таблице (вкладка: ' . $sheetName . ') отсутствуют',
                'data' => [],
                'headers' => []
            ];
        }
        
        return [
            'success' => true,
            'message' => 'Все данные успешно получены из вкладки "' . $sheetName . '" (' . count($sheetData) . ' строк)',
            'data' => $sheetData,
            'headers' => !empty($sheetData) && is_array($sheetData[0]) ? array_keys($sheetData[0]) : []
        ];
    }
    
    // Если ничего не подошло - ошибка
    return [
        'success' => false,
        'message' => 'Ошибка: получен неизвестный формат данных от Google Sheets',
        'data' => [],
        'headers' => []
    ];
}

/**
 * Функция для чтения данных из Google Sheets через Apps Script
 * 
 * @param string $url URL-адрес Google Apps Script
 * @return array Декодированные JSON-данные или информация об ошибке
 */
function readFromGoogleSheet($url) {
    // Инициализируем cURL-сессию
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; DataExtractor/1.0)',
        CURLOPT_HEADER => false,
    ]);

    // Выполняем запрос
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Проверяем ошибки cURL
    if ($curlError) {
        return ['error' => "cURL Error: $curlError"];
    }

    // Проверяем HTTP-код ответа
    if ($httpCode !== 200) {
        return ['error' => "HTTP $httpCode"];
    }

    // Декодируем JSON-ответ
    $decodedResponse = json_decode($response, true);
    
    // Проверяем, был ли успешно декодирован JSON
    if (json_last_error() !== JSON_ERROR_NONE) {
        // Если не JSON, возможно это просто текст или HTML
        if (strpos($response, '<!DOCTYPE html>') !== false || strpos($response, '<html') !== false) {
            return ['error' => "Получен HTML-ответ вместо JSON, возможно проблема с аутентификацией или URL"];
        }
        return ['error' => 'JSON decode error: ' . json_last_error_msg()];
    }

    return $decodedResponse;
}