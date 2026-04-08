<?php
/**
 * Название файла:      get_product_with_links.php
 * Назначение:          Получение записи из parsed_products с фильтром
 *                      status = 1, price IS NULL, product_url IS NULL (LIMIT 1)
 *                      и всех связанных записей из parsed_links.
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-04-08
 */

/**
 * Возвращает одну запись из parsed_products, у которой:
 *   status = 1, price IS NULL, product_url IS NULL.
 * Сортировка по updated_at ASC (самая старая запись первой).
 *
 * @param  PDO $pdo  Объект PDO подключения
 * @return array|null Ассоциативный массив записи или null
 */
function getProductForProcessing(PDO $pdo): ?array
{
    $sql = "
        SELECT *
        FROM `parsed_products`
        WHERE `status` = 1
          AND `price` IS NULL
          AND `product_url` IS NULL
        ORDER BY `updated_at` ASC
        LIMIT 1
    ";

    $stmt = $pdo->query($sql);
    $row  = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * Возвращает все записи из parsed_links, связанные с указанным parent_id.
 *
 * @param  PDO $pdo       Объект PDO подключения
 * @param  int $parentId  ID родительской записи из parsed_products
 * @return array           Массив ассоциативных массивов
 */
function getLinksByParentId(PDO $pdo, int $parentId): array
{
    $sql = "
        SELECT `id`, `html`, `execution_status`, `priority`
        FROM `parsed_links`
        WHERE `parent_id` = :parent_id
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':parent_id', $parentId, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Формирует строку описания товара из данных parsed_products.
 * Включает название, рейд-контроллер (если есть) и блок питания (если есть).
 *
 * @param  array $product Ассоциативный массив записи из parsed_products
 * @return string          Собранная строка описания
 */
function buildProductInfoString(array $product): string
{
    $parts = [];

    // Название товара (обязательное поле)
    $parts[] = $product['name'];

    // Рейд контроллер (если есть)
    if (!empty($product['raid'])) {
        $parts[] = $product['raid'];
    }

    // Блок питания (если есть)
    if (!empty($product['power_supply'])) {
        $parts[] = $product['power_supply'];
    }

    return implode(' | ', $parts);
}
