-- ========================================
-- Добавление колонок execution_status и priority в parsed_links
-- ========================================
-- execution_status — статус выполнения (числовое значение), по умолчанию NULL
-- priority         — приоритет (числовое значение), по умолчанию NULL
-- ========================================

ALTER TABLE `parsed_links`
    ADD COLUMN `execution_status` INT DEFAULT NULL COMMENT 'Статус выполнения',
    ADD COLUMN `priority`         INT DEFAULT NULL COMMENT 'Приоритет';
