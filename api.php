<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
session_name('ruang_novel');
session_start([
    'cookie_httponly' => true,
    'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'cookie_samesite' => 'Lax',
    'use_strict_mode' => true,
]);

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_data(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        respond(400, ['error' => 'Format permintaan tidak valid.']);
    }
    return $data;
}

function require_method(string ...$allowed): void
{
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        respond(405, ['error' => 'Metode permintaan tidak diizinkan.']);
    }
}

function current_user(): ?array
{
    $id = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) {
        return null;
    }
    $statement = database()->prepare('SELECT id, username, role FROM users WHERE id = ?');
    $statement->execute([$id]);
    $user = $statement->fetch();
    if (!$user) {
        $_SESSION = [];
        session_regenerate_id(true);
        return null;
    }
    return $user;
}

function require_auth(): array
{
    $user = current_user();
    if ($user === null) {
        respond(401, ['error' => 'Silakan masuk terlebih dahulu.']);
    }
    return $user;
}

function require_admin(): array
{
    $user = require_auth();
    if ($user['role'] !== 'admin') {
        respond(403, ['error' => 'Akses ini hanya tersedia untuk admin.']);
    }
    return $user;
}

function require_csrf(): void
{
    $expected = (string) ($_SESSION['csrf_token'] ?? '');
    $actual = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($expected === '' || !hash_equals($expected, $actual)) {
        respond(403, ['error' => 'Sesi keamanan tidak valid. Muat ulang halaman dan coba lagi.']);
    }
}

function text_length(string $value): int
{
    preg_match_all('/./us', $value, $matches);
    return count($matches[0]);
}

function validate_text(mixed $value, string $label, int $max, bool $required = true): string
{
    if (!is_string($value)) {
        if ($value === null && !$required) {
            return '';
        }
        respond(422, ['error' => $label . ' tidak valid.']);
    }
    $value = trim($value);
    $length = text_length($value);
    if (($required && $length === 0) || $length > $max) {
        respond(422, ['error' => $label . ' wajib diisi dan maksimal ' . $max . ' karakter.']);
    }
    return $value;
}

function validate_username(mixed $value): string
{
    $username = validate_text($value, 'Nama pengguna', 60);
    if (!preg_match('/^[\p{L}\p{N}_.-]{3,60}$/u', $username)) {
        respond(422, ['error' => 'Nama pengguna harus 3–60 karakter: huruf, angka, titik, garis bawah, atau tanda hubung.']);
    }
    return $username;
}

function valid_id(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        respond(422, ['error' => 'ID tidak valid.']);
    }
    return $id;
}

function handle_login(array $data): never
{
    require_method('POST');
    $username = validate_username($data['username'] ?? null);
    $password = $data['password'] ?? null;
    if (!is_string($password)) {
        respond(422, ['error' => 'Kata sandi tidak valid.']);
    }
    $statement = database()->prepare('SELECT id, username, password_hash, role FROM users WHERE username = ? LIMIT 1');
    $statement->execute([$username]);
    $user = $statement->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        respond(401, ['error' => 'Nama pengguna atau kata sandi tidak sesuai.']);
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    respond(200, [
        'user' => ['id' => (int) $user['id'], 'username' => $user['username'], 'role' => $user['role']],
        'csrf' => $_SESSION['csrf_token'],
    ]);
}

function handle_register(array $data): never
{
    require_method('POST');
    $username = validate_username($data['username'] ?? null);
    $password = $data['password'] ?? null;
    if (!is_string($password) || strlen($password) < 8) {
        respond(422, ['error' => 'Kata sandi harus minimal 8 karakter.']);
    }

    $statement = database()->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)');
    $statement->execute([$username, password_hash($password, PASSWORD_DEFAULT), 'user']);
    $userId = (int) database()->lastInsertId();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    respond(201, [
        'user' => ['id' => $userId, 'username' => $username, 'role' => 'user'],
        'csrf' => $_SESSION['csrf_token'],
    ]);
}

function handle_novels(string $method, array $data): never
{
    if ($method === 'GET') {
        require_auth();
        $novels = database()->query(
            'SELECT novels.id, novels.title, novels.author, novels.genre,
                    novels.publication_year AS year, novels.status, novels.description,
                    novels.created_at AS createdAt, users.username AS ownerUsername,
                    novels.owner_user_id AS ownerUserId,
                    (SELECT COUNT(*) FROM novel_chapters WHERE novel_id = novels.id) AS chapterCount
             FROM novels
             LEFT JOIN users ON users.id = novels.owner_user_id
             ORDER BY novels.created_at DESC, novels.id DESC'
        )->fetchAll();
        foreach ($novels as &$novel) {
            $novel['id'] = (string) $novel['id'];
            $novel['year'] = $novel['year'] === null ? '' : (string) $novel['year'];
        }
        unset($novel);
        respond(200, ['novels' => $novels]);
    }

    $user = require_auth();
    require_csrf();
    if ($method === 'POST' || $method === 'PUT') {
        $id = $method === 'PUT' ? valid_id($data['id'] ?? null) : null;
        if ($id !== null) {
            require_novel_manager($id, $user);
        }
        $title = validate_text($data['title'] ?? null, 'Judul novel', 100);
        $author = validate_text($data['author'] ?? null, 'Nama penulis', 80);
        $genre = validate_text($data['genre'] ?? '', 'Genre', 40, false);
        $description = validate_text($data['description'] ?? '', 'Catatan', 240, false);
        $year = $data['year'] ?? '';
        if ($year === '' || $year === null) {
            $year = null;
        } elseif (!is_numeric($year) || (int) $year < 1 || (int) $year > 2100) {
            respond(422, ['error' => 'Tahun terbit harus antara 1 dan 2100.']);
        } else {
            $year = (int) $year;
        }
        $status = $data['status'] ?? '';
        if (!in_array($status, ['Belum dibaca', 'Sedang dibaca', 'Selesai'], true)) {
            respond(422, ['error' => 'Status membaca tidak valid.']);
        }
        $chapters = $data['chapters'] ?? ($method === 'POST' ? null : []);
        if (!is_array($chapters) || ($method === 'POST' && count($chapters) < 1) || count($chapters) > 100) {
            respond(422, ['error' => 'Novel harus memiliki 1 sampai 100 bab baru.']);
        }
        $validatedChapters = [];
        foreach ($chapters as $index => $chapter) {
            if (!is_array($chapter)) {
                respond(422, ['error' => 'Data bab ke-' . ($index + 1) . ' tidak valid.']);
            }
            $chapterTitle = validate_text($chapter['title'] ?? null, 'Judul bab ke-' . ($index + 1), 120);
            $content = validate_text($chapter['content'] ?? null, 'Isi bab ke-' . ($index + 1), 100000);
            $validatedChapters[] = [$chapterTitle, $content];
        }

        $connection = database();
        if ($method === 'POST') {
            $connection->beginTransaction();
            try {
                $statement = $connection->prepare(
                    'INSERT INTO novels (title, author, genre, publication_year, status, description, owner_user_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $statement->execute([$title, $author, $genre, $year, $status, $description, $user['id']]);
                $novelId = (int) $connection->lastInsertId();
                insert_novel_chapters($connection, $novelId, 0, $validatedChapters);
                $connection->commit();
            } catch (Throwable $exception) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
                throw $exception;
            }
            respond(201, ['message' => 'Novel berhasil ditambahkan.']);
        }

        $connection->beginTransaction();
        try {
            $novelLock = $connection->prepare('SELECT id FROM novels WHERE id = ? FOR UPDATE');
            $novelLock->execute([$id]);
            if (!$novelLock->fetchColumn()) {
                $connection->rollBack();
                respond(404, ['error' => 'Novel tidak ditemukan.']);
            }
            $chapterCountStatement = $connection->prepare(
                'SELECT COUNT(*) FROM novel_chapters WHERE novel_id = ? FOR UPDATE'
            );
            $chapterCountStatement->execute([$id]);
            $existingChapterCount = (int) $chapterCountStatement->fetchColumn();
            if ($existingChapterCount + count($validatedChapters) > 100) {
                $connection->rollBack();
                respond(422, ['error' => 'Jumlah bab novel tidak boleh melebihi 100.']);
            }
            $statement = $connection->prepare(
                'UPDATE novels SET title = ?, author = ?, genre = ?, publication_year = ?, status = ?, description = ? WHERE id = ?'
            );
            $statement->execute([$title, $author, $genre, $year, $status, $description, $id]);
            insert_novel_chapters($connection, $id, $existingChapterCount, $validatedChapters);
            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
        respond(200, ['message' => count($validatedChapters) > 0
            ? 'Novel berhasil diperbarui dan bab baru ditambahkan.'
            : 'Detail novel berhasil diperbarui.']);
    }

    if ($method === 'DELETE') {
        $id = valid_id($data['id'] ?? null);
        require_novel_manager($id, $user);
        $statement = database()->prepare('DELETE FROM novels WHERE id = ?');
        $statement->execute([$id]);
        respond(200, ['message' => 'Novel berhasil dihapus.']);
    }
    require_method('GET', 'POST', 'PUT', 'DELETE');
}

function require_novel_manager(int $novelId, array $user): void
{
    $statement = database()->prepare('SELECT owner_user_id FROM novels WHERE id = ?');
    $statement->execute([$novelId]);
    $ownerId = $statement->fetchColumn();
    if ($ownerId === false) {
        respond(404, ['error' => 'Novel tidak ditemukan.']);
    }
    if ($user['role'] !== 'admin' && (int) $ownerId !== (int) $user['id']) {
        respond(403, ['error' => 'User hanya dapat mengelola novel miliknya sendiri.']);
    }
}

function insert_novel_chapters(PDO $connection, int $novelId, int $existingChapterCount, array $chapters): void
{
    $statement = $connection->prepare(
        'INSERT INTO novel_chapters (novel_id, chapter_number, title, content) VALUES (?, ?, ?, ?)'
    );
    foreach ($chapters as $index => [$title, $content]) {
        $statement->execute([$novelId, $existingChapterCount + $index + 1, $title, $content]);
    }
}

function handle_story(array $query): never
{
    require_method('GET');
    require_auth();
    $id = valid_id($query['novelId'] ?? null);
    $statement = database()->prepare(
        'SELECT id, title, author, description FROM novels WHERE id = ?'
    );
    $statement->execute([$id]);
    $novel = $statement->fetch();
    if (!$novel) {
        respond(404, ['error' => 'Novel tidak ditemukan.']);
    }
    $chaptersStatement = database()->prepare(
        'SELECT chapter_number AS chapterNumber, title, content
         FROM novel_chapters WHERE novel_id = ? ORDER BY chapter_number'
    );
    $chaptersStatement->execute([$id]);
    respond(200, [
        'novel' => $novel,
        'chapters' => $chaptersStatement->fetchAll(),
    ]);
}

function handle_accounts(string $method, array $data): never
{
    if ($method === 'GET') {
        require_admin();
        $accounts = database()->query('SELECT id, username, role, created_at AS createdAt FROM users ORDER BY username')->fetchAll();
        respond(200, ['accounts' => $accounts]);
    }
    $admin = require_admin();
    require_csrf();
    if ($method === 'POST') {
        $username = validate_username($data['username'] ?? null);
        $password = $data['password'] ?? null;
        if (!is_string($password) || strlen($password) < 8) {
            respond(422, ['error' => 'Kata sandi harus minimal 8 karakter.']);
        }
        $role = $data['role'] ?? null;
        if (!in_array($role, ['admin', 'user'], true)) {
            respond(422, ['error' => 'Peran akun tidak valid.']);
        }
        $statement = database()->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)');
        $statement->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role]);
        respond(201, ['message' => 'Akun berhasil dibuat.']);
    }
    if ($method === 'PUT') {
        $id = valid_id($data['id'] ?? null);
        $username = validate_username($data['username'] ?? null);
        $role = $data['role'] ?? null;
        if (!in_array($role, ['admin', 'user'], true)) {
            respond(422, ['error' => 'Peran akun tidak valid.']);
        }
        $existingStatement = database()->prepare('SELECT id, role FROM users WHERE id = ?');
        $existingStatement->execute([$id]);
        $existing = $existingStatement->fetch();
        if (!$existing) {
            respond(404, ['error' => 'Akun tidak ditemukan.']);
        }
        if ($id === (int) $admin['id'] && $role !== 'admin') {
            respond(422, ['error' => 'Peran admin yang sedang digunakan tidak dapat diubah.']);
        }
        if ($existing['role'] === 'admin' && $role !== 'admin') {
            $count = (int) database()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
            if ($count <= 1) {
                respond(422, ['error' => 'Harus ada setidaknya satu akun admin.']);
            }
        }
        $password = $data['password'] ?? '';
        if (!is_string($password)) {
            respond(422, ['error' => 'Kata sandi tidak valid.']);
        }
        if ($password !== '' && strlen($password) < 8) {
            respond(422, ['error' => 'Kata sandi baru harus minimal 8 karakter.']);
        }
        if ($password === '') {
            $statement = database()->prepare('UPDATE users SET username = ?, role = ? WHERE id = ?');
            $statement->execute([$username, $role, $id]);
        } else {
            $statement = database()->prepare('UPDATE users SET username = ?, role = ?, password_hash = ? WHERE id = ?');
            $statement->execute([$username, $role, password_hash($password, PASSWORD_DEFAULT), $id]);
        }
        respond(200, ['message' => 'Akun berhasil diperbarui.']);
    }
    if ($method === 'DELETE') {
        $id = valid_id($data['id'] ?? null);
        if ($id === (int) $admin['id']) {
            respond(422, ['error' => 'Admin tidak dapat menghapus akun yang sedang digunakan.']);
        }
        $target = database()->prepare('SELECT role FROM users WHERE id = ?');
        $target->execute([$id]);
        $role = $target->fetchColumn();
        if ($role === false) {
            respond(404, ['error' => 'Akun tidak ditemukan.']);
        }
        if ($role === 'admin') {
            $count = (int) database()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
            if ($count <= 1) {
                respond(422, ['error' => 'Harus ada setidaknya satu akun admin.']);
            }
        }
        $statement = database()->prepare('DELETE FROM users WHERE id = ?');
        $statement->execute([$id]);
        respond(200, ['message' => 'Akun berhasil dihapus.']);
    }
    require_method('GET', 'POST', 'PUT', 'DELETE');
}

try {
    $action = (string) ($_GET['action'] ?? '');
    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $data = request_data();

    if ($action === 'login') {
        handle_login($data);
    }
    if ($action === 'register') {
        handle_register($data);
    }
    if ($action === 'me') {
        require_method('GET');
        $user = current_user();
        respond(200, [
            'user' => $user ? ['id' => (int) $user['id'], 'username' => $user['username'], 'role' => $user['role']] : null,
            'csrf' => $_SESSION['csrf_token'] ?? null,
        ]);
    }
    if ($action === 'logout') {
        require_method('POST');
        require_auth();
        require_csrf();
        $_SESSION = [];
        session_regenerate_id(true);
        respond(200, ['message' => 'Anda telah keluar.']);
    }
    if ($action === 'novels') {
        handle_novels($method, $data);
    }
    if ($action === 'story') {
        handle_story($_GET);
    }
    if ($action === 'accounts') {
        handle_accounts($method, $data);
    }
    respond(404, ['error' => 'Endpoint tidak ditemukan.']);
} catch (PDOException $exception) {
    error_log('Ruang Novel database request failed: ' . $exception->getMessage());
    if ($exception->getCode() === '23000') {
        respond(409, ['error' => 'Nama pengguna tersebut sudah digunakan.']);
    }
    respond(500, ['error' => 'Terjadi masalah saat mengakses database. Pastikan database novel sudah disiapkan.']);
} catch (Throwable $exception) {
    error_log('Ruang Novel request failed: ' . $exception->getMessage());
    respond(500, ['error' => 'Terjadi kesalahan pada server. Silakan coba lagi.']);
}
