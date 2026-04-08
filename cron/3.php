<?php
/**
 * Название файла:      3.php
 * Назначение:          Крон-скрипт для получения товара из parsed_products
 *                      (status=1, price IS NULL, product_url IS NULL)
 *                      и вывода связанных ссылок из parsed_links в формате JSON.
 *
 * Логика:
 *  1. Получаем одну запись из parsed_products с фильтром:
 *     status = 1, price IS NULL, product_url IS NULL (LIMIT 1).
 *  2. Из найденной записи формируем строку: name | raid (если есть) | power_supply (если есть).
 *  3. По id найденной записи получаем все связанные записи из parsed_links.
 *  4. Формируем JSON из parsed_links (id, html, execution_status, priority).
 *  5. Выводим собранную информацию.
 *
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-04-08
 */

// Определяем константу доступа для подключения к БД
define('APP_ACCESS', true);

// Подключение к базе данных (Singleton PDO)
require_once __DIR__ . '/../connect/db.php';

// Функции для получения товара и связанных ссылок
require_once __DIR__ . '/functions/get_product_with_links.php';

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

if (empty($links)) {
    echo "ℹ️  Связанных ссылок не найдено.\n";
} else {
    echo "📊 Найдено ссылок: " . count($links) . "\n\n";

    // Формируем JSON из данных parsed_links
    $jsonOutput = json_encode($links, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    echo $jsonOutput . "\n";
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
?>
