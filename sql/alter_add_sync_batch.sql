ALTER TABLE `parsed_products`
    ADD COLUMN `sync_batch_id` INT UNSIGNED NOT NULL DEFAULT 0 
    COMMENT 'ID сессии синхронизации. Записи со старым batch_id удаляются после синхронизации';
