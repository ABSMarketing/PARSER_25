<?php
/**
 * Название файла:      4.php
 * Назначение:          Крон-скрипт для поиска цены товара через HTML-парсинг и DeepSeek.
 *
 * Логика:
 *  1. Получаем товар из parsed_products (price IS NULL, status = 2).
 *  2. Берём самую приоритетную ссылку из parsed_links (execution_status = 1).
 *  3. Загружаем HTML-страницу через прокси (с fallback на прямой запрос).
 *  4. Очищаем HTML и дозированно (по чанкам) отправляем в DeepSeek для поиска цены.
 *  5. При нахождении цены — записываем в parsed_products.price и product_url.
 *  6. При отсутствии — помечаем ссылку как обработанную, переходим к следующей.
 *
 * Скрипт рассчитан на многократный запуск по крону — каждый запуск обрабатывает
 * одну ссылку для одного товара.
 *
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-04-08
 */

// Увеличиваем лимит выполнения для крон-скрипта (5 минут)
set_time_limit(300);

// Отключаем буферизацию вывода для мгновенного отображения логов в крон-скрипте
ob_implicit_flush(true);
if (ob_get_level()) {
    ob_end_flush();
}

// Определяем константу доступа для подключения к БД
define('APP_ACCESS', true);

// Подключение к базе данных (Singleton PDO)
require_once __DIR__ . '/../connect/db.php';

// Функции для получения товара и ссылки, записи результатов
require_once __DIR__ . '/functions/get_product_for_pricing.php';

// Функции для получения описания товара (buildProductInfoString)
require_once __DIR__ . '/functions/get_product_with_links.php';

// Функции работы с прокси (получение, обновление статистики)
require_once __DIR__ . '/functions/proxy.php';

// Функции загрузки страницы через cURL
require_once __DIR__ . '/functions/parse_links.php';

// Функции получения ключа DeepSeek
require_once __DIR__ . '/functions/provider_deepseek.php';

// Функции очистки HTML, разбиения на чанки и поиска цены через DeepSeek
require_once __DIR__ . '/functions/deepseek_price_search.php';

// ========================================
// НАСТРОЙКИ API
// ========================================
$Api_Key     = 'dd28c37f63d429e5e0056b53f5a4800d4258b1d886a1dba7f34cd6948fe86d62';
$Users_ID    = 1;
$proxyApiKey = 'b0a4b2f682dd07568287b3b7df1b7851ddd363bd0babd1a7f1cc605b3ae6908c';
$proxyUserId = 1;

// ========================================
// 1. ПОЛУЧАЕМ ТОВАР ДЛЯ ПОИСКА ЦЕНЫ
// ========================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "🔍 Поиск товара для определения цены...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$product = getProductForPricing($pdo);

if ($product === null) {
    echo "✅ Нет товаров для поиска цены.\n";
    exit(0);
}

$productId = (int) $product['id'];

echo "📄 ID записи: {$productId}\n";
echo "🔗 Сайт: {$product['url']}\n";
echo "📂 Категория: {$product['category']}\n\n";

// ========================================
// 2. ФОРМИРУЕМ ОПИСАНИЕ ТОВАРА
// ========================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
$productInfo = buildProductInfoString($product);
echo "📝 Товар: {$productInfo}\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// ========================================
// 3. ПОЛУЧАЕМ ПРИОРИТЕТНУЮ ССЫЛКУ
// ========================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "🔗 Ссылка для парсинга:\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$link = getBestLinkForProduct($pdo, $productId);

if ($link === null) {
    echo "⚠️ Все ссылки исчерпаны, цена не найдена → price = 0\n";
    saveProductPrice($pdo, $productId, 0, null);
    exit(0);
}

$linkId  = (int) $link['id'];
$linkUrl = $link['url_a'];

echo "📄 Link ID: {$linkId}\n";
echo "🌐 URL: {$linkUrl}\n\n";

// ========================================
// 4. ПОЛУЧАЕМ API-КЛЮЧ DEEPSEEK
// ========================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "🔑 Получение API-ключа DeepSeek...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$keyResult = getDeepseekApiKey($Api_Key, $Users_ID);

if (!$keyResult['success']) {
    echo "❌ Ошибка получения ключа: {$keyResult['error']}\n";
    updateLinkExecutionStatus($pdo, $linkId, 2);
    touchProductUpdatedAt($pdo, $productId);
    exit(1);
}

$deepseekKey = $keyResult['key'];
echo "✅ Ключ DeepSeek получен\n\n";

// ========================================
// 5. ЗАГРУЗКА HTML-СТРАНИЦЫ (ПРОКСИ → НАПРЯМУЮ)
// ========================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "🌐 Загрузка HTML-страницы...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$pageResult = null;
$proxyData  = null;
$usedProxy  = false;

// Шаг 5a: Пробуем через прокси
$proxyData = getProxy($proxyApiKey, $proxyUserId);

if ($proxyData !== null) {
    $proxyDisplay = ($proxyData['protocol'] ?? 'http') . '://' . $proxyData['host'] . ':' . $proxyData['port'];
    echo "🔄 Попытка через прокси: {$proxyDisplay}\n";

    $pageResult = fetchPage($linkUrl, $proxyData);

    if ($pageResult['success']) {
        echo "✅ Страница загружена через прокси ({$pageResult['duration_ms']} мс)\n";
        updateProxy($proxyApiKey, $proxyUserId, (int) $proxyData['id'], true, $pageResult['duration_ms']);
        $usedProxy = true;
    } else {
        echo "❌ Ошибка через прокси: {$pageResult['error']}\n";
        updateProxy($proxyApiKey, $proxyUserId, (int) $proxyData['id'], false, $pageResult['duration_ms']);
        $pageResult = null;
    }
} else {
    echo "ℹ️  Прокси не доступен, переходим к прямому запросу.\n";
}

// Шаг 5b: Если прокси не сработал — загружаем напрямую
if ($pageResult === null) {
    echo "🔄 Загрузка напрямую (без прокси)...\n";
    $pageResult = fetchPage($linkUrl);

    if ($pageResult['success']) {
        echo "✅ Страница загружена напрямую ({$pageResult['duration_ms']} мс)\n";
    } else {
        echo "❌ Не удалось загрузить страницу: {$pageResult['error']}\n";
        updateLinkExecutionStatus($pdo, $linkId, 2);
        touchProductUpdatedAt($pdo, $productId);
        exit(1);
    }
}

$html = $pageResult['html'];
echo "📊 Размер HTML: " . mb_strlen($html) . " символов\n";

// ========================================
// 6. ОЧИСТКА HTML И ПОИСК ЦЕНЫ ЧЕРЕЗ DEEPSEEK
// ========================================
echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "🧹 Очистка HTML...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "🤖 Поиск цены через DeepSeek...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$searchResult = searchPriceInHtml($deepseekKey, $productInfo, $html);

// ========================================
// 7. ПЕРЕПОДКЛЮЧЕНИЕ К БД
// ========================================
$pdo = Database::reconnect();

// ========================================
// 8. ЗАПИСЬ РЕЗУЛЬТАТОВ В БД
// ========================================
echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "💾 Запись результатов в БД...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$finalPrice    = null;
$finalUrl      = null;
$finalLinkStatus = 2; // по умолчанию — обработано, не найдено

if ($searchResult['status'] === 'FOUND' && $searchResult['price'] > 0) {
    // Сценарий A: Цена найдена
    // Очищаем цену: оставляем только цифры и точку (убираем пробелы, символы валют и прочее)
    $finalPrice      = (float) preg_replace('/[^0-9.]/', '', (string) $searchResult['price']);
    $finalUrl        = $linkUrl;
    $finalLinkStatus = 3;

    saveProductPrice($pdo, $productId, $finalPrice, $finalUrl);
    updateLinkExecutionStatus($pdo, $linkId, $finalLinkStatus);

    echo "✅ parsed_products.price = " . number_format($finalPrice, 2, '.', '') . "\n";
    echo "✅ parsed_products.product_url = {$finalUrl}\n";
    echo "✅ parsed_links.execution_status = 3\n";

} elseif ($searchResult['status'] === 'REQUEST') {
    // Сценарий B: Цена по запросу
    $finalPrice      = 0;
    $finalUrl        = $linkUrl;
    $finalLinkStatus = 3;

    saveProductPrice($pdo, $productId, $finalPrice, $finalUrl);
    updateLinkExecutionStatus($pdo, $linkId, $finalLinkStatus);

    echo "✅ parsed_products.price = 0 (цена по запросу)\n";
    echo "✅ parsed_products.product_url = {$finalUrl}\n";
    echo "✅ parsed_links.execution_status = 3\n";

} elseif ($searchResult['status'] === 'EMPTY_HTML') {
    // HTML после очистки пустой
    updateLinkExecutionStatus($pdo, $linkId, 2);
    touchProductUpdatedAt($pdo, $productId);

    echo "⚠️ HTML после очистки пустой\n";
    echo "✅ parsed_links.execution_status = 2\n";

} else {
    // Сценарий C: Товар/цена не найдены после всех чанков
    updateLinkExecutionStatus($pdo, $linkId, 2);
    touchProductUpdatedAt($pdo, $productId);

    echo "⚠️ Цена не найдена (статус: {$searchResult['status']})\n";
    echo "✅ parsed_links.execution_status = 2\n";
    echo "✅ parsed_products.updated_at обновлён (сдвиг в конец очереди)\n";
}

// ========================================
// ИТОГ
// ========================================
echo "\n════════════════════════════════════════════\n";
echo "✅ ОБРАБОТКА ЗАВЕРШЕНА\n";
echo "════════════════════════════════════════════\n";
echo "ID записи:     {$productId}\n";
echo "Товар:         {$productInfo}\n";
echo "Ссылка:        {$linkUrl}\n";
if ($finalPrice !== null) {
    echo "Цена:          " . number_format($finalPrice, 2, '.', ' ') . "\n";
} else {
    echo "Цена:          не найдена\n";
}
echo "Чанков:        {$searchResult['chunks_processed']}/{$searchResult['chunks_total']}";
if ($searchResult['status'] === 'FOUND' || $searchResult['status'] === 'REQUEST') {
    echo " (остановлено после нахождения)";
}
echo "\n";
echo "Прокси:        " . ($usedProxy ? 'Да' : 'Нет') . "\n";
?>
