<?php
/**
 * Название файла:      get_product_for_pricing.php
 * Назначение:          Функции для получения товара и ссылки для поиска цены,
 *                      а также для записи результатов обработки в БД.
 *
 * Используется в:      cron/4.php
 *
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-04-08
 */

/**
 * Возвращает одну запись из parsed_products для поиска цены.
 * Фильтр: (price IS NULL OR (price = 0 AND url NOT LIKE '%u-server.ru%')) AND status = 2.
 * Сортировка: ORDER BY updated_at ASC (FIFO-очередь).
 *
 * @param  PDO $pdo  Объект PDO подключения
 * @return array|null Ассоциативный массив записи или null
 */
function getProductForPricing(PDO $pdo): ?array
{
    $sql = "
        SELECT `id`, `name`, `raid`, `power_supply`, `url`, `category`
        FROM `parsed_products`
        WHERE (`price` IS NULL OR (`price` = 0 AND `url` NOT LIKE '%u-server.ru%'))
          AND `status` = 1
        ORDER BY `updated_at` ASC
        LIMIT 1
    ";

    $stmt = $pdo->query($sql);
    $row  = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * Возвращает самую приоритетную необработанную ссылку для товара.
 * Фильтр: parent_id = :id AND execution_status = 1 (релевантна, от 3.php).
 * Сортировка: ORDER BY priority DESC (сначала самые приоритетные).
 *
 * @param  PDO $pdo        Объект PDO подключения
 * @param  int $productId  ID товара из parsed_products
 * @return array|null      Ассоциативный массив ['id', 'url_a'] или null
 */
function getBestLinkForProduct(PDO $pdo, int $productId): ?array
{
    $sql = "
        SELECT `id`, `url_a`
        FROM `parsed_links`
        WHERE `parent_id` = :product_id
          AND `execution_status` = 1
        ORDER BY `priority` DESC
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
    $stmt->execute();

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * Записывает цену и URL страницы с ценой в parsed_products.
 *
 * @param  PDO         $pdo        Объект PDO подключения
 * @param  int         $productId  ID товара
 * @param  float       $price      Цена товара
 * @param  string|null $productUrl URL страницы с ценой
 * @return void
 */
function saveProductPrice(PDO $pdo, int $productId, float $price, ?string $productUrl): void
{
    $sql = "
        UPDATE `parsed_products`
        SET `price` = :price,
            `product_url` = :product_url,
            `updated_at` = NOW()
        WHERE `id` = :id
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':price',       $price,      PDO::PARAM_STR);
    $stmt->bindValue(':product_url', $productUrl, PDO::PARAM_STR);
    $stmt->bindValue(':id',          $productId,  PDO::PARAM_INT);
    $stmt->execute();
}

/**
 * Обновляет execution_status конкретной ссылки в parsed_links.
 *
 * @param  PDO $pdo    Объект PDO подключения
 * @param  int $linkId ID записи в parsed_links
 * @param  int $status Новый execution_status (2 = не найдено, 3 = найдено)
 * @return void
 */
function updateLinkExecutionStatus(PDO $pdo, int $linkId, int $status): void
{
    $sql = "UPDATE `parsed_links` SET `execution_status` = :status WHERE `id` = :id";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':status', $status, PDO::PARAM_INT);
    $stmt->bindValue(':id',     $linkId, PDO::PARAM_INT);
    $stmt->execute();
}

/**
 * Обновляет только updated_at в parsed_products (сдвигает в конец FIFO-очереди).
 *
 * @param  PDO $pdo       Объект PDO подключения
 * @param  int $productId ID товара
 * @return void
 */
function touchProductUpdatedAt(PDO $pdo, int $productId): void
{
    $sql = "UPDATE `parsed_products` SET `updated_at` = NOW() WHERE `id` = :id";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id', $productId, PDO::PARAM_INT);
    $stmt->execute();
}
