<?php
// export.php — Export rekap penilaian semua siswa ke CSV
require 'config.php';
require 'auth.php';
checkRole(['admin']);

$stmt = $pdo->query("
    SELECT
        s.id,
        s.name         AS nama_siswa,
        s.school       AS asal_sekolah,
        s.grade        AS jenjang,
        u.name         AS asesor,
        a.child_total,
        a.parent_total,
        a.grand_total,
        CASE WHEN a.is_recommended = 1 THEN 'Rekomen' ELSE 'Tidak Rekomen' END AS rekomendasi,
        a.child_notes  AS catatan_anak,
        a.parent_notes AS catatan_ortu,
        DATE_FORMAT(a.updated_at, '%d/%m/%Y %H:%i') AS tanggal_penilaian
    FROM students s
    LEFT JOIN users u  ON s.user_id  = u.id
    LEFT JOIN assessments a ON s.id = a.student_id
    ORDER BY s.id ASC
");
$rows = $stmt->fetchAll();

// Set header CSV Murni (Native tanpa peringatan)
$filename = 'PAABS_Rekap_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');

// BOM untuk Excel (agar bisa baca UTF-8)
fputs($out, "\xEF\xBB\xBF");

// MAGIC TRICK: Beritahu Excel secara eksplisit delimiter yang digunakan (Koma)
// Ini akan menghilangkan error koma/petik terlepas dari region laptop apapun
fputs($out, "sep=,\n");

// Header kolom
fputcsv($out, [
    'No.',
    'Nama Siswa',
    'Asal Sekolah',
    'Jenjang',
    'Asesor',
    'Skor Anak (maks 60)',
    'Skor Ortu (maks 48)',
    'Total Skor (maks 108)',
    'Rekomendasi',
    'Tanggal Penilaian',
    'Catatan Anak',
    'Catatan Ortu',
], ',');

// Data
$no = 1;
foreach ($rows as $r) {
    fputcsv($out, [
        $no++,
        $r['nama_siswa'],
        $r['asal_sekolah'],
        $r['jenjang'],
        $r['asesor'] ?? 'Belum diplot',
        $r['child_total']  ?? '-',
        $r['parent_total'] ?? '-',
        $r['grand_total']  ?? '-',
        $r['rekomendasi']  ?? 'Belum dinilai',
        $r['tanggal_penilaian'] ?? '-',
        $r['catatan_anak']  ?? '',
        $r['catatan_ortu']  ?? '',
    ], ',');
}

fclose($out);
exit;

