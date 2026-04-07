<?php
// admin.php — Multi-Filter: Status, Jenjang, Rekomen + Debounce + Highlight
require 'config.php';
require 'auth.php';
checkRole(['admin']);

$user = $_SESSION['user'];

// ── Filter params ──────────────────────────────────────────────
$search  = trim($_GET['q']      ?? '');
$status  = $_GET['status']      ?? '';   // 'done' | 'pending'
$grade   = $_GET['grade']       ?? '';   // SD|SMP|SMA|SMK
$rekomen = $_GET['rekomen']     ?? '';   // '1' | '0'

// ── Build WHERE clause ─────────────────────────────────────────
$conditions = [];
$params     = [];

if ($search) {
    $conditions[] = "(s.name LIKE :search OR s.school LIKE :search OR u.name LIKE :search)";
    $params['search'] = "%$search%";
}
if ($status === 'done')    $conditions[] = "a.id IS NOT NULL";
if ($status === 'pending') $conditions[] = "a.id IS NULL";
if ($grade)                { $conditions[] = "s.grade LIKE :grade"; $params['grade'] = $grade . '%'; }
if ($rekomen === '1')      $conditions[] = "a.is_recommended = 1";
if ($rekomen === '0')      $conditions[] = "a.is_recommended = 0";

$where = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

// ── Stats ──────────────────────────────────────────────────────
$totalStudents = $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
$completed     = $pdo->query("SELECT COUNT(*) FROM assessments")->fetchColumn();
$pending_count = $totalStudents - $completed;
$recommended   = $pdo->query("SELECT COUNT(*) FROM assessments WHERE is_recommended = 1")->fetchColumn();

// ── Paging & Filtered Stats ────────────────────────────────────
$countQuery = "
    SELECT COUNT(s.id)
    FROM students s
    LEFT JOIN users u ON s.user_id = u.id
    LEFT JOIN assessments a ON s.id = a.student_id
    $where
";
$stmtCount = $pdo->prepare($countQuery);
$stmtCount->execute($params);
$totalFiltered = (int)$stmtCount->fetchColumn();

$limit = 50;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$totalPages = max(1, ceil($totalFiltered / $limit));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $limit;

// ── Main query ─────────────────────────────────────────────────
$query = "
    SELECT s.*, u.name as interviewer_name,
           a.id as assessment_id, a.grand_total, a.child_total, a.parent_total, a.is_recommended
    FROM students s
    LEFT JOIN users u ON s.user_id = u.id
    LEFT JOIN assessments a ON s.id = a.student_id
    $where
    ORDER BY s.id ASC
    LIMIT $limit OFFSET $offset
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$students = $stmt->fetchAll();

// ── Filter URL builder ─────────────────────────────────────────
function filterUrl(array $newParams): string {
    $base = [
        'q'       => trim($_GET['q']      ?? ''),
        'status'  => $_GET['status']      ?? '',
        'grade'   => $_GET['grade']       ?? '',
        'rekomen' => $_GET['rekomen']     ?? '',
    ];
    if (isset($newParams['page'])) $base['page'] = trim($_GET['page'] ?? '');
    $base = array_filter($base, fn($v) => $v !== '');
    $merged = array_filter(array_merge($base, $newParams), fn($v) => $v !== '');
    return 'admin.php' . ($merged ? '?' . http_build_query($merged) : '');
}

// ── Text highlight helper (server-side, XSS-safe) ─────────────
function hl(string $text, string $query): string {
    $safe = htmlspecialchars($text);
    if (!$query) return $safe;
    $pattern = '/(' . preg_quote(htmlspecialchars($query), '/') . ')/iu';
    return preg_replace($pattern, '<mark class="bg-yellow-100 text-yellow-800 rounded px-0.5 not-italic">$1</mark>', $safe);
}

$activeFilters = (int)($search !== '') + (int)($status !== '') + (int)($grade !== '') + (int)($rekomen !== '');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="favicon.png">
    <title>Admin Dashboard | PAABS</title>
    <meta name="description" content="Panel administrasi untuk memantau dan mengelola seluruh penilaian siswa PAABS">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="style.css?v=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest" defer></script>
    <style>
        body { font-family: 'Nunito', sans-serif; }
        #toast { transition: opacity 0.5s ease; }
        #toast:hover { opacity: 1 !important; }
        mark { font-style: normal; }
        :focus-visible { outline: 2px solid #3b82f6; outline-offset: 2px; border-radius: 6px; }
    </style>
</head>
<body class="bg-slate-50 antialiased min-h-screen text-slate-600">

    <!-- Toast Notifikasi -->
    <?php if(isset($_GET['success'])): ?>
    <div id="toast" class="fixed top-6 right-6 z-[999] bg-green-600 text-white px-6 py-4 rounded-2xl shadow-2xl flex items-center gap-3" role="status" aria-live="polite">
        <i data-lucide="check-circle" class="w-5 h-5 shrink-0" aria-hidden="true"></i>
        <span class="text-sm font-bold">Hasil penilaian berhasil disimpan!</span>
        <button onclick="document.getElementById('toast').remove()" class="ml-2 opacity-60 hover:opacity-100" aria-label="Tutup notifikasi">
            <i data-lucide="x" class="w-4 h-4" aria-hidden="true"></i>
        </button>
    </div>
    <script>setTimeout(() => { const t = document.getElementById('toast'); if(t){t.style.opacity='0';setTimeout(()=>t?.remove(),500);} }, 4000);</script>
    <?php endif; ?>

    <!-- Banner Error -->
    <?php if(isset($_GET['error'])): ?>
    <?php
    $errMsgs = [
        'csrf'        => 'Sesi keamanan tidak valid. Silakan muat ulang halaman dan coba lagi.',
        'incomplete'  => 'Data tidak lengkap. Pastikan semua pertanyaan telah dijawab.',
        'not_found'   => 'Siswa tidak ditemukan di database.',
        'unauthorized'=> 'Anda tidak memiliki akses ke data siswa tersebut.',
        'save_failed' => 'Gagal menyimpan data. Silakan coba beberapa saat lagi.',
    ];
    $errMsg = $errMsgs[$_GET['error']] ?? 'Terjadi kesalahan yang tidak terduga.';
    ?>
    <div class="max-w-7xl mx-auto mt-4 px-6 md:px-10">
        <div class="bg-red-50 border border-red-200 rounded-2xl px-5 py-4 flex items-center gap-3 text-red-700" role="alert">
            <i data-lucide="alert-circle" class="w-5 h-5 shrink-0" aria-hidden="true"></i>
            <p class="text-sm font-semibold"><?= htmlspecialchars($errMsg) ?></p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Navbar -->
    <nav class="bg-white border-b border-slate-200 px-4 py-3 sticky top-0 z-50 shadow-sm" role="navigation" aria-label="Navigasi utama">
      <div class="flex flex-wrap justify-between items-center mx-auto max-w-7xl">
        <a href="admin.php" class="flex items-center gap-3 hover:opacity-80 transition-opacity" aria-label="PAABS Admin — kembali ke panel admin" aria-current="page">
            <i data-lucide="shield-check" class="w-5 h-5 text-blue-600" aria-hidden="true"></i>
            <span class="text-base font-black text-slate-800 tracking-tighter">PAABS <span class="text-blue-600">Admin</span></span>
        </a>
        <div class="flex items-center gap-2">
            <a href="students.php"
               class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 px-3 py-2 rounded-xl transition-all focus-visible:ring-2 focus-visible:ring-blue-400"
               aria-label="Kelola data siswa">
                <i data-lucide="users" class="w-3.5 h-3.5" aria-hidden="true"></i>
                <span class="hidden md:inline">Kelola Siswa</span>
            </a>
            <a href="users.php"
               class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 px-3 py-2 rounded-xl transition-all focus-visible:ring-2 focus-visible:ring-blue-400"
               aria-label="Kelola akun asesor">
                <i data-lucide="user-cog" class="w-3.5 h-3.5" aria-hidden="true"></i>
                <span class="hidden md:inline">Kelola Asesor</span>
            </a>
            <a href="export.php"
               class="inline-flex items-center gap-1.5 text-xs font-bold text-white bg-green-600 hover:bg-green-700 px-3 py-2 rounded-xl transition-all shadow-md focus-visible:ring-2 focus-visible:ring-green-400"
               aria-label="Export data ke CSV">
                <i data-lucide="download" class="w-3.5 h-3.5" aria-hidden="true"></i>
                <span class="hidden md:inline">Export Excel</span>
            </a>
            <div class="flex-col items-end hidden md:flex text-right mx-2">
                <span class="text-xs font-bold text-slate-900 leading-none"><?= htmlspecialchars($user['name']) ?></span>
                <span class="text-xs font-medium text-slate-400 leading-none mt-1">Administrator</span>
            </div>
            <a href="logout.php"
               class="text-white bg-red-600 hover:bg-red-700 font-bold rounded-xl text-xs px-3 py-2 transition-all shadow-md flex items-center gap-1.5 focus-visible:ring-2 focus-visible:ring-red-400"
               aria-label="Keluar dari akun">
                <i data-lucide="log-out" class="w-3.5 h-3.5" aria-hidden="true"></i>
                Keluar
            </a>
        </div>
      </div>
    </nav>

    <main id="ajax-container" class="p-6 md:p-10 max-w-7xl mx-auto space-y-8">

        <!-- Header -->
        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6 md:p-7">
            <h1 class="text-2xl md:text-3xl font-black text-slate-900 tracking-tight">Rekap Penilaian Siswa</h1>
            <p class="text-sm text-slate-500 font-medium mt-1.5 flex flex-wrap items-center gap-1.5">
                <span>Memantau</span>
                <span class="inline-flex items-center px-2 py-0.5 bg-blue-100 text-blue-700 font-bold rounded-md tracking-wide">
                    <?= $totalStudents ?> data siswa
                </span>
                <span>secara keseluruhan.</span>
            </p>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4" role="list" aria-label="Statistik penilaian">
            <a href="admin.php" class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm flex items-center gap-4 hover:shadow-md transition-all focus-visible:ring-2 focus-visible:ring-blue-400" role="listitem" aria-label="Total siswa: <?= $totalStudents ?>">
               <div class="bg-blue-100 p-3 rounded-2xl text-blue-600 shrink-0" aria-hidden="true"><i data-lucide="users" class="w-5 h-5"></i></div>
               <div><p class="text-xs font-bold text-slate-400 tracking-tight">Total Siswa</p><p class="text-2xl font-black text-slate-900"><?= $totalStudents ?></p></div>
            </a>
            <a href="<?= filterUrl(['status'=>'done','rekomen'=>'','grade'=>'','q'=>'']) ?>" class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm flex items-center gap-4 hover:shadow-md transition-all focus-visible:ring-2 focus-visible:ring-green-400 <?= $status==='done'&&!$grade&&!$rekomen&&!$search?'ring-2 ring-green-400':'' ?>" role="listitem" aria-label="Selesai dinilai: <?= $completed ?>">
               <div class="bg-green-100 p-3 rounded-2xl text-green-600 shrink-0" aria-hidden="true"><i data-lucide="check-circle" class="w-5 h-5"></i></div>
               <div><p class="text-xs font-bold text-slate-400 tracking-tight">Selesai Dinilai</p><p class="text-2xl font-black text-slate-900"><?= $completed ?></p></div>
            </a>
            <a href="<?= filterUrl(['status'=>'pending','rekomen'=>'','grade'=>'','q'=>'']) ?>" class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm flex items-center gap-4 hover:shadow-md transition-all focus-visible:ring-2 focus-visible:ring-amber-400 <?= $status==='pending'&&!$grade&&!$rekomen&&!$search?'ring-2 ring-amber-400':'' ?>" role="listitem" aria-label="Menunggu penilaian: <?= $pending_count ?>">
               <div class="bg-amber-100 p-3 rounded-2xl text-amber-600 shrink-0" aria-hidden="true"><i data-lucide="clock" class="w-5 h-5"></i></div>
               <div><p class="text-xs font-bold text-slate-400 tracking-tight">Menunggu</p><p class="text-2xl font-black text-slate-900"><?= $pending_count ?></p></div>
            </a>
            <a href="<?= filterUrl(['rekomen'=>'1','status'=>'','grade'=>'','q'=>'']) ?>" class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm flex items-center gap-4 hover:shadow-md transition-all focus-visible:ring-2 focus-visible:ring-purple-400 <?= $rekomen==='1'&&!$grade&&!$status&&!$search?'ring-2 ring-purple-400':'' ?>" role="listitem" aria-label="Direkomendasikan: <?= $recommended ?>">
               <div class="bg-purple-100 p-3 rounded-2xl text-purple-600 shrink-0" aria-hidden="true"><i data-lucide="star" class="w-5 h-5"></i></div>
               <div><p class="text-xs font-bold text-slate-400 tracking-tight">Rekomendasi</p><p class="text-2xl font-black text-slate-900"><?= $recommended ?></p></div>
            </a>
        </div>

        <!-- Table + Filter -->
        <div class="bg-white border border-slate-200 rounded-[28px] overflow-hidden shadow-sm">

            <!-- Search Bar -->
            <div class="p-5 md:p-6 border-b border-slate-100 bg-slate-50/50 space-y-4">
                <div class="flex flex-col md:flex-row gap-4 justify-between items-start md:items-center">
                    <div class="flex items-center gap-3">
                        <h2 class="text-base font-extrabold text-slate-800 flex items-center tracking-tight shrink-0">
                            <i data-lucide="list" class="w-4 h-4 mr-2 text-blue-600" aria-hidden="true"></i> Rekapitulasi Interviu
                        </h2>
                        <!-- Hasil filter badge -->
                        <span class="text-xs font-semibold text-slate-500" aria-live="polite">
                            <?= $totalFiltered ?> siswa (Hal <?= $page ?>/<?= $totalPages ?>)
                            <?php if($activeFilters > 0): ?>
                            <span class="ml-1 px-2 py-0.5 bg-blue-100 text-blue-700 rounded-full text-xs font-black"><?= $activeFilters ?> filter aktif</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <!-- Search form -->
                    <form id="searchForm" method="GET" class="flex gap-2 w-full md:w-auto">
                        <?php foreach(['status','grade','rekomen'] as $k): ?>
                            <?php if(!empty($_GET[$k])): ?>
                            <input type="hidden" name="<?= $k ?>" value="<?= htmlspecialchars($_GET[$k]) ?>">
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <div class="relative flex-1 md:w-64">
                            <div class="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none">
                                <i data-lucide="search" class="w-4 h-4 text-slate-400"></i>
                            </div>
                            <input id="searchInput" type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                                   class="w-full pl-10 pr-9 py-2.5 text-sm border border-slate-200 rounded-xl bg-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500 font-medium transition-all placeholder:text-slate-400"
                                   placeholder="Nama, sekolah, asesor..." autocomplete="off">
                            <?php if($search): ?>
                            <a href="<?= filterUrl(['q'=>'']) ?>" class="absolute inset-y-0 right-2.5 flex items-center text-slate-300 hover:text-slate-500">
                                <i data-lucide="x-circle" class="w-4 h-4"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php if($activeFilters > 0): ?>
                        <a href="admin.php" class="flex items-center gap-1 px-3 py-2.5 bg-red-50 text-red-500 border border-red-200 rounded-xl text-xs font-bold hover:bg-red-100 transition-all" title="Reset semua filter">
                            <i data-lucide="filter-x" class="w-3.5 h-3.5"></i>
                            <span class="hidden md:inline">Reset</span>
                        </a>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- Filter Chips -->
                <div class="flex flex-wrap gap-x-4 gap-y-2 overflow-x-auto pb-1" role="group" aria-label="Filter data siswa">
                    <!-- Status -->
                    <div class="flex items-center gap-1.5 shrink-0">
                        <span class="text-xs font-bold text-slate-400 uppercase tracking-wide" id="filter-status-label">Status:</span>
                        <div class="flex gap-1" role="radiogroup" aria-labelledby="filter-status-label">
                            <a href="<?= filterUrl(['status'=>'']) ?>" class="px-3 py-1 rounded-full border text-xs font-semibold transition-all <?= $status==='' ? 'bg-slate-800 text-white border-slate-800' : 'bg-white text-slate-600 border-slate-200 hover:border-slate-400' ?>" aria-pressed="<?= $status==='' ? 'true':'false' ?>">Semua</a>
                            <a href="<?= filterUrl(['status'=>'done']) ?>" class="px-3 py-1 rounded-full border text-xs font-semibold transition-all <?= $status==='done' ? 'bg-green-600 text-white border-green-600' : 'bg-white text-slate-600 border-slate-200 hover:border-green-300 hover:text-green-600' ?>" aria-pressed="<?= $status==='done' ? 'true':'false' ?>">Selesai</a>
                            <a href="<?= filterUrl(['status'=>'pending']) ?>" class="px-3 py-1 rounded-full border text-xs font-semibold transition-all <?= $status==='pending' ? 'bg-amber-500 text-white border-amber-500' : 'bg-white text-slate-600 border-slate-200 hover:border-amber-300 hover:text-amber-600' ?>" aria-pressed="<?= $status==='pending' ? 'true':'false' ?>">Menunggu</a>
                        </div>
                    </div>

                    <div class="w-px bg-slate-200 self-stretch hidden md:block" aria-hidden="true"></div>

                    <!-- Rekomendasi -->
                    <div class="flex items-center gap-1.5 shrink-0">
                        <span class="text-xs font-bold text-slate-400 uppercase tracking-wide" id="filter-rekomen-label">Rekomen:</span>
                        <div class="flex gap-1" role="radiogroup" aria-labelledby="filter-rekomen-label">
                            <a href="<?= filterUrl(['rekomen'=>'']) ?>" class="px-3 py-1 rounded-full border text-xs font-semibold transition-all <?= $rekomen==='' ? 'bg-slate-800 text-white border-slate-800' : 'bg-white text-slate-600 border-slate-200 hover:border-slate-400' ?>" aria-pressed="<?= $rekomen==='' ? 'true':'false' ?>">Semua</a>
                            <a href="<?= filterUrl(['rekomen'=>'1']) ?>" class="flex items-center gap-1 px-3 py-1 rounded-full border text-xs font-semibold transition-all <?= $rekomen==='1' ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-slate-600 border-slate-200 hover:border-blue-300 hover:text-blue-600' ?>" aria-pressed="<?= $rekomen==='1' ? 'true':'false' ?>">
                                <i data-lucide="check" class="w-3 h-3" aria-hidden="true"></i> Rekomen
                            </a>
                            <a href="<?= filterUrl(['rekomen'=>'0']) ?>" class="flex items-center gap-1 px-3 py-1 rounded-full border text-xs font-semibold transition-all <?= $rekomen==='0' ? 'bg-red-500 text-white border-red-500' : 'bg-white text-slate-600 border-slate-200 hover:border-red-300 hover:text-red-500' ?>" aria-pressed="<?= $rekomen==='0' ? 'true':'false' ?>">
                                <i data-lucide="x" class="w-3 h-3" aria-hidden="true"></i> Tidak Rekomen
                            </a>
                        </div>
                    </div>

                    <div class="w-px bg-slate-200 self-stretch hidden md:block" aria-hidden="true"></div>

                    <!-- Jenjang -->
                    <div class="flex items-center gap-1.5 shrink-0">
                        <span class="text-xs font-bold text-slate-400 uppercase tracking-wide" id="filter-jenjang-label">Jenjang:</span>
                        <div class="flex gap-1" role="radiogroup" aria-labelledby="filter-jenjang-label">
                            <a href="<?= filterUrl(['grade'=>'']) ?>" class="px-3 py-1 rounded-full border text-xs font-semibold transition-all <?= $grade==='' ? 'bg-slate-800 text-white border-slate-800' : 'bg-white text-slate-600 border-slate-200 hover:border-slate-400' ?>" aria-pressed="<?= $grade==='' ? 'true':'false' ?>">Semua</a>
                            <?php foreach(['SD','SMP','SMA','SMK AKL','SMK DKV'] as $g): ?>
                            <a href="<?= filterUrl(['grade'=>$g]) ?>" class="px-3 py-1 rounded-full border text-xs font-semibold transition-all <?= $grade===$g ? 'bg-slate-700 text-white border-slate-700' : 'bg-white text-slate-600 border-slate-200 hover:border-slate-400' ?>" aria-pressed="<?= $grade===$g ? 'true':'false' ?>"><?= $g ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Table -->
            <div class="overflow-x-auto">
                <table class="w-full text-left" role="table">
                    <thead class="bg-slate-50 border-b border-slate-100">
                        <tr class="text-slate-500 text-xs font-bold tracking-wide uppercase">
                            <th scope="col" class="px-6 py-4">Informasi Siswa</th>
                            <th scope="col" class="px-6 py-4">Asesor</th>
                            <th scope="col" class="px-6 py-4">Skor</th>
                            <th scope="col" class="px-6 py-4">Status</th>
                            <th scope="col" class="px-6 py-4">Rekomendasi</th>
                            <th scope="col" class="px-6 py-4 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-medium text-slate-600">
                        <?php foreach($students as $s): ?>
                            <tr class="hover:bg-slate-50/60 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="font-bold text-slate-800 text-sm"><?= hl($s['name'], $search) ?></div>
                                    <div class="text-slate-400 text-[10px] font-medium mt-0.5"><?= hl($s['school'], $search) ?></div>
                                    <div class="flex items-center gap-2 mt-1.5 flex-wrap">
                                        <span class="px-1.5 py-0.5 bg-slate-100 text-slate-500 rounded text-[9px] font-black"><?= htmlspecialchars($s['grade']) ?></span>
                                        <?php if($s['interview_date']): ?>
                                            <span class="flex items-center gap-1 text-[9px] font-bold text-blue-500">
                                                <i data-lucide="calendar" class="w-2.5 h-2.5"></i> <?= $s['interview_date'] ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if($s['interview_time']): ?>
                                            <span class="flex items-center gap-1 text-[9px] font-bold text-amber-500">
                                                <i data-lucide="clock" class="w-2.5 h-2.5"></i> <?= htmlspecialchars($s['interview_time']) ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if($s['location']): ?>
                                            <span class="flex items-center gap-1 text-[9px] font-bold text-slate-400">
                                                <i data-lucide="map-pin" class="w-2.5 h-2.5 text-slate-300"></i> <?= htmlspecialchars($s['location']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center text-slate-600 font-medium text-xs">
                                        <i data-lucide="user-check" class="w-3.5 h-3.5 mr-2 text-slate-300 shrink-0"></i>
                                        <?= hl($s['interviewer_name'] ?? 'Belum diplot', $search) ?>
                                    </div>
                                </td>

                                <td class="px-6 py-4">
                                    <?php if($s['assessment_id']): ?>
                                        <div class="text-sm font-black text-slate-800"><?= $s['grand_total'] ?> <span class="text-slate-300 text-xs font-medium">/ 108</span></div>
                                        <div class="text-xs text-slate-400 font-medium mt-0.5">
                                            <span class="text-blue-400">Anak: <?= $s['child_total'] ?></span>
                                            · <span class="text-green-400">Ortu: <?= $s['parent_total'] ?></span>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-slate-300 text-xs">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4">
                                    <?php if($s['assessment_id']): ?>
                                        <div class="flex items-center text-green-600 font-semibold text-xs">
                                            <i data-lucide="check-circle" class="w-3.5 h-3.5 mr-1.5 shrink-0"></i> Selesai
                                        </div>
                                    <?php else: ?>
                                        <div class="flex items-center text-amber-500 font-semibold text-xs">
                                            <i data-lucide="clock" class="w-3.5 h-3.5 mr-1.5 shrink-0"></i> Menunggu
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4">
                                    <?php if($s['assessment_id']): ?>
                                        <?php if($s['is_recommended'] == 1): ?>
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-blue-100 text-blue-700 rounded-lg text-xs font-bold">
                                                <i data-lucide="check" class="w-3 h-3" aria-hidden="true"></i> Rekomen
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-red-100 text-red-600 rounded-lg text-xs font-bold">
                                                <i data-lucide="x" class="w-3 h-3" aria-hidden="true"></i> Tidak
                                            </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-slate-300 text-xs" aria-label="Belum ada keputusan">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="flex justify-end gap-2">
                                        <a href="interview.php?id=<?= $s['id'] ?>"
                                           class="p-2 bg-slate-100 text-slate-600 rounded-lg hover:bg-blue-600 hover:text-white transition-all shadow-sm active:scale-95 focus-visible:ring-2 focus-visible:ring-blue-400"
                                           aria-label="<?= $s['assessment_id'] ? 'Lihat/Edit penilaian ' : 'Isi interview ' ?><?= htmlspecialchars($s['name']) ?>">
                                            <i data-lucide="<?= $s['assessment_id'] ? 'pencil' : 'eye' ?>" class="w-4 h-4" aria-hidden="true"></i>
                                        </a>
                                        <?php if($s['assessment_id']): ?>
                                        <a href="report.php?id=<?= $s['id'] ?>" target="_blank" rel="noopener noreferrer"
                                           class="p-2 bg-slate-100 text-slate-600 rounded-lg hover:bg-purple-600 hover:text-white transition-all shadow-sm active:scale-95 focus-visible:ring-2 focus-visible:ring-purple-400"
                                           aria-label="Cetak laporan untuk <?= htmlspecialchars($s['name']) ?>">
                                            <i data-lucide="printer" class="w-4 h-4" aria-hidden="true"></i>
                                        </a>
                                        <?php endif; ?>
                                        <a href="students.php?edit=<?= $s['id'] ?>"
                                           class="p-2 bg-slate-100 text-slate-600 rounded-lg hover:bg-amber-500 hover:text-white transition-all shadow-sm active:scale-95 focus-visible:ring-2 focus-visible:ring-amber-400"
                                           aria-label="Edit data siswa <?= htmlspecialchars($s['name']) ?>">
                                            <i data-lucide="user-pen" class="w-4 h-4" aria-hidden="true"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if(empty($students)): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-20 text-center">
                                <i data-lucide="search-x" class="w-10 h-10 text-slate-300 mx-auto mb-3" aria-hidden="true"></i>
                                <p class="text-slate-500 font-bold text-sm">Tidak ada data yang cocok dengan filter</p>
                                <p class="text-slate-400 text-xs font-medium mt-1">Coba ubah kombinasi filter atau reset semua filter</p>
                                <a href="admin.php" class="mt-4 inline-flex items-center gap-1.5 text-sm text-blue-600 font-bold hover:underline">
                                    <i data-lucide="rotate-ccw" class="w-3.5 h-3.5" aria-hidden="true"></i> Reset semua filter
                                </a>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if($totalPages > 1): ?>
            <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex items-center justify-between">
                <span class="text-xs font-semibold text-slate-500">
                    Menampilkan <?= $offset + 1 ?> - <?= min($offset + $limit, $totalFiltered) ?> dari total <?= $totalFiltered ?> siswa
                </span>
                <div class="flex items-center gap-1.5">
                    <?php if($page > 1): ?>
                    <a href="<?= filterUrl(['page' => $page - 1]) ?>" class="p-2 rounded-lg border border-slate-200 bg-white text-slate-500 hover:bg-slate-50 transition-colors" aria-label="Halaman sebelumnya">
                        <i data-lucide="chevron-left" class="w-4 h-4"></i>
                    </a>
                    <?php endif; ?>
                    
                    <span class="px-3 py-1.5 rounded-lg bg-blue-50 text-blue-700 font-bold text-xs border border-blue-100">
                        Hal <?= $page ?> dari <?= $totalPages ?>
                    </span>

                    <?php if($page < $totalPages): ?>
                    <a href="<?= filterUrl(['page' => $page + 1]) ?>" class="p-2 rounded-lg border border-slate-200 bg-white text-slate-500 hover:bg-slate-50 transition-colors" aria-label="Halaman selanjutnya">
                        <i data-lucide="chevron-right" class="w-4 h-4"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <footer class="text-center py-6 text-slate-400 text-xs font-medium border-t border-slate-100 mt-4" role="contentinfo">
        Yayasan Sultan Iskandar Muda &copy; <?= date('Y') ?> &mdash; PAABS v1.0 | Abdul Muis, S.T., M.Kom.
    </footer>

    <script src="ajax-spa.js?v=1.1"></script>
</body>
</html>
