<?php
// interview.php - UX Enhanced: Accessibility + Validation + Security
require 'config.php';
require 'auth.php';
checkRole(['admin', 'asesor']);

$student_id = (int)($_GET['id'] ?? 0);
$role       = $_SESSION['user']['role'];
$backUrl    = ($role === 'admin') ? 'admin.php' : 'asesor.php';

// Friendly redirect jika ID tidak ada
if (!$student_id) {
    header("Location: $backUrl?error=missing_id");
    exit();
}

$stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
$stmt->execute([$student_id]);
$s = $stmt->fetch();

// Friendly redirect jika siswa tidak ditemukan
if (!$s) {
    header("Location: $backUrl?error=not_found");
    exit();
}

// Otorisasi asesor: hanya boleh akses siswa yang ditetapkan untuknya
if ($role === 'asesor' && (int)$s['user_id'] !== (int)$_SESSION['user']['id']) {
    header("Location: asesor.php?error=unauthorized");
    exit();
}

$stmt = $pdo->prepare("SELECT * FROM assessments WHERE student_id = ?");
$stmt->execute([$student_id]);
$assessment = $stmt->fetch();

$scores = [];
if ($assessment) {
    $stmt = $pdo->prepare("SELECT question_id, score FROM assessment_answers WHERE assessment_id = ?");
    $stmt->execute([$assessment['id']]);
    $scores = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Label skala skor
$scoreLabels = ['Tidak Ada', 'Kurang', 'Cukup', 'Baik', 'Sangat Baik'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="favicon.png">
    <title>Interview — <?= htmlspecialchars($s['name']) ?> | PAABS</title>
    <meta name="description" content="Formulir wawancara terstruktur calon anak asuh PAABS">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="style.css?v=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest" defer></script>
    <style>
        body { font-family: 'Nunito', sans-serif; }
        :focus-visible { outline: 2px solid #3b82f6; outline-offset: 2px; border-radius: 6px; }
        .question-error { ring: 2px; --tw-ring-color: #f87171; }
        .score-legend span { font-size: 10px; line-height: 1.2; }

        /* ── Auto-save indicator ─────────────────────────── */
        #autosaveBar {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 20px;
            transition: all 0.3s ease;
            white-space: nowrap;
        }
        #autosaveBar.state-idle    { background: #f1f5f9; color: #94a3b8; }
        #autosaveBar.state-saving  { background: #eff6ff; color: #3b82f6; }
        #autosaveBar.state-saved   { background: #f0fdf4; color: #16a34a; }
        #autosaveBar.state-error   { background: #fef2f2; color: #dc2626; }
        #autosaveBar .dot {
            width: 7px; height: 7px;
            border-radius: 50%;
            background: currentColor;
            flex-shrink: 0;
        }
        #autosaveBar.state-saving .dot {
            animation: pulse-dot 1s ease-in-out infinite;
        }
        @keyframes pulse-dot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50%       { opacity: 0.4; transform: scale(0.7); }
        }

        /* Animasi card berhasil disimpan */
        .card-saved-flash {
            animation: saved-flash 0.6s ease;
        }
        @keyframes saved-flash {
            0%   { box-shadow: 0 0 0 0 rgba(34,197,94,0.5); }
            50%  { box-shadow: 0 0 0 6px rgba(34,197,94,0.15); }
            100% { box-shadow: 0 0 0 0 rgba(34,197,94,0); }
        }

        /* Session expired overlay */
        #sessionExpiredOverlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,0.85);
            backdrop-filter: blur(6px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }
        #sessionExpiredOverlay.show { display: flex; }
    </style>
</head>
<body class="bg-slate-50 antialiased min-h-screen pb-32 text-slate-600">

    <!-- Full Width Sticky Header -->
    <header class="sticky top-0 z-50 bg-white/95 backdrop-blur-xl border-b border-slate-200 shadow-sm px-4 py-4 md:px-8 md:py-5" role="banner">
        <div class="max-w-4xl mx-auto flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <div class="flex items-center gap-4 w-full md:w-auto">
                <a href="<?= $backUrl ?>"
                   aria-label="Kembali ke daftar siswa"
                   class="p-2.5 bg-slate-100 hover:bg-slate-200 rounded-2xl transition-all text-slate-500 shrink-0 flex items-center gap-2 focus-visible:ring-2 focus-visible:ring-blue-500">
                    <i data-lucide="arrow-left" class="w-5 h-5" aria-hidden="true"></i>
                    <span class="hidden sm:inline text-xs font-bold text-slate-600">Kembali</span>
                </a>
                <div class="flex-1">
                    <h1 class="text-lg md:text-xl font-black text-slate-800 tracking-tight leading-none">
                        <?= htmlspecialchars($s['name']) ?>
                    </h1>
                    <p class="text-xs font-semibold text-slate-400 mt-1">
                        Wawancara Terstruktur ·
                        <span class="text-blue-600"><?= htmlspecialchars($s['grade']) ?></span> ·
                        <?= htmlspecialchars($s['school']) ?>
                    </p>
                </div>
                <!-- Auto-save indicator -->
                <div id="autosaveBar" class="state-idle hidden md:flex" aria-live="polite" aria-label="Status penyimpanan otomatis">
                    <span class="dot"></span>
                    <span id="autosaveText">Siap</span>
                </div>
                <!-- Save button mobile -->
                <button type="button"
                        id="saveBtnMobile"
                        onclick="handleSubmit(event)"
                        class="md:hidden px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold text-xs shadow-md transition-all active:scale-95 flex items-center gap-1.5"
                        aria-label="Simpan penilaian">
                    <i data-lucide="save" class="w-3.5 h-3.5" aria-hidden="true"></i>
                    Simpan
                </button>
            </div>

            <div class="flex items-center justify-between w-full md:w-auto gap-6 mt-2 md:mt-0">
                <div class="flex-1 md:text-right space-y-1">
                    <div class="flex md:justify-end items-end gap-1.5">
                        <span class="text-xs font-bold text-slate-400">Skor:</span>
                        <span id="totalDisplay" class="text-xl font-black text-slate-800 leading-none" aria-live="polite" aria-label="Total skor">
                            0 <span class="text-xs font-bold text-slate-300">/108</span>
                        </span>
                        <span id="progressText" class="text-xs font-bold text-slate-400 ml-4 hidden md:inline-block" aria-live="polite">0 / 27 pertanyaan</span>
                    </div>
                    <div class="w-full md:w-48 bg-slate-100 rounded-full h-1.5 md:mt-1 md:ml-auto" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" aria-label="Progress pengisian formulir">
                        <div id="progressBar" class="bg-blue-600 h-1.5 rounded-full transition-all duration-300" style="width: 0%"></div>
                    </div>
                </div>
                <button type="button"
                        id="saveBtnDesktop"
                        onclick="handleSubmit(event)"
                        class="hidden md:flex px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-2xl font-bold text-xs shadow-lg shadow-blue-200 active:scale-95 transition-all items-center gap-2 focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-blue-500"
                        aria-label="Simpan hasil penilaian">
                    <i data-lucide="save" class="w-4 h-4" aria-hidden="true"></i>
                    Simpan Penilaian
                </button>
            </div>
        </div>
    </header>

    <!-- Session Expired Overlay -->
    <div id="sessionExpiredOverlay" role="alertdialog" aria-modal="true" aria-labelledby="sessionExpiredTitle">
        <div class="bg-white rounded-3xl p-8 max-w-sm w-full mx-4 text-center shadow-2xl">
            <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i data-lucide="lock" class="w-8 h-8 text-red-500"></i>
            </div>
            <h2 id="sessionExpiredTitle" class="text-lg font-black text-slate-800 mb-2">Sesi Berakhir</h2>
            <p class="text-sm text-slate-500 font-medium mb-6">Sesi Anda telah berakhir. Jawaban yang sudah Anda pilih <strong class="text-slate-700">telah tersimpan otomatis</strong>. Silakan login kembali untuk melanjutkan.</p>
            <a href="index.php" class="block w-full py-3 px-6 bg-blue-600 hover:bg-blue-700 text-white font-black rounded-2xl text-sm transition-all active:scale-95">
                Login Kembali
            </a>
        </div>
    </div>

    <!-- Error Banner (jika ada query error) -->
    <?php if (isset($_GET['error'])): ?>
    <div class="max-w-4xl mx-auto mt-4 px-4">
        <div class="bg-red-50 border border-red-200 rounded-2xl px-5 py-4 flex items-center gap-3 text-red-700" role="alert">
            <i data-lucide="alert-circle" class="w-5 h-5 shrink-0" aria-hidden="true"></i>
            <p class="text-sm font-semibold">
                <?php
                $msgs = [
                    'missing_id'   => 'ID siswa tidak ditemukan. Silakan kembali ke daftar.',
                    'not_found'    => 'Siswa tidak ditemukan di database.',
                    'unauthorized' => 'Anda tidak memiliki akses ke data siswa ini.',
                ];
                echo $msgs[$_GET['error']] ?? 'Terjadi kesalahan. Silakan coba lagi.';
                ?>
            </p>
        </div>
    </div>
    <?php endif; ?>

    <div class="max-w-4xl mx-auto p-4 md:p-8">

        <!-- Info Card Siswa -->
        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-5 mb-8 flex items-center gap-4">
            <div class="w-12 h-12 bg-blue-100 rounded-2xl flex items-center justify-center shrink-0">
                <i data-lucide="user" class="w-6 h-6 text-blue-600" aria-hidden="true"></i>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-xs font-bold text-slate-400 uppercase tracking-wide">Data Kandidat</p>
                <p class="text-sm font-black text-slate-800 mt-0.5"><?= htmlspecialchars($s['name']) ?></p>
                <p class="text-xs text-slate-500 font-medium truncate"><?= htmlspecialchars($s['school']) ?></p>
            </div>
            <?php if ($assessment): ?>
            <div class="shrink-0 px-3 py-1.5 bg-emerald-100 text-emerald-700 rounded-xl text-xs font-bold flex items-center gap-1.5">
                <i data-lucide="check-circle" class="w-3.5 h-3.5" aria-hidden="true"></i>
                Sudah Dinilai
            </div>
            <?php else: ?>
            <div class="shrink-0 px-3 py-1.5 bg-amber-100 text-amber-700 rounded-xl text-xs font-bold flex items-center gap-1.5">
                <i data-lucide="clock" class="w-3.5 h-3.5" aria-hidden="true"></i>
                Belum Dinilai
            </div>
            <?php endif; ?>
        </div>

        <!-- Legenda Skala Skor -->
        <div class="bg-slate-800 text-white rounded-2xl px-5 py-3.5 mb-8 flex flex-wrap items-center gap-x-6 gap-y-2">
            <span class="text-xs font-bold text-slate-300 uppercase tracking-wide shrink-0">Skala Penilaian:</span>
            <?php foreach ($scoreLabels as $i => $label): ?>
            <div class="flex items-center gap-2">
                <span class="w-6 h-6 bg-slate-700 rounded-lg flex items-center justify-center text-xs font-black text-white"><?= $i ?></span>
                <span class="text-xs font-semibold text-slate-300"><?= $label ?></span>
            </div>
            <?php endforeach; ?>
        </div>

        <form id="interviewForm" action="save.php" method="POST" class="space-y-10" novalidate>
            <input type="hidden" name="student_id" value="<?= $student_id ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <!-- ═══════════════════════════════════════════════════ -->
            <!-- INSTRUMEN 1: ANAK ASUH -->
            <!-- ═══════════════════════════════════════════════════ -->
            <section aria-labelledby="instrumen1-heading">
                <div class="flex items-center gap-3 px-2 mb-8">
                    <div class="w-12 h-12 bg-blue-600 text-white rounded-2xl flex items-center justify-center shadow-lg shadow-blue-100" aria-hidden="true">
                        <i data-lucide="user" class="w-6 h-6"></i>
                    </div>
                    <div>
                        <h2 id="instrumen1-heading" class="text-lg font-bold tracking-tight text-slate-800">Instrumen 1</h2>
                        <p class="text-xs font-medium text-slate-400 mt-0.5">Penilaian Calon Anak Asuh · 15 pertanyaan · Skor maks. 60</p>
                    </div>
                </div>

                <?php foreach ($childSections as $sectionName => $qList): ?>
                <div class="space-y-4 mb-8">
                    <div class="flex items-center gap-3 md:ml-4 mb-2">
                        <div class="h-5 w-1 bg-blue-500 rounded-full" aria-hidden="true"></div>
                        <h3 class="text-xs font-black text-slate-700 uppercase tracking-widest"><?= htmlspecialchars($sectionName) ?></h3>
                    </div>
                    <?php foreach ($qList as $q): ?>
                    <div id="card-<?= $q['id'] ?>" class="bg-white rounded-3xl p-5 md:p-7 border border-slate-200 shadow-sm space-y-5 transition-all duration-200">
                        <div class="flex gap-3">
                            <span class="text-blue-600 font-black text-sm shrink-0 leading-relaxed pt-0.5"><?= str_replace('c','', $q['id']) ?>.</span>
                            <h4 class="text-sm font-semibold text-slate-700 leading-relaxed"><?= htmlspecialchars($q['text']) ?></h4>
                        </div>
                        <fieldset>
                            <legend class="sr-only">Pilih skor untuk: <?= htmlspecialchars($q['text']) ?></legend>
                            <div class="grid grid-cols-5 gap-2" role="radiogroup">
                                <?php for($v = 0; $v <= 4; $v++): ?>
                                <label class="cursor-pointer group flex-1" title="<?= $scoreLabels[$v] ?>">
                                    <input type="radio"
                                           name="scores[<?= $q['id'] ?>]"
                                           value="<?= $v ?>"
                                           aria-label="Skor <?= $v ?> — <?= $scoreLabels[$v] ?>"
                                           class="sr-only peer score-input"
                                           <?= (isset($scores[$q['id']]) && $scores[$q['id']] == $v) ? 'checked' : '' ?>>
                                    <div class="h-12 md:h-14 flex flex-col items-center justify-center rounded-2xl bg-slate-50 border border-slate-200 text-slate-400
                                                peer-checked:bg-blue-600 peer-checked:text-white peer-checked:border-blue-600 peer-checked:shadow-lg peer-checked:shadow-blue-200
                                                transition-all group-hover:bg-blue-50 group-hover:border-blue-200
                                                peer-focus-visible:ring-2 peer-focus-visible:ring-blue-400 peer-focus-visible:ring-offset-1">
                                        <span class="font-black text-lg leading-none"><?= $v ?></span>
                                        <span class="score-legend mt-0.5 hidden md:block text-center px-1 leading-tight">
                                            <span class="text-[9px] font-semibold opacity-70 peer-checked:opacity-100"><?= $scoreLabels[$v] ?></span>
                                        </span>
                                    </div>
                                </label>
                                <?php endfor; ?>
                            </div>
                            <!-- Label skala di mobile (compact) -->
                            <div class="flex justify-between mt-1.5 px-1 md:hidden" aria-hidden="true">
                                <span class="text-[9px] text-slate-400 font-semibold"><?= $scoreLabels[0] ?></span>
                                <span class="text-[9px] text-slate-400 font-semibold"><?= $scoreLabels[4] ?></span>
                            </div>
                        </fieldset>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>

                <!-- CHILD NOTES -->
                <div class="bg-blue-50 p-6 md:p-8 rounded-3xl border-2 border-blue-200 space-y-3 mt-2">
                    <label for="child_notes" class="text-sm font-bold text-blue-700 flex items-center gap-2">
                        <i data-lucide="message-square" class="w-4 h-4" aria-hidden="true"></i>
                        Catatan Interview Anak Asuh
                        <span class="text-red-500" aria-label="wajib diisi">*</span>
                    </label>
                    <p class="text-xs text-blue-500 font-medium -mt-1">Wajib diisi: tuliskan kesimpulan dan kesan dari hasil interview calon anak asuh.</p>
                    <textarea id="child_notes"
                              name="child_notes"
                              required
                              minlength="10"
                              maxlength="1000"
                              class="w-full bg-white border border-blue-200 rounded-2xl p-5 text-slate-800 text-sm focus:ring-4 focus:ring-blue-100 focus:border-blue-500 outline-none min-h-[140px] transition-all font-medium leading-relaxed resize-y"
                              placeholder="Contoh: Kandidat menunjukkan motivasi yang tinggi dan memiliki visi yang jelas untuk masa depan..."
                              aria-required="true"><?= htmlspecialchars($assessment['child_notes'] ?? '') ?></textarea>
                    <div class="flex justify-end">
                        <span id="childNotesCount" class="text-xs text-slate-400 font-medium" aria-live="polite">
                            <?= strlen($assessment['child_notes'] ?? '') ?>/1000 karakter
                        </span>
                    </div>
                </div>
            </section>


            <!-- ═══════════════════════════════════════════════════ -->
            <!-- INSTRUMEN 2: ORANGTUA -->
            <!-- ═══════════════════════════════════════════════════ -->
            <section aria-labelledby="instrumen2-heading" class="pt-4 border-t border-slate-200">
                <div class="flex items-center gap-3 px-2 mb-8 mt-6">
                    <div class="w-12 h-12 bg-emerald-600 text-white rounded-2xl flex items-center justify-center shadow-lg shadow-emerald-100" aria-hidden="true">
                        <i data-lucide="users" class="w-6 h-6"></i>
                    </div>
                    <div>
                        <h2 id="instrumen2-heading" class="text-lg font-bold tracking-tight text-slate-800">Instrumen 2</h2>
                        <p class="text-xs font-medium text-slate-400 mt-0.5">Penilaian Orangtua · 12 pertanyaan · Skor maks. 48</p>
                    </div>
                </div>

                <?php foreach ($parentSections as $sectionName => $qList): ?>
                <div class="space-y-4 mb-8">
                    <div class="flex items-center gap-3 md:ml-4 mb-2">
                        <div class="h-5 w-1 bg-emerald-500 rounded-full" aria-hidden="true"></div>
                        <h3 class="text-xs font-black text-slate-700 uppercase tracking-widest"><?= htmlspecialchars($sectionName) ?></h3>
                    </div>
                    <?php foreach ($qList as $q): ?>
                    <div id="card-<?= $q['id'] ?>" class="bg-white rounded-3xl p-5 md:p-7 border border-slate-200 shadow-sm space-y-5 transition-all duration-200">
                        <div class="flex gap-3">
                            <span class="text-emerald-600 font-black text-sm shrink-0 leading-relaxed pt-0.5"><?= str_replace('p','', $q['id']) ?>.</span>
                            <h4 class="text-sm font-semibold text-slate-700 leading-relaxed"><?= htmlspecialchars($q['text']) ?></h4>
                        </div>
                        <fieldset>
                            <legend class="sr-only">Pilih skor untuk: <?= htmlspecialchars($q['text']) ?></legend>
                            <div class="grid grid-cols-5 gap-2" role="radiogroup">
                                <?php for($v = 0; $v <= 4; $v++): ?>
                                <label class="cursor-pointer group flex-1" title="<?= $scoreLabels[$v] ?>">
                                    <input type="radio"
                                           name="scores[<?= $q['id'] ?>]"
                                           value="<?= $v ?>"
                                           aria-label="Skor <?= $v ?> — <?= $scoreLabels[$v] ?>"
                                           class="sr-only peer score-input"
                                           <?= (isset($scores[$q['id']]) && $scores[$q['id']] == $v) ? 'checked' : '' ?>>
                                    <div class="h-12 md:h-14 flex flex-col items-center justify-center rounded-2xl bg-slate-50 border border-slate-200 text-slate-400
                                                peer-checked:bg-emerald-600 peer-checked:text-white peer-checked:border-emerald-600 peer-checked:shadow-lg peer-checked:shadow-emerald-200
                                                transition-all group-hover:bg-emerald-50 group-hover:border-emerald-200
                                                peer-focus-visible:ring-2 peer-focus-visible:ring-emerald-400 peer-focus-visible:ring-offset-1">
                                        <span class="font-black text-lg leading-none"><?= $v ?></span>
                                        <span class="score-legend mt-0.5 hidden md:block text-center px-1 leading-tight">
                                            <span class="text-[9px] font-semibold opacity-70"><?= $scoreLabels[$v] ?></span>
                                        </span>
                                    </div>
                                </label>
                                <?php endfor; ?>
                            </div>
                            <div class="flex justify-between mt-1.5 px-1 md:hidden" aria-hidden="true">
                                <span class="text-[9px] text-slate-400 font-semibold"><?= $scoreLabels[0] ?></span>
                                <span class="text-[9px] text-slate-400 font-semibold"><?= $scoreLabels[4] ?></span>
                            </div>
                        </fieldset>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>

                <!-- PARENT NOTES -->
                <div class="bg-emerald-50 p-6 md:p-8 rounded-3xl border-2 border-emerald-200 space-y-3 mt-2">
                    <label for="parent_notes" class="text-sm font-bold text-emerald-700 flex items-center gap-2">
                        <i data-lucide="message-square" class="w-4 h-4" aria-hidden="true"></i>
                        Catatan Interview Orangtua
                        <span class="text-red-500" aria-label="wajib diisi">*</span>
                    </label>
                    <p class="text-xs text-emerald-600 font-medium -mt-1">Wajib diisi: tuliskan catatan etika, komitmen, dan faktor penting lainnya dari orangtua.</p>
                    <textarea id="parent_notes"
                              name="parent_notes"
                              required
                              minlength="10"
                              maxlength="1000"
                              class="w-full bg-white border border-emerald-200 rounded-2xl p-5 text-slate-800 text-sm focus:ring-4 focus:ring-emerald-100 focus:border-emerald-500 outline-none min-h-[140px] transition-all font-medium leading-relaxed resize-y"
                              placeholder="Contoh: Orangtua menunjukkan komitmen yang kuat, aktif dalam proses pendampingan anak..."
                              aria-required="true"><?= htmlspecialchars($assessment['parent_notes'] ?? '') ?></textarea>
                    <div class="flex justify-end">
                        <span id="parentNotesCount" class="text-xs text-slate-400 font-medium" aria-live="polite">
                            <?= strlen($assessment['parent_notes'] ?? '') ?>/1000 karakter
                        </span>
                    </div>
                </div>
            </section>

            <!-- ═══════════════════════════════════════════════════ -->
            <!-- REKOMENDASI AKHIR -->
            <!-- ═══════════════════════════════════════════════════ -->
            <section aria-labelledby="rekomen-heading" class="pt-4">
                <div class="bg-white p-6 md:p-8 rounded-3xl shadow-sm border border-slate-200 space-y-6">
                    <div>
                        <h2 id="rekomen-heading" class="text-base font-bold text-slate-800 flex items-center gap-2">
                            <i data-lucide="check-circle" class="w-4 h-4 text-blue-600" aria-hidden="true"></i>
                            Keputusan Rekomendasi
                        </h2>
                        <p class="text-xs text-slate-400 font-medium mt-1">Berdasarkan akumulasi nilai anak asuh & orangtua — pilih satu keputusan akhir.</p>
                    </div>
                    <fieldset>
                        <legend class="sr-only">Pilih keputusan rekomendasi akhir</legend>
                        <div class="flex gap-4">
                            <label class="flex-1 cursor-pointer group">
                                <input type="radio" name="is_recommended" value="1" class="sr-only peer" required
                                       aria-label="Rekomendasikan kandidat ini"
                                       <?= (isset($assessment['is_recommended']) && $assessment['is_recommended'] == 1) ? 'checked' : '' ?>>
                                <div class="p-5 rounded-2xl border-2 border-slate-200 bg-slate-50
                                            peer-checked:bg-blue-600 peer-checked:text-white peer-checked:border-blue-600
                                            peer-focus-visible:ring-2 peer-focus-visible:ring-blue-400
                                            text-center font-bold transition-all text-sm shadow-sm flex flex-col items-center gap-2">
                                    <i data-lucide="thumbs-up" class="w-6 h-6" aria-hidden="true"></i>
                                    R — Rekomendasikan
                                </div>
                            </label>
                            <label class="flex-1 cursor-pointer group">
                                <input type="radio" name="is_recommended" value="0" class="sr-only peer" required
                                       aria-label="Tidak merekomendasikan kandidat ini"
                                       <?= (isset($assessment['is_recommended']) && $assessment['is_recommended'] == 0) ? 'checked' : '' ?>>
                                <div class="p-5 rounded-2xl border-2 border-slate-200 bg-slate-50
                                            peer-checked:bg-red-600 peer-checked:text-white peer-checked:border-red-600
                                            peer-focus-visible:ring-2 peer-focus-visible:ring-red-400
                                            text-center font-bold transition-all text-sm shadow-sm flex flex-col items-center gap-2">
                                    <i data-lucide="thumbs-down" class="w-6 h-6" aria-hidden="true"></i>
                                    TR — Tidak Rekomendasikan
                                </div>
                            </label>
                        </div>
                    </fieldset>
                </div>
            </section>
        </form>

        <footer class="text-center py-10 text-slate-400 text-xs font-medium">
            Yayasan Sultan Iskandar Muda &copy; <?= date('Y') ?> &mdash; PAABS v1.0 | Abdul Muis, S.T., M.Kom.
        </footer>
    </div>

    <!-- Error Toast (Validasi) -->
    <div id="errorToast"
         class="fixed bottom-6 left-1/2 -translate-x-1/2 z-[999] bg-red-600 text-white px-6 py-3.5 rounded-2xl shadow-2xl flex items-center gap-3 opacity-0 pointer-events-none transition-all duration-300"
         role="alert"
         aria-live="assertive">
        <i data-lucide="alert-circle" class="w-5 h-5 shrink-0" aria-hidden="true"></i>
        <span id="errorToastMsg" class="text-sm font-bold"></span>
    </div>

    <script>
        // ── Init Icons ──────────────────────────────────────────────
        document.addEventListener('DOMContentLoaded', () => {
            if (typeof lucide !== 'undefined') lucide.createIcons();
        });

        const TOTAL_QUESTIONS = 27; // 15 anak + 12 orangtua
        const STUDENT_ID      = <?= (int)$student_id ?>;
        let formDirty         = false;
        let saveQueue         = Promise.resolve(); // Antrian save serial
        let notesDebounceTimer = {};

        // ── Auto-save Indicator ──────────────────────────────────────
        const autosaveBar  = document.getElementById('autosaveBar');
        const autosaveText = document.getElementById('autosaveText');

        function setAutoSaveState(state, msg) {
            if (!autosaveBar) return;
            autosaveBar.classList.remove('state-idle','state-saving','state-saved','state-error','hidden');
            autosaveBar.classList.add('state-' + state);
            autosaveText.textContent = msg;
        }

        // ── Auto-Save: kirim 1 skor ke autosave.php ──────────────────
        function autoSaveScore(questionId, score, cardEl) {
            setAutoSaveState('saving', 'Menyimpan...');

            const body = new URLSearchParams({
                student_id:  STUDENT_ID,
                question_id: questionId,
                score:       score
            });

            saveQueue = saveQueue.then(() =>
                fetch('autosave.php', { method: 'POST', body, credentials: 'same-origin' })
                    .then(r => {
                        if (r.status === 401) { showSessionExpired(); return; }
                        return r.json();
                    })
                    .then(data => {
                        if (!data) return;
                        if (data.ok) {
                            setAutoSaveState('saved', '✓ Tersimpan');
                            if (cardEl) {
                                cardEl.classList.add('card-saved-flash');
                                cardEl.addEventListener('animationend', () =>
                                    cardEl.classList.remove('card-saved-flash'), { once: true });
                            }
                            // Update skor total dari server response
                            if (data.grandTotal !== undefined) {
                                document.getElementById('totalDisplay').innerHTML =
                                    data.grandTotal + ' <span class="text-xs font-bold text-slate-300">/108</span>';
                            }
                            // Kembali ke idle setelah 2.5 detik
                            setTimeout(() => setAutoSaveState('idle', 'Siap'), 2500);
                        } else {
                            setAutoSaveState('error', '✗ Gagal menyimpan');
                        }
                    })
                    .catch(() => setAutoSaveState('error', '✗ Koneksi gagal'))
            );
        }

        // ── Auto-Save: notes (debounce 1.5 detik) ────────────────────
        function autoSaveNotes(fieldName, value) {
            clearTimeout(notesDebounceTimer[fieldName]);
            setAutoSaveState('saving', 'Menyimpan catatan...');

            notesDebounceTimer[fieldName] = setTimeout(() => {
                const body = new URLSearchParams({
                    student_id: STUDENT_ID,
                    field:      fieldName,
                    value:      value
                });

                fetch('autosave.php', { method: 'POST', body, credentials: 'same-origin' })
                    .then(r => {
                        if (r.status === 401) { showSessionExpired(); return; }
                        return r.json();
                    })
                    .then(data => {
                        if (!data) return;
                        if (data.ok) {
                            setAutoSaveState('saved', '✓ Catatan tersimpan');
                            setTimeout(() => setAutoSaveState('idle', 'Siap'), 2500);
                        } else {
                            setAutoSaveState('error', '✗ Gagal menyimpan catatan');
                        }
                    })
                    .catch(() => setAutoSaveState('error', '✗ Koneksi gagal'));
            }, 1500);
        }

        // ── Session Expired Handler ──────────────────────────────────
        function showSessionExpired() {
            const overlay = document.getElementById('sessionExpiredOverlay');
            if (overlay) {
                overlay.classList.add('show');
                // Init icon di dalam overlay
                if (typeof lucide !== 'undefined') lucide.createIcons();
                formDirty = false; // Prevent beforeunload
            }
        }

        // ── Heartbeat Ping (setiap 4 menit) ──────────────────────────
        function pingSession() {
            fetch('ping.php', { method: 'GET', credentials: 'same-origin', cache: 'no-store' })
                .then(r => {
                    if (r.status === 401) showSessionExpired();
                })
                .catch(() => { /* Ignore network blip, akan coba lagi */ });
        }
        // Ping pertama setelah 1 menit, lalu setiap 4 menit
        setTimeout(() => {
            pingSession();
            setInterval(pingSession, 4 * 60 * 1000);
        }, 60 * 1000);


        // ── Progress & Total Score ──────────────────────────────────
        function updateStats() {
            let total = 0;
            const answered = new Set();

            document.querySelectorAll('.score-input:checked').forEach(input => {
                total += parseInt(input.value);
                answered.add(input.name);
            });

            const answeredCount = answered.size;
            const pct = Math.round(answeredCount / TOTAL_QUESTIONS * 100);

            document.getElementById('totalDisplay').innerHTML =
                total + ' <span class="text-xs font-bold text-slate-300">/108</span>';
            document.getElementById('progressText').textContent =
                answeredCount + ' / ' + TOTAL_QUESTIONS + ' pertanyaan';

            const bar = document.getElementById('progressBar');
            bar.style.width = pct + '%';
            bar.parentElement.setAttribute('aria-valuenow', pct);

            // Warna bar: biru → hijau saat selesai
            bar.className = 'h-1.5 rounded-full transition-all duration-300 ' +
                (answeredCount === TOTAL_QUESTIONS ? 'bg-emerald-500' : 'bg-blue-600');
        }

        document.querySelectorAll('.score-input').forEach(input => {
            input.addEventListener('change', () => {
                updateStats();
                formDirty = true;

                // Hapus highlight error jika sudah dipilih
                const qId = input.name.replace('scores[','').replace(']','');
                const card = document.getElementById('card-' + qId);
                if (card) card.classList.remove('ring-2', 'ring-red-400', 'bg-red-50');

                // ── AUTO-SAVE: kirim langsung ke server ──
                autoSaveScore(qId, input.value, card);
            });
        });

        // Textarea: counter + auto-save notes (debounce)
        ['child_notes', 'parent_notes'].forEach(id => {
            const el = document.getElementById(id);
            const countEl = document.getElementById(id === 'child_notes' ? 'childNotesCount' : 'parentNotesCount');
            if (el && countEl) {
                el.addEventListener('input', () => {
                    formDirty = true;
                    countEl.textContent = el.value.length + '/1000 karakter';
                    countEl.className = 'text-xs font-medium ' +
                        (el.value.length >= 950 ? 'text-red-500' : 'text-slate-400');

                    // ── AUTO-SAVE notes (debounce 1.5 detik) ──
                    autoSaveNotes(id, el.value);
                });
            }
        });

        updateStats();

        // ── Show Error Toast ────────────────────────────────────────
        function showErrorToast(msg) {
            const toast = document.getElementById('errorToast');
            document.getElementById('errorToastMsg').textContent = msg;
            toast.classList.remove('opacity-0', 'pointer-events-none');
            toast.classList.add('opacity-100');
            setTimeout(() => {
                toast.classList.add('opacity-0', 'pointer-events-none');
                toast.classList.remove('opacity-100');
            }, 4000);
        }

        // ── Pre-Submit Validation ───────────────────────────────────
        function handleSubmit(e) {
            // Kumpulkan semua nama pertanyaan unik
            const allNames = new Set();
            document.querySelectorAll('.score-input').forEach(i => allNames.add(i.name));

            // Cek yang belum dijawab
            const unanswered = [];
            allNames.forEach(name => {
                const checked = document.querySelector(`.score-input[name="${name}"]:checked`);
                if (!checked) unanswered.push(name);
            });

            // Cek catatan
            const childNotes  = document.getElementById('child_notes').value.trim();
            const parentNotes = document.getElementById('parent_notes').value.trim();
            const rekomen     = document.querySelector('input[name="is_recommended"]:checked');

            if (unanswered.length > 0) {
                // Highlight pertanyaan yang belum dijawab
                unanswered.forEach(name => {
                    const qId  = name.replace('scores[','').replace(']','');
                    const card = document.getElementById('card-' + qId);
                    if (card) {
                        card.classList.add('ring-2', 'ring-red-400', 'bg-red-50');
                    }
                });
                // Scroll ke pertanyaan pertama yang belum dijawab
                const firstQId = unanswered[0].replace('scores[','').replace(']','');
                const firstCard = document.getElementById('card-' + firstQId);
                if (firstCard) firstCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                showErrorToast(`${unanswered.length} pertanyaan belum dijawab — ditandai dengan border merah`);
                return;
            }

            if (childNotes.length < 10) {
                document.getElementById('child_notes').focus();
                showErrorToast('Catatan interview anak asuh wajib diisi (minimal 10 karakter)');
                return;
            }
            if (parentNotes.length < 10) {
                document.getElementById('parent_notes').focus();
                showErrorToast('Catatan interview orangtua wajib diisi (minimal 10 karakter)');
                return;
            }
            if (!rekomen) {
                document.querySelector('[aria-labelledby="rekomen-heading"]').scrollIntoView({ behavior: 'smooth' });
                showErrorToast('Pilih keputusan rekomendasi akhir (R atau TR)');
                return;
            }

            // Semua OK — disable buttons, tampilkan loading
            formDirty = false; // Cegah beforeunload
            const btns = [document.getElementById('saveBtnMobile'), document.getElementById('saveBtnDesktop')];
            btns.forEach(btn => {
                if (btn) {
                    btn.disabled = true;
                    btn.classList.add('opacity-60', 'cursor-not-allowed');
                    btn.innerHTML = '<svg class="animate-spin w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>Menyimpan...';
                }
            });

            document.getElementById('interviewForm').submit();
        }

        // ── beforeunload Guard ──────────────────────────────────────
        // Tandai form sebagai dirty saat ada perubahan awal
        document.getElementById('interviewForm').addEventListener('change', () => { formDirty = true; });
        document.getElementById('interviewForm').addEventListener('input', () => { formDirty = true; });

        window.addEventListener('beforeunload', (e) => {
            if (formDirty) {
                e.preventDefault();
                e.returnValue = 'Ada perubahan yang belum disimpan. Yakin ingin meninggalkan halaman?';
                return e.returnValue;
            }
        });

        // Klik link "Kembali" — bypass beforeunload setelah konfirmasi
        // Kecuali link di dalam overlay session expired
        document.querySelectorAll('a[href]').forEach(link => {
            link.addEventListener('click', (e) => {
                // Jangan hadang link di dalam session expired overlay
                if (link.closest('#sessionExpiredOverlay')) return;

                if (formDirty) {
                    const ok = confirm('Ada perubahan yang belum disimpan. Yakin ingin kembali?');
                    if (!ok) e.preventDefault();
                    else formDirty = false;
                }
            });
        });
    </script>
</body>
</html>
