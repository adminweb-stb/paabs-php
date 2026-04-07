<?php
// index.php - Clean Light Edition
require 'config.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        // Coba verifikasi Bcrypt (akun baru)
        $valid = password_verify($pass, $user['password']);

        // Fallback: plaintext untuk akun lama — auto-upgrade ke Bcrypt
        if (!$valid && $pass === $user['password']) {
            $valid = true;
            // Langsung upgrade ke Bcrypt saat login
            $hashed = password_hash($pass, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
                ->execute([$hashed, $user['id']]);
            $user['password'] = $hashed;
        }

        if ($valid) {
            $_SESSION['user'] = $user;

            // Cek apakah user wajib ganti password (first login / default password)
            if (!empty($user['must_change_password'])) {
                header("Location: change_password.php");
            } elseif ($user['role'] === 'admin') {
                header("Location: admin.php");
            } else {
                header("Location: asesor.php");
            }
            exit();
        }
    }

    $error = "Email atau password salah. Silakan coba lagi.";
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="favicon.png">
    <title>Login | PAABS</title>
    <link rel="stylesheet" href="style.css?v=1.0">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/flowbite/2.3.0/flowbite.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Nunito', sans-serif; }
    </style>
</head>
<body class="bg-slate-50 antialiased">
    <section class="flex flex-col items-center justify-center px-6 py-8 mx-auto md:h-screen lg:py-0">
        <div class="flex items-center mb-6 text-xl font-black text-slate-900 tracking-tighter uppercase">
            <i data-lucide="shield-check" class="w-6 h-6 mr-2 text-blue-600"></i>
            PAABS <span class="text-blue-600 ml-1">Portal</span>
        </div>
        
        <div class="w-full bg-white rounded-2xl shadow-sm border border-slate-200 md:mt-0 sm:max-w-md xl:p-0">
            <div class="p-8 space-y-6 md:p-10">
                <div class="space-y-1">
                    <h1 class="text-lg font-bold leading-tight tracking-tight text-slate-900 md:text-xl">
                        Selamat Datang Kembali
                    </h1>
                    <p class="text-sm font-medium text-slate-500">Gunakan email akun YPSIM Bapak untuk masuk.</p>
                </div>

                <?php if($error): ?>
                    <div class="p-4 mb-4 text-sm text-red-800 rounded-xl bg-red-50 border border-red-100 flex items-center" role="alert">
                        <i data-lucide="alert-circle" class="w-4 h-4 mr-2"></i>
                        <span class="font-bold"><?= $error ?></span>
                    </div>
                <?php endif; ?>

                <form class="space-y-4 md:space-y-6" method="POST">
                    <div>
                        <label for="email" class="block mb-2 text-xs font-semibold text-slate-600">Email Resmi</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none">
                                <i data-lucide="mail" class="w-4 h-4 text-slate-400"></i>
                            </div>
                            <input type="email" name="email" id="email" class="bg-slate-50 border border-slate-300 text-slate-900 sm:text-sm rounded-xl focus:ring-blue-500 focus:border-blue-500 block w-full pl-10 p-3.5 font-medium transition-all" placeholder="admin@ypsim.com" required>
                        </div>
                    </div>
                    <div>
                        <label for="password" class="block mb-2 text-xs font-semibold text-slate-600">Kata Sandi</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none">
                                <i data-lucide="lock" class="w-4 h-4 text-slate-400"></i>
                            </div>
                            <input type="password" name="password" id="password" placeholder="••••••••" class="bg-slate-50 border border-slate-300 text-slate-900 sm:text-sm rounded-xl focus:ring-blue-500 focus:border-blue-500 block w-full pl-10 p-3.5 font-medium transition-all" required>
                        </div>
                    </div>
                    
                    <button type="submit" class="w-full text-white bg-blue-600 hover:bg-blue-700 focus:ring-4 focus:outline-none focus:ring-blue-300 font-black rounded-xl text-sm px-5 py-3.5 text-center transition-all shadow-md active:scale-[0.98]">
                        Masuk ke Dashboard
                    </button>
                </form>
            </div>
        </div>
    </section>

    <footer class="text-center py-6 text-slate-400 text-xs font-medium border-t border-slate-100 mt-4">
        Yayasan Sultan Iskandar Muda &copy; <?= date('Y') ?> &mdash; PAABS v1.0 | Abdul Muis, S.T., M.Kom.
    </footer>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/flowbite/2.3.0/flowbite.min.js"></script>
    <script>lucide.createIcons();</script>
</body>
</html>
