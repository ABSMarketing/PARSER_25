<?php
/**
 * Название файла:      save_to_db.php
 * Назначение:          Сохранение распарсенных данных в базу данных.
 *                      Нормализация пробелов, проверка на существование
 *                      записи по (url, category, name) и вставка/обновление.
 * Автор:               Команда разработки
 * Версия:              2.1
 * Дата создания:       2026-04-07
 */

/**
 * Максимальное значение sync_batch_id, после которого счётчик сбрасывается.
 */
define('SYNC_BATCH_ID_LIMIT', 1000000);

/**
 * Нормализация пробелов в строке:
 * — удаляет ведущие и замыкающие пробелы
 * — заменяет множественные пробелы одним
 *
 * @param  string|null $str Исходная строка
 * @return string           Нормализованная строка
 */
function normalizeSpaces(?string $str): string
{
    if ($str === null) {
        return '';
    }
    return preg_replace('/\s+/', ' ', trim($str));
}

/**
 * Генерирует новый уникальный batch_id для текущей сессии синхронизации.
 * Берёт MAX(sync_batch_id) + 1 из таблицы (первый запуск вернёт 1).
 *
 * При достижении SYNC_BATCH_ID_LIMIT сбрасывает все существующие записи
 * на sync_batch_id = 0 и возвращает 1, начиная цикл заново.
 *
 * @param  PDO $pdo  Объект PDO подключения
 * @return int       Новый batch_id
 */
function generateBatchId(PDO $pdo): int
{
    $stmt = $pdo->query("SELECT COALESCE(MAX(`sync_batch_id`), 0) FROM `parsed_products`");
    $currentMax = (int) $stmt->fetchColumn();

    if ($currentMax >= SYNC_BATCH_ID_LIMIT) {
        // Сбрасываем все записи на 0, новый batch_id начнётся с 1
        $pdo->exec("UPDATE `parsed_products` SET `sync_batch_id` = 0");
        echo "⚠️  sync_batch_id достиг лимита ({$currentMax} >= " . SYNC_BATCH_ID_LIMIT . "). Счётчик сброшен.\n";
        return 1;
    }

    return $currentMax + 1;
}

/**
 * Удаляет все записи, у которых sync_batch_id НЕ равен текущему batch_id.
 * Вызывается ПОСЛЕ завершения полной синхронизации всех вкладок.
 *
 * @param  PDO $pdo      Объект PDO подключения
 * @param  int $batchId  Текущий batch_id сессии синхронизации
 * @return int           Количество удалённых строк
 */
function deleteOrphanedRecords(PDO $pdo, int $batchId): int
{
    $stmt = $pdo->prepare("DELETE FROM `parsed_products` WHERE `sync_batch_id` != :batch_id");
    $stmt->bindValue(':batch_id', $batchId, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->rowCount();
}

/**
 * Сохраняет одну запись в таблицу parsed_products.
 * Если запись с таким (url, category, name) уже существует — обновляет остальные поля.
 * Если не существует — вставляет новую.
 *
 * @param  PDO    $pdo         Объект PDO подключения
 * @param  string $url         URL сайта
 * @param  string $category    Категория (название вкладки)
 * @param  int|null $columnIndex  Индекс колонки
 * @param  int|null $rowIndex     Индекс строки
 * @param  string $name        Название товара
 * @param  string|null $raid   Рейд контроллер
 * @param  string|null $power  Блок питания
 * @param  int $batchId        ID сессии синхронизации
 * @return bool                true — если запись вставлена/обновлена
 */
function saveProductToDb(
    PDO $pdo,
    string $url,
    string $category,
    ?int $columnIndex,
    ?int $rowIndex,
    string $name,
    ?string $raid,
    ?string $power,
    int $batchId
): bool {
    // Нормализуем пробелы во всех строковых полях
    $url       = normalizeSpaces($url);
    $category  = normalizeSpaces($category);
    $name      = normalizeSpaces($name);
    $raid      = normalizeSpaces($raid);
    $power     = normalizeSpaces($power);

    // Пропускаем пустые обязательные поля
    if ($url === '' || $category === '' || $name === '') {
        return false;
    }

    // Заменяем «—» (тире-заглушку) на NULL
    $raid  = ($raid  === '' || $raid  === '—') ? null : $raid;
    $power = ($power === '' || $power === '—') ? null : $power;

    $sql = "
        INSERT INTO `parsed_products`
            (`url`, `category`, `column_index`, `row_index`, `name`, `raid`, `power_supply`, `sync_batch_id`)
        VALUES
            (:url, :category, :column_index, :row_index, :name, :raid, :power_supply, :batch_id)
        AS new_data
        ON DUPLICATE KEY UPDATE
            `column_index`  = new_data.`column_index`,
            `row_index`     = new_data.`row_index`,
            `raid`          = new_data.`raid`,
            `power_supply`  = new_data.`power_supply`,
            `sync_batch_id` = new_data.`sync_batch_id`
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->bindValue(':url',          $url,         PDO::PARAM_STR);
    $stmt->bindValue(':category',     $category,    PDO::PARAM_STR);
    $stmt->bindValue(':column_index', $columnIndex, $columnIndex !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $stmt->bindValue(':row_index',    $rowIndex,    $rowIndex !== null    ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $stmt->bindValue(':name',         $name,        PDO::PARAM_STR);
    $stmt->bindValue(':raid',         $raid,        $raid  !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':power_supply', $power,       $power !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':batch_id',     $batchId,     PDO::PARAM_INT);

    return $stmt->execute();
}

/**
 * Разбирает строку формата «url|category|col|row|name|raid|power»
 * и сохраняет данные в БД.
 *
 * @param  PDO    $pdo     Объект PDO подключения
 * @param  string $line   Строка данных через разделитель |
 * @param  int $batchId   ID сессии синхронизации
 * @return bool           true — если запись вставлена/обновлена
 */
function parseLineAndSave(PDO $pdo, string $line, int $batchId): bool
{
    // Формат: url|category|column_index|row_index|name|raid|power_supply (7 полей)
    $expectedFields = 7;
    $parts = explode('|', $line);

    if (count($parts) < $expectedFields) {
        return false;
    }

    $url         = $parts[0];
    $category    = $parts[1];
    $columnIndex = is_numeric(trim($parts[2])) ? (int) trim($parts[2]) : null;
    $rowIndex    = is_numeric(trim($parts[3])) ? (int) trim($parts[3]) : null;
    $name        = $parts[4];
    $raid        = $parts[5];
    $power       = $parts[6];

    return saveProductToDb($pdo, $url, $category, $columnIndex, $rowIndex, $name, $raid, $power, $batchId);
}
