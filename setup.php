<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

session_name('ruang_novel');
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'use_strict_mode' => true,
]);

if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Halaman setup hanya dapat dibuka dari komputer Laragon (localhost).');
}

$error = '';
$initialized = false;
$adminExists = false;
$demoAccountsCreated = false;
$csrf = $_SESSION['setup_csrf'] ??= bin2hex(random_bytes(32));

try {
    $host = getenv('NOVEL_DB_HOST') ?: DB_HOST;
    $port = getenv('NOVEL_DB_PORT') ?: DB_PORT;
    $name = getenv('NOVEL_DB_NAME') ?: DB_NAME;
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new RuntimeException('Nama database tidak valid.');
    }
    $user = getenv('NOVEL_DB_USER') ?: DB_USER;
    $password = getenv('NOVEL_DB_PASSWORD');
    if ($password === false) {
        $password = DB_PASSWORD;
    }
    $server = new PDO(
        sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $host, $port),
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $server->exec(sprintf(
        'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        str_replace('`', '``', $name)
    ));
    $schema = file_get_contents(__DIR__ . '/schema.sql');
    if ($schema === false) {
        throw new RuntimeException('File schema.sql tidak dapat dibaca.');
    }
    $statements = preg_split('/;\s*(?:\r?\n|$)/', preg_replace('/^\s*--.*$/m', '', $schema) ?: '');
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement === '' || preg_match('/^(CREATE DATABASE|USE)\b/i', $statement)) {
            continue;
        }
        database()->exec($statement);
    }
    $ownerColumn = database()->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'novels' AND COLUMN_NAME = 'owner_user_id'"
    );
    if ((int) $ownerColumn->fetchColumn() === 0) {
        database()->exec(
            'ALTER TABLE novels
             ADD COLUMN owner_user_id INT UNSIGNED NULL,
             ADD INDEX novels_owner_user_id_index (owner_user_id),
             ADD CONSTRAINT novels_owner_user_fk FOREIGN KEY (owner_user_id)
                 REFERENCES users (id) ON DELETE SET NULL'
        );
    }
    database()->exec(
        'CREATE TABLE IF NOT EXISTS novel_chapters (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            novel_id INT UNSIGNED NOT NULL,
            chapter_number SMALLINT UNSIGNED NOT NULL,
            title VARCHAR(120) NOT NULL,
            content MEDIUMTEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY novel_chapters_number_unique (novel_id, chapter_number),
            CONSTRAINT novel_chapters_novel_fk FOREIGN KEY (novel_id) REFERENCES novels (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $initialized = true;
    $userCount = (int) database()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($userCount === 0) {
        $insert = database()->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)');
        $insert->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT), 'admin']);
        $insert->execute(['user', password_hash('user1234', PASSWORD_DEFAULT), 'user']);
        $demoAccountsCreated = true;
    }
    $adminExists = (int) database()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0;
} catch (Throwable $exception) {
    error_log('Ruang Novel setup failed: ' . $exception->getMessage());
    $error = 'Database belum dapat disiapkan. Pastikan MySQL Laragon aktif dan pengaturan database.php sesuai.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $initialized && !$adminExists) {
    $sentCsrf = (string) ($_POST['csrf'] ?? '');
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if (!hash_equals($csrf, $sentCsrf)) {
        $error = 'Sesi setup tidak valid. Muat ulang halaman dan coba lagi.';
    } elseif (!preg_match('/^[\p{L}\p{N}_.-]{3,60}$/u', $username)) {
        $error = 'Nama pengguna harus 3–60 karakter: huruf, angka, titik, garis bawah, atau tanda hubung.';
    } elseif (strlen($password) < 8) {
        $error = 'Kata sandi admin harus minimal 8 karakter.';
    } else {
        try {
            $insert = database()->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)');
            $insert->execute([$username, password_hash($password, PASSWORD_DEFAULT), 'admin']);
            $adminExists = true;
            session_regenerate_id(true);
        } catch (PDOException $exception) {
            error_log('Ruang Novel initial admin setup failed: ' . $exception->getMessage());
            $error = 'Akun admin tidak dapat dibuat. Periksa database lalu coba lagi.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Setup Ruang Novel</title>
  <link rel="stylesheet" href="style.css">
  <style>
    body{min-height:100vh;display:grid;place-items:center;padding:24px;background:#f7f6f1}
    .setup-card{width:min(100%,470px);padding:32px;border:1px solid #e9e9e2;border-radius:12px;background:#fff;box-shadow:0 16px 45px rgba(40,56,46,.08)}
    .setup-card h1{margin:0 0 10px;font-family:'Playfair Display',serif;font-size:32px;font-weight:500}
    .setup-card .muted{font-size:13px}
    .setup-card .button{width:100%;margin-top:18px}
    .setup-success{padding:16px;border-radius:8px;background:#eaf2e9;color:#426b57;line-height:1.7}
    .setup-error{margin:16px 0;padding:12px;border-radius:7px;background:#fbefed;color:#a33d37;font-size:12px;line-height:1.6}
    .setup-link{display:inline-block;margin-top:15px;color:#426b57;font-weight:600}
  </style>
</head>
<body>
  <main class="setup-card">
    <p class="eyebrow">RUANG NOVEL · PENGATURAN AWAL</p>
    <h1>Siapkan perpustakaan.</h1>
    <p class="muted">Database <strong>novel</strong> serta tabel akun dan novel disiapkan otomatis menggunakan pengaturan Laragon di <code>database.php</code>.</p>
    <?php if ($error !== ''): ?>
      <p class="setup-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <?php if ($adminExists): ?>
      <div class="setup-success"><strong>Setup selesai.</strong><br>
        <?php if ($demoAccountsCreated): ?>
          Akun demo sudah dibuat:
          <ul>
            <li>Admin — nama pengguna <code>admin</code>, kata sandi <code>admin123</code></li>
            <li>User — nama pengguna <code>user</code>, kata sandi <code>user1234</code></li>
          </ul>
          Segera ganti kata sandi demo sebelum aplikasi digunakan bersama.
        <?php else: ?>
          Akun admin sudah tersedia. Admin dapat membuat akun user melalui menu Kelola akun.
        <?php endif; ?>
        Pembuatan admin awal telah dikunci.
      </div>
      <a class="setup-link" href="index.html">Buka halaman login →</a>
    <?php elseif ($initialized): ?>
      <p class="muted">Buat akun admin pertama. Kata sandi disimpan dalam bentuk hash.</p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <label for="username">Nama pengguna admin</label>
        <input id="username" name="username" type="text" minlength="3" maxlength="60" autocomplete="username" required>
        <label for="password">Kata sandi admin</label>
        <input id="password" name="password" type="password" minlength="8" autocomplete="new-password" required>
        <button class="button button-primary" type="submit">Buat akun admin <span aria-hidden="true">→</span></button>
      </form>
    <?php endif; ?>
  </main>
</body>
</html>
