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
$recipes_list = $patient_id ? get_recipes($pdo, $patient_id) : [];
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

$patients_list = $user_type === 'doctor' ? $pdo->query("SELECT id, full_name, phone_number, birth_date FROM patients ORDER BY full_name")->fetchAll() : [];

?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Личный кабинет — МедЦентр "Надежда"</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    
    <style>
        .color-primary { color: #17a2b8; } /* Deep Blue */
        .bg-primary { background-color: #17a2b8; }
        .hover\:bg-primary-dark:hover { background-color: #1e3a8a; }
        .status-scheduled { background-color: #fef3c7; color: #a16207; }
        .status-completed { background-color: #d1fae5; color: #065f46; }
        .status-cancelled, .status-no_show { background-color: #fee2e2; color: #991b1b; }
        .protocol-text { 
            white-space: pre-line; 
            background-color: #eff6ff; 
            padding: 8px; 
            border-left: 4px solid #3b82f6; 
            border-radius: 6px;
        }
        .modal {
            display: none; 
            z-index: 1000;
        }
        .modal.open {
            display: flex;
        }
        .tab-content > div {
            display: none;
        }
        .tab-content > div.active {
            display: block;
        }
    </style>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            600: '#17a2b8',
                            700: '#1d4ed8',
                        },
                    },
                }
            }
        }
    </script>
</head>
<body class="bg-gray-50 min-h-screen pt-16">

<header class="fixed top-0 left-0 right-0 bg-primary-600 shadow-lg z-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3 flex justify-between items-center">
        <a class="text-white text-xl font-bold tracking-tight" href="profile.php">
            <i class="fas fa-notes-medical mr-2"></i> МедЦентр "Надежда"
        </a>
        <div class="flex items-center space-x-4">
            <div class="text-white text-right">
                <div class="font-semibold"><?= htmlspecialchars($user_full_name) ?></div>
                <span class="text-xs px-2 py-0.5 rounded bg-white text-primary-600"><?= $user_type === 'patient' ? 'Пациент' : 'Врач' ?></span>
            </div>
            <form method="post" class="inline">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="logout">
                <button class="text-white hover:text-red-300 transition duration-150 p-2 rounded-full hover:bg-white/10" type="submit" title="Выйти">
                    <i class="fas fa-sign-out-alt text-lg"></i>
                </button>
            </form>
        </div>
    </div>
</header>

<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <?php if ($error): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-6" role="alert">
            <i class="fas fa-exclamation-circle mr-2"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-6" role="alert">
            <i class="fas fa-check-circle mr-2"></i> <?= $success ?>
        </div>
    <?php endif; ?>

    <h2 class="text-2xl font-semibold color-primary pb-1 mb-4 border-b-4 border-primary-600">
        <i class="fas fa-id-card-alt mr-2"></i> Личный кабинет
    </h2>
    
    <div class="grid grid-cols-2 gap-2">
        <div class="bg-white shadow-xl rounded-xl p-6 mb-8 border border-gray-100 h-fit">
            <div class="flex justify-between items-center pb-4 mb-4 border-b border-gray-100">
                <h5 class="text-lg font-bold text-primary-600">Ваши данные</h5>
                <button class="px-4 py-2 text-sm font-medium text-primary-600 bg-white border border-primary-600 rounded-lg hover:bg-primary-600 hover:text-white transition" 
                    onclick="document.getElementById('editProfileModal').classList.add('open')">
                    <i class="fas fa-edit mr-1"></i> Редактировать
                </button>
            </div>
            <div class="gap-x-8 gap-y-4 text-lg">
                <div>
                    <dl>
                        <div class="flex mb-1"><dt class="w-1/3 text-gray-500">ФИО:</dt><dd class="font-semibold"><?= htmlspecialchars($profile['full_name']) ?></dd></div>
                        <div class="flex mb-1"><dt class="w-1/3 text-gray-500">Email:</dt><dd class=""><?= htmlspecialchars($profile['email']) ?></dd></div>
                        <div class="flex mb-1"><dt class="w-1/3 text-gray-500">Телефон:</dt><dd class=""><?= htmlspecialchars($profile['phone_number']) ?></dd></div>
                    </dl>
                </div>
                <div>
                    <dl>
                        <?php if ($user_type === 'patient'): ?>
                            <div class="flex mb-1"><dt class="w-1/3 text-gray-500">Дата рождения:</dt><dd class="w-2/3"><?= $profile['birth_date'] ?></dd></div>
                            <div class="flex mb-1"><dt class="w-1/3 text-gray-500">Адрес:</dt><dd class="w-2/3"><?= htmlspecialchars($profile['home_address']) ?></dd></div>
                        <?php else: ?>
                            <div class="flex mb-1"><dt class="w-1/3 text-gray-500">Должность:</dt><dd class="w-2/3"><?= htmlspecialchars($profile['position']) ?></dd></div>
                            <div class="flex mb-1"><dt class="w-1/3 text-gray-500">Отделение:</dt><dd class="w-2/3"><?= htmlspecialchars($profile['dep_name'] ?? '—') ?></dd></div>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>
            <?php if ($user_type === 'patient' && $profile['note']): ?>
                <div class="mt-4 p-3 bg-indigo-50 text-indigo-700 rounded-lg text-sm border border-indigo-200">
                    <i class="fas fa-info-circle mr-2"></i> <span class="font-semibold">Примечание для врачей:</span> <?= htmlspecialchars($profile['note']) ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="bg-white shadow-xl rounded-xl p-6 mb-8 border border-gray-100">
            <div class="flex justify-between items-center pb-4 mb-4 border-b border-gray-100">
                <h5 class="text-lg font-bold text-primary-600"><i class="fas fa-chart-line mr-1"></i> Статистика</h5>
            </div>
            <div class="grid grid-cols-2 gap-4 text-sm">
                <?php if ($user_type === 'patient'): ?>
                    <div class="p-3 bg-blue-50 border border-blue-200 rounded-lg">
                        <div class="text-2xl font-bold text-blue-600"><?= $stats['total_appointments'] ?></div>
                        <div class="text-sm text-gray-500">Всего приёмов</div>
                    </div>
                    <div class="p-3 bg-green-50 border border-green-200 rounded-lg">
                        <div class="text-2xl font-bold text-green-600"><?= $stats['completed_appointments'] ?></div>
                        <div class="text-sm text-gray-500">Завершённых приёмов</div>
                    </div>
                    <div class="p-3 bg-purple-50 border border-purple-200 rounded-lg col-span-2">
                        <div class="text-2xl font-bold text-purple-600"><?= $stats['total_diagnoses'] ?></div>
                        <div class="text-sm text-gray-500">Установленных диагнозов</div>
                    </div>
                    <div class="p-3 bg-yellow-50 border border-yellow-200 rounded-lg col-span-2">
                        <div class="text-2xl font-bold text-yellow-600"><?= $stats['completion_rate'] ?>%</div>
                        <div class="text-sm text-gray-500">Процент завершённых приёмов</div>
                    </div>
                <?php elseif ($user_type === 'doctor'): ?>
                    <div class="p-3 bg-blue-50 border border-blue-200 rounded-lg">
                        <div class="text-2xl font-bold text-blue-600"><?= $stats['total_appointments'] ?></div>
                        <div class="text-sm text-gray-500">Всего приёмов</div>
                    </div>
                    <div class="p-3 bg-green-50 border border-green-200 rounded-lg">
                        <div class="text-2xl font-bold text-green-600"><?= $stats['completed_appointments'] ?></div>
                        <div class="text-sm text-gray-500">Завершённых приёмов</div>
                    </div>
                    <div class="p-3 bg-red-50 border border-red-200 rounded-lg">
                        <div class="text-2xl font-bold text-red-600"><?= $stats['unique_patients'] ?></div>
                        <div class="text-sm text-gray-500">Уникальных пациентов</div>
                    </div>
                    <div class="p-3 bg-purple-50 border border-purple-200 rounded-lg">
                        <div class="text-2xl font-bold text-purple-600"><?= $stats['total_diagnoses_issued'] ?></div>
                        <div class="text-sm text-gray-500">Выданных диагнозов</div>
                    </div>
                    <div class="p-3 bg-yellow-50 border border-yellow-200 rounded-lg col-span-2">
                        <div class="text-2xl font-bold text-yellow-600"><?= $stats['completion_rate'] ?>%</div>
                        <div class="text-sm text-gray-500">Процент завершённых приёмов</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($user_type === 'patient'): ?>

        <h2 class="text-2xl font-semibold color-primary pb-1 mb-4 border-b-4 border-primary-600">
            <i class="fas fa-calendar-alt mr-2"></i> Запись на приём
        </h2>
        <div class="bg-white shadow-lg rounded-xl p-6 mb-8">
            <form method="post">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="book_appointment">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Выберите врача</label>
                        <select class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-primary-600 focus:border-primary-600" name="doctor_id" required>
                            <option value="">-- Выберите врача --</option>
                            <?php
                            $doctors = $pdo->query("SELECT d.id, d.full_name, d.position, dep.name AS dep FROM doctors d LEFT JOIN departments dep ON d.fk_department = dep.id")->fetchAll();
                            foreach ($doctors as $d) {
                                echo '<option value="'.$d['id'].'">'.htmlspecialchars($d['full_name']).' — '.$d['position'].' ('.$d['dep'].')</option>';
                            }
                            ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Дата и время</label>
                        <input type="datetime-local" class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-primary-600 focus:border-primary-600" name="appointment_datetime" min="<?= date('Y-m-d\TH:i') ?>" required>
                    </div>
                    <button class="h-10 px-4 py-2 text-white bg-green-600 rounded-lg font-medium hover:bg-green-700 transition" type="submit">
                        <i class="fas fa-plus-circle mr-1"></i> Записаться
                    </button>
                </div>
            </form>
        </div>

        <h2 class="text-2xl font-semibold color-primary pb-1 mb-4 border-b-4 border-primary-600">
            <i class="fas fa-clipboard-list mr-2"></i> Мои приёмы
        </h2>
        <?php
        $stmt = $pdo->prepare("SELECT a.*, d.full_name AS doctor_name, dep.name AS dep_name FROM appointments a JOIN doctors d ON a.fk_doctor = d.id LEFT JOIN departments dep ON d.fk_department = dep.id WHERE a.fk_patient = ? ORDER BY a.appointment_datetime DESC");
        $stmt->execute([$patient_id]);
        $appointments = $stmt->fetchAll();
        ?>
        <?php if (empty($appointments)): ?>
            <div class="p-4 bg-blue-100 text-blue-700 rounded-lg"><i class="fas fa-info-circle mr-2"></i> У вас пока нет запланированных приёмов.</div>
        <?php else: ?>
            <div class="bg-white shadow-lg rounded-xl overflow-hidden mb-8">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Врач</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Дата и время</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Статус</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Протокол</th>
                                <th class="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($appointments as $a): ?>
                                <tr class="hover:bg-gray-50 status-<?= $a['status'] ?>">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?= htmlspecialchars($a['doctor_name']) ?>
                                        <div class="text-xs text-gray-500"><?= $a['dep_name'] ?? '—' ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= date('d.m.Y H:i', strtotime($a['appointment_datetime'])) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php 
                                        $status_text = $a['status'] === 'scheduled' ? 'Запланировано' : ($a['status'] === 'completed' ? 'Проведён' : ($a['status'] === 'cancelled' ? 'Отменён' : 'Не явился'));
                                        ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full status-<?= $a['status'] ?>">
                                            <?= $status_text ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500"><?= $a['protocol'] ? '<div class="protocol-text text-xs">'.nl2br(htmlspecialchars($a['protocol'])).'</div>' : '—' ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <?php if ($a['status'] === 'scheduled' && strtotime($a['appointment_datetime']) > time()): ?>
                                            <form method="post">
                                                <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="cancel_appointment">
                                                <input type="hidden" name="apt_id" value="<?= $a['id'] ?>">
                                                <button class="text-red-600 hover:text-red-900 text-xs font-semibold" type="submit"><i class="fas fa-times mr-1"></i> Отменить</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <div>
                <h2 class="text-2xl font-semibold color-primary pb-1 mb-4 border-b-4 border-primary-600">
                    <i class="fas fa-book-medical mr-2"></i> Диагнозы
                </h2>
                <div class="bg-white shadow-lg rounded-xl overflow-hidden">
                    <?php if (empty($diagnoses_list)): ?>
                        <div class="p-4 bg-blue-100 text-blue-700"><i class="fas fa-info-circle mr-2"></i> Диагнозов пока нет.</div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Дата</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Диагноз</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Врач</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php foreach ($diagnoses_list as $d): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= date('d.m.Y', strtotime($d['date_create'])) ?></td>
                                            <td class="px-6 py-4 text-sm text-gray-900"><?= htmlspecialchars($d['diag_name']) ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($d['doctor_name']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div>
                <h2 class="text-2xl font-semibold color-primary pb-1 mb-4 border-b-4 border-primary-600">
                    <i class="fas fa-pills mr-2"></i> Рецепты
                </h2>
                <div class="bg-white shadow-lg rounded-xl overflow-hidden">
                    <?php if (empty($recipes_list)): ?>
                        <div class="p-4 bg-blue-100 text-blue-700"><i class="fas fa-info-circle mr-2"></i> Рецептов пока нет.</div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Лекарство</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Дозировка</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">До</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Врач</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php foreach ($recipes_list as $r): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4 text-sm text-gray-900"><?= htmlspecialchars($r['name']) ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= $r['dosage'] ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= date('d.m.Y', strtotime($r['date_expire'])) ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($r['doctor_name']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    <?php else: ?>

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-8">
            <div class="lg:col-span-1">
                <h2 class="text-xl font-semibold color-primary pb-1 mb-4 border-b-4 border-primary-600">
                    <i class="fas fa-users mr-2"></i> Пациенты
                </h2>
                <div class="bg-white shadow-lg rounded-xl overflow-hidden">
                    <div class="divide-y divide-gray-200">
                        <?php foreach ($patients_list as $p): ?>
                            <a href="?view_patient=<?= $p['id'] ?>" class="block p-4 hover:bg-blue-50 transition duration-150 <?= $view_patient === $p['id'] ? 'bg-blue-100 text-primary-600 font-semibold' : 'text-gray-700' ?>">
                                <div class="font-medium"><?= htmlspecialchars($p['full_name']) ?></div>
                                <small class="text-xs <?= $view_patient === $p['id'] ? 'text-primary-700' : 'text-gray-500' ?>"><?= $p['phone_number'] ?></small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="lg:col-span-3">
                <?php if ($current_patient): ?>
                    <h2 class="text-2xl font-semibold color-primary pb-1 mb-4 border-b-4 border-primary-600">
                        <i class="fas fa-folder-open mr-2"></i> Карта: <?= htmlspecialchars($current_patient['full_name']) ?>
                    </h2>
                    
                    <div class="bg-white shadow-xl rounded-xl p-6 mb-6">
                        <h5 class="text-lg font-bold text-primary-600 mb-3"><i class="fas fa-user-injured mr-1"></i> Информация</h5>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
                            <div class="flex"><dt class="w-1/3 text-gray-500">Дата рождения:</dt><dd class="w-2/3"><?= $current_patient['birth_date'] ?></dd></div>
                            <div class="flex"><dt class="w-1/3 text-gray-500">Телефон:</dt><dd class="w-2/3"><?= $current_patient['phone_number'] ?></dd></div>
                            <div class="flex"><dt class="w-1/3 text-gray-500">Адрес:</dt><dd class="w-2/3"><?= htmlspecialchars($current_patient['home_address']) ?></dd></div>
                            <div class="flex"><dt class="w-1/3 text-gray-500">Email:</dt><dd class="w-2/3"><?= htmlspecialchars($current_patient['email']) ?></dd></div>
                        </div>
                        <?php if ($current_patient['note']): ?>
                            <div class="mt-4 p-3 bg-gray-100 text-gray-700 rounded-lg text-sm border border-gray-200">
                                <span class="font-semibold">Примечание:</span> <?= htmlspecialchars($current_patient['note']) ?>
                            </div>
                        <?php endif; ?>
                        <a href="profile.php" class="inline-block mt-4 px-3 py-1 text-sm bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition"><i class="fas fa-arrow-left mr-1"></i> Назад к списку</a>
                    </div>

                    <div x-data="{ activeTab: 'appointments' }">
                        <div class="flex border-b border-gray-300">
                            <button @click="activeTab = 'appointments'" :class="{'border-primary-600 text-primary-600': activeTab === 'appointments', 'border-transparent text-gray-500 hover:text-primary-600': activeTab !== 'appointments'}" class="py-2 px-4 font-medium text-sm border-b-4 transition duration-150">
                                <i class="fas fa-history mr-1"></i> Приёмы
                            </button>
                            <button @click="activeTab = 'diagnoses'" :class="{'border-primary-600 text-primary-600': activeTab === 'diagnoses', 'border-transparent text-gray-500 hover:text-primary-600': activeTab !== 'diagnoses'}" class="py-2 px-4 font-medium text-sm border-b-4 transition duration-150">
                                <i class="fas fa-book-medical mr-1"></i> Диагнозы
                            </button>
                            <button @click="activeTab = 'recipes'" :class="{'border-primary-600 text-primary-600': activeTab === 'recipes', 'border-transparent text-gray-500 hover:text-primary-600': activeTab !== 'recipes'}" class="py-2 px-4 font-medium text-sm border-b-4 transition duration-150">
                                <i class="fas fa-pills mr-1"></i> Рецепты
                            </button>
                        </div>
                        
                        <div class="bg-white shadow-xl rounded-b-xl p-6 mb-8 tab-content">
                            
                            <div x-show="activeTab === 'appointments'" :class="{'active': activeTab === 'appointments'}">
                                <h5 class="text-lg font-bold text-primary-600 mb-3">История приёмов</h5>
                                <?php if (empty($patient_appointments)): ?>
                                    <div class="p-4 bg-blue-100 text-blue-700"><i class="fas fa-info-circle mr-2"></i> У пациента нет приёмов.</div>
                                <?php else: ?>
                                    <div class="overflow-x-auto">
                                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                                            <thead>
                                                <tr class="bg-gray-50">
                                                    <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">Дата</th>
                                                    <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">Врач</th>
                                                    <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">Статус</th>
                                                    <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">Протокол</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-200">
                                                <?php foreach ($patient_appointments as $a): ?>
                                                    <tr class="hover:bg-gray-50 status-<?= $a['status'] ?>">
                                                        <td class="px-6 py-4 whitespace-nowrap text-gray-500"><?= date('d.m.Y H:i', strtotime($a['appointment_datetime'])) ?></td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-gray-900"><?= htmlspecialchars($a['doctor_name']) ?></td>
                                                        <td class="px-6 py-4 whitespace-nowrap">
                                                            <?php 
                                                            $status_text = $a['status'] === 'scheduled' ? 'Запланировано' : ($a['status'] === 'completed' ? 'Проведён' : ($a['status'] === 'cancelled' ? 'Отменён' : 'Не явился'));
                                                            ?>
                                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full status-<?= $a['status'] ?>">
                                                                <?= $status_text ?>
                                                            </span>
                                                        </td>
                                                        <td class="px-6 py-4 text-gray-500"><?= $a['protocol'] ? '<div class="protocol-text text-xs">'.nl2br(htmlspecialchars($a['protocol'])).'</div>' : '—' ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div x-show="activeTab === 'diagnoses'" :class="{'active': activeTab === 'diagnoses'}">
                                <h5 class="text-lg font-bold text-primary-600 mb-3">Список диагнозов</h5>
                                <?php if (empty($patient_diagnoses)): ?>
                                    <div class="p-4 bg-blue-100 text-blue-700">Нет диагнозов.</div>
                                <?php else: ?>
                                    <div class="overflow-x-auto">
                                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                                            <thead>
                                                <tr class="bg-gray-50">
                                                    <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">Дата</th>
                                                    <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">Диагноз</th>
                                                    <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">Врач</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-200">
                                                <?php foreach ($patient_diagnoses as $d): ?>
                                                    <tr class="hover:bg-gray-50">
                                                        <td class="px-6 py-4 whitespace-nowrap text-gray-500"><?= date('d.m.Y', strtotime($d['date_create'])) ?></td>
                                                        <td class="px-6 py-4 text-gray-900"><?= htmlspecialchars($d['diag_name']) ?></td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-gray-500"><?= htmlspecialchars($d['doctor_name']) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                                
                                <hr class="my-4">
                                <h6 class="text-base font-semibold text-gray-700 mb-2">Добавить диагноз</h6>
                                <form method="post">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="add_diagnosis">
                                    <input type="hidden" name="patient_id" value="<?= $view_patient ?>">
                                    <div class="flex space-x-2">
                                        <input type="text" class="flex-grow px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-600 focus:border-primary-600" name="diag_name" placeholder="Название / Код МКБ-10" required>
                                        <button class="px-4 py-2 text-white bg-green-600 rounded-lg font-medium hover:bg-green-700 transition"><i class="fas fa-plus mr-1"></i> Добавить</button>
                                    </div>
                                </form>
                            </div>

                            <div x-show="activeTab === 'recipes'" :class="{'active': activeTab === 'recipes'}">
                                <h5 class="text-lg font-bold text-primary-600 mb-3">Список рецептов</h5>
                                <?php if (empty($patient_recipes)): ?>
                                    <div class="p-4 bg-blue-100 text-blue-700">Нет рецептов.</div>
                                <?php else: ?>
                                    <div class="overflow-x-auto">
                                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                                            <thead>
                                                <tr class="bg-gray-50">
                                                    <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">Лекарство</th>
                                                    <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">Дозировка</th>
                                                    <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">До</th>
                                                    <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">Врач</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-200">
                                                <?php foreach ($patient_recipes as $r): ?>
                                                    <tr class="hover:bg-gray-50">
                                                        <td class="px-6 py-4 text-gray-900"><?= htmlspecialchars($r['name']) ?></td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-gray-500"><?= $r['dosage'] ?></td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-gray-500"><?= date('d.m.Y', strtotime($r['date_expire'])) ?></td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-gray-500"><?= htmlspecialchars($r['doctor_name']) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                                
                                <hr class="my-4">
                                <h6 class="text-base font-semibold text-gray-700 mb-2">Выписать рецепт</h6>
                                <form method="post">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="add_recipe">
                                    <input type="hidden" name="patient_id" value="<?= $view_patient ?>">
                                    <div class="grid grid-cols-5 gap-2 items-end">
                                        <div class="col-span-2"><input type="text" class="w-full px-3 py-2 border border-gray-300 rounded-lg" name="recipe_name" placeholder="Лекарство" required></div>
                                        <div class="col-span-1"><input type="number" class="w-full px-3 py-2 border border-gray-300 rounded-lg" name="dosage" placeholder="Доза" required></div>
                                        <div class="col-span-1"><input type="date" class="w-full px-3 py-2 border border-gray-300 rounded-lg" name="date_expire" required></div>
                                        <div class="col-span-1"><button class="w-full px-4 py-2 text-white bg-green-600 rounded-lg font-medium hover:bg-green-700 transition"><i class="fas fa-plus"></i></button></div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                <?php else: ?>
                    <h2 class="text-2xl font-semibold color-primary pb-1 mb-4 border-b-4 border-primary-600">
                        <i class="fas fa-calendar-check mr-2"></i> Мои ближайшие приёмы
                    </h2>
                    <?php
                    $stmt = $pdo->prepare("SELECT a.*, p.full_name AS patient_name FROM appointments a JOIN patients p ON a.fk_patient = p.id WHERE a.fk_doctor = ? ORDER BY a.appointment_datetime");
                    $stmt->execute([$doctor_id]);
                    $appointments = $stmt->fetchAll();
                    ?>
                    <?php if (empty($appointments)): ?>
                        <div class="p-4 bg-blue-100 text-blue-700"><i class="fas fa-info-circle mr-2"></i> У вас нет запланированных приёмов.</div>
                    <?php else: ?>
                        <div class="bg-white shadow-lg rounded-xl overflow-hidden mb-8">
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead>
                                        <tr class="bg-gray-50 text-sm">
                                            <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">Пациент</th>
                                            <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">Дата и время</th>
                                            <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase" style="width: 50%;">Протокол / Статус</th>
                                            <th class="px-6 py-3"></th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200">
                                        <?php foreach ($appointments as $a): ?>
                                            <tr class="hover:bg-gray-50 status-<?= $a['status'] ?>">
                                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                    <a href="?view_patient=<?= $a['fk_patient'] ?>" class="text-primary-600 hover:underline font-semibold"><?= htmlspecialchars($a['patient_name']) ?></a>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= date('d.m.Y H:i', strtotime($a['appointment_datetime'])) ?></td>
                                                <td class="px-6 py-4 text-sm">
                                                    <form method="post">
                                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                                                        <input type="hidden" name="action" value="update_protocol">
                                                        <input type="hidden" name="apt_id" value="<?= $a['id'] ?>">
                                                        
                                                        <div class="flex items-center space-x-2 mb-2">
                                                            <select name="status" class="text-xs px-2 py-1 border border-gray-300 rounded-lg">
                                                                <option value="scheduled" <?= $a['status'] == 'scheduled' ? 'selected' : '' ?>>Запланировано</option>
                                                                <option value="completed" <?= $a['status'] == 'completed' ? 'selected' : '' ?>>Проведён</option>
                                                                <option value="no_show" <?= $a['status'] == 'no_show' ? 'selected' : '' ?>>Не явился</option>
                                                                <option value="cancelled" <?= $a['status'] == 'cancelled' ? 'selected' : '' ?>>Отменён</option>
                                                            </select>
                                                            <button class="text-xs px-3 py-1 text-white bg-primary-600 rounded-lg hover:bg-primary-700 transition" type="submit"><i class="fas fa-save mr-1"></i> Сохранить</button>
                                                        </div>
                                                        <textarea name="protocol" class="w-full text-sm px-2 py-1 border border-gray-300 rounded-lg focus:ring-primary-600 focus:border-primary-600" rows="2" placeholder="Протокол осмотра..."><?= htmlspecialchars($a['protocol'] ?? '') ?></textarea>
                                                    </form>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                                    <?php if ($a['status'] === 'scheduled' && strtotime($a['appointment_datetime']) > time()): ?>
                                                        <form method="post">
                                                            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                                                            <input type="hidden" name="action" value="cancel_appointment">
                                                            <input type="hidden" name="apt_id" value="<?= $a['id'] ?>">
                                                            <button class="text-xs px-3 py-1 text-white bg-red-600 rounded-lg hover:bg-red-700 transition" type="submit"><i class="fas fa-times mr-1"></i> Отменить</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

    <?php endif; ?>

</main>

<div id="editProfileModal" class="hidden modal fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg overflow-hidden transform transition-all">
        <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_profile">
            <div class="p-6 bg-primary-600 text-white flex justify-between items-center">
                <h5 class="text-xl font-bold"><i class="fas fa-edit mr-1"></i> Редактирование профиля</h5>
                <button type="button" class="text-white hover:text-gray-200 text-2xl" onclick="document.getElementById('editProfileModal').classList.remove('open')">&times;</button>
            </div>
            <div class="p-6">
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">ФИО</label>
                    <input type="text" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-600 focus:border-primary-600" name="full_name" value="<?= htmlspecialchars($profile['full_name']) ?>" required>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Телефон</label>
                    <input type="tel" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-600 focus:border-primary-600" name="phone_number" value="<?= htmlspecialchars($profile['phone_number']) ?>" required>
                </div>
                <?php if ($user_type === 'patient'): ?>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Дата рождения</label>
                            <input type="date" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-600 focus:border-primary-600" name="birth_date" value="<?= $profile['birth_date'] ?>" required>
                        </div>
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Адрес проживания</label>
                            <input type="text" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-600 focus:border-primary-600" name="home_address" value="<?= htmlspecialchars($profile['home_address']) ?>" required>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Примечание (для врачей)</label>
                        <textarea class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-600 focus:border-primary-600" name="note" rows="3"><?= htmlspecialchars($profile['note'] ?? '') ?></textarea>
                    </div>
                <?php else: ?>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Должность</label>
                        <input type="text" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-600 focus:border-primary-600" name="position" value="<?= htmlspecialchars($profile['position']) ?>" required>
                    </div>
                <?php endif; ?>
            </div>
            <div class="p-4 bg-gray-50 flex justify-end space-x-3">
                <button type="button" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition" onclick="document.getElementById('editProfileModal').classList.remove('open')">Отмена</button>
                <button type="submit" class="px-4 py-2 bg-primary-600 text-white rounded-lg hover:bg-primary-700 transition"><i class="fas fa-save mr-1"></i> Сохранить изменения</button>
            </div>
        </form>
    </div>
</div>

<script>
    document.getElementById('editProfileModal').addEventListener('click', function(event) {
        if (event.target === this) {
            this.classList.remove('open');
        }
    });
</script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</body>
</html>