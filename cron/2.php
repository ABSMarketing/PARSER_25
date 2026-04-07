<?php
/**
 * Название файла:      2.php
 * Назначение:          Крон-скрипт для парсинга ссылок (<a> тэгов) со страниц,
 *                      URL которых хранятся в parsed_products.
 *
 * Логика:
 *  1. Получаем запись из parsed_products с самой старой updated_at и status IS NULL.
 *  2. Пытаемся загрузить страницу через прокси (API).
 *  3. Если прокси недоступен или произошла ошибка — загружаем напрямую.
 *  4. Извлекаем все тэги <a> из HTML.
 *  5. Сохраняем ссылки в таблицу parsed_links (с проверкой на дубликат).
 *  6. Обновляем статус записи в parsed_products.
 *
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-04-07
 */

// Определяем константу доступа для подключения к БД
define('APP_ACCESS', true);

// Подключение к базе данных (Singleton PDO)
require_once __DIR__ . '/../connect/db.php';

// Функция получения записи с самой дальней датой обновления
require_once __DIR__ . '/functions/get_oldest_product.php';

// Функции работы с прокси (получение, обновление статистики)
require_once __DIR__ . '/functions/proxy.php';

// Функции загрузки страницы, извлечения ссылок и сохранения в БД
require_once __DIR__ . '/functions/parse_links.php';

// ========================================
// НАСТРОЙКИ ПРОКСИ API
// ========================================
$proxyApiKey = 'b0a4b2f682dd07568287b3b7df1b7851ddd363bd0babd1a7f1cc605b3ae6908c';
$proxyUserId = 1;

// ========================================
// 1. ПОЛУЧАЕМ ЗАПИСЬ ДЛЯ ОБРАБОТКИ
// ========================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "🔍 Поиск необработанной записи...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$product = getOldestUnprocessedProduct($pdo);

if ($product === null) {
    echo "✅ Нет записей для обработки (все записи уже обработаны).\n";
    exit(0);
}

$productId  = (int) $product['id'];
$productUrl = $product['url'];

echo "📄 ID: {$productId}\n";
echo "🔗 URL: {$productUrl}\n\n";

// ========================================
// 2. ЗАГРУЗКА СТРАНИЦЫ (ПРОКСИ → НАПРЯМУЮ)
// ========================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "🌐 Загрузка страницы...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$pageResult  = null;
$proxyData   = null;
$usedProxy   = false;

// Шаг 2a: Пробуем через прокси
$proxyData = getProxy($proxyApiKey, $proxyUserId);

if ($proxyData !== null) {
    $proxyDisplay = ($proxyData['protocol'] ?? 'http') . '://' . $proxyData['host'] . ':' . $proxyData['port'];
    echo "🔄 Попытка через прокси: {$proxyDisplay}\n";

    $pageResult = fetchPage($productUrl, $proxyData);

    if ($pageResult['success']) {
        echo "✅ Страница загружена через прокси ({$pageResult['duration_ms']} мс)\n";
        updateProxy($proxyApiKey, $proxyUserId, (int) $proxyData['id'], true, $pageResult['duration_ms']);
        $usedProxy = true;
    } else {
        echo "❌ Ошибка через прокси: {$pageResult['error']}\n";
        updateProxy($proxyApiKey, $proxyUserId, (int) $proxyData['id'], false, $pageResult['duration_ms']);
        $pageResult = null; // сбрасываем, чтобы попробовать без прокси
    }
} else {
    echo "ℹ️  Прокси не доступен, переходим к прямому запросу.\n";
}

// Шаг 2b: Если прокси не сработал — загружаем напрямую
if ($pageResult === null) {
    echo "🔄 Загрузка напрямую (без прокси)...\n";
    $pageResult = fetchPage($productUrl);

    if ($pageResult['success']) {
        echo "✅ Страница загружена напрямую ({$pageResult['duration_ms']} мс)\n";
    } else {
        echo "❌ Ошибка загрузки: {$pageResult['error']}\n";
        echo "\n⛔ Не удалось загрузить страницу. Устанавливаем статус ошибки.\n";
        updateProductStatus($pdo, $productId, 2);
        exit(1);
    }
}

// ========================================
// 3. ИЗВЛЕЧЕНИЕ ССЫЛОК (<a> тэгов)
// ========================================
echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "🔗 Извлечение ссылок из HTML...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$links = extractLinks($pageResult['html'], $productUrl);
echo "📊 Найдено ссылок: " . count($links) . "\n\n";

if (empty($links)) {
    echo "ℹ️  На странице не найдено ни одной ссылки.\n";
    updateProductStatus($pdo, $productId, 1);
    echo "\n✅ Статус записи обновлён (1 — обработано, ссылок нет).\n";
    exit(0);
}

// ========================================
// 4. СОХРАНЕНИЕ В БД
// ========================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "💾 Сохранение ссылок в БД...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$result = saveLinksToDb($pdo, $productId, $links);

echo "💾 Вставлено новых: {$result['inserted']}\n";
echo "🔄 Обновлено:       {$result['updated']}\n";
echo "⏭️  Пропущено:      {$result['skipped']}\n";

// ========================================
// 5. ОБНОВЛЯЕМ СТАТУС ЗАПИСИ
// ========================================
updateProductStatus($pdo, $productId, 1);

echo "\n════════════════════════════════════════════\n";
echo "✅ ОБРАБОТКА ЗАВЕРШЕНА\n";
echo "════════════════════════════════════════════\n";
echo "ID записи:  {$productId}\n";
echo "URL:        {$productUrl}\n";
echo "Ссылок:     {$result['inserted']} вставлено, {$result['updated']} обновлено, {$result['skipped']} пропущено\n";
echo "Прокси:     " . ($usedProxy ? 'Да' : 'Нет') . "\n";
?>
