<?php
// report.php — Print-ready report per siswa
require 'config.php';
require 'auth.php';
checkRole(['admin', 'asesor']);

$student_id = (int)($_GET['id'] ?? 0);
if (!$student_id) die("Missing student ID");

// Student data
$stmt = $pdo->prepare("SELECT s.*, u.name as interviewer_name FROM students s LEFT JOIN users u ON s.user_id = u.id WHERE s.id = ?");
$stmt->execute([$student_id]);
$s = $stmt->fetch();
if (!$s) die("Student not found");

// Assessment
$stmt = $pdo->prepare("SELECT * FROM assessments WHERE student_id = ?");
$stmt->execute([$student_id]);
$assessment = $stmt->fetch();
if (!$assessment) die("Belum ada penilaian untuk siswa ini.");

// Answers
$stmt = $pdo->prepare("SELECT question_id, question_text, score, type FROM assessment_answers WHERE assessment_id = ? ORDER BY type ASC, question_id ASC");
$stmt->execute([$assessment['id']]);
$answers = $stmt->fetchAll();

$childAnswers  = array_filter($answers, fn($a) => $a['type'] === 'child');
$parentAnswers = array_filter($answers, fn($a) => $a['type'] === 'parent');

$printDate = date('d F Y, H:i');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="favicon.png">
    <title>Laporan Hasil Interview — <?= htmlspecialchars($s['name']) ?> | PAABS</title>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Nunito', sans-serif;
            background: #f8fafc;
            color: #1e293b;
            font-size: 11px;
            line-height: 1.5;
        }

        /* ── Screen Layout ── */
        .screen-toolbar {
            background: white;
            border-bottom: 1px solid #e2e8f0;
            padding: 14px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 10;
            box-shadow: 0 1px 4px rgba(0,0,0,.06);
        }
        .screen-toolbar .brand { font-weight: 900; font-size: 14px; color: #1e293b; }
        .screen-toolbar .brand span { color: #2563eb; }
        .btn-print {
            background: #2563eb;
            color: white;
            border: none;
            padding: 10px 22px;
            border-radius: 12px;
            font-family: 'Nunito', sans-serif;
            font-weight: 800;
            font-size: 12px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background .2s;
        }
        .btn-print:hover { background: #1d4ed8; }
        .btn-back {
            background: #f1f5f9;
            color: #475569;
            border: none;
            padding: 10px 18px;
            border-radius: 12px;
            font-family: 'Nunito', sans-serif;
            font-weight: 700;
            font-size: 12px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-back:hover { background: #e2e8f0; }

        .report-wrapper {
            max-width: 800px;
            margin: 28px auto;
            padding: 0 20px 60px;
        }

        /* ── Report Card ── */
        .report-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 4px 24px rgba(0,0,0,.06);
        }

        /* Header */
        .report-header {
            background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%);
            color: white;
            padding: 28px 32px;
        }
        .report-header .org { font-size: 9px; font-weight: 700; letter-spacing: 2px; opacity: .7; text-transform: uppercase; margin-bottom: 4px; }
        .report-header .title { font-size: 18px; font-weight: 900; margin-bottom: 16px; }
        .report-header .meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .meta-item label { font-size: 8px; font-weight: 700; opacity: .6; text-transform: uppercase; letter-spacing: 1px; display: block; margin-bottom: 2px; }
        .meta-item span { font-size: 11px; font-weight: 700; }

        /* Score Summary */
        .score-summary {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            border-bottom: 1px solid #f1f5f9;
        }
        .score-box {
            padding: 18px 20px;
            text-align: center;
            border-right: 1px solid #f1f5f9;
        }
        .score-box:last-child { border-right: none; }
        .score-box .label { font-size: 9px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }
        .score-box .value { font-size: 28px; font-weight: 900; }
        .score-box .max { font-size: 10px; color: #cbd5e1; font-weight: 600; }
        .score-box.child .value { color: #2563eb; }
        .score-box.parent .value { color: #16a34a; }
        .score-box.grand .value { color: #1e293b; }

        /* Recommendation Banner */
        .rec-banner {
            padding: 14px 24px;
            font-weight: 800;
            font-size: 13px;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .rec-banner.ok { background: #eff6ff; color: #1d4ed8; border-bottom: 1px solid #dbeafe; }
        .rec-banner.no { background: #fff1f2; color: #be123c; border-bottom: 1px solid #fecdd3; }

        /* Section */
        .section-wrap { padding: 24px 28px; }
        .section-title {
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 16px;
        }
        .section-title .bar { width: 3px; height: 16px; border-radius: 2px; }
        .section-title .bar.blue { background: #2563eb; }
        .section-title .bar.green { background: #16a34a; }

        /* Question Row */
        .q-row {
            display: grid;
            grid-template-columns: 22px 1fr 36px;
            gap: 8px;
            padding: 8px 0;
            border-bottom: 1px solid #f8fafc;
            align-items: start;
        }
        .q-row:last-child { border-bottom: none; }
        .q-num { font-weight: 800; color: #94a3b8; font-size: 10px; padding-top: 1px; }
        .q-text { color: #475569; font-size: 10.5px; font-weight: 600; line-height: 1.5; }
        .q-score {
            font-weight: 900;
            font-size: 14px;
            text-align: center;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .q-score.s0 { background: #f1f5f9; color: #94a3b8; }
        .q-score.s1 { background: #fef9c3; color: #a16207; }
        .q-score.s2 { background: #ffedd5; color: #c2410c; }
        .q-score.s3 { background: #dcfce7; color: #166534; }
        .q-score.s4 { background: #dbeafe; color: #1d4ed8; }

        /* Section subtotal */
        .section-subtotal {
            margin-top: 12px;
            padding: 10px 14px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-weight: 700;
            font-size: 11px;
        }
        .section-subtotal.child { background: #eff6ff; color: #1d4ed8; }
        .section-subtotal.parent { background: #f0fdf4; color: #166534; }

        /* Notes */
        .notes-wrap {
            border-radius: 12px;
            padding: 14px 16px;
            margin-top: 16px;
        }
        .notes-wrap.blue { background: #eff6ff; border: 1px solid #dbeafe; }
        .notes-wrap.green { background: #f0fdf4; border: 1px solid #dcfce7; }
        .notes-wrap .notes-label { font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }
        .notes-wrap.blue .notes-label { color: #1d4ed8; }
        .notes-wrap.green .notes-label { color: #166534; }
        .notes-wrap .notes-text { font-size: 10.5px; color: #334155; font-weight: 600; line-height: 1.7; white-space: pre-wrap; }

        /* Divider */
        .divider { border: none; border-top: 1px solid #f1f5f9; margin: 0 28px; }

        /* Signature */
        .signature-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            padding: 24px 28px;
        }
        .sig-box { text-align: center; }
        .sig-box .sig-label { font-size: 9px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 48px; }
        .sig-box .sig-line { border-top: 1.5px solid #1e293b; padding-top: 6px; font-size: 10px; font-weight: 800; color: #334155; }
        .sig-box .sig-role { font-size: 9px; color: #94a3b8; margin-top: 2px; }

        /* Footer */
        .report-footer {
            background: #f8fafc;
            border-top: 1px solid #f1f5f9;
            padding: 12px 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .report-footer p { font-size: 8px; font-weight: 700; color: #cbd5e1; text-transform: uppercase; letter-spacing: 1px; }

        /* ── Print Overrides ── */
        @media print {
            body { background: white; font-size: 10px; }
            .screen-toolbar { display: none; }
            .report-wrapper { margin: 0; padding: 0; max-width: 100%; }
            .report-card { border: none; border-radius: 0; box-shadow: none; }
            @page { margin: 15mm 15mm 15mm 15mm; size: A4; }
            .q-row { page-break-inside: avoid; }
            .section-wrap { page-break-inside: avoid; }
        }
    </style>
</head>
<body>

    <!-- Toolbar (hidden on print) -->
    <div class="screen-toolbar">
        <div>
            <div class="brand">PAABS <span>Report</span></div>
            <div style="font-size:10px;color:#94a3b8;font-weight:600;margin-top:2px;">Laporan Hasil Interview Siswa</div>
        </div>
        <div style="display:flex;gap:10px;align-items:center;">
            <a href="<?= $_SESSION['user']['role'] === 'admin' ? 'admin.php' : 'asesor.php' ?>" class="btn-back">
                ← Kembali
            </a>
            <button class="btn-print" onclick="window.print()">
                🖨️ Cetak / Simpan PDF
            </button>
        </div>
    </div>

    <div class="report-wrapper">
        <div class="report-card">

            <!-- Header -->
            <div class="report-header">
                <p class="org">Yayasan Sultan Iskandar Muda • Program Anak Asuh</p>
                <p class="title">Laporan Hasil Interview Seleksi</p>
                <div class="meta-grid">
                    <div class="meta-item">
                        <label>Nama Kandidat</label>
                        <span><?= htmlspecialchars($s['name']) ?></span>
                    </div>
                    <div class="meta-item">
                        <label>Asal Sekolah</label>
                        <span><?= htmlspecialchars($s['school']) ?></span>
                    </div>
                    <div class="meta-item">
                        <label>Jenjang</label>
                        <span><?= htmlspecialchars($s['grade']) ?></span>
                    </div>
                    <div class="meta-item">
                        <label>Pewawancara</label>
                        <span><?= htmlspecialchars($s['interviewer_name'] ?? '—') ?></span>
                    </div>
                    <div class="meta-item">
                        <label>Jadwal</label>
                        <span><?= $s['interview_date'] ? $s['interview_date'] : '—' ?> <?= $s['interview_time'] ? ' ('.$s['interview_time'].')' : '' ?></span>
                    </div>
                    <?php if($s['location']): ?>
                    <div class="meta-item">
                        <label>Lokasi / Ruangan</label>
                        <span><?= htmlspecialchars($s['location']) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="meta-item">
                        <label>Tanggal Cetak</label>
                        <span><?= $printDate ?></span>
                    </div>
                    <div class="meta-item">
                        <label>No. Assessment</label>
                        <span>#<?= str_pad($assessment['id'], 4, '0', STR_PAD_LEFT) ?></span>
                    </div>
                </div>
            </div>

            <!-- Score Summary -->
            <div class="score-summary">
                <div class="score-box child">
                    <div class="label">Instrumen Anak</div>
                    <div class="value"><?= $assessment['child_total'] ?></div>
                    <div class="max">dari 60</div>
                </div>
                <div class="score-box parent">
                    <div class="label">Instrumen Orangtua</div>
                    <div class="value"><?= $assessment['parent_total'] ?></div>
                    <div class="max">dari 48</div>
                </div>
                <div class="score-box grand">
                    <div class="label">Total Skor</div>
                    <div class="value"><?= $assessment['grand_total'] ?></div>
                    <div class="max">dari 108</div>
                </div>
            </div>

            <!-- Recommendation Banner -->
            <?php if($assessment['is_recommended'] == 1): ?>
            <div class="rec-banner ok">
                ✓ DIREKOMENDASIKAN — Kandidat memenuhi kriteria Program Anak Asuh YPSIM
            </div>
            <?php else: ?>
            <div class="rec-banner no">
                ✗ TIDAK DIREKOMENDASIKAN — Kandidat belum memenuhi kriteria
            </div>
            <?php endif; ?>

            <!-- Instrumen 1: Anak Asuh -->
            <div class="section-wrap">
                <div class="section-title">
                    <div class="bar blue"></div>
                    Instrumen 1 — Penilaian Calon Anak Asuh
                </div>
                <?php $childNum = 1; foreach($childAnswers as $a): ?>
                <div class="q-row">
                    <div class="q-num"><?= $childNum++ ?></div>
                    <div class="q-text"><?= htmlspecialchars($a['question_text']) ?></div>
                    <div class="q-score s<?= $a['score'] ?>"><?= $a['score'] ?></div>
                </div>
                <?php endforeach; ?>

                <div class="section-subtotal child">
                    <span>Subtotal Instrumen Anak Asuh</span>
                    <span><?= $assessment['child_total'] ?> / 60</span>
                </div>

                <?php if(!empty($assessment['child_notes'])): ?>
                <div class="notes-wrap blue">
                    <div class="notes-label">📝 Catatan Asesor — Anak Asuh</div>
                    <div class="notes-text"><?= htmlspecialchars($assessment['child_notes']) ?></div>
                </div>
                <?php endif; ?>
            </div>

            <hr class="divider">

            <!-- Instrumen 2: Orangtua -->
            <div class="section-wrap">
                <div class="section-title">
                    <div class="bar green"></div>
                    Instrumen 2 — Penilaian Orangtua
                </div>
                <?php $parentNum = 1; foreach($parentAnswers as $a): ?>
                <div class="q-row">
                    <div class="q-num"><?= $parentNum++ ?></div>
                    <div class="q-text"><?= htmlspecialchars($a['question_text']) ?></div>
                    <div class="q-score s<?= $a['score'] ?>"><?= $a['score'] ?></div>
                </div>
                <?php endforeach; ?>

                <div class="section-subtotal parent">
                    <span>Subtotal Instrumen Orangtua</span>
                    <span><?= $assessment['parent_total'] ?> / 48</span>
                </div>

                <?php if(!empty($assessment['parent_notes'])): ?>
                <div class="notes-wrap green">
                    <div class="notes-label">📝 Catatan Asesor — Orangtua</div>
                    <div class="notes-text"><?= htmlspecialchars($assessment['parent_notes']) ?></div>
                </div>
                <?php endif; ?>
            </div>

            <hr class="divider">

            <!-- Signature -->
            <div class="signature-row">
                <div class="sig-box">
                    <div class="sig-label">Diketahui oleh</div>
                    <div class="sig-line">Koordinator Program</div>
                    <div class="sig-role">YPSIM — Tim Seleksi</div>
                </div>
                <div class="sig-box">
                    <div class="sig-label">Pewawancara</div>
                    <div class="sig-line"><?= htmlspecialchars($s['interviewer_name'] ?? '________________________') ?></div>
                    <div class="sig-role">Asesor</div>
                </div>
            </div>

            <!-- Footer -->
            <div class="report-footer">
                <p>PAABS v1.0 — Sistem Seleksi Program Anak Asuh YPSIM | Abdul Muis, S.T., M.Kom.</p>
                <p>Dicetak: <?= $printDate ?> • Yayasan Sultan Iskandar Muda</p>
            </div>

        </div>
    </div>

</body>
</html>
