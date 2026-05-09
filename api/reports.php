<?php
// =============================================
// api/reports.php
// =============================================
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;

// Body: JSON atau multipart
$body = [];
$ct   = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($ct, 'application/json') !== false) {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
} else {
    $body = $_POST;
}

// ─── STATS (admin) ─────────────────────────────────────────
if ($action === 'stats') {
    requireAdmin();
    $db       = getDB();
    $total    = $db->query('SELECT COUNT(*) FROM reports')->fetchColumn();
    $byStatus = $db->query('SELECT status, COUNT(*) as cnt FROM reports GROUP BY status')->fetchAll();
    $recent   = $db->query(
        'SELECT r.*, u.name as user_name, u.avatar as user_avatar,
                c.name as category_name, c.icon as category_icon
         FROM reports r
         JOIN users u ON u.id = r.user_id
         JOIN categories c ON c.id = r.category_id
         ORDER BY r.created_at DESC LIMIT 5'
    )->fetchAll();

    $statusMap = ['pending'=>0,'in_progress'=>0,'resolved'=>0,'rejected'=>0];
    foreach ($byStatus as $row) $statusMap[$row['status']] = (int)$row['cnt'];

    // Tambah URL foto
    foreach ($recent as &$r) {
        $r['photo_url'] = $r['photo'] ? UPLOAD_URL . 'reports/' . $r['photo'] : null;
        $r['avatar_url'] = $r['user_avatar'] ? UPLOAD_URL . 'avatars/' . $r['user_avatar'] : null;
    }

    jsonResponse(['success'=>true,'total'=>(int)$total,'by_status'=>$statusMap,'recent'=>$recent]);
}

// ─── CATEGORIES ────────────────────────────────────────────
if ($action === 'categories') {
    requireAuth();
    $cats = getDB()->query('SELECT * FROM categories ORDER BY name')->fetchAll();
    jsonResponse(['success'=>true,'categories'=>$cats]);
}

// ─── LIST ──────────────────────────────────────────────────
if ($method === 'GET' && !$id && !$action) {
    $user   = requireAuth();
    $db     = getDB();
    $status = $_GET['status'] ?? '';
    $search = $_GET['search'] ?? '';
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = 10;
    $offset = ($page - 1) * $limit;

    $where = []; $params = [];
    if ($user['role'] !== 'admin') { $where[] = 'r.user_id = ?'; $params[] = $user['id']; }
    if ($status) { $where[] = 'r.status = ?'; $params[] = $status; }
    if ($search) { $where[] = '(r.title LIKE ? OR r.location LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
    $whereSQL = $where ? 'WHERE '.implode(' AND ',$where) : '';

    $cStmt = $db->prepare("SELECT COUNT(*) FROM reports r $whereSQL");
    $cStmt->execute($params);
    $total = (int)$cStmt->fetchColumn();

    $stmt = $db->prepare(
        "SELECT r.*, u.name as user_name, u.avatar as user_avatar,
                c.name as category_name, c.icon as category_icon
         FROM reports r
         JOIN users u ON u.id = r.user_id
         JOIN categories c ON c.id = r.category_id
         $whereSQL ORDER BY r.created_at DESC LIMIT $limit OFFSET $offset"
    );
    $stmt->execute($params);
    $reports = $stmt->fetchAll();

    foreach ($reports as &$r) {
        $r['photo_url']  = $r['photo']       ? UPLOAD_URL.'reports/'.$r['photo']       : null;
        $r['avatar_url'] = $r['user_avatar']  ? UPLOAD_URL.'avatars/'.$r['user_avatar'] : null;
    }

    jsonResponse(['success'=>true,'reports'=>$reports,'total'=>$total,'page'=>$page,'pages'=>(int)ceil($total/$limit)]);
}

// ─── DETAIL ────────────────────────────────────────────────
if ($method === 'GET' && $id) {
    $user = requireAuth();
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT r.*, u.name as user_name, u.avatar as user_avatar,
                c.name as category_name, c.icon as category_icon
         FROM reports r
         JOIN users u ON u.id = r.user_id
         JOIN categories c ON c.id = r.category_id
         WHERE r.id = ?'
    );
    $stmt->execute([$id]);
    $report = $stmt->fetch();
    if (!$report) jsonResponse(['success'=>false,'message'=>'Laporan tidak ditemukan'], 404);
    if ($user['role'] !== 'admin' && $report['user_id'] != $user['id']) jsonResponse(['success'=>false,'message'=>'Forbidden'], 403);
    $report['photo_url']  = $report['photo']       ? UPLOAD_URL.'reports/'.$report['photo']       : null;
    $report['avatar_url'] = $report['user_avatar']  ? UPLOAD_URL.'avatars/'.$report['user_avatar'] : null;
    $cmt = $db->prepare('SELECT rc.*, u.name as commenter FROM report_comments rc JOIN users u ON u.id = rc.user_id WHERE rc.report_id = ? ORDER BY rc.created_at ASC');
    $cmt->execute([$id]);
    $report['comments'] = $cmt->fetchAll();
    jsonResponse(['success'=>true,'report'=>$report]);
}

// ─── CREATE (multipart/form-data untuk support foto) ───────
if ($method === 'POST' && !$action) {
    $user = requireAuth();

    $title      = sanitize($body['title']       ?? '');
    $description= sanitize($body['description'] ?? '');
    $location   = sanitize($body['location']    ?? '');
    $categoryId = (int)($body['category_id']    ?? 0);
    $priority   = in_array($body['priority'] ?? '', ['low','medium','high']) ? $body['priority'] : 'medium';
    $lat        = $body['latitude']  ?? null;
    $lng        = $body['longitude'] ?? null;

    if (!$title || !$description || !$location || !$categoryId)
        jsonResponse(['success'=>false,'message'=>'Field wajib: title, description, location, category_id'], 422);

    // Handle foto laporan (opsional)
    $photoFile = null;
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $photoFile = handleUpload('photo', UPLOAD_REPORTS);
    }

    $db   = getDB();
    $stmt = $db->prepare(
        'INSERT INTO reports (user_id, category_id, title, description, location, latitude, longitude, priority, photo)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$user['id'], $categoryId, $title, $description, $location, $lat, $lng, $priority, $photoFile]);
    $newId = (int)$db->lastInsertId();

    jsonResponse(['success'=>true,'message'=>'Laporan berhasil dikirim','id'=>$newId], 201);
}

// ─── UPDATE STATUS (admin) ─────────────────────────────────
if ($method === 'PUT' && $id) {
    $admin    = requireAdmin();
    $db       = getDB();
    $bodyRaw  = json_decode(file_get_contents('php://input'), true) ?? [];
    $status   = $bodyRaw['status']   ?? '';
    $priority = $bodyRaw['priority'] ?? '';
    $comment  = $bodyRaw['comment']  ?? '';

    $sets = []; $params = [];
    if ($status   && in_array($status,   ['pending','in_progress','resolved','rejected'])) { $sets[] = 'status = ?';   $params[] = $status; }
    if ($priority && in_array($priority, ['low','medium','high']))                          { $sets[] = 'priority = ?'; $params[] = $priority; }

    if ($sets) {
        $params[] = $id;
        $db->prepare('UPDATE reports SET '.implode(', ',$sets).' WHERE id = ?')->execute($params);
    }
    if (!empty($comment)) {
        $db->prepare('INSERT INTO report_comments (report_id, user_id, comment) VALUES (?, ?, ?)')->execute([$id, $admin['id'], sanitize($comment)]);
    }

    jsonResponse(['success'=>true,'message'=>'Laporan berhasil diupdate']);
}

// ─── DELETE + reorder nomor_urut ───────────────────────────
if ($method === 'DELETE' && $id) {
    $user = requireAuth();
    $db   = getDB();

    $stmt = $db->prepare('SELECT user_id, photo FROM reports WHERE id = ?');
    $stmt->execute([$id]);
    $report = $stmt->fetch();
    if (!$report) jsonResponse(['success'=>false,'message'=>'Laporan tidak ditemukan'], 404);
    if ($user['role'] !== 'admin' && $report['user_id'] != $user['id']) jsonResponse(['success'=>false,'message'=>'Forbidden'], 403);

    // Hapus foto jika ada
    if ($report['photo'] && file_exists(UPLOAD_REPORTS . $report['photo'])) {
        unlink(UPLOAD_REPORTS . $report['photo']);
    }

    $db->prepare('DELETE FROM reports WHERE id = ?')->execute([$id]);

    // Reorder nomor_urut agar tetap konsisten
    $db->exec('CALL reorder_nomor_urut()');

    jsonResponse(['success'=>true,'message'=>'Laporan berhasil dihapus']);
}

jsonResponse(['success'=>false,'message'=>'Bad request'], 400);