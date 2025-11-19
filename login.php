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
    <title>Вход в личный кабинет</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        /* Обновленные стили для профессионального вида */
        body {font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; max-width: 500px; margin: 50px auto; padding: 20px; background: #f4f7f9; border: 1px solid #dee2e6; border-radius: 8px;}
        h2 {color: #17a2b8; text-align: center; margin-bottom: 25px;} /* Медицинский синий */
        input, button {width: 100%; padding: 12px; margin: 8px 0; box-sizing: border-box; border: 1px solid #ced4da; border-radius: 4px;}
        button {background: #17a2b8; color: white; border: none; cursor: pointer; font-weight: bold;}
        button:hover {background: #138496;}
        .error {color: #dc3545; background: #f8d7da; padding: 10px; border-radius: 4px; border: 1px solid #f5c6cb; margin-bottom: 15px;}
        a {color: #17a2b8; text-decoration: none; display: block; text-align: center; margin-top: 15px;}
        a:hover {text-decoration: underline;}
        .links{display: flex; align-items: center; justify-content: space-between;}
    </style>
</head>
<body>
    <h2>Вход в личный кабинет</h2>
    <?php if ($error) echo "<div class='error'>$error</div>"; ?>

    <form method="POST" novalidate>
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
        <label for="email">Email</label>
        <input type="email" name="email" id="email" placeholder="Введите ваш email" required>

        <label for="password">Пароль</label>
        <input type="password" name="password" id="password" placeholder="Введите ваш пароль" required>

        <button type="submit">Войти</button>
    </form>
    <div class="links">
        <a href="index.php">← На главную</a>
        <a href="register.php">Нет аккаунта? Зарегистрироваться</a>
    </div>
</body>
</html>