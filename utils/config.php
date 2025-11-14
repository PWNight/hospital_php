<?php
// Блокируем доступ при неавторизованном запросе
if (!defined('IN_APP')) {
    die('Direct access not permitted');
}

// Обьявляем переменные данных от базы
define('DB_HOST', 'localhost');
define('DB_NAME', 'hospital');
define('DB_USER', 'rodion');
define('DB_PASS', 'rodion');
define('SITE_URL', 'http://localhost:8000');
define('SESSION_LIFETIME', 3600 * 24 * 30);

// Регистрируем соединение с базой
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    error_log("DB Error: " . $e->getMessage());
    http_response_code(500);
    die("Сервис временно недоступен");
}

// Запуск безопасной сессии
function secure_session_start() {
    ini_set('session.use_only_cookies', 1);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', 0);
    ini_set('session.cookie_samesite', 'Strict');

    $host = parse_url(SITE_URL, PHP_URL_HOST);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'domain'   => $host,
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);

    session_start();

    // Если сессия не инициализирована, то инициализируем, иначе просто обновляем
    if (!isset($_SESSION['initiated'])) {
        session_regenerate_id(true);
        $_SESSION['initiated'] = true;
        $_SESSION['created'] = time();
    } elseif (time() - $_SESSION['created'] > SESSION_LIFETIME / 2) {
        session_regenerate_id(true);
        $_SESSION['created'] = time();
    }
}