<?php
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['error' => 'unauthorized']); exit;
}

$host = 'localhost'; $db = 'chatapp'; $user = 'root'; $pass = ''; $charset = 'utf8mb4';
try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo json_encode(['error' => 'db']); exit;
}

$me = $_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

    // ── GET MESSAGES ────────────────────────────────────────────────────────
    case 'get_messages':
        $with  = $_GET['with']  ?? '';
        $after = (int)($_GET['after'] ?? 0);
        if (!$with) { echo json_encode(['error' => 'no contact']); exit; }

        $stmt = $pdo->prepare("
            SELECT m.id, m.sender_id, m.receiver_id, m.body, m.type, m.is_read, m.created_at,
                   u.name AS sender_name
            FROM messages m
            JOIN users u ON u.phone = m.sender_id
            WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
              AND m.id > ?
            ORDER BY m.created_at ASC, m.id ASC
            LIMIT 100
        ");
        $stmt->execute([$me, $with, $with, $me, $after]);
        $msgs = $stmt->fetchAll();

        // get contact status + typing
        $cs = $pdo->prepare('SELECT status, typing_to, typing_at FROM users WHERE phone = ?');
        $cs->execute([$with]);
        $csRow = $cs->fetch();
        $isTyping = ($csRow && $csRow['typing_to'] === $me && $csRow['typing_at'] !== null);

        echo json_encode([
            'messages'       => $msgs,
            'contact_status' => $csRow['status'] ?? 'offline',
            'is_typing'      => $isTyping,
        ]);
        break;

    // ── SEND MESSAGE ─────────────────────────────────────────────────────────
    case 'send':
        $to   = $_POST['to']   ?? '';
        $body = trim($_POST['body'] ?? '');
        $type = $_POST['type'] ?? 'text';
        if (!$to || !$body) { echo json_encode(['error' => 'empty']); exit; }
        // verify recipient exists
        $chk = $pdo->prepare('SELECT 1 FROM users WHERE phone = ?');
        $chk->execute([$to]);
        if (!$chk->fetch()) { echo json_encode(['error' => 'user not found']); exit; }

        $ins = $pdo->prepare('INSERT INTO messages (sender_id, receiver_id, body, type, is_read, created_at) VALUES (?,?,?,?,0,NOW())');
        $ins->execute([$me, $to, $body, $type]);
        $newId = $pdo->lastInsertId();
        echo json_encode(['success' => true, 'id' => $newId]);
        break;

    // ── MARK READ ────────────────────────────────────────────────────────────
    case 'mark_read':
        $from = $_POST['from'] ?? $_GET['from'] ?? '';
        if (!$from) { echo json_encode(['ok' => false]); exit; }
        $upd = $pdo->prepare('UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0');
        $upd->execute([$from, $me]);
        echo json_encode(['ok' => true]);
        break;

    // ── CHAT LIST (for sidebar polling) ──────────────────────────────────────
    case 'chat_list':
        $stmt = $pdo->prepare("
            SELECT u.phone, u.name, u.status,
                   m.body AS last_msg, m.created_at AS last_time, m.sender_id,
                   (SELECT COUNT(*) FROM messages WHERE sender_id = u.phone AND receiver_id = ? AND is_read = 0) AS unread
            FROM users u
            JOIN messages m ON m.id = (
                SELECT id FROM messages
                WHERE (sender_id = ? AND receiver_id = u.phone) OR (sender_id = u.phone AND receiver_id = ?)
                ORDER BY created_at DESC LIMIT 1
            )
            WHERE u.phone != ?
            ORDER BY m.created_at DESC
        ");
        $stmt->execute([$me, $me, $me, $me]);
        $chats = $stmt->fetchAll();
        echo json_encode(['chats' => $chats]);
        break;

    // ── USER INFO ────────────────────────────────────────────────────────────
    case 'user_info':
        $phone = $_GET['phone'] ?? '';
        if (!$phone) { echo json_encode(['error' => 'no phone']); exit; }
        $stmt = $pdo->prepare('SELECT name, status, bio, last_seen FROM users WHERE phone = ?');
        $stmt->execute([$phone]);
        $u = $stmt->fetch();
        if (!$u) { echo json_encode(['error' => 'not found']); exit; }
        echo json_encode($u);
        break;

    // ── UPDATE MY STATUS ─────────────────────────────────────────────────────
    case 'set_status':
        $status = $_POST['status'] ?? 'online';
        if (!in_array($status, ['online','offline','away'])) $status = 'online';
        $upd = $pdo->prepare('UPDATE users SET status = ?, last_seen = NOW() WHERE phone = ?');
        $upd->execute([$status, $me]);
        echo json_encode(['ok' => true]);
        break;

    default:
        echo json_encode(['error' => 'unknown action']);
}