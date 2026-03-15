<?php
// ─── DB CONFIG ────────────────────────────────────────────────────────────────
$host = 'localhost';
$db   = 'chatapp';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

// ─── HANDLE POST ──────────────────────────────────────────────────────────────
$error   = '';
$success = '';
$mode    = ''; // 'login' | 'register'

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone    = trim($_POST['phone']    ?? '');
    $password = trim($_POST['password'] ?? '');
    $name     = trim($_POST['name']     ?? '');

    // Basic validation
    if (!$phone || !$password) {
        $error = 'Phone number and password are required.';
    } elseif (strlen($phone) < 7) {
        $error = 'Please enter a valid phone number.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {
        try {
            $pdo = new PDO($dsn, $user, $pass, $options);

            // Check if user exists
            $stmt = $pdo->prepare('SELECT * FROM users WHERE phone = ?');
            $stmt->execute([$phone]);
            $existingUser = $stmt->fetch();

            if ($existingUser) {
                // ── LOGIN ──
                if (password_verify($password, $existingUser['password'])) {
                    // Update status to online & last_seen
                    $upd = $pdo->prepare('UPDATE users SET status = "online", last_seen = NOW() WHERE phone = ?');
                    $upd->execute([$phone]);

                    session_start();
                    $_SESSION['user_id']   = $existingUser['phone'];
                    $_SESSION['user_name'] = $existingUser['name'];
                    $mode    = 'login';
                    $success = 'Welcome back, ' . htmlspecialchars($existingUser['name']) . '! Redirecting...';
                    header('Location: index.php');
                    exit;
                } else {
                    $error = 'Incorrect password. Please try again.';
                }
            } else {
                // ── REGISTER (first time) ──
                if (!$name) {
                    $error = 'Looks like you\'re new! Please enter your name to create an account.';
                    $mode  = 'needs_name'; // trigger JS to show name field
                } else {
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    $ins = $pdo->prepare(
                        'INSERT INTO users (phone, name, password, avatar, status, created_at)
                         VALUES (?, ?, ?, ?, "offline", NOW())'
                    );
                    $ins->execute([$phone, $name, $hashed, '?']);
                    session_start();
                    $_SESSION['user_id']   = $phone;
                    $_SESSION['user_name'] = $name;
                    $mode    = 'register';
                    $success = 'Account created! Welcome, ' . htmlspecialchars($name) . '!';
                    header('Location: index.php');
                    exit;
                }
            }
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WhatsApp Web – Sign In</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }

        :root {
            --wa-green: #00a884;
            --wa-green-dark: #008069;
            --wa-bg: #111b21;
            --wa-panel: #202c33;
            --wa-border: #2a3942;
            --wa-input-bg: #2a3942;
            --wa-text-primary: #e9edef;
            --wa-text-secondary: #8696a0;
            --wa-text-muted: #667781;
            --wa-hover: #2a3942;
            --wa-error: #f15c6d;
        }

        html,
        body {
            min-height: 100vh;
            background: var(--wa-bg);
            display: flex;
            flex-direction: column;
        }

        /* ── TOP BAR ── */
        .wa-topbar {
            background: var(--wa-panel);
            border-bottom: 1px solid var(--wa-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 28px;
            height: 52px;
            flex-shrink: 0;
        }

        .wa-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--wa-text-primary);
            font-size: 19px;
            font-weight: 500;
        }

        .wa-brand svg {
            width: 26px;
            height: 26px;
            color: var(--wa-green);
        }

        .wa-nav {
            display: flex;
            gap: 24px;
        }

        .wa-nav a {
            color: var(--wa-text-secondary);
            font-size: 13px;
            text-decoration: none;
        }

        .wa-nav a:hover {
            color: var(--wa-text-primary);
        }

        /* ── BODY ── */
        .wa-body {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 28px 16px;
        }

        .wa-card {
            background: var(--wa-panel);
            border: 1px solid var(--wa-border);
            border-radius: 16px;
            display: flex;
            width: 100%;
            max-width: 820px;
            min-height: 460px;
            overflow: hidden;
        }

        /* ── LEFT ── */
        .wa-left {
            flex: 1;
            padding: 36px 36px 32px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            border-right: 1px solid var(--wa-border);
        }

        /* TABS */
        .wa-tabs {
            display: flex;
            width: 100%;
            border-bottom: 1px solid var(--wa-border);
            margin-bottom: 26px;
        }

        .wa-tab {
            flex: 1;
            padding: 10px 0;
            font-size: 13.5px;
            color: var(--wa-text-muted);
            cursor: pointer;
            border-bottom: 2px solid transparent;
            margin-bottom: -1px;
            text-align: center;
            transition: color .15s;
        }

        .wa-tab:hover {
            color: var(--wa-text-secondary);
        }

        .wa-tab.active {
            color: var(--wa-green);
            border-bottom-color: var(--wa-green);
            font-weight: 500;
        }

        /* QR PANEL */
        .wa-qr-panel {
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .wa-qr-wrap {
            width: 172px;
            height: 172px;
            background: white;
            border-radius: 10px;
            padding: 10px;
            margin-bottom: 20px;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .wa-qr-wrap svg {
            width: 152px;
            height: 152px;
        }

        @keyframes scan {

            0%,
            100% {
                top: 10px
            }

            50% {
                top: calc(100% - 18px)
            }
        }

        .wa-scan-line {
            position: absolute;
            left: 10px;
            right: 10px;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--wa-green), transparent);
            border-radius: 2px;
            animation: scan 2.4s ease-in-out infinite;
            pointer-events: none;
        }

        .wa-qr-corner {
            position: absolute;
            width: 18px;
            height: 18px;
            border-color: var(--wa-green);
            border-style: solid;
        }

        .wa-qr-corner.tl {
            top: 7px;
            left: 7px;
            border-width: 3px 0 0 3px;
            border-radius: 3px 0 0 0
        }

        .wa-qr-corner.tr {
            top: 7px;
            right: 7px;
            border-width: 3px 3px 0 0;
            border-radius: 0 3px 0 0
        }

        .wa-qr-corner.bl {
            bottom: 7px;
            left: 7px;
            border-width: 0 0 3px 3px;
            border-radius: 0 0 0 3px
        }

        .wa-qr-corner.br {
            bottom: 7px;
            right: 7px;
            border-width: 0 3px 3px 0;
            border-radius: 0 0 3px 0
        }

        .wa-qr-steps {
            text-align: left;
            width: 100%;
            max-width: 240px;
        }

        .wa-qr-steps p {
            font-size: 13px;
            color: var(--wa-text-secondary);
            line-height: 1.6;
            margin-bottom: 5px;
            display: flex;
            gap: 8px;
            align-items: flex-start;
        }

        .wa-step-num {
            background: var(--wa-green);
            color: white;
            width: 17px;
            height: 17px;
            border-radius: 50%;
            font-size: 10px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-top: 2px;
        }

        /* FORM PANEL */
        .wa-form-panel {
            display: none;
            flex-direction: column;
            align-items: stretch;
            width: 100%;
        }

        .wa-form-panel.active {
            display: flex;
        }

        .wa-form-title {
            font-size: 18px;
            font-weight: 400;
            color: var(--wa-text-primary);
            margin-bottom: 5px;
        }

        .wa-form-sub {
            font-size: 13px;
            color: var(--wa-text-secondary);
            margin-bottom: 20px;
            line-height: 1.55;
        }

        /* FIELDS */
        .wa-field {
            margin-bottom: 13px;
        }

        .wa-label {
            font-size: 12px;
            color: var(--wa-text-secondary);
            margin-bottom: 5px;
            display: block;
        }

        .wa-input-wrap {
            display: flex;
            align-items: center;
            background: var(--wa-input-bg);
            border-radius: 8px;
            border: 1px solid var(--wa-border);
            transition: border-color .15s;
            overflow: hidden;
        }

        .wa-input-wrap:focus-within {
            border-color: var(--wa-green);
        }

        .wa-input-wrap.error {
            border-color: var(--wa-error) !important;
        }

        .wa-country-sel {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 0 10px;
            border-right: 1px solid var(--wa-border);
            height: 44px;
            cursor: pointer;
            color: var(--wa-text-primary);
            font-size: 14px;
            flex-shrink: 0;
            white-space: nowrap;
            position: relative;
        }

        .wa-country-sel svg {
            width: 12px;
            height: 12px;
            color: var(--wa-text-muted);
        }

        .wa-flag {
            font-size: 18px;
            line-height: 1;
        }

        .wa-country-list {
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            min-width: 220px;
            background: #1a2730;
            border: 1px solid var(--wa-border);
            border-radius: 8px;
            z-index: 20;
            max-height: 180px;
            overflow-y: auto;
            display: none;
            box-shadow: 0 8px 24px rgba(0, 0, 0, .45);
        }

        .wa-country-list.open {
            display: block;
        }

        .wa-country-list::-webkit-scrollbar {
            width: 3px
        }

        .wa-country-list::-webkit-scrollbar-thumb {
            background: #374045;
            border-radius: 2px
        }

        .wa-co {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 12px;
            font-size: 13px;
            color: var(--wa-text-primary);
            cursor: pointer;
            transition: background .12s;
        }

        .wa-co:hover {
            background: var(--wa-hover);
        }

        .wa-co .wa-flag {
            font-size: 16px;
        }

        .wa-co-num {
            color: var(--wa-text-muted);
            font-size: 12px;
            margin-left: auto;
        }

        .wa-input {
            flex: 1;
            background: none;
            border: none;
            outline: none;
            color: var(--wa-text-primary);
            font-size: 14.5px;
            padding: 0 12px;
            height: 44px;
            width: 100%;
        }

        .wa-input::placeholder {
            color: var(--wa-text-muted);
        }

        .wa-eye {
            padding: 0 12px;
            cursor: pointer;
            color: var(--wa-text-muted);
            display: flex;
            align-items: center;
        }

        .wa-eye svg {
            width: 18px;
            height: 18px;
        }

        .wa-eye:hover {
            color: var(--wa-text-secondary);
        }

        .wa-field-err {
            font-size: 11.5px;
            color: var(--wa-error);
            margin-top: 4px;
            display: none;
        }

        .wa-field-err.show {
            display: block;
        }

        /* STRENGTH */
        .wa-strength {
            display: flex;
            gap: 4px;
            margin-top: 7px;
        }

        .wa-bar {
            flex: 1;
            height: 3px;
            border-radius: 2px;
            background: var(--wa-border);
            transition: background .2s;
        }

        .wa-bar.weak {
            background: var(--wa-error)
        }

        .wa-bar.medium {
            background: #f0b429
        }

        .wa-bar.strong {
            background: var(--wa-green)
        }

        .wa-strength-lbl {
            font-size: 11.5px;
            margin-top: 4px;
            color: var(--wa-text-muted);
        }

        /* NAME FIELD HINT */
        .wa-new-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: rgba(0, 168, 132, .12);
            color: var(--wa-green);
            border: 1px solid rgba(0, 168, 132, .3);
            border-radius: 20px;
            font-size: 12px;
            padding: 3px 10px;
            margin-bottom: 12px;
        }

        .wa-new-badge svg {
            width: 13px;
            height: 13px;
        }

        /* FORGOT */
        .wa-forgot {
            text-align: right;
            margin-top: -4px;
            margin-bottom: 14px;
        }

        .wa-forgot a {
            font-size: 12.5px;
            color: var(--wa-green);
            text-decoration: none;
        }

        .wa-forgot a:hover {
            text-decoration: underline;
        }

        /* BUTTONS */
        .wa-btn {
            width: 100%;
            background: var(--wa-green);
            color: white;
            border: none;
            border-radius: 24px;
            padding: 12px;
            font-size: 15px;
            font-weight: 500;
            cursor: pointer;
            transition: background .15s;
            margin-top: 4px;
        }

        .wa-btn:hover {
            background: var(--wa-green-dark);
        }

        .wa-btn:active {
            transform: scale(.99);
        }

        .wa-divider {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 14px 0;
        }

        .wa-divider-line {
            flex: 1;
            height: 1px;
            background: var(--wa-border);
        }

        .wa-divider span {
            font-size: 12px;
            color: var(--wa-text-muted);
        }

        .wa-btn-ghost {
            width: 100%;
            background: transparent;
            color: var(--wa-text-secondary);
            border: 1px solid var(--wa-border);
            border-radius: 24px;
            padding: 11px;
            font-size: 14px;
            cursor: pointer;
            transition: all .15s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .wa-btn-ghost:hover {
            border-color: var(--wa-text-secondary);
            color: var(--wa-text-primary);
            background: var(--wa-hover);
        }

        .wa-signup {
            text-align: center;
            margin-top: 13px;
            font-size: 13px;
            color: var(--wa-text-muted);
        }

        .wa-signup a {
            color: var(--wa-green);
            text-decoration: none;
        }

        .wa-signup a:hover {
            text-decoration: underline;
        }

        /* SERVER ALERTS */
        .wa-alert {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 10px 13px;
            border-radius: 8px;
            margin-bottom: 14px;
            font-size: 13px;
            line-height: 1.5;
        }

        .wa-alert.err {
            background: rgba(241, 92, 109, .12);
            border: 1px solid rgba(241, 92, 109, .3);
            color: #f15c6d;
        }

        .wa-alert.ok {
            background: rgba(0, 168, 132, .12);
            border: 1px solid rgba(0, 168, 132, .3);
            color: var(--wa-green);
        }

        .wa-alert svg {
            width: 16px;
            height: 16px;
            flex-shrink: 0;
            margin-top: 1px;
        }

        /* SUCCESS SCREEN */
        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(10px)
            }

            to {
                opacity: 1;
                transform: translateY(0)
            }
        }

        .wa-success {
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 20px;
            animation: fadeUp .35s ease;
        }

        .wa-success.show {
            display: flex;
        }

        .wa-success-ring {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: rgba(0, 168, 132, .15);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
        }

        .wa-success-ring svg {
            width: 34px;
            height: 34px;
            color: var(--wa-green);
        }

        .wa-success h3 {
            font-size: 19px;
            color: var(--wa-text-primary);
            font-weight: 500;
            margin-bottom: 6px;
        }

        .wa-success p {
            font-size: 13px;
            color: var(--wa-text-secondary);
        }

        /* ── RIGHT ── */
        .wa-right {
            width: 300px;
            padding: 36px 28px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .wa-app-icon {
            width: 64px;
            height: 64px;
            background: var(--wa-green);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 22px;
        }

        .wa-app-icon svg {
            width: 40px;
            height: 40px;
            color: white;
        }

        .wa-right-title {
            font-size: 23px;
            font-weight: 300;
            color: var(--wa-text-primary);
            line-height: 1.35;
            margin-bottom: 8px;
        }

        .wa-right-title strong {
            font-weight: 500;
        }

        .wa-right-desc {
            font-size: 13px;
            color: var(--wa-text-secondary);
            line-height: 1.65;
            margin-bottom: 22px;
        }

        .wa-feat {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
            padding: 10px 12px;
            background: var(--wa-input-bg);
            border-radius: 10px;
        }

        .wa-feat-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: rgba(0, 168, 132, .15);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .wa-feat-icon svg {
            width: 16px;
            height: 16px;
            color: var(--wa-green);
        }

        .wa-feat-text {
            font-size: 12.5px;
            color: var(--wa-text-secondary);
            line-height: 1.4;
        }

        .wa-feat-text strong {
            color: var(--wa-text-primary);
            font-weight: 500;
            display: block;
            font-size: 13px;
            margin-bottom: 1px;
        }

        /* ── FOOTER ── */
        .wa-footer {
            background: var(--wa-panel);
            border-top: 1px solid var(--wa-border);
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 18px;
            flex-wrap: wrap;
            flex-shrink: 0;
        }

        .wa-footer a {
            color: var(--wa-text-muted);
            font-size: 12px;
            text-decoration: none;
        }

        .wa-footer a:hover {
            color: var(--wa-text-secondary);
        }

        .wa-footer-dot {
            width: 3px;
            height: 3px;
            border-radius: 50%;
            background: var(--wa-text-muted);
        }
    </style>
</head>

<body>

    <!-- TOP BAR -->
    <div class="wa-topbar">
        <div class="wa-brand">
            <svg viewBox="0 0 24 24" fill="currentColor">
                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z" />
                <path d="M12 0C5.373 0 0 5.373 0 12c0 2.127.558 4.126 1.532 5.862L.054 23.25a.75.75 0 00.916.916l5.388-1.478A11.953 11.953 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.99 0-3.86-.548-5.462-1.5l-.389-.228-4.037 1.108 1.108-4.037-.228-.389A9.953 9.953 0 012 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z" />
            </svg>
            WhatsApp
        </div>
        <nav class="wa-nav">
            <a href="#">Android</a>
            <a href="#">iPhone</a>
            <a href="#">Help</a>
        </nav>
    </div>

    <!-- BODY -->
    <div class="wa-body">
        <div class="wa-card">

            <!-- LEFT PANEL -->
            <div class="wa-left">
                <div class="wa-tabs">
                    <div class="wa-tab" id="tabQr" onclick="switchTab('qr')">Scan QR Code</div>
                    <div class="wa-tab active" id="tabPw" onclick="switchTab('pw')">Phone & Password</div>
                </div>

                <!-- QR PANEL -->
                <div class="wa-qr-panel" id="panelQr" style="display:none;">
                    <div class="wa-qr-wrap">
                        <svg viewBox="0 0 160 160" xmlns="http://www.w3.org/2000/svg">
                            <rect width="160" height="160" fill="white" />
                            <rect x="10" y="10" width="50" height="50" rx="4" fill="#111" />
                            <rect x="16" y="16" width="38" height="38" rx="2" fill="white" />
                            <rect x="22" y="22" width="26" height="26" rx="1" fill="#111" />
                            <rect x="100" y="10" width="50" height="50" rx="4" fill="#111" />
                            <rect x="106" y="16" width="38" height="38" rx="2" fill="white" />
                            <rect x="112" y="22" width="26" height="26" rx="1" fill="#111" />
                            <rect x="10" y="100" width="50" height="50" rx="4" fill="#111" />
                            <rect x="16" y="106" width="38" height="38" rx="2" fill="white" />
                            <rect x="22" y="112" width="26" height="26" rx="1" fill="#111" />
                            <rect x="70" y="10" width="8" height="8" fill="#111" />
                            <rect x="82" y="10" width="8" height="8" fill="#111" />
                            <rect x="70" y="22" width="8" height="8" fill="#111" />
                            <rect x="82" y="22" width="8" height="8" fill="#111" />
                            <rect x="70" y="34" width="8" height="8" fill="#111" />
                            <rect x="70" y="46" width="8" height="8" fill="#111" />
                            <rect x="82" y="46" width="8" height="8" fill="#111" />
                            <rect x="10" y="70" width="8" height="8" fill="#111" />
                            <rect x="22" y="70" width="8" height="8" fill="#111" />
                            <rect x="34" y="70" width="8" height="8" fill="#111" />
                            <rect x="46" y="70" width="8" height="8" fill="#111" />
                            <rect x="10" y="82" width="8" height="8" fill="#111" />
                            <rect x="34" y="82" width="8" height="8" fill="#111" />
                            <rect x="46" y="82" width="8" height="8" fill="#111" />
                            <rect x="70" y="70" width="8" height="8" fill="#111" />
                            <rect x="82" y="70" width="8" height="8" fill="#111" />
                            <rect x="94" y="70" width="8" height="8" fill="#111" />
                            <rect x="106" y="70" width="8" height="8" fill="#111" />
                            <rect x="118" y="70" width="8" height="8" fill="#111" />
                            <rect x="130" y="70" width="8" height="8" fill="#111" />
                            <rect x="142" y="70" width="8" height="8" fill="#111" />
                            <rect x="70" y="82" width="8" height="8" fill="#111" />
                            <rect x="94" y="82" width="8" height="8" fill="#111" />
                            <rect x="118" y="82" width="8" height="8" fill="#111" />
                            <rect x="142" y="82" width="8" height="8" fill="#111" />
                            <rect x="70" y="94" width="8" height="8" fill="#111" />
                            <rect x="82" y="94" width="8" height="8" fill="#111" />
                            <rect x="94" y="94" width="8" height="8" fill="#111" />
                            <rect x="106" y="94" width="8" height="8" fill="#111" />
                            <rect x="130" y="94" width="8" height="8" fill="#111" />
                            <rect x="70" y="106" width="8" height="8" fill="#111" />
                            <rect x="94" y="106" width="8" height="8" fill="#111" />
                            <rect x="118" y="106" width="8" height="8" fill="#111" />
                            <rect x="142" y="106" width="8" height="8" fill="#111" />
                            <rect x="70" y="118" width="8" height="8" fill="#111" />
                            <rect x="82" y="118" width="8" height="8" fill="#111" />
                            <rect x="106" y="118" width="8" height="8" fill="#111" />
                            <rect x="130" y="118" width="8" height="8" fill="#111" />
                            <rect x="70" y="130" width="8" height="8" fill="#111" />
                            <rect x="94" y="130" width="8" height="8" fill="#111" />
                            <rect x="118" y="130" width="8" height="8" fill="#111" />
                            <rect x="130" y="130" width="8" height="8" fill="#111" />
                            <rect x="142" y="130" width="8" height="8" fill="#111" />
                            <rect x="70" y="142" width="8" height="8" fill="#111" />
                            <rect x="82" y="142" width="8" height="8" fill="#111" />
                            <rect x="94" y="142" width="8" height="8" fill="#111" />
                            <rect x="106" y="142" width="8" height="8" fill="#111" />
                            <rect x="118" y="142" width="8" height="8" fill="#111" />
                            <rect x="10" y="94" width="8" height="8" fill="#111" />
                            <rect x="22" y="94" width="8" height="8" fill="#111" />
                            <rect x="46" y="94" width="8" height="8" fill="#111" />
                            <rect x="10" y="106" width="8" height="8" fill="#111" />
                            <rect x="34" y="106" width="8" height="8" fill="#111" />
                            <rect x="46" y="106" width="8" height="8" fill="#111" />
                            <rect x="22" y="118" width="8" height="8" fill="#111" />
                            <rect x="46" y="118" width="8" height="8" fill="#111" />
                            <rect x="10" y="130" width="8" height="8" fill="#111" />
                            <rect x="22" y="130" width="8" height="8" fill="#111" />
                            <rect x="34" y="130" width="8" height="8" fill="#111" />
                            <rect x="10" y="142" width="8" height="8" fill="#111" />
                            <rect x="46" y="142" width="8" height="8" fill="#111" />
                        </svg>
                        <div class="wa-scan-line"></div>
                        <div class="wa-qr-corner tl"></div>
                        <div class="wa-qr-corner tr"></div>
                        <div class="wa-qr-corner bl"></div>
                        <div class="wa-qr-corner br"></div>
                    </div>
                    <div class="wa-qr-steps">
                        <p><span class="wa-step-num">1</span> Open WhatsApp on your phone</p>
                        <p><span class="wa-step-num">2</span> Tap Menu or Settings → Linked Devices</p>
                        <p><span class="wa-step-num">3</span> Point your camera at this screen</p>
                    </div>
                </div>

                <!-- FORM PANEL -->
                <div class="wa-form-panel active" id="panelPw">

                    <?php if ($success && ($mode === 'login' || $mode === 'register')): ?>
                        <!-- SUCCESS SCREEN -->
                        <div class="wa-success show">
                            <div class="wa-success-ring">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                    <polyline points="20 6 9 17 4 12" />
                                </svg>
                            </div>
                            <h3><?= $mode === 'register' ? 'Account Created!' : 'Welcome Back!' ?></h3>
                            <p><?= htmlspecialchars($success) ?></p>
                        </div>

                    <?php else: ?>

                        <?php if ($error): ?>
                            <div class="wa-alert err">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10" />
                                    <line x1="12" y1="8" x2="12" y2="12" />
                                    <line x1="12" y1="16" x2="12.01" y2="16" />
                                </svg>
                                <?= htmlspecialchars($error) ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="" id="loginForm" autocomplete="off">

                            <!-- Dynamic heading -->
                            <div class="wa-form-title" id="formTitle">
                                <?= ($mode === 'needs_name') ? 'Create your account' : 'Welcome' ?>
                            </div>
                            <div class="wa-form-sub" id="formSub">
                                <?= ($mode === 'needs_name')
                                    ? 'New number detected — fill in your name to get started.'
                                    : 'Enter your phone number. We\'ll log you in or create an account automatically.' ?>
                            </div>

                            <?php if ($mode === 'needs_name'): ?>
                                <div class="wa-new-badge">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2" />
                                        <circle cx="9" cy="7" r="4" />
                                        <line x1="19" y1="8" x2="19" y2="14" />
                                        <line x1="22" y1="11" x2="16" y2="11" />
                                    </svg>
                                    New account
                                </div>
                            <?php endif; ?>

                            <!-- NAME (shown only when new user detected) -->
                            <div class="wa-field" id="nameField" style="<?= ($mode === 'needs_name') ? '' : 'display:none' ?>">
                                <label class="wa-label" for="nameInput">Your name</label>
                                <div class="wa-input-wrap" id="nameWrap">
                                    <input class="wa-input" type="text" id="nameInput" name="name"
                                        placeholder="Enter your full name"
                                        value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                                        oninput="clearErr('nameErr','nameWrap')">
                                </div>
                                <div class="wa-field-err" id="nameErr">Please enter your name</div>
                            </div>

                            <!-- PHONE -->
                            <div class="wa-field">
                                <label class="wa-label" for="phoneInput">Phone number</label>
                                <div class="wa-input-wrap <?= ($error && strpos($error, 'phone') !== false) ? 'error' : '' ?>" id="phoneWrap">
                                    <div class="wa-country-sel" id="countryBtn" onclick="toggleDd(event)">
                                        <span class="wa-flag" id="selFlag">🇮🇳</span>
                                        <span id="selCode">+91</span>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                            <polyline points="6 9 12 15 18 9" />
                                        </svg>
                                        <div class="wa-country-list" id="countryList">
                                            <?php
                                            $countries = [
                                                ['🇮🇳', '91', 'India'],
                                                ['🇺🇸', '1', 'United States'],
                                                ['🇬🇧', '44', 'United Kingdom'],
                                                ['🇦🇺', '61', 'Australia'],
                                                ['🇨🇦', '1', 'Canada'],
                                                ['🇩🇪', '49', 'Germany'],
                                                ['🇯🇵', '81', 'Japan'],
                                                ['🇧🇷', '55', 'Brazil'],
                                                ['🇿🇦', '27', 'South Africa'],
                                                ['🇫🇷', '33', 'France'],
                                                ['🇮🇹', '39', 'Italy'],
                                                ['🇸🇬', '65', 'Singapore'],
                                                ['🇦🇪', '971', 'UAE'],
                                                ['🇵🇰', '92', 'Pakistan'],
                                                ['🇧🇩', '880', 'Bangladesh'],
                                            ];
                                            foreach ($countries as [$f, $c, $n]):
                                            ?>
                                                <div class="wa-co" onclick="selCountry(event,'<?= $f ?>','+<?= $c ?>')">
                                                    <span class="wa-flag"><?= $f ?></span> <?= $n ?> <span class="wa-co-num">+<?= $c ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <input class="wa-input" type="tel" id="phoneInput" name="phone"
                                        placeholder="Enter mobile number"
                                        value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
                                        oninput="clearErr('phoneErr','phoneWrap')">
                                </div>
                                <div class="wa-field-err <?= ($error && strpos($error, 'phone') !== false) ? 'show' : '' ?>" id="phoneErr">
                                    Please enter a valid phone number
                                </div>
                            </div>

                            <!-- PASSWORD -->
                            <div class="wa-field">
                                <label class="wa-label" for="pwInput">Password</label>
                                <div class="wa-input-wrap <?= ($error && strpos($error, 'assword') !== false) ? 'error' : '' ?>" id="pwWrap">
                                    <input class="wa-input" type="password" id="pwInput" name="password"
                                        placeholder="Enter your password"
                                        oninput="checkStrength(); clearErr('pwErr','pwWrap')">
                                    <div class="wa-eye" onclick="toggleEye()">
                                        <svg id="eyeIco" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                            <circle cx="12" cy="12" r="3" />
                                        </svg>
                                    </div>
                                </div>
                                <div class="wa-strength">
                                    <div class="wa-bar" id="b1"></div>
                                    <div class="wa-bar" id="b2"></div>
                                    <div class="wa-bar" id="b3"></div>
                                    <div class="wa-bar" id="b4"></div>
                                </div>
                                <div class="wa-strength-lbl" id="pwLbl"></div>
                                <div class="wa-field-err <?= ($error && strpos($error, 'assword') !== false) ? 'show' : '' ?>" id="pwErr">
                                    Password must be at least 6 characters
                                </div>
                            </div>

                            <div class="wa-forgot"><a href="#">Forgot password?</a></div>

                            <button type="submit" class="wa-btn" id="submitBtn">Continue</button>

                            <div class="wa-divider">
                                <div class="wa-divider-line"></div><span>or</span>
                                <div class="wa-divider-line"></div>
                            </div>

                            <button type="button" class="wa-btn-ghost">
                                <svg viewBox="0 0 24 24" width="18" height="18">
                                    <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" />
                                    <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" />
                                    <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z" />
                                    <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" />
                                </svg>
                                Continue with Google
                            </button>

                        </form>

                    <?php endif; ?>
                </div><!-- /form panel -->
            </div><!-- /left -->

            <!-- RIGHT INFO PANEL -->
            <div class="wa-right">
                <div class="wa-app-icon">
                    <svg viewBox="0 0 24 24" fill="currentColor">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z" />
                        <path d="M12 0C5.373 0 0 5.373 0 12c0 2.127.558 4.126 1.532 5.862L.054 23.25a.75.75 0 00.916.916l5.388-1.478A11.953 11.953 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.99 0-3.86-.548-5.462-1.5l-.389-.228-4.037 1.108 1.108-4.037-.228-.389A9.953 9.953 0 012 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z" />
                    </svg>
                </div>
                <div class="wa-right-title">Use WhatsApp on your <strong>computer</strong></div>
                <div class="wa-right-desc">New here? We'll create your account automatically. Already joined? We'll log you right in.</div>
                <div class="wa-feat">
                    <div class="wa-feat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="11" width="18" height="11" rx="2" />
                            <path d="M7 11V7a5 5 0 0110 0v4" />
                        </svg></div>
                    <div class="wa-feat-text"><strong>End-to-end encrypted</strong>Your messages stay private always</div>
                </div>
                <div class="wa-feat">
                    <div class="wa-feat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z" />
                        </svg></div>
                    <div class="wa-feat-text"><strong>All your chats synced</strong>Messages across all your devices</div>
                </div>
                <div class="wa-feat">
                    <div class="wa-feat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10" />
                            <polyline points="12 6 12 12 16 14" />
                        </svg></div>
                    <div class="wa-feat-text"><strong>Smart sign-in</strong>Auto detects new vs returning users</div>
                </div>
            </div>

        </div>
    </div>

    <!-- FOOTER -->
    <div class="wa-footer">
        <a href="#">© 2024 WhatsApp LLC</a>
        <div class="wa-footer-dot"></div>
        <a href="#">Privacy Policy</a>
        <div class="wa-footer-dot"></div>
        <a href="#">Terms of Service</a>
        <div class="wa-footer-dot"></div>
        <a href="#">Help Center</a>
        <div class="wa-footer-dot"></div>
        <a href="#">Download</a>
    </div>

    <script>
        // ── TABS
        function switchTab(t) {
            document.getElementById('tabQr').classList.toggle('active', t === 'qr');
            document.getElementById('tabPw').classList.toggle('active', t === 'pw');
            document.getElementById('panelQr').style.display = t === 'qr' ? 'flex' : 'none';
            document.getElementById('panelPw').style.display = t === 'pw' ? 'flex' : 'none';
        }
        // ── COUNTRY DROPDOWN
        function toggleDd(e) {
            e.stopPropagation();
            document.getElementById('countryList').classList.toggle('open');
        }

        function selCountry(e, flag, code) {
            e.stopPropagation();
            document.getElementById('selFlag').textContent = flag;
            document.getElementById('selCode').textContent = code;
            document.getElementById('countryList').classList.remove('open');
        }
        document.addEventListener('click', () => document.getElementById('countryList').classList.remove('open'));
        // ── EYE TOGGLE
        function toggleEye() {
            var i = document.getElementById('pwInput');
            var ico = document.getElementById('eyeIco');
            if (i.type === 'password') {
                i.type = 'text';
                ico.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>';
            } else {
                i.type = 'password';
                ico.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
            }
        }
        // ── PASSWORD STRENGTH
        function checkStrength() {
            var pw = document.getElementById('pwInput').value;
            var bars = ['b1', 'b2', 'b3', 'b4'].map(id => document.getElementById(id));
            var lbl = document.getElementById('pwLbl');
            bars.forEach(b => b.className = 'wa-bar');
            if (!pw) {
                lbl.textContent = '';
                return;
            }
            var s = 0;
            if (pw.length >= 6) s++;
            if (pw.length >= 10) s++;
            if (/[A-Z]/.test(pw) && /[0-9]/.test(pw)) s++;
            if (/[^A-Za-z0-9]/.test(pw)) s++;
            var cls = s <= 1 ? 'weak' : s <= 2 ? 'medium' : 'strong';
            var txt = s <= 1 ? 'Weak' : s <= 2 ? 'Fair' : s <= 3 ? 'Good' : 'Strong';
            for (var i = 0; i < s; i++) bars[i].classList.add(cls);
            lbl.textContent = txt;
            lbl.style.color = cls === 'weak' ? '#f15c6d' : cls === 'medium' ? '#f0b429' : '#00a884';
        }
        // ── CLEAR ERROR
        function clearErr(errId, wrapId) {
            document.getElementById(errId).classList.remove('show');
            document.getElementById(wrapId).classList.remove('error');
        }
        // ── SMART: detect if user is new and show name field via AJAX check
        document.getElementById('phoneInput').addEventListener('blur', function() {
            var phone = this.value.trim();
            if (phone.length < 7) return;
            fetch('check_user.php?phone=' + encodeURIComponent(phone))
                .then(r => r.json())
                .then(data => {
                    var nf = document.getElementById('nameField');
                    var title = document.getElementById('formTitle');
                    var sub = document.getElementById('formSub');
                    var btn = document.getElementById('submitBtn');
                    if (!data.exists) {
                        nf.style.display = 'block';
                        title.textContent = 'Create your account';
                        sub.textContent = 'New number! Enter your name to register.';
                        btn.textContent = 'Create Account';
                    } else {
                        nf.style.display = 'none';
                        title.textContent = 'Welcome back';
                        sub.textContent = 'Enter your password to continue.';
                        btn.textContent = 'Log In';
                    }
                })
                .catch(() => {}); // silently ignore if check_user.php isn't available
        });

        // Auto-open Phone tab on page load
        switchTab('pw');

        <?php if ($mode === 'needs_name'): ?>
            // Server told us it's a new user – show name field
            document.getElementById('nameField').style.display = 'block';
            document.getElementById('formTitle').textContent = 'Create your account';
            document.getElementById('submitBtn').textContent = 'Create Account';
        <?php endif; ?>
    </script>

</body>

</html>