<?php
// Функция для получения полного ключа API Google Sheets через API
require_once __DIR__ . '/functions/provider_google.php';

// Функция для извлечения данных из указанных колонок с определенной строки
require_once __DIR__ . '/functions/Google_Tabl.php';

// Функция для получения значения из JSON данных по координатам строки и колонки
require_once __DIR__ . '/functions/Google_Value.php';

/**
 * Функция для получения всех данных из Google Sheets через API
 * Получаем URL из раздела Платформы
 * 
 */
// === НАСТРОЙКИ ===
$Api_Key  = 'dd28c37f63d429e5e0056b53f5a4800d4258b1d886a1dba7f34cd6948fe86d62'; // api_key
$Provider = 'script.google'; // provider
$Users_ID = 1; // users_id

// === НАЗВАНИЯ ВКЛАДОК (МАССИВ) ===
$SheetNames = ['Платформы', 'Процессоры', 'Оперативная память'];
$SheetNames = ['Платформы', 'Процессоры', 'Оперативная память'];
$SheetNames = ['Оперативная память'];

$COLUMN_INDEX = null;
$START_ROW = null;

$columnDisplay = ($COLUMN_INDEX === null || $COLUMN_INDEX === -1) ? 'ВСЕ' : (is_numeric($COLUMN_INDEX) ? "колонка " . ($COLUMN_INDEX + 1) : "колонка '$COLUMN_INDEX'");
$startRowDisplay = ($START_ROW === null || $START_ROW === -1) ? 'ВСЕ' : "строка " . ($START_ROW + 2) . " (индекс данных: $START_ROW)";

echo "=== НАСТРОЙКИ ===\n";
echo "Вкладки для обработки: " . implode(', ', $SheetNames) . "\n";
echo "Колонка: $columnDisplay\n";
echo "Начало с: $startRowDisplay\n";
echo "========================================\n\n";

$allSheetsData = [];

foreach ($SheetNames as $index => $SheetName) {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "📄 ОБРАБОТКА ВКЛАДКИ " . ($index + 1) . " ИЗ " . count($SheetNames) . ": '$SheetName'\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
    $result = getColumnDataWithCoordinates(
        $Api_Key, $Users_ID, $Provider,
        $COLUMN_INDEX, $START_ROW, $SheetName
    );
    
    if ($result['success']) {
        echo "✅ " . $result['message'] . "\n\n";
        
        $allSheetsData[$SheetName] = [
            'success' => true,
            'message' => $result['message'],
            'json_data' => $result['json_data'],
            'data' => json_decode($result['json_data'], true)
        ];
        
        // 1. Получить все ссылки
        $serverData = getRowData($result['json_data'], 2);
        echo "\n📊 Ссылки:\n";
        foreach ($serverData as $cell) {
            if (empty(trim($cell['column_name'])) || !preg_match('/https?:\/\//', $cell['column_name'])) {
                continue;
            }
            echo "   {$SheetName}/{$cell['column']}/{$cell['row']}: {$cell['column_name']}\n";
        }
        
        // Определяем стартовую строку для каждой вкладки
        // Для Платформы данные начинаются с 5 строки (row=4 в индексации PHP)
        // Для Процессоров и Оперативной памяти данные начинаются с 4 строки (row=3 в индексации PHP)
        $startRowForData = 3; // По умолчанию с 4 строки (row=3)
        if ($SheetName == 'Платформы') {
            $startRowForData = 4; // Для Платформы с 5 строки (row=4)
        }
        
        // Получаем данные для всех колонок с правильной стартовой строкой
        $namesData = getValuesByRowRange($result['json_data'], $startRowForData, 1000, 2);
        $raidData = getValuesByRowRange($result['json_data'], $startRowForData, 1000, 3);
        $powerData = getValuesByRowRange($result['json_data'], $startRowForData, 1000, 4);
        
        // ОЧИЩАЕМ МАССИВЫ ПЕРЕД КАЖДОЙ ВКЛАДКОЙ
        $combinedData = [];
        
        // Добавляем названия
        foreach ($namesData as $item) {
            if (!empty($item['value']) || $item['value'] === 0 || $item['value'] === '0') {
                $row = $item['row'];
                $combinedData[$row] = [
                    'name' => $item['value'],
                    'raid' => null,
                    'power' => null
                ];
            }
        }
        
        // Добавляем Рейд (только если есть данные)
        if (!empty($raidData)) {
            foreach ($raidData as $item) {
                if (!empty($item['value']) || $item['value'] === 0 || $item['value'] === '0') {
                    $row = $item['row'];
                    if (!isset($combinedData[$row])) {
                        $combinedData[$row] = [
                            'name' => null,
                            'raid' => null,
                            'power' => null
                        ];
                    }
                    $combinedData[$row]['raid'] = $item['value'];
                }
            }
        }
        
        // Добавляем Блоки Питания (только если есть данные)
        if (!empty($powerData)) {
            foreach ($powerData as $item) {
                if (!empty($item['value']) || $item['value'] === 0 || $item['value'] === '0') {
                    $row = $item['row'];
                    if (!isset($combinedData[$row])) {
                        $combinedData[$row] = [
                            'name' => null,
                            'raid' => null,
                            'power' => null
                        ];
                    }
                    $combinedData[$row]['power'] = $item['value'];
                }
            }
        }
        
        // Выводим только строки с названиями
        echo "\n📋 Только строки с названиями (Вкладка/Колонка/Строка/Название/Рейд/Блоки Питания):\n";
        
        if (empty($combinedData)) {
            echo "   Нет данных для отображения\n";
        } else {
            foreach ($combinedData as $row => $data) {
                if (!empty($data['name'])) {
                    $output = "   {$SheetName}/2/{$row}";
                    $output .= "/" . ($data['name'] ?? '—');
                    $output .= "/" . ($data['raid'] ?? '—');
                    $output .= "/" . ($data['power'] ?? '—');
                    echo $output . "\n";
                }
            }
        }
        
    } else {
        echo "❌ " . $result['message'] . "\n\n";
        
        $allSheetsData[$SheetName] = [
            'success' => false,
            'message' => $result['message'],
            'json_data' => '',
            'data' => []
        ];
    }
    
    echo "\n";
}

echo "════════════════════════════════════════════\n";
echo "📊 ОБРАБОТКА ЗАВЕРШЕНА\n";
echo "════════════════════════════════════════════\n";
?>