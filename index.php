<?php
session_start();

// ── Auth guard ──────────────────────────────────────────────────────────────
if (empty($_SESSION['user_id'])) {
	header('Location: login.php');
	exit;
}

// ── DB ───────────────────────────────────────────────────────────────────────
$host = 'localhost';
$db = 'chatapp';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';
try {
	$pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
	]);
} catch (PDOException $e) {
	die('DB error: ' . $e->getMessage());
}

$me = $_SESSION['user_id']; // phone

// ── Load all users except self (for "new chat") ───────────────────────────
$allUsers = $pdo->prepare('SELECT phone, name, avatar, status FROM users WHERE phone != ? ORDER BY name');
$allUsers->execute([$me]);
$allUsers = $allUsers->fetchAll();

// ── Load chat list: users I've had conversations with ──────────────────────
$chatList = $pdo->prepare("
    SELECT u.phone, u.name, u.avatar, u.status,
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
$chatList->execute([$me, $me, $me, $me]);
$chatList = $chatList->fetchAll();

// ── My profile ────────────────────────────────────────────────────────────
$myProfile = $pdo->prepare('SELECT name, avatar FROM users WHERE phone = ?');
$myProfile->execute([$me]);
$myProfile = $myProfile->fetch();
?>
<!DOCTYPE html>
<html lang="en">

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>ChatApp - Real time web app</title>
	<style>
		* {
			box-sizing: border-box;
			margin: 0;
			padding: 0;
			font-family: 'Segoe UI', system-ui, sans-serif;
		}

		:root {
			--g: #00a884;
			--gd: #008069;
			--bg: #111b21;
			--panel: #202c33;
			--chat-bg: #0b141a;
			--inp: #2a3942;
			--border: #2a3942;
			--tp: #e9edef;
			--ts: #8696a0;
			--tm: #667781;
			--bin: #202c33;
			--bout: #005c4b;
			--hov: #2a3942;
			--icon: #aebac1;
		}

		html,
		body {
			height: 100%;
			background: var(--bg);
			overflow: hidden;
		}

		.app {
			display: flex;
			height: 100vh;
		}

		/* ── SIDEBAR ── */
		.sidebar {
			width: 380px;
			min-width: 380px;
			display: flex;
			flex-direction: column;
			background: var(--bg);
			border-right: 1px solid var(--border);
		}

		.sidebar-hdr {
			display: flex;
			align-items: center;
			justify-content: space-between;
			padding: 10px 16px;
			background: var(--panel);
			height: 60px;
			flex-shrink: 0;
		}

		.avatar {
			width: 40px;
			height: 40px;
			border-radius: 50%;
			object-fit: cover;
			cursor: pointer;
			background: var(--inp);
			display: flex;
			align-items: center;
			justify-content: center;
			flex-shrink: 0;
		}

		.avatar-sm {
			width: 49px;
			height: 49px;
			border-radius: 50%;
			object-fit: cover;
			flex-shrink: 0;
			background: var(--inp);
		}

		.avatar img,
		.avatar-sm img {
			width: 100%;
			height: 100%;
			border-radius: 50%;
			object-fit: cover;
		}

		.avatar-init {
			font-size: 16px;
			font-weight: 600;
			color: white;
		}

		.avatar-sm-init {
			font-size: 18px;
			font-weight: 600;
			color: white;
			width: 100%;
			height: 100%;
			display: flex;
			align-items: center;
			justify-content: center;
			border-radius: 50%;
		}

		.icons {
			display: flex;
			gap: 6px;
			align-items: center;
		}

		.icon-btn {
			width: 36px;
			height: 36px;
			border-radius: 50%;
			display: flex;
			align-items: center;
			justify-content: center;
			cursor: pointer;
			color: var(--icon);
			transition: background .15s;
		}

		.icon-btn:hover {
			background: var(--hov);
		}

		.icon-btn svg {
			width: 22px;
			height: 22px;
		}

		.search-bar {
			padding: 8px 12px;
			background: var(--bg);
			flex-shrink: 0;
		}

		.search-wrap {
			display: flex;
			align-items: center;
			background: var(--inp);
			border-radius: 8px;
			padding: 6px 12px;
			gap: 8px;
		}

		.search-wrap svg {
			color: var(--ts);
			width: 18px;
			height: 18px;
			flex-shrink: 0;
		}

		.search-wrap input {
			background: none;
			border: none;
			outline: none;
			color: var(--tp);
			font-size: 14px;
			width: 100%;
		}

		.search-wrap input::placeholder {
			color: var(--tm);
		}

		.chat-list {
			overflow-y: auto;
			flex: 1;
		}

		.chat-list::-webkit-scrollbar {
			width: 4px;
		}

		.chat-list::-webkit-scrollbar-thumb {
			background: #374045;
			border-radius: 2px;
		}

		.chat-tile {
			display: flex;
			align-items: center;
			gap: 12px;
			padding: 10px 16px;
			cursor: pointer;
			border-bottom: 1px solid #1a2630;
			transition: background .12s;
		}

		.chat-tile:hover {
			background: var(--hov);
		}

		.chat-tile.active {
			background: var(--hov);
		}

		.tile-info {
			flex: 1;
			min-width: 0;
		}

		.tile-top {
			display: flex;
			justify-content: space-between;
			align-items: center;
			margin-bottom: 3px;
		}

		.tile-name {
			font-size: 16px;
			color: var(--tp);
			font-weight: 400;
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
		}

		.tile-time {
			font-size: 12px;
			color: var(--tm);
			flex-shrink: 0;
		}

		.tile-bottom {
			display: flex;
			justify-content: space-between;
			align-items: center;
		}

		.tile-preview {
			font-size: 14px;
			color: var(--tm);
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
			max-width: 220px;
		}

		.unread-badge {
			background: var(--g);
			color: white;
			font-size: 11px;
			font-weight: 600;
			border-radius: 50%;
			width: 20px;
			height: 20px;
			display: flex;
			align-items: center;
			justify-content: center;
			flex-shrink: 0;
		}

		.status-dot {
			width: 9px;
			height: 9px;
			border-radius: 50%;
			flex-shrink: 0;
		}

		.status-dot.online {
			background: var(--g);
		}

		.status-dot.offline {
			background: var(--tm);
		}

		.status-dot.away {
			background: #f0b429;
		}

		.no-chats {
			padding: 32px 16px;
			text-align: center;
			color: var(--tm);
			font-size: 14px;
		}

		.no-chats svg {
			width: 48px;
			height: 48px;
			color: var(--border);
			margin-bottom: 12px;
		}

		/* ── MAIN / WELCOME ── */
		.main {
			flex: 1;
			display: flex;
			flex-direction: column;
			background: var(--chat-bg);
			position: relative;
		}

		.welcome-screen {
			flex: 1;
			display: flex;
			flex-direction: column;
			align-items: center;
			justify-content: center;
			text-align: center;
			padding: 40px;
		}

		.welcome-screen svg {
			width: 80px;
			height: 80px;
			color: var(--border);
			margin-bottom: 20px;
		}

		.welcome-screen h2 {
			font-size: 30px;
			font-weight: 300;
			color: var(--tp);
			margin-bottom: 10px;
		}

		.welcome-screen p {
			font-size: 14px;
			color: var(--ts);
			max-width: 360px;
			line-height: 1.6;
		}

		.welcome-screen .lock {
			display: flex;
			align-items: center;
			gap: 6px;
			margin-top: 20px;
			font-size: 13px;
			color: var(--tm);
		}

		.welcome-screen .lock svg {
			width: 14px;
			height: 14px;
		}

		/* ── CHAT WINDOW ── */
		.chat-win {
			flex: 1;
			display: flex;
			flex-direction: column;
			display: none;
		}

		.chat-hdr {
			display: flex;
			align-items: center;
			gap: 12px;
			padding: 10px 16px;
			background: var(--panel);
			border-bottom: 1px solid var(--border);
			height: 60px;
			flex-shrink: 0;
			cursor: pointer;
		}

		.chat-hdr:hover {
			background: #263036;
		}

		.chat-title {
			font-size: 16px;
			color: var(--tp);
			font-weight: 500;
		}

		.chat-sub {
			font-size: 13px;
			color: var(--ts);
		}

		.chat-body {
			flex: 1;
			overflow-y: auto;
			padding: 16px 8%;
			display: flex;
			flex-direction: column;
			gap: 2px;
			background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='120' height='120'%3E%3Crect fill='%230b141a' width='120' height='120'/%3E%3Cpath d='M20 20h2v2h-2zM60 20h2v2h-2zM100 20h2v2h-2zM40 40h2v2h-2zM80 40h2v2h-2zM20 60h2v2h-2zM60 60h2v2h-2zM100 60h2v2h-2zM40 80h2v2h-2zM80 80h2v2h-2zM20 100h2v2h-2zM60 100h2v2h-2zM100 100h2v2h-2z' fill='%231a2630' opacity='0.5'/%3E%3C/svg%3E");
		}

		.chat-body::-webkit-scrollbar {
			width: 4px;
		}

		.chat-body::-webkit-scrollbar-thumb {
			background: #374045;
			border-radius: 2px;
		}

		.datestamp {
			display: flex;
			justify-content: center;
			margin: 10px 0;
		}

		.datestamp span {
			background: #182229;
			color: var(--ts);
			font-size: 12px;
			padding: 5px 12px;
			border-radius: 8px;
			border: 1px solid var(--border);
		}

		.msg-group {
			display: flex;
			gap: 8px;
			margin-bottom: 2px;
			align-items: flex-end;
		}

		.msg-group.own {
			flex-direction: row-reverse;
		}

		.msg-avatar {
			width: 32px;
			height: 32px;
			border-radius: 50%;
			object-fit: cover;
			flex-shrink: 0;
			margin-bottom: 4px;
			background: var(--inp);
			display: flex;
			align-items: center;
			justify-content: center;
			font-size: 12px;
			font-weight: 600;
			color: white;
		}

		.msg-avatar img {
			width: 32px;
			height: 32px;
			border-radius: 50%;
		}

		.messages {
			display: flex;
			flex-direction: column;
			gap: 2px;
			max-width: 65%;
		}

		.msg-group.own .messages {
			align-items: flex-end;
		}

		.bubble {
			background: var(--bin);
			color: var(--tp);
			padding: 6px 9px 8px;
			border-radius: 8px;
			font-size: 14.2px;
			line-height: 1.4;
			position: relative;
			word-break: break-word;
		}

		.bubble:first-child {
			border-top-left-radius: 0;
		}

		.msg-group.own .bubble {
			background: var(--bout);
		}

		.msg-group.own .bubble:first-child {
			border-top-left-radius: 8px;
			border-top-right-radius: 0;
		}

		.bubble-time {
			font-size: 11px;
			color: var(--tm);
			float: right;
			margin-left: 8px;
			margin-top: 2px;
			line-height: 1;
		}

		.ticks {
			display: inline-flex;
			align-items: center;
			margin-left: 2px;
		}

		.ticks svg {
			width: 14px;
			height: 14px;
		}

		.ticks.read svg {
			color: #53bdeb;
		}

		.ticks.sent svg {
			color: var(--ts);
		}

		.typing-indicator {
			display: none;
			padding: 4px 0;
		}

		.typing-indicator.show {
			display: flex;
		}

		.typing-dot {
			width: 7px;
			height: 7px;
			background: var(--ts);
			border-radius: 50%;
			margin: 0 2px;
			animation: typingBounce 1.2s infinite;
		}

		.typing-dot:nth-child(2) {
			animation-delay: .2s;
		}

		.typing-dot:nth-child(3) {
			animation-delay: .4s;
		}

		@keyframes typingBounce {

			0%,
			60%,
			100% {
				transform: translateY(0)
			}

			30% {
				transform: translateY(-6px)
			}
		}

		.loading-msgs {
			text-align: center;
			padding: 24px;
			color: var(--tm);
			font-size: 13px;
		}

		/* ── FOOTER ── */
		.chat-footer {
			display: flex;
			align-items: center;
			gap: 8px;
			padding: 8px 12px;
			background: var(--panel);
			border-top: 1px solid var(--border);
			flex-shrink: 0;
		}

		.compose {
			flex: 1;
			background: var(--inp);
			border: none;
			border-radius: 8px;
			padding: 9px 14px;
			color: var(--tp);
			font-size: 15px;
			outline: none;
			resize: none;
			max-height: 120px;
			line-height: 1.4;
		}

		.compose::placeholder {
			color: var(--tm);
		}

		.send-btn {
			width: 44px;
			height: 44px;
			border-radius: 50%;
			background: var(--g);
			display: flex;
			align-items: center;
			justify-content: center;
			cursor: pointer;
			flex-shrink: 0;
			transition: background .15s;
			border: none;
		}

		.send-btn:hover {
			background: var(--gd);
		}

		.send-btn svg {
			width: 22px;
			height: 22px;
			color: white;
		}

		.send-btn:disabled {
			opacity: .5;
			cursor: default;
		}

		.scroll-btn {
			position: absolute;
			bottom: 72px;
			right: 20px;
			width: 38px;
			height: 38px;
			background: var(--panel);
			border-radius: 50%;
			display: flex;
			align-items: center;
			justify-content: center;
			cursor: pointer;
			border: 1px solid var(--border);
			color: var(--icon);
			z-index: 10;
		}

		.scroll-btn svg {
			width: 18px;
			height: 18px;
		}

		/* ── NEW CHAT MODAL ── */
		.modal-overlay {
			display: none;
			position: fixed;
			inset: 0;
			background: rgba(0, 0, 0, .5);
			z-index: 100;
			align-items: center;
			justify-content: center;
		}

		.modal-overlay.open {
			display: flex;
		}

		.modal {
			background: var(--panel);
			border-radius: 12px;
			width: 360px;
			max-height: 520px;
			display: flex;
			flex-direction: column;
			border: 1px solid var(--border);
			overflow: hidden;
		}

		.modal-hdr {
			padding: 16px 20px;
			border-bottom: 1px solid var(--border);
			display: flex;
			align-items: center;
			justify-content: space-between;
		}

		.modal-hdr h3 {
			color: var(--tp);
			font-size: 16px;
			font-weight: 500;
		}

		.modal-close {
			cursor: pointer;
			color: var(--ts);
			width: 28px;
			height: 28px;
			display: flex;
			align-items: center;
			justify-content: center;
			border-radius: 50%;
		}

		.modal-close:hover {
			background: var(--inp);
			color: var(--tp);
		}

		.modal-close svg {
			width: 18px;
			height: 18px;
		}

		.modal-search {
			padding: 10px 16px;
			border-bottom: 1px solid var(--border);
		}

		.modal-search input {
			width: 100%;
			background: var(--inp);
			border: none;
			border-radius: 8px;
			padding: 8px 12px;
			color: var(--tp);
			font-size: 14px;
			outline: none;
		}

		.modal-search input::placeholder {
			color: var(--tm);
		}

		.modal-list {
			overflow-y: auto;
			flex: 1;
		}

		.modal-list::-webkit-scrollbar {
			width: 4px;
		}

		.modal-list::-webkit-scrollbar-thumb {
			background: #374045;
			border-radius: 2px;
		}

		.modal-user {
			display: flex;
			align-items: center;
			gap: 12px;
			padding: 12px 16px;
			cursor: pointer;
			transition: background .12s;
		}

		.modal-user:hover {
			background: var(--hov);
		}

		.modal-user-name {
			font-size: 15px;
			color: var(--tp);
		}

		.modal-user-phone {
			font-size: 12px;
			color: var(--tm);
		}

		/* ── PROFILE PANEL ── */
		.profile-panel {
			position: absolute;
			top: 0;
			left: 0;
			right: 0;
			bottom: 0;
			background: var(--bg);
			z-index: 50;
			display: none;
			flex-direction: column;
		}

		.profile-panel.open {
			display: flex;
		}

		.profile-hdr {
			display: flex;
			align-items: center;
			gap: 16px;
			padding: 16px 20px;
			background: var(--panel);
			border-bottom: 1px solid var(--border);
		}

		.profile-back {
			cursor: pointer;
			color: var(--ts);
			width: 32px;
			height: 32px;
			display: flex;
			align-items: center;
			justify-content: center;
			border-radius: 50%;
		}

		.profile-back:hover {
			background: var(--inp);
			color: var(--tp);
		}

		.profile-back svg {
			width: 20px;
			height: 20px;
		}

		.profile-hdr h3 {
			color: var(--tp);
			font-size: 16px;
			font-weight: 500;
		}

		.profile-body {
			flex: 1;
			overflow-y: auto;
			padding: 24px 20px;
		}

		.profile-avatar-wrap {
			display: flex;
			justify-content: center;
			margin-bottom: 24px;
		}

		.profile-avatar-big {
			width: 120px;
			height: 120px;
			border-radius: 50%;
			background: var(--g);
			display: flex;
			align-items: center;
			justify-content: center;
			font-size: 48px;
			font-weight: 600;
			color: white;
		}

		.profile-info-card {
			background: var(--panel);
			border-radius: 10px;
			border: 1px solid var(--border);
			overflow: hidden;
			margin-bottom: 12px;
		}

		.profile-info-row {
			padding: 14px 16px;
			border-bottom: 1px solid var(--border);
		}

		.profile-info-row:last-child {
			border-bottom: none;
		}

		.profile-info-label {
			font-size: 12px;
			color: var(--g);
			margin-bottom: 4px;
		}

		.profile-info-value {
			font-size: 15px;
			color: var(--tp);
		}

		.profile-status-row {
			display: flex;
			align-items: center;
			gap: 8px;
		}

		.logout-btn {
			width: 100%;
			background: rgba(241, 92, 109, .1);
			color: #f15c6d;
			border: 1px solid rgba(241, 92, 109, .3);
			border-radius: 8px;
			padding: 12px;
			font-size: 15px;
			cursor: pointer;
			margin-top: 16px;
			transition: background .15s;
		}

		.logout-btn:hover {
			background: rgba(241, 92, 109, .2);
		}

		/* ── UTILS ── */
		.color-1 {
			background: #e06c75
		}

		.color-2 {
			background: #e5c07b
		}

		.color-3 {
			background: #98c379
		}

		.color-4 {
			background: #56b6c2
		}

		.color-5 {
			background: #61afef
		}

		.color-6 {
			background: #c678dd
		}

		.color-7 {
			background: #f0b429
		}

		.color-8 {
			background: #00a884
		}

		@keyframes msgIn {
			from {
				opacity: 0;
				transform: translateY(6px)
			}

			to {
				opacity: 1;
				transform: translateY(0)
			}
		}

		.bubble-new {
			animation: msgIn .2s ease;
		}

		/* ── MOBILE BACK BUTTON (hidden on desktop) ── */
		.back-btn {
			display: none;
			width: 36px;
			height: 36px;
			align-items: center;
			justify-content: center;
			border-radius: 50%;
			cursor: pointer;
			color: var(--icon);
			flex-shrink: 0;
			transition: background .15s;
		}

		.back-btn:hover {
			background: var(--hov);
		}

		.back-btn svg {
			width: 22px;
			height: 22px;
		}

		/* ── MOBILE ── */
		@media (max-width: 768px) {

			html,
			body {
				height: 100%;
				overflow: hidden;
				position: fixed;
				width: 100%;
			}

			.app {
				position: fixed;
				inset: 0;
				overflow: hidden;
			}

			.sidebar {
				width: 100%;
				min-width: 100%;
				position: absolute;
				inset: 0;
				z-index: 10;
				transition: transform .28s cubic-bezier(.4, 0, .2, 1);
			}

			.sidebar.slide-out {
				transform: translateX(-100%);
			}

			.main {
				width: 100%;
				position: absolute;
				inset: 0;
				z-index: 5;
				transform: translateX(100%);
				transition: transform .28s cubic-bezier(.4, 0, .2, 1);
				background: var(--chat-bg);
			}

			.main.slide-in {
				transform: translateX(0);
			}

			.back-btn {
				display: flex;
			}

			.chat-hdr {
				cursor: default;
			}

			.chat-hdr:hover {
				background: var(--panel);
			}

			.chat-body {
				padding: 12px 3%;
			}

			.messages {
				max-width: 84%;
			}

			.modal {
				width: 95%;
				max-height: 82vh;
			}

			.profile-panel {
				position: fixed;
				z-index: 200;
			}

			.welcome-screen h2 {
				font-size: 22px;
			}

			.welcome-screen svg {
				width: 56px;
				height: 56px;
			}

			.scroll-btn {
				bottom: 68px;
				right: 12px;
			}

			.welcome-screen p {
				font-size: 13px;
			}

			.sidebar-hdr {
				padding: 8px 12px;
			}

			.chat-footer {
				padding: 6px 10px;
				gap: 6px;
			}

			.send-btn {
				width: 40px;
				height: 40px;
			}

			.icon-btn {
				width: 34px;
				height: 34px;
			}
		}

		@media (max-width: 420px) {
			.chat-body {
				padding: 8px 2%;
			}

			.messages {
				max-width: 90%;
			}

			.compose {
				font-size: 14px;
				padding: 8px 10px;
			}

			.bubble {
				font-size: 13.5px;
			}
		}
	</style>
</head>

<body>
	<div class="app">

		<!-- ── SIDEBAR ── -->
		<div class="sidebar">
			<div class="sidebar-hdr">
				<div class="avatar color-<?= (crc32($me) % 8) + 1 ?>" onclick="openProfile()" title="My profile">
					<?php if ($myProfile['avatar'] && $myProfile['avatar'] !== '?'): ?>
						<img src="<?= htmlspecialchars($myProfile['avatar']) ?>" alt="">
					<?php else: ?>
						<span class="avatar-init"><?= strtoupper(mb_substr($myProfile['name'], 0, 1)) ?></span>
					<?php endif; ?>
				</div>
				<div class="icons">
					<div class="icon-btn" title="New chat" onclick="openNewChat()">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
							<path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z" />
							<line x1="12" y1="8" x2="12" y2="16" />
							<line x1="8" y1="12" x2="16" y2="12" />
						</svg>
					</div>
					<div class="icon-btn" title="Menu" onclick="openProfile()">
						<svg viewBox="0 0 24 24" fill="currentColor">
							<circle cx="12" cy="5" r="1.8" />
							<circle cx="12" cy="12" r="1.8" />
							<circle cx="12" cy="19" r="1.8" />
						</svg>
					</div>
				</div>
			</div>
			<div class="search-bar">
				<div class="search-wrap">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
						<circle cx="11" cy="11" r="8" />
						<line x1="21" y1="21" x2="16.65" y2="16.65" />
					</svg>
					<input type="text" placeholder="Search or start new chat" id="sidebarSearch" oninput="filterChats(this.value)">
				</div>
			</div>
			<div class="chat-list" id="chatList">
				<?php if (empty($chatList)): ?>
					<div class="no-chats">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
							<path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z" />
						</svg>
						<p>No conversations yet.<br>Click the chat icon to start one!</p>
					</div>
				<?php else: ?>
					<?php foreach ($chatList as $c): ?>
						<?php $initials = strtoupper(mb_substr($c['name'], 0, 1));
						$colorNum = (crc32($c['phone']) % 8) + 1; ?>
						<div class="chat-tile" data-phone="<?= htmlspecialchars($c['phone']) ?>" data-name="<?= htmlspecialchars($c['name']) ?>"
							onclick="openChat('<?= htmlspecialchars($c['phone']) ?>','<?= htmlspecialchars($c['name']) ?>')">
							<div class="avatar-sm color-<?= $colorNum ?>">
								<div class="avatar-sm-init"><?= $initials ?></div>
							</div>
							<div class="tile-info">
								<div class="tile-top">
									<span class="tile-name"><?= htmlspecialchars($c['name']) ?></span>
									<span class="tile-time"><?= formatTime($c['last_time']) ?></span>
								</div>
								<div class="tile-bottom">
									<span class="tile-preview">
										<?= $c['sender_id'] === $me ? 'You: ' : '' ?><?= htmlspecialchars(mb_substr($c['last_msg'], 0, 35)) ?><?= mb_strlen($c['last_msg']) > 35 ? '...' : '' ?>
									</span>
									<?php if ($c['unread'] > 0): ?>
										<span class="unread-badge"><?= $c['unread'] ?></span>
									<?php endif; ?>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</div>

		<!-- ── MAIN ── -->
		<div class="main" id="main">

			<!-- Welcome screen -->
			<div class="welcome-screen" id="welcomeScreen">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2">
					<path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z" />
				</svg>
				<h2>WhatsApp Web</h2>
				<p>Send and receive messages without keeping your phone online. Use WhatsApp on up to 4 linked devices and 1 phone at the same time.</p>
				<div class="lock">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
						<rect x="3" y="11" width="18" height="11" rx="2" />
						<path d="M7 11V7a5 5 0 0110 0v4" />
					</svg>
					Your personal messages are end-to-end encrypted
				</div>
			</div>

			<!-- Chat window -->
			<div class="chat-win" id="chatWin">
				<div class="chat-hdr" id="chatHdr" onclick="toggleProfile()">
					<div class="back-btn" onclick="event.stopPropagation(); goBackToList()" title="Back">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<polyline points="15 18 9 12 15 6" />
						</svg>
					</div>
					<div class="avatar-sm color-1" id="chatAvatar" style="width:40px;height:40px;">
						<div class="avatar-sm-init" id="chatAvatarInit" style="font-size:14px;"></div>
					</div>
					<div style="flex:1;min-width:0;">
						<div class="chat-title" id="chatTitle">—</div>
						<div class="chat-sub" id="chatSub">click to view profile</div>
					</div>
					<div class="icons" onclick="e => e.stopPropagation()">
						<div class="icon-btn" onclick="event.stopPropagation()">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
								<circle cx="11" cy="11" r="8" />
								<line x1="21" y1="21" x2="16.65" y2="16.65" />
							</svg>
						</div>
						<div class="icon-btn" onclick="event.stopPropagation()">
							<svg viewBox="0 0 24 24" fill="currentColor">
								<circle cx="12" cy="5" r="1.8" />
								<circle cx="12" cy="12" r="1.8" />
								<circle cx="12" cy="19" r="1.8" />
							</svg>
						</div>
					</div>
				</div>
				<div class="chat-body" id="chatBody">
					<div class="loading-msgs" id="loadingMsgs">Loading messages...</div>
				</div>
				<div class="scroll-btn" id="scrollBtn" onclick="scrollToBottom(true)" style="display:none;">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
						<polyline points="6 9 12 15 18 9" />
					</svg>
				</div>
				<div class="chat-footer">
					<div class="icon-btn">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
							<circle cx="12" cy="12" r="10" />
							<path d="M8 14s1.5 2 4 2 4-2 4-2" />
							<line x1="9" y1="9" x2="9.01" y2="9" />
							<line x1="15" y1="9" x2="15.01" y2="9" />
						</svg>
					</div>
					<div class="icon-btn">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
							<path d="M21.44 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.19-9.19a4 4 0 015.66 5.66l-9.2 9.19a2 2 0 01-2.83-2.83l8.49-8.48" />
						</svg>
					</div>
					<textarea class="compose" id="compose" placeholder="Type a message" rows="1"
						onkeydown="handleKey(event)" oninput="autoResize(this); onTypingInput()"></textarea>
					<button class="send-btn" id="sendBtn" onclick="sendMessage()">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<path d="M22 2L11 13" />
							<path d="M22 2L15 22 11 13 2 9l20-7z" />
						</svg>
					</button>
				</div>
			</div>

			<!-- Profile panel (right side) -->
			<div class="profile-panel" id="profilePanel">
				<div class="profile-hdr">
					<div class="profile-back" onclick="closeProfile()">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<polyline points="15 18 9 12 15 6" />
						</svg>
					</div>
					<h3 id="profilePanelTitle">Contact Info</h3>
				</div>
				<div class="profile-body">
					<div class="profile-avatar-wrap">
						<div class="profile-avatar-big color-1" id="profileBigAvatar"></div>
					</div>
					<div class="profile-info-card">
						<div class="profile-info-row">
							<div class="profile-info-label">Name</div>
							<div class="profile-info-value" id="profileName">—</div>
						</div>
						<div class="profile-info-row">
							<div class="profile-info-label">Phone</div>
							<div class="profile-info-value" id="profilePhone">—</div>
						</div>
						<div class="profile-info-row">
							<div class="profile-info-label">Bio</div>
							<div class="profile-info-value" id="profileBio">Hey there! I am using WhatsApp.</div>
						</div>
						<div class="profile-info-row">
							<div class="profile-info-label">Status</div>
							<div class="profile-status-row">
								<div class="status-dot" id="profileStatusDot"></div>
								<div class="profile-info-value" id="profileStatus">—</div>
							</div>
						</div>
					</div>
				</div>
			</div>

		</div><!-- /main -->
	</div><!-- /app -->

	<!-- ── MY PROFILE PANEL (sidebar avatar click) ── -->
	<div class="modal-overlay" id="myProfileOverlay" onclick="closeMyProfile(event)">
		<div class="modal" onclick="event.stopPropagation()">
			<div class="modal-hdr">
				<h3>My Profile</h3>
				<div class="modal-close" onclick="closeMyProfile()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
						<line x1="18" y1="6" x2="6" y2="18" />
						<line x1="6" y1="6" x2="18" y2="18" />
					</svg></div>
			</div>
			<div class="profile-body" style="padding:20px;">
				<div class="profile-avatar-wrap">
					<div class="profile-avatar-big color-<?= (crc32($me) % 8) + 1 ?>"><?= strtoupper(mb_substr($myProfile['name'], 0, 1)) ?></div>
				</div>
				<div class="profile-info-card">
					<div class="profile-info-row">
						<div class="profile-info-label">Name</div>
						<div class="profile-info-value"><?= htmlspecialchars($myProfile['name']) ?></div>
					</div>
					<div class="profile-info-row">
						<div class="profile-info-label">Phone</div>
						<div class="profile-info-value"><?= htmlspecialchars($me) ?></div>
					</div>
				</div>
				<form method="POST" action="logout.php">
					<button type="submit" class="logout-btn">Log out</button>
				</form>
			</div>
		</div>
	</div>

	<!-- ── NEW CHAT MODAL ── -->
	<div class="modal-overlay" id="newChatModal" onclick="closeNewChat(event)">
		<div class="modal" onclick="event.stopPropagation()">
			<div class="modal-hdr">
				<h3>New Chat</h3>
				<div class="modal-close" onclick="closeNewChat()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
						<line x1="18" y1="6" x2="6" y2="18" />
						<line x1="6" y1="6" x2="18" y2="18" />
					</svg></div>
			</div>
			<div class="modal-search">
				<input type="text" placeholder="Search contacts..." id="contactSearch" oninput="filterContacts(this.value)">
			</div>
			<div class="modal-list" id="contactList">
				<?php foreach ($allUsers as $u):
					$ci = strtoupper(mb_substr($u['name'], 0, 1));
					$cn = (crc32($u['phone']) % 8) + 1;
				?>
					<div class="modal-user" data-name="<?= htmlspecialchars($u['name']) ?>"
						onclick="startChat('<?= htmlspecialchars($u['phone']) ?>','<?= htmlspecialchars($u['name']) ?>')">
						<div class="avatar-sm color-<?= $cn ?>" style="width:42px;height:42px;">
							<div class="avatar-sm-init" style="font-size:16px;"><?= $ci ?></div>
						</div>
						<div>
							<div class="modal-user-name"><?= htmlspecialchars($u['name']) ?></div>
							<div class="modal-user-phone"><?= htmlspecialchars($u['phone']) ?></div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	</div>

	<script>
		const ME = <?= json_encode($me) ?>;
		let currentChat = null; // phone of open chat
		let lastMsgId = 0;
		let pollInterval = null;
		let sidebarPollInterval = null;
		let isProfileOpen = false;
		let isMyProfileOpen = false;

		// ── COLOR helper ──────────────────────────────────────────────────────────
		function colorFor(phone) {
			let h = 0;
			for (let i = 0; i < phone.length; i++) h = (Math.imul(31, h) + phone.charCodeAt(i)) | 0;
			return (Math.abs(h) % 8) + 1;
		}

		// ── FORMAT TIME ───────────────────────────────────────────────────────────
		function fmtTime(ts) {
			if (!ts) return '';
			const d = new Date(ts.replace(' ', 'T'));
			const now = new Date();
			const diff = now - d;
			if (diff < 86400000 && d.getDate() === now.getDate()) return d.toLocaleTimeString([], {
				hour: '2-digit',
				minute: '2-digit'
			});
			if (diff < 604800000) return ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][d.getDay()];
			return d.toLocaleDateString([], {
				day: '2-digit',
				month: '2-digit',
				year: '2-digit'
			});
		}

		// ── OPEN CHAT ─────────────────────────────────────────────────────────────
		function openChat(phone, name) {
			// Stop typing in previous chat
			if (currentChat && currentChat !== phone) stopTypingOnSend();
			currentChat = phone;
			lastMsgId = 0;
			document.getElementById('welcomeScreen').style.display = 'none';
			document.getElementById('chatWin').style.display = 'flex';
			document.getElementById('chatTitle').textContent = name;
			document.getElementById('chatSub').textContent = 'click for contact info';
			document.getElementById('chatAvatarInit').textContent = name[0].toUpperCase();
			const av = document.getElementById('chatAvatar');
			av.className = 'avatar-sm color-' + colorFor(phone);
			av.style.width = '40px';
			av.style.height = '40px';
			document.getElementById('chatBody').innerHTML = '<div class="loading-msgs">Loading messages...</div>';
			// mark active tile
			document.querySelectorAll('.chat-tile').forEach(t => t.classList.toggle('active', t.dataset.phone === phone));
			// clear unread badge on tile
			const tile = document.querySelector(`.chat-tile[data-phone="${phone}"]`);
			if (tile) {
				const b = tile.querySelector('.unread-badge');
				if (b) b.remove();
			}
			// close profile if open
			document.getElementById('profilePanel').classList.remove('open');
			// fetch initial messages
			fetchMessages(true);
			// start polling
			clearInterval(pollInterval);
			pollInterval = setInterval(() => fetchMessages(false), 1500);
			document.getElementById('compose').focus();
		}

		function startChat(phone, name) {
			closeNewChat();
			// add to sidebar if not present
			if (!document.querySelector(`.chat-tile[data-phone="${phone}"]`)) {
				addTileToSidebar(phone, name, '', '');
			}
			openChat(phone, name);
		}

		// ── FETCH MESSAGES ────────────────────────────────────────────────────────
		function fetchMessages(initial) {
			if (!currentChat) return;
			fetch(`api.php?action=get_messages&with=${encodeURIComponent(currentChat)}&after=${lastMsgId}`)
				.then(r => r.json())
				.then(data => {
					if (data.error) return;
					if (initial) {
						document.getElementById('chatBody').innerHTML = '';
						lastMsgId = 0;
					}
					if (data.messages && data.messages.length > 0) {
						renderMessages(data.messages, initial);
						lastMsgId = data.messages[data.messages.length - 1].id;
						// mark as read
						fetch(`api.php?action=mark_read&from=${encodeURIComponent(currentChat)}`, {
							method: 'POST'
						});
						// update sidebar tile
						const last = data.messages[data.messages.length - 1];
						updateSidebarTile(last.sender_id === ME ? currentChat : last.sender_id,
							document.getElementById('chatTitle').textContent, last.body, last.created_at);
					} else if (initial) {
						document.getElementById('chatBody').innerHTML = '<div class="loading-msgs" style="color:var(--tm)">No messages yet. Say hello! 👋</div>';
					}
					// update contact sub with status + typing
					updateChatSubStatus(data.contact_status, data.is_typing);
				}).catch(() => {});
		}

		// ── RENDER MESSAGES ───────────────────────────────────────────────────────
		function renderMessages(msgs, initial) {
			const body = document.getElementById('chatBody');
			let lastDate = '',
				lastSender = '',
				lastSenderEl = null;
			// get last existing date stamp
			const existing = body.querySelectorAll('.datestamp span');
			if (existing.length) lastDate = existing[existing.length - 1].textContent;

			msgs.forEach((m, idx) => {
				const d = new Date(m.created_at.replace(' ', 'T'));
				const dateStr = d.toLocaleDateString([], {
					day: '2-digit',
					month: '2-digit',
					year: 'numeric'
				});
				if (dateStr !== lastDate) {
					const ds = document.createElement('div');
					ds.className = 'datestamp';
					ds.innerHTML = `<span>${dateStr}</span>`;
					body.appendChild(ds);
					lastDate = dateStr;
					lastSender = '';
				}
				const own = m.sender_id === ME;
				const timeStr = d.toLocaleTimeString([], {
					hour: '2-digit',
					minute: '2-digit'
				});
				const isNew = !initial;

				if (m.sender_id !== lastSender) {
					// new group
					const grp = document.createElement('div');
					grp.className = 'msg-group' + (own ? ' own' : '');
					if (!own) {
						const av = document.createElement('div');
						av.className = `msg-avatar color-${colorFor(m.sender_id)}`;
						av.textContent = m.sender_name ? m.sender_name[0].toUpperCase() : '?';
						grp.appendChild(av);
					}
					const msgs_wrap = document.createElement('div');
					msgs_wrap.className = 'messages';
					grp.appendChild(msgs_wrap);
					body.appendChild(grp);
					lastSenderEl = msgs_wrap;
					lastSender = m.sender_id;
				}
				const bubble = document.createElement('div');
				bubble.className = 'bubble' + (isNew ? ' bubble-new' : '');
				bubble.innerHTML = `${escHtml(m.body)}<span class="bubble-time">${timeStr}${own ? ` <span class="ticks ${m.is_read ? 'read' : 'sent'}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="4 12 8 16 20 7"/><polyline points="9 12 13 16 20 7" opacity="0.6"/></svg></span>` : ''}</span>`;
				lastSenderEl.appendChild(bubble);
			});
			if (initial || isNearBottom()) scrollToBottom(false);
		}

		function escHtml(s) {
			return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>');
		}

		// ── SEND MESSAGE ──────────────────────────────────────────────────────────
		function sendMessage() {
			const txt = document.getElementById('compose').value.trim();
			if (!txt || !currentChat) return;
			stopTypingOnSend();
			document.getElementById('compose').value = '';
			document.getElementById('compose').style.height = '';
			const fd = new FormData();
			fd.append('action', 'send');
			fd.append('to', currentChat);
			fd.append('body', txt);
			fetch('api.php', {
					method: 'POST',
					body: fd
				})
				.then(r => r.json())
				.then(data => {
					if (data.success) {
						fetchMessages(false);
						refreshSidebar();
					}
				});
		}

		function handleKey(e) {
			if (e.key === 'Enter' && !e.shiftKey) {
				e.preventDefault();
				sendMessage();
			}
		}

		// ── TYPING STATUS ─────────────────────────────────────────────────────────
		let typingTimeout = null;
		let isCurrentlyTyping = false;

		function onTypingInput() {
			if (!currentChat) return;
			const txt = document.getElementById('compose').value;

			if (txt.length > 0) {
				// Send typing_start only if not already flagged
				if (!isCurrentlyTyping) {
					isCurrentlyTyping = true;
					sendTyping('typing_start');
				}
				// Reset the stop-typing debounce (3s after last keystroke)
				clearTimeout(typingTimeout);
				typingTimeout = setTimeout(() => {
					isCurrentlyTyping = false;
					sendTyping('typing_stop');
				}, 3000);
			} else {
				// Input cleared — stop immediately
				clearTimeout(typingTimeout);
				if (isCurrentlyTyping) {
					isCurrentlyTyping = false;
					sendTyping('typing_stop');
				}
			}
		}

		function sendTyping(action) {
			if (!currentChat) return;
			const fd = new FormData();
			fd.append('to', currentChat);
			fetch(`check_user.php?action=${action}`, {
				method: 'POST',
				body: fd
			}).catch(() => {});
		}

		function stopTypingOnSend() {
			clearTimeout(typingTimeout);
			if (isCurrentlyTyping) {
				isCurrentlyTyping = false;
				sendTyping('typing_stop');
			}
		}

		// ── UPDATE CHAT HEADER STATUS (online / typing / last seen) ───────────────
		function updateChatSubStatus(status, isTyping) {
			const sub = document.getElementById('chatSub');
			if (isTyping) {
				sub.innerHTML = '<span style="color:var(--g);font-style:normal;">typing...</span>';
			} else if (status === 'online') {
				sub.textContent = 'online';
			} else {
				// Fetch proper last_seen text
				fetch(`check_user.php?action=check&phone=${encodeURIComponent(currentChat)}&viewer=${encodeURIComponent(ME)}`)
					.then(r => r.json())
					.then(d => {
						if (d.last_seen) sub.textContent = d.last_seen;
					})
					.catch(() => {
						sub.textContent = 'last seen recently';
					});
			}
		}

		function autoResize(el) {
			el.style.height = 'auto';
			el.style.height = Math.min(el.scrollHeight, 120) + 'px';
		}

		// ── SIDEBAR HELPERS ───────────────────────────────────────────────────────
		function addTileToSidebar(phone, name, lastMsg, time) {
			const list = document.getElementById('chatList');
			const noChats = list.querySelector('.no-chats');
			if (noChats) noChats.remove();
			const cn = colorFor(phone);
			const tile = document.createElement('div');
			tile.className = 'chat-tile';
			tile.dataset.phone = phone;
			tile.dataset.name = name;
			tile.onclick = () => openChat(phone, name);
			tile.innerHTML = `
      <div class="avatar-sm color-${cn}"><div class="avatar-sm-init">${name[0].toUpperCase()}</div></div>
      <div class="tile-info">
        <div class="tile-top"><span class="tile-name">${escHtml(name)}</span><span class="tile-time"></span></div>
        <div class="tile-bottom"><span class="tile-preview"></span></div>
      </div>`;
			list.prepend(tile);
		}

		function updateSidebarTile(phone, name, lastMsg, time) {
			let tile = document.querySelector(`.chat-tile[data-phone="${phone}"]`);
			if (!tile) {
				addTileToSidebar(phone, name, lastMsg, time);
				tile = document.querySelector(`.chat-tile[data-phone="${phone}"]`);
			}
			tile.querySelector('.tile-time').textContent = fmtTime(time);
			const preview = lastMsg.length > 35 ? lastMsg.slice(0, 35) + '...' : lastMsg;
			tile.querySelector('.tile-preview').textContent = preview;
			// move to top
			tile.parentNode.prepend(tile);
		}

		function refreshSidebar() {
			fetch('api.php?action=chat_list')
				.then(r => r.json())
				.then(data => {
					if (!data.chats) return;
					data.chats.forEach(c => {
						if (c.phone !== currentChat) {
							let tile = document.querySelector(`.chat-tile[data-phone="${c.phone}"]`);
							if (!tile) addTileToSidebar(c.phone, c.name, c.last_msg, c.last_time);
							updateSidebarTile(c.phone, c.name, c.last_msg, c.last_time);
							// show unread badge
							if (c.unread > 0 && c.phone !== currentChat) {
								tile = document.querySelector(`.chat-tile[data-phone="${c.phone}"]`);
								if (tile && !tile.querySelector('.unread-badge')) {
									const b = document.createElement('span');
									b.className = 'unread-badge';
									b.textContent = c.unread;
									tile.querySelector('.tile-bottom').appendChild(b);
								}
							}
						}
					});
				});
		}

		// ── SCROLL ────────────────────────────────────────────────────────────────
		function scrollToBottom(smooth) {
			const b = document.getElementById('chatBody');
			b.scrollTo({
				top: b.scrollHeight,
				behavior: smooth ? 'smooth' : 'auto'
			});
			document.getElementById('scrollBtn').style.display = 'none';
		}

		function isNearBottom() {
			const b = document.getElementById('chatBody');
			return b.scrollHeight - b.scrollTop - b.clientHeight < 120;
		}
		document.getElementById('chatBody').addEventListener('scroll', () => {
			document.getElementById('scrollBtn').style.display = isNearBottom() ? 'none' : 'flex';
		});

		// ── CONTACT PROFILE ───────────────────────────────────────────────────────
		function toggleProfile() {
			const panel = document.getElementById('profilePanel');
			if (panel.classList.contains('open')) {
				panel.classList.remove('open');
				return;
			}
			if (!currentChat) return;
			document.getElementById('profilePanelTitle').textContent = 'Contact Info';
			document.getElementById('profileName').textContent = document.getElementById('chatTitle').textContent;
			document.getElementById('profilePhone').textContent = currentChat;
			document.getElementById('profileBigAvatar').textContent = document.getElementById('chatTitle').textContent[0].toUpperCase();
			document.getElementById('profileBigAvatar').className = `profile-avatar-big color-${colorFor(currentChat)}`;
			// fetch contact status
			fetch(`api.php?action=user_info&phone=${encodeURIComponent(currentChat)}`)
				.then(r => r.json())
				.then(d => {
					if (d.status) {
						document.getElementById('profileStatus').textContent = d.status;
						document.getElementById('profileStatusDot').className = `status-dot ${d.status}`;
					}
					if (d.bio) document.getElementById('profileBio').textContent = d.bio || 'Hey there! I am using WhatsApp.';
				});
			panel.classList.add('open');
		}

		function closeProfile() {
			document.getElementById('profilePanel').classList.remove('open');
		}

		// ── MY PROFILE ────────────────────────────────────────────────────────────
		function openProfile() {
			document.getElementById('myProfileOverlay').classList.add('open');
		}

		function closeMyProfile(e) {
			if (!e || e.target === document.getElementById('myProfileOverlay'))
				document.getElementById('myProfileOverlay').classList.remove('open');
		}

		// ── NEW CHAT MODAL ────────────────────────────────────────────────────────
		function openNewChat() {
			document.getElementById('newChatModal').classList.add('open');
			document.getElementById('contactSearch').focus();
		}

		function closeNewChat(e) {
			if (!e || e.target === document.getElementById('newChatModal'))
				document.getElementById('newChatModal').classList.remove('open');
		}

		function filterContacts(q) {
			q = q.toLowerCase();
			document.querySelectorAll('#contactList .modal-user').forEach(u => {
				u.style.display = u.dataset.name.toLowerCase().includes(q) ? '' : 'none';
			});
		}

		function filterChats(q) {
			q = q.toLowerCase();
			document.querySelectorAll('#chatList .chat-tile').forEach(t => {
				t.style.display = (t.dataset.name || '').toLowerCase().includes(q) ? '' : 'none';
			});
		}

		// ── HEARTBEAT: keep current user "online" (ping every 10s) ──────────────
		function sendHeartbeat() {
			fetch('check_user.php?action=heartbeat', {
				method: 'POST'
			}).catch(() => {});
		}
		sendHeartbeat(); // immediate on load
		setInterval(sendHeartbeat, 10000);

		// ── SET OFFLINE on tab close / navigation away ────────────────────────────
		window.addEventListener('beforeunload', () => {
			navigator.sendBeacon('check_user.php?action=set_offline');
			navigator.sendBeacon('check_user.php?action=typing_stop');
		});

		// ── SIDEBAR POLLING (for new messages from others) ────────────────────────
		sidebarPollInterval = setInterval(refreshSidebar, 4000);

		// ── HANDLE PAGE VISIBILITY (pause/resume polling) ────────────────────────
		document.addEventListener('visibilitychange', () => {
			if (document.hidden) {
				clearInterval(pollInterval);
				if (isCurrentlyTyping) {
					isCurrentlyTyping = false;
					sendTyping('typing_stop');
				}
			} else if (currentChat) {
				pollInterval = setInterval(() => fetchMessages(false), 1500);
			}
		});

		// ── MOBILE NAV ────────────────────────────────────────────────────────────
		function isMobile() {
			return window.innerWidth <= 768;
		}

		function showChatOnMobile() {
			if (!isMobile()) return;
			document.querySelector('.sidebar').classList.add('slide-out');
			document.getElementById('main').classList.add('slide-in');
		}

		function goBackToList() {
			if (!isMobile()) return;
			document.querySelector('.sidebar').classList.remove('slide-out');
			document.getElementById('main').classList.remove('slide-in');
			clearInterval(pollInterval);
			currentChat = null;
		}

		// Patch openChat to trigger mobile slide
		const _origOpenChat = openChat;
		openChat = function(phone, name) {
			_origOpenChat(phone, name);
			showChatOnMobile();
		};

		// Touch swipe-back gesture on chat body
		let touchStartX = 0;
		document.getElementById('chatBody').addEventListener('touchstart', e => {
			touchStartX = e.touches[0].clientX;
		}, {
			passive: true
		});
		document.getElementById('chatBody').addEventListener('touchend', e => {
			const dx = e.changedTouches[0].clientX - touchStartX;
			if (dx > 80 && isMobile()) goBackToList();
		}, {
			passive: true
		});

		// Handle browser back button on mobile
		window.addEventListener('popstate', () => {
			if (isMobile() && currentChat) goBackToList();
		});
		document.querySelector('.sidebar').addEventListener('touchstart', e => {}, {
			passive: true
		});
	</script>
</body>

</html>

<?php
function formatTime($ts)
{
	if (!$ts) return '';
	$d = new DateTime($ts);
	$now = new DateTime();
	$diff = $now->getTimestamp() - $d->getTimestamp();
	if ($diff < 86400 && $d->format('d') === $now->format('d')) return $d->format('g:i A');
	if ($diff < 604800) return $d->format('D');
	return $d->format('d/m/y');
}
?>