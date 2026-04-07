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

// === КОНФИГУРАЦИЯ КОЛОНОК ДЛЯ КАЖДОЙ ВКЛАДКИ ===
// startRow — номер строки Google Sheets, с которой начинаются данные (совпадает с нумерацией в таблице;
//             используется как нижняя граница при фильтрации в getValuesByRowRange)
// nameCol  — номер колонки с названием (обязательно)
// raidCol  — номер колонки с RAID-контроллером (null если отсутствует для данной вкладки)
// powerCol — номер колонки с блоками питания (null если отсутствует для данной вкладки)
$sheetConfig = [
    'Платформы'         => ['startRow' => 4, 'nameCol' => 2, 'raidCol' => 3, 'powerCol' => 4],
    'Процессоры'        => ['startRow' => 3, 'nameCol' => 2, 'raidCol' => null, 'powerCol' => null],
    'Оперативная память' => ['startRow' => 3, 'nameCol' => 2, 'raidCol' => null, 'powerCol' => null],
];

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
        $SheetName, $COLUMN_INDEX, $START_ROW
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
        
        // Определяем настройки колонок для данной вкладки из конфига
        // Используем дефолтные значения на случай если вкладка не описана в конфиге
        $cfg = $sheetConfig[$SheetName] ?? ['startRow' => 3, 'nameCol' => 2, 'raidCol' => null, 'powerCol' => null];
        $startRowForData = $cfg['startRow'];
        
        // Получаем данные для колонки с названиями
        $namesData = getValuesByRowRange($result['json_data'], $startRowForData, 1000, $cfg['nameCol']);
        
        // Получаем данные для рейд-колонки (только если задана в конфиге)
        $raidData = ($cfg['raidCol'] !== null)
            ? getValuesByRowRange($result['json_data'], $startRowForData, 1000, $cfg['raidCol'])
            : [];
        
        // Получаем данные для колонки блоков питания (только если задана в конфиге)
        $powerData = ($cfg['powerCol'] !== null)
            ? getValuesByRowRange($result['json_data'], $startRowForData, 1000, $cfg['powerCol'])
            : [];
        
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
        $hasRaid  = ($cfg['raidCol']  !== null);
        $hasPower = ($cfg['powerCol'] !== null);
        $headerSuffix = '/Название' . ($hasRaid ? '/Рейд' : '') . ($hasPower ? '/Блоки Питания' : '');
        echo "\n📋 Только строки с названиями (Вкладка/Колонка/Строка{$headerSuffix}):\n";
        
        if (empty($combinedData)) {
            echo "   Нет данных для отображения\n";
        } else {
            foreach ($combinedData as $row => $data) {
                if (!empty($data['name'])) {
                    $output = "   {$SheetName}/{$cfg['nameCol']}/{$row}";
                    $output .= "/" . ($data['name'] ?? '—');
                    if ($hasRaid) {
                        $output .= "/" . ($data['raid'] ?? '—');
                    }
                    if ($hasPower) {
                        $output .= "/" . ($data['power'] ?? '—');
                    }
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