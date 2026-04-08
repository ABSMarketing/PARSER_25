-- ========================================
-- Добавление колонок price и product_url
-- в таблицу parsed_products
-- ========================================
-- price       — Цена товара (по умолчанию NULL)
-- product_url — Ссылка на карточку товара (по умолчанию NULL)
-- ========================================

ALTER TABLE `parsed_products`
    ADD COLUMN `price` DECIMAL(12,2) DEFAULT NULL
    COMMENT 'Цена товара',
    ADD COLUMN `product_url` VARCHAR(1000) DEFAULT NULL
    COMMENT 'Ссылка на карточку товара';
