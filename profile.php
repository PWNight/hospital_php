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

global $pdo;
$user_id = $_SESSION['user_id'];

// 1. ПОЛУЧЕНИЕ ТИПА И ID ПРОФИЛЯ
$stmt = $pdo->prepare("SELECT type FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user_type = $stmt->fetchColumn();

$patient_id = null;
$doctor_id = null;

if ($user_type === 'patient') {
    $stmt = $pdo->prepare("SELECT id FROM patients WHERE fk_user = ?");
    $stmt->execute([$user_id]);
    $patient_id = $stmt->fetchColumn();
} else {
    $stmt = $pdo->prepare("SELECT id FROM doctors WHERE fk_user = ?");
    $stmt->execute([$user_id]);
    $doctor_id = $stmt->fetchColumn();
}

$error = '';
$success = '';

// --- ФУНКЦИИ ДЛЯ ПОЛУЧЕНИЯ ДАННЫХ (ИСПРАВЛЕНО: SQL-ОШИБКИ) ---

function get_diagnoses($pdo, $pid) {
    // ИСПРАВЛЕНО: Используем 'name' для диагноза и 'date_create' для даты, т.к. 'note' и 'date' отсутствуют в SQL.
    $stmt = $pdo->prepare("SELECT d.name AS diag_name, d.date_create AS date, doc.full_name AS doctor_name, dep.name AS dep_name 
                           FROM diagnoses d 
                           JOIN doctors doc ON d.fk_doctor = doc.id 
                           LEFT JOIN departments dep ON doc.fk_department = dep.id 
                           WHERE d.fk_patient = ? 
                           ORDER BY d.date_create DESC");
    $stmt->execute([$pid]);
    return $stmt->fetchAll();
}

function get_recipes($pdo, $pid) {
    // ИСПРАВЛЕНО: Используем 'name' для медикамента, 'date_create' для даты выписки, и 'cost', т.к. 'instructions' и 'medication_name' отсутствуют в SQL.
    $stmt = $pdo->prepare("SELECT r.name AS medication_name, r.dosage, r.cost, r.date_create AS date_prescribed, doc.full_name AS doctor_name 
                           FROM recipes r 
                           JOIN doctors doc ON r.fk_doctor = doc.id 
                           WHERE r.fk_patient = ? 
                           ORDER BY r.date_create DESC");
    $stmt->execute([$pid]);
    return $stmt->fetchAll();
}


// --- ЛОГИКА ОБРАБОТКИ ФОРМ ---

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf($_POST['csrf'] ?? '')) {

    if (($_POST['action'] ?? '') === 'logout') {
        logout();
        header('Location: login.php');
        exit;
    }

    if (($_POST['action'] ?? '') === 'update_profile') {
        // Защищенное обновление профиля
        if ($user_type === 'patient') {
            $stmt = $pdo->prepare("UPDATE patients SET full_name = ?, birth_date = ?, home_address = ?, phone_number = ?, note = ? WHERE id = ?");
            $stmt->execute([$_POST['full_name'], $_POST['birth_date'], $_POST['home_address'], $_POST['phone_number'], $_POST['note'] ?? null, $patient_id]);
        } else {
            $stmt = $pdo->prepare("UPDATE doctors SET full_name = ?, position = ?, phone_number = ? WHERE id = ?");
            $stmt->execute([$_POST['full_name'], $_POST['position'], $_POST['phone_number'], $doctor_id]);
        }
        $success = 'Профиль обновлён';
    }

    if ($user_type === 'patient' && ($_POST['action'] ?? '') === 'book_appointment') {
        // Логика записи на прием (ВОССТАНОВЛЕНА)
        $doc_id = filter_var($_POST['doctor_id'] ?? '', FILTER_VALIDATE_INT);
        $dt = trim($_POST['appointment_datetime'] ?? '');
        
        if (!$doc_id || empty($dt)) {
            $error = 'Необходимо выбрать доктора, дату и время.';
        } elseif (strtotime($dt) <= time()) {
            $error = 'Нельзя записаться на прошедшее время.';
        } else {
            // Проверка на занятость слота
            $check = $pdo->prepare("SELECT id FROM appointments WHERE fk_doctor = ? AND appointment_datetime = ? AND status != 'cancelled'");
            $check->execute([$doc_id, $dt]);
            if ($check->fetch()) {
                $error = 'Время уже занято.';
            } else {
                // Вставка записи
                $stmt = $pdo->prepare("INSERT INTO appointments (fk_patient, fk_doctor, appointment_datetime, status) VALUES (?, ?, ?, 'scheduled')");
                $stmt->execute([$patient_id, $doc_id, $dt]);
                $success = 'Вы успешно записаны!';
            }
        }
    }

    if (($_POST['action'] ?? '') === 'cancel_appointment') {
        // Отмена приема
        $apt_id = filter_var($_POST['apt_id'] ?? '', FILTER_VALIDATE_INT);
        $owner_id = $patient_id ?? $doctor_id;
        $role = $patient_id ? 'fk_patient' : 'fk_doctor';
        
        $stmt = $pdo->prepare("UPDATE appointments SET status = 'cancelled' WHERE id = ? AND $role = ?");
        $stmt->execute([$apt_id, $owner_id]);
        $success = 'Запись отменена';
    }

    if ($user_type === 'doctor' && ($_POST['action'] ?? '') === 'update_protocol') {
        // Обновление протокола и статуса (Доктор)
        $apt_id = filter_var($_POST['apt_id'] ?? '', FILTER_VALIDATE_INT);
        $protocol = trim($_POST['protocol'] ?? '');
        $status = in_array($_POST['status'], ['scheduled','completed','no_show','cancelled']) ? $_POST['status'] : 'scheduled';
        
        $stmt = $pdo->prepare("UPDATE appointments SET protocol = ?, status = ? WHERE id = ? AND fk_doctor = ?");
        $stmt->execute([$protocol, $status, $apt_id, $doctor_id]);
        $success = 'Протокол сохранён';
    }

    if ($user_type === 'doctor' && isset($_POST['patient_id'])) {
        $pid = filter_var($_POST['patient_id'] ?? '', FILTER_VALIDATE_INT);

        if (($_POST['action'] ?? '') === 'add_diagnosis') {
            // Добавление диагноза (ИСПРАВЛЕНО: убран 'note', используется 'date_create')
            $diag_name = trim($_POST['diag_name'] ?? '');
            if ($diag_name !== '') {
                $stmt = $pdo->prepare("INSERT INTO diagnoses (fk_patient, fk_doctor, name, date_create) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$pid, $doctor_id, $diag_name]);
                $success = 'Диагноз добавлен';
            }
        }

        if (($_POST['action'] ?? '') === 'add_recipe') {
            // Выписка рецепта (ИСПРАВЛЕНО: убраны 'instructions', 'date_prescribed', добавлены 'cost' и 'date_expire')
            $medication_name = trim($_POST['medication_name'] ?? '');
            $dosage = filter_var($_POST['dosage'] ?? 0, FILTER_VALIDATE_INT);
            // Используем 'cost' и 'date_expire' согласно SQL структуре
            $cost = filter_var($_POST['cost'] ?? 0, FILTER_VALIDATE_INT); 
            $date_expire = date('Y-m-d H:i:s', strtotime('+30 days')); // Устанавливаем срок действия по умолчанию (30 дней)
            
            if ($medication_name && $dosage > 0) {
                $stmt = $pdo->prepare("INSERT INTO recipes (fk_patient, fk_doctor, name, dosage, cost, date_create, date_expire) VALUES (?, ?, ?, ?, ?, NOW(), ?)");
                $stmt->execute([$pid, $doctor_id, $medication_name, $dosage, $cost, $date_expire]);
                $success = 'Рецепт выписан';
            } else {
                $error = "Название медикамента и дозировка обязательны.";
            }
        }
    }

    $redirect = 'profile.php';
    if (isset($_GET['view_patient'])) {
        $redirect .= '?view_patient=' . filter_var($_GET['view_patient'], FILTER_VALIDATE_INT);
    }
    header("Location: $redirect");
    exit;
}

// --- ФЕТЧИНГ ОСНОВНЫХ ДАННЫХ ПРОФИЛЯ ---
$profile = [];
if ($user_type === 'patient') {
    $stmt = $pdo->prepare("SELECT p.*, u.email FROM patients p JOIN users u ON p.fk_user = u.id WHERE p.id = ?");
    $stmt->execute([$patient_id]);
    $profile = $stmt->fetch();
} else {
    $stmt = $pdo->prepare("SELECT d.*, dep.name AS dep_name, u.email FROM doctors d LEFT JOIN departments dep ON d.fk_department = dep.id JOIN users u ON d.fk_user = u.id WHERE d.id = ?");
    $stmt->execute([$doctor_id]);
    $profile = $stmt->fetch();
}

$user_full_name = htmlspecialchars($profile['full_name'] ?? 'Пользователь');

// --- ФЕТЧИНГ СПИСКА ДОКТОРОВ ДЛЯ ЗАПИСИ (ПАЦИЕНТ) ---
$doctors_list = [];
if ($user_type === 'patient') {
    $stmt = $pdo->prepare("SELECT d.id, d.full_name, d.position, dep.name AS dep_name 
                           FROM doctors d 
                           JOIN departments dep ON d.fk_department = dep.id 
                           ORDER BY d.full_name");
    $stmt->execute();
    $doctors_list = $stmt->fetchAll();
}

// --- ФЕТЧИНГ СПИСКА ПАЦИЕНТОВ (ДОКТОР) ---
$patients_list = [];
if ($user_type === 'doctor') {
    $stmt = $pdo->prepare("SELECT id, full_name, phone_number, birth_date FROM patients ORDER BY full_name");
    $stmt->execute();
    $patients_list = $stmt->fetchAll();
}

// --- ФЕТЧИНГ ПРИЕМОВ ДЛЯ ПАЦИЕНТА
$appointments = [];
if ($user_type === 'patient' && $patient_id) {
    $stmt = $pdo->prepare("SELECT a.*, d.full_name AS doctor_name, dep.name AS dep_name FROM appointments a JOIN doctors d ON a.fk_doctor = d.id LEFT JOIN departments dep ON d.fk_department = dep.id WHERE a.fk_patient = ? ORDER BY a.appointment_datetime DESC");
    $stmt->execute([$patient_id]);
    $appointments = $stmt->fetchAll();
}

// --- ФЕТЧИНГ ИСТОРИИ ДЛЯ ПАЦИЕНТА
$diagnoses_list = $patient_id ? get_diagnoses($pdo, $patient_id) : [];
$recipes_list = $patient_id ? get_recipes($pdo, $patient_id) : [];


// --- ДОКТОР: ПРОСМОТР КАРТЫ ПАЦИЕНТА ---
$view_patient = isset($_GET['view_patient']) ? filter_var($_GET['view_patient'], FILTER_VALIDATE_INT) : 0;
$current_patient = null;
$patient_appointments = [];
$patient_diagnoses = [];
$patient_recipes = [];

if ($view_patient > 0 && $user_type === 'doctor') {
    $stmt = $pdo->prepare("SELECT p.*, u.email FROM patients p JOIN users u ON p.fk_user = u.id WHERE p.id = ?");
    $stmt->execute([$view_patient]);
    $current_patient = $stmt->fetch();

    if ($current_patient) {
        $stmt = $pdo->prepare("SELECT a.*, doc.full_name AS doctor_name, dep.name AS dep_name 
                               FROM appointments a 
                               JOIN doctors doc ON a.fk_doctor = doc.id 
                               LEFT JOIN departments dep ON doc.fk_department = dep.id 
                               WHERE a.fk_patient = ? 
                               ORDER BY a.appointment_datetime DESC");
        $stmt->execute([$view_patient]);
        $patient_appointments = $stmt->fetchAll();

        $patient_diagnoses = get_diagnoses($pdo, $view_patient);
        $patient_recipes = get_recipes($pdo, $view_patient);
    }
}


// --- ФЕТЧИНГ СТАТИСТИКИ ---
$stats = [];
if ($user_type === 'patient' && $patient_id) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE fk_patient = ?");
    $stmt->execute([$patient_id]);
    $stats['total_appointments'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE fk_patient = ? AND status = 'completed'");
    $stmt->execute([$patient_id]);
    $stats['completed_appointments'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM diagnoses WHERE fk_patient = ?");
    $stmt->execute([$patient_id]);
    $stats['total_diagnoses'] = (int)$stmt->fetchColumn();

    $stats['completion_rate'] = $stats['total_appointments'] > 0 ? round(($stats['completed_appointments'] / $stats['total_appointments']) * 100) : 0;

} elseif ($user_type === 'doctor' && $doctor_id) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE fk_doctor = ?");
    $stmt->execute([$doctor_id]);
    $stats['total_appointments'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE fk_doctor = ? AND status = 'completed'");
    $stmt->execute([$doctor_id]);
    $stats['completed_appointments'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT fk_patient) FROM appointments WHERE fk_doctor = ? AND status = 'completed'");
    $stmt->execute([$doctor_id]);
    $stats['unique_patients'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM diagnoses WHERE fk_doctor = ?");
    $stmt->execute([$doctor_id]);
    $stats['total_diagnoses_issued'] = (int)$stmt->fetchColumn();

    $stats['completion_rate'] = $stats['total_appointments'] > 0 ? round(($stats['completed_appointments'] / $stats['total_appointments']) * 100) : 0;
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Личный кабинет — МедЦентр "Надежда"</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <style>
        /* Минималистичный белый/синий дизайн */
        :root {
            --bs-primary: #007BFF;
            --bs-primary-rgb: 0,123,255;
        }
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding-top: 70px; }
        .navbar { background-color: #ffffff; border-bottom: 1px solid #e9ecef; box-shadow: 0 2px 4px rgba(0,0,0,.05); }
        .navbar-brand { color: var(--bs-primary) !important; font-weight: bold; }
        .btn-primary { background-color: var(--bs-primary); border-color: var(--bs-primary); }
        .btn-primary:hover { background-color: #0056b3; border-color: #0056b3; }
        .btn-outline-primary { color: var(--bs-primary); border-color: var(--bs-primary); }
        .btn-outline-primary:hover { background-color: var(--bs-primary); color: white; }
        .text-accent { color: var(--bs-primary); }
        .border-accent { border-color: var(--bs-primary) !important; }
        
        .card { border: 1px solid #e9ecef; box-shadow: 0 4px 12px rgba(0,0,0,.05); }
        .card-header { background-color: #f8f9fa; color: var(--bs-primary); font-weight: bold; border-bottom: 1px solid #e9ecef; }
        
        /* Статусы приемов */
        .status-scheduled { background-color: #cfe2ff; color: #084298; } /* Light Blue */
        .status-completed { background-color: #d1e7dd; color: #0f5132; } /* Light Green */
        .status-cancelled, .status-no_show { background-color: #f8d7da; color: #842029; } /* Light Red */
        
        .protocol-text { 
            white-space: pre-line; 
            background-color: #f8f9fa; 
            padding: 10px; 
            border-left: 4px solid var(--bs-primary); 
            border-radius: 6px;
        }
        .modal { z-index: 1051; }
        
        /* Навигация в Карте Пациента */
        .nav-link.active-tab {
            color: var(--bs-primary);
            border-bottom: 3px solid var(--bs-primary);
            font-weight: 600;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-light fixed-top">
    <div class="container">
        <a class="navbar-brand fw-bold" href="index.php">МедЦентр "Надежда"</a>
        <div class="d-flex align-items-center">
            <div class="text-end me-3">
                <div class="font-semibold"><?= htmlspecialchars($user_full_name) ?></div>
                <span class="badge bg-primary"><?= $user_type === 'patient' ? 'Пациент' : 'Врач' ?></span>
            </div>
            <form method="post" class="d-inline">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="logout">
                <button class="btn btn-danger" type="submit" title="Выйти">
                    <i class="fas fa-sign-out-alt"></i>
                </button>
            </form>
        </div>
    </div>
</nav>

<main class="container py-4">
    <?php if ($error): ?>
        <div class="alert alert-danger" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success" role="alert">
            <i class="fas fa-check-circle me-2"></i> <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <h2 class="text-2xl font-semibold text-accent pb-1 mb-4 border-bottom border-accent">
        <i class="fas fa-id-card-alt me-2"></i> Личный кабинет
    </h2>
    
    <?php if ($view_patient && $current_patient && $user_type === 'doctor'): ?>
        <h3 class="text-2xl text-accent"><i class="fas fa-notes-medical me-2"></i> Карта пациента: <?= htmlspecialchars($current_patient['full_name']) ?></h3>
        <p class="text-muted">Email: <?= htmlspecialchars($current_patient['email']) ?>. Дата рождения: <?= htmlspecialchars($current_patient['birth_date']) ?></p>
        
        <div class="mb-4">
            <a href="profile.php" class="btn btn-outline-secondary btn-sm mb-3">
                <i class="fas fa-arrow-left me-1"></i> Назад к списку пациентов
            </a>
            
            <div x-data="{ activeTab: 'appointments' }">
                <ul class="nav nav-tabs mb-3" id="patientTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="appointments-tab" data-bs-toggle="tab" data-bs-target="#appointments" type="button" role="tab" aria-controls="appointments" aria-selected="true">
                            <i class="fas fa-history me-1"></i> Приёмы
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="diagnoses-tab" data-bs-toggle="tab" data-bs-target="#diagnoses" type="button" role="tab" aria-controls="diagnoses" aria-selected="false">
                            <i class="fas fa-book-medical me-1"></i> Диагнозы
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="recipes-tab" data-bs-toggle="tab" data-bs-target="#recipes" type="button" role="tab" aria-controls="recipes" aria-selected="false">
                            <i class="fas fa-pills me-1"></i> Рецепты
                        </button>
                    </li>
                </ul>
                <div class="tab-content" id="patientTabsContent">
                    
                    <div class="tab-pane fade show active" id="appointments" role="tabpanel" aria-labelledby="appointments-tab">
                        <div class="card">
                            <div class="card-body p-0">
                                <?php if (empty($patient_appointments)): ?>
                                    <div class="p-4 alert alert-info mb-0"><i class="fas fa-info-circle me-2"></i> У пациента нет приёмов.</div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover mb-0">
                                            <thead class="bg-light">
                                                <tr>
                                                    <th class="py-3 px-3">Врач</th>
                                                    <th class="py-3 px-3">Дата и время</th>
                                                    <th class="py-3 px-3" style="width: 50%;">Протокол / Статус</th>
                                                    <th class="py-3 px-3">Действия</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($patient_appointments as $a): ?>
                                                    <tr class="<?= str_replace(['scheduled', 'completed', 'cancelled', 'no_show'], ['table-primary', 'table-success', 'table-danger', 'table-warning'], 'status-' . $a['status']) ?>">
                                                        <td class="px-3 py-3">
                                                            <?= htmlspecialchars($a['doctor_name']) ?>
                                                            <div class="text-muted small"><?= $a['dep_name'] ?? '—' ?></div>
                                                        </td>
                                                        <td class="px-3 py-3"><?= date('d.m.Y H:i', strtotime($a['appointment_datetime'])) ?></td>
                                                        <td class="px-3 py-3">
                                                            <span class="badge bg-secondary"><?= htmlspecialchars(ucfirst($a['status'])) ?></span>
                                                            <?php if ($a['protocol']): ?>
                                                                <div class="protocol-text mt-2 small"><?= htmlspecialchars($a['protocol']) ?></div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="px-3 py-3">
                                                             <?php if ($a['fk_doctor'] == $doctor_id && $a['status'] !== 'cancelled'): ?>
                                                                <button class="btn btn-sm btn-info" 
                                                                    data-bs-toggle="modal" data-bs-target="#editProtocolModal"
                                                                    data-apt-id="<?= $a['id'] ?>" 
                                                                    data-protocol="<?= htmlspecialchars($a['protocol'] ?? '') ?>"
                                                                    data-status="<?= $a['status'] ?>">
                                                                    <i class="fas fa-file-signature"></i> Протокол
                                                                </button>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="tab-pane fade" id="diagnoses" role="tabpanel" aria-labelledby="diagnoses-tab">
                        <div class="card mb-4">
                            <div class="card-body p-0">
                                <?php if (empty($patient_diagnoses)): ?>
                                    <div class="p-4 alert alert-info mb-0">Диагнозов пока нет.</div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover mb-0">
                                            <thead class="bg-light">
                                                <tr>
                                                    <th class="py-3 px-3">Дата</th>
                                                    <th class="py-3 px-3">Диагноз</th>
                                                    <th class="py-3 px-3">Врач</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($patient_diagnoses as $d): ?>
                                                    <tr class="hover:bg-gray-50">
                                                        <td class="px-3 py-3 small"><?= date('d.m.Y', strtotime($d['date'])) ?></td>
                                                        <td class="px-3 py-3 fw-bold text-accent"><?= htmlspecialchars($d['diag_name']) ?></td>
                                                        <td class="px-3 py-3 small text-muted"><?= htmlspecialchars($d['doctor_name']) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <h6 class="text-base fw-bold text-secondary mb-2">Добавить диагноз</h6>
                        <button class="btn btn-sm btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#addDiagnosisModal">
                            <i class="fas fa-plus me-1"></i> Добавить диагноз
                        </button>
                    </div>

                    <div class="tab-pane fade" id="recipes" role="tabpanel" aria-labelledby="recipes-tab">
                        <div class="card mb-4">
                            <div class="card-body p-0">
                                <?php if (empty($patient_recipes)): ?>
                                    <div class="p-4 alert alert-info mb-0">Нет рецептов.</div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover mb-0">
                                            <thead class="bg-light">
                                                <tr>
                                                    <th class="py-3 px-3">Лекарство</th>
                                                    <th class="py-3 px-3">Дозировка</th>
                                                    <th class="py-3 px-3">Цена (Cost)</th>
                                                    <th class="py-3 px-3">Дата выписки</th>
                                                    <th class="py-3 px-3">Врач</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($patient_recipes as $r): ?>
                                                    <tr>
                                                        <td class="px-3 py-3 fw-bold text-success"><?= htmlspecialchars($r['medication_name']) ?></td>
                                                        <td class="px-3 py-3"><?= htmlspecialchars($r['dosage']) ?></td>
                                                        <td class="px-3 py-3 small text-muted"><?= htmlspecialchars($r['cost'] ?? '—') ?></td>
                                                        <td class="px-3 py-3 small"><?= date('d.m.Y', strtotime($r['date_prescribed'])) ?></td>
                                                        <td class="px-3 py-3 small text-muted"><?= htmlspecialchars($r['doctor_name']) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <h6 class="text-base fw-bold text-secondary mb-2">Выписать новый рецепт</h6>
                        <button class="btn btn-sm btn-success mb-3" data-bs-toggle="modal" data-bs-target="#addRecipeModal">
                            <i class="fas fa-pills me-1"></i> Выписать рецепт
                        </button>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="row">
            <div class="col-lg-6">
                <div class="card mb-4 h-100">
                    <div class="card-header">Ваши данные</div>
                    <div class="card-body">
                        <div class="d-flex justify-content-end mb-3">
                            <button class="btn btn-sm btn-outline-primary" 
                                onclick="new bootstrap.Modal(document.getElementById('editProfileModal')).show()">
                                <i class="fas fa-edit me-1"></i> Редактировать
                            </button>
                        </div>
                        <div class="row">
                            <div class="col-12">
                                <dl class="row mb-0">
                                    <dt class="col-4 text-muted">ФИО:</dt><dd class="col-8 fw-semibold"><?= htmlspecialchars($profile['full_name'] ?? '—') ?></dd>
                                    <dt class="col-4 text-muted">Email:</dt><dd class="col-8"><?= htmlspecialchars($profile['email'] ?? '—') ?></dd>
                                    <dt class="col-4 text-muted">Телефон:</dt><dd class="col-8"><?= htmlspecialchars($profile['phone_number'] ?? '—') ?></dd>
                                    <?php if ($user_type === 'patient'): ?>
                                        <dt class="col-4 text-muted">Дата рождения:</dt><dd class="col-8"><?= htmlspecialchars($profile['birth_date'] ?? '—') ?></dd>
                                        <dt class="col-4 text-muted">Адрес:</dt><dd class="col-8"><?= htmlspecialchars($profile['home_address'] ?? '—') ?></dd>
                                    <?php else: ?>
                                        <dt class="col-4 text-muted">Должность:</dt><dd class="col-8"><?= htmlspecialchars($profile['position'] ?? '—') ?></dd>
                                        <dt class="col-4 text-muted">Отделение:</dt><dd class="col-8"><?= htmlspecialchars($profile['dep_name'] ?? '—') ?></dd>
                                    <?php endif; ?>
                                </dl>
                            </div>
                        </div>
                        <?php if ($user_type === 'patient' && ($profile['note'] ?? false)): ?>
                            <div class="mt-4 p-3 bg-light text-primary rounded border border-primary-subtle small">
                                <i class="fas fa-info-circle me-2"></i> <span class="fw-semibold">Примечание для врачей:</span> <?= htmlspecialchars($profile['note']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-6">
                <div class="card mb-4 h-100">
                    <div class="card-header"><i class="fas fa-chart-line me-1"></i> Статистика</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <?php if ($user_type === 'patient'): ?>
                                <div class="col-md-6">
                                    <div class="p-3 border rounded text-center bg-light">
                                        <div class="fs-4 fw-bold text-primary"><?= $stats['total_appointments'] ?? 0 ?></div>
                                        <div class="small text-muted">Всего приёмов</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="p-3 border rounded text-center bg-light">
                                        <div class="fs-4 fw-bold text-success"><?= $stats['completed_appointments'] ?? 0 ?></div>
                                        <div class="small text-muted">Завершённых приёмов</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="p-3 border rounded text-center bg-light">
                                        <div class="fs-4 fw-bold text-info"><?= $stats['total_diagnoses'] ?? 0 ?></div>
                                        <div class="small text-muted">Установленных диагнозов</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="p-3 border rounded text-center bg-light">
                                        <div class="fs-4 fw-bold text-warning"><?= $stats['completion_rate'] ?? 0 ?>%</div>
                                        <div class="small text-muted">Процент завершённых</div>
                                    </div>
                                </div>
                            <?php elseif ($user_type === 'doctor'): ?>
                                <div class="col-md-6">
                                    <div class="p-3 border rounded text-center bg-light">
                                        <div class="fs-4 fw-bold text-primary"><?= $stats['total_appointments'] ?? 0 ?></div>
                                        <div class="small text-muted">Всего приёмов</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="p-3 border rounded text-center bg-light">
                                        <div class="fs-4 fw-bold text-success"><?= $stats['completed_appointments'] ?? 0 ?></div>
                                        <div class="small text-muted">Завершённых приёмов</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="p-3 border rounded text-center bg-light">
                                        <div class="fs-4 fw-bold text-info"><?= $stats['unique_patients'] ?? 0 ?></div>
                                        <div class="small text-muted">Уникальных пациентов</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="p-3 border rounded text-center bg-light">
                                        <div class="fs-4 fw-bold text-warning"><?= $stats['total_diagnoses_issued'] ?? 0 ?></div>
                                        <div class="small text-muted">Выписанных диагнозов</div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <hr class="my-5">

        <?php if ($user_type === 'patient'): ?>
            <h3 class="text-2xl text-accent pb-1 mb-4 border-bottom border-accent">
                <i class="far fa-calendar-alt me-2"></i> Мои приемы
                <button class="btn btn-sm btn-success float-end" data-bs-toggle="modal" data-bs-target="#bookAppointmentModal">
                    <i class="fas fa-plus me-1"></i> Записаться на прием
                </button>
            </h3>
            <div class="card mb-4">
                <div class="card-body p-0">
                    <?php if (empty($appointments)): ?>
                        <div class="p-4 alert alert-info mb-0"><i class="fas fa-info-circle me-2"></i> У вас пока нет запланированных приёмов.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="py-3 px-3">Врач</th>
                                        <th class="py-3 px-3">Дата и время</th>
                                        <th class="py-3 px-3">Статус</th>
                                        <th class="py-3 px-3">Протокол</th>
                                        <th class="py-3 px-3">Действия</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($appointments as $a): ?>
                                        <tr class="<?= str_replace(['scheduled', 'completed', 'cancelled', 'no_show'], ['table-primary', 'table-success', 'table-danger', 'table-warning'], 'status-' . $a['status']) ?>">
                                            <td class="px-3 py-3 fw-bold text-accent">
                                                <?= htmlspecialchars($a['doctor_name']) ?>
                                                <div class="text-muted small"><?= $a['dep_name'] ?? '—' ?></div>
                                            </td>
                                            <td class="px-3 py-3"><?= date('d.m.Y H:i', strtotime($a['appointment_datetime'])) ?></td>
                                            <td class="px-3 py-3">
                                                <span class="badge bg-secondary"><?= htmlspecialchars(ucfirst($a['status'])) ?></span>
                                            </td>
                                            <td class="px-3 py-3">
                                                <?php if ($a['protocol']): ?>
                                                    <button class="btn btn-sm btn-outline-info" 
                                                       data-bs-toggle="modal" data-bs-target="#protocolViewModal" 
                                                       data-protocol="<?= htmlspecialchars($a['protocol']) ?>">
                                                       Посмотреть
                                                    </button>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-3 py-3">
                                                <?php if ($a['status'] === 'scheduled' && strtotime($a['appointment_datetime']) > time()): ?>
                                                    <form method="post" class="d-inline" onsubmit="return confirm('Вы уверены, что хотите отменить эту запись?');">
                                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                                                        <input type="hidden" name="action" value="cancel_appointment">
                                                        <input type="hidden" name="apt_id" value="<?= $a['id'] ?>">
                                                        <button class="btn btn-sm btn-danger" type="submit">Отменить</button>
                                                    </form>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <h3 class="text-2xl text-accent pb-1 mb-4 border-bottom border-accent">
                <i class="fas fa-book-medical me-2"></i> Мои записи
            </h3>
            
            <div class="row">
                <div class="col-lg-6">
                    <h4 class="text-primary fs-5">Диагнозы</h4>
                    <div class="card mb-4">
                        <div class="list-group list-group-flush">
                            <?php if (empty($diagnoses_list)): ?>
                                <div class="list-group-item alert alert-info mb-0"><i class="fas fa-info-circle me-2"></i> Диагнозов пока нет.</div>
                            <?php else: ?>
                                <?php foreach ($diagnoses_list as $d): ?>
                                    <div class="list-group-item">
                                        <div class="d-flex w-100 justify-content-between">
                                            <h6 class="mb-1 fw-bold"><?= htmlspecialchars($d['diag_name']) ?></h6>
                                            <small class="text-muted"><?= date('d.m.Y', strtotime($d['date'])) ?></small>
                                        </div>
                                        <p class="mb-1 small">Врач: <?= htmlspecialchars($d['doctor_name']) ?> (<?= htmlspecialchars($d['dep_name'] ?? '—') ?>)</p>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <h4 class="text-success fs-5">Рецепты</h4>
                    <div class="card mb-4">
                        <div class="list-group list-group-flush">
                            <?php if (empty($recipes_list)): ?>
                                <div class="list-group-item alert alert-info mb-0"><i class="fas fa-info-circle me-2"></i> Рецептов пока нет.</div>
                            <?php else: ?>
                                <?php foreach ($recipes_list as $r): ?>
                                    <div class="list-group-item">
                                        <div class="d-flex w-100 justify-content-between">
                                            <h6 class="mb-1 fw-bold text-success"><?= htmlspecialchars($r['medication_name']) ?> (<?= htmlspecialchars($r['dosage']) ?>)</h6>
                                            <small class="text-muted"><?= date('d.m.Y', strtotime($r['date_prescribed'])) ?></small>
                                        </div>
                                        <p class="mb-1 small">Врач: <?= htmlspecialchars($r['doctor_name']) ?></p>
                                        <small class="text-secondary">Цена: <?= htmlspecialchars($r['cost'] ?? '—') ?> руб.</small>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($user_type === 'doctor'): ?>
            <h3 class="text-2xl text-accent pb-1 mb-4 border-bottom border-accent">
                <i class="fas fa-users me-2"></i> Ваши пациенты
            </h3>
            <div class="card mb-4">
                <div class="card-body p-0">
                    <?php if (empty($patients_list)): ?>
                        <div class="p-4 alert alert-info mb-0"><i class="fas fa-info-circle me-2"></i> В системе пока нет зарегистрированных пациентов.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="py-3 px-3">ФИО</th>
                                        <th class="py-3 px-3">Дата рождения</th>
                                        <th class="py-3 px-3">Телефон</th>
                                        <th class="py-3 px-3">Действия</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($patients_list as $p): ?>
                                        <tr>
                                            <td class="px-3 py-3 fw-bold text-accent"><?= htmlspecialchars($p['full_name']) ?></td>
                                            <td class="px-3 py-3"><?= htmlspecialchars($p['birth_date']) ?></td>
                                            <td class="px-3 py-3"><?= htmlspecialchars($p['phone_number']) ?></td>
                                            <td class="px-3 py-3">
                                                <a href="?view_patient=<?= $p['id'] ?>" class="btn btn-sm btn-primary">
                                                    <i class="fas fa-file-alt me-1"></i> Карта
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

    <?php endif; ?>
</main>

<?php if ($user_type === 'patient'): ?>
<div class="modal fade" id="bookAppointmentModal" tabindex="-1" aria-labelledby="bookAppointmentModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="book_appointment">
                <div class="modal-header">
                    <h5 class="modal-title text-accent" id="bookAppointmentModalLabel"><i class="fas fa-calendar-check me-2"></i> Запись на прием</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="doctor_id" class="form-label">Выберите врача</label>
                        <select class="form-select" id="doctor_id" name="doctor_id" required>
                            <option value="" disabled selected>— Выбрать —</option>
                            <?php foreach ($doctors_list as $doc): ?>
                                <option value="<?= $doc['id'] ?>">
                                    <?= htmlspecialchars($doc['full_name']) ?> (<?= htmlspecialchars($doc['position']) ?>, <?= htmlspecialchars($doc['dep_name']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="appointment_datetime" class="form-label">Дата и время приема</label>
                        <input type="datetime-local" class="form-control" id="appointment_datetime" name="appointment_datetime" required min="<?= date('Y-m-d\TH:i') ?>">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-check me-1"></i> Записаться</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="modal fade" id="editProfileModal" tabindex="-1" aria-labelledby="editProfileModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="update_profile">
                <div class="modal-header">
                    <h5 class="modal-title text-accent" id="editProfileModalLabel">Редактировать профиль</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="full_name" class="form-label">ФИО</label>
                        <input type="text" class="form-control" id="full_name" name="full_name" value="<?= htmlspecialchars($profile['full_name'] ?? '') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="phone_number" class="form-label">Номер телефона</label>
                        <input type="tel" class="form-control" id="phone_number" name="phone_number" value="<?= htmlspecialchars($profile['phone_number'] ?? '') ?>" required>
                    </div>
                    <?php if ($user_type === 'patient'): ?>
                        <div class="mb-3">
                            <label for="birth_date" class="form-label">Дата рождения</label>
                            <input type="date" class="form-control" id="birth_date" name="birth_date" value="<?= htmlspecialchars($profile['birth_date'] ?? '') ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="home_address" class="form-label">Адрес проживания</label>
                            <input type="text" class="form-control" id="home_address" name="home_address" value="<?= htmlspecialchars($profile['home_address'] ?? '') ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="note" class="form-label">Примечание для врачей (Аллергии, хронические болезни)</label>
                            <textarea class="form-control" id="note" name="note" rows="3"><?= htmlspecialchars($profile['note'] ?? '') ?></textarea>
                        </div>
                    <?php else: ?>
                        <div class="mb-3">
                            <label for="position" class="form-label">Должность</label>
                            <input type="text" class="form-control" id="position" name="position" value="<?= htmlspecialchars($profile['position'] ?? '') ?>" required>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Сохранить изменения</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="protocolViewModal" tabindex="-1" aria-labelledby="protocolViewModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-accent" id="protocolViewModalLabel">Протокол приема</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p id="protocol-content" class="protocol-text"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
            </div>
        </div>
    </div>
</div>

<?php if ($user_type === 'doctor' && $view_patient > 0): ?>
<div class="modal fade" id="editProtocolModal" tabindex="-1" aria-labelledby="editProtocolModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="update_protocol">
                <input type="hidden" name="apt_id" id="edit-apt-id">
                <input type="hidden" name="view_patient" value="<?= $view_patient ?>">
                <div class="modal-header">
                    <h5 class="modal-title text-accent" id="editProtocolModalLabel">Редактировать протокол и статус</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="protocol-textarea" class="form-label">Протокол приема (Наблюдения, рекомендации)</label>
                        <textarea class="form-control" id="protocol-textarea" name="protocol" rows="5"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="status-select" class="form-label">Статус приема</label>
                        <select class="form-select" id="status-select" name="status">
                            <option value="scheduled">Запланирован</option>
                            <option value="completed">Завершен</option>
                            <option value="no_show">Неявка</option>
                            <option value="cancelled">Отменен</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i> Сохранить</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="addDiagnosisModal" tabindex="-1" aria-labelledby="addDiagnosisModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="add_diagnosis">
                <input type="hidden" name="patient_id" value="<?= $view_patient ?>">
                <input type="hidden" name="view_patient" value="<?= $view_patient ?>">
                <div class="modal-header">
                    <h5 class="modal-title text-accent" id="addDiagnosisModalLabel">Установить диагноз</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="diag_name" class="form-label">Название / Код МКБ-10</label>
                        <input type="text" class="form-control" id="diag_name" name="diag_name" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-primary">Добавить</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="addRecipeModal" tabindex="-1" aria-labelledby="addRecipeModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="add_recipe">
                <input type="hidden" name="patient_id" value="<?= $view_patient ?>">
                <input type="hidden" name="view_patient" value="<?= $view_patient ?>">
                <div class="modal-header">
                    <h5 class="modal-title text-accent" id="addRecipeModalLabel">Выписать рецепт</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="medication_name" class="form-label">Название медикамента</label>
                        <input type="text" class="form-control" id="medication_name" name="medication_name" required>
                    </div>
                    <div class="mb-3">
                        <label for="dosage" class="form-label">Дозировка (например, 1)</label>
                        <input type="number" min="1" class="form-control" id="dosage" name="dosage" required>
                    </div>
                    <div class="mb-3">
                        <label for="cost" class="form-label">Цена (Cost)</label>
                        <input type="number" min="0" class="form-control" id="cost" name="cost" value="0">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-pills me-1"></i> Выписать</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // JS для Пациента: Просмотр Протокола
    document.getElementById('protocolViewModal').addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget
        const protocol = button.getAttribute('data-protocol')
        const modalBodyInput = this.querySelector('#protocol-content')
        modalBodyInput.textContent = protocol
    });

    // JS для Доктора: Редактирование Протокола
    <?php if ($user_type === 'doctor' && $view_patient > 0): ?>
    document.getElementById('editProtocolModal').addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget
        const aptId = button.getAttribute('data-apt-id')
        const protocol = button.getAttribute('data-protocol')
        const status = button.getAttribute('data-status')
        
        this.querySelector('#edit-apt-id').value = aptId
        this.querySelector('#protocol-textarea').value = protocol
        this.querySelector('#status-select').value = status
    });
    <?php endif; ?>
</script>
</body>
</html>