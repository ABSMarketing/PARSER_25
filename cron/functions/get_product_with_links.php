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
 * Возвращает порцию (батч) записей из parsed_links, связанных с указанным parent_id,
 * у которых execution_status IS NULL (необработанные).
 * Сортировка по id ASC обеспечивает детерминированный порядок обработки.
 *
 * @param  PDO $pdo       Объект PDO подключения
 * @param  int $parentId  ID родительской записи из parsed_products
 * @param  int $limit     Максимальное количество ссылок в батче (по умолчанию 10)
 * @return array           Массив ассоциативных массивов (не более $limit элементов)
 */
function getLinksByParentId(PDO $pdo, int $parentId, int $limit = 10): array
{
    $sql = "
        SELECT `id`, `html`, `execution_status`, `priority`
        FROM `parsed_links`
        WHERE `parent_id` = :parent_id
          AND `execution_status` IS NULL
        ORDER BY `id` ASC
        LIMIT :limit
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':parent_id', $parentId, PDO::PARAM_INT);
    $stmt->bindValue(':limit',     $limit,    PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Обновляет записи в parsed_links по результатам классификации DeepSeek.
 * Для каждого элемента массива $classifiedData выполняет UPDATE полей
 * execution_status и priority. Все обновления выполняются в одной транзакции.
 *
 * @param  PDO   $pdo            Объект PDO подключения
 * @param  array $classifiedData Массив объектов из ответа DeepSeek (поля: id, execution_status, priority)
 * @return bool                  true при успехе, false при ошибке
 */
function updateLinksClassification(PDO $pdo, array $classifiedData): bool
{
    if (empty($classifiedData)) {
        return true;
    }

    $sql = "
        UPDATE `parsed_links`
        SET `execution_status` = :execution_status,
            `priority`         = :priority
        WHERE `id` = :id
    ";

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare($sql);

        foreach ($classifiedData as $item) {
            if (!isset($item['id'])) {
                echo "⚠️  Пропущен элемент без поля id в ответе DeepSeek\n";
                continue;
            }
            $stmt->bindValue(':id',               (int) $item['id'],               PDO::PARAM_INT);
            $stmt->bindValue(':execution_status', (int) ($item['execution_status'] ?? 1), PDO::PARAM_INT);
            $stmt->bindValue(':priority',         (int) ($item['priority']         ?? 0), PDO::PARAM_INT);
            $stmt->execute();
        }

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        try {
            $pdo->rollBack();
        } catch (Exception $rollbackException) {
            echo "❌ Ошибка отката транзакции: " . $rollbackException->getMessage() . "\n";
        }
        echo "❌ Ошибка записи в parsed_links: " . $e->getMessage() . "\n";
        return false;
    }
}

/**
 * Обновляет запись в parsed_products по итогам обработки.
 * При успехе устанавливает status=2 и обновляет updated_at.
 * При неудаче обновляет только updated_at (статус не меняется),
 * чтобы запись «уходила в конец очереди» (ORDER BY updated_at ASC).
 *
 * @param  PDO  $pdo       Объект PDO подключения
 * @param  int  $productId ID записи в parsed_products
 * @param  bool $success   true — успех, false — неудача
 * @return void
 */
function updateProductStatus(PDO $pdo, int $productId, bool $success): void
{
    if ($success) {
        $sql  = "UPDATE `parsed_products` SET `status` = 2, `updated_at` = NOW() WHERE `id` = :id";
    } else {
        $sql  = "UPDATE `parsed_products` SET `updated_at` = NOW() WHERE `id` = :id";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id', $productId, PDO::PARAM_INT);
    $stmt->execute();
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
