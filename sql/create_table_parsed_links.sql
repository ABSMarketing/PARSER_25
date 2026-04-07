-- ========================================
-- Создание таблицы parsed_links
-- ========================================
-- Хранит все найденные ссылки (<a> тэги) со страниц parsed_products.
-- При удалении родительской записи из parsed_products
-- связанные строки удаляются автоматически (ON DELETE CASCADE).
-- Уникальность определяется по: parent_id, url_a
-- ========================================

CREATE TABLE IF NOT EXISTS `parsed_links` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `parent_id`   INT UNSIGNED    NOT NULL COMMENT 'ID родительской записи из parsed_products',
    `url_a`       VARCHAR(2048)   NOT NULL COMMENT 'URL из атрибута href тэга <a>',
    `html`        TEXT            NOT NULL COMMENT 'Полный HTML тэга <a> (включая текст)',
    `status`      TINYINT UNSIGNED DEFAULT NULL COMMENT 'Статус обработки ссылки',
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Дата создания записи',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_parent_url` (`parent_id`, `url_a`(700)),
    CONSTRAINT `fk_parsed_links_parent`
        FOREIGN KEY (`parent_id`) REFERENCES `parsed_products` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
