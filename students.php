<?php
// students.php — CRUD Manajemen Data Siswa + Multi-Filter & Search
require 'config.php';
require 'auth.php';
checkRole(['admin']);

$user = $_SESSION['user'];
$msg  = '';
$msgType = 'green';

// ── Fetch all asesors for dropdown & filter ──
$asesors = $pdo->query("SELECT id, name FROM users WHERE role = 'asesor' ORDER BY name ASC")->fetchAll();

// ── Handle POST Actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name           = trim($_POST['name'] ?? '');
        $school         = trim($_POST['school'] ?? '');
        $grade          = trim($_POST['grade'] ?? '');
        $interview_date = $_POST['interview_date'] ?: null;
        $interview_time = trim($_POST['interview_time'] ?? '');
        $location       = trim($_POST['location'] ?? '');
        $user_id        = (int)($_POST['user_id'] ?? 0) ?: null;

        if ($name && $school && $grade) {
            $pdo->prepare("INSERT INTO students (name, school, grade, interview_date, interview_time, location, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([$name, $school, $grade, $interview_date, $interview_time, $location, $user_id]);
            $msg = "Siswa <strong>$name</strong> berhasil ditambahkan.";
        } else {
            $msg = "Nama, sekolah, dan jenjang wajib diisi.";
            $msgType = 'red';
        }

    } elseif ($action === 'edit') {
        $id             = (int)($_POST['id'] ?? 0);
        $name           = trim($_POST['name'] ?? '');
        $school         = trim($_POST['school'] ?? '');
        $grade          = trim($_POST['grade'] ?? '');
        $interview_date = $_POST['interview_date'] ?: null;
        $interview_time = trim($_POST['interview_time'] ?? '');
        $location       = trim($_POST['location'] ?? '');
        $user_id        = (int)($_POST['user_id'] ?? 0) ?: null;

        if ($id && $name && $school && $grade) {
            $pdo->prepare("UPDATE students SET name=?, school=?, grade=?, interview_date=?, interview_time=?, location=?, user_id=? WHERE id=?")
                ->execute([$name, $school, $grade, $interview_date, $interview_time, $location, $user_id, $id]);
            $msg = "Data siswa berhasil diperbarui.";
        } else {
            $msg = "Data tidak lengkap.";
            $msgType = 'red';
        }

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $stmt = $pdo->prepare("SELECT id FROM assessments WHERE student_id = ?");
            $stmt->execute([$id]);
            $assmt = $stmt->fetch();
            if ($assmt) {
                $pdo->prepare("DELETE FROM assessment_answers WHERE assessment_id = ?")->execute([$assmt['id']]);
                $pdo->prepare("DELETE FROM assessments WHERE id = ?")->execute([$assmt['id']]);
            }
            $pdo->prepare("DELETE FROM students WHERE id = ?")->execute([$id]);
            $msg = "Siswa berhasil dihapus beserta data penilaiannya.";
            $msgType = 'amber';
        }
    }

    // Preserve query parameters after POST
    $queryParams = [];
    foreach(['q', 'grade', 'status', 'asesor'] as $k) {
        if (!empty($_GET[$k])) $queryParams[$k] = $_GET[$k];
    }
    $queryParams['msg'] = $msg;
    $queryParams['type'] = $msgType;
    
    header("Location: students.php?" . http_build_query($queryParams));
    exit;
}

if (isset($_GET['msg'])) {
    $msg = $_GET['msg'];
    $msgType = $_GET['type'] ?? 'green';
}

$editStudent = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $editStudent = $stmt->fetch();
}

// ── highlight helper ─────────────────────────────────────────
function hl(string $text, string $query): string {
    $safe = htmlspecialchars($text);
    if (!$query) return $safe;
    $pattern = '/(' . preg_quote(htmlspecialchars($query), '/') . ')/iu';
    return preg_replace($pattern, '<mark class="bg-yellow-100 text-yellow-800 rounded px-0.5">$1</mark>', $safe);
}

// ── Filters & Search ─────────────────────────────────────────
$search   = trim($_GET['q'] ?? '');
$gradeF   = $_GET['grade']  ?? '';
$statusF  = $_GET['status'] ?? ''; // 'done' | 'pending'
$asesorF  = $_GET['asesor'] ?? ''; // user_id or 'unassigned'

$conditions = [];
$params = [];

if ($search) {
    // Cari di nama siswa atau sekolah
    $conditions[] = "(s.name LIKE :search OR s.school LIKE :search)";
    $params['search'] = "%$search%";
}
if ($gradeF) {
    $conditions[] = "s.grade = :grade";
    $params['grade'] = $gradeF;
}
if ($statusF === 'done') {
    $conditions[] = "a.id IS NOT NULL";
} elseif ($statusF === 'pending') {
    $conditions[] = "a.id IS NULL";
}
if ($asesorF === 'unassigned') {
    $conditions[] = "s.user_id IS NULL";
} elseif ($asesorF) {
    $conditions[] = "s.user_id = :asesor";
    $params['asesor'] = $asesorF;
}

$where = $conditions ? " WHERE " . implode(" AND ", $conditions) : "";

$query  = "
    SELECT s.*, u.name as asesor_name,
           a.id as assessment_id, a.grand_total, a.is_recommended
    FROM students s
    LEFT JOIN users u ON s.user_id = u.id
    LEFT JOIN assessments a ON s.id = a.student_id
    $where
    ORDER BY s.id ASC
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$students = $stmt->fetchAll();

// Total un-filtered
$totalStudents = $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();

// Filter URL helper
function studentUrl(array $newParams): string {
    $base = array_filter([
        'q'      => trim($_GET['q']     ?? ''),
        'grade'  => $_GET['grade']      ?? '',
        'status' => $_GET['status']     ?? '',
        'asesor' => $_GET['asesor']     ?? '',
    ], fn($v) => $v !== '');
    $merged = array_filter(array_merge($base, $newParams), fn($v) => $v !== '');
    return 'students.php' . ($merged ? '?' . http_build_query($merged) : '');
}
$activeFilters = (int)($search!=='') + (int)($gradeF!=='') + (int)($statusF!=='') + (int)($asesorF!=='');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="favicon.png">
    <title>Kelola Siswa | PAABS</title>
    <link rel="stylesheet" href="style.css?v=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>body { font-family: 'Nunito', sans-serif; }</style>
</head>
<body class="bg-slate-50 antialiased min-h-screen text-slate-600">

    <!-- Toast -->
    <?php if($msg): ?>
    <div id="toast" class="fixed top-6 right-6 z-[999] px-6 py-4 rounded-2xl shadow-2xl flex items-center gap-3
        <?= $msgType === 'green' ? 'bg-green-600 text-white' : ($msgType === 'red' ? 'bg-red-600 text-white' : 'bg-amber-500 text-white') ?>">
        <i data-lucide="<?= $msgType === 'green' ? 'check-circle' : ($msgType === 'red' ? 'alert-circle' : 'alert-triangle') ?>" class="w-5 h-5 shrink-0"></i>
        <span class="text-sm font-bold"><?= $msg ?></span>
        <button onclick="document.getElementById('toast').remove()" class="ml-2 opacity-60 hover:opacity-100">
            <i data-lucide="x" class="w-4 h-4"></i>
        </button>
    </div>
    <script>
        // Bersihkan parameter msg & type dari URL agar tidak muncul lagi saat di-refresh!
        if (window.history.replaceState) {
            const url = new URL(window.location);
            url.searchParams.delete('msg');
            url.searchParams.delete('type');
            window.history.replaceState(null, '', url.href);
        }
        setTimeout(() => { 
            const t = document.getElementById('toast'); 
            if(t){t.style.opacity='0'; setTimeout(()=>t?.remove(),500);} 
        }, 4500);
    </script>
    <?php endif; ?>

    <nav class="bg-white border-b border-slate-200 px-4 py-3 sticky top-0 z-50 shadow-sm">
      <div class="flex justify-between items-center mx-auto max-w-7xl">
        <div class="flex items-center gap-3">
            <a href="admin.php" class="p-2 bg-slate-100 hover:bg-slate-200 rounded-xl transition-all text-slate-500">
                <i data-lucide="arrow-left" class="w-4 h-4"></i>
            </a>
            <span class="text-base font-black text-slate-800 tracking-tighter">
                PAABS <span class="text-blue-600">Kelola Siswa</span>
            </span>
        </div>
        <div class="flex items-center gap-2">
            <span class="text-xs font-bold text-slate-400"><?= htmlspecialchars($user['name']) ?></span>
            <a href="logout.php" class="text-white bg-red-600 hover:bg-red-700 font-bold rounded-xl text-[10px] px-3 py-1.5 transition-all shadow-md">Keluar</a>
        </div>
      </div>
    </nav>

    <main id="ajax-container" class="p-6 md:p-10 max-w-7xl mx-auto space-y-8">

        <div>
            <h1 class="text-xl font-extrabold text-slate-900 tracking-tight">Manajemen Data Siswa</h1>
            <p class="text-sm text-slate-500 font-medium mt-1">Tambah, edit, atau hapus data siswa dan assign asesor.</p>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

            <!-- Form Tambah / Edit -->
            <div class="lg:col-span-1">
                <div class="bg-white border border-slate-200 rounded-3xl p-6 shadow-sm sticky top-24">
                    <h2 class="text-sm font-bold text-slate-800 mb-5 flex items-center gap-2">
                        <i data-lucide="<?= $editStudent ? 'pencil' : 'user-plus' ?>" class="w-4 h-4 text-blue-600"></i>
                        <?= $editStudent ? 'Edit Data Siswa' : 'Tambah Siswa Baru' ?>
                    </h2>

                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="<?= $editStudent ? 'edit' : 'add' ?>">
                        <?php if($editStudent): ?>
                        <input type="hidden" name="id" value="<?= $editStudent['id'] ?>">
                        <?php endif; ?>

                        <div>
                            <label class="block mb-1.5 text-xs font-semibold text-slate-600">Nama Lengkap *</label>
                            <input type="text" name="name" required
                                   value="<?= htmlspecialchars($editStudent['name'] ?? '') ?>"
                                   placeholder="Nama siswa..."
                                   class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all bg-slate-50">
                        </div>
                        <div>
                            <label class="block mb-1.5 text-xs font-semibold text-slate-600">Asal Sekolah *</label>
                            <input type="text" name="school" required
                                   value="<?= htmlspecialchars($editStudent['school'] ?? '') ?>"
                                   placeholder="Nama sekolah..."
                                   class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all bg-slate-50">
                        </div>
                        <div>
                            <label class="block mb-1.5 text-xs font-semibold text-slate-600">Jenjang *</label>
                            <select name="grade" required
                                    class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all bg-slate-50">
                                <option value="">-- Pilih Jenjang --</option>
                                <?php foreach(['SD','SMP','SMA','SMK'] as $g): ?>
                                <option value="<?= $g ?>" <?= ($editStudent['grade'] ?? '') == $g ? 'selected' : '' ?>><?= $g ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block mb-1.5 text-xs font-semibold text-slate-600">Assign ke Asesor</label>
                            <select name="user_id"
                                    class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all bg-slate-50">
                                <option value="">-- Belum diplot --</option>
                                <?php foreach($asesors as $a): ?>
                                <option value="<?= $a['id'] ?>" <?= ($editStudent['user_id'] ?? '') == $a['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($a['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block mb-1.5 text-xs font-semibold text-slate-600">Tgl Interview</label>
                                <input type="date" name="interview_date"
                                       value="<?= $editStudent['interview_date'] ?? '' ?>"
                                       class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all bg-slate-50">
                            </div>
                            <div>
                                <label class="block mb-1.5 text-xs font-semibold text-slate-600">Jam (Waktu)</label>
                                <input type="text" name="interview_time"
                                       value="<?= htmlspecialchars($editStudent['interview_time'] ?? '') ?>"
                                       placeholder="11.00 - 11.40"
                                       class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all bg-slate-50">
                            </div>
                        </div>
                        <div>
                            <label class="block mb-1.5 text-xs font-semibold text-slate-600">Ruangan / Lokasi</label>
                            <input type="text" name="location"
                                   value="<?= htmlspecialchars($editStudent['location'] ?? '') ?>"
                                   placeholder="R. A-102"
                                   class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all bg-slate-50">
                        </div>

                        <div class="flex gap-2 pt-2">
                            <button type="submit"
                                    class="flex-1 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-sm font-bold transition-all shadow-md active:scale-[.98]">
                                <?= $editStudent ? 'Simpan Perubahan' : 'Tambah Siswa' ?>
                            </button>
                            <?php if($editStudent): ?>
                            <a href="students.php" class="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl text-sm font-bold transition-all">
                                Batal
                            </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Tabel Siswa -->
            <div class="lg:col-span-2 space-y-4">
                <!-- Search & Filters -->
                <div class="space-y-3">
                    <form id="searchForm" method="GET" class="relative group w-full">
                        <?php foreach(['status', 'grade', 'asesor'] as $k): ?>
                            <?php if(!empty($_GET[$k])): ?>
                            <input type="hidden" name="<?= $k ?>" value="<?= htmlspecialchars($_GET[$k]) ?>">
                            <?php endif; ?>
                        <?php endforeach; ?>
                        
                        <div class="absolute inset-y-0 left-0 flex items-center pl-4 pointer-events-none">
                            <i data-lucide="search" class="w-4 h-4 text-slate-400"></i>
                        </div>
                        <input id="searchInput" type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                               class="block w-full p-3 pl-10 pr-10 text-sm border border-slate-200 rounded-2xl bg-white focus:ring-blue-500 focus:border-blue-500 font-medium shadow-sm transition-all placeholder:text-slate-400"
                               placeholder="Cari nama siswa atau sekolah..." autocomplete="off">
                        <?php if($search): ?>
                        <a href="<?= studentUrl(['q'=>'']) ?>" class="absolute inset-y-0 right-4 flex items-center text-slate-300 hover:text-slate-500">
                            <i data-lucide="x-circle" class="w-4 h-4"></i>
                        </a>
                        <?php endif; ?>
                    </form>

                    <!-- Filter Chips -->
                    <div class="flex flex-wrap items-center gap-2">
                        <!-- Grade -->
                        <div class="flex items-center gap-1">
                            <a href="<?= studentUrl(['grade'=>'']) ?>" class="px-3 py-1 rounded-full border text-xs font-semibold transition-all <?= $gradeF==='' ? 'bg-slate-800 text-white border-slate-800' : 'bg-white text-slate-600 border-slate-200 hover:border-slate-400' ?>">Semua Grade</a>
                            <a href="<?= studentUrl(['grade'=>'SD']) ?>" class="hidden sm:inline-block px-3 py-1 rounded-full border text-xs font-semibold transition-all <?= $gradeF==='SD' ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-slate-600 border-slate-200 hover:border-blue-300' ?>">SD</a>
                            <a href="<?= studentUrl(['grade'=>'SMP']) ?>" class="hidden sm:inline-block px-3 py-1 rounded-full border text-xs font-semibold transition-all <?= $gradeF==='SMP' ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-slate-600 border-slate-200 hover:border-blue-300' ?>">SMP</a>
                            <a href="<?= studentUrl(['grade'=>'SMA']) ?>" class="hidden sm:inline-block px-3 py-1 rounded-full border text-xs font-semibold transition-all <?= $gradeF==='SMA' ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-slate-600 border-slate-200 hover:border-blue-300' ?>">SMA</a>
                        </div>
                        
                        <!-- Status -->
                        <div class="flex items-center gap-1 pl-1 border-l border-slate-200">
                            <a href="<?= studentUrl(['status'=>'done']) ?>" class="px-3 py-1 rounded-full border text-xs font-semibold transition-all <?= $statusF==='done' ? 'bg-green-600 text-white border-green-600' : 'bg-white text-slate-600 border-slate-200 hover:border-green-300 hover:text-green-600' ?>">✓ Selesai</a>
                            <a href="<?= studentUrl(['status'=>'pending']) ?>" class="px-3 py-1 rounded-full border text-xs font-semibold transition-all <?= $statusF==='pending' ? 'bg-amber-500 text-white border-amber-500' : 'bg-white text-slate-600 border-slate-200 hover:border-amber-300 hover:text-amber-500' ?>">⏳ Menunggu</a>
                        </div>

                        <!-- Asesor Assigned -->
                        <div class="flex items-center gap-1 pl-1 border-l border-slate-200">
                            <a href="<?= studentUrl(['asesor'=>'unassigned']) ?>" class="px-3 py-1 rounded-full border text-xs font-semibold transition-all <?= $asesorF==='unassigned' ? 'bg-purple-600 text-white border-purple-600' : 'bg-white text-slate-600 border-slate-200 hover:border-purple-300 hover:text-purple-600' ?>">Belum Diplot</a>
                        </div>

                        <?php if($activeFilters > 0): ?>
                        <a href="students.php" class="ml-auto flex items-center gap-1 px-3 py-1 bg-red-50 text-red-500 border border-red-200 rounded-full text-xs font-semibold hover:bg-red-100 transition-all">
                            <i data-lucide="filter-x" class="w-3 h-3"></i> Clear
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="bg-white border border-slate-200 rounded-[24px] overflow-hidden shadow-sm">
                    <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50 flex justify-between items-center">
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest"><?= count($students) ?> dari <?= $totalStudents ?> siswa</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="text-slate-400 text-xs font-semibold border-b border-slate-100">
                                    <th class="px-5 py-3">Nama / Sekolah</th>
                                    <th class="px-5 py-3">Jadwal & Lokasi</th>
                                    <th class="px-5 py-3">Asesor</th>
                                    <th class="px-5 py-3">Status</th>
                                    <th class="px-5 py-3 text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50 text-xs">
                                <?php foreach($students as $s): ?>
                                <tr class="hover:bg-slate-50/50 transition-colors <?= ($editStudent && $editStudent['id'] == $s['id']) ? 'bg-blue-50 border-l-4 border-blue-500' : '' ?>">
                                    <td class="px-5 py-3 text-slate-600">
                                        <div class="font-bold text-sm text-slate-800"><?= hl($s['name'], $search) ?></div>
                                        <div class="text-slate-400 text-xs font-medium mt-0.5"><?= hl($s['school'], $search) ?></div>
                                    </td>
                                    <td class="px-5 py-3">
                                        <div class="flex items-center gap-1.5">
                                            <span class="px-2 py-1 bg-slate-100 text-slate-500 rounded-lg text-[10px] font-bold"><?= htmlspecialchars($s['grade']) ?></span>
                                            <?php if($s['interview_date']): ?>
                                                <span class="text-[10px] font-bold text-blue-600"><?= $s['interview_date'] ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-[10px] text-slate-400 font-medium mt-1 flex flex-wrap items-center gap-x-2 gap-y-1">
                                            <?php if($s['interview_time']): ?>
                                                <span class="flex items-center gap-1"><i data-lucide="clock" class="w-3 h-3 text-slate-300"></i> <?= htmlspecialchars($s['interview_time']) ?></span>
                                            <?php endif; ?>
                                            <?php if($s['location']): ?>
                                                <span class="flex items-center gap-1"><i data-lucide="map-pin" class="w-3 h-3 text-slate-300"></i> <?= htmlspecialchars($s['location']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-5 py-3 text-slate-600 font-medium text-xs">
                                        <?php if($s['asesor_name']): ?>
                                            <?= htmlspecialchars($s['asesor_name']) ?>
                                        <?php else: ?>
                                            <span class="text-red-400 italic">Belum diplot</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-3">
                                        <?php if($s['assessment_id']): ?>
                                            <span class="text-green-600 text-xs font-semibold flex items-center gap-1.5">
                                                <i data-lucide="check-circle" class="w-3.5 h-3.5"></i> Selesai (<?= $s['grand_total'] ?>)
                                            </span>
                                        <?php else: ?>
                                            <span class="text-amber-500 text-xs font-semibold flex items-center gap-1.5">
                                                <i data-lucide="clock" class="w-3.5 h-3.5"></i> Menunggu
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-3 text-right">
                                        <div class="flex justify-end gap-1.5">
                                            <a href="<?= studentUrl(['edit'=>$s['id']]) ?>" class="p-1.5 bg-slate-100 text-slate-600 rounded-lg hover:bg-amber-500 hover:text-white transition-all" title="Edit">
                                                <i data-lucide="pencil" class="w-4 h-4"></i>
                                            </a>
                                            <a href="interview.php?id=<?= $s['id'] ?>" class="p-1.5 bg-slate-100 text-slate-600 rounded-lg hover:bg-blue-600 hover:text-white transition-all" title="Lihat interview">
                                                <i data-lucide="eye" class="w-4 h-4"></i>
                                            </a>
                                            <form method="POST" onsubmit="return confirm('Hapus siswa <?= htmlspecialchars(addslashes($s['name'])) ?>? Semua data penilaian akan ikut terhapus!')" style="display:inline">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                                <button type="submit" class="p-1.5 bg-slate-100 text-slate-600 rounded-lg hover:bg-red-600 hover:text-white transition-all" title="Hapus">
                                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($students)): ?>
                                <tr>
                                    <td colspan="5" class="px-5 py-12 text-center text-slate-400 text-sm font-bold">
                                        <i data-lucide="search-x" class="w-8 h-8 text-slate-300 mx-auto mb-2"></i>
                                        Tidak ada data yang cocok dengan filter.
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <footer class="text-center py-6 text-slate-400 text-xs font-medium border-t border-slate-100 mt-4">
        Yayasan Sultan Iskandar Muda &copy; <?= date('Y') ?> &mdash; PAABS v1.0 | Abdul Muis, S.T., M.Kom.
    </footer>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/flowbite/2.3.0/flowbite.min.js"></script>
    <script src="ajax-spa.js?v=1.1"></script>
</body>
</html>
