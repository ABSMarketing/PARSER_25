# 📋 Техническое задание: Крон-скрипт `cron/4.php` — Поиск цены товара через HTML-парсинг и DeepSeek

**Проект:** PARSER_25
**Репозиторий:** [ABSMarketing/PARSER_25](https://github.com/ABSMarketing/PARSER_25)
**Целевой файл:** `cron/4.php` (новый)
**Новые функции:** `cron/functions/get_product_for_pricing.php` (новый), `cron/functions/deepseek_price_search.php` (новый)
**Переиспользуемые файлы:**
- [`connect/db.php`](https://github.com/ABSMarketing/PARSER_25/blob/main/connect/db.php) — подключение к БД (**⛔ НЕ ИЗМЕНЯТЬ**)
- [`cron/functions/proxy.php`](https://github.com/ABSMarketing/PARSER_25/blob/main/cron/functions/proxy.php) — функции `getProxy()` / `updateProxy()`
- [`cron/functions/parse_links.php`](https://github.com/ABSMarketing/PARSER_25/blob/main/cron/functions/parse_links.php) — функция `fetchPage()` для загрузки HTML через cURL+прокси
- [`cron/functions/provider_deepseek.php`](https://github.com/ABSMarketing/PARSER_25/blob/main/cron/functions/provider_deepseek.php) — функция `getDeepseekApiKey()` для получения API-ключа DeepSeek

**Эталонные файлы (паттерны):**
- [`cron/2.php`](https://github.com/ABSMarketing/PARSER_25/blob/main/cron/2.php) — загрузка HTML через прокси
- [`cron/3.php`](https://github.com/ABSMarketing/PARSER_25/blob/main/cron/3.php) — формирование описания товара, работа с DeepSeek

**Дата:** 2026-04-08
**Версия ТЗ:** 1.0

---

## 0. Цель и назначение скрипта

Создать новый крон-скрипт `cron/4.php`, который:
1. Находит товар без цены в таблице `parsed_products`.
2. Берёт самую приоритетную ссылку из `parsed_links` (классифицированную скриптом `3.php`).
3. Загружает HTML-страницу по ссылке (через прокси, как в `2.php`).
4. **Дозированно** (по частям) отправляет HTML в DeepSeek для поиска цены товара.
5. При нахождении цены — записывает её в `parsed_products.price` и URL в `parsed_products.product_url`.
6. При отсутствии цены или товара — переходит к следующей ссылке (помечает текущую как обработанную).

Скрипт рассчитан на **многократный запуск по крону** — каждый запуск обрабатывает **одну ссылку** для **одного товара**.

---

## 1. Место в конвейере обработки

```
┌──────────────────┐     ┌──────────────────┐     ┌──────────────────┐     ┌──────────────────┐
│   cron/1.php     │     │   cron/2.php     │     │   cron/3.php     │     │   cron/4.php     │
│ Импорт товаров   │ ──▶ │ Парсинг ссылок   │ ──▶ │ Классификация    │ ──▶ │ Поиск цены       │
│ из Google Sheets │     │ со страниц       │     │ ссылок DeepSeek  │     │ через DeepSeek   │
│                  │     │ (HTML → <a> тэги)│     │ (priority 0–10)  │     │ (HTML → цена)    │
└──────────────────┘     └──────────────────┘     └──────────────────┘     └──────────────────┘
      status=NULL              status=1                status=2               price заполнен
      ↓ после 2.php           ↓ после 3.php           ↓ после 4.php          или price=0
```

**Предусловия для `4.php`:**
- Товар в `parsed_products` имеет `status = 2` (все ссылки классифицированы скриптом `3.php`)
- В `parsed_links` есть записи с `execution_status = 1` (помечены как релевантные скриптом `3.php`)
- Колонка `price` в `parsed_products` = `NULL` (цена ещё не найдена)

---

## 2. Структура базы данных (существующая)

### Таблица `parsed_products`

| Колонка       | Тип             | Описание                            | Используется в 4.php |
|---------------|-----------------|-------------------------------------|----------------------|
| `id`          | INT UNSIGNED PK | Уникальный ID                       | ✅ Чтение            |
| `url`         | VARCHAR(500)    | URL сайта                           | ℹ️ Информационно     |
| `category`    | VARCHAR(255)    | Категория товара                    | ℹ️ Информационно     |
| `name`        | VARCHAR(500)    | Название товара                     | ✅ Чтение            |
| `raid`        | VARCHAR(255)    | Рейд контроллер                     | ✅ Чтение            |
| `power_supply`| VARCHAR(255)    | Блок питания                        | ✅ Чтение            |
| `price`       | DECIMAL(12,2)   | **Цена товара** (NULL → заполняем!) | ✅ Запись             |
| `product_url` | VARCHAR(1000)   | **URL страницы с ценой**            | ✅ Запись             |
| `status`      | TINYINT         | Статус обработки                    | ✅ Чтение/Запись      |
| `updated_at`  | DATETIME        | Дата обновления (FIFO-очередь)      | ✅ Чтение/Запись      |

### Таблица `parsed_links`

| Колонка            | Тип             | Описание                    | Используется в 4.php |
|--------------------|-----------------|-----------------------------|----------------------|
| `id`               | INT UNSIGNED PK | Уникальный ID               | ✅ Чтение            |
| `parent_id`        | INT UNSIGNED FK | ID товара из parsed_products| ✅ Чтение            |
| `url_a`            | VARCHAR(2048)   | **URL страницы для парсинга**| ✅ Чтение            |
| `execution_status` | INT             | Статус: 1=релевантна, 2=обработана/не найден | ✅ Запись |
| `priority`         | INT             | Приоритет (0–10 от DeepSeek)| ✅ Чтение (сортировка)|

---

## 3. Алгоритм выполнения (пошагово)

### Шаг 0. Инициализация скрипта

```
set_time_limit(300)           — увеличить лимит выполнения до 5 минут
ob_implicit_flush(true)       — отключить буферизацию вывода
define('APP_ACCESS', true)    — определить константу доступа
require db.php                — подключение к БД (Singleton PDO)
require функции               — подключение необходимых функций
```

### Шаг 1. Получение товара для поиска цены

**SQL-запрос:**
```sql
SELECT id, name, raid, power_supply, url, category
FROM parsed_products
WHERE price IS NULL
  AND status = 2
ORDER BY updated_at ASC
LIMIT 1
```

**Логика:**
- Фильтр `price IS NULL` — только товары без цены
- Фильтр `status = 2` — только товары, у которых ссылки уже классифицированы (скрипт `3.php` завершил обработку)
- Сортировка `ORDER BY updated_at ASC` — FIFO-очередь (самая старая запись первой)
- `LIMIT 1` — обрабатываем по одному товару за запуск крона

**Если записей нет** → вывести `✅ Нет товаров для поиска цены.` → `exit(0)`

### Шаг 2. Формирование описания товара

Аналогично функции `buildProductInfoString()` из `cron/functions/get_product_with_links.php`:

```
Формат: name | raid (если есть) | power_supply (если есть)
Пример: "HP DL 360 G9 8SFF | P440ar | 2x500W"
```

**Полученные данные для дальнейших шагов:**
- `$productId` — ID товара
- `$productInfo` — строка описания товара

### Шаг 3. Получение приоритетной ссылки из `parsed_links`

**SQL-запрос:**
```sql
SELECT id, url_a
FROM parsed_links
WHERE parent_id = :product_id
  AND execution_status = 1
ORDER BY priority DESC
LIMIT 1
```

**Логика:**
- Фильтр `execution_status = 1` — только ссылки, классифицированные как релевантные (скрипт `3.php`)
- Сортировка `ORDER BY priority DESC` — сначала самые приоритетные ссылки (10 → 9 → 8 → ...)
- `LIMIT 1` — обрабатываем одну ссылку за запуск

**Если ссылок нет** (все `execution_status = 1` уже обработаны):
- Записать в `parsed_products`: `price = 0`, `updated_at = NOW()`
- Вывести `⚠️ Все ссылки исчерпаны, цена не найдена → price = 0`
- `exit(0)`

**Полученные данные:**
- `$linkId` — ID записи в `parsed_links`
- `$linkUrl` — URL страницы для парсинга

### Шаг 4. Загрузка HTML-страницы через прокси

**Используем существующие функции** из `cron/functions/proxy.php` и `cron/functions/parse_links.php` (паттерн из `cron/2.php`):

1. Получить прокси: `getProxy($proxyApiKey, $proxyUserId)`
2. Попытка загрузки через прокси: `fetchPage($linkUrl, $proxyData)`
3. Если через прокси не удалось — загрузка напрямую: `fetchPage($linkUrl)`
4. Обновить статистику прокси: `updateProxy(...)`

**Если HTML не загружен** (ошибка на обоих вариантах):
- Записать в `parsed_links`: `execution_status = 2` (обработана, неуспешно)
- Обновить `parsed_products.updated_at = NOW()` (сдвинуть в конец очереди)
- Вывести `❌ Не удалось загрузить страницу`
- `exit(1)`

**Полученные данные:**
- `$html` — полный HTML-код страницы

### Шаг 5. Дозированная отправка HTML в DeepSeek для поиска цены

⚠️ **Ключевая особенность:** HTML-код страницы может быть очень большим (100+ КБ). Отправлять весь HTML в одном запросе к DeepSeek неэффективно и может превысить лимит токенов. Поэтому HTML разбивается на **чанки (порции)** и отправляется последовательно.

#### 5.1. Очистка HTML (предобработка)

Перед разбиением на чанки — очистить HTML от мусора для уменьшения объёма:

1. Удалить содержимое тэгов `<script>...</script>`
2. Удалить содержимое тэгов `<style>...</style>`
3. Удалить HTML-комментарии `<!-- ... -->`
4. Удалить SVG-блоки `<svg>...</svg>`
5. Удалить `<noscript>...</noscript>`
6. Схлопнуть множественные пробелы / переводы строк в одинарные

#### 5.2. Разбиение на чанки

```
Размер чанка:  ~15 000 символов (≈ 4000 токенов DeepSeek)
Перекрытие:    500 символов (чтобы не потерять цену на стыке)
```

**Пример:**
- HTML после очистки: 60 000 символов
- Чанки: [0–15000], [14500–29500], [29000–44000], [43500–58500], [58000–60000]
- Итого: 5 запросов к DeepSeek

#### 5.3. Последовательная отправка чанков в DeepSeek

**Для каждого чанка отправляем запрос с промптом:**

**System-промпт:**
```
Ты — помощник для поиска цены товара на HTML-странице интернет-магазина.

Тебе дан товар и фрагмент HTML-кода страницы.

Твоя задача — найти цену указанного товара в этом фрагменте HTML.

Правила:
- Ищи только ТОЧНОЕ или БЛИЗКОЕ соответствие названию товара.
- Цена может быть в тэгах: <span>, <div>, <p>, <td>, атрибутах data-price и т.п.
- Цена может быть в формате: "1 234.56", "1234,56", "$1,234.56", "1 234 руб." и т.п.
- Если нашёл цену — верни её числовое значение (только цифры и точка как десятичный разделитель).
- Если вместо цены указано "Цена по запросу", "Звоните", "По запросу", "Call for price" — верни "REQUEST".
- Если товар не найден в этом фрагменте — верни "NOT_FOUND".
- Если товар найден, но цены нет — верни "NO_PRICE".

Верни ТОЛЬКО JSON без комментариев, без пояснений, без markdown-разметки.
Формат ответа:
{"status": "FOUND|NOT_FOUND|NO_PRICE|REQUEST", "price": число или null, "currency": "строка или null", "product_name_on_page": "строка или null"}
```

**User-сообщение:**
```
Товар: {$productInfo}
Фрагмент HTML (часть {N} из {total}):
{$chunk}
```

#### 5.4. Обработка ответа DeepSeek для каждого чанка

| Ответ DeepSeek `status`  | Действие                                                     | Продолжать перебор? |
|--------------------------|--------------------------------------------------------------|---------------------|
| `FOUND` (price > 0)     | Записать цену в `parsed_products.price`, URL в `product_url` | ⛔ СТОП             |
| `REQUEST`                | Записать `price = 0` в `parsed_products`                     | ⛔ СТОП             |
| `NOT_FOUND`              | Перейти к следующему чанку                                    | ✅ Продолжить       |
| `NO_PRICE`               | Запомнить, что товар найден, но без цены                      | ✅ Продолжить       |
| Ошибка парсинга JSON     | Перейти к следующему чанку                                    | ✅ Продолжить       |

#### 5.5. Получение API-ключа DeepSeek

Использовать существующую функцию `getDeepseekApiKey()` из `cron/functions/provider_deepseek.php`:
```php
$keyResult = getDeepseekApiKey($Api_Key, $Users_ID);
```

**API-ключ получать ОДИН РАЗ перед началом цикла по чанкам.**

#### 5.6. Переподключение к БД

После завершения всех запросов к DeepSeek (которые могут занять несколько минут) — **обязательно** переподключиться к БД перед записью результатов:

```php
$pdo = Database::reconnect();
```

---

## 4. Запись результатов в БД

### Сценарий A: Цена найдена (`status = FOUND`, `price > 0`)

```sql
-- 1. Записать цену и URL страницы в parsed_products
UPDATE parsed_products
SET price = :price,
    product_url = :product_url,
    updated_at = NOW()
WHERE id = :product_id

-- 2. Пометить ссылку как успешно обработанную в parsed_links
UPDATE parsed_links
SET execution_status = 3
WHERE id = :link_id
```

> `execution_status = 3` — цена найдена на этой странице (успех).

### Сценарий B: Цена по запросу (`status = REQUEST`)

```sql
-- 1. Записать price = 0 в parsed_products
UPDATE parsed_products
SET price = 0,
    product_url = :product_url,
    updated_at = NOW()
WHERE id = :product_id

-- 2. Пометить ссылку как обработанную
UPDATE parsed_links
SET execution_status = 3
WHERE id = :link_id
```

### Сценарий C: Товар/цена не найдены после всех чанков

```sql
-- Пометить ссылку как обработанную (безуспешно)
UPDATE parsed_links
SET execution_status = 2
WHERE id = :link_id

-- Обновить дату в parsed_products (сдвинуть в конец FIFO-очереди)
UPDATE parsed_products
SET updated_at = NOW()
WHERE id = :product_id
```

> При следующем запуске крона для этого товара будет выбрана **следующая** ссылка по приоритету.

### Сценарий D: Все ссылки исчерпаны (нет `execution_status = 1`)

Если на Шаге 3 не найдено ни одной ссылки с `execution_status = 1`:

```sql
-- Записать price = 0 (цена не найдена нигде)
UPDATE parsed_products
SET price = 0,
    updated_at = NOW()
WHERE id = :product_id
```

---

## 5. Значения `execution_status` в `parsed_links` (полная карта)

| Значение | Устанавливается | Описание                                     |
|----------|-----------------|----------------------------------------------|
| `NULL`   | `2.php`         | Ссылка найдена, ещё не классифицирована      |
| `1`      | `3.php`         | Ссылка релевантна, нужно искать товар/цену   |
| `0`      | `3.php`         | Ссылка нерелевантна                          |
| `2`      | **`4.php`**     | Ссылка обработана — товар/цена НЕ найдены    |
| `3`      | **`4.php`**     | Ссылка обработана — цена НАЙДЕНА (успех)     |

---

## 6. Схема потока выполнения `cron/4.php`

```
┌──────────────────────────────────────────────────────────┐
│ 0. set_time_limit(300), ob_implicit_flush(true)          │
│    define('APP_ACCESS', true), require db.php            │
├──────────────────────────────────────────────────────────┤
│ 1. SELECT из parsed_products (price IS NULL, status=2)   │
│    Нет записей → exit(0)                                 │
├──────────────────────────────────────────────────────────┤
│ 2. Сформировать описание товара                          │
│    "name | raid | power_supply"                          │
├──────────────────────────────────────────────────────────┤
│ 3. SELECT из parsed_links (execution_status=1,           │
│    ORDER BY priority DESC, LIMIT 1)                      │
│    Нет ссылок → price=0, exit(0)                         │
├──────────────────────────────────────────────────────────┤
│ 4. Получить API-ключ DeepSeek                            │
│    Ошибка → execution_status=2, exit(1)                  │
├──────────────────────────────────────────────────────────┤
│ 5. Загрузить HTML через прокси → напрямую (fallback)     │
│    Ошибка → execution_status=2, updated_at, exit(1)      │
├──────────────────────────────────────────────────────────┤
│ 6. Очистить HTML (скрипты, стили, комментарии)           │
├──────────────────────────────────────────────────────────┤
│ 7. Разбить на чанки (~15 000 символов, 500 перекрытие)   │
├──────────────────────────────────────────────────────────┤
│ 8. ЦИКЛ по чанкам:                                       │
│    ├─ Отправить чанк в DeepSeek                          │
│    ├─ FOUND (price > 0)  → СТОП → Сценарий A            │
│    ├─ REQUEST            → СТОП → Сценарий B            │
│    ├─ NOT_FOUND          → следующий чанк                │
│    ├─ NO_PRICE           → запомнить, следующий чанк     │
│    └─ Ошибка парсинга    → следующий чанк                │
├──────────────────────────────────────────────────────────┤
│ 9. Database::reconnect() (перед записью в БД)            │
├──────────────────────────────────────────────────────────┤
│ 10. Запись результатов (см. Сценарии A/B/C/D)            │
├──────────────────────────────────────────────────────────┤
│ 11. Вывод итогов в stdout                                │
└──────────────────────────────────────────────────────────┘
```

---

## 7. Список файлов для создания/изменения

### Новые файлы

| Файл | Назначение |
|------|-----------|
| `cron/4.php` | Основной крон-скрипт |
| `cron/functions/get_product_for_pricing.php` | Функции получения товара и ссылки для поиска цены |
| `cron/functions/deepseek_price_search.php` | Функции очистки HTML, разбиения на чанки, отправки в DeepSeek и обработки ответа |

### Переиспользуемые файлы (НЕ ИЗМЕНЯТЬ)

| Файл | Что используем |
|------|----------------|
| `connect/db.php` | `$pdo`, `Database::reconnect()` |
| `cron/functions/proxy.php` | `getProxy()`, `updateProxy()` |
| `cron/functions/parse_links.php` | `fetchPage()` |
| `cron/functions/provider_deepseek.php` | `getDeepseekApiKey()` |
| `cron/functions/get_product_with_links.php` | `buildProductInfoString()` |

---

## 8. Описание функций для новых файлов

### `cron/functions/get_product_for_pricing.php`

#### `getProductForPricing(PDO $pdo): ?array`
- Возвращает одну запись из `parsed_products` для поиска цены
- Фильтр: `price IS NULL AND status = 2`
- Сортировка: `ORDER BY updated_at ASC`
- Возвращает: `['id', 'name', 'raid', 'power_supply', 'url', 'category']` или `null`

#### `getBestLinkForProduct(PDO $pdo, int $productId): ?array`
- Возвращает самую приоритетную необработанную ссылку
- Фильтр: `parent_id = :id AND execution_status = 1`
- Сортировка: `ORDER BY priority DESC`
- `LIMIT 1`
- Возвращает: `['id', 'url_a']` или `null`

#### `saveProductPrice(PDO $pdo, int $productId, float $price, ?string $productUrl): void`
- Записывает цену и URL страницы в `parsed_products`
- SQL: `UPDATE parsed_products SET price = :price, product_url = :product_url, updated_at = NOW() WHERE id = :id`

#### `updateLinkExecutionStatus(PDO $pdo, int $linkId, int $status): void`
- Обновляет `execution_status` конкретной ссылки в `parsed_links`
- SQL: `UPDATE parsed_links SET execution_status = :status WHERE id = :id`

#### `touchProductUpdatedAt(PDO $pdo, int $productId): void`
- Обновляет только `updated_at` в `parsed_products` (сдвигает в конец FIFO-очереди)
- SQL: `UPDATE parsed_products SET updated_at = NOW() WHERE id = :id`

### `cron/functions/deepseek_price_search.php`

#### `cleanHtmlForPriceSearch(string $html): string`
- Удаляет `<script>`, `<style>`, `<!-- -->`, `<svg>`, `<noscript>`
- Схлопывает множественные пробелы/переводы строк
- Возвращает очищенный HTML

#### `splitHtmlIntoChunks(string $html, int $chunkSize = 15000, int $overlap = 500): array`
- Разбивает очищенный HTML на чанки заданного размера с перекрытием
- Возвращает массив строк (чанков)

#### `searchPriceInChunk(string $deepseekKey, string $productInfo, string $chunk, int $chunkNumber, int $totalChunks): array`
- Отправляет один чанк HTML в DeepSeek с промптом на поиск цены
- Возвращает: `['success' => bool, 'status' => 'FOUND|NOT_FOUND|NO_PRICE|REQUEST', 'price' => float|null, 'currency' => string|null, 'product_name' => string|null, 'error' => string|null]`
- Retry-логика при timeout/429/502/503 (до 3 попыток)

#### `searchPriceInHtml(string $deepseekKey, string $productInfo, string $html): array`
- **Основная функция** — оркестратор
- Очищает HTML → разбивает на чанки → последовательно отправляет в DeepSeek
- Останавливается при нахождении цены (`FOUND`) или обнаружении "Цена по запросу" (`REQUEST`)
- Возвращает: `['success' => bool, 'status' => 'FOUND|NOT_FOUND|NO_PRICE|REQUEST', 'price' => float|null, 'currency' => string|null, 'product_name' => string|null, 'chunks_processed' => int, 'chunks_total' => int]`

---

## 9. Настройки и константы

| Параметр | Значение | Описание |
|----------|----------|----------|
| `set_time_limit` | `300` (5 мин) | Максимальное время выполнения скрипта |
| `$Api_Key` | Такой же, как в `3.php` | Ключ авторизации servero.space |
| `$Users_ID` | `1` | ID пользователя |
| `$proxyApiKey` | Такой же, как в `2.php` | Ключ авторизации прокси API |
| `$proxyUserId` | `1` | ID пользователя прокси |
| Размер чанка | `15000` символов | Размер одного фрагмента HTML |
| Перекрытие чанков | `500` символов | Перекрытие между чанками |
| DeepSeek модель | `deepseek-chat` | Модель для запроса |
| `CURLOPT_TIMEOUT` | `120` | Таймаут запроса к DeepSeek |
| `CURLOPT_CONNECTTIMEOUT` | `30` | Таймаут подключения |
| Max retries | `3` | Максимум повторных попыток при ошибке |

---

## 10. Требования к реализации

1. **Только prepared statements** для всех SQL-запросов
2. **Логирование в stdout** в стиле эмодзи (`✅`, `❌`, `📡`, `🔍`), как в `2.php` и `3.php`
3. **Не изменять существующие файлы** — только создание новых
4. **Не менять структуру БД** — все нужные колонки уже существуют
5. **Переподключение к БД** (`Database::reconnect()`) перед записью результатов после вызовов DeepSeek
6. **Корректная обработка ошибок** — скрипт не должен «падать молча»
7. **Retry-логика** для запросов к DeepSeek (timeout, 429, 502, 503)
8. **Следовать паттернам** существующих скриптов (`2.php`, `3.php`):
   - Заголовок-комментарий файла (PHPDoc)
   - Секции с разделителями `━━━━━━━━`
   - Блок `═════ ОБРАБОТКА ЗАВЕРШЕНА ═════` в конце
9. **Очистка ответа DeepSeek** от markdown-обёрток (` ```json ... ``` `)

---

## 11. Обработка граничных случаев

| Ситуация | Действие |
|----------|----------|
| Нет товаров с `price IS NULL` и `status = 2` | `exit(0)` — ничего не делать |
| Нет ссылок с `execution_status = 1` для товара | Записать `price = 0`, `exit(0)` |
| Не удалось получить API-ключ DeepSeek | `execution_status = 2` для ссылки, `exit(1)` |
| Не удалось загрузить HTML (прокси + напрямую) | `execution_status = 2` для ссылки, `exit(1)` |
| HTML после очистки пустой (< 100 символов) | `execution_status = 2` для ссылки, `exit(0)` |
| DeepSeek вернул невалидный JSON | Пропустить чанк, перейти к следующему |
| DeepSeek вернул ошибку по всем чанкам | `execution_status = 2` для ссылки |
| Цена = 0 или отрицательная в ответе DeepSeek | Трактовать как `NOT_FOUND`, перейти к следующему чанку |
| Найден `NO_PRICE` во всех чанках (товар есть, цены нет) | `execution_status = 2`, `updated_at = NOW()` |
| «Цена по запросу» (REQUEST) | Записать `price = 0`, `product_url`, `execution_status = 3` |

---

## 12. Критерии приёмки

| # | Критерий | Способ проверки |
|---|----------|-----------------|
| 1 | Скрипт корректно выбирает товар с `price IS NULL`, `status = 2` | `SELECT` из БД до/после запуска |
| 2 | Выбирается ссылка с наивысшим `priority` и `execution_status = 1` | Проверить лог вывода |
| 3 | HTML загружается через прокси с fallback на прямой запрос | Проверить лог вывода |
| 4 | HTML очищается от скриптов/стилей перед отправкой | Проверить размер до/после в логе |
| 5 | HTML разбивается на чанки и отправляется последовательно | Проверить лог (`Чанк 1/5`, `Чанк 2/5`...) |
| 6 | При нахождении цены перебор чанков останавливается | Проверить лог — `СТОП, цена найдена` |
| 7 | Цена записывается в `parsed_products.price` | `SELECT price FROM parsed_products WHERE id = X` |
| 8 | URL страницы записывается в `parsed_products.product_url` | `SELECT product_url FROM parsed_products WHERE id = X` |
| 9 | При «Цена по запросу» записывается `price = 0` | `SELECT price FROM parsed_products WHERE id = X` |
| 10 | При неудаче — `execution_status = 2` в `parsed_links` | `SELECT execution_status FROM parsed_links WHERE id = X` |
| 11 | При успехе — `execution_status = 3` в `parsed_links` | `SELECT execution_status FROM parsed_links WHERE id = X` |
| 12 | Скрипт не зависает (set_time_limit, буферизация) | Запуск с 5+ чанками |
| 13 | Retry при timeout/429/502/503 | Проверить лог при ошибке DeepSeek |
| 14 | FIFO-порядок: самая старая `updated_at` первой | Множественные запуски |
| 15 | **Существующие файлы НЕ ИЗМЕНЕНЫ** | `git diff` — только новые файлы |

---

## 13. ⛔ Ограничения

1. **НЕ ИЗМЕНЯТЬ** файл `connect/db.php` — используется всеми скриптами
2. **НЕ ИЗМЕНЯТЬ** существующие файлы в `cron/functions/` — только переиспользовать
3. **НЕ ИЗМЕНЯТЬ** существующие крон-скрипты `1.php`, `2.php`, `3.php`
4. **НЕ МЕНЯТЬ** структуру таблиц БД — все нужные колонки уже существуют
5. **НЕ ХРАНИТЬ** полный HTML в БД — только обрабатывать в памяти

---

## 14. Пример вывода скрипта (ожидаемый)

```
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
🔍 Поиск товара для определения цены...
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

📄 ID записи: 42
🔗 Сайт: https://example-shop.com
📂 Категория: Платформы

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
📝 Товар: HP DL 360 G9 8SFF | P440ar | 2x500W
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
🔗 Ссылка для парсинга:
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

📄 Link ID: 1234
🌐 URL: https://example-shop.com/catalog/servers/hp-dl360-g9
📊 Приоритет: 9

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
🔑 Получение API-ключа DeepSeek...
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

✅ Ключ DeepSeek получен

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
🌐 Загрузка HTML-страницы...
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

🔄 Попытка через прокси: http://proxy.example.com:8080
✅ Страница загружена через прокси (1245 мс)
📊 Размер HTML: 87 432 символов

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
🧹 Очистка HTML...
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

📊 Размер после очистки: 34 218 символов (экономия 61%)
📊 Количество чанков: 3

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
🤖 Поиск цены через DeepSeek...
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

📤 Чанк 1/3 (15000 символов)...
📡 DeepSeek ответ: HTTP 200
📋 Результат: NOT_FOUND

📤 Чанк 2/3 (15000 символов)...
📡 DeepSeek ответ: HTTP 200
📋 Результат: FOUND — цена 45 500.00

🎯 Цена найдена! Остановка перебора.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
💾 Запись результатов в БД...
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

✅ parsed_products.price = 45500.00
✅ parsed_products.product_url = https://example-shop.com/catalog/servers/hp-dl360-g9
✅ parsed_links.execution_status = 3

════════════════════════════════════════════
✅ ОБРАБОТКА ЗАВЕРШЕНА
════════════════════════════════════════════
ID записи:     42
Товар:         HP DL 360 G9 8SFF | P440ar | 2x500W
Ссылка:        https://example-shop.com/catalog/servers/hp-dl360-g9
Цена:          45 500.00
Чанков:        2/3 (остановлено после нахождения)
Прокси:        Да
```
