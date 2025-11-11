<?php
define('IN_APP', true);
require_once 'utils/functions.php';

if (is_logged_in()) {
    header('Location: profile.php');
    exit;
}

$error = $success = '';

if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
    if ( !validate_csrf($_POST['csrf'] ?? '') ) {
        $error = 'CSRF токен недействителен';
    } else {
        $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
        $pass  = $_POST['password'] ?? '';

        if ( !$email ) {
            $error = "Некорректный email";
        } elseif (strlen($pass) < 8) {
            $error = "Пароль должен быть не менее 8 символов";
        } else {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ( $stmt->fetch() ) {
                $error = "Этот email уже зарегистрирован";
            } else {
                $hash = password_hash($pass, PASSWORD_ARGON2ID);

                $stmt = $pdo->prepare("INSERT INTO users (email, password, created_at) VALUES (?, ?, NOW())");
                $stmt->execute([$email, $hash]);

                $success = "Регистрация успешна! Теперь вы можете войти.";
                unset($_SESSION['csrf_token']);
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Регистрация</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {font-family: Arial; max-width: 400px; margin: 50px auto; padding: 20px; background: #f4f4f4;}
        input, button {width: 100%; padding: 10px; margin: 8px 0; box-sizing: border-box;}
        button {background: #28a745; color: white; border: none; cursor: pointer;}
        button:hover {background: #218838;}
        .error {color: #d9534f; background: #f2dede; padding: 10px; border-radius: 4px;}
        .success {color: #155724; background: #d4edda; padding: 10px; border-radius: 4px;}
    </style>
</head>
<body>
    <h2>Регистрация</h2>
    <?php if ( $error ) echo "<div class='error'>$error</div>"; ?>
    <?php if ( $success ) echo "<div class='success'>$success</div>"; ?>

    <form method="POST" novalidate>
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
        <p><input type="email" name="email" placeholder="Email" required autofocus></p>
        <p><input type="password" name="password" placeholder="Пароль (мин. 8 символов)" minlength="8" required></p>
        <button type="submit">Зарегистрироваться</button>
    </form>
    <p><a href="login.php">Уже есть аккаунт? Войти</a></p>
</body>
</html>