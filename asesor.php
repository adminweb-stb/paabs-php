<?php
// asesor.php — Enhanced: Multi-Filter, Highlight, Debounce
require 'config.php';
require 'auth.php';
checkRole(['asesor']);

$user   = $_SESSION['user'];
$search = trim($_GET['q']      ?? '');
$status = $_GET['status']      ?? '';   // 'done' | 'pending'
$date   = $_GET['date']        ?? date('Y-m-d'); // Default: today


// ── highlight helper ─────────────────────────────────────────
function hl(string $text, string $query): string {
    $safe = htmlspecialchars($text);
    if (!$query) return $safe;
    $pattern = '/(' . preg_quote(htmlspecialchars($query), '/') . ')/iu';
    return preg_replace($pattern, '<mark class="bg-yellow-100 text-yellow-800 rounded px-0.5">$1</mark>', $safe);
}

// ── Build query ───────────────────────────────────────────────
$query = "SELECT s.*, a.id as assessment_id, a.grand_total, a.child_total, a.parent_total, a.is_recommended
          FROM students s
          LEFT JOIN assessments a ON s.id = a.student_id
          WHERE s.user_id = :user_id";
$params = ['user_id' => $user['id']];

if ($date !== 'all') {
    $query .= " AND s.interview_date = :date";
    $params['date'] = $date;
}

if ($search) {
    $query .= " AND (s.name LIKE :search OR s.school LIKE :search)";
    $params['search'] = "%$search%";
}
if ($status === 'done')    $query .= " AND a.id IS NOT NULL";
if ($status === 'pending') $query .= " AND a.id IS NULL";
$query .= " ORDER BY s.id ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$students = $stmt->fetchAll();

// Stats (unfiltered untuk progress bar)
$stmtStats = $pdo->prepare("
    SELECT 
        COUNT(s.id) as total,
        COUNT(a.id) as done_count
    FROM students s
    LEFT JOIN assessments a ON s.id = a.student_id
    WHERE s.user_id = :uid " . ($date !== 'all' ? " AND s.interview_date = :date" : "") . "
");
$statsParams = ['uid' => $user['id']];
if ($date !== 'all') $statsParams['date'] = $date;
$stmtStats->execute($statsParams);
$stats = $stmtStats->fetch();

$total       = (int)$stats['total'];
$doneCount   = (int)$stats['done_count'];
$progressPct = $total > 0 ? round($doneCount / $total * 100) : 0;

// Filter URL helper
function asesorUrl(array $newParams): string {
    $base = array_filter([
        'q'      => trim($_GET['q']     ?? ''),
        'status' => $_GET['status']     ?? '',
        'date'   => $_GET['date']       ?? '',
    ], fn($v) => $v !== '');
    $merged = array_filter(array_merge($base, $newParams), fn($v) => $v !== '');
    return 'asesor.php' . ($merged ? '?' . http_build_query($merged) : '');
}

$activeFilters = (int)($search !== '') + (int)($status !== '');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="favicon.png">
    <title>Asesor Dashboard | PAABS</title>
    <meta name="description" content="Dashboard asesor untuk mengelola penilaian wawancara siswa PAABS">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="style.css?v=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest" defer></script>
    <style>
        body { font-family: 'Nunito', sans-serif; }
        #toast { transition: opacity 0.5s ease; }
        #toast:hover { opacity: 1 !important; }
        :focus-visible { outline: 2px solid #3b82f6; outline-offset: 2px; border-radius: 6px; }
    </style>
</head>
<body class="bg-slate-50 antialiased min-h-screen text-slate-600">

    <!-- Toast Notifikasi -->
    <?php if(isset($_GET['success'])): ?>
    <div id="toast" class="fixed top-6 right-6 z-[999] bg-green-600 text-white px-6 py-4 rounded-2xl shadow-2xl flex items-center gap-3" role="status" aria-live="polite">
        <i data-lucide="check-circle" class="w-5 h-5 shrink-0" aria-hidden="true"></i>
        <span class="text-sm font-bold">Hasil penilaian berhasil disimpan!</span>
        <button onclick="document.getElementById('toast').remove()" class="ml-2 opacity-60 hover:opacity-100 focus-visible:opacity-100" aria-label="Tutup notifikasi">
            <i data-lucide="x" class="w-4 h-4" aria-hidden="true"></i>
        </button>
    </div>
    <script>
        // Bersihkan URL dari parameter success agar tidak muncul kembali saat halaman di-refresh
        if (window.history.replaceState) {
            const url = new URL(window.location);
            url.searchParams.delete('success');
            window.history.replaceState(null, '', url.href);
        }

        setTimeout(() => {
            const t = document.getElementById('toast');
            if(t) { t.style.opacity = '0'; setTimeout(() => t?.remove(), 500); }
        }, 4000);
    </script>
    <?php endif; ?>

    <!-- Banner Error (dari redirect save.php) -->
    <?php if(isset($_GET['error'])): ?>
    <?php
    $errMsgs = [
        'csrf'        => 'Sesi keamanan tidak valid. Silakan coba lagi.',
        'incomplete'  => 'Data tidak lengkap. Pastikan semua pertanyaan telah dijawab.',
        'not_found'   => 'Siswa tidak ditemukan.',
        'unauthorized'=> 'Anda tidak memiliki akses ke data siswa tersebut.',
        'save_failed' => 'Gagal menyimpan data. Silakan coba beberapa saat lagi.',
    ];
    $errMsg = $errMsgs[$_GET['error']] ?? 'Terjadi kesalahan. Silakan coba lagi.';
    ?>
    <div class="max-w-7xl mx-auto mt-4 px-6 md:px-10">
        <div class="bg-red-50 border border-red-200 rounded-2xl px-5 py-4 flex items-center gap-3 text-red-700" role="alert">
            <i data-lucide="alert-circle" class="w-5 h-5 shrink-0" aria-hidden="true"></i>
            <p class="text-sm font-semibold"><?= htmlspecialchars($errMsg) ?></p>
        </div>
    </div>
    <script>
        // Bersihkan URL dari parameter error agar tidak memblokir saat di-refresh
        if (window.history.replaceState) {
            const url = new URL(window.location);
            url.searchParams.delete('error');
            window.history.replaceState(null, '', url.href);
        }
    </script>
    <?php endif; ?>

    <nav class="bg-white border-b border-slate-200 px-4 py-3 sticky top-0 z-50 shadow-sm" role="navigation" aria-label="Navigasi utama">
      <div class="flex flex-wrap justify-between items-center mx-auto max-w-7xl">
        <a href="asesor.php" class="flex items-center gap-3 hover:opacity-80 transition-opacity" aria-label="PAABS Asesor — kembali ke dashboard" aria-current="page">
            <i data-lucide="clipboard-list" class="w-5 h-5 text-blue-600" aria-hidden="true"></i>
            <span class="text-base font-black text-slate-800 tracking-tighter leading-none">
                PAABS <span class="text-blue-600">Asesor</span>
            </span>
        </a>
        <div class="flex items-center gap-3">
            <div class="flex-col items-end hidden md:flex text-right">
                <span class="text-xs font-bold text-slate-900 leading-none"><?= htmlspecialchars($user['name']) ?></span>
                <span class="text-xs font-medium text-slate-400 leading-none mt-1">Akun Asesor</span>
            </div>
            <a href="logout.php"
               class="text-white bg-red-600 hover:bg-red-700 focus-visible:ring-2 focus-visible:ring-red-400 font-bold rounded-xl text-xs px-4 py-2 text-center transition-all shadow-md flex items-center gap-1.5"
               aria-label="Keluar dari akun">
                <i data-lucide="log-out" class="w-3.5 h-3.5" aria-hidden="true"></i>
                Keluar
            </a>
        </div>
      </div>
    </nav>

    <?php
    // Sapaan berdasarkan waktu (Timezone Jakarta)
    $hour = (int)date('H');
    if ($hour >= 5  && $hour < 12) $greeting = 'Selamat Pagi';
    elseif ($hour >= 12 && $hour < 15) $greeting = 'Selamat Siang';
    elseif ($hour >= 15 && $hour < 19) $greeting = 'Selamat Sore';
    else $greeting = 'Selamat Malam';

    $pendingCount = $total - $doneCount;

    // Pesan motivasi kontekstual
    if ($total === 0) {
        $motivation = 'Belum ada jadwal asesment hari ini. Silakan cek di hari lain.';
    } elseif ($doneCount === 0) {
        $motivation = 'Yuk mulai! Selesaikan penilaian untuk semua siswa yang telah ditugaskan.';
    } elseif ($doneCount === $total) {
        $motivation = '🎉 Luar biasa! Seluruh penilaian telah tuntas. Terima kasih atas dedikasi Anda.';
    } elseif ($progressPct >= 75) {
        $motivation = 'Hampir selesai! Hanya ' . $pendingCount . ' siswa lagi yang perlu dinilai.';
    } elseif ($progressPct >= 50) {
        $motivation = 'Progres bagus! Sudah lebih dari setengah penilaian selesai. Terus semangat!';
    } else {
        $motivation = 'Ada ' . $pendingCount . ' siswa menunggu penilaian Anda. Setiap penilaian sangat berarti.';
    }
    ?>
    <main id="ajax-container" class="p-6 md:p-10 max-w-7xl mx-auto space-y-8">

        <!-- ═══════════════════════════════════════════════════ -->
        <!-- GREETING CARD                                       -->
        <!-- ═══════════════════════════════════════════════════ -->
        <?php
        // Ambil nama depan saja agar lebih personal
        $firstName = explode(' ', trim($user['name']))[0];
        ?>
        <div class="relative overflow-hidden bg-gradient-to-br from-blue-600 via-blue-700 to-indigo-800 rounded-3xl p-6 md:p-8 shadow-xl shadow-blue-200">

            <!-- Decorative blobs -->
            <div class="absolute -top-10 -right-10 w-48 h-48 bg-white/5 rounded-full blur-2xl pointer-events-none" aria-hidden="true"></div>
            <div class="absolute bottom-0 left-20 w-32 h-32 bg-indigo-400/20 rounded-full blur-xl pointer-events-none" aria-hidden="true"></div>

            <div class="relative flex flex-col md:flex-row md:items-center justify-between gap-6">

                <!-- Kiri: Sapaan + Motivasi -->
                <div class="space-y-2">
                    <p class="text-blue-200 text-xs font-bold uppercase tracking-widest flex items-center gap-2">
                        <i data-lucide="sun" class="w-3.5 h-3.5" aria-hidden="true"></i>
                        <?= htmlspecialchars($greeting) ?>, <?= date('l, d M Y') ?>
                    </p>
                    <h1 class="text-2xl md:text-3xl font-black text-white tracking-tight leading-tight">
                        Halo, <?= htmlspecialchars($firstName) ?>! 👋
                    </h1>
                    <p class="text-blue-100 text-sm font-medium leading-relaxed max-w-md">
                        <?= htmlspecialchars($motivation) ?>
                    </p>
                </div>

                <!-- Kanan: Stat Pills -->
                <?php if ($total > 0): ?>
                <div class="flex flex-row md:flex-col gap-3 shrink-0">

                    <!-- Selesai -->
                    <div class="flex items-center gap-3 bg-white/10 backdrop-blur-sm border border-white/20 rounded-2xl px-4 py-3 min-w-[130px]">
                        <div class="w-8 h-8 bg-emerald-400/20 rounded-xl flex items-center justify-center shrink-0">
                            <i data-lucide="check-circle" class="w-4 h-4 text-emerald-300" aria-hidden="true"></i>
                        </div>
                        <div>
                            <p class="text-[10px] font-bold text-blue-200 uppercase tracking-wide leading-none">Selesai</p>
                            <p class="text-xl font-black text-white leading-tight mt-0.5">
                                <?= $doneCount ?>
                                <span class="text-xs font-semibold text-blue-300">/ <?= $total ?></span>
                            </p>
                        </div>
                    </div>

                    <!-- Progress -->
                    <div class="flex items-center gap-3 bg-white/10 backdrop-blur-sm border border-white/20 rounded-2xl px-4 py-3 min-w-[130px]">
                        <div class="w-8 h-8 bg-amber-400/20 rounded-xl flex items-center justify-center shrink-0">
                            <i data-lucide="clock" class="w-4 h-4 text-amber-300" aria-hidden="true"></i>
                        </div>
                        <div>
                            <p class="text-[10px] font-bold text-blue-200 uppercase tracking-wide leading-none">Menunggu</p>
                            <p class="text-xl font-black text-white leading-tight mt-0.5">
                                <?= $pendingCount ?>
                                <span class="text-xs font-semibold text-blue-300">siswa</span>
                            </p>
                        </div>
                    </div>

                </div>
                <?php endif; ?>
            </div>

            <!-- Progress Bar bawah kartu -->
            <?php if ($total > 0): ?>
            <div class="relative mt-5 pt-5 border-t border-white/10">
                <div class="flex justify-between items-center mb-2">
                    <span class="text-xs font-bold text-blue-200 uppercase tracking-wide">Progress Keseluruhan</span>
                    <span class="text-sm font-black text-white"><?= $progressPct ?>%</span>
                </div>
                <div class="w-full bg-white/10 rounded-full h-2 overflow-hidden"
                     role="progressbar"
                     aria-valuenow="<?= $progressPct ?>"
                     aria-valuemin="0"
                     aria-valuemax="100"
                     aria-label="Progress penilaian <?= $progressPct ?>%">
                    <div class="h-2 rounded-full transition-all duration-1000 ease-out
                                <?= $progressPct === 100 ? 'bg-emerald-400' : 'bg-white' ?>"
                         style="width: <?= $progressPct ?>%">
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Dashboard Overview Hub -->
        <div class="bg-white rounded-[24px] p-5 shadow-sm border border-slate-200 flex flex-col lg:flex-row items-start lg:items-center justify-between gap-4">
            
            <!-- Title & Default Message -->
            <div class="space-y-1 shrink-0">
                <h2 class="text-lg font-black text-slate-900 tracking-tight">Daftar Seleksi Siswa</h2>
                <?php if ($total === 0 && $date !== 'all'): ?>
                    <p class="text-[10px] text-amber-500 font-black uppercase tracking-tight flex items-center gap-1">
                        <i data-lucide="alert-circle" class="w-3 h-3"></i> Tidak ada jadwal hari ini
                    </p>
                <?php else: ?>
                    <p class="text-xs text-slate-500 font-medium italic">Gunakan pencarian atau filter untuk menemukan siswa.</p>
                <?php endif; ?>
            </div>

            <!-- Search + Filters -->
            <div class="flex flex-col sm:flex-row gap-3 w-full lg:w-auto items-start sm:items-center">
                <!-- Date Picker -->
                <form method="GET" class="flex items-center gap-2">
                    <?php if($status): ?> <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>"> <?php endif; ?>
                    <?php if($search): ?> <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>"> <?php endif; ?>
                    <input type="date" name="date" value="<?= $date !== 'all' ? $date : '' ?>" 
                           onchange="this.form.submit()"
                           class="text-xs font-bold border-slate-200 rounded-xl bg-slate-50 focus:ring-blue-500/20 focus:border-blue-500 px-3 py-2">
                    <a href="<?= asesorUrl(['date'=>'all']) ?>" 
                       class="px-3 py-2 bg-slate-100 hover:bg-slate-200 rounded-xl text-[10px] font-black uppercase tracking-tight transition-all <?= $date==='all' ? 'bg-blue-600 text-white shadow-md' : 'text-slate-500' ?>">
                        Semua
                    </a>
                </form>

                <!-- Search Bar -->
                <form id="searchForm" method="GET" class="w-full sm:w-[320px]">
                    <?php if($status): ?>
                    <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
                    <?php endif; ?>
                    <div class="relative group">
                        <div class="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none">
                            <i data-lucide="search" class="w-4 h-4 text-slate-400 group-focus-within:text-blue-500 transition-colors"></i>
                        </div>
                        <input id="searchInput" type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                               class="block w-full p-2.5 pl-10 pr-9 text-xs text-slate-900 border border-slate-200 rounded-xl bg-slate-50 focus:bg-white focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 font-bold transition-all placeholder:font-medium placeholder:text-slate-400"
                               placeholder="Cari nama / sekolah..." autocomplete="off">
                        <?php if($search): ?>
                            <a href="<?= asesorUrl(['q'=>'']) ?>" class="absolute inset-y-0 right-3 flex items-center text-slate-300 hover:text-rose-500 transition-colors">
                                <i data-lucide="x-circle" class="w-4 h-4"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>

                <!-- Filter Chips -->
                <div class="flex items-center gap-1.5 shrink-0 bg-slate-50 p-1 rounded-xl border border-slate-100">
                    <a href="<?= asesorUrl(['status'=>'']) ?>" class="px-3 py-1.5 rounded-lg text-[10px] font-bold transition-all <?= $status==='' ? 'bg-slate-800 text-white shadow-sm' : 'text-slate-500 hover:text-slate-700 hover:bg-slate-200/50' ?>">
                        Semua
                    </a>
                    <a href="<?= asesorUrl(['status'=>'done']) ?>" class="flex items-center gap-1 px-3 py-1.5 rounded-lg text-[10px] font-bold transition-all <?= $status==='done' ? 'bg-emerald-100 text-emerald-800 shadow-sm border border-emerald-200/50' : 'text-slate-500 hover:text-emerald-700 hover:bg-emerald-50' ?>">
                        <i data-lucide="check-circle" class="w-3 h-3 <?= $status==='done' ? 'text-emerald-600' : 'text-slate-400' ?>"></i> Selesai
                    </a>
                    <a href="<?= asesorUrl(['status'=>'pending']) ?>" class="flex items-center gap-1 px-3 py-1.5 rounded-lg text-[10px] font-bold transition-all <?= $status==='pending' ? 'bg-amber-100 text-amber-800 shadow-sm border border-amber-200/50' : 'text-slate-500 hover:text-amber-700 hover:bg-amber-50' ?>">
                        <i data-lucide="clock" class="w-3 h-3 <?= $status==='pending' ? 'text-amber-600' : 'text-slate-400' ?>"></i> Menunggu
                    </a>

                    <?php if($activeFilters > 0): ?>
                    <a href="asesor.php" class="ml-1 flex items-center gap-1 px-2.5 py-1.5 bg-rose-50 text-rose-600 rounded-lg text-[10px] font-bold uppercase transition-all hover:bg-rose-100" aria-label="Hapus semua filter">
                        <i data-lucide="filter-x" class="w-3 h-3" aria-hidden="true"></i> Reset
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Student Cards Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 pb-10">
            <?php foreach ($students as $s):
                $isPending   = !$s['assessment_id'];
                $isRecommend = $s['assessment_id'] && $s['is_recommended'] == 1;
                $isNotRecom  = $s['assessment_id'] && $s['is_recommended'] != 1;
            ?>

            <div class="bg-white rounded-[20px] shadow-sm hover:shadow-xl hover:-translate-y-1 transition-all duration-300 overflow-hidden flex flex-col border border-slate-100">

                <!-- ── CARD HEADER (warna per status) ── -->
                <?php if ($isPending): ?>
                <div class="bg-amber-400 px-5 pt-5 pb-4">
                <?php elseif ($isRecommend): ?>
                <div class="bg-emerald-500 px-5 pt-5 pb-4">
                <?php else: ?>
                <div class="bg-red-500 px-5 pt-5 pb-4">
                <?php endif; ?>

                    <!-- Top row: icon + badges -->
                    <div class="flex items-center justify-between mb-3">
                        <!-- Status icon box -->
                        <div class="w-10 h-10 bg-white/25 rounded-xl flex items-center justify-center">
                            <?php if ($isPending): ?>
                                <i data-lucide="clock" class="w-5 h-5 text-white"></i>
                            <?php elseif ($isRecommend): ?>
                                <i data-lucide="check-circle" class="w-5 h-5 text-white"></i>
                            <?php else: ?>
                                <i data-lucide="x-circle" class="w-5 h-5 text-white"></i>
                            <?php endif; ?>
                        </div>

                        <!-- Right badges -->
                        <div class="flex items-center gap-2">
                            <!-- Report button (only if assessed) -->
                            <?php if ($s['assessment_id']): ?>
                            <a href="report.php?id=<?= $s['id'] ?>" target="_blank" rel="noopener noreferrer"
                               class="w-8 h-8 bg-white/20 hover:bg-white/35 text-white rounded-lg flex items-center justify-center transition-all"
                               title="Lihat Laporan">
                                <i data-lucide="file-text" class="w-4 h-4"></i>
                            </a>
                            <?php endif; ?>
                            <!-- ID badge -->
                            <span class="px-2.5 py-1 bg-slate-900 text-white rounded-lg text-[10px] font-bold">#<?= $s['id'] ?></span>
                        </div>
                    </div>

                    <!-- Grade badge + Name + School -->
                    <span class="inline-block text-[10px] font-black uppercase tracking-widest px-2.5 py-0.5 bg-white/20 text-white rounded-full mb-1.5">
                        <?= htmlspecialchars($s['grade']) ?>
                    </span>
                    <h3 class="text-base font-black text-white leading-tight line-clamp-1">
                        <?= hl($s['name'], $search) ?>
                    </h3>
                    <p class="text-white/75 text-[11px] font-medium mt-0.5 line-clamp-1">
                        <?= hl($s['school'], $search) ?>
                    </p>
                    
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <?php if($s['interview_time']): ?>
                        <div class="flex items-center gap-1.5 px-2.5 py-1 bg-black/20 rounded-lg border border-white/5">
                            <i data-lucide="clock" class="w-3 h-3 text-blue-200"></i>
                            <span class="text-[9px] font-black text-white tracking-wide"><?= htmlspecialchars($s['interview_time']) ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <?php if($s['location']): ?>
                        <div class="flex items-center gap-1.5 px-2.5 py-1 bg-black/10 rounded-lg border border-white/5">
                            <i data-lucide="map-pin" class="w-3 h-3 text-emerald-300"></i>
                            <span class="text-[9px] font-bold text-white tracking-wide"><?= htmlspecialchars($s['location']) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ── CARD BODY (putih, konten skor / status tunggu) ── -->
                <div class="p-4 flex-1 flex flex-col gap-3">

                    <?php if ($s['assessment_id']): ?>
                    <!-- Skor Box -->
                    <div class="bg-slate-50 rounded-xl p-3 space-y-2 border border-slate-100">
                        <!-- Total -->
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wide flex items-center gap-1">
                                <i data-lucide="award" class="w-3.5 h-3.5 text-amber-400"></i> Total
                            </span>
                            <div class="flex items-baseline gap-0.5">
                                <span class="text-2xl font-black text-slate-900 leading-none"><?= $s['grand_total'] ?></span>
                                <span class="text-[10px] font-bold text-slate-400">/108</span>
                            </div>
                        </div>
                        <!-- Divider -->
                        <div class="h-px bg-slate-200"></div>
                        <!-- Breakdown -->
                        <div class="grid grid-cols-2 gap-2">
                            <div class="bg-blue-50 py-2 rounded-lg text-center border border-blue-100">
                                <p class="text-[9px] font-bold text-blue-400 uppercase tracking-wider mb-0.5">Anak</p>
                                <div class="flex items-baseline gap-0.5 justify-center">
                                    <span class="text-lg font-black text-blue-700"><?= $s['child_total'] ?></span>
                                    <span class="text-[10px] text-blue-400">/60</span>
                                </div>
                            </div>
                            <div class="bg-emerald-50 py-2 rounded-lg text-center border border-emerald-100">
                                <p class="text-[9px] font-bold text-emerald-500 uppercase tracking-wider mb-0.5">Ortu</p>
                                <div class="flex items-baseline gap-0.5 justify-center">
                                    <span class="text-lg font-black text-emerald-700"><?= $s['parent_total'] ?></span>
                                    <span class="text-[10px] text-emerald-400">/48</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Verdict badge -->
                    <?php if ($isRecommend): ?>
                    <div class="flex items-center gap-1.5 px-3 py-1.5 bg-emerald-50 border border-emerald-200 rounded-xl">
                        <i data-lucide="check-circle" class="w-3.5 h-3.5 text-emerald-500 shrink-0"></i>
                        <span class="text-[10px] font-black text-emerald-700 uppercase tracking-wide">Direkomendasikan</span>
                    </div>
                    <?php else: ?>
                    <div class="flex items-center gap-1.5 px-3 py-1.5 bg-red-50 border border-red-200 rounded-xl">
                        <i data-lucide="x-circle" class="w-3.5 h-3.5 text-red-500 shrink-0"></i>
                        <span class="text-[10px] font-black text-red-700 uppercase tracking-wide">Tidak Direkomendasikan</span>
                    </div>
                    <?php endif; ?>

                    <?php else: ?>
                    <!-- Pending placeholder -->
                    <div class="flex-1 flex flex-col items-center justify-center py-6 bg-amber-50 border border-dashed border-amber-200 rounded-xl gap-2">
                        <i data-lucide="clipboard-list" class="w-8 h-8 text-amber-300"></i>
                        <p class="text-[10px] font-black text-amber-500 uppercase tracking-widest">Menunggu Interview</p>
                    </div>
                    <?php endif; ?>

                </div>

                <!-- ── ACTION BUTTON ── -->
                <div class="px-4 pb-4">
                    <?php if ($isRecommend): ?>
                    <a href="interview.php?id=<?= $s['id'] ?>"
                       class="w-full py-2.5 bg-emerald-500 hover:bg-emerald-600 text-white rounded-xl text-xs font-bold flex items-center justify-center gap-2 transition-all active:scale-95 shadow-sm shadow-emerald-200">
                        <i data-lucide="pencil" class="w-3.5 h-3.5"></i> Edit Penilaian
                    </a>
                    <?php elseif ($isNotRecom): ?>
                    <a href="interview.php?id=<?= $s['id'] ?>"
                       class="w-full py-2.5 bg-red-500 hover:bg-red-600 text-white rounded-xl text-xs font-bold flex items-center justify-center gap-2 transition-all active:scale-95 shadow-sm shadow-red-200">
                        <i data-lucide="pencil" class="w-3.5 h-3.5"></i> Edit Penilaian
                    </a>
                    <?php else: ?>
                    <a href="interview.php?id=<?= $s['id'] ?>"
                       class="w-full py-2.5 bg-amber-400 hover:bg-amber-500 text-white rounded-xl text-xs font-bold flex items-center justify-center gap-2 transition-all active:scale-95 shadow-sm shadow-amber-200">
                        <i data-lucide="play" class="w-3.5 h-3.5"></i> Mulai Interview
                    </a>
                    <?php endif; ?>
                </div>

            </div>

            <?php endforeach; ?>

            <?php if(empty($students)): ?>
            <div class="col-span-full bg-white rounded-[32px] p-16 text-center border border-slate-100 shadow-sm">
                <div class="w-20 h-20 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-6">
                    <i data-lucide="<?= $search ? 'search-x' : 'calendar-x' ?>" class="w-10 h-10 text-slate-300"></i>
                </div>
                <h3 class="text-xl font-black text-slate-800 tracking-tight">Tidak ada siswa yang ditemukan</h3>
                <p class="text-slate-500 font-medium mt-2 max-w-sm mx-auto">
                    <?php if($date !== 'all'): ?>
                        Mungkin jadwal untuk tanggal <strong><?= $date ?></strong> belum tersedia atau sudah selesai.
                    <?php else: ?>
                        Belum ada siswa yang di-plot untuk Anda saat ini.
                    <?php endif; ?>
                </p>
                
                <?php if($date !== 'all' || $search || $status): ?>
                <div class="flex justify-center gap-3 mt-8">
                    <a href="asesor.php?date=all" class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-black rounded-2xl text-xs transition-all shadow-lg shadow-blue-500/20 active:scale-95 flex items-center gap-2">
                        <i data-lucide="list" class="w-4 h-4"></i> Lihat Semua Siswa Saya
                    </a>
                </div>
                <?php endif; ?>
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
