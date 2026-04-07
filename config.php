<?php
// Set Timezone ke Waktu Indonesia Barat (Jakarta)
date_default_timezone_set('Asia/Jakarta');

// config.php - Professional Instrument Edition
// PENTING: Ubah bagian di bawah ini saat mengunggah ke HOSTING!
$host = 'localhost'; // Ganti dengan host database hosting (biasanya localhost)
$user = 'root';      // Ganti dengan username database hosting
$pass = '';          // Ganti dengan password database hosting
$db   = 'paabs_db';  // Ganti dengan nama database hosting (biasanya prefix_paabs_db)

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

// INSTRUMEN 1: CALON ANAK ASUH (15 SOAL)
$childSections = [
    'Motivasi / Minat Belajar' => [
        ['id' => 'c1', 'text' => 'Apa tujuan kamu sekolah? Apa usaha yang kamu lakukan untuk meningkatkan prestasi belajarmu di sekolah?'],
        ['id' => 'c2', 'text' => 'Apa alasan kamu ingin menjadi Anak Asuh? (keinginan orangtua/keinginan sendiri? temukan jawaban mereka selain karena faktor ekonomi)'],
        ['id' => 'c3', 'text' => 'Apa pencapaian terbaik yang pernah kamu peroleh sehingga dengan pencapaian tersebut kamu layak diterima di program anak asuh?'],
        ['id' => 'c4', 'text' => 'Selain belajar di sekolah, menurut kamu berapa lama perlu tambahan belajar di rumah untuk meningkatkan prestasi belajarmu? Jelaskan!'],
        ['id' => 'c5', 'text' => 'Apakah sekolah itu merupakan beban atau tantangan? Jelaskan'],
    ],
    'Moral / Etika & Keberagaman' => [
        ['id' => 'c6', 'text' => 'Belakangan ini sering kita dengar terjadi berbagai kenakalan remaja. Apa yang kamu lakukan untuk menghindari masalah tersebut? Boleh disebutkan dalam bentuk contoh!'],
        ['id' => 'c7', 'text' => 'Apa makna Kebhinekaan menurut kamu? Dan pandangan kamu terhadap teman yang berbeda suku, agama, ras dengan kamu? Ada tidak kriteria dalam memilih teman?'],
        ['id' => 'c8', 'text' => 'Apa yang akan kamu lakukan terhadap seseorang atau institusi yang membantu kamu dalam pendidikan? (seperti dr. Sofyan Tan / YPSIM)'],
        ['id' => 'c9', 'text' => 'Adakah sosok pemimpin idolamu? Sebutkan!'],
        ['id' => 'c10', 'text' => 'Melihat Dasar Negara Indonesia Pancasila, apakah sesuai dengan yang diajarkan di Agama dan kehidupan keluargamu?'],
    ],
    'Kejujuran' => [
        ['id' => 'c11', 'text' => 'Apa yang kamu lakukan untuk membantu mengatasi keadaan ekonomi keluargamu?'],
        ['id' => 'c12', 'text' => 'Berapa Uang Jajanmu sehari? (semakin besar uang jajan semakin kecil nilainya)'],
        ['id' => 'c13', 'text' => 'Jika kamu tidak lulus menjadi anak asuh di YP. Sultan Iskandar Muda, apa yang akan kamu lakukan?'],
        ['id' => 'c14', 'text' => 'Kira-kira kamu tau tidak kenapa orangtua sampai harus butuh bantuan finansial untuk sekolah kamu di Program Anak Asuh Sultan Iskandar Muda?'],
        ['id' => 'c15', 'text' => 'Apa yang kamu ketahui tentang Program Anak Asuh/Konsep Pendidikan di Sultan Iskandar Muda dan dr. Sofyan Tan? Jelaskan!'],
    ]
];

// INSTRUMEN 2: ORANGTUA (12 SOAL)
$parentSections = [
    'Keseharian Anak dan Peran Orangtua' => [
        ['id' => 'p1', 'text' => 'Apakah Bapak/Ibu di rumah memberi tugas khusus untuk anak ibu?'],
        ['id' => 'p2', 'text' => 'Sejauh apa Bapak/Ibu mengenali teman-teman sekolah atau teman rumah anak Bapak/Ibu?'],
        ['id' => 'p3', 'text' => 'Seberapa sering Bapak/Ibu melakukan cek Ponsel anak atau berkomunikasi dengan anak?'],
        ['id' => 'p4', 'text' => 'Boleh jelaskan sejauh mana peran dan tugas orangtua dalam pembelajaran anak? Sekaligus jelaskan yang telah Bapak/Ibu lakukan!'],
    ],
    'Ekonomi Keluarga' => [
        ['id' => 'p5', 'text' => 'Menurut Anda, siapa yang bertanggungjawab terhadap sekolah anak Bapak/Ibu?'],
        ['id' => 'p6', 'text' => 'Jika orangtua menjawab tanggungjawab mereka (Orangtua). Lanjutkan pertanyaan dengan "Jadi kenapa Bapak/Ibu daftar Program Anak Asuh? Berarti berubah jadi tanggungjawab Program kan? Boleh jelaskan tanggapannya akan hal tersebut?'],
        ['id' => 'p7', 'text' => 'Apakah kelebihan Anak Bapak/Ibu dibandingkan anak lainnya sehingga Program Anak Asuh harus memilih anak anda?'],
        ['id' => 'p8', 'text' => 'Apa pekerjaan sehari-hari Bapak/Ibu untuk memenuhi kebutuhan keluarga? Apakah ada anak Bapak-Ibu yang sudah bekerja membantu perekonomian keluarga?'],
    ],
    'Keberagaman' => [
        ['id' => 'p9', 'text' => 'Apa pendapat Bapak/Ibu tentang keberagaman yang ada di Sultan Iskandar Muda? apakah sesuai dengan yang diajarkan di Agama dan kehidupan keluarga Bapak/Ibu?'],
    ],
    'Komitmen' => [
        ['id' => 'p10', 'text' => 'Jika anak Bapak/Ibu gagal menjadi anak asuh, apa yang akan anda lakukan?'],
        ['id' => 'p11', 'text' => 'Kira-kira berapa uang sekolah yang anda sanggup bayar untuk sekolah anak Bapak/Ibu? (Tuliskan besaran di catatan khusus)'],
        ['id' => 'p12', 'text' => 'Apakah Bapak/Ibu akan selalu luangkan waktu jika diundang di acara sekolah?'],
    ]
];

// Flatten arrays for easy searching in legacy code
$childQuestions = [];
foreach($childSections as $questions) $childQuestions = array_merge($childQuestions, $questions);

$parentQuestions = [];
foreach($parentSections as $questions) $parentQuestions = array_merge($parentQuestions, $questions);
?>
