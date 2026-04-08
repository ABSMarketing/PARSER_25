<?php
/**
 * Название файла:      db.php
 * Назначение:          Класс Database — Singleton-реализация PDO.
 *                      Обеспечивает единственный экземпляр подключения к базе данных
 *                      на протяжении всего жизненного цикла одного запроса.
 *                      Persistent connections отключены для предотвращения
 *                      исчерпания пула соединений базы данных.
 * Автор:               Команда разработки
 * Версия:              1.1
 * Дата создания:       2026-02-10
 * Последнее изменение: 2026-02-10
 */

// ========================================
// ПРОВЕРКА ДОСТУПА
// ========================================

if (!defined('APP_ACCESS')) {
    http_response_code(403);
    exit('Доступ запрещён');
}

// ========================================
// КЛАСС DATABASE (SINGLETON)
// ========================================

/**
 * Singleton-класс для управления подключением к базе данных через PDO.
 * Использует обычные (non-persistent) соединения для предотвращения
 * исчерпания пула соединений MySQL при высокой нагрузке.
 */
class Database
{
    /**
     * @var Database|null Единственный экземпляр класса
     */
    private static $instance = null;

    /**
     * @var PDO|null Объект подключения к базе данных
     */
    private $pdo = null;

    /**
     * @var string|null Хост базы данных
     */
    private static $host = null;

    /**
     * @var string|null Название базы данных
     */
    private static $dbName = null;

    /**
     * @var string|null Имя пользователя базы данных
     */
    private static $userName = null;

    /**
     * @var string|null Пароль пользователя базы данных
     */
    private static $password = null;

    /**
     * Инициализация параметров подключения из переменных окружения.
     * Вызывается автоматически перед первым подключением.
     */
    public static function init(): void
    {
        self::$host     = getenv('DB_HOST') ?: 'localhost';
        self::$dbName   = getenv('DB_NAME') ?: die("❌ Переменная окружения DB_NAME не задана\n");
        self::$userName = getenv('DB_USER') ?: die("❌ Переменная окружения DB_USER не задана\n");
        self::$password = getenv('DB_PASSWORD') ?: die("❌ Переменная окружения DB_PASSWORD не задана\n");
    }

    /**
     * Приватный конструктор для предотвращения прямого создания экземпляра
     */
    private function __construct()
    {
        $dsn = "mysql:host=" . self::$host . ";dbname=" . self::$dbName . ";charset=utf8";
        $options = [
            PDO::ATTR_PERSISTENT         => false,  // ✅ ОТКЛЮЧАЕМ persistent connections для избегания "Too many connections"
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8',  // Установка кодировки
        ];

        $this->pdo = new PDO($dsn, self::$userName, self::$password, $options);
    }

    /**
     * Получение экземпляра класса и объекта подключения
     *
     * @return PDO Объект PDO для работы с базой данных
     */
    public static function get(): PDO
    {
        if (self::$instance === null) {
            if (self::$dbName === null) {
                self::init();
            }
            self::$instance = new self();
        }
        
        return self::$instance->pdo;
    }

    /**
     * Закрытие соединения (принудительное уничтожение экземпляра)
     * Вызывать при необходимости
     */
    public static function close(): void
    {
        if (self::$instance !== null) {
            self::$instance->pdo = null; // Явное закрытие PDO соединения
            self::$instance = null;
        }
    }

    /**
     * Переподключение к базе данных.
     * Закрывает текущее соединение и создаёт новое.
     * Используется после длительных операций (например, вызов внешних API),
     * чтобы избежать ошибки "MySQL server has gone away".
     *
     * @return PDO Новый объект PDO
     */
    public static function reconnect(): PDO
    {
        self::close();
        return self::get();
    }

    /**
     * Закрытие соединения при завершении скрипта
     * Регистрируется через register_shutdown_function()
     */
    public static function shutdown(): void
    {
        if (self::$instance !== null) {
            self::$instance->pdo = null; // Явное закрытие PDO соединения
            self::$instance = null;
        }
    }
}

// ========================================
// ИНИЦИАЛИЗАЦИЯ ГЛОБАЛЬНОГО ПОДКЛЮЧЕНИЯ
// ========================================

// Глобальная переменная для удобства (как раньше)
$pdo = Database::get();

// Регистрация функции закрытия соединения при завершении скрипта
register_shutdown_function(['Database', 'shutdown']);