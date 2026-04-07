<?php
// save.php - Professional Instrument + Dual Notes + Multi-Role Redirect
require 'config.php';
require 'auth.php';
checkRole(['admin', 'asesor']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $redirect = ($_SESSION['user']['role'] === 'admin') ? 'admin.php' : 'asesor.php';
    header("Location: $redirect");
    exit();
}

// CSRF Token Validation
$storedToken = $_SESSION['csrf_token'] ?? '';
$submittedToken = $_POST['csrf_token'] ?? '';
if (!$storedToken || !hash_equals($storedToken, $submittedToken)) {
    $redirect = ($_SESSION['user']['role'] === 'admin') ? 'admin.php' : 'asesor.php';
    header("Location: $redirect?error=csrf");
    exit();
}
// Regenerate token after use
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$student_id = (int)($_POST['student_id'] ?? 0);
$scores = $_POST['scores'] ?? [];
$child_notes = trim($_POST['child_notes'] ?? '');
$parent_notes = trim($_POST['parent_notes'] ?? '');
$is_recommended = in_array($_POST['is_recommended'] ?? '', ['0','1']) ? (int)$_POST['is_recommended'] : 0;

$role = $_SESSION['user']['role'];

if (!$student_id || empty($scores)) {
    $redirect = ($role === 'admin') ? 'admin.php' : 'asesor.php';
    header("Location: $redirect?error=incomplete");
    exit();
}

// Validasi & clamp nilai skor ke rentang 0-4
foreach ($scores as $key => $val) {
    $scores[$key] = max(0, min(4, (int)$val));
}

// Fetch Student details
$stmt = $pdo->prepare("SELECT name, school, grade, user_id FROM students WHERE id = ?");
$stmt->execute([$student_id]);
$s = $stmt->fetch();
if (!$s) {
    $redirect = ($role === 'admin') ? 'admin.php' : 'asesor.php';
    header("Location: $redirect?error=not_found");
    exit();
}

// Otorisasi asesor: hanya boleh simpan siswa yang ditugaskan kepadanya
if ($role === 'asesor' && (int)$s['user_id'] !== (int)$_SESSION['user']['id']) {
    header("Location: asesor.php?error=unauthorized");
    exit();
}

// Calculate Totals
$childTotal = 0;
$parentTotal = 0;
foreach ($childQuestions as $q) {
    if (isset($scores[$q['id']])) $childTotal += (int)$scores[$q['id']];
}
foreach ($parentQuestions as $q) {
    if (isset($scores[$q['id']])) $parentTotal += (int)$scores[$q['id']];
}
$grandTotal = $childTotal + $parentTotal;

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT id FROM assessments WHERE student_id = ?");
    $stmt->execute([$student_id]);
    $existing = $stmt->fetch();

    if ($existing) {
        $assessment_id = $existing['id'];
        $stmt = $pdo->prepare("UPDATE assessments SET 
            child_total = ?, parent_total = ?, grand_total = ?, 
            is_recommended = ?, child_notes = ?, parent_notes = ?, 
            updated_by = ?, updated_at = NOW() 
            WHERE id = ?");
        $stmt->execute([$childTotal, $parentTotal, $grandTotal, $is_recommended, $child_notes, $parent_notes, $_SESSION['user']['id'], $assessment_id]);
        
        $pdo->prepare("DELETE FROM assessment_answers WHERE assessment_id = ?")->execute([$assessment_id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO assessments (
            user_id, student_id, candidate_name, school, grade, 
            child_total, parent_total, grand_total, is_recommended, 
            child_notes, parent_notes, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
        $stmt->execute([
            $_SESSION['user']['id'], $student_id, $s['name'], $s['school'], $s['grade'], 
            $childTotal, $parentTotal, $grandTotal, $is_recommended, $child_notes, $parent_notes
        ]);
        $assessment_id = $pdo->lastInsertId();
    }

    $stmtAns = $pdo->prepare("INSERT INTO assessment_answers (assessment_id, question_id, question_text, score, type) VALUES (?, ?, ?, ?, ?)");
    foreach ($childQuestions as $q) {
        $stmtAns->execute([$assessment_id, $q['id'], $q['text'], (int)($scores[$q['id']] ?? 0), 'child']);
    }
    foreach ($parentQuestions as $q) {
        $stmtAns->execute([$assessment_id, $q['id'], $q['text'], (int)($scores[$q['id']] ?? 0), 'parent']);
    }

    $pdo->commit();

    // FIXED: Smart redirect based on Role
    $redirect = ($_SESSION['user']['role'] === 'admin') ? 'admin.php' : 'asesor.php';
    header("Location: $redirect?success=1");
    exit();

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $redirect = ($role === 'admin') ? 'admin.php' : 'asesor.php';
    // Log error ke server log (tidak tampilkan ke user)
    error_log("PAABS Save Error: " . $e->getMessage());
    header("Location: $redirect?error=save_failed");
    exit();
}
?>
