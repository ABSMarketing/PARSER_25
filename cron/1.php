<?php
// Определяем константу доступа для подключения к БД
define('APP_ACCESS', true);

// Подключение к базе данных (Singleton PDO)
require_once __DIR__ . '/../connect/db.php';

// Функция для получения полного ключа API Google Sheets через API
require_once __DIR__ . '/functions/provider_google.php';

// Функция для извлечения данных из указанных колонок с определенной строки
require_once __DIR__ . '/functions/Google_Tabl.php';

// Функция для получения значения из JSON данных по координатам строки и колонки
require_once __DIR__ . '/functions/Google_Value.php';

// Функция для сохранения данных в БД
require_once __DIR__ . '/functions/save_to_db.php';

// Генерируем уникальный ID для этой сессии синхронизации
$batchId = generateBatchId($pdo);
echo "🔄 Batch ID синхронизации: {$batchId}\n\n";

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
        
        // 1. Собрать все ссылки из строки 2, очистить от лишних символов в конце
        $serverData = getRowData($result['json_data'], 2);
        $urls = [];
        foreach ($serverData as $cell) {
            $val = trim($cell['column_name']);
            if (empty($val) || !preg_match('/https?:\/\//', $val)) {
                continue;
            }
            // Убираем лишние символы в конце (цифры, пробелы и т.п. после домена/пути)
            $cleanUrl = preg_replace('/[\s\d]+$/', '', $val);
            $urls[] = $cleanUrl;
        }
        
        // Определяем конфигурацию для каждой вкладки
        $defaultSheetConfig = ['startRow' => 3, 'nameCol' => 2, 'raidCol' => null, 'powerCol' => null];
        $sheetConfig = [
            'Платформы'          => ['startRow' => 4, 'nameCol' => 2, 'raidCol' => 3, 'powerCol' => 4],
            'Процессоры'         => $defaultSheetConfig,
            'Оперативная память' => $defaultSheetConfig,
        ];
        $cfg = $sheetConfig[$SheetName] ?? $defaultSheetConfig;
        $startRowForData = $cfg['startRow'];
        
        // Получаем данные для всех колонок с правильной стартовой строкой
        $namesData = getValuesByRowRange($result['json_data'], $startRowForData, 1000, $cfg['nameCol']);
        $raidData  = $cfg['raidCol']  !== null ? getValuesByRowRange($result['json_data'], $startRowForData, 1000, $cfg['raidCol'])  : [];
        $powerData = $cfg['powerCol'] !== null ? getValuesByRowRange($result['json_data'], $startRowForData, 1000, $cfg['powerCol']) : [];
        
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
        
        // Выводим в новом формате и сохраняем в БД: для каждого сайта — все товары
        echo "\n";
        $savedCount   = 0;
        $skippedCount = 0;

        foreach ($urls as $url) {
            foreach ($combinedData as $row => $data) {
                if (!empty($data['name'])) {
                    $name  = $data['name'];
                    $raid  = $data['raid']  ?? '—';
                    $power = $data['power'] ?? '—';

                    $line = "{$url}|{$SheetName}|{$cfg['nameCol']}|{$row}|{$name}|{$raid}|{$power}";
                    echo $line . "\n";

                    // Сохраняем / обновляем запись в БД
                    try {
                        if (parseLineAndSave($pdo, $line, $batchId)) {
                            $savedCount++;
                        } else {
                            $skippedCount++;
                        }
                    } catch (PDOException $e) {
                        echo "⚠️  Ошибка БД (строка {$row}): " . $e->getMessage() . "\n";
                        $skippedCount++;
                    }
                }
            }
        }

        echo "\n💾 Сохранено/обновлено: {$savedCount}, Пропущено: {$skippedCount}\n";
        
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

$deletedCount = deleteOrphanedRecords($pdo, $batchId);
echo "🗑️  Удалено устаревших записей: {$deletedCount}\n";
?>