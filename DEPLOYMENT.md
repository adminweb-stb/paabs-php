# 📋 Catatan Deployment — PAABS

Dokumen ini berisi checklist dan catatan penting yang **wajib dibaca** sebelum melakukan deployment ke hosting/server produksi.

---

## ⚠️ Checklist Sebelum Deploy

### 1. Konfigurasi Database (`config.php`)
```php
$host = 'localhost';      // ← Sesuaikan dengan host hosting
$user = 'root';           // ← Ganti username database hosting
$pass = '';               // ← Masukkan password database hosting
$db   = 'paabs_db';       // ← Sesuaikan nama database (biasanya ada prefix: namauser_paabs_db)
```

---

### 2. Konfigurasi HTTPS (`auth.php` baris 13)

> **PENTING:** Jika server hosting sudah menggunakan **HTTPS** (URL diawali `https://`),  
> ubah nilai `secure` dari `false` menjadi `true`.

```php
// Sebelum (untuk HTTP / lokal):
'secure' => false,

// Sesudah (untuk HTTPS di hosting):
'secure' => true,
```

**Kenapa penting?**  
Jika `secure => true` tapi server pakai HTTP → asesor tidak bisa login (cookie tidak terkirim).  
Jika `secure => false` di HTTPS → cookie bisa dibaca via HTTP (kurang aman).

---

### 3. Import Database
- Jalankan file `paabs_db_compatible.sql` di phpMyAdmin hosting.
- Pastikan charset database adalah `utf8mb4`.

---

### 4. Hak Akses File (jika Linux server)
```bash
chmod 644 *.php
chmod 644 .htaccess
```

---

### 5. Cek `.htaccess`
Pastikan file `.htaccess` ter-upload dan berfungsi. Jika ada error 500, cek apakah `mod_rewrite` aktif di hosting.

---

## 🔑 Akun Default Admin

| Email | Role |
|-------|------|
| `admin@ypsim.com` | Admin |

> Password default sudah di-hash Bcrypt. Hubungi developer jika lupa password admin.

---

## 📁 File Kunci Sistem

| File | Fungsi |
|------|--------|
| `config.php` | Koneksi DB + daftar pertanyaan instrumen |
| `auth.php` | Session, lifetime, keamanan cookie |
| `autosave.php` | Endpoint AJAX auto-save per butir jawaban |
| `ping.php` | Heartbeat: jaga session tetap hidup |
| `interview.php` | Halaman formulir wawancara asesor |
| `save.php` | Submit akhir penilaian |

---

## 📞 Kontak Developer

Abdul Muis, S.T., M.Kom. — PAABS v1.0
