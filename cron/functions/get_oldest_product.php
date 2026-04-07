<?php
/**
 * Название файла:      get_oldest_product.php
 * Назначение:          Получение записи из parsed_products с самой дальней
 *                      датой обновления (updated_at) и status IS NULL.
 *                      Возвращает id и url для дальнейшего парсинга ссылок.
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-04-07
 */

/**
 * Возвращает запись из parsed_products с самой старой датой обновления,
 * у которой колонка status равна NULL.
 *
 * @param  PDO $pdo  Объект PDO подключения
 * @return array|null Ассоциативный массив ['id' => ..., 'url' => ...] или null
 */
function getOldestUnprocessedProduct(PDO $pdo): ?array
{
    $sql = "
        SELECT `id`, `url`
        FROM `parsed_products`
        WHERE `status` IS NULL
        ORDER BY `updated_at` ASC
        LIMIT 1
    ";

    $stmt = $pdo->query($sql);
    $row  = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}
