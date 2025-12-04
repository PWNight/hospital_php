<?php
define('IN_APP', true);
require_once 'utils/functions.php';

$is_logged_in = is_logged_in();

// Заголовок для header.php
$page_title = 'Медицинский центр "Надежда" - Главная';

// Включаем новый header
require_once 'header.php'; 
?>

<header class="hero-section text-center">
    <div class="container">
        <h1 class="display-4 mb-3">Ваше здоровье — наша забота</h1>
        <p class="lead mb-4 text-muted">Мы предлагаем высококачественные медицинские услуги с индивидуальным подходом к каждому пациенту.</p>
        <?php if (!$is_logged_in): ?>
            <a href="register.php" class="btn btn-primary btn-lg me-3"><i class="fas fa-user-plus me-1"></i> Записаться</a>
        <?php else: ?>
            <a href="profile.php" class="btn btn-primary btn-lg me-3"><i class="fas fa-user-circle me-1"></i> Личный кабинет</a>
        <?php endif; ?>
        <a href="#services" class="btn btn-outline-primary btn-lg">Узнать больше</a>
    </div>
</header>

<section id="services" class="py-5">
    <div class="container text-center">
        <h2 class="mb-4 fw-bold">Наши ключевые услуги</h2>
        <p class="text-muted mb-5">Лучшие специалисты и современное оборудование для вашего благополучия.</p>
        <div class="row">
            <div class="col-md-4 mb-4">
                <div class="feature-card">
                    <div class="icon-circle"><i class="fas fa-heartbeat fa-2x"></i></div>
                    <h4 class="fw-bold">Кардиология</h4>
                    <p class="text-muted">Современная диагностика и лечение заболеваний сердечно-сосудистой системы.</p>
                </div>
            </div>
            <div class="col-md-4 mb-4">
                <div class="feature-card">
                    <div class="icon-circle"><i class="fas fa-user-md fa-2x"></i></div>
                    <h4 class="fw-bold">Терапия</h4>
                    <p class="text-muted">Консультации, профилактика и лечение общих заболеваний.</p>
                </div>
            </div>
            <div class="col-md-4 mb-4">
                <div class="feature-card">
                    <div class="icon-circle"><i class="fas fa-bone fa-2x"></i></div>
                    <h4 class="fw-bold">Травматология</h4>
                    <p class="text-muted">Помощь при травмах и заболеваниях опорно-двигательного аппарата.</p>
                </div>
            </div>
        </div>
        <a href="#" class="btn btn-outline-primary mt-4">Посмотреть все услуги</a>
    </div>
</section>

<?php 
// Включаем новый footer, который содержит закрывающие теги и скрипты
require_once 'footer.php'; 
?>