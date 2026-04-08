# 📋 Техническое задание: Доработка крон-скрипта `cron/3.php`

**Проект:** PARSER_25
**Репозиторий:** <a href="https://github.com/ABSMarketing/PARSER_25">ABSMarketing/PARSER_25</a>
**Целевой файл:** <a href="https://github.com/ABSMarketing/PARSER_25/blob/main/cron/3.php">`cron/3.php`</a>
**Связанные файлы:**
- <a href="https://github.com/ABSMarketing/PARSER_25/blob/main/cron/functions/get_product_with_links.php">`cron/functions/get_product_with_links.php`</a>
- <a href="https://github.com/ABSMarketing/PARSER_25/blob/main/cron/functions/provider_deepseek.php">`cron/functions/provider_deepseek.php`</a>
- <a href="https://github.com/ABSMarketing/PARSER_25/blob/main/connect/db.php">`connect/db.php`</a> — **⛔ НЕ ИЗМЕНЯТЬ** (см. раздел 7)

**Дата:** 2026-04-08
**Версия ТЗ:** 2.0 (финальная)

---

## 0. Контекст проблемы

При запуске скрипта `cron/3.php` выполнение **зависает** на этапе отправки данных в DeepSeek API. Вывод обрывается на строке:

```
📤 Товар: HP DL 360 G9 8SFF | P440ar | 2x500W
📤 Ссылок для анализа: 50
```

После этого — никакого вывода, никаких записей в БД, никаких ошибок.

**Диагностика выявила следующие корневые причины:**
1. **PHP убивает скрипт по `max_execution_time`** (по умолчанию 30 сек) во время длительного ожидания ответа от DeepSeek API (cURL таймаут = 120 сек > PHP лимит = 30 сек).
2. **Буферизация вывода** скрывает последние сообщения — при аварийном завершении буфер не сбрасывается.
3. **Таймаут cURL недостаточен** для обработки 50 ссылок (120 сек для объёмного запроса).
4. **Retry-логика слишком узкая** — повтор только при точном совпадении `timed out`, но не при `timeout`, HTTP 429, 502, 503.
5. **Отсутствие отладочного вывода** после `curl_exec` — невозможно диагностировать проблему.

---

## 1. Описание текущего состояния

Скрипт `cron/3.php` выполняет следующую логику:
1. Получает одну запись из таблицы `parsed_products` (фильтр: `status=1`, `price IS NULL`, `product_url IS NULL`, `ORDER BY updated_at ASC`).
2. Формирует строку описания товара: `name | raid | power_supply`.
3. Получает связанные ссылки из таблицы `parsed_links` по `parent_id`.
4. Получает API-ключ DeepSeek через сервис `servero.space`.
5. Отправляет товар и ссылки в DeepSeek для классификации (бинарно: `priority=0` или `priority=1`).
6. Выводит результат классификации в stdout.

### Текущие проблемы

| # | Проблема | Тип |
|---|----------|-----|
| 1 | Скрипт зависает/убивается на этапе DeepSeek API — нет `set_time_limit` | 🔴 Критично |
| 2 | Буферизация вывода скрывает ошибки при аварийном завершении | 🔴 Критично |
| 3 | cURL таймаут (120 сек) недостаточен для 50 ссылок | 🔴 Критично |
| 4 | Retry-логика слишком узкая (только `timed out`) | 🟡 Важно |
| 5 | Нет отладочного вывода HTTP-кода после вызова DeepSeek | 🟡 Важно |
| 6 | Результаты классификации **не сохраняются** в `parsed_links` | 🔴 Критично |
| 7 | Шкала приоритетов бинарная (0/1) — недостаточно гранулярная | 🟡 Важно |
| 8 | Статус записи в `parsed_products` **не обновляется** по итогам обработки | 🔴 Критично |

---

## 2. Требования к доработке

### 2.1. Устранение зависания скрипта (стабильность выполнения)

#### 2.1.1. Добавить `set_time_limit` в `cron/3.php`

```php
// Увеличиваем лимит выполнения для крон-скрипта (5 минут)
set_time_limit(300);
```

#### 2.1.2. Отключить буферизацию вывода в `cron/3.php`

```php
// Отключаем буферизацию вывода для мгновенного отображения логов в крон-скрипте
ob_implicit_flush(true);
if (ob_get_level()) {
    ob_end_flush();
}
```

#### 2.1.3. Увеличить таймауты cURL

В функции `_sendDeepseekClassificationRequest()` (`cron/functions/provider_deepseek.php`):

| Параметр | Было | Стало |
|----------|------|-------|
| `CURLOPT_CONNECTTIMEOUT` | 15 | 30 |
| `CURLOPT_TIMEOUT` | 120 | 300 |

#### 2.1.4. Отладочный вывод после `curl_exec`

```php
echo "📡 DeepSeek ответ: HTTP {$httpCode}\n";
if ($curlError) {
    echo "⚠️  cURL ошибка: {$curlError}\n";
}
```

#### 2.1.5. Расширить retry-логику

```php
$errorMsg = $result['error'] ?? '';
$isRetryable = str_contains($errorMsg, 'timed out')
            || str_contains($errorMsg, 'timeout')
            || str_contains($errorMsg, 'HTTP 429')
            || str_contains($errorMsg, 'HTTP 502')
            || str_contains($errorMsg, 'HTTP 503');

if ($isRetryable && $attempt < $maxRetries) {
```

---

### 2.2. Сохранение результатов DeepSeek в таблицу `parsed_links`

SQL-запрос:
```sql
UPDATE parsed_links
SET execution_status = :execution_status,
    priority = :priority
WHERE id = :id
```

**В транзакции. Если ошибка — rollback, логировать ошибку.**

---

### 2.3. Изменение шкалы приоритетов на 10-балльную

В промпте DeepSeek (`provider_deepseek.php`) значение priority теперь от 0 до 10:

| Балл   | Значение |
|--------|----------|
| 10     | товар точно продаётся на этой странице |
| 7–9    | высокая вероятность, каталог товара    |
| 4–6    | средняя вероятность                   |
| 1–3    | низкая вероятность                    |
| 0      | точно не относится к товару           |

В статистике (`cron/3.php`) добавить разбиение по диапазонам:
- 7–10, 4–6, 1–3, 0

---

### 2.4. Обновление статуса в `parsed_products` по итогам обработки

При успехе:
```sql
UPDATE parsed_products
SET status = 2, updated_at = NOW()
WHERE id = :productId
```

При ошибке:
```sql
UPDATE parsed_products
SET updated_at = NOW()
WHERE id = :productId
```

---

### 2.5. Переподключение к БД после API

Перед записью в БД после вызова DeepSeek обязательно выполнить:
```php
$pdo = Database::reconnect();
```

---

## 3. Список изменяемых файлов

| Файл | Тип изменений |
|------|---------------|
| `cron/3.php` | set_time_limit, ob_implicit_flush, reconnect, статистика, вызовы новых функций |
| `cron/functions/get_product_with_links.php` | Функции updateLinksClassification, updateProductStatus |
| `cron/functions/provider_deepseek.php` | Промпт, таймаут, retry, отладка |

---

## 4. Схема обновлённого потока выполнения

```
┌──────────────────────────────────────────────────┐
│ 0. set_time_limit(300), ob_implicit_flush(true)  │
├──────────────────────────────────────────────────┤
│ 1. Получить запись из parsed_products            │
│ 2. Сформировать описание товара                  │
│ 3. Получить ссылки из parsed_links               │
│    Нет ссылок → updateProductStatus(НЕУДАЧА)     │
│ 4. Получить API-ключ DeepSeek                    │
│    Ошибка → updateProductStatus(НЕУДАЧА)         │
│ 5. Отправить в DeepSeek для классификации        │
│    Ошибка → updateProductStatus(НЕУДАЧА)         │
│ 5.1. Database::reconnect()                       │
│ 6. Записать результаты в parsed_links            │
│    Ошибка → updateProductStatus(НЕУДАЧА)         │
│ 7. updateProductStatus(УСПЕХ)                    │
│    → status=2, updated_at=NOW()                  │
│ 8. Вывести итоги (статистика 0–10)               │
└──────────────────────────────────────────────────┘
```

---

## 5. Требования к реализации

- Только prepared statements
- Все update транзакционно
- Логирование в stdout в стиле ✅/❌
- Не менять структуру БД
- Обратная совместимость (если мало полей в ответе — не падать)
- Перед записью результатов — переподключение к БД

---

## 6. Критерии приёмки

| # | Критерий | Проверка |
|---|----------|----------|
| 1 | Скрипт не зависает при 50+ ссылках | Проверить запуск |
| 2 | Есть вывод HTTP-кода DeepSeek | Проверить stdout |
| 3 | Retry при timeout/429/502/503 | Проверить в логах |
| 4 | `parsed_links` обновлены по результатам | Проверить SELECT |
| 5 | priority всегда от 0 до 10 | Проверка выборки |
| 6 | При успехе: status=2 | Проверить SELECT |
| 7 | При ошибке: только updated_at | Проверка SELECT |
| 8 | FIFO-порядок выбора | Проверка SELECT |
| 9 | Пограничные тесты: нет ссылок, ошибка API, пустой ответ, ошибка записи | Проверить логи, результат|
| 10 | **Файл `connect/db.php` не менять!** | Проверить git diff |

---

## 7. ⛔ Ограничения: не изменять `connect/db.php`

Файл `connect/db.php` используется всеми скриптами проекта. Любые изменения в нём могут привести к сбоям других частей системы. Использовать класс и методы как есть.
