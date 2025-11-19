<?php
define('IN_APP', true);
require_once 'utils/functions.php';
if (session_status() === PHP_SESSION_NONE) {
    secure_session_start();
}
$is_logged_in = is_logged_in();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Медицинский центр "Надежда" - Главная</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        .hero-section {
            /* Медицинский синий фон с градиентом для профессионального вида */
            background: linear-gradient(rgba(23, 162, 184, 0.8), rgba(23, 162, 184, 0.8)), url('images/hospital-bg.jpg'); 
            background-size: cover;
            color: white;
            padding: 100px 0;
        }
        .section-padding {
            padding: 60px 0;
        }
        .icon-circle {
            background-color: #17a2b8;
            color: white;
            padding: 15px;
            border-radius: 50%;
            display: inline-flex;
            width: 60px;
            height: 60px;
            justify-content: center;
            align-items: center;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>

<header>
    <nav class="navbar navbar-expand-lg navbar-light bg-light">
        <div class="container">
            <a class="navbar-brand text-info fw-bold" href="index.php"><i class="fas fa-hospital-alt"></i> МЦ "НАДЕЖДА"</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item"><a class="nav-link active" href="index.php">Главная</a></li>
                    <li class="nav-item"><a class="nav-link" href="#services">Услуги</a></li>
                    <li class="nav-item"><a class="nav-link" href="#doctors">Врачи</a></li>
                    <li class="nav-item"><a class="nav-link" href="#contacts">Контакты</a></li>
                </ul>
                <div class="d-flex">
                    <?php if ($is_logged_in): ?>
                        <a href="profile.php" class="btn btn-success"><i class="fas fa-user-circle"></i> Личный кабинет</a>
                    <?php else: ?>
                        <a href="login.php" class="btn btn-outline-info me-2"><i class="fas fa-sign-in-alt"></i> Войти</a>
                        <a href="register.php" class="btn btn-info text-white"><i class="fas fa-user-plus"></i> Регистрация</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>
</header>

<section class="hero-section text-center">
    <div class="container">
        <h1>Забота о вашем здоровье — наша миссия</h1>
        <p class="lead mb-4">Полный спектр медицинских услуг от ведущих специалистов. Доверьте свое здоровье профессионалам.</p>
        <a href="login.php" class="btn btn-light btn-lg me-3"><i class="fas fa-calendar-check"></i> Записаться на приём</a>
        <a href="#contacts" class="btn btn-outline-light btn-lg">Наши контакты</a>
    </div>
</section>

<section id="services" class="section-padding">
    <div class="container text-center">
        <h2>Направления и Услуги</h2>
        <p class="text-muted mb-5">Мы предлагаем высококачественную медицинскую помощь по широкому спектру направлений.</p>
        <div class="row">
            <div class="col-md-4 mb-4">
                <div class="icon-circle"><i class="fas fa-heartbeat fa-2x"></i></div>
                <h4 class="fw-bold">Кардиология</h4>
                <p>Современная диагностика и лечение заболеваний сердечно-сосудистой системы.</p>
            </div>
            <div class="col-md-4 mb-4">
                <div class="icon-circle"><i class="fas fa-user-md fa-2x"></i></div>
                <h4 class="fw-bold">Терапия</h4>
                <p>Консультации, профилактика и лечение общих заболеваний.</p>
            </div>
            <div class="col-md-4 mb-4">
                <div class="icon-circle"><i class="fas fa-bone fa-2x"></i></div>
                <h4 class="fw-bold">Травматология</h4>
                <p>Помощь при травмах и заболеваниях опорно-двигательного аппарата.</p>
            </div>
        </div>
        <a href="#" class="btn btn-outline-info mt-4">Посмотреть все услуги</a>
    </div>
</section>

<footer class="bg-dark text-white py-4">
    <div class="container text-center">
        <p class="mb-0">&copy; <?= date('Y') ?> Медицинский центр "Надежда". Все права защищены.</p>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>