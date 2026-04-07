<?php
// users.php — CRUD Manajemen User & Asesor + Search/Filter
require 'config.php';
require 'auth.php';
checkRole(['admin']);

$user = $_SESSION['user'];
$msg  = '';
$msgType = 'green';

// ── Handle POST Actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name     = trim($_POST['name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = in_array($_POST['role'] ?? '', ['admin', 'asesor']) ? $_POST['role'] : 'asesor';

        if ($name && $email && $password) {
            $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $check->execute([$email]);
            if ($check->fetch()) {
                $msg = "Email sudah terdaftar. Gunakan email lain.";
                $msgType = 'red';
            } else {
                $hashed = password_hash($password, PASSWORD_BCRYPT);
                $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)")
                    ->execute([$name, $email, $hashed, $role]);
                $msg = "Akun <strong>$name</strong> berhasil dibuat.";
            }
        } else {
            $msg = "Nama, email, dan password wajib diisi.";
            $msgType = 'red';
        }

    } elseif ($action === 'edit') {
        $id    = (int)($_POST['id'] ?? 0);
        $name  = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role  = in_array($_POST['role'] ?? '', ['admin', 'asesor']) ? $_POST['role'] : 'asesor';

        if ($id && $name && $email) {
            $check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $check->execute([$email, $id]);
            if ($check->fetch()) {
                $msg = "Email sudah digunakan akun lain.";
                $msgType = 'red';
            } else {
                $pdo->prepare("UPDATE users SET name=?, email=?, role=? WHERE id=?")
                    ->execute([$name, $email, $role, $id]);
                $msg = "Data akun berhasil diperbarui.";
            }
        } else {
            $msg = "Data tidak lengkap.";
            $msgType = 'red';
        }

    } elseif ($action === 'reset_password') {
        $id       = (int)($_POST['id'] ?? 0);
        $password = $_POST['new_password'] ?? '';

        if ($id && strlen($password) >= 6) {
            $hashed = password_hash($password, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([$hashed, $id]);
            $msg = "Password berhasil direset.";
            $msgType = 'amber';
        } else {
            $msg = "Password minimal 6 karakter.";
            $msgType = 'red';
        }

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id && $id !== (int)$user['id']) {
            $pdo->prepare("UPDATE students SET user_id = NULL WHERE user_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
            $msg = "Akun berhasil dihapus.";
            $msgType = 'amber';
        } else {
            $msg = "Tidak dapat menghapus akun Anda sendiri.";
            $msgType = 'red';
        }
    }

    $queryParams = [];
    foreach(['q', 'role_filter'] as $k) {
        if (!empty($_GET[$k])) $queryParams[$k] = $_GET[$k];
    }
    $queryParams['msg'] = $msg;
    $queryParams['type'] = $msgType;
    
    header("Location: users.php?" . http_build_query($queryParams));
    exit;
}

if (isset($_GET['msg'])) {
    $msg = $_GET['msg'];
    $msgType = $_GET['type'] ?? 'green';
}

$editUser = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT id, name, email, role FROM users WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $editUser = $stmt->fetch();
}

$resetUser = null;
if (isset($_GET['reset'])) {
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE id = ?");
    $stmt->execute([(int)$_GET['reset']]);
    $resetUser = $stmt->fetch();
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
$roleFilter = $_GET['role_filter'] ?? '';

$conditions = [];
$params = [];

if ($search) {
    $conditions[] = "(u.name LIKE :search OR u.email LIKE :search)";
    $params['search'] = "%$search%";
}
if ($roleFilter === 'admin' || $roleFilter === 'asesor') {
    $conditions[] = "u.role = :role_filter";
    $params['role_filter'] = $roleFilter;
}

$where = $conditions ? " WHERE " . implode(" AND ", $conditions) : "";

$query = "
    SELECT u.*, COUNT(s.id) as student_count
    FROM users u
    LEFT JOIN students s ON s.user_id = u.id
    $where
    GROUP BY u.id
    ORDER BY u.role DESC, u.name ASC
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$usersList = $stmt->fetchAll();

// Filter URL helper
function userUrl(array $newParams): string {
    $base = array_filter([
        'q'           => trim($_GET['q'] ?? ''),
        'role_filter' => $_GET['role_filter'] ?? '',
    ], fn($v) => $v !== '');
    $merged = array_filter(array_merge($base, $newParams), fn($v) => $v !== '');
    return 'users.php' . ($merged ? '?' . http_build_query($merged) : '');
}
$activeFilters = (int)($search!=='') + (int)($roleFilter!=='');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="favicon.png">
    <title>Kelola User | PAABS</title>
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
        <i data-lucide="<?= $msgType === 'green' ? 'check-circle' : ($msgType === 'red' ? 'alert-circle' : 'info') ?>" class="w-5 h-5 shrink-0"></i>
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
                PAABS <span class="text-blue-600">Kelola User</span>
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
            <h1 class="text-xl font-extrabold text-slate-900 tracking-tight">Manajemen Akun & Asesor</h1>
            <p class="text-sm text-slate-500 font-medium mt-1">Buat, edit, reset password, dan hapus akun admin/asesor.</p>
        </div>

        <?php if(!$editUser && !$resetUser): ?>
        <div class="bg-blue-50 border border-blue-200 rounded-2xl p-4 flex items-start gap-3 text-sm">
            <i data-lucide="shield-check" class="w-5 h-5 text-blue-600 shrink-0 mt-0.5"></i>
            <div>
                <p class="font-bold text-blue-800">Password diamankan dengan Bcrypt</p>
                <p class="text-blue-600 font-medium text-xs mt-0.5">Semua akun baru dan reset password menggunakan enkripsi password_hash() — tidak disimpan plaintext.</p>
            </div>
        </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

            <!-- Form Panel -->
            <div class="lg:col-span-1">
                <?php if($resetUser): ?>
                <div class="bg-white border border-amber-200 rounded-3xl p-6 shadow-sm sticky top-24">
                    <h2 class="text-sm font-bold text-slate-800 mb-1 flex items-center gap-2">
                        <i data-lucide="key" class="w-4 h-4 text-amber-500"></i>
                        Reset Password
                    </h2>
                    <p class="text-xs text-slate-400 font-medium mb-5">Untuk akun: <strong><?= htmlspecialchars($resetUser['name']) ?></strong></p>

                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="reset_password">
                        <input type="hidden" name="id" value="<?= $resetUser['id'] ?>">
                        <div>
                            <label class="block mb-1.5 text-xs font-semibold text-slate-600">Password Baru *</label>
                            <input type="password" name="new_password" required minlength="6"
                                   placeholder="Min. 6 karakter"
                                   class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium focus:ring-2 focus:ring-amber-500 focus:border-amber-500 outline-none bg-slate-50">
                        </div>
                        <div class="flex gap-2 pt-1">
                            <button type="submit" class="flex-1 py-2.5 bg-amber-500 hover:bg-amber-600 text-white rounded-xl text-sm font-bold transition-all shadow-md active:scale-95">
                                Reset Password
                            </button>
                            <a href="<?= userUrl(['reset'=>'']) ?>" class="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl text-sm font-bold transition-all">Batal</a>
                        </div>
                    </form>
                </div>
                <?php else: ?>
                <div class="bg-white border border-slate-200 rounded-3xl p-6 shadow-sm sticky top-24">
                    <h2 class="text-sm font-bold text-slate-800 mb-5 flex items-center gap-2">
                        <i data-lucide="<?= $editUser ? 'pencil' : 'user-plus' ?>" class="w-4 h-4 text-blue-600"></i>
                        <?= $editUser ? 'Edit Akun' : 'Tambah Akun Baru' ?>
                    </h2>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="<?= $editUser ? 'edit' : 'add' ?>">
                        <?php if($editUser): ?>
                        <input type="hidden" name="id" value="<?= $editUser['id'] ?>">
                        <?php endif; ?>

                        <div>
                            <label class="block mb-1.5 text-xs font-semibold text-slate-600">Nama Lengkap *</label>
                            <input type="text" name="name" required
                                   value="<?= htmlspecialchars($editUser['name'] ?? '') ?>"
                                   placeholder="Nama lengkap..."
                                   class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none bg-slate-50">
                        </div>
                        <div>
                            <label class="block mb-1.5 text-xs font-semibold text-slate-600">Email *</label>
                            <input type="email" name="email" required
                                   value="<?= htmlspecialchars($editUser['email'] ?? '') ?>"
                                   placeholder="email@ypsim.com"
                                   class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none bg-slate-50">
                        </div>
                        <?php if(!$editUser): ?>
                        <div>
                            <label class="block mb-1.5 text-xs font-semibold text-slate-600">Password *</label>
                            <input type="password" name="password" required minlength="6"
                                   placeholder="Min. 6 karakter"
                                   class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none bg-slate-50">
                            <p class="text-xs text-slate-400 font-medium mt-1">Password akan dienkripsi otomatis (Bcrypt).</p>
                        </div>
                        <?php endif; ?>
                        <div>
                            <label class="block mb-1.5 text-xs font-semibold text-slate-600">Role *</label>
                            <select name="role"
                                    class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none bg-slate-50">
                                <option value="asesor" <?= ($editUser['role'] ?? '') === 'asesor' ? 'selected' : '' ?>>Asesor</option>
                                <option value="admin"  <?= ($editUser['role'] ?? '') === 'admin'  ? 'selected' : '' ?>>Admin</option>
                            </select>
                        </div>
                        <div class="flex gap-2 pt-2">
                            <button type="submit"
                                    class="flex-1 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-sm font-bold transition-all shadow-md active:scale-95">
                                <?= $editUser ? 'Simpan Perubahan' : 'Buat Akun' ?>
                            </button>
                            <?php if($editUser): ?>
                            <a href="<?= userUrl(['edit'=>'']) ?>" class="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl text-sm font-bold transition-all">Batal</a>
                            <?php endif; ?>
                        </div>
                        <?php if($editUser): ?>
                        <div class="pt-2 border-t border-slate-100">
                            <a href="<?= userUrl(['edit'=> '', 'reset'=>$editUser['id']]) ?>"
                               class="w-full flex items-center justify-center gap-2 py-2.5 bg-amber-50 hover:bg-amber-100 text-amber-600 border border-amber-200 rounded-xl text-xs font-bold transition-all">
                                <i data-lucide="key" class="w-3.5 h-3.5"></i> Reset Password Akun Ini
                            </a>
                        </div>
                        <?php endif; ?>
                    </form>
                </div>
                <?php endif; ?>
            </div>

            <!-- Daftar User -->
            <div class="lg:col-span-2 space-y-4">
                
                <!-- Search & Filters -->
                <div class="space-y-3">
                    <form id="searchForm" method="GET" class="relative group w-full">
                        <?php if($roleFilter): ?>
                        <input type="hidden" name="role_filter" value="<?= htmlspecialchars($roleFilter) ?>">
                        <?php endif; ?>
                        
                        <div class="absolute inset-y-0 left-0 flex items-center pl-4 pointer-events-none">
                            <i data-lucide="search" class="w-4 h-4 text-slate-400"></i>
                        </div>
                        <input id="searchInput" type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                               class="block w-full p-3 pl-10 pr-10 text-sm border border-slate-200 rounded-2xl bg-white focus:ring-blue-500 focus:border-blue-500 font-medium shadow-sm transition-all placeholder:text-slate-400"
                               placeholder="Cari nama akun atau email..." autocomplete="off">
                        <?php if($search): ?>
                        <a href="<?= userUrl(['q'=>'']) ?>" class="absolute inset-y-0 right-4 flex items-center text-slate-300 hover:text-slate-500">
                            <i data-lucide="x-circle" class="w-4 h-4"></i>
                        </a>
                        <?php endif; ?>
                    </form>

                    <!-- Filter Chips -->
                    <div class="flex flex-wrap items-center gap-2">
                        <a href="<?= userUrl(['role_filter'=>'']) ?>" class="px-3 py-1 rounded-full border text-xs font-semibold transition-all <?= $roleFilter==='' ? 'bg-slate-800 text-white border-slate-800' : 'bg-white text-slate-600 border-slate-200 hover:border-slate-400' ?>">Semua Role</a>
                        <a href="<?= userUrl(['role_filter'=>'admin']) ?>" class="px-3 py-1 rounded-full border text-xs font-semibold transition-all <?= $roleFilter==='admin' ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-slate-600 border-slate-200 hover:border-blue-300' ?>">Admins</a>
                        <a href="<?= userUrl(['role_filter'=>'asesor']) ?>" class="px-3 py-1 rounded-full border text-xs font-semibold transition-all <?= $roleFilter==='asesor' ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-slate-600 border-slate-200 hover:border-indigo-300' ?>">Asesor</a>

                        <?php if($activeFilters > 0): ?>
                        <a href="users.php" class="ml-auto flex items-center gap-1 px-3 py-1 bg-red-50 text-red-500 border border-red-200 rounded-full text-xs font-semibold hover:bg-red-100 transition-all">
                            <i data-lucide="filter-x" class="w-3 h-3"></i> Clear
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="bg-white border border-slate-200 rounded-[24px] overflow-hidden shadow-sm">
                    <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50">
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest"><?= count($usersList) ?> akun terdaftar</p>
                    </div>
                    <div class="divide-y divide-slate-50">
                        <?php foreach($usersList as $u): ?>
                        <div class="flex items-center justify-between px-6 py-4 hover:bg-slate-50/50 transition-colors
                            <?= ($editUser && $editUser['id'] == $u['id']) ? 'bg-blue-50 border-l-4 border-blue-500' : '' ?>
                            <?= ($resetUser && $resetUser['id'] == $u['id']) ? 'bg-amber-50 border-l-4 border-amber-400' : '' ?>">
                            <div class="flex items-center gap-4">
                                <!-- Avatar -->
                                <div class="w-10 h-10 rounded-2xl flex items-center justify-center font-black text-sm shrink-0
                                    <?= $u['role'] === 'admin' ? 'bg-blue-100 text-blue-600' : 'bg-slate-100 text-slate-500' ?>">
                                    <?= strtoupper(substr($u['name'], 0, 1)) ?>
                                </div>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <p class="font-bold text-slate-800 text-sm"><?= hl($u['name'], $search) ?></p>
                                        <span class="px-2 py-0.5 rounded-md text-xs font-bold
                                            <?= $u['role'] === 'admin' ? 'bg-blue-100 text-blue-700' : 'bg-slate-100 text-slate-500' ?>">
                                            <?= strtoupper($u['role']) ?>
                                        </span>
                                        <?php if($u['id'] == $user['id']): ?>
                                        <span class="px-2 py-0.5 bg-green-100 text-green-700 rounded-md text-xs font-bold">Anda</span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-slate-400 text-xs font-medium mt-0.5"><?= hl($u['email'], $search) ?></p>
                                    <?php if($u['role'] === 'asesor'): ?>
                                    <p class="text-xs text-slate-400 font-medium mt-1">
                                        <i data-lucide="users" class="w-3 h-3 inline-block mr-0.5 text-slate-300"></i>
                                        <?= $u['student_count'] ?> siswa diassign
                                    </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="flex gap-1.5 shrink-0">
                                <a href="<?= userUrl(['edit'=>$u['id']]) ?>"
                                   class="p-2 bg-slate-100 text-slate-600 rounded-xl hover:bg-amber-500 hover:text-white transition-all active:scale-95"
                                   title="Edit akun">
                                    <i data-lucide="pencil" class="w-4 h-4"></i>
                                </a>
                                <a href="<?= userUrl(['reset'=>$u['id']]) ?>"
                                   class="p-2 bg-slate-100 text-slate-600 rounded-xl hover:bg-blue-600 hover:text-white transition-all active:scale-95"
                                   title="Reset password">
                                    <i data-lucide="key" class="w-4 h-4"></i>
                                </a>
                                <?php if($u['id'] != $user['id']): ?>
                                <form method="POST" onsubmit="return confirm('Hapus akun <?= htmlspecialchars(addslashes($u['name'])) ?>? Siswa yang diassign akan menjadi unplot.')" class="inline">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                    <button type="submit"
                                            class="p-2 bg-slate-100 text-slate-600 rounded-xl hover:bg-red-600 hover:text-white transition-all active:scale-95"
                                            title="Hapus akun">
                                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <?php if(empty($usersList)): ?>
                        <div class="p-12 text-center text-slate-400 text-sm font-bold">
                            <i data-lucide="search-x" class="w-8 h-8 text-slate-300 mx-auto mb-2"></i>
                            Tidak ada data yang cocok dengan filter.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mt-4 bg-amber-50 border border-amber-200 rounded-2xl p-4">
                    <p class="text-xs font-bold text-amber-700 flex items-center gap-2">
                        <i data-lucide="alert-triangle" class="w-4 h-4 shrink-0"></i>
                        Catatan: Akun lama yang dibuat sebelum fitur ini mungkin masih menggunakan password plaintext.
                        Gunakan tombol <strong>Reset Password</strong> untuk memperbarui ke Bcrypt.
                    </p>
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
