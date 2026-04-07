<?php
/**
 * Google_Tabl.php
 * Функция для извлечения данных из указанных колонок с определенной строки Google
 * 
 * Функция извлекает значения из указанных колонок, начиная с определенной строки
 * и возвращает их в формате JSON с координатами (номер строки и номер колонки).
 *
 * @param array $allData Все данные из таблицы
 * @param mixed $columnSpecifier Индекс колонки (0, 1, 2...), имя колонки (например 'A', 'Name'), 
 *                              или -1/null для извлечения ВСЕХ колонок
 * @param int|null $startRow Индекс строки, с которой начинать извлечение (0 - первая строка данных)
 *                           Если значение null или -1, извлекаются все строки
 * @return array Массив с извлеченными данными и координатами
 */
function extractColumnWithCoordinates($allData, $columnSpecifier = 0, $startRow = null) {
    $extractedData = [];
    
    // Если startRow не задан или равен -1, начинаем с первой строки (индекс 0)
    $actualStartRow = ($startRow === null || $startRow === -1) ? 0 : max(0, (int)$startRow);
    
    // Определяем, нужно ли извлекать все колонки
    $extractAllColumns = ($columnSpecifier === null || $columnSpecifier === -1);
    
    // Получаем заголовки колонок, если данные в формате объектов
    $allHeaders = [];
    if (!empty($allData) && is_array($allData[0]) && !array_is_list($allData[0])) {
        $allHeaders = array_keys($allData[0]);
    }
    
    // Определяем список колонок для извлечения
    $columnsToExtract = [];
    
    if ($extractAllColumns) {
        // Извлекаем все колонки
        if (!empty($allHeaders)) {
            // Если данные в формате объектов, используем имена колонок
            $columnsToExtract = $allHeaders;
        } else {
            // Если данные в формате индексированного массива, определяем максимальное количество колонок
            $maxCols = 0;
            foreach ($allData as $row) {
                if (is_array($row)) {
                    $maxCols = max($maxCols, count($row));
                }
            }
            $columnsToExtract = range(0, $maxCols - 1);
        }
    } else {
        // Извлекаем указанную колонку
        if (!empty($allHeaders)) {
            if (is_numeric($columnSpecifier) && isset($allHeaders[$columnSpecifier])) {
                $columnsToExtract = [$allHeaders[$columnSpecifier]];
            } elseif (is_string($columnSpecifier) && in_array($columnSpecifier, $allHeaders)) {
                $columnsToExtract = [$columnSpecifier];
            } else {
                // Если указанная колонка не найдена, пробуем использовать как индекс
                if (is_numeric($columnSpecifier)) {
                    $columnsToExtract = [(int)$columnSpecifier];
                } else {
                    return [];
                }
            }
        } else {
            $columnsToExtract = [is_numeric($columnSpecifier) ? (int)$columnSpecifier : 0];
        }
    }
    
    // Если колонки не определены, возвращаем пустой массив
    if (empty($columnsToExtract)) {
        return [];
    }
    
    // Обрабатываем каждую строку, начиная с указанной
    for ($rowIndex = $actualStartRow; $rowIndex < count($allData); $rowIndex++) {
        $currentRow = $allData[$rowIndex];
        
        // Если данные в формате объектов (ассоциативный массив)
        if (!array_is_list($currentRow)) {
            foreach ($columnsToExtract as $colSpecifier) {
                $columnName = is_string($colSpecifier) ? $colSpecifier : (isset($allHeaders[$colSpecifier]) ? $allHeaders[$colSpecifier] : "col_$colSpecifier");
                $value = isset($currentRow[$columnName]) ? $currentRow[$columnName] : '';
                $colIndex = is_string($colSpecifier) ? array_search($colSpecifier, $allHeaders) : $colSpecifier;
                
                $extractedData[] = [
                    'value' => $value,
                    'row' => $rowIndex + 2, // Нумерация строк начинается с 2, потому что строка 1 - заголовки
                    'column' => $colIndex + 1, // Нумерация колонок начинается с 1
                    'column_name' => $columnName
                ];
            }
        } 
        // Если данные в формате индексированного массива
        else {
            foreach ($columnsToExtract as $colIndex) {
                $value = isset($currentRow[$colIndex]) ? $currentRow[$colIndex] : '';
                
                $extractedData[] = [
                    'value' => $value,
                    'row' => $rowIndex + 2,
                    'column' => $colIndex + 1,
                    'column_index' => $colIndex
                ];
            }
        }
    }
    
    return $extractedData;
}

/**
 * Функция для получения данных из указанных колонок строк в формате JSON с координатами
 * 
 * @param string $apiKey Ключ авторизации (обязательно)
 * @param int $userId ID пользователя (обязательно)
 * @param string $provider Провайдер (обязательно)
 * @param string $sheetName Название вкладки в Google Sheets (обязательно)
 * @param mixed $columnSpecifier Индекс колонки (0, 1, 2...), имя колонки, или -1/null для ВСЕХ колонок
 * @param int|null $startRow Индекс строки, с которой начинать извлечение (0 - первая строка данных)
 *                           Если значение null или -1, извлекаются все строки
 * @return array Массив с результатами:
 *               - 'success' => bool: успешность операции
 *               - 'message' => string: текстовое сообщение о результате
 *               - 'json_data' => string: JSON-строка с извлеченными данными и координатами
 */
function getColumnDataWithCoordinates($apiKey, $userId, $provider, $sheetName, $columnSpecifier = 0, $startRow = null) {
    // Сначала получаем все данные с указанием вкладки
    $allDataResult = getAllDataFromGoogleSheets($apiKey, $userId, $provider, $sheetName);
    
    if (!$allDataResult['success']) {
        return [
            'success' => false,
            'message' => $allDataResult['message'],
            'json_data' => ''
        ];
    }
    
    // Извлекаем указанные колонки со строками, начиная с указанной, и координатами
    $columnData = extractColumnWithCoordinates($allDataResult['data'], $columnSpecifier, $startRow);
    
    // Преобразуем в JSON
    $jsonData = json_encode($columnData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
    // Формируем сообщение
    $extractAllColumns = ($columnSpecifier === null || $columnSpecifier === -1);
    
    if ($extractAllColumns) {
        $identifier = "ВСЕХ колонок";
    } elseif (is_numeric($columnSpecifier)) {
        $identifier = "колонки " . ($columnSpecifier + 1);
    } else {
        $identifier = "колонки '$columnSpecifier'";
    }
    
    $totalRows = count($columnData);
    
    if ($startRow === null || $startRow === -1) {
        $message = "Данные из вкладки '$sheetName', $identifier успешно извлечены (все $totalRows значений)";
    } else {
        $startRowNum = $startRow + 2;
        $message = "Данные из вкладки '$sheetName', $identifier успешно извлечены (начиная со строки $startRowNum, $totalRows значений)";
    }
    
    return [
        'success' => true,
        'message' => $message,
        'json_data' => $jsonData
    ];
}