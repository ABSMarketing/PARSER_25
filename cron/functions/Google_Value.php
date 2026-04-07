<?php
/**
 * Google_Value.php
 * Функция для получения значения из JSON данных по координатам строки и колонки
 * 
 * @param string $jsonData JSON-строка с данными (формат из getColumnDataWithCoordinates)
 * @param int $row Номер строки (например, 5, 6, 7...)
 * @param mixed $column Номер колонки (например, 1, 2, 3...) или имя колонки (например 'пппппп')
 * @return array|null Массив с данными ячейки или null если не найдено
 * 
 * Возвращаемый массив:
 * [
 *     'value' => mixed,        // Значение ячейки
 *     'row' => int,            // Номер строки
 *     'column' => int,         // Номер колонки
 *     'column_name' => string, // Имя колонки
 *     'found' => bool          // Найдено или нет
 * ]
 */
function getValueByCoordinates($jsonData, $row, $column) {
    // Декодируем JSON в массив
    $data = json_decode($jsonData, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'found' => false,
            'error' => 'Ошибка декодирования JSON: ' . json_last_error_msg()
        ];
    }
    
    if (empty($data)) {
        return [
            'found' => false,
            'error' => 'Данные пусты'
        ];
    }
    
    // Определяем, ищем по номеру колонки или по имени
    $searchByColumnName = is_string($column);
    
    foreach ($data as $item) {
        // Проверяем совпадение строки
        if ($item['row'] == $row) {
            
            if ($searchByColumnName) {
                // Поиск по имени колонки
                if (isset($item['column_name']) && $item['column_name'] === $column) {
                    return [
                        'found' => true,
                        'value' => $item['value'],
                        'row' => $item['row'],
                        'column' => $item['column'],
                        'column_name' => $item['column_name']
                    ];
                }
            } else {
                // Поиск по номеру колонки
                if ($item['column'] == $column) {
                    return [
                        'found' => true,
                        'value' => $item['value'],
                        'row' => $item['row'],
                        'column' => $item['column'],
                        'column_name' => $item['column_name'] ?? 'col_' . $item['column']
                    ];
                }
            }
        }
    }
    
    // Если не найдено
    return [
        'found' => false,
        'row' => $row,
        'column' => $column,
        'message' => "Ячейка (строка $row, " . ($searchByColumnName ? "колонка '$column'" : "колонка $column") . ") не найдена"
    ];
}

/**
 * Функция для получения значения по координатам с возможностью указать диапазон строк
 * 
 * @param string $jsonData JSON-строка с данными
 * @param int $startRow Начальная строка
 * @param int $endRow Конечная строка (если null, то до конца)
 * @param mixed $column Номер или имя колонки
 * @return array Массив найденных значений
 */
function getValuesByRowRange($jsonData, $startRow, $endRow = null, $column = null) {
    $data = json_decode($jsonData, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => 'Ошибка декодирования JSON: ' . json_last_error_msg()];
    }
    
    $result = [];
    
    foreach ($data as $item) {
        if ($item['row'] >= $startRow && ($endRow === null || $item['row'] <= $endRow)) {
            if ($column !== null) {
                $searchByColumnName = is_string($column);
                
                if ($searchByColumnName) {
                    if (isset($item['column_name']) && $item['column_name'] === $column) {
                        $result[] = $item;
                    }
                } else {
                    if ($item['column'] == $column) {
                        $result[] = $item;
                    }
                }
            } else {
                $result[] = $item;
            }
        }
    }
    
    return $result;
}

/**
 * Функция для получения всех значений для конкретного сервера (по строке)
 * 
 * @param string $jsonData JSON-строка с данными
 * @param int $row Номер строки
 * @return array Массив всех ячеек в строке
 */
function getRowData($jsonData, $row) {
    $data = json_decode($jsonData, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => 'Ошибка декодирования JSON: ' . json_last_error_msg()];
    }
    
    $result = [];
    
    foreach ($data as $item) {
        if ($item['row'] == $row) {
            $result[] = $item;
        }
    }
    
    return $result;
}

/**
 * Функция для получения всех значений для конкретной колонки
 * 
 * @param string $jsonData JSON-строка с данными
 * @param mixed $column Номер или имя колонки
 * @return array Массив всех значений в колонке
 */
function getColumnData($jsonData, $column) {
    $data = json_decode($jsonData, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => 'Ошибка декодирования JSON: ' . json_last_error_msg()];
    }
    
    $result = [];
    $searchByColumnName = is_string($column);
    
    foreach ($data as $item) {
        if ($searchByColumnName) {
            if (isset($item['column_name']) && $item['column_name'] === $column) {
                $result[] = $item;
            }
        } else {
            if ($item['column'] == $column) {
                $result[] = $item;
            }
        }
    }
    
    return $result;
}

/**
 * 
// ... после того как получили $result['json_data'] ...

// ========== ПРИМЕРЫ ИСПОЛЬЗОВАНИЯ ФУНКЦИЙ ==========

// 1. Получить конкретную ячейку по номеру строки и колонки
$cellValue = getValueByCoordinates($result['json_data'], 5, 2);
if ($cellValue['found']) {
    echo "\n🔍 Поиск ячейки (строка 5, колонка 2):\n";
    echo "   Значение: '{$cellValue['value']}'\n";
    echo "   Имя колонки: '{$cellValue['column_name']}'\n";
}

// 2. Получить ячейку по номеру строки и имени колонки
$cellValue2 = getValueByCoordinates($result['json_data'], 5, 'пппппп');
if ($cellValue2['found']) {
    echo "\n🔍 Поиск ячейки (строка 5, колонка 'пппппп'):\n";
    echo "   Значение: '{$cellValue2['value']}'\n";
}

// 3. Получить все данные для конкретного сервера (строки 5)
$serverData = getRowData($result['json_data'], 5);
echo "\n📊 Данные сервера (строка 5):\n";
foreach ($serverData as $cell) {
    echo "   {$cell['column_name']}: {$cell['value']}\n";
}

// 4. Получить все цены из колонки 'пппппп'
$prices = getColumnData($result['json_data'], 'пппппп');
echo "\n💰 Все цены из колонки 'пппппп':\n";
foreach ($prices as $price) {
    if (!empty($price['value']) && is_numeric($price['value'])) {
        echo "   Строка {$price['row']}: {$price['value']} руб.\n";
    }
}

// 5. Получить диапазон строк (с 5 по 10) из колонки 2
$rangeData = getValuesByRowRange($result['json_data'], 5, 10, 2);
echo "\n📋 Данные строк 5-10 из колонки 2:\n";
foreach ($rangeData as $item) {
    echo "   Строка {$item['row']}: {$item['value']}\n";
}
 */