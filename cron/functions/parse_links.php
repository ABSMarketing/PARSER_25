<?php
/**
 * Название файла:      parse_links.php
 * Назначение:          Загрузка HTML-страницы по URL (через прокси или напрямую),
 *                      извлечение всех тэгов <a> и сохранение в таблицу parsed_links.
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-04-07
 */

/**
 * Загружает HTML-страницу через cURL.
 *
 * @param  string      $url   URL страницы
 * @param  array|null  $proxy Данные прокси (protocol, host, port, login, password) или null
 * @return array              ['success'=>bool, 'html'=>string, 'duration_ms'=>int, 'error'=>string]
 */
function fetchPage(string $url, ?array $proxy = null): array
{
    $startTime = microtime(true);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                                . '(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
        ],
    ]);

    // Настраиваем прокси, если передан
    if ($proxy !== null) {
        $proxyUrl = $proxy['host'] . ':' . $proxy['port'];
        curl_setopt($ch, CURLOPT_PROXY, $proxyUrl);

        // Протокол
        $protocol = strtolower($proxy['protocol'] ?? 'http');
        if ($protocol === 'socks5') {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
        } elseif ($protocol === 'socks4') {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS4);
        } else {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
        }

        // Авторизация прокси (если есть)
        if (!empty($proxy['login']) && !empty($proxy['password'])) {
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy['login'] . ':' . $proxy['password']);
        }
    }

    $html    = curl_exec($ch);
    $err     = curl_error($ch);
    $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $durationMs = (int) round((microtime(true) - $startTime) * 1000);

    if ($err) {
        return ['success' => false, 'html' => '', 'duration_ms' => $durationMs, 'error' => "cURL: {$err}"];
    }

    if ($code < 200 || $code >= 400) {
        return ['success' => false, 'html' => '', 'duration_ms' => $durationMs, 'error' => "HTTP {$code}"];
    }

    return ['success' => true, 'html' => $html, 'duration_ms' => $durationMs, 'error' => ''];
}

/**
 * Извлекает все тэги <a> из HTML-строки.
 * Возвращает массив: [['url' => href, 'html' => полный тэг], ...]
 *
 * @param  string $html  HTML-контент страницы
 * @param  string $baseUrl  Базовый URL для формирования абсолютных ссылок
 * @return array
 */
function extractLinks(string $html, string $baseUrl): array
{
    $links = [];

    // Используем DOMDocument для корректного парсинга HTML
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $anchors = $dom->getElementsByTagName('a');

    foreach ($anchors as $anchor) {
        $href = $anchor->getAttribute('href');

        // Пропускаем пустые ссылки, якоря и javascript:
        if ($href === '' || $href === '#' || stripos($href, 'javascript:') === 0) {
            continue;
        }

        // Формируем абсолютный URL, если ссылка относительная
        $absoluteUrl = makeAbsoluteUrl($href, $baseUrl);

        // Получаем полный HTML тэга <a>
        $outerHtml = $dom->saveHTML($anchor);

        $links[] = [
            'url'  => $absoluteUrl,
            'html' => $outerHtml,
        ];
    }

    return $links;
}

/**
 * Преобразует относительный URL в абсолютный.
 *
 * @param  string $href    Значение href из тэга <a>
 * @param  string $baseUrl Базовый URL страницы
 * @return string          Абсолютный URL
 */
function makeAbsoluteUrl(string $href, string $baseUrl): string
{
    // Если уже абсолютный
    if (preg_match('/^https?:\/\//i', $href)) {
        return $href;
    }

    // Протокол-относительный URL
    if (strpos($href, '//') === 0) {
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
        return $scheme . ':' . $href;
    }

    $parsed = parse_url($baseUrl);
    $scheme = ($parsed['scheme'] ?? 'https');
    $host   = ($parsed['host']   ?? '');
    $port   = isset($parsed['port']) ? ':' . $parsed['port'] : '';
    $origin = $scheme . '://' . $host . $port;

    // Абсолютный путь
    if (strpos($href, '/') === 0) {
        return $origin . $href;
    }

    // Относительный путь
    $basePath = ($parsed['path'] ?? '/');
    $basePath = preg_replace('/\/[^\/]*$/', '/', $basePath);

    return $origin . $basePath . $href;
}

/**
 * Сохраняет массив ссылок в таблицу parsed_links с проверкой на дубликат.
 *
 * @param  PDO   $pdo      Объект PDO подключения
 * @param  int   $parentId ID родительской записи из parsed_products
 * @param  array $links    Массив [['url'=>..., 'html'=>...], ...]
 * @return array            ['saved'=>int, 'skipped'=>int]
 */
function saveLinksToDb(PDO $pdo, int $parentId, array $links): array
{
    $saved   = 0;
    $skipped = 0;

    $sql = "
        INSERT INTO `parsed_links` (`parent_id`, `url_a`, `html`)
        VALUES (:parent_id, :url_a, :html)
        ON DUPLICATE KEY UPDATE
            `html` = VALUES(`html`)
    ";
    $stmt = $pdo->prepare($sql);

    foreach ($links as $link) {
        $urlA = trim($link['url']);
        $html = trim($link['html']);

        if ($urlA === '') {
            $skipped++;
            continue;
        }

        try {
            $stmt->bindValue(':parent_id', $parentId, PDO::PARAM_INT);
            $stmt->bindValue(':url_a',     $urlA,     PDO::PARAM_STR);
            $stmt->bindValue(':html',      $html,     PDO::PARAM_STR);
            $stmt->execute();
            $saved++;
        } catch (PDOException $e) {
            echo "⚠️  Ошибка сохранения ссылки ({$urlA}): " . $e->getMessage() . "\n";
            $skipped++;
        }
    }

    return ['saved' => $saved, 'skipped' => $skipped];
}

/**
 * Обновляет статус записи в parsed_products.
 *
 * @param  PDO $pdo       Объект PDO подключения
 * @param  int $productId ID записи
 * @param  int $status    Новый статус (1 — успех, 2 — ошибка)
 * @return void
 */
function updateProductStatus(PDO $pdo, int $productId, int $status): void
{
    $sql = "UPDATE `parsed_products` SET `status` = :status WHERE `id` = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':status', $status, PDO::PARAM_INT);
    $stmt->bindValue(':id',     $productId, PDO::PARAM_INT);
    $stmt->execute();
}
