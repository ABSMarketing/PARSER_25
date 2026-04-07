<?php
/**
 * Название файла:      save_to_db.php
 * Назначение:          Сохранение распарсенных данных в базу данных.
 *                      Нормализация пробелов, проверка на существование
 *                      записи по (url, category, name) и вставка/обновление.
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-04-07
 */

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
    ?string $power
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
            (`url`, `category`, `column_index`, `row_index`, `name`, `raid`, `power_supply`)
        VALUES
            (:url, :category, :column_index, :row_index, :name, :raid, :power_supply)
        AS new_data
        ON DUPLICATE KEY UPDATE
            `column_index` = new_data.`column_index`,
            `row_index`    = new_data.`row_index`,
            `raid`         = new_data.`raid`,
            `power_supply` = new_data.`power_supply`
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->bindValue(':url',          $url,         PDO::PARAM_STR);
    $stmt->bindValue(':category',     $category,    PDO::PARAM_STR);
    $stmt->bindValue(':column_index', $columnIndex, $columnIndex !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $stmt->bindValue(':row_index',    $rowIndex,    $rowIndex !== null    ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $stmt->bindValue(':name',         $name,        PDO::PARAM_STR);
    $stmt->bindValue(':raid',         $raid,        $raid  !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':power_supply', $power,       $power !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);

    return $stmt->execute();
}

/**
 * Разбирает строку формата «url|category|col|row|name|raid|power»
 * и сохраняет данные в БД.
 *
 * @param  PDO    $pdo  Объект PDO подключения
 * @param  string $line Строка данных через разделитель |
 * @return bool         true — если запись вставлена/обновлена
 */
function parseLineAndSave(PDO $pdo, string $line): bool
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

    return saveProductToDb($pdo, $url, $category, $columnIndex, $rowIndex, $name, $raid, $power);
}
