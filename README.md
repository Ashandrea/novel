# Ruang Novel

Aplikasi katalog novel berbasis HTML, CSS, JavaScript, PHP, dan MySQL/MariaDB untuk Laragon.

## Menjalankan di Laragon

1. Salin folder proyek ke direktori web Laragon, biasanya `C:\laragon\www\ruang-novel`.
2. Jalankan **Apache** dan **MySQL** dari Laragon.
3. Buka `http://localhost/ruang-novel/setup.php`. Halaman setup akan membuat database `novel` beserta tabel yang dibutuhkan.
   - Jika lebih suka membuat database secara manual, impor [`schema.sql`](./schema.sql) melalui phpMyAdmin atau HeidiSQL sebelum membuka halaman setup.
4. Pada database baru, halaman setup membuat akun demo dengan kata sandi yang disimpan dalam bentuk hash:
   - Admin: `admin` / `admin123`
   - User: `user` / `user1234`
5. Buka `http://localhost/ruang-novel/` lalu masuk menggunakan salah satu akun di atas.
6. Pengunjung dapat mendaftar dari halaman login; akun yang didaftarkan otomatis memiliki peran **User**. Ganti kata sandi demo sebelum aplikasi digunakan bersama. Dari menu **Kelola akun**, admin dapat membuat akun tambahan dengan peran **Admin** atau **User**.

## Hak akses

- **Admin:** melihat katalog semua pengguna, menambah/mengubah/menghapus novel, serta membuat/mengubah/menghapus akun. Novel yang dibuat user menampilkan nama pemiliknya.
- **User:** melihat, mencari, dan memfilter katalog; membuat novel dengan isi per bab; serta mengubah informasi dan menambahkan bab atau menghapus novel miliknya sendiri. User tidak dapat mengelola novel pengguna lain.
- Setiap novel baru harus memiliki sedikitnya satu bab; maksimal 100 bab dan 100.000 karakter per bab.
- **Pendaftaran mandiri:** hanya membuat akun **User**; akun Admin tetap dibuat melalui setup atau oleh admin yang sudah masuk.

## Konfigurasi database

Pengaturan bawaan cocok untuk instalasi Laragon lokal standar: host `127.0.0.1`, port `3306`, pengguna `root`, kata sandi kosong, dan database `novel`. Sesuaikan konstanta di [`database.php`](./database.php) atau set environment variables `NOVEL_DB_HOST`, `NOVEL_DB_PORT`, `NOVEL_DB_NAME`, `NOVEL_DB_USER`, dan `NOVEL_DB_PASSWORD`.

Jangan gunakan konfigurasi root/kata sandi kosong untuk server publik. Gunakan akun database khusus dengan hak minimum dan aktifkan HTTPS.
