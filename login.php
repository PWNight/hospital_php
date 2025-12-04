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
global $pdo; // Убедимся, что $pdo доступен
$ip = $_SERVER['REMOTE_ADDR'];
$error = '';

// Если метод ПОСТ, то выполняем код авторизации
if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
    // Проверяем валидность CSRF токена и брутфорса
    if ( !validate_csrf($_POST['csrf'] ?? '') ) {
        $error = 'Ошибка безопасности (CSRF)';
    } elseif ( !check_bruteforce($pdo, $ip) ) {
        $error = "Слишком много попыток входа. Подождите 5 минут.";
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
                // Успешный вход
                clear_bruteforce($pdo, $ip);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['last_activity'] = time();

                header('Location: profile.php');
                exit;
            } else {
                // Неудачный вход
                $error = "Неверный email или пароль";
                increment_bruteforce($pdo, $ip);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Вход - Медицинский центр</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        /* Минималистичный белый/синий дизайн */
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 20px;
        }
        .form-container {
            max-width: 400px;
            margin: 50px auto;
            padding: 30px;
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,.05);
        }
        h2 {
            color: #007BFF;
            text-align: center;
            margin-bottom: 25px;
            font-weight: 300;
        }
        label {
            font-weight: 500;
        }
        .btn-primary {
            background-color: #007BFF;
            border-color: #007BFF;
            color: white;
            padding: 12px;
            font-weight: bold;
            margin-top: 20px;
        }
        .btn-primary:hover {
            background-color: #0056b3;
            border-color: #0056b3;
        }
        a {
            color: #007BFF;
            text-decoration: none;
            display: block;
            text-align: center;
            margin-top: 15px;
        }
        a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="form-container">
        <h2>Вход в личный кабинет</h2>

        <?php if ($error): ?>
            <div class='alert alert-danger'><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" novalidate>
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            
            <div class="mb-3">
                <label for="email" class="form-label">Email</label>
                <input type="email" name="email" id="email" class="form-control" placeholder="Введите ваш email" required>
            </div>
            
            <div class="mb-3">
                <label for="password" class="form-label">Пароль</label>
                <input type="password" name="password" id="password" class="form-control" placeholder="Введите пароль" required>
            </div>

            <button type="submit" class="btn btn-primary">Войти</button>
        </form>
        <div class="links">
            <a href="register.php">Нет аккаунта? Зарегистрироваться</a>
        </div>
    </div>
</body>
</html>