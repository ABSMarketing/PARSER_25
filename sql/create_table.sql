-- ========================================
-- Создание таблицы parsed_products
-- ========================================
-- Хранит данные парсинга из Google Sheets
-- Уникальность определяется по: url, category, name
-- ========================================

CREATE TABLE IF NOT EXISTS `parsed_products` (
    `id`           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `url`          VARCHAR(500)    NOT NULL COMMENT 'URL сайта',
    `category`     VARCHAR(255)    NOT NULL COMMENT 'Категория (вкладка: Платформы, Процессоры и т.д.)',
    `column_index` INT UNSIGNED    DEFAULT NULL COMMENT 'Индекс колонки',
    `row_index`    INT UNSIGNED    DEFAULT NULL COMMENT 'Индекс строки',
    `name`         VARCHAR(500)    NOT NULL COMMENT 'Название товара',
    `raid`         VARCHAR(255)    DEFAULT NULL COMMENT 'Рейд контроллер',
    `power_supply` VARCHAR(255)    DEFAULT NULL COMMENT 'Блок питания',
    `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Дата создания',
    `updated_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Дата обновления',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_url_category_name` (`url`(191), `category`(191), `name`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
