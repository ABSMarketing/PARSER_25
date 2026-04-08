<?php
/**
 * Название файла:      3.php
 * Назначение:          Крон-скрипт для получения товара из parsed_products
 *                      (status=1, price IS NULL, product_url IS NULL),
 *                      получения связанных ссылок из parsed_links,
 *                      и отправки данных в DeepSeek для классификации ссылок
 *                      по релевантности к товару (приоритет по 10-балльной шкале).
 *
 * Логика:
 *  1. Получаем одну запись из parsed_products с фильтром:
 *     status = 1, price IS NULL, product_url IS NULL
 *     (сортировка по updated_at ASC — самая старая запись первой, LIMIT 1).
 *  2. Из найденной записи формируем строку: name | raid (если есть) | power_supply (если есть).
 *  3. По id найденной записи получаем все связанные записи из parsed_links.
 *  4. Получаем API-ключ DeepSeek через servero.space.
 *  5. Отправляем товар и ссылки в DeepSeek для классификации
 *     (priority 1-10 по 10-балльной шкале, 10 — максимальная вероятность).
 *  6. При успехе: обновляем parsed_links (execution_status, priority),
 *     обновляем parsed_products (status=2, updated_at).
 *  7. При неудаче: обновляем только updated_at в parsed_products.
 *
 * Автор:               Команда разработки
 * Версия:              3.0
 * Дата создания:       2026-04-08
 */

// Определяем константу доступа для подключения к БД
define('APP_ACCESS', true);

// Подключение к базе данных (Singleton PDO)
require_once __DIR__ . '/../connect/db.php';

// Функции для получения товара и связанных ссылок
require_once __DIR__ . '/functions/get_product_with_links.php';

// Функции для получения ключа DeepSeek и классификации ссылок
require_once __DIR__ . '/functions/provider_deepseek.php';

// ========================================
// НАСТРОЙКИ API
// ========================================
$Api_Key  = 'dd28c37f63d429e5e0056b53f5a4800d4258b1d886a1dba7f34cd6948fe86d62';
$Users_ID = 1;

// ========================================
// 1. ПОЛУЧАЕМ ЗАПИСЬ ДЛЯ ОБРАБОТКИ
// ========================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "🔍 Поиск записи (status=1, price IS NULL, product_url IS NULL)...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$product = getProductForProcessing($pdo);

if ($product === null) {
    echo "✅ Нет записей для обработки.\n";
    exit(0);
}

$productId = (int) $product['id'];

echo "📄 ID записи: {$productId}\n";
echo "🔗 URL: {$product['url']}\n";
echo "📂 Категория: {$product['category']}\n\n";

// ========================================
// 2. ФОРМИРУЕМ СТРОКУ ОПИСАНИЯ ТОВАРА
// ========================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "📝 Информация о товаре:\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$productInfo = buildProductInfoString($product);
echo $productInfo . "\n\n";

// ========================================
// 3. ПОЛУЧАЕМ СВЯЗАННЫЕ ЗАПИСИ ИЗ parsed_links
// ========================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "🔗 Связанные ссылки (parsed_links):\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$links = getLinksByParentId($pdo, $productId);

$maxLinksPerBatch = 50;
if (count($links) > $maxLinksPerBatch) {
    echo "⚠️  Ссылок слишком много (" . count($links) . "), обрабатываем первые {$maxLinksPerBatch}\n";
    $links = array_slice($links, 0, $maxLinksPerBatch);
}

if (empty($links)) {
    echo "ℹ️  Связанных ссылок не найдено.\n";
} else {
    echo "📊 Найдено ссылок: " . count($links) . "\n\n";

    $jsonOutput = json_encode($links, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    echo $jsonOutput . "\n";
}

// ========================================
// 4. ПОЛУЧАЕМ API-КЛЮЧ DEEPSEEK
// ========================================
echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "🔑 Получение API-ключа DeepSeek...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$keyResult = getDeepseekApiKey($Api_Key, $Users_ID);

if (!$keyResult['success']) {
    echo "❌ Ошибка получения ключа: {$keyResult['error']}\n";
    exit(1);
}

echo "✅ Ключ DeepSeek получен (режим: full)\n";

// ========================================
// 5. ОТПРАВКА В DEEPSEEK ДЛЯ КЛАССИФИКАЦИИ
// ========================================
echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "🤖 Отправка данных в DeepSeek...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

if (empty($links)) {
    echo "ℹ️  Нет ссылок для классификации.\n";
    // Нет ссылок — обновляем только дату
    updateProductStatus($pdo, $productId, false);
    echo "\n📝 Обновлена дата updated_at для записи #{$productId} (нет ссылок).\n";
} else {
    echo "📤 Товар: {$productInfo}\n";
    echo "📤 Ссылок для анализа: " . count($links) . "\n\n";

    $deepseekResult = classifyLinksWithDeepseek($keyResult['key'], $productInfo, $links);

    if (!$deepseekResult['success']) {
        echo "❌ Ошибка DeepSeek: {$deepseekResult['error']}\n";
        if ($deepseekResult['raw']) {
            echo "📋 Ответ: {$deepseekResult['raw']}\n";
        }

        // Неудача — обновляем только дату
        updateProductStatus($pdo, $productId, false);
        echo "\n📝 Обновлена дата updated_at для записи #{$productId} (ошибка DeepSeek).\n";
        exit(1);
    }

    echo "✅ Классификация получена от DeepSeek\n\n";

    // ========================================
    // 6. РЕЗУЛЬТАТ КЛАССИФИКАЦИИ
    // ========================================
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "📊 Результат классификации DeepSeek (шкала 1-10):\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    $classifiedJson = json_encode($deepseekResult['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    echo $classifiedJson . "\n";

    $highPriority = 0;
    $lowPriority  = 0;
    foreach ($deepseekResult['data'] as $item) {
        $p = (int) ($item['priority'] ?? 0);
        if ($p >= 7) {
            $highPriority++;
        } else {
            $lowPriority++;
        }
    }
    echo "\n📈 Высокий приоритет (7-10): {$highPriority}\n";
    echo "📉 Низкий приоритет (1-6): {$lowPriority}\n";

    // ========================================
    // 7. СОХРАНЕНИЕ РЕЗУЛЬТАТОВ В parsed_links
    // ========================================
    echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "💾 Сохранение результатов в parsed_links...\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    $updatedCount = updateParsedLinks($pdo, $deepseekResult['data']);
    echo "✅ Обновлено записей в parsed_links: {$updatedCount}\n";

    // ========================================
    // 8. ОБНОВЛЕНИЕ СТАТУСА В parsed_products
    // ========================================
    echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "📝 Обновление статуса в parsed_products...\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    updateProductStatus($pdo, $productId, true);
    echo "✅ Запись #{$productId}: status = 2, updated_at обновлена.\n";
}

// ========================================
// ИТОГ
// ========================================
echo "\n════════════════════════════════════════════\n";
echo "✅ ОБРАБОТКА ЗАВЕРШЕНА\n";
echo "════════════════════════════════════════════\n";
echo "ID записи:   {$productId}\n";
echo "Товар:       {$productInfo}\n";
echo "Ссылок:      " . count($links) . "\n";
if (!empty($links) && isset($highPriority)) {
    echo "Высокий приоритет (7-10): {$highPriority}\n";
}
?>
