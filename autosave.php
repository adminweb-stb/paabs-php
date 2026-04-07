<?php
// autosave.php — Auto-save per butir: dipanggil via AJAX, tidak ada redirect
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require 'config.php';
require 'auth.php';

// Pastikan user sudah login
if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Session habis. Silakan login kembali.']);
    exit();
}

// Hanya terima POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed.']);
    exit();
}

$role       = $_SESSION['user']['role'];
$userId     = (int)$_SESSION['user']['id'];
$studentId  = (int)($_POST['student_id'] ?? 0);
$questionId = trim($_POST['question_id'] ?? '');
$score      = isset($_POST['score']) ? max(0, min(4, (int)$_POST['score'])) : null;
$fieldName  = trim($_POST['field'] ?? '');   // 'child_notes' atau 'parent_notes'
$fieldValue = trim($_POST['value'] ?? '');

if (!$studentId) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'student_id tidak valid.']);
    exit();
}

// Verifikasi siswa ada dan asesor berhak mengaksesnya
$stmtS = $pdo->prepare("SELECT id, name, school, grade, user_id FROM students WHERE id = ?");
$stmtS->execute([$studentId]);
$student = $stmtS->fetch();

if (!$student) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'msg' => 'Siswa tidak ditemukan.']);
    exit();
}

if ($role === 'asesor' && (int)$student['user_id'] !== $userId) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Akses tidak diizinkan.']);
    exit();
}

try {
    // ── Cek / buat assessment record ────────────────────────────────
    $stmtA = $pdo->prepare("SELECT id FROM assessments WHERE student_id = ?");
    $stmtA->execute([$studentId]);
    $assessment = $stmtA->fetch();

    if (!$assessment) {
        // Buat assessment baru (draft, belum ada rekomendasi)
        $stmtNew = $pdo->prepare("
            INSERT INTO assessments
                (user_id, student_id, candidate_name, school, grade,
                 child_total, parent_total, grand_total, is_recommended,
                 child_notes, parent_notes, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 0, 0, 0, 0, '', '', NOW(), NOW())
        ");
        $stmtNew->execute([
            $userId,
            $studentId,
            $student['name'],
            $student['school'],
            $student['grade']
        ]);
        $assessmentId = (int)$pdo->lastInsertId();
    } else {
        $assessmentId = (int)$assessment['id'];
    }

    // ── Simpan skor per butir ────────────────────────────────────────
    if ($questionId !== '' && $score !== null) {
        // Tentukan tipe pertanyaan dari prefix ID
        $type = (strpos($questionId, 'c') === 0) ? 'child' : 'parent';

        // Cari teks pertanyaan dari config
        $questionText = '';
        $allQuestions = array_merge($childQuestions, $parentQuestions);
        foreach ($allQuestions as $q) {
            if ($q['id'] === $questionId) {
                $questionText = $q['text'];
                break;
            }
        }

        // Upsert: update jika sudah ada, insert jika belum
        $stmtCheck = $pdo->prepare(
            "SELECT id FROM assessment_answers WHERE assessment_id = ? AND question_id = ?"
        );
        $stmtCheck->execute([$assessmentId, $questionId]);
        $existingAnswer = $stmtCheck->fetch();

        if ($existingAnswer) {
            $pdo->prepare("UPDATE assessment_answers SET score = ? WHERE id = ?")
                ->execute([$score, $existingAnswer['id']]);
        } else {
            $pdo->prepare("
                INSERT INTO assessment_answers (assessment_id, question_id, question_text, score, type)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([$assessmentId, $questionId, $questionText, $score, $type]);
        }

        // Hitung ulang total dan update assessment
        $stmtTotals = $pdo->prepare("
            SELECT type, SUM(score) as total
            FROM assessment_answers
            WHERE assessment_id = ?
            GROUP BY type
        ");
        $stmtTotals->execute([$assessmentId]);
        $totals = ['child' => 0, 'parent' => 0];
        while ($row = $stmtTotals->fetch()) {
            $totals[$row['type']] = (int)$row['total'];
        }
        $grandTotal = $totals['child'] + $totals['parent'];

        $pdo->prepare("
            UPDATE assessments
            SET child_total = ?, parent_total = ?, grand_total = ?, updated_at = NOW()
            WHERE id = ?
        ")->execute([$totals['child'], $totals['parent'], $grandTotal, $assessmentId]);

        echo json_encode([
            'ok'          => true,
            'msg'         => 'Tersimpan',
            'childTotal'  => $totals['child'],
            'parentTotal' => $totals['parent'],
            'grandTotal'  => $grandTotal,
        ]);
        exit();
    }

    // ── Simpan notes ─────────────────────────────────────────────────
    // Whitelist + mapping eksplisit — tidak ada interpolasi nama kolom dari user input
    $allowedNoteFields = [
        'child_notes'  => 'child_notes',
        'parent_notes' => 'parent_notes',
    ];
    if (isset($allowedNoteFields[$fieldName])) {
        $column    = $allowedNoteFields[$fieldName]; // nilai pasti dari mapping, bukan dari $_POST
        $safeValue = substr($fieldValue, 0, 1000);
        $pdo->prepare("UPDATE assessments SET {$column} = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$safeValue, $assessmentId]);

        echo json_encode(['ok' => true, 'msg' => 'Catatan tersimpan']);
        exit();
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Tidak ada data yang disimpan.']);

} catch (Exception $e) {
    error_log("PAABS AutoSave Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'Gagal menyimpan. Coba lagi.']);
}
?>
