<?php
if (!defined('IN_APP')) {
    die('Access denied');
}

require_once 'config.php';

if ( session_status() === PHP_SESSION_NONE ) {
    secure_session_start();
}

// Генерация CSRF токена
function csrf_token() {
    if ( empty($_SESSION['csrf_token']) ) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    return $_SESSION['csrf_token'];
}

// Проверка CSRF токена
function validate_csrf( $token ) {
    if ( !isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time']) ) return false;
    if ( time() - $_SESSION['csrf_token_time'] > 3600 ) {
        unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

// Проверка на брутфорс
function check_bruteforce( $pdo, $ip ) {
    $stmt = $pdo->prepare("SELECT attempts, last_attempt FROM login_attempts WHERE ip = ?");
    $stmt->execute([$ip]);
    $row = $stmt->fetch();

    if ( $row && $row['attempts'] >= 5 && strtotime($row['last_attempt']) > time() - 300 ) {
        return false;
    }
    return true;
}

// Добавить запись о входе
function increment_bruteforce($pdo, $ip) {
    $stmt = $pdo->prepare("INSERT INTO login_attempts (ip, attempts, last_attempt) 
                           VALUES (?, 1, NOW()) 
                           ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = NOW()");
    $stmt->execute([$ip]);
}

// Очистить записи входа
function clear_bruteforce($pdo, $ip) {
    $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE ip = ?");
    $stmt->execute([$ip]);
}

// Проверка авторизации
function is_logged_in() {
    return isset(
        $_SESSION['user_id']) && isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] < SESSION_LIFETIME
    );
}

// Обновление последней активности
function update_activity() {
    $_SESSION['last_activity'] = time();
}

// Выход из системы
function logout() {
    $_SESSION = [];
    if ( ini_get("session.use_cookies") ) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}