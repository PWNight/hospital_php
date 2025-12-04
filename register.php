<?php
// Разрешаем доступ к файлу и импортируем функции
define('IN_APP', true);
require_once 'utils/config.php'; // Добавлено, так как $pdo не определен без него
require_once 'utils/functions.php';

// Проверяем наличие авторизации
if (is_logged_in()) {
    header('Location: profile.php');
    exit;
}

// Обьявляем переменные
global $pdo; // Убедимся, что $pdo доступен
$error = $success = '';

// Если метод ПОСТ, то выполняем код авторизации
if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
    // Проверяем валидность CSRF токена
    if ( !validate_csrf($_POST['csrf'] ?? '') ) {
        $error = 'CSRF токен недействителен';
    } else {
        // Получаем данные из формы
        $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
        $pass  = $_POST['password'] ?? '';
        $account_type = $_POST['type'] ?? '';

        // Проверяем валидность общих полей
        if ( !$email ) {
            $error = "Некорректный email";
        } elseif ( !$account_type ){
            $error = "Не выбран тип аккаунта";
        } elseif (strlen($pass) < 8) {
            $error = "Пароль должен быть не менее 8 символов";
        } else {
            // Получаем email из БД и проверяем на дубликат
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ( $stmt->fetch() ) {
                $error = "Этот email уже зарегистрирован";
            } else {
                // Хешируем пароль
                $hash = password_hash($pass, PASSWORD_ARGON2ID);

                switch ($account_type) {
                    case "doctor":
                        // Получаем данные из формы
                        $department = filter_var($_POST['department'] ?? '', FILTER_VALIDATE_INT);
                        $doc_full_name = trim($_POST['doc_full_name'] ?? ''); // <-- Renamed for clarity
                        $position = trim($_POST['position'] ?? '');
                        $doc_phone_number = trim($_POST['doc_phone_number'] ?? ''); // <-- Renamed for clarity

                        if ( !$department || !$doc_full_name || !$position || !$doc_phone_number ) { // <-- Updated validation
                            $error = 'Указаны не все данные врача';
                        }else{
                            // 1. Создаём новую учётку
                            $stmt = $pdo->prepare("INSERT INTO users (email, password, created_at, type) VALUES (?, ?, NOW(), ?)");
                            $stmt->execute([$email, $hash, $account_type]);
                            $user_id = $pdo->lastInsertId();

                            // 2. Создаём запись врача
                            $stmt = $pdo->prepare("
                                INSERT INTO doctors (fk_user, fk_department, full_name, position,
                                email, phone_number) VALUES (?, ?, ?, ?, ?, ?)");
                            $stmt->execute([$user_id, $department, $doc_full_name, $position, $email, $doc_phone_number]); // <-- Used new variables

                            // Переносим на страницу авторизации
                            header('Location: login.php');
                            exit;
                        }
                        break;
                    case "patient":
                        // Получаем данные из формы
                        $pat_full_name = trim($_POST['pat_full_name'] ?? ''); // <-- Renamed for clarity
                        $birth_date = trim($_POST['birth_date'] ?? '');
                        $home_address = trim($_POST['home_address'] ?? '');
                        $pat_phone_number = trim($_POST['pat_phone_number'] ?? ''); // <-- Renamed for clarity

                        if ( !$pat_full_name || !$birth_date || !$home_address || !$pat_phone_number ) { // <-- Updated validation
                            $error = 'Указаны не все данные пациента';
                        }else{
                            // 1. Создаём новую учётку
                            $stmt = $pdo->prepare("INSERT INTO users (email, password, created_at, type) VALUES (?, ?, NOW(), ?)");
                            $stmt->execute([$email, $hash, $account_type]);
                            $user_id = $pdo->lastInsertId();

                            // 2. Создаём запись пациента
                            $stmt = $pdo->prepare("
                                INSERT INTO patients (fk_user, full_name, birth_date, home_address,
                                email, phone_number) VALUES (?, ?, ?, ?, ?, ?)");
                            $stmt->execute([$user_id, $pat_full_name, $birth_date, $home_address, $email, $pat_phone_number]); // <-- Used new variables

                            // Переносим на страницу авторизации
                            header('Location: login.php');
                            exit;
                        }
                        break;
                }
            }
        }
    }
}

// Фетчинг списка отделений для селекта
$departments = [];
if (isset($pdo)) {
    $stmt = $pdo->prepare("SELECT id, name FROM departments ORDER BY name");
    $stmt->execute();
    $departments = $stmt->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Регистрация — МедЦентр "Надежда"</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        /* Минималистичный белый/синий дизайн */
        :root {
            --bs-primary: #007BFF;
            --bs-primary-rgb: 0,123,255;
        }
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .card-register {
            max-width: 500px; 
            margin: 50px auto; 
            box-shadow: 0 8px 16px rgba(0,0,0,.08); 
            border: none;
        }
        .btn-primary { background-color: var(--bs-primary); border-color: var(--bs-primary); }
        .btn-primary:hover { background-color: #0056b3; border-color: #0056b3; }
        .text-accent { color: var(--bs-primary) !important; }
        
        .form-section { border: 1px dashed #ced4da; border-radius: 6px; padding: 15px; margin-top: 15px; background-color: #ffffff; }
    </style>
</head>
<body>
    <div class="card card-register">
        <div class="card-body p-4 p-md-5">
            <h2 class="card-title text-center text-accent mb-4 fw-bold">
                <i class="fas fa-user-plus me-2"></i> Регистрация
            </h2>
            <?php if ( $error ): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" novalidate>
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                
                <div class="mb-3">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" class="form-control" name="email" id="email" autocomplete="email" placeholder="email@example.com" required autofocus value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>
                
                <div class="mb-3">
                    <label for="password" class="form-label">Пароль</label>
                    <input type="password" class="form-control" name="password" id="password" placeholder="Пароль (мин. 8 символов)" minlength="8" required>
                </div>
                
                <div class="mb-3">
                    <label for="type" class="form-label">Тип аккаунта</label>
                    <select id="type" name="type" class="form-select" required>
                        <option value="" disabled selected>Выберите тип аккаунта</option>
                        <option value="doctor" <?= (($_POST['type'] ?? '') === 'doctor') ? 'selected' : '' ?>>Врач</option>
                        <option value="patient" <?= (($_POST['type'] ?? '') === 'patient') ? 'selected' : '' ?>>Пациент</option>
                    </select>
                </div>

                <div id="patient-form" class="form-section" style="display:<?= (($_POST['type'] ?? '') === 'patient') ? 'block' : 'none' ?>;">
                    <h5 class="text-secondary"><i class="fas fa-hospital-user me-1"></i> Данные пациента</h5>
                    <div class="mb-3">
                        <label for="pat_full_name" class="form-label">ФИО</label>
                        <input type="text" class="form-control" name="pat_full_name" id="pat_full_name" placeholder="Иванов Иван Иванович" value="<?= htmlspecialchars($_POST['pat_full_name'] ?? '') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="birth_date" class="form-label">Дата рождения</label>
                        <input type="date" class="form-control" name="birth_date" id="birth_date" value="<?= htmlspecialchars($_POST['birth_date'] ?? '') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="home_address" class="form-label">Домашний адрес</label>
                        <input type="text" class="form-control" name="home_address" id="home_address" placeholder="ул. Ленина, д. 5, кв. 10" value="<?= htmlspecialchars($_POST['home_address'] ?? '') ?>" required>
                    </div>
                    <div class="mb-0">
                        <label for="pat_phone_number" class="form-label">Номер телефона</label>
                        <input type="tel" class="form-control" name="pat_phone_number" id="pat_phone_number" placeholder="+7 999 123-45-67" value="<?= htmlspecialchars($_POST['pat_phone_number'] ?? '') ?>" required>
                    </div>
                </div>
                
                <div id="doctor-form" class="form-section" style="display:<?= (($_POST['type'] ?? '') === 'doctor') ? 'block' : 'none' ?>;">
                    <h5 class="text-secondary"><i class="fas fa-user-md me-1"></i> Данные врача</h5>
                    <div class="mb-3">
                        <label for="doc_full_name" class="form-label">ФИО</label>
                        <input type="text" class="form-control" name="doc_full_name" id="doc_full_name" placeholder="Петров Петр Петрович" value="<?= htmlspecialchars($_POST['doc_full_name'] ?? '') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="department" class="form-label">Отделение</label>
                        <select id="department" name="department" class="form-select" required>
                            <option value="" disabled selected>Выберите отделение</option>
                            <?php foreach( $departments as $department ) : ?>
                                <option value="<?= $department['id'] ?>" <?= (($_POST['department'] ?? '') == $department['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($department["name"]) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="position" class="form-label">Должность</label>
                        <input type="text" class="form-control" name="position" id="position" placeholder="Терапевт" value="<?= htmlspecialchars($_POST['position'] ?? '') ?>" required>
                    </div>
                    <div class="mb-0">
                        <label for="doc_phone_number" class="form-label">Номер телефона</label>
                        <input type="tel" class="form-control" name="doc_phone_number" id="doc_phone_number" placeholder="+7 999 123-45-67" value="<?= htmlspecialchars($_POST['doc_phone_number'] ?? '') ?>" required>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary btn-lg mt-4">
                    <i class="fas fa-check-circle me-1"></i> Зарегистрироваться
                </button>
            </form>
            
            <a href="login.php" class="d-block text-center mt-3 text-accent text-decoration-none">
                <i class="fas fa-sign-in-alt me-1"></i> Уже есть аккаунт? Войти
            </a>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // JS для переключения форм
        const selectType = document.getElementById("type");
        const doctorForm = document.getElementById("doctor-form");
        const patientForm = document.getElementById("patient-form");
        
        selectType.addEventListener("change", function (){
            const value = this.value;
            if(value === "doctor"){
                doctorForm.style.display = "block";
                patientForm.style.display = "none";
            } else if (value === "patient") {
                patientForm.style.display = "block";
                doctorForm.style.display = "none";
            }
        });
    </script>
</body>
</html>