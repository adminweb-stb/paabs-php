<?php
// change_password.php — Forced password change on first login
require 'config.php';
require 'auth.php';

// Hanya user yang sudah login yang bisa akses
if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit();
}

$user     = $_SESSION['user'];
$role     = $user['role'];
$dashUrl  = ($role === 'admin') ? 'admin.php' : 'asesor.php';

// Jika user sudah tidak perlu ganti password, redirect ke dashboard
// (triple-check langsung dari DB untuk keamanan)
$stmt = $pdo->prepare("SELECT must_change_password FROM users WHERE id = ?");
$stmt->execute([$user['id']]);
$row  = $stmt->fetch();

if (!$row || !$row['must_change_password']) {
    header("Location: $dashUrl");
    exit();
}

$error   = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPass     = $_POST['new_password']     ?? '';
    $confirmPass = $_POST['confirm_password'] ?? '';

    if (strlen($newPass) < 8) {
        $error = 'Password baru minimal 8 karakter.';
    } elseif (!preg_match('/[A-Za-z]/', $newPass) || !preg_match('/[0-9]/', $newPass)) {
        $error = 'Password harus mengandung minimal 1 huruf dan 1 angka.';
    } elseif ($newPass !== $confirmPass) {
        $error = 'Konfirmasi password tidak cocok.';
    } else {
        $hashed = password_hash($newPass, PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?")
            ->execute([$hashed, $user['id']]);

        // Update session agar flag terupdate
        $_SESSION['user']['must_change_password'] = 0;

        $success = true;
        // Redirect ke dashboard setelah 2 detik
        header("Refresh: 2; url=$dashUrl");
    }
}

// Ambil nama depan
$firstName = explode(' ', trim($user['name']))[0];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="favicon.png">
    <title>Wajib Ganti Password | PAABS</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="style.css?v=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest" defer></script>
    <style>
        body { font-family: 'Nunito', sans-serif; }
        :focus-visible { outline: 2px solid #3b82f6; outline-offset: 2px; border-radius: 6px; }
        /* Strength bar */
        #strength-bar { transition: width 0.3s ease, background-color 0.3s ease; }
    </style>
</head>
<body class="bg-gradient-to-br from-slate-900 via-blue-950 to-indigo-900 min-h-screen flex items-center justify-center p-4 antialiased">

    <!-- Decorative blobs -->
    <div class="absolute top-0 left-0 w-96 h-96 bg-blue-600/10 rounded-full blur-3xl pointer-events-none" aria-hidden="true"></div>
    <div class="absolute bottom-0 right-0 w-80 h-80 bg-indigo-500/10 rounded-full blur-3xl pointer-events-none" aria-hidden="true"></div>

    <div class="relative w-full max-w-md">

        <!-- Logo -->
        <div class="flex items-center justify-center gap-2 mb-8">
            <div class="w-10 h-10 bg-blue-600 rounded-2xl flex items-center justify-center shadow-lg shadow-blue-900">
                <i data-lucide="shield-check" class="w-5 h-5 text-white" aria-hidden="true"></i>
            </div>
            <span class="text-white font-black text-xl tracking-tight">PAABS <span class="text-blue-400">Portal</span></span>
        </div>

        <!-- Card -->
        <div class="bg-white rounded-3xl shadow-2xl overflow-hidden">

            <?php if ($success): ?>
            <!-- SUCCESS STATE -->
            <div class="p-8 text-center space-y-4">
                <div class="w-20 h-20 bg-emerald-100 rounded-full flex items-center justify-center mx-auto" aria-hidden="true">
                    <i data-lucide="check-circle" class="w-10 h-10 text-emerald-500"></i>
                </div>
                <h1 class="text-xl font-black text-slate-800">Password Berhasil Diperbarui!</h1>
                <p class="text-sm text-slate-500 font-medium">Akun Anda sekarang aman. Mengalihkan ke dashboard...</p>
                <div class="flex items-center justify-center gap-2 text-sm text-emerald-600 font-bold">
                    <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    Sedang mengalihkan...
                </div>
            </div>

            <?php else: ?>
            <!-- FORM STATE -->
            <!-- Header Banner -->
            <div class="bg-gradient-to-r from-amber-500 to-orange-500 px-6 py-5 flex items-start gap-4">
                <div class="w-10 h-10 bg-white/20 rounded-2xl flex items-center justify-center shrink-0 mt-0.5" aria-hidden="true">
                    <i data-lucide="key" class="w-5 h-5 text-white"></i>
                </div>
                <div>
                    <h1 class="text-white font-black text-base leading-tight">Halo, <?= htmlspecialchars($firstName) ?>! Satu langkah lagi 👋</h1>
                    <p class="text-amber-100 text-xs font-medium mt-1 leading-relaxed">
                        Demi keamanan akun, Anda <strong>wajib mengganti password default</strong> sebelum dapat menggunakan sistem.
                    </p>
                </div>
            </div>

            <div class="p-6 md:p-8 space-y-5">

                <!-- Info kenapa harus ganti -->
                <div class="bg-amber-50 border border-amber-200 rounded-2xl px-4 py-3 flex items-start gap-3">
                    <i data-lucide="info" class="w-4 h-4 text-amber-600 shrink-0 mt-0.5" aria-hidden="true"></i>
                    <p class="text-xs text-amber-700 font-medium leading-relaxed">
                        Akun Anda saat ini menggunakan <strong>password default</strong> yang diberikan admin.
                        Langkah ini hanya dilakukan <strong>sekali</strong> dan tidak bisa di-skip.
                    </p>
                </div>

                <?php if ($error): ?>
                <div class="bg-red-50 border border-red-200 rounded-2xl px-4 py-3 flex items-center gap-3" role="alert">
                    <i data-lucide="alert-circle" class="w-4 h-4 text-red-500 shrink-0" aria-hidden="true"></i>
                    <p class="text-sm text-red-700 font-semibold"><?= htmlspecialchars($error) ?></p>
                </div>
                <?php endif; ?>

                <form method="POST" id="changePassForm" class="space-y-5" novalidate>

                    <!-- Password Baru -->
                    <div>
                        <label for="new_password" class="block text-xs font-bold text-slate-600 mb-1.5">
                            Password Baru <span class="text-red-500" aria-label="wajib diisi">*</span>
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none" aria-hidden="true">
                                <i data-lucide="lock" class="w-4 h-4 text-slate-400"></i>
                            </div>
                            <input type="password"
                                   id="new_password"
                                   name="new_password"
                                   required
                                   minlength="8"
                                   autocomplete="new-password"
                                   class="w-full bg-slate-50 border border-slate-200 rounded-xl pl-10 pr-10 py-3 text-sm font-medium text-slate-800 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all"
                                   placeholder="Minimal 8 karakter"
                                   aria-describedby="pass-strength-label">
                            <button type="button"
                                    id="toggleNew"
                                    class="absolute inset-y-0 right-3 flex items-center text-slate-400 hover:text-slate-600"
                                    aria-label="Tampilkan/sembunyikan password baru">
                                <i data-lucide="eye" class="w-4 h-4" aria-hidden="true"></i>
                            </button>
                        </div>

                        <!-- Password Strength Bar -->
                        <div class="mt-2 space-y-1" id="strength-container" hidden>
                            <div class="w-full bg-slate-100 rounded-full h-1.5 overflow-hidden">
                                <div id="strength-bar" class="h-1.5 rounded-full w-0"></div>
                            </div>
                            <p id="pass-strength-label" class="text-xs font-bold text-slate-400" aria-live="polite"></p>
                        </div>

                        <p class="text-xs text-slate-400 font-medium mt-1.5">
                            Minimal 8 karakter, kombinasi huruf dan angka.
                        </p>
                    </div>

                    <!-- Konfirmasi Password -->
                    <div>
                        <label for="confirm_password" class="block text-xs font-bold text-slate-600 mb-1.5">
                            Konfirmasi Password <span class="text-red-500" aria-label="wajib diisi">*</span>
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none" aria-hidden="true">
                                <i data-lucide="lock-keyhole" class="w-4 h-4 text-slate-400"></i>
                            </div>
                            <input type="password"
                                   id="confirm_password"
                                   name="confirm_password"
                                   required
                                   autocomplete="new-password"
                                   class="w-full bg-slate-50 border border-slate-200 rounded-xl pl-10 pr-10 py-3 text-sm font-medium text-slate-800 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all"
                                   placeholder="Ulangi password baru"
                                   aria-describedby="match-indicator">
                            <button type="button"
                                    id="toggleConfirm"
                                    class="absolute inset-y-0 right-3 flex items-center text-slate-400 hover:text-slate-600"
                                    aria-label="Tampilkan/sembunyikan konfirmasi password">
                                <i data-lucide="eye" class="w-4 h-4" aria-hidden="true"></i>
                            </button>
                        </div>
                        <p id="match-indicator" class="text-xs font-bold mt-1.5 hidden" aria-live="polite"></p>
                    </div>

                    <button type="submit"
                            id="submitBtn"
                            class="w-full py-3.5 bg-blue-600 hover:bg-blue-700 text-white rounded-2xl font-black text-sm shadow-lg shadow-blue-200 active:scale-[0.98] transition-all flex items-center justify-center gap-2">
                        <i data-lucide="shield-check" class="w-4 h-4" aria-hidden="true"></i>
                        Simpan & Masuk ke Dashboard
                    </button>
                </form>

                <!-- Info logout -->
                <p class="text-center text-xs text-slate-400 font-medium">
                    Bukan akun Anda?
                    <a href="logout.php" class="text-blue-600 font-bold hover:underline">Keluar</a>
                </p>
            </div>
            <?php endif; ?>
        </div>

        <footer class="text-center py-8 text-slate-400 text-[10px] font-medium border-t border-slate-100">
            Yayasan Sultan Iskandar Muda &copy; <?= date('Y') ?> &mdash; PAABS v1.0 | Abdul Muis, S.T., M.Kom.
        </footer>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (typeof lucide !== 'undefined') lucide.createIcons();

            const newPass     = document.getElementById('new_password');
            const confirmPass = document.getElementById('confirm_password');
            const bar         = document.getElementById('strength-bar');
            const label       = document.getElementById('pass-strength-label');
            const container   = document.getElementById('strength-container');
            const matchHint   = document.getElementById('match-indicator');
            const submitBtn   = document.getElementById('submitBtn');

            // ── Toggle Password Visibility ──────────────────────
            function setupToggle(btnId, inputId) {
                const btn   = document.getElementById(btnId);
                const input = document.getElementById(inputId);
                if (!btn || !input) return;
                btn.addEventListener('click', () => {
                    const show = input.type === 'password';
                    input.type = show ? 'text' : 'password';
                    btn.querySelector('i').setAttribute('data-lucide', show ? 'eye-off' : 'eye');
                    lucide.createIcons();
                });
            }
            setupToggle('toggleNew', 'new_password');
            setupToggle('toggleConfirm', 'confirm_password');

            // ── Password Strength ────────────────────────────────
            function getStrength(pw) {
                let score = 0;
                if (pw.length >= 8)  score++;
                if (pw.length >= 12) score++;
                if (/[A-Z]/.test(pw)) score++;
                if (/[0-9]/.test(pw)) score++;
                if (/[^A-Za-z0-9]/.test(pw)) score++;
                return score;
            }

            const strengthConfig = [
                { max: 1, label: 'Sangat Lemah',  color: 'bg-red-500',    text: 'text-red-500',    width: '20%' },
                { max: 2, label: 'Lemah',          color: 'bg-orange-500', text: 'text-orange-500', width: '40%' },
                { max: 3, label: 'Cukup',          color: 'bg-amber-500',  text: 'text-amber-600',  width: '60%' },
                { max: 4, label: 'Kuat',           color: 'bg-blue-500',   text: 'text-blue-600',   width: '80%' },
                { max: 5, label: '💪 Sangat Kuat', color: 'bg-emerald-500',text: 'text-emerald-600',width: '100%'},
            ];

            if (newPass) {
                newPass.addEventListener('input', () => {
                    const pw = newPass.value;
                    if (!pw) { container.hidden = true; return; }

                    container.hidden = false;
                    const score = Math.min(getStrength(pw), 5);
                    const cfg   = strengthConfig[Math.max(score - 1, 0)];

                    bar.className  = `h-1.5 rounded-full transition-all duration-300 ${cfg.color}`;
                    bar.style.width = cfg.width;
                    label.className = `text-xs font-bold mt-0.5 ${cfg.text}`;
                    label.textContent = cfg.label;

                    checkMatch();
                });
            }

            // ── Match Indicator ──────────────────────────────────
            function checkMatch() {
                if (!confirmPass.value) { matchHint.classList.add('hidden'); return; }
                matchHint.classList.remove('hidden');
                if (newPass.value === confirmPass.value) {
                    matchHint.textContent = '✓ Password cocok';
                    matchHint.className   = 'text-xs font-bold mt-1.5 text-emerald-600';
                } else {
                    matchHint.textContent = '✗ Password tidak cocok';
                    matchHint.className   = 'text-xs font-bold mt-1.5 text-red-500';
                }
            }

            if (confirmPass) confirmPass.addEventListener('input', checkMatch);

            // ── Submit Loading State ─────────────────────────────
            document.getElementById('changePassForm')?.addEventListener('submit', (e) => {
                if (!newPass.value || !confirmPass.value) return;
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<svg class="animate-spin w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>Menyimpan...';
            });

            // Set focus ke input pertama
            newPass?.focus();
        });
    </script>
</body>
</html>
