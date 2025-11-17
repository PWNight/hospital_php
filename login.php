<?php
// Разрешаем доступ к файлу и импортируем функции
define('IN_APP', true);
require_once 'utils/functions.php';

// Проверяем наличие авторизации
if (is_logged_in()) {
    header('Location: profile.php');
    exit;
}

// Обьявляем переменные
$ip = $_SERVER['REMOTE_ADDR'];
$error = '';

// Если метод ПОСТ, то выполняем код авторизации
if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
    // Проверяем валидность CSRF токена и брутфорса
    if ( !validate_csrf($_POST['csrf'] ?? '') ) {
        $error = 'Ошибка безопасности (CSRF)';
    } elseif ( !check_bruteforce($pdo, $ip) ) {
        $error = "Слишком много попыток. Подождите 5 минут.";
    } else {
        // Получаем данные из формы
        $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
        $pass  = $_POST['password'] ?? '';

        // Проверяем валидность полей
        if ( !$email || !$pass ) {
            $error = "Заполните все поля";
            increment_bruteforce($pdo, $ip);
        } else {
            // Получаем данные из БД
            $stmt = $pdo->prepare("SELECT id, password FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            // Сверяем данные из БД и формы
            if ( $user && password_verify($pass, $user['password']) ) {
                // Очищаем попытки входа и пересоздаём сессию
                clear_bruteforce($pdo, $ip);
                session_regenerate_id(true);

                // Заполняем сессию
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_email'] = $email;
                $_SESSION['last_activity'] = time();

                // Убираем CSRF токен и переходим в профиль
                unset($_SESSION['csrf_token']);
                header('Location: profile.php');
                exit;
            } else {
                // Засчитываем попытку входа и выдаём ошибку
                increment_bruteforce($pdo, $ip);
                $error = "Неверный email или пароль";
                sleep(2);
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Вход</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {font-family: Arial; max-width: 400px; margin: 50px auto; padding: 20px; background: #f4f4f4;}
        input, button {width: 100%; padding: 10px; margin: 8px 0; box-sizing: border-box;}
        button {background: #007cba; color: white; border: none; cursor: pointer;}
        button:hover {background: #005a87;}
        .error {color: #d9534f; background: #f2dede; padding: 10px; border-radius: 4px;}
    </style>
</head>
<body>
    <h2>Вход в систему</h2>
    <?php if ($error) echo "<div class='error'>$error</div>"; ?>

    <form method="POST" novalidate>
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
        <p><input type="email" name="email" placeholder="Email" required autofocus></p>
        <p><input type="password" name="password" placeholder="Пароль" required></p>
        <button type="submit">Войти</button>
    </form>
    <p><a href="register.php">Нет аккаунта? Зарегистрироваться</a></p>
</body>
</html>