<?php
// header.php

// Определяем заголовок, если не передан
$page_title = $page_title ?? 'Медицинский центр "Надежда"';
// Предполагаем, что функция is_logged_in() доступна
$is_logged_in = is_logged_in(); 
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($page_title) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="style.css"> 
</head>
<body>
<nav class="navbar navbar-expand-lg">
    <div class="container">
        <a class="navbar-brand" href="index.php">
            <i class="fas fa-clinic-medical me-2"></i>Надежда
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a class="nav-link" href="index.php">Главная</a>
                </li>
                <?php if ($is_logged_in): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="profile.php">
                            <i class="fas fa-user-circle me-1"></i>Профиль
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="btn btn-outline-primary ms-2" href="logout.php">
                            <i class="fas fa-sign-out-alt me-1"></i>Выйти
                        </a>
                    </li>
                <?php else: ?>
                    <li class="nav-item">
                        <a class="nav-link" href="login.php">Войти</a>
                    </li>
                    <li class="nav-item">
                        <a class="btn btn-primary ms-2" href="register.php">
                            <i class="fas fa-user-plus me-1"></i>Регистрация
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>