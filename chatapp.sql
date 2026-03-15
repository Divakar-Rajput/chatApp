-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 15, 2026 at 05:07 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `chatapp`
--

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `id` bigint(20) NOT NULL,
  `sender_id` varchar(20) NOT NULL,
  `receiver_id` varchar(20) NOT NULL,
  `body` text NOT NULL,
  `type` enum('text','emoji','image') NOT NULL DEFAULT 'text',
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `messages`
--

INSERT INTO `messages` (`id`, `sender_id`, `receiver_id`, `body`, `type`, `is_read`, `created_at`) VALUES
(13, '0000000001', '0000000002', 'hello sir', 'text', 1, '2026-03-15 21:31:57'),
(14, '0000000002', '0000000001', 'hii sir', 'text', 1, '2026-03-15 21:32:08'),
(15, '0000000001', '0000000002', 'how are you sir?', 'text', 1, '2026-03-15 21:32:22'),
(16, '0000000002', '0000000001', 'i am fine sir and also', 'text', 1, '2026-03-15 21:32:37'),
(17, '0000000001', '0000000002', 'i am also fine', 'text', 1, '2026-03-15 21:32:54'),
(18, '0000000001', '0000000002', 'this is a real time chat app', 'text', 1, '2026-03-15 21:33:08'),
(19, '0000000002', '0000000001', 'yes it it made by divakar rajput', 'text', 1, '2026-03-15 21:33:28'),
(20, '0000000001', '0000000002', 'http://localhost/chat/index.php', 'text', 1, '2026-03-15 21:34:24');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `phone` varchar(20) NOT NULL,
  `name` varchar(80) NOT NULL,
  `password` varchar(255) NOT NULL,
  `avatar` varchar(10) NOT NULL DEFAULT '?',
  `bio` varchar(150) DEFAULT NULL,
  `status` enum('online','offline','away') NOT NULL DEFAULT 'offline',
  `last_seen` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `typing_to` varchar(20) DEFAULT NULL,
  `typing_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`phone`, `name`, `password`, `avatar`, `bio`, `status`, `last_seen`, `created_at`, `typing_to`, `typing_at`) VALUES
('0000000001', 'Andry', '$2y$10$3f9bxV9W07SWHEWvDyeWJ.GMJ6TyYNBsElhaHiiA63FxuGm6ZQzia', '?', NULL, 'online', '2026-03-15 21:37:06', '2026-03-15 20:41:00', NULL, NULL),
('0000000002', 'Dev', '$2y$10$8AHVyg20z836s8J4z49JUeX8HD4G/rybSXzD3/pT9mULDdanxXmrW', '?', NULL, 'offline', '2026-03-15 21:36:36', '2026-03-15 20:33:53', NULL, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_convo` (`sender_id`,`receiver_id`,`created_at`),
  ADD KEY `idx_receiver_unread` (`receiver_id`,`is_read`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`phone`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `messages`
--
ALTER TABLE `messages`
  ADD CONSTRAINT `fk_msg_receiver` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`phone`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_msg_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`phone`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
