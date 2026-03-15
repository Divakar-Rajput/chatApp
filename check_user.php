<?php
session_start();
header('Content-Type: application/json');

$host = 'localhost'; $db = 'chatapp'; $user = 'root'; $pass = ''; $charset = 'utf8mb4';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo json_encode(['error' => 'db_error']); exit;
}

// ── Ensure typing columns exist (safe to run every time, fails silently) ──────
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS typing_to VARCHAR(20) DEFAULT NULL");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS typing_at DATETIME DEFAULT NULL");
} catch (PDOException $e) { /* ignore if already exists */ }

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── AUTO-EXPIRE: inactive > 15s → offline; typing > 5s stale → clear ─────────
$pdo->exec('UPDATE users SET status = "offline", typing_to = NULL, typing_at = NULL
            WHERE status = "online" AND last_seen < NOW() - INTERVAL 15 SECOND');
$pdo->exec('UPDATE users SET typing_to = NULL, typing_at = NULL
            WHERE typing_at IS NOT NULL AND typing_at < NOW() - INTERVAL 5 SECOND');

// ── HEARTBEAT: ping every ~10s to stay "online" ───────────────────────────────
if ($action === 'heartbeat') {
    if (empty($_SESSION['user_id'])) { echo json_encode(['error' => 'unauthorized']); exit; }
    $pdo->prepare('UPDATE users SET status = "online", last_seen = NOW() WHERE phone = ?')
        ->execute([$_SESSION['user_id']]);
    echo json_encode(['ok' => true, 'status' => 'online']);
    exit;
}

// ── TYPING START: user is currently typing to someone ─────────────────────────
// POST: check_user.php?action=typing_start  body: to=PHONE
if ($action === 'typing_start') {
    if (empty($_SESSION['user_id'])) { echo json_encode(['error' => 'unauthorized']); exit; }
    $to = trim($_POST['to'] ?? '');
    if (!$to) { echo json_encode(['error' => 'no_to']); exit; }
    $pdo->prepare('UPDATE users SET typing_to = ?, typing_at = NOW(), last_seen = NOW() WHERE phone = ?')
        ->execute([$to, $_SESSION['user_id']]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── TYPING STOP: user stopped typing ─────────────────────────────────────────
// POST: check_user.php?action=typing_stop
if ($action === 'typing_stop') {
    if (empty($_SESSION['user_id'])) { echo json_encode(['error' => 'unauthorized']); exit; }
    $pdo->prepare('UPDATE users SET typing_to = NULL, typing_at = NULL WHERE phone = ?')
        ->execute([$_SESSION['user_id']]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── CHECK: status + typing state of a single user ────────────────────────────
// GET: check_user.php?action=check&phone=XX&viewer=ME
if ($action === 'check') {
    $phone  = trim($_GET['phone']  ?? '');
    $viewer = trim($_GET['viewer'] ?? $_SESSION['user_id'] ?? '');
    if (!$phone) { echo json_encode(['error' => 'no_phone']); exit; }

    $stmt = $pdo->prepare('SELECT phone, name, status, last_seen, typing_to, typing_at FROM users WHERE phone = ?');
    $stmt->execute([$phone]);
    $u = $stmt->fetch();
    if (!$u) { echo json_encode(['exists' => false]); exit; }

    $isTypingToMe = ($u['typing_to'] === $viewer && $u['typing_at'] !== null);

    echo json_encode([
        'exists'          => true,
        'phone'           => $u['phone'],
        'name'            => $u['name'],
        'status'          => $u['status'],
        'is_typing'       => $isTypingToMe,
        'last_seen'       => $u['status'] === 'online' ? 'online' : formatLastSeen($u['last_seen']),
        'last_seen_raw'   => $u['last_seen'],
    ]);
    exit;
}

// ── BULK CHECK: status + typing for multiple phones ───────────────────────────
// POST: check_user.php?action=bulk  body: phones[]=XX&viewer=ME
if ($action === 'bulk') {
    $phones = $_POST['phones'] ?? [];
    $viewer = trim($_POST['viewer'] ?? $_SESSION['user_id'] ?? '');
    if (!is_array($phones) || empty($phones)) { echo json_encode(['users' => []]); exit; }
    $phones = array_values(array_map('trim', array_filter($phones)));
    $ph = implode(',', array_fill(0, count($phones), '?'));
    $stmt = $pdo->prepare("SELECT phone, name, status, last_seen, typing_to, typing_at FROM users WHERE phone IN ($ph)");
    $stmt->execute($phones);
    $result = [];
    foreach ($stmt->fetchAll() as $u) {
        $isTyping = ($u['typing_to'] === $viewer && $u['typing_at'] !== null);
        $result[$u['phone']] = [
            'name'      => $u['name'],
            'status'    => $u['status'],
            'is_typing' => $isTyping,
            'last_seen' => $u['status'] === 'online' ? 'online' : formatLastSeen($u['last_seen']),
        ];
    }
    echo json_encode(['users' => $result]);
    exit;
}

// ── SET OFFLINE: tab close / manual logout ────────────────────────────────────
if ($action === 'set_offline') {
    if (empty($_SESSION['user_id'])) { echo json_encode(['ok' => false]); exit; }
    $pdo->prepare('UPDATE users SET status = "offline", last_seen = NOW(), typing_to = NULL, typing_at = NULL WHERE phone = ?')
        ->execute([$_SESSION['user_id']]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── FALLBACK: plain phone existence check (used by login form) ────────────────
$phone = trim($_GET['phone'] ?? '');
if ($phone) {
    $stmt = $pdo->prepare('SELECT 1 FROM users WHERE phone = ? LIMIT 1');
    $stmt->execute([$phone]);
    echo json_encode(['exists' => (bool)$stmt->fetch()]);
    exit;
}

echo json_encode(['error' => 'unknown_action']);

// ── HELPER ────────────────────────────────────────────────────────────────────
function formatLastSeen($ts) {
    if (!$ts) return 'last seen a while ago';
    $diff = time() - strtotime($ts);
    if ($diff < 60)       return 'last seen just now';
    if ($diff < 3600)     return 'last seen ' . floor($diff / 60) . ' min ago';
    if ($diff < 86400)    return 'last seen today at '     . date('g:i A', strtotime($ts));
    if ($diff < 172800)   return 'last seen yesterday at ' . date('g:i A', strtotime($ts));
    return 'last seen ' . date('d M Y', strtotime($ts));
}