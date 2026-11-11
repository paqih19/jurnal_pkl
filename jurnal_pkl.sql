-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 11, 2026 at 06:53 AM
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
-- Database: `jurnal_pkl`
--

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `status` enum('hadir','izin','sakit','alpha') NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `check_in` time DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `location_text` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_members`
--

CREATE TABLE `chat_members` (
  `room_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `joined_at` datetime DEFAULT current_timestamp(),
  `last_read_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `chat_members`
--

INSERT INTO `chat_members` (`room_id`, `user_id`, `joined_at`, `last_read_at`) VALUES
(1, 1, '2026-11-11 11:24:37', NULL),
(1, 2, '2026-11-11 11:24:37', NULL),
(1, 3, '2026-11-11 11:24:37', '2026-11-11 11:24:41'),
(1, 4, '2026-11-11 11:24:37', NULL),
(1, 5, '2026-11-11 11:24:37', NULL),
(1, 6, '2026-11-11 11:24:37', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `chat_rooms`
--

CREATE TABLE `chat_rooms` (
  `id` int(11) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `type` enum('group','private','public') NOT NULL DEFAULT 'group',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `chat_rooms`
--

INSERT INTO `chat_rooms` (`id`, `name`, `type`, `created_by`, `created_at`) VALUES
(1, 'Chat Kelompok PKL', 'public', NULL, '2026-11-11 11:18:21');

-- --------------------------------------------------------

--
-- Table structure for table `journals`
--

CREATE TABLE `journals` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `content` text DEFAULT NULL,
  `activity_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `teacher_comment` text DEFAULT NULL,
  `commented_by` int(11) DEFAULT NULL,
  `commented_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `journals`
--

INSERT INTO `journals` (`id`, `user_id`, `title`, `content`, `activity_date`, `created_at`, `teacher_comment`, `commented_by`, `commented_at`) VALUES
(3, 1, 'Pengecekan Server', 'adalahapokoknya', '2026-11-09', '2026-11-09 01:57:23', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `journal_photos`
--

CREATE TABLE `journal_photos` (
  `id` int(11) NOT NULL,
  `journal_id` int(11) NOT NULL,
  `filename` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `journal_photos`
--

INSERT INTO `journal_photos` (`id`, `journal_id`, `filename`) VALUES
(13, 3, 'jurnal_3_1794189443_0.png'),
(14, 3, 'jurnal_3_1794189444_1.jpg'),
(15, 3, 'jurnal_3_1794189444_2.jpg'),
(16, 3, 'jurnal_3_1794189444_3.jpg'),
(17, 3, 'jurnal_3_1794189444_4.jpg'),
(18, 3, 'jurnal_3_1794189444_5.jpg'),
(19, 3, 'jurnal_3_1794189444_6.jpg'),
(20, 3, 'jurnal_3_1794189444_7.jpg'),
(21, 3, 'jurnal_3_1794189444_8.jpg'),
(22, 3, 'jurnal_3_1794189444_9.jpg'),
(23, 3, 'jurnal_3_1794189444_10.jpg');

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `content` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_read` tinyint(1) DEFAULT 0,
  `reply_to_id` int(11) DEFAULT NULL,
  `edited_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `is_pinned` tinyint(1) DEFAULT 0,
  `attachment` varchar(255) DEFAULT NULL,
  `attachment_type` varchar(20) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `duration` int(11) DEFAULT NULL,
  `room_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `messages_backup`
--

CREATE TABLE `messages_backup` (
  `id` int(11) NOT NULL DEFAULT 0,
  `user_id` int(11) NOT NULL,
  `content` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_read` tinyint(1) DEFAULT 0,
  `reply_to_id` int(11) DEFAULT NULL,
  `edited_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `is_pinned` tinyint(1) DEFAULT 0,
  `attachment` varchar(255) DEFAULT NULL,
  `attachment_type` varchar(20) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `duration` int(11) DEFAULT NULL,
  `room_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `messages_backup`
--

INSERT INTO `messages_backup` (`id`, `user_id`, `content`, `created_at`, `is_read`, `reply_to_id`, `edited_at`, `deleted_at`, `is_pinned`, `attachment`, `attachment_type`, `file_name`, `file_size`, `duration`, `room_id`) VALUES
(1, 2, 'haiii', '2026-11-07 01:53:20', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 1),
(2, 2, 'haii', '2026-11-07 01:53:33', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 1),
(3, 1, 'haiih', '2026-11-07 01:53:54', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 1),
(4, 1, 'asyesyehh', '2026-11-07 05:51:11', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 1),
(5, 3, 'JOSJISSSSSSS', '2026-11-07 05:55:15', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 1),
(6, 1, 'oyy', '2026-11-09 01:58:02', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 1),
(7, 1, 'asyesyehh', '2026-11-09 01:58:09', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 1),
(8, 3, 'hihhu', '2026-11-09 01:58:36', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 1),
(9, 4, 'gredaggreduGG', '2026-11-09 01:59:08', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 1),
(10, 5, 'ekhemm', '2026-11-09 01:59:57', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 1),
(11, 1, 'wallawwee', '2026-11-09 02:15:05', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 1),
(12, 3, 'farellll', '2026-11-09 02:45:23', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 1),
(13, 3, 'relll', '2026-11-09 02:46:16', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 1),
(14, 3, 'ppp', '2026-11-09 03:05:21', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 1),
(15, 3, 'asap', '2026-11-09 03:22:21', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 1),
(16, 1, 'p', '2026-11-11 03:34:13', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 1),
(17, 3, 'kulannn', '2026-11-11 03:34:24', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 1),
(18, 1, '🔗', '2026-11-11 03:34:57', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 1),
(19, 3, 'p', '2026-11-11 03:40:37', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 1),
(20, 3, 'pppp', '2026-11-11 03:45:11', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 1);

-- --------------------------------------------------------

--
-- Table structure for table `message_reactions`
--

CREATE TABLE `message_reactions` (
  `id` int(11) NOT NULL,
  `message_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `emoji` varchar(10) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `message_reads`
--

CREATE TABLE `message_reads` (
  `message_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `read_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `message_stars`
--

CREATE TABLE `message_stars` (
  `message_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tasks`
--

CREATE TABLE `tasks` (
  `id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `teacher_name` varchar(100) DEFAULT NULL,
  `deadline` date DEFAULT NULL,
  `status` enum('todo','doing','done') DEFAULT 'todo',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `assigned_to` int(11) DEFAULT NULL,
  `created_by_role` varchar(20) DEFAULT 'student'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('member','teacher') DEFAULT 'member',
  `photo` varchar(255) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `jurusan` varchar(100) DEFAULT NULL,
  `sekolah` varchar(150) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `username`, `email`, `password`, `role`, `photo`, `bio`, `jurusan`, `sekolah`, `created_at`) VALUES
(1, 'Farrel Imaduddin Al Ashari', 'farrel', 'farrel', '$2y$10$FMQRdH0ecCbZZVWtN2n7/u1YZN/gr7X98Er4NG4sqDAWCXlZwmB6S', 'member', 'user_1_1794017614.jpg', '#EPEPDIHATISAMPAIMATI', 'RPL', 'SMKS ALMAMUN SUMEDANG', '2026-11-04 06:19:21'),
(2, 'Zahran Fauzan ZamZami', 'zahran', 'zahran', '$2y$10$FMQRdH0ecCbZZVWtN2n7/u1YZN/gr7X98Er4NG4sqDAWCXlZwmB6S', 'member', 'user_2_1794190331.jpg', 'Adalahpokoknya', 'RPL', 'SMKS AL MAMUN SUMEDANG', '2026-11-04 06:19:21'),
(3, 'Saif Paqihul Kamal', 'saif', 'saif', '$2y$10$FMQRdH0ecCbZZVWtN2n7/u1YZN/gr7X98Er4NG4sqDAWCXlZwmB6S', 'member', 'user_3_1794190359.jpg', 'PaqihGanteng', 'RPL', 'SMKS AL MAMUN SUMEDANG', '2026-11-04 06:19:21'),
(4, 'Lupi Maulana', 'lupi', 'lupi', '$2y$10$FMQRdH0ecCbZZVWtN2n7/u1YZN/gr7X98Er4NG4sqDAWCXlZwmB6S', 'member', 'user_4_1794190390.jpg', 'GredaGGreduG Is Priority', 'RPL', 'SMKS AL MAMUN SUMEDANG', '2026-11-04 06:19:21'),
(5, 'Hamzah Alafi Iskandar', 'hamzah', 'hamzah', '$2y$10$FMQRdH0ecCbZZVWtN2n7/u1YZN/gr7X98Er4NG4sqDAWCXlZwmB6S', 'member', 'user_5_1794018069.jpg', 'PencintaSholawatAbizz', 'RPL', 'SMKS AL MAMUN SUMEDANG', '2026-11-04 06:19:21'),
(6, 'Fauzi Abdul Aziz Basyri', 'guru', 'guru', '$2y$10$FMQRdH0ecCbZZVWtN2n7/u1YZN/gr7X98Er4NG4sqDAWCXlZwmB6S', 'teacher', 'user_6_1794018772.jpg', 'Asyesyehh', '-', 'SMKS ALMAMUN SUMEDANG', '2026-11-04 06:19:21');

-- --------------------------------------------------------

--
-- Table structure for table `user_activity`
--

CREATE TABLE `user_activity` (
  `user_id` int(11) NOT NULL,
  `last_seen` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_activity`
--

INSERT INTO `user_activity` (`user_id`, `last_seen`) VALUES
(1, '2026-11-11 10:51:55'),
(3, '2026-11-11 11:24:41');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_user_date` (`user_id`,`date`);

--
-- Indexes for table `chat_members`
--
ALTER TABLE `chat_members`
  ADD PRIMARY KEY (`room_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `chat_rooms`
--
ALTER TABLE `chat_rooms`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `journals`
--
ALTER TABLE `journals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `journal_photos`
--
ALTER TABLE `journal_photos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `journal_id` (`journal_id`);

--
-- Indexes for table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `message_reactions`
--
ALTER TABLE `message_reactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_react` (`message_id`,`user_id`,`emoji`);

--
-- Indexes for table `message_reads`
--
ALTER TABLE `message_reads`
  ADD PRIMARY KEY (`message_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `message_stars`
--
ALTER TABLE `message_stars`
  ADD PRIMARY KEY (`message_id`,`user_id`);

--
-- Indexes for table `tasks`
--
ALTER TABLE `tasks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `username_2` (`username`);

--
-- Indexes for table `user_activity`
--
ALTER TABLE `user_activity`
  ADD PRIMARY KEY (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `chat_rooms`
--
ALTER TABLE `chat_rooms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `journals`
--
ALTER TABLE `journals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `journal_photos`
--
ALTER TABLE `journal_photos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `message_reactions`
--
ALTER TABLE `message_reactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tasks`
--
ALTER TABLE `tasks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `chat_members`
--
ALTER TABLE `chat_members`
  ADD CONSTRAINT `chat_members_ibfk_1` FOREIGN KEY (`room_id`) REFERENCES `chat_rooms` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chat_members_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `journals`
--
ALTER TABLE `journals`
  ADD CONSTRAINT `journals_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `journal_photos`
--
ALTER TABLE `journal_photos`
  ADD CONSTRAINT `journal_photos_ibfk_1` FOREIGN KEY (`journal_id`) REFERENCES `journals` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `messages`
--
ALTER TABLE `messages`
  ADD CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `message_reactions`
--
ALTER TABLE `message_reactions`
  ADD CONSTRAINT `message_reactions_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `message_reads`
--
ALTER TABLE `message_reads`
  ADD CONSTRAINT `message_reads_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `message_reads_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `message_stars`
--
ALTER TABLE `message_stars`
  ADD CONSTRAINT `message_stars_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tasks`
--
ALTER TABLE `tasks`
  ADD CONSTRAINT `tasks_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_activity`
--
ALTER TABLE `user_activity`
  ADD CONSTRAINT `user_activity_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
