<?php
/**
 * Название файла:      extract_price_structured.php
 * Назначение:          Извлечение цены товара из структурированных данных HTML
 *                      (JSON-LD, микроданные, Open Graph, data-атрибуты)
 *                      БЕЗ обращения к DeepSeek.
 *
 * Используется в:      cron/functions/deepseek_price_search.php
 *
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-04-09
 */

/**
 * Пытается извлечь цену товара из структурированных данных HTML.
 * Проверяет в порядке приоритета:
 *  1. JSON-LD (Schema.org Product/Offer)
 *  2. Микроданные (itemprop="price")
 *  3. Open Graph (product:price:amount)
 *  4. data-атрибуты (data-price, data-product-price)
 *
 * @param  string $html        Исходный HTML-код страницы
 * @param  string $productInfo Описание товара (для логирования)
 * @return array  ['found'=>bool, 'price'=>float|null, 'currency'=>string|null,
 *                 'source'=>string|null, 'product_name'=>string|null,
 *                 'is_request'=>bool]
 */
function extractPriceFromStructuredData(string $html, string $productInfo): array
{
    $default = [
        'found'        => false,
        'price'        => null,
        'currency'     => null,
        'source'       => null,
        'product_name' => null,
        'is_request'   => false,
    ];

    // 1. JSON-LD (Schema.org)
    $result = extractPriceFromJsonLd($html);
    if ($result['found']) {
        echo "🏷️ Цена найдена в JSON-LD (Schema.org): {$result['price']} {$result['currency']}\n";
        return $result;
    }

    // 2. Микроданные (itemprop="price")
    $result = extractPriceFromMicrodata($html);
    if ($result['found']) {
        echo "🏷️ Цена найдена в микроданных (itemprop): {$result['price']} {$result['currency']}\n";
        return $result;
    }

    // 3. Open Graph (product:price:amount)
    $result = extractPriceFromOpenGraph($html);
    if ($result['found']) {
        echo "🏷️ Цена найдена в Open Graph: {$result['price']} {$result['currency']}\n";
        return $result;
    }

    // 4. data-атрибуты (data-price, data-product-price)
    $result = extractPriceFromDataAttributes($html);
    if ($result['found']) {
        echo "🏷️ Цена найдена в data-атрибуте: {$result['price']}\n";
        return $result;
    }

    return $default;
}

/**
 * Извлекает цену из блоков JSON-LD (<script type="application/ld+json">).
 * Ищет объекты Schema.org типа Product с вложенными Offer/AggregateOffer.
 *
 * @param  string $html Исходный HTML
 * @return array  ['found'=>bool, 'price'=>float|null, 'currency'=>string|null,
 *                 'source'=>string|null, 'product_name'=>string|null,
 *                 'is_request'=>bool]
 */
function extractPriceFromJsonLd(string $html): array
{
    $default = [
        'found'        => false,
        'price'        => null,
        'currency'     => null,
        'source'       => 'json-ld',
        'product_name' => null,
        'is_request'   => false,
    ];

    // Извлекаем все блоки JSON-LD
    if (!preg_match_all('/<script\s+type=["\']application\/ld\+json["\']\s*>([\s\S]*?)<\/script>/i', $html, $matches)) {
        return $default;
    }

    foreach ($matches[1] as $jsonStr) {
        $data = json_decode(trim($jsonStr), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            continue;
        }

        // JSON-LD может быть массивом объектов или одним объектом
        $items = isset($data[0]) ? $data : [$data];

        // Также обрабатываем @graph
        if (isset($data['@graph']) && is_array($data['@graph'])) {
            $items = array_merge($items, $data['@graph']);
        }

        foreach ($items as $item) {
            $result = parseJsonLdProduct($item);
            if ($result['found']) {
                return $result;
            }
        }
    }

    return $default;
}

/**
 * Рекурсивно парсит объект JSON-LD для поиска Product/Offer с ценой.
 *
 * @param  array $item Объект JSON-LD
 * @return array Результат поиска цены
 */
function parseJsonLdProduct(array $item): array
{
    $default = [
        'found'        => false,
        'price'        => null,
        'currency'     => null,
        'source'       => 'json-ld',
        'product_name' => null,
        'is_request'   => false,
    ];

    $type = $item['@type'] ?? '';

    // Нормализуем тип (может быть массивом)
    if (is_array($type)) {
        $type = implode(',', $type);
    }
    $typeLower = mb_strtolower($type);

    // Ищем Product, IndividualProduct, ProductModel
    $isProduct = str_contains($typeLower, 'product');

    if (!$isProduct) {
        return $default;
    }

    $productName = $item['name'] ?? null;

    // Ищем offers
    $offers = $item['offers'] ?? $item['Offers'] ?? null;
    if ($offers === null) {
        return $default;
    }

    // offers может быть одним объектом или массивом
    if (isset($offers['@type']) || isset($offers['price'])) {
        $offerList = [$offers];
    } elseif (isset($offers[0])) {
        $offerList = $offers;
    } else {
        $offerList = [$offers];
    }

    foreach ($offerList as $offer) {
        $offerType = mb_strtolower($offer['@type'] ?? '');

        // AggregateOffer — берём lowPrice или highPrice
        if (str_contains($offerType, 'aggregateoffer')) {
            $price = $offer['lowPrice'] ?? $offer['highPrice'] ?? $offer['price'] ?? null;
        } else {
            $price = $offer['price'] ?? null;
        }

        // Проверяем availability — если "по запросу" или discontinued
        $availability = mb_strtolower($offer['availability'] ?? '');
        if (str_contains($availability, 'discontinued') || str_contains($availability, 'soldout')) {
            continue;
        }

        if ($price !== null && $price !== '' && $price !== 0 && $price !== '0') {
            $cleanPrice = parseNumericPrice((string) $price);
            if ($cleanPrice !== null && $cleanPrice > 0) {
                return [
                    'found'        => true,
                    'price'        => $cleanPrice,
                    'currency'     => $offer['priceCurrency'] ?? null,
                    'source'       => 'json-ld',
                    'product_name' => $productName,
                    'is_request'   => false,
                ];
            }
        }

        // Если цена 0 или отсутствует — может быть "по запросу"
        if ($price === null || $price === '' || $price === 0 || $price === '0' || $price === '0.00') {
            // Проверяем наличие товара (если InStock но цена 0 — вероятно "по запросу")
            if (str_contains($availability, 'instock') || str_contains($availability, 'preorder')) {
                return [
                    'found'        => true,
                    'price'        => null,
                    'currency'     => $offer['priceCurrency'] ?? null,
                    'source'       => 'json-ld',
                    'product_name' => $productName,
                    'is_request'   => true,
                ];
            }
        }
    }

    return $default;
}

/**
 * Извлекает цену из микроданных (itemprop="price").
 *
 * @param  string $html Исходный HTML
 * @return array  Результат поиска цены
 */
function extractPriceFromMicrodata(string $html): array
{
    $default = [
        'found'        => false,
        'price'        => null,
        'currency'     => null,
        'source'       => 'microdata',
        'product_name' => null,
        'is_request'   => false,
    ];

    // itemprop="price" с атрибутом content
    if (preg_match('/<[^>]+itemprop=["\']price["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m)) {
        $price = parseNumericPrice($m[1]);
        if ($price !== null && $price > 0) {
            $currency = null;
            if (preg_match('/<[^>]+itemprop=["\']priceCurrency["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $cm)) {
                $currency = $cm[1];
            }
            return [
                'found'        => true,
                'price'        => $price,
                'currency'     => $currency,
                'source'       => 'microdata',
                'product_name' => null,
                'is_request'   => false,
            ];
        }
    }

    // itemprop="price" — значение внутри тега (content в самом атрибуте)
    if (preg_match('/<[^>]+content=["\']([^"\']+)["\'][^>]*itemprop=["\']price["\']/i', $html, $m)) {
        $price = parseNumericPrice($m[1]);
        if ($price !== null && $price > 0) {
            $currency = null;
            if (preg_match('/<[^>]+itemprop=["\']priceCurrency["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $cm)) {
                $currency = $cm[1];
            }
            return [
                'found'        => true,
                'price'        => $price,
                'currency'     => $currency,
                'source'       => 'microdata',
                'product_name' => null,
                'is_request'   => false,
            ];
        }
    }

    // <meta itemprop="price" content="...">
    if (preg_match('/<meta\s[^>]*itemprop=["\']price["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m)) {
        $price = parseNumericPrice($m[1]);
        if ($price !== null && $price > 0) {
            return [
                'found'        => true,
                'price'        => $price,
                'currency'     => null,
                'source'       => 'microdata',
                'product_name' => null,
                'is_request'   => false,
            ];
        }
    }

    return $default;
}

/**
 * Извлекает цену из тегов Open Graph (product:price:amount).
 *
 * @param  string $html Исходный HTML
 * @return array  Результат поиска цены
 */
function extractPriceFromOpenGraph(string $html): array
{
    $default = [
        'found'        => false,
        'price'        => null,
        'currency'     => null,
        'source'       => 'open-graph',
        'product_name' => null,
        'is_request'   => false,
    ];

    // <meta property="product:price:amount" content="...">
    if (preg_match('/<meta\s[^>]*property=["\']product:price:amount["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m)) {
        $price = parseNumericPrice($m[1]);
        if ($price !== null && $price > 0) {
            $currency = null;
            if (preg_match('/<meta\s[^>]*property=["\']product:price:currency["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $cm)) {
                $currency = $cm[1];
            }
            return [
                'found'        => true,
                'price'        => $price,
                'currency'     => $currency,
                'source'       => 'open-graph',
                'product_name' => null,
                'is_request'   => false,
            ];
        }
    }

    // content перед property
    if (preg_match('/<meta\s[^>]*content=["\']([^"\']+)["\'][^>]*property=["\']product:price:amount["\']/i', $html, $m)) {
        $price = parseNumericPrice($m[1]);
        if ($price !== null && $price > 0) {
            $currency = null;
            if (preg_match('/<meta\s[^>]*property=["\']product:price:currency["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $cm)) {
                $currency = $cm[1];
            }
            return [
                'found'        => true,
                'price'        => $price,
                'currency'     => $currency,
                'source'       => 'open-graph',
                'product_name' => null,
                'is_request'   => false,
            ];
        }
    }

    return $default;
}

/**
 * Извлекает цену из data-атрибутов (data-price, data-product-price и т.п.).
 *
 * @param  string $html Исходный HTML
 * @return array  Результат поиска цены
 */
function extractPriceFromDataAttributes(string $html): array
{
    $default = [
        'found'        => false,
        'price'        => null,
        'currency'     => null,
        'source'       => 'data-attribute',
        'product_name' => null,
        'is_request'   => false,
    ];

    // Ищем data-price="число" или data-product-price="число"
    $patterns = [
        '/data-price=["\']([0-9][0-9\s.,]*)["\']/',
        '/data-product-price=["\']([0-9][0-9\s.,]*)["\']/',
        '/data-price-amount=["\']([0-9][0-9\s.,]*)["\']/',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $html, $m)) {
            $price = parseNumericPrice($m[1]);
            if ($price !== null && $price > 0) {
                return [
                    'found'        => true,
                    'price'        => $price,
                    'currency'     => null,
                    'source'       => 'data-attribute',
                    'product_name' => null,
                    'is_request'   => false,
                ];
            }
        }
    }

    return $default;
}

/**
 * Парсит строковое значение цены и возвращает числовое значение.
 * Обрабатывает форматы: "1234.56", "1 234,56", "1,234.56", "1234" и т.п.
 *
 * @param  string $priceStr Строковое значение цены
 * @return float|null       Числовое значение или null при ошибке
 */
function parseNumericPrice(string $priceStr): ?float
{
    // Убираем символы валют, пробелы и служебные символы
    $cleaned = preg_replace('/[^\d.,]/', '', trim($priceStr));

    if ($cleaned === '') {
        return null;
    }

    // Определяем формат числа
    $hasComma = str_contains($cleaned, ',');
    $hasDot   = str_contains($cleaned, '.');

    if ($hasComma && $hasDot) {
        // "1,234.56" или "1.234,56"
        $lastComma = strrpos($cleaned, ',');
        $lastDot   = strrpos($cleaned, '.');

        if ($lastComma > $lastDot) {
            // Формат: 1.234,56 (европейский)
            $cleaned = str_replace('.', '', $cleaned);
            $cleaned = str_replace(',', '.', $cleaned);
        } else {
            // Формат: 1,234.56 (американский)
            $cleaned = str_replace(',', '', $cleaned);
        }
    } elseif ($hasComma) {
        // Может быть "1234,56" (десятичный разделитель) или "1,234" (разделитель тысяч)
        $parts = explode(',', $cleaned);
        if (count($parts) === 2 && strlen($parts[count($parts) - 1]) <= 2) {
            // "1234,56" — десятичный
            $cleaned = str_replace(',', '.', $cleaned);
        } else {
            // "1,234" или "1,234,567" — разделитель тысяч
            $cleaned = str_replace(',', '', $cleaned);
        }
    }
    // Если только точка — оставляем как есть (стандартный формат)

    // Убираем множественные точки, оставляя последнюю
    if (substr_count($cleaned, '.') > 1) {
        $parts   = explode('.', $cleaned);
        $decimal = array_pop($parts);
        $cleaned = implode('', $parts) . '.' . $decimal;
    }

    if ($cleaned === '' || $cleaned === '.') {
        return null;
    }

    $value = (float) $cleaned;

    // Защита от нереалистичных цен
    if ($value <= 0 || $value > 100000000) {
        return null;
    }

    return $value;
}
