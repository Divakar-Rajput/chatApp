<?php
session_start();
$host = 'localhost'; $db = 'chatapp'; $user = 'root'; $pass = '';
try {
    $pdo = new PDO("mysql:host=$host;dbname=$db", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if (!empty($_SESSION['user_id'])) {
        $pdo->prepare('UPDATE users SET status = "offline", last_seen = NOW() WHERE phone = ?')
            ->execute([$_SESSION['user_id']]);
    }
} catch (PDOException $e) {}
session_destroy();
header('Location: login.php');
exit;