<?php
// =============================================
// api/auth.php
// POST ?action=register
// POST ?action=login
// POST ?action=logout
// GET  ?action=me
// POST ?action=update_profile  (name + avatar)
// =============================================
require_once __DIR__ . '/config.php';

$action = $_GET['action'] ?? '';

// Untuk multipart (upload avatar), body dari $_POST bukan php://input
$body = [];
if ($_SERVER['CONTENT_TYPE'] ?? '' === 'application/json') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
} else {
    $body = $_POST;
}

switch ($action) {

    // ─── REGISTER ─────────────────────────────────────────
    case 'register':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['success'=>false,'message'=>'Method not allowed'], 405);
        $body  = json_decode(file_get_contents('php://input'), true) ?? [];
        $name  = sanitize($body['name']  ?? '');
        $email = strtolower(trim($body['email'] ?? ''));
        $pass  = $body['password'] ?? '';
        if (!$name || !$email || !$pass) jsonResponse(['success'=>false,'message'=>'Semua field wajib diisi'], 422);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonResponse(['success'=>false,'message'=>'Format email tidak valid'], 422);
        if (strlen($pass) < 6) jsonResponse(['success'=>false,'message'=>'Password minimal 6 karakter'], 422);
        $db = getDB();
        $chk = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $chk->execute([$email]);
        if ($chk->fetch()) jsonResponse(['success'=>false,'message'=>'Email sudah terdaftar'], 409);
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $db->prepare('INSERT INTO users (name, email, password) VALUES (?, ?, ?)')->execute([$name, $email, $hash]);
        $userId    = (int)$db->lastInsertId();
        $token     = generateToken();
        $expiresAt = date('Y-m-d H:i:s', strtotime('+'.TOKEN_EXPIRE_HOURS.' hours'));
        $db->prepare('INSERT INTO sessions (user_id, token, expires_at) VALUES (?, ?, ?)')->execute([$userId, $token, $expiresAt]);
        jsonResponse(['success'=>true,'message'=>'Registrasi berhasil','token'=>$token,'user'=>['id'=>$userId,'name'=>$name,'email'=>$email,'role'=>'user','avatar'=>null]], 201);
        break;

    // ─── LOGIN ─────────────────────────────────────────────
    case 'login':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['success'=>false,'message'=>'Method not allowed'], 405);
        $body  = json_decode(file_get_contents('php://input'), true) ?? [];
        $email = strtolower(trim($body['email'] ?? ''));
        $pass  = $body['password'] ?? '';
        if (!$email || !$pass) jsonResponse(['success'=>false,'message'=>'Email dan password wajib diisi'], 422);
        $db   = getDB();
        $stmt = $db->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($pass, $user['password'])) jsonResponse(['success'=>false,'message'=>'Email atau password salah'], 401);
        $db->prepare('DELETE FROM sessions WHERE user_id = ? AND expires_at <= NOW()')->execute([$user['id']]);
        $token     = generateToken();
        $expiresAt = date('Y-m-d H:i:s', strtotime('+'.TOKEN_EXPIRE_HOURS.' hours'));
        $db->prepare('INSERT INTO sessions (user_id, token, expires_at) VALUES (?, ?, ?)')->execute([$user['id'], $token, $expiresAt]);
        jsonResponse(['success'=>true,'message'=>'Login berhasil','token'=>$token,'user'=>['id'=>$user['id'],'name'=>$user['name'],'email'=>$user['email'],'role'=>$user['role'],'avatar'=>$user['avatar']]]);
        break;

    // ─── LOGOUT ────────────────────────────────────────────
    case 'logout':
        $user    = requireAuth();
        $headers = getallheaders();
        $auth    = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        preg_match('/Bearer\s+(.+)/i', $auth, $m);
        $token   = trim($m[1] ?? '');
        getDB()->prepare('DELETE FROM sessions WHERE token = ?')->execute([$token]);
        jsonResponse(['success'=>true,'message'=>'Logout berhasil']);
        break;

    // ─── ME ────────────────────────────────────────────────
    case 'me':
        $user = requireAuth();
        $db   = getDB();
        $c    = $db->prepare('SELECT status, COUNT(*) as cnt FROM reports WHERE user_id = ? GROUP BY status');
        $c->execute([$user['id']]);
        $counts = ['pending'=>0,'in_progress'=>0,'resolved'=>0,'rejected'=>0];
        foreach ($c->fetchAll() as $r) $counts[$r['status']] = (int)$r['cnt'];
        $avatarUrl = $user['avatar'] ? UPLOAD_URL . 'avatars/' . $user['avatar'] : null;
        jsonResponse(['success'=>true,'user'=>['id'=>$user['id'],'name'=>$user['name'],'email'=>$user['email'],'role'=>$user['role'],'avatar'=>$avatarUrl,'created_at'=>$user['created_at']],'stats'=>$counts]);
        break;

    // ─── UPDATE PROFILE ────────────────────────────────────
    case 'update_profile':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['success'=>false,'message'=>'Method not allowed'], 405);
        $user = requireAuth();
        $db   = getDB();
        $name = sanitize($_POST['name'] ?? $user['name']);

        // Handle avatar upload
        $avatarFile = $user['avatar'];
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            // Hapus avatar lama
            if ($user['avatar'] && file_exists(UPLOAD_AVATARS . $user['avatar'])) {
                unlink(UPLOAD_AVATARS . $user['avatar']);
            }
            $avatarFile = handleUpload('avatar', UPLOAD_AVATARS);
        }

        $db->prepare('UPDATE users SET name = ?, avatar = ? WHERE id = ?')->execute([$name, $avatarFile, $user['id']]);
        $avatarUrl = $avatarFile ? UPLOAD_URL . 'avatars/' . $avatarFile : null;
        jsonResponse(['success'=>true,'message'=>'Profil berhasil diupdate','user'=>['id'=>$user['id'],'name'=>$name,'email'=>$user['email'],'role'=>$user['role'],'avatar'=>$avatarUrl]]);
        break;

    default:
        jsonResponse(['success'=>false,'message'=>'Action tidak dikenal'], 404);
}