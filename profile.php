<?php
define('IN_APP', true);
require_once 'utils/config.php';
require_once 'utils/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    secure_session_start();
}

if (!is_logged_in()) {
    header('Location: login.php');
    exit;
}

update_activity();

$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT type FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user_type = $stmt->fetchColumn();

if ($user_type === 'patient') {
    $stmt = $pdo->prepare("SELECT id FROM patients WHERE fk_user = ?");
    $stmt->execute([$user_id]);
    $patient_id = $stmt->fetchColumn();
    $doctor_id = null;
} else {
    $stmt = $pdo->prepare("SELECT id FROM doctors WHERE fk_user = ?");
    $stmt->execute([$user_id]);
    $doctor_id = $stmt->fetchColumn();
    $patient_id = null;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf($_POST['csrf'] ?? '')) {

    if ($_POST['action'] === 'logout') {
        logout();
        header('Location: login.php');
        exit;
    }

    if ($_POST['action'] === 'update_profile') {
        if ($user_type === 'patient') {
            $stmt = $pdo->prepare("UPDATE patients SET full_name = ?, birth_date = ?, home_address = ?, phone_number = ?, note = ? WHERE id = ?");
            $stmt->execute([$_POST['full_name'], $_POST['birth_date'], $_POST['home_address'], $_POST['phone_number'], $_POST['note'] ?? null, $patient_id]);
        } else {
            $stmt = $pdo->prepare("UPDATE doctors SET full_name = ?, position = ?, phone_number = ? WHERE id = ?");
            $stmt->execute([$_POST['full_name'], $_POST['position'], $_POST['phone_number'], $doctor_id]);
        }
        $success = 'Профиль обновлён';
    }

    if ($user_type === 'patient' && $_POST['action'] === 'book_appointment') {
        $doc_id = (int)$_POST['doctor_id'];
        $dt = $_POST['appointment_datetime'];
        if (strtotime($dt) <= time()) {
            $error = 'Нельзя записаться на прошедшее время';
        } else {
            $check = $pdo->prepare("SELECT id FROM appointments WHERE fk_doctor = ? AND appointment_datetime = ?");
            $check->execute([$doc_id, $dt]);
            if ($check->fetch()) {
                $error = 'Время уже занято';
            } else {
                $stmt = $pdo->prepare("INSERT INTO appointments (fk_patient, fk_doctor, appointment_datetime) VALUES (?, ?, ?)");
                $stmt->execute([$patient_id, $doc_id, $dt]);
                $success = 'Вы успешно записаны!';
            }
        }
    }

    if ($_POST['action'] === 'cancel_appointment') {
        $apt_id = (int)$_POST['apt_id'];
        $owner_id = $patient_id ?? $doctor_id;
        $role = $patient_id ? 'fk_patient' : 'fk_doctor';
        $stmt = $pdo->prepare("UPDATE appointments SET status = 'cancelled' WHERE id = ? AND $role = ?");
        $stmt->execute([$apt_id, $owner_id]);
        $success = 'Запись отменена';
    }

    if ($user_type === 'doctor' && $_POST['action'] === 'update_protocol') {
        $apt_id = (int)$_POST['apt_id'];
        $protocol = trim($_POST['protocol'] ?? '');
        $status = in_array($_POST['status'], ['scheduled','completed','no_show','cancelled']) ? $_POST['status'] : 'scheduled';
        $stmt = $pdo->prepare("UPDATE appointments SET protocol = ?, status = ? WHERE id = ? AND fk_doctor = ?");
        $stmt->execute([$protocol, $status, $apt_id, $doctor_id]);
        $success = 'Протокол сохранён';
    }

    if ($user_type === 'doctor' && isset($_POST['patient_id'])) {
        $pid = (int)$_POST['patient_id'];

        if ($_POST['action'] === 'add_diagnosis') {
            $diag = trim($_POST['diag_name'] ?? '');
            if ($diag !== '') {
                $stmt = $pdo->prepare("INSERT INTO diagnoses (fk_patient, fk_doctor, name, date_create) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$pid, $doctor_id, $diag]);
                $success = 'Диагноз добавлен';
            }
        }

        if ($_POST['action'] === 'add_recipe') {
            $name = trim($_POST['recipe_name'] ?? '');
            $dosage = (int)($_POST['dosage'] ?? 0);
            $cost = (int)($_POST['cost'] ?? 0);
            $expire = $_POST['date_expire'] ?? '';
            if ($name && $dosage > 0 && $cost > 0 && $expire) {
                $stmt = $pdo->prepare("INSERT INTO recipes (fk_patient, fk_doctor, name, dosage, cost, date_create, date_expire) VALUES (?, ?, ?, ?, ?, NOW(), ?)");
                $stmt->execute([$pid, $doctor_id, $name, $dosage, $cost, $expire]);
                $success = 'Рецепт выписан';
            }
        }
    }

    $redirect = 'profile.php';
    if (isset($_GET['view_patient'])) {
        $redirect .= '?view_patient=' . (int)$_GET['view_patient'];
    }
    header("Location: $redirect");
    exit;
}

if ($user_type === 'patient') {
    $profile = $pdo->query("SELECT p.*, u.email FROM patients p JOIN users u ON p.fk_user = u.id WHERE p.id = $patient_id")->fetch();
} else {
    $profile = $pdo->query("SELECT d.*, dep.name AS dep_name, u.email FROM doctors d LEFT JOIN departments dep ON d.fk_department = dep.id JOIN users u ON d.fk_user = u.id WHERE d.id = $doctor_id")->fetch();
}

function get_diagnoses($pdo, $pid) {
    $stmt = $pdo->prepare("SELECT d.name AS diag_name, d.date_create, doc.full_name AS doctor_name, dep.name AS dep_name FROM diagnoses d JOIN doctors doc ON d.fk_doctor = doc.id LEFT JOIN departments dep ON doc.fk_department = dep.id WHERE d.fk_patient = ? ORDER BY d.date_create DESC");
    $stmt->execute([$pid]);
    return $stmt->fetchAll();
}

function get_recipes($pdo, $pid) {
    $stmt = $pdo->prepare("SELECT r.*, doc.full_name AS doctor_name FROM recipes r JOIN doctors doc ON r.fk_doctor = doc.id WHERE r.fk_patient = ? ORDER BY r.date_create DESC");
    $stmt->execute([$pid]);
    return $stmt->fetchAll();
}

$diagnoses_list = $patient_id ? get_diagnoses($pdo, $patient_id) : [];
$recipes_list = $patient_id ? get_recipes($pdo, $patient_id) : [];

$patients_list = $user_type === 'doctor' ? $pdo->query("SELECT id, full_name, phone_number, birth_date FROM patients ORDER BY full_name")->fetchAll() : [];

$view_patient = isset($_GET['view_patient']) ? (int)$_GET['view_patient'] : 0;
$current_patient = null;
$patient_appointments = [];
$patient_diagnoses = [];
$patient_recipes = [];

if ($view_patient > 0 && $user_type === 'doctor') {
    $stmt = $pdo->prepare("SELECT p.*, u.email FROM patients p JOIN users u ON p.fk_user = u.id WHERE p.id = ?");
    $stmt->execute([$view_patient]);
    $current_patient = $stmt->fetch();

    if ($current_patient) {
        $stmt = $pdo->prepare("SELECT a.*, doc.full_name AS doctor_name, dep.name AS dep_name FROM appointments a JOIN doctors doc ON a.fk_doctor = doc.id LEFT JOIN departments dep ON doc.fk_department = dep.id WHERE a.fk_patient = ? ORDER BY a.appointment_datetime DESC");
        $stmt->execute([$view_patient]);
        $patient_appointments = $stmt->fetchAll();

        $patient_diagnoses = get_diagnoses($pdo, $view_patient);
        $patient_recipes = get_recipes($pdo, $view_patient);
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Личный кабинет — МедЦентр</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f5f7fa; padding-top: 80px; }
        .navbar { box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .card { border-radius: 15px; margin-bottom: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
        .table thead { background: #007cba; color: white; }
        .protocol-text { white-space: pre-line; background: #f8f9fa; padding: 15px; border-radius: 8px; border: 1px solid #dee2e6; }
        .status-scheduled { background: #fff3cd; }
        .status-completed { background: #d4edda; }
        .status-cancelled, .status-no_show { background: #f8d7da; }
        h2 { color: #007cba; padding-bottom: 8px; border-bottom: 3px solid #007cba; margin: 30px 0 20px; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-primary fixed-top">
    <div class="container-fluid">
        <a class="navbar-brand" href="profile.php"><i class="fas fa-clinic-medical"></i> МедЦентр</a>
        <div class="d-flex align-items-center">
            <span class="navbar-text text-white me-4">
                <?= htmlspecialchars($_SESSION['user_email']) ?>
                <span class="badge bg-light text-dark ms-2"><?= $user_type === 'patient' ? 'Пациент' : 'Врач' ?></span>
            </span>
            <form method="post" class="d-inline">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="logout">
                <button class="btn btn-outline-light btn-sm"><i class="fas fa-sign-out-alt"></i> Выйти</button>
            </form>
        </div>
    </div>
</nav>

<div class="container">
    <?php if ($error): ?>
        <div class="alert alert-danger mt-3"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success mt-3"><?= $success ?></div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5><i class="fas fa-user"></i> Профиль</h5>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editProfileModal"><i class="fas fa-edit"></i> Редактировать</button>
        </div>
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-4">ФИО</dt>
                <dd class="col-sm-8"><?= htmlspecialchars($profile['full_name']) ?></dd>
                <dt class="col-sm-4">Email</dt>
                <dd class="col-sm-8"><?= htmlspecialchars($profile['email']) ?></dd>
                <dt class="col-sm-4">Телефон</dt>
                <dd class="col-sm-8"><?= htmlspecialchars($profile['phone_number']) ?></dd>
                <?php if ($user_type === 'patient'): ?>
                    <dt class="col-sm-4">Дата рождения</dt>
                    <dd class="col-sm-8"><?= $profile['birth_date'] ?></dd>
                    <dt class="col-sm-4">Адрес</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($profile['home_address']) ?></dd>
                    <?php if ($profile['note']): ?>
                        <dt class="col-sm-4">Примечание</dt>
                        <dd class="col-sm-8"><?= htmlspecialchars($profile['note']) ?></dd>
                    <?php endif; ?>
                <?php else: ?>
                    <dt class="col-sm-4">Должность</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($profile['position']) ?></dd>
                    <dt class="col-sm-4">Отделение</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($profile['dep_name'] ?? '—') ?></dd>
                <?php endif; ?>
            </dl>
        </div>
    </div>

    <?php if ($user_type === 'patient'): ?>

        <h2><i class="fas fa-calendar-plus"></i> Записаться на приём</h2>
        <div class="card mb-4">
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <input type="hidden" name="action" value="book_appointment">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <select class="form-select" name="doctor_id" required>
                                <option value="">Выберите врача</option>
                                <?php
                                $doctors = $pdo->query("SELECT d.id, d.full_name, d.position, dep.name AS dep FROM doctors d LEFT JOIN departments dep ON d.fk_department = dep.id")->fetchAll();
                                foreach ($doctors as $d) {
                                    echo '<option value="'.$d['id'].'">'.htmlspecialchars($d['full_name']).' — '.$d['position'].' ('.$d['dep'].')</option>';
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <input type="datetime-local" class="form-control" name="appointment_datetime" min="<?= date('Y-m-d\TH:i') ?>" required>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button class="btn btn-success w-100">Записаться</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <h2><i class="fas fa-calendar-check"></i> Мои приёмы</h2>
        <?php
        $stmt = $pdo->prepare("SELECT a.*, d.full_name AS doctor_name, dep.name AS dep_name FROM appointments a JOIN doctors d ON a.fk_doctor = d.id LEFT JOIN departments dep ON d.fk_department = dep.id WHERE a.fk_patient = ? ORDER BY a.appointment_datetime DESC");
        $stmt->execute([$patient_id]);
        $appointments = $stmt->fetchAll();
        ?>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr><th>Врач</th><th>Дата и время</th><th>Статус</th><th>Протокол</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($appointments as $a): ?>
                        <tr class="status-<?= $a['status'] ?>">
                            <td><?= htmlspecialchars($a['doctor_name']) ?> (<?= $a['dep_name'] ?? '—' ?>)</td>
                            <td><?= $a['appointment_datetime'] ?></td>
                            <td>
                                <span class="badge bg-<?= $a['status'] === 'scheduled' ? 'warning' : ($a['status'] === 'completed' ? 'success' : 'danger') ?>">
                                    <?= $a['status'] === 'scheduled' ? 'Запланировано' : ($a['status'] === 'completed' ? 'Проведён' : ($a['status'] === 'cancelled' ? 'Отменён' : 'Не явился')) ?>
                                </span>
                            </td>
                            <td><?= $a['protocol'] ? '<div class="protocol-text">'.nl2br(htmlspecialchars($a['protocol'])).'</div>' : '—' ?></td>
                            <td>
                                <?php if ($a['status'] === 'scheduled'): ?>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="cancel_appointment">
                                        <input type="hidden" name="apt_id" value="<?= $a['id'] ?>">
                                        <button class="btn btn-danger btn-sm">Отменить</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <h2><i class="fas fa-notes-medical"></i> Мои диагнозы</h2>
        <?php if (empty($diagnoses_list)): ?>
            <div class="alert alert-info">Диагнозов пока нет</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead><tr><th>Дата</th><th>Диагноз</th><th>Врач</th><th>Отделение</th></tr></thead>
                    <tbody>
                        <?php foreach ($diagnoses_list as $d): ?>
                            <tr>
                                <td><?= $d['date_create'] ?></td>
                                <td><?= htmlspecialchars($d['diag_name']) ?></td>
                                <td><?= htmlspecialchars($d['doctor_name']) ?></td>
                                <td><?= $d['dep_name'] ?: '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <h2><i class="fas fa-prescription-bottle-alt"></i> Мои рецепты</h2>
        <?php if (empty($recipes_list)): ?>
            <div class="alert alert-info">Рецептов пока нет</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead><tr><th>Лекарство</th><th>Дозировка</th><th>Стоимость</th><th>Выписан</th><th>До</th><th>Врач</th></tr></thead>
                    <tbody>
                        <?php foreach ($recipes_list as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars($r['name']) ?></td>
                                <td><?= $r['dosage'] ?></td>
                                <td><?= $r['cost'] ?> ₽</td>
                                <td><?= $r['date_create'] ?></td>
                                <td><?= $r['date_expire'] ?></td>
                                <td><?= htmlspecialchars($r['doctor_name']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    <?php else: // Врач ?>

        <h2><i class="fas fa-calendar-check"></i> Мои приёмы</h2>
        <?php
        $stmt = $pdo->prepare("SELECT a.*, p.full_name AS patient_name FROM appointments a JOIN patients p ON a.fk_patient = p.id WHERE a.fk_doctor = ? ORDER BY a.appointment_datetime");
        $stmt->execute([$doctor_id]);
        $appointments = $stmt->fetchAll();
        ?>
        <div class="table-responsive mb-5">
            <table class="table table-hover">
                <thead>
                    <tr><th>Пациент</th><th>Дата и время</th><th>Статус</th><th>Протокол</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($appointments as $a): ?>
                        <tr class="status-<?= $a['status'] ?>">
                            <td><a href="?view_patient=<?= $a['fk_patient'] ?>"><?= htmlspecialchars($a['patient_name']) ?></a></td>
                            <td><?= $a['appointment_datetime'] ?></td>
                            <td>
                                <form method="post">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="update_protocol">
                                    <input type="hidden" name="apt_id" value="<?= $a['id'] ?>">
                                    <select name="status" class="form-select form-select-sm d-inline w-auto">
                                        <option value="scheduled" <?= $a['status'] == 'scheduled' ? 'selected' : '' ?>>Запланировано</option>
                                        <option value="completed" <?= $a['status'] == 'completed' ? 'selected' : '' ?>>Проведён</option>
                                        <option value="no_show" <?= $a['status'] == 'no_show' ? 'selected' : '' ?>>Не явился</option>
                                        <option value="cancelled" <?= $a['status'] == 'cancelled' ? 'selected' : '' ?>>Отменён</option>
                                    </select>
                                    <textarea name="protocol" class="form-control mt-2" rows="3" placeholder="Протокол осмотра..."><?= htmlspecialchars($a['protocol'] ?? '') ?></textarea>
                                    <button class="btn btn-primary btn-sm mt-2">Сохранить</button>
                                </form>
                            </td>
                            <td>
                                <?php if ($a['status'] === 'scheduled'): ?>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="cancel_appointment">
                                        <input type="hidden" name="apt_id" value="<?= $a['id'] ?>">
                                        <button class="btn btn-danger btn-sm">Отменить</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <h2><i class="fas fa-users"></i> Пациенты</h2>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead><tr><th>ФИО</th><th>Телефон</th><th>Дата рождения</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($patients_list as $p): ?>
                        <tr>
                            <td><?= htmlspecialchars($p['full_name']) ?></td>
                            <td><?= $p['phone_number'] ?></td>
                            <td><?= $p['birth_date'] ?></td>
                            <td><a class="btn btn-info btn-sm" href="?view_patient=<?= $p['id'] ?>"><i class="fas fa-folder-open"></i> Карта</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($current_patient): ?>
            <div class="card mt-5">
                <div class="card-header bg-info text-white">
                    <h4><i class="fas fa-folder-open"></i> Карта пациента: <?= htmlspecialchars($current_patient['full_name']) ?></h4>
                </div>
                <div class="card-body">

                    <h5 class="mt-4"><i class="fas fa-calendar-check"></i> История приёмов</h5>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead><tr><th>Дата</th><th>Врач</th><th>Статус</th><th>Протокол</th></tr></thead>
                            <tbody>
                                <?php foreach ($patient_appointments as $a): ?>
                                    <tr class="status-<?= $a['status'] ?>">
                                        <td><?= $a['appointment_datetime'] ?></td>
                                        <td><?= htmlspecialchars($a['doctor_name']) ?></td>
                                        <td><span class="badge bg-<?= $a['status'] === 'completed' ? 'success' : 'warning' ?>"><?= $a['status'] === 'completed' ? 'Проведён' : 'Запланирован' ?></span></td>
                                        <td><?= $a['protocol'] ? '<div class="protocol-text">'.nl2br(htmlspecialchars($a['protocol'])).'</div>' : '—' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <h5 class="mt-4"><i class="fas fa-notes-medical"></i> Диагнозы</h5>
                    <?php if (empty($patient_diagnoses)): ?>
                        <div class="alert alert-info">Нет диагнозов</div>
                    <?php else: ?>
                        <table class="table table-hover">
                            <thead><tr><th>Дата</th><th>Диагноз</th><th>Врач</th></tr></thead>
                            <tbody>
                                <?php foreach ($patient_diagnoses as $d): ?>
                                    <tr>
                                        <td><?= $d['date_create'] ?></td>
                                        <td><?= htmlspecialchars($d['diag_name']) ?></td>
                                        <td><?= htmlspecialchars($d['doctor_name']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <form method="post" class="mt-3">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <input type="hidden" name="action" value="add_diagnosis">
                        <input type="hidden" name="patient_id" value="<?= $view_patient ?>">
                        <div class="input-group">
                            <input type="text" class="form-control" name="diag_name" placeholder="Новый диагноз / код МКБ-10" required>
                            <button class="btn btn-success">Добавить</button>
                        </div>
                    </form>

                    <h5 class="mt-5"><i class="fas fa-prescription-bottle-alt"></i> Рецепты</h5>
                    <?php if (empty($patient_recipes)): ?>
                        <div class="alert alert-info">Нет рецептов</div>
                    <?php else: ?>
                        <table class="table table-hover">
                            <thead><tr><th>Лекарство</th><th>Дозировка</th><th>Стоимость</th><th>Выписан</th><th>До</th><th>Врач</th></tr></thead>
                            <tbody>
                                <?php foreach ($patient_recipes as $r): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($r['name']) ?></td>
                                        <td><?= $r['dosage'] ?></td>
                                        <td><?= $r['cost'] ?> ₽</td>
                                        <td><?= $r['date_create'] ?></td>
                                        <td><?= $r['date_expire'] ?></td>
                                        <td><?= htmlspecialchars($r['doctor_name']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <form method="post" class="mt-3">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <input type="hidden" name="action" value="add_recipe">
                        <input type="hidden" name="patient_id" value="<?= $view_patient ?>">
                        <div class="row g-3">
                            <div class="col-md-5"><input type="text" class="form-control" name="recipe_name" placeholder="Лекарство" required></div>
                            <div class="col-md-2"><input type="number" class="form-control" name="dosage" placeholder="Дозировка" required></div>
                            <div class="col-md-2"><input type="number" class="form-control" name="cost" placeholder="Стоимость ₽" required></div>
                            <div class="col-md-2"><input type="date" class="form-control" name="date_expire" required></div>
                            <div class="col-md-1 d-flex align-items-center">
                                <button class="btn btn-success w-100"><i class="fas fa-plus"></i></button>
                            </div>
                        </div>
                    </form>

                    <div class="mt-4">
                        <a href="profile.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Назад</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    <?php endif; ?>

    <div class="modal fade" id="editProfileModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="post">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="update_profile">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Редактирование профиля</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">ФИО</label>
                            <input type="text" class="form-control" name="full_name" value="<?= htmlspecialchars($profile['full_name']) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Телефон</label>
                            <input type="tel" class="form-control" name="phone_number" value="<?= htmlspecialchars($profile['phone_number']) ?>" required>
                        </div>
                        <?php if ($user_type === 'patient'): ?>
                            <div class="mb-3">
                                <label class="form-label">Дата рождения</label>
                                <input type="date" class="form-control" name="birth_date" value="<?= $profile['birth_date'] ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Адрес</label>
                                <input type="text" class="form-control" name="home_address" value="<?= htmlspecialchars($profile['home_address']) ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Примечание</label>
                                <textarea class="form-control" name="note"><?= htmlspecialchars($profile['note'] ?? '') ?></textarea>
                            </div>
                        <?php else: ?>
                            <div class="mb-3">
                                <label class="form-label">Должность</label>
                                <input type="text" class="form-control" name="position" value="<?= htmlspecialchars($profile['position']) ?>" required>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" class="btn btn-primary">Сохранить</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>