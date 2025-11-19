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

        // Проверяем валидность полей
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
                        $department = $_POST['department'] ?? '';
                        $full_name = $_POST['doc_full_name'] ?? '';
                        $position = $_POST['position'] ?? '';
                        $phone_number = $_POST['doc_phone_number'] ?? '';

                        if ( !$department || !$full_name || !$position || !$phone_number ) {
                            $error = 'Указаны не все данные врача';
                        }else{
                            // Создаём новую учётку
                            $stmt = $pdo->prepare("INSERT INTO users (email, password, created_at, type) VALUES (?, ?, NOW(), ?)");
                            $stmt->execute([$email, $hash, $account_type]);
                            $user_id = $pdo->lastInsertId();

                            // Создаём запись врача
                            $stmt = $pdo->prepare("
                                INSERT INTO doctors (fk_user, fk_department, full_name, position,
                                email, phone_number) VALUES (?, ?, ?, ?, ?, ?)");
                            $stmt->execute([$user_id, $department, $full_name, $position, $email, $phone_number]);

                            // Переносим на страницу авторизации
                            header('Location: login.php');
                            exit;
                        }
                        break;
                    case "patient":
                        // Получаем данные из формы
                        $full_name = $_POST['pat_full_name'] ?? '';
                        $birth_date = $_POST['birth_date'] ?? '';
                        $home_address = $_POST['home_address'] ?? '';
                        $phone_number = $_POST['pat_phone_number'] ?? '';

                        if ( !$full_name || !$birth_date || !$home_address || !$phone_number ) {
                            $error = 'Указаны не все данные пациента';
                        }else{
                            // Создаём новую учётку
                            $stmt = $pdo->prepare("INSERT INTO users (email, password, created_at, type) VALUES (?, ?, NOW(), ?)");
                            $stmt->execute([$email, $hash, $account_type]);
                            $user_id = $pdo->lastInsertId();

                            // Создаём запись пациента
                            $stmt = $pdo->prepare("
                                INSERT INTO patients (fk_user, full_name, birth_date, home_address,
                                email, phone_number) VALUES (?, ?, ?, ?, ?, ?)");
                            $stmt->execute([$user_id, $full_name, $birth_date, $home_address, $email, $phone_number]);

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
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Регистрация</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {font-family: Arial; max-width: 400px; margin: 50px auto; padding: 20px; background: #f4f4f4;}
        input, button, select {width: 100%; padding: 10px; margin: 8px 0; box-sizing: border-box;}
        button {background: #28a745; color: white; border: none; cursor: pointer;}
        button:hover {background: #218838;}
        .error {color: #d9534f; background: #f2dede; padding: 10px; margin: 8px 0; border-radius: 4px;}
    </style>
</head>
<body>
    <h2>Регистрация</h2>
    <?php if ( $error ) echo "<div class='error'>$error</div>"; ?>
    <form method="POST" novalidate>
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
        <div>
            <label for="email">Введите почту</label>
            <input type="email" name="email" id="email" autocomplete="email" placeholder="Email" required autofocus>
        </div>
        <div>
            <label for="password">Введите пароль</label>
            <input type="password" name="password" id="password" placeholder="Пароль (мин. 8 символов)" minlength="8" required>
        </div>
        <div>
            <label for="type">Выберите тип аккаунта</label>
            <select id="type" name="type">
                    <option selected disabled>Выберите тип аккаунта</option>
                    <option value="doctor">Врач</option>
                    <option value="patient">Пациент</option>
            </select>
        </div>
        <div id="patient-form" style="display:none;">
            <div>
                <label for="pat_full_name">Введите ФИО</label>
                <input name="pat_full_name" id="pat_full_name" minlength="8" required>
            </div>
            <div>
                <label for="birth_date">Введите дату рождения</label>
                <input type="date" name="birth_date" id="birth_date" required>
            </div>
            <div>
                <label for="home_address">Введите домашний адрес</label>
                <input name="home_address" id="home_address" minlength="8" required>
            </div>
            <div>
                <label for="pat_phone_number">Введите номер телефона</label>
                <input type="tel" name="pat_phone_number" id="pat_phone_number" minlength="8" required>
            </div>
        </div>
        <div id="doctor-form" style="display:none;">
            <div>
                <label for="doc_full_name">Введите ФИО</label>
                <input name="doc_full_name" id="doc_full_name" minlength="8" required>
            </div>
            <div>
                <label for="department">Выберите отдел</label>
                <select id="department" name="department">
                    <option selected disabled>Выберите отделение</option>
                    <?php
                        // Получаем список отделений
                        $stmt = $pdo->prepare("SELECT id, name FROM departments");
                        $stmt->execute();
                        $departments = $stmt->fetchAll();
                        foreach( $departments as $department ) {
                            echo "<option value='".$department['id']."'>".$department["name"]."</option>";
                        }
                    ?>
                </select>
            </div>
            <div>
                <label for="position">Введите должность</label>
                <input name="position" id="position" minlength="8" required>
            </div>
            <div>
                <label for="doc_phone_number">Введите номер телефона</label>
                <input type="tel" name="doc_phone_number" id="doc_phone_number" minlength="8" required>
            </div>
        </div>
        <button type="submit">Зарегистрироваться</button>
    </form>
    <a href="login.php">Уже есть аккаунт? Войти</a>
    <script>
        const select = document.getElementById("type")
        select.addEventListener("change", function (){
            var value = this.value;
            if(value == "doctor"){
                document.getElementById("doctor-form").style = "display:block;"
                document.getElementById("patient-form").style = "display:none;"
            }else{
                document.getElementById("patient-form").style = "display:block;"
                document.getElementById("doctor-form").style = "display:none;"
            }
        });
    </script>
</body>
</html>