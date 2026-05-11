<?php
// =============================================
// api/config.php
// =============================================

define('DB_HOST', 'sql213.infinityfree.com');
define('DB_NAME', 'if0_41875047_fixit_db');
define('DB_USER', 'if0_41875047');
define('DB_PASS', 'vE3e8pOK2aPjTym');
define('DB_CHAR', 'utf8mb4');
define('TOKEN_EXPIRE_HOURS', 24);

// Upload settings
define('UPLOAD_DIR',      __DIR__ . '/../uploads/');
define('UPLOAD_REPORTS',  __DIR__ . '/../uploads/reports/');
define('UPLOAD_AVATARS',  __DIR__ . '/../uploads/avatars/');
define('UPLOAD_URL', 'https://ecoguardian.free.nf//uploads/');
define('MAX_FILE_SIZE',   5 * 1024 * 1024); // 5MB
define('ALLOWED_TYPES',   ['image/jpeg','image/png','image/webp','image/gif']);

// CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ─── Database ─────────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHAR;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            jsonResponse(['success'=>false,'message'=>'Database connection failed'], 500);
        }
    }
    return $pdo;
}

// ─── Helpers ──────────────────────────────────────────────
function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function getAuthUser(): ?array {
    $token = '';

    // Cara 1: Header Authorization (standar)
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['HTTP_AUTHORIZATION'];
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            $token = trim($m[1]);
        }

    // Cara 2: Redirect header (LiteSpeed InfinityFree)
    } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            $token = trim($m[1]);
        }

    // Cara 3: Query string fallback (?_token=xxx)
    } elseif (!empty($_GET['_token'])) {
        $token = trim($_GET['_token']);
    }

    // Kalau token kosong, return null
    if (!$token) return null;

    // Cari user berdasarkan token di database
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT u.* FROM users u
         JOIN sessions s ON s.user_id = u.id
         WHERE s.token = ? AND s.expires_at > NOW() AND u.is_active = 1 LIMIT 1'
    );
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}
function requireAuth(): array {
    $user = getAuthUser();
    if (!$user) jsonResponse(['success'=>false,'message'=>'Unauthorized'], 401);
    return $user;
}

function requireAdmin(): array {
    $user = requireAuth();
    if ($user['role'] !== 'admin') jsonResponse(['success'=>false,'message'=>'Forbidden'], 403);
    return $user;
}

function generateToken(): string { return bin2hex(random_bytes(32)); }

function sanitize(string $val): string {
    return htmlspecialchars(strip_tags(trim($val)));
}

// ─── Upload file helper ────────────────────────────────────
function handleUpload(string $fieldName, string $destDir): ?string {
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) return null;

    $file = $_FILES[$fieldName];
    if ($file['size'] > MAX_FILE_SIZE) jsonResponse(['success'=>false,'message'=>'Ukuran file maksimal 5MB'], 422);

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, ALLOWED_TYPES)) jsonResponse(['success'=>false,'message'=>'Format file tidak didukung. Gunakan JPG, PNG, atau WEBP'], 422);

    // Buat folder jika belum ada
    if (!is_dir($destDir)) mkdir($destDir, 0755, true);

    $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid('img_', true) . '.' . strtolower($ext);
    $destPath = $destDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) jsonResponse(['success'=>false,'message'=>'Gagal menyimpan file'], 500);

    return $filename;
}