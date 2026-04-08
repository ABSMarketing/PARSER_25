<?php
/**
 * Название файла:      get_product_for_pricing.php
 * Назначение:          Функции для получения товара и ссылки (одним запросом),
 *                      а также для записи результатов обработки в БД.
 *
 * Используется в:      cron/4.php
 *
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-04-08
 */

/**
 * Возвращает одну запись из parsed_products вместе с наиболее приоритетной
 * ссылкой из parsed_links за один SQL-запрос (LEFT JOIN).
 *
 * Фильтр товара:  price IS NULL, status = 1.
 * Фильтр ссылки:  execution_status = 1.
 * Сортировка:     updated_at ASC (самый старый товар), priority DESC (лучшая ссылка).
 *
 * Если у товара нет доступных ссылок, поля link_id и link_url будут NULL.
 *
 * @param  PDO $pdo  Объект PDO подключения
 * @return array|null Ассоциативный массив или null, если товаров нет
 */
function getProductWithLink(PDO $pdo): ?array
{
    $sql = "
        SELECT
            pp.`id`           AS `product_id`,
            pp.`name`,
            pp.`raid`,
            pp.`power_supply`,
            pp.`url`,
            pp.`category`,
            pl.`id`           AS `link_id`,
            pl.`url_a`        AS `link_url`
        FROM `parsed_products` pp
        LEFT JOIN `parsed_links` pl
            ON pl.`parent_id` = pp.`id`
           AND pl.`execution_status` = 1
        WHERE pp.`price` IS NULL
          AND pp.`status` = 1
        ORDER BY pp.`updated_at` ASC, pl.`priority` DESC
        LIMIT 1
    ";

    $stmt = $pdo->query($sql);
    $row  = $stmt->fetch(PDO::FETCH_ASSOC);

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
