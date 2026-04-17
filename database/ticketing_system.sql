-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 06, 2026 at 01:33 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `ticketing_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `employee_tickets`
--

CREATE TABLE `employee_tickets` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `company` varchar(255) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `category` varchar(100) NOT NULL,
  `sub_category` varchar(255) DEFAULT NULL,
  `priority` enum('Low','Medium','High','Critical') NOT NULL,
  `department` enum('IT','HR','Marketing','Admin','Technical','Accounting','Supply Chain','MPDC','E-Comm') DEFAULT NULL,
  `assigned_company` varchar(255) DEFAULT NULL,
  `assigned_department` enum('IT','HR','Marketing','Admin','Technical','Accounting','Supply Chain','MPDC','E-Comm') NOT NULL,
  `description` text DEFAULT NULL,
  `admin_note` text DEFAULT NULL,
  `attachment` varchar(255) DEFAULT NULL,
  `status` enum('Open','In Progress','Resolved','Closed') DEFAULT 'Open',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `started_at` datetime DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `employee_update_unread` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `employee_tickets`
--

INSERT INTO `employee_tickets` (`id`, `user_id`, `company`, `subject`, `category`, `sub_category`, `priority`, `department`, `assigned_company`, `assigned_department`, `description`, `admin_note`, `attachment`, `status`, `created_at`, `updated_at`, `is_read`, `started_at`, `resolved_at`, `employee_update_unread`) VALUES
(30, 16, '', 'sadsad', 'Network Issue', NULL, 'Low', 'IT', NULL, '', 'dsadsa', NULL, NULL, 'Resolved', '2026-02-26 02:51:32', '2026-02-27 10:00:18', 1, '2026-02-27 09:05:34', '2026-02-27 10:00:18', 0),
(31, 14, '', 'aasdasd', 'Software Issue', NULL, 'Low', 'HR', NULL, '', 'sadasd', NULL, NULL, 'Resolved', '2026-02-26 03:25:19', '2026-02-27 10:39:06', 1, '2026-02-27 09:05:49', '2026-02-27 10:39:06', 0),
(32, 14, '', 'gumagana', 'Network Issue', NULL, 'Critical', 'HR', NULL, '', 'ganda', NULL, '1772076393_699fbd69ba9ba.docx', 'Resolved', '2026-02-26 03:26:33', '2026-02-27 12:43:20', 1, '2026-02-27 09:06:02', '2026-02-27 12:43:20', 0),
(33, 14, '', 'lesgoooo', 'Network Issue', NULL, 'Medium', 'HR', NULL, '', 'sadasdas', NULL, NULL, 'Resolved', '2026-02-26 03:33:27', '2026-02-27 10:41:10', 1, '2026-02-27 10:41:03', '2026-02-27 10:41:10', 0),
(34, 14, '', 'sdsad', 'Hardware Issue', NULL, 'Medium', 'HR', NULL, 'HR', NULL, NULL, NULL, 'Open', '2026-02-26 03:39:45', NULL, 0, NULL, NULL, 0),
(35, 14, '', 'sdsad', 'Hardware Issue', NULL, 'Medium', 'HR', NULL, '', NULL, NULL, NULL, '', '2026-02-26 04:01:57', '2026-02-27 10:40:41', 1, '2026-02-27 10:39:13', NULL, 0),
(36, 13, '', 'Cannot Sign In', 'Email Problem', NULL, 'Critical', 'IT', NULL, 'IT', 'I need to see the file in my email. ', NULL, '1772080803_699fcea32b82a.png', 'Open', '2026-02-26 04:40:03', NULL, 0, '2026-02-27 09:05:19', NULL, 0),
(37, 13, '', 'NO WIFI CONNECTION', 'Network Issue', NULL, 'Low', 'IT', NULL, '', 'Cannot connect to company Wifi. ', NULL, '1772081028_699fcf8409df3.png', '', '2026-02-26 04:43:48', '2026-02-27 10:38:12', 1, '2026-02-27 09:04:58', NULL, 0),
(38, 13, '', 'PLDT Problem', 'Network Issue', NULL, 'Medium', 'IT', NULL, '', 'It ssaur sloowwwww', NULL, '1772090160_699ff330ebb2e.png', 'In Progress', '2026-02-26 07:16:00', '2026-03-02 11:04:30', 1, '2026-02-27 09:03:46', '2026-02-27 09:04:32', 0),
(39, 20, '', 'sadsadas', 'Network Issue', NULL, 'Medium', '', NULL, 'IT', 'REQUESTER NAME: Matthew Pascua\nREQUESTER EMAIL: admin@gmail.com\n\nDESCRIPTION:\nasdasdsa', NULL, '1772178178_69a14b0297508.jpg', 'Open', '2026-02-27 07:42:58', NULL, 0, NULL, NULL, 0),
(40, 20, '', 'sadas', 'Network Issue', NULL, 'High', '', NULL, 'Technical', 'REQUESTER NAME: dasdsa\nREQUESTER EMAIL: matthewpascua22@gmail.com\n\nDESCRIPTION:\nsadasdsa', NULL, NULL, 'Open', '2026-02-27 07:44:57', NULL, 0, '2026-03-02 16:27:44', NULL, 0),
(41, 20, '', 'sadas', 'Network Issue', NULL, 'Critical', '', NULL, 'IT', 'REQUESTER NAME: dasdsa\nREQUESTER EMAIL: matthewpascua22@gmail.com\n\nDESCRIPTION:\nsdasd', NULL, NULL, 'Open', '2026-02-27 07:53:46', NULL, 0, '2026-03-02 16:27:42', NULL, 0),
(42, 20, '', 'sadsad', 'Account Access', NULL, 'High', '', NULL, 'IT', 'REQUESTER NAME: Matthew Pascua\nREQUESTER EMAIL: matthew@gmail.com\n\nDESCRIPTION:\nasdsadsa', NULL, NULL, 'Open', '2026-02-27 08:06:03', NULL, 0, '2026-03-02 09:06:35', NULL, 0),
(43, 26, '', 'mattthew ', 'Network Issue', NULL, 'Low', 'Marketing', NULL, 'IT', 'dsadasdas', NULL, NULL, 'Open', '2026-03-02 07:08:20', NULL, 0, '2026-03-02 16:27:38', NULL, 0),
(44, 26, '', 'Cannot stay connected', 'Network Issue', NULL, 'Medium', 'Marketing', NULL, '', 'the wire is broken. ', 'hello is it good?', '1772439128_69a5465866558.png', 'Resolved', '2026-03-02 08:12:08', '2026-03-03 09:29:04', 1, '2026-03-02 16:13:12', '2026-03-02 16:16:18', 0),
(45, 26, '', 'sadas', 'Network Issue', NULL, 'High', 'Marketing', NULL, '', 'sadasd', 'Is it good?', NULL, 'Open', '2026-03-02 08:57:22', '2026-03-03 09:27:01', 1, '2026-03-03 08:54:25', NULL, 0),
(46, 25, '', 'Cannot View', 'Email Problem', NULL, 'High', 'IT', NULL, 'IT', 'HHHHH', 'Hello ms kumain kana ba? ;)', NULL, 'In Progress', '2026-03-03 01:30:46', '2026-03-03 09:35:59', 1, '2026-03-03 09:35:37', NULL, 0),
(47, 26, '', 'notif', 'Hardware Issue', NULL, 'Medium', 'Marketing', NULL, 'Admin', 'sadsad', 'hellu', NULL, 'In Progress', '2026-03-03 04:45:07', '2026-03-03 12:46:01', 1, '2026-03-03 12:45:33', NULL, 0),
(48, 26, '', 'dsadsad', 'Hardware Issue', NULL, 'Medium', 'Marketing', NULL, 'Admin', 'dsadsad', NULL, NULL, 'Open', '2026-03-03 05:24:54', NULL, 0, '2026-03-03 13:25:22', NULL, 0),
(49, 20, 'Leads Animal Health - LAH', 'asdsa', 'Software Issue', NULL, 'Medium', '', NULL, '', 'REQUESTER NAME: Matthew Pascua\nREQUESTER EMAIL: enzocharlesgarcia.21@gmail.com\n\nDESCRIPTION:\nsadasd', NULL, NULL, 'Open', '2026-03-03 08:48:51', NULL, 0, NULL, NULL, 0),
(51, 26, '', 'Di ako maka log in ee', 'Software Issue', NULL, 'Critical', 'Marketing', NULL, '', 'Hindi nga ako maka login e', 'SIGI TIGNAN KO LATER.', '1772528582_69a6a3c6ace69.png', 'In Progress', '2026-03-03 09:03:02', '2026-03-03 17:06:16', 1, '2026-03-03 17:05:09', NULL, 0),
(52, 27, '', 'Email error 505', 'Email Problem', NULL, 'Critical', 'HR', NULL, 'IT', 'I need my email ASAP', 'Okay na ms ;)', '1772546817_69a6eb01203cf.png', '', '2026-03-03 14:06:57', '2026-03-04 15:38:45', 1, '2026-03-03 22:08:01', '2026-03-03 22:08:51', 0),
(53, 30, '', 'ffcfcf', 'Network Issue', NULL, 'Low', 'Marketing', NULL, 'Marketing', NULL, '', NULL, 'Open', '2026-03-04 05:12:35', '2026-03-04 13:17:48', 1, '2026-03-04 13:13:42', NULL, 0),
(54, 30, '', 'asdasd', 'Hardware Issue', NULL, 'High', 'E-Comm', NULL, 'Accounting', 'asdasd', '', NULL, '', '2026-03-04 05:32:32', '2026-03-04 15:39:16', 1, '2026-03-04 13:32:56', '2026-03-04 15:19:36', 0),
(55, 31, '', 'Keyboard is not working in spacebar. ', 'Hardware Issue', NULL, 'Critical', '', NULL, 'MPDC', 'Cannot do paper works. ', '', '1772610122_69a7e24a9eb55.png', 'Closed', '2026-03-04 07:42:02', '2026-03-04 17:09:26', 1, '2026-03-04 15:42:34', '2026-03-04 15:45:57', 0),
(56, 31, '', 'I cannot open folder', 'Network Issue', NULL, 'Critical', '', NULL, 'MPDC', 'I need to view the folder. ', '', '1772611426_69a7e762de655.png', 'Closed', '2026-03-04 08:03:46', '2026-03-04 17:09:09', 1, '2026-03-04 16:04:11', '2026-03-04 16:06:27', 0),
(57, 31, '', 'asdasd', 'Email Problem', NULL, 'Critical', '', NULL, 'Admin', 'asdasdsadsa', NULL, '1772615427_69a7f7034bb85.png', 'Open', '2026-03-04 09:10:27', NULL, 0, '2026-03-04 17:10:52', NULL, 0),
(58, 31, '', 'sdasd', 'Network Issue', NULL, 'Medium', 'Admin', NULL, 'Admin', 'asdasdsad', NULL, '1772671085_69a8d06deb22d.png', 'Open', '2026-03-05 00:38:05', NULL, 0, NULL, NULL, 0),
(59, 60, '', 'sad', 'Software Issue', NULL, 'Medium', 'HR', NULL, 'HR', 'descr', 'check it', NULL, 'Resolved', '2026-03-05 01:11:17', '2026-03-05 09:35:52', 1, '2026-03-05 09:11:31', '2026-03-05 09:35:52', 0),
(60, 62, '', 'LAN Problem', 'Network Issue', NULL, 'High', 'HR', NULL, 'IT', 'Lan Connection', NULL, '1772676151_69a8e437ba0d8.png', 'Open', '2026-03-05 02:02:31', NULL, 0, '2026-03-05 10:25:33', NULL, 0),
(61, 66, '', 'System Crash', 'Software Issue', NULL, 'Critical', 'IT', NULL, 'HR', 'System Bug', NULL, '1772676482_69a8e58234906.png', 'Open', '2026-03-05 02:08:02', NULL, 0, '2026-03-05 10:12:41', NULL, 0),
(62, 66, '', 'sad', 'Hardware Issue', NULL, 'Medium', 'IT', NULL, 'HR', 'dsadas', NULL, NULL, 'Open', '2026-03-05 02:36:09', NULL, 0, '2026-03-05 10:37:02', NULL, 0),
(63, 60, '', 'assigned', 'Software Issue', NULL, 'High', 'HR', NULL, 'IT', NULL, NULL, NULL, 'Open', '2026-03-05 02:37:31', NULL, 0, NULL, NULL, 0),
(64, 60, '', 'assigned', 'Network Issue', NULL, 'Critical', 'HR', NULL, 'IT', NULL, NULL, NULL, 'Open', '2026-03-05 02:38:30', NULL, 0, '2026-03-05 10:42:00', NULL, 0),
(65, 60, '', 'enzo', 'Email Problem', NULL, 'Low', 'HR', NULL, 'IT', 'matthew', '', NULL, 'In Progress', '2026-03-05 02:45:24', '2026-03-05 11:04:01', 1, '2026-03-05 10:46:26', NULL, 0),
(66, 60, '', 'chel', 'Software Issue', NULL, 'High', 'HR', NULL, 'Marketing', 'sdsad', '', NULL, 'Open', '2026-03-05 03:12:25', '2026-03-05 11:13:42', 1, '2026-03-05 11:13:23', NULL, 0),
(67, 60, '', 'LATEST', 'Software Issue', NULL, 'Critical', 'HR', 'Malveda Holdings Corporation', 'IT', 'LATEST', NULL, '1772682510_69a8fd0e3846c.png', 'Open', '2026-03-05 03:48:30', NULL, 0, NULL, NULL, 0),
(68, 60, '', 'asdasd', 'Hardware Issue', NULL, 'High', 'HR', 'FARMEX', 'IT', 'asdad', '', '1772682667_69a8fdab72100.png', 'Resolved', '2026-03-05 03:51:07', '2026-03-05 14:42:26', 1, '2026-03-05 11:51:50', '2026-03-05 14:42:26', 0),
(69, 60, '', 'asdas', 'Software Issue', NULL, 'High', 'HR', 'FARMEX', 'HR', 'asdasd', NULL, '1772683279_69a9000fc44ea.png', 'Open', '2026-03-05 04:01:19', NULL, 0, '2026-03-05 12:01:41', NULL, 0),
(70, 60, '', 'sdasd', 'Software Issue', NULL, 'High', 'HR', 'Malveda Holdings Corporation', 'IT', 'asdasd', NULL, '1772683336_69a900489a506.png', 'Open', '2026-03-05 04:02:16', NULL, 0, NULL, NULL, 0),
(71, 69, '', 'sad', 'Software Issue', NULL, 'High', 'HR', 'Malveda Holdings Corporation', 'HR', 'fafaf', NULL, NULL, 'Open', '2026-03-05 04:44:16', NULL, 0, NULL, NULL, 0),
(72, 69, '', 'sadsad', 'Software Issue', NULL, 'Medium', 'HR', 'Malveda Holdings Corporation', 'HR', 'xz', NULL, NULL, 'Open', '2026-03-05 04:45:29', NULL, 0, '2026-03-05 14:42:37', NULL, 0),
(73, 60, '', 'dsadad', 'Software Issue', NULL, 'Medium', 'HR', 'Malveda Holdings Corporation', 'HR', 'asdasd', '', NULL, 'Open', '2026-03-05 04:46:59', '2026-03-05 12:47:42', 1, '2026-03-05 12:47:13', NULL, 0),
(74, 63, '', 'sadasd', 'Hardware Issue', NULL, 'Medium', 'IT', 'Malveda Holdings Corporation', 'HR', 'asdasd', '', NULL, 'Open', '2026-03-05 04:48:55', '2026-03-05 12:49:42', 1, '2026-03-05 12:49:10', NULL, 0),
(75, 62, '', 'sadasd', 'Hardware Issue', NULL, 'Medium', 'HR', 'FARMEX', 'IT', 'asdas', '', NULL, 'In Progress', '2026-03-05 04:50:55', '2026-03-05 13:36:07', 1, '2026-03-05 12:51:20', '2026-03-05 12:57:18', 0),
(76, 60, '', 'try', 'Hardware Issue', NULL, 'Medium', 'HR', 'Malveda Holdings Corporation', 'Marketing', 'sdasd', '', NULL, 'Resolved', '2026-03-05 04:54:41', '2026-03-05 13:33:49', 1, '2026-03-05 12:55:10', '2026-03-05 12:56:53', 0),
(77, 71, 'FARMEX', 'sadasd', 'Hardware Issue', NULL, 'Medium', '', NULL, 'HR', 'REQUESTER NAME: Matthew Pascua\nREQUESTER EMAIL: enzocharlesgarcia.21@gmail.com\n\nDESCRIPTION:\nsadasd', NULL, NULL, 'Open', '2026-03-05 06:26:24', NULL, 0, NULL, NULL, 0),
(78, 62, '', 'sadsasssss', 'Software Issue', NULL, 'Medium', 'HR', 'FARMEX', 'IT', 'asdasdsa', '', NULL, 'Resolved', '2026-03-05 06:27:42', '2026-03-05 14:41:50', 1, '2026-03-05 14:28:02', '2026-03-05 14:41:50', 0),
(79, 62, '', 'sir deniel pogi', 'Hardware Issue', NULL, 'Medium', 'HR', 'FARMEX', 'HR', 'descption', '', '1772694190_69a92aae710f8.png', 'In Progress', '2026-03-05 07:03:10', '2026-03-05 15:05:11', 1, '2026-03-05 15:04:05', NULL, 0),
(80, 71, 'Malveda Holdings Corporation - MHC', 'asdasd', 'Software Issue', NULL, 'High', '', NULL, 'IT', 'REQUESTER NAME: Enzo Mendoza\nREQUESTER EMAIL: enzomendoza8teen@gmail.com\n\nDESCRIPTION:\nmalveda', NULL, '1772695429_69a92f8561d1b.png', 'Open', '2026-03-05 07:23:49', NULL, 0, NULL, NULL, 0),
(81, 71, 'FARMEX', 'asdasd', 'Hardware Issue', NULL, 'Medium', '', NULL, 'HR', 'REQUESTER NAME: Enzo mendoza\nREQUESTER EMAIL: enzomendoza8teen@gmail.com\n\nDESCRIPTION:\nfarmex', NULL, NULL, 'Open', '2026-03-05 07:24:30', NULL, 0, NULL, NULL, 0);

-- --------------------------------------------------------

--
-- Table structure for table `knowledge_base`
--

CREATE TABLE `knowledge_base` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `category` varchar(100) NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `views` int(11) DEFAULT 0,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `knowledge_base`
--

INSERT INTO `knowledge_base` (`id`, `title`, `content`, `image_path`, `category`, `created_by`, `created_at`, `views`, `updated_at`) VALUES
(2, 'Resident Evil: Requiem', 'Leon is dead', NULL, 'Network', NULL, '2026-03-02 03:29:37', 3, '2026-03-05 01:08:58'),
(3, 'Printer Not Printing – Troubleshooting Guide', 'If your office printer is not printing, follow these steps:\r\n\r\nSTEP 1: Check Printer Status\r\n- Make sure the printer is powered ON.\r\n- Check if there is paper loaded.\r\n- Check ink or toner levels.\r\n\r\nSTEP 2: Check Connection\r\n- Ensure USB cable is properly connected.\r\n- If network printer, make sure Wi-Fi or LAN is connected.\r\n\r\nSTEP 3: Restart Devices\r\n- Restart your computer.\r\n- Restart the printer.\r\n\r\nSTEP 4: Clear Print Queue\r\n- Open Control Panel > Devices & Printers\r\n- Open printer queue\r\n- Cancel all pending documents\r\n\r\nIf issue persists:\r\nSubmit a Helpdesk Ticket under:\r\nCategory: Hardware\r\nPriority: Medium', NULL, 'Hardware Troubleshooting', NULL, '2026-03-02 06:02:46', 1, '2026-03-05 01:08:55'),
(4, 'Cannot Connect to Company WiFi', 'If you cannot connect to the company WiFi:\r\n\r\n1. Ensure Airplane Mode is OFF.\r\n2. Select the correct network:\r\n   - LEADS-STAFF\r\n   - LEADS-WAREHOUSE\r\n3. Enter the correct WiFi password.\r\n4. Restart your device.\r\n\r\nFor Warehouse & Farm areas:\r\n- Signal may be weaker.\r\n- Try moving closer to access point.\r\n\r\nIf still not working:\r\nSubmit a ticket with:\r\n- Your location (Office / Farm / Warehouse)\r\n- Device type\r\n- Screenshot of error message', NULL, 'Network', NULL, '2026-03-02 06:03:43', 6, '2026-03-05 07:14:06'),
(5, 'Email Not Receiving Messages', 'If you are not receiving emails:\r\n\r\n1. Check Spam or Junk folder.\r\n2. Make sure mailbox is not full.\r\n3. Confirm sender used correct email address.\r\n4. Refresh your email application.\r\n\r\nIf using Outlook:\r\n- Click Send/Receive\r\n- Restart Outlook\r\n\r\nIf using Webmail:\r\n- Log out and log back in\r\n\r\nIf problem continues:\r\nSubmit a ticket under Email category.\r\nInclude:\r\n- Screenshot of issue\r\n- Sender email address\r\n- Date and time of missing email', NULL, 'Software Guides', NULL, '2026-03-02 06:04:33', 6, '2026-03-05 01:08:46'),
(6, 'How to Submit a Proper Helpdesk Ticket', 'To help IT resolve your issue faster, include:\r\n\r\n1. Clear Subject\r\nExample: \"Cannot Print HR Documents\"\r\n\r\n2. Detailed Description\r\n- What happened?\r\n- When did it start?\r\n- Is it affecting others?\r\n\r\n3. Attach Screenshot (if possible)\r\n\r\n4. Select Correct Category:\r\n- Network\r\n- Hardware\r\n- Email\r\n- Software\r\n- System\r\n\r\n5. Set Proper Priority:\r\n- Low: Minor inconvenience\r\n- Medium: Work affected\r\n- High: Department stopped\r\n- Critical: Entire system down', NULL, 'Technical Support', NULL, '2026-03-02 06:05:05', 5, '2026-03-05 07:02:05');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `type` varchar(50) NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `ticket_id`, `message`, `type`, `is_read`, `created_at`) VALUES
(1, 26, 47, 'Your ticket #47 status was updated to In Progress.', 'status_update', 1, '2026-03-03 04:46:01'),
(2, 26, 47, 'Your ticket #47 was reassigned to Admin.', 'reassigned', 1, '2026-03-03 04:46:01'),
(3, 26, 47, 'Admin added a note to ticket #47: \'hellu\'', 'note_added', 1, '2026-03-03 04:46:02'),
(4, 22, 48, 'New Medium priority ticket #000048 from Enzo Mendoza - Marketing', 'new_ticket', 0, '2026-03-03 05:24:54'),
(5, 23, 48, 'New Medium priority ticket #000048 from Enzo Mendoza - Marketing', 'new_ticket', 0, '2026-03-03 05:24:54'),
(6, 24, 48, 'New Medium priority ticket #000048 from Enzo Mendoza - Marketing', 'new_ticket', 1, '2026-03-03 05:24:54'),
(7, 22, 51, 'New Critical priority ticket #000051 from Enzo Mendoza - Marketing', 'new_ticket', 0, '2026-03-03 09:03:02'),
(8, 23, 51, 'New Critical priority ticket #000051 from Enzo Mendoza - Marketing', 'new_ticket', 1, '2026-03-03 09:03:02'),
(9, 24, 51, 'New Critical priority ticket #000051 from Enzo Mendoza - Marketing', 'new_ticket', 1, '2026-03-03 09:03:02'),
(10, 26, 51, 'Your ticket #51 status was updated to In Progress.', 'status_update', 1, '2026-03-03 09:05:45'),
(11, 26, 51, 'Your ticket #51 was reassigned to IT.', 'reassigned', 1, '2026-03-03 09:05:45'),
(12, 26, 51, 'Your ticket #51 was reassigned to .', 'reassigned', 1, '2026-03-03 09:06:16'),
(13, 26, 51, 'Admin added a note to ticket #51: \'SIGI TIGNAN KO LATER.\'', 'note_added', 1, '2026-03-03 09:06:16'),
(14, 22, 52, 'New Critical priority ticket #000052 from Iloiza Dominguez - HR', 'new_ticket', 0, '2026-03-03 14:06:57'),
(15, 23, 52, 'New Critical priority ticket #000052 from Iloiza Dominguez - HR', 'new_ticket', 1, '2026-03-03 14:06:57'),
(16, 24, 52, 'New Critical priority ticket #000052 from Iloiza Dominguez - HR', 'new_ticket', 1, '2026-03-03 14:06:57'),
(17, 27, 52, 'Your ticket #52 has been closed.', 'ticket_closed', 1, '2026-03-03 14:08:51'),
(18, 27, 52, 'Admin added a note to ticket #52: \'Okay na ms ;)\'', 'note_added', 1, '2026-03-03 14:08:51'),
(19, 23, 53, 'New Low priority ticket #000053 from Matthew Pascua - Marketing', 'new_ticket', 0, '2026-03-04 05:12:35'),
(20, 24, 53, 'New Low priority ticket #000053 from Matthew Pascua - Marketing', 'new_ticket', 1, '2026-03-04 05:12:35'),
(21, 30, 53, 'Your ticket #53 was reassigned to Marketing.', 'reassigned', 1, '2026-03-04 05:17:45'),
(22, 23, 54, 'New High priority ticket #000054 from Matthew Pascua - E-Comm', 'new_ticket', 0, '2026-03-04 05:32:32'),
(23, 24, 54, 'New High priority ticket #000054 from Matthew Pascua - E-Comm', 'new_ticket', 0, '2026-03-04 05:32:32'),
(24, 30, 54, 'Your ticket #54 was reassigned to Admin.', 'reassigned', 1, '2026-03-04 05:33:05'),
(25, 30, 54, 'Your ticket #54 status was updated to In Progress.', 'status_update', 0, '2026-03-04 07:08:28'),
(26, 30, 54, 'Your ticket #54 was reassigned to IT.', 'reassigned', 0, '2026-03-04 07:08:28'),
(27, 30, 54, 'Your ticket #54 has been closed.', 'ticket_closed', 0, '2026-03-04 07:08:55'),
(28, 30, 54, 'Your ticket #54 has been closed.', 'ticket_closed', 0, '2026-03-04 07:09:36'),
(29, 30, 54, 'Your ticket #54 has been closed.', 'ticket_closed', 0, '2026-03-04 07:12:04'),
(30, 30, 54, 'Your ticket #54 has been closed.', 'ticket_closed', 0, '2026-03-04 07:18:59'),
(31, 30, 54, 'Your ticket #54 status was updated to In Progress.', 'status_update', 0, '2026-03-04 07:19:28'),
(32, 30, 54, 'Your ticket #54 has been closed.', 'ticket_closed', 0, '2026-03-04 07:19:36'),
(33, 30, 54, 'Your ticket #54 has been closed.', 'ticket_closed', 0, '2026-03-04 07:19:51'),
(34, 30, 54, 'Your ticket #54 has been closed.', 'ticket_closed', 0, '2026-03-04 07:38:14'),
(35, 27, 52, 'Your ticket #52 has been closed.', 'ticket_closed', 0, '2026-03-04 07:38:45'),
(36, 30, 54, 'Your ticket #54 has been closed.', 'ticket_closed', 0, '2026-03-04 07:39:16'),
(37, 30, 54, 'Your ticket #54 was reassigned to Accounting.', 'reassigned', 0, '2026-03-04 07:39:16'),
(38, 23, 55, 'New Critical priority ticket #000055 from Enzo Mendoza - Sales', 'new_ticket', 0, '2026-03-04 07:42:02'),
(39, 24, 55, 'New Critical priority ticket #000055 from Enzo Mendoza - Sales', 'new_ticket', 0, '2026-03-04 07:42:02'),
(40, 31, 55, 'Your ticket #55 has been closed.', 'ticket_closed', 0, '2026-03-04 07:45:57'),
(41, 23, 56, 'New Critical priority ticket #000056 from Enzo Mendoza - Sales', 'new_ticket', 0, '2026-03-04 08:03:46'),
(42, 24, 56, 'New Critical priority ticket #000056 from Enzo Mendoza - Sales', 'new_ticket', 0, '2026-03-04 08:03:46'),
(43, 31, 56, 'Your ticket #56 has been closed.', 'ticket_closed', 0, '2026-03-04 08:06:27'),
(44, 31, 55, 'Your ticket #55 has been closed.', 'ticket_closed', 0, '2026-03-04 08:20:06'),
(45, 31, 55, 'Your ticket #55 has been closed.', 'ticket_closed', 0, '2026-03-04 08:27:35'),
(46, 31, 55, 'Your ticket #55 has been closed.', 'ticket_closed', 0, '2026-03-04 08:27:53'),
(47, 31, 55, 'Your ticket #55 has been closed.', 'ticket_closed', 0, '2026-03-04 08:35:00'),
(48, 31, 55, 'Your ticket #55 was reassigned to MPDC.', 'reassigned', 0, '2026-03-04 08:35:00'),
(49, 31, 56, 'Your ticket #56 has been closed.', 'ticket_closed', 0, '2026-03-04 08:35:25'),
(50, 31, 56, 'Your ticket #56 was reassigned to Supply Chain.', 'reassigned', 0, '2026-03-04 08:35:25'),
(51, 31, 56, 'Your ticket #56 has been closed.', 'ticket_closed', 0, '2026-03-04 08:35:48'),
(52, 31, 56, 'Your ticket #56 was reassigned to Admin.', 'reassigned', 0, '2026-03-04 08:35:48'),
(53, 31, 56, 'Your ticket #56 has been closed.', 'ticket_closed', 0, '2026-03-04 08:40:39'),
(54, 31, 56, 'Your ticket #56 was reassigned to MPDC.', 'reassigned', 0, '2026-03-04 08:40:39'),
(55, 31, 55, 'Your ticket #55 has been closed.', 'ticket_closed', 0, '2026-03-04 08:54:41'),
(56, 31, 55, 'Your ticket #55 has been closed.', 'ticket_closed', 0, '2026-03-04 08:54:45'),
(57, 31, 56, 'Your ticket #56 has been closed.', 'ticket_closed', 0, '2026-03-04 09:01:35'),
(58, 31, 56, 'Your ticket #56 has been closed.', 'ticket_closed', 0, '2026-03-04 09:02:12'),
(59, 31, 56, 'Your ticket #56 has been closed.', 'ticket_closed', 0, '2026-03-04 09:05:45'),
(60, 31, 56, 'Your ticket #56 has been closed.', 'ticket_closed', 0, '2026-03-04 09:05:49'),
(61, 31, 56, 'Your ticket #56 has been closed.', 'ticket_closed', 0, '2026-03-04 09:09:09'),
(62, 31, 55, 'Your ticket #55 has been closed.', 'ticket_closed', 0, '2026-03-04 09:09:26'),
(63, 23, 57, 'New Critical priority ticket #000057 from Enzo Mendoza - Sales', 'new_ticket', 0, '2026-03-04 09:10:27'),
(64, 24, 57, 'New Critical priority ticket #000057 from Enzo Mendoza - Sales', 'new_ticket', 1, '2026-03-04 09:10:27'),
(65, 23, 58, 'New Medium priority ticket #000058 from Enzo Mendoza - Admin', 'new_ticket', 0, '2026-03-05 00:38:05'),
(66, 24, 58, 'New Medium priority ticket #000058 from Enzo Mendoza - Admin', 'new_ticket', 0, '2026-03-05 00:38:05'),
(67, 58, 59, 'New Medium priority ticket #000059 from Enzo Mendoza HR1 - HR', 'new_ticket', 1, '2026-03-05 01:11:17'),
(68, 60, 59, 'Your ticket #59 has been closed.', 'ticket_closed', 1, '2026-03-05 01:35:52'),
(69, 60, 59, 'Admin added a note to ticket #59: \'check it\'', 'note_added', 1, '2026-03-05 01:35:52'),
(70, 58, 60, 'New High priority ticket #000060 from Enzo Charles HR2 - HR', 'new_ticket', 0, '2026-03-05 02:02:31'),
(71, 58, 61, 'New Critical priority ticket #000061 from Chelle Ambayan - IT2 - IT', 'new_ticket', 0, '2026-03-05 02:08:02'),
(72, 58, 62, 'New Medium priority ticket #000062 from Chelle Ambayan - IT2 - IT', 'new_ticket', 0, '2026-03-05 02:36:09'),
(73, 58, 63, 'New High priority ticket #000063 from Enzo Mendoza HR1 - HR', 'new_ticket', 0, '2026-03-05 02:37:31'),
(74, 58, 64, 'New Critical priority ticket #000064 from Enzo Mendoza HR1 - HR', 'new_ticket', 1, '2026-03-05 02:38:30'),
(75, 58, 65, 'New Low priority ticket #000065 from Enzo Mendoza HR1 - HR', 'new_ticket', 0, '2026-03-05 02:45:24'),
(76, 60, 65, 'Your ticket #65 was reassigned to HR.', 'reassigned', 1, '2026-03-05 02:46:47'),
(77, 60, 65, 'Your ticket #65 was reassigned to IT.', 'reassigned', 1, '2026-03-05 02:51:59'),
(78, 60, 65, 'Your ticket #65 status was updated to In Progress by IT.', 'status_update', 1, '2026-03-05 02:52:44'),
(79, 60, 65, 'Your ticket #65 was reassigned to Marketing.', 'reassigned', 1, '2026-03-05 02:52:44'),
(80, 63, 65, 'New ticket #65 was assigned to your department by Marketing.', 'dept_assigned', 1, '2026-03-05 03:04:01'),
(81, 65, 65, 'New ticket #65 was assigned to your department by Marketing.', 'dept_assigned', 0, '2026-03-05 03:04:01'),
(82, 66, 65, 'New ticket #65 was assigned to your department by Marketing.', 'dept_assigned', 0, '2026-03-05 03:04:01'),
(83, 60, 65, 'Your ticket #65 was reassigned to IT.', 'reassigned', 1, '2026-03-05 03:04:01'),
(84, 63, 66, 'New ticket #000066 from Enzo Mendoza HR1 was assigned to your department.', 'dept_assigned', 1, '2026-03-05 03:12:25'),
(85, 65, 66, 'New ticket #000066 from Enzo Mendoza HR1 was assigned to your department.', 'dept_assigned', 0, '2026-03-05 03:12:25'),
(86, 66, 66, 'New ticket #000066 from Enzo Mendoza HR1 was assigned to your department.', 'dept_assigned', 0, '2026-03-05 03:12:25'),
(87, 58, 66, 'New High priority ticket #000066 from Enzo Mendoza HR1 - HR', 'new_ticket', 0, '2026-03-05 03:12:25'),
(88, 70, 66, 'New ticket #66 was assigned to your department by IT.', 'dept_assigned', 1, '2026-03-05 03:13:42'),
(89, 60, 66, 'Your ticket #66 was reassigned to Marketing.', 'reassigned', 1, '2026-03-05 03:13:42'),
(90, 58, 67, 'New Critical priority ticket #000067 from Enzo Mendoza HR1 - HR', 'new_ticket', 0, '2026-03-05 03:48:30'),
(91, 65, 68, 'New ticket #000068 from Enzo Mendoza HR1 was assigned to your department.', 'dept_assigned', 1, '2026-03-05 03:51:07'),
(92, 66, 68, 'New ticket #000068 from Enzo Mendoza HR1 was assigned to your department.', 'dept_assigned', 1, '2026-03-05 03:51:07'),
(93, 58, 68, 'New High priority ticket #000068 from Enzo Mendoza HR1 - HR', 'new_ticket', 0, '2026-03-05 03:51:07'),
(94, 69, 69, 'New ticket #000069 from Enzo Mendoza HR1 was assigned to your department.', 'dept_assigned', 1, '2026-03-05 04:01:19'),
(95, 58, 69, 'New High priority ticket #000069 from Enzo Mendoza HR1 - HR', 'new_ticket', 0, '2026-03-05 04:01:19'),
(96, 58, 70, 'New High priority ticket #000070 from Enzo Mendoza HR1 - HR', 'new_ticket', 0, '2026-03-05 04:02:16'),
(97, 58, 71, 'New High priority ticket #000071 from Angelica Herrera - HR - HR', 'new_ticket', 0, '2026-03-05 04:44:16'),
(98, 58, 72, 'New Medium priority ticket #000072 from Angelica Herrera - HR - HR', 'new_ticket', 0, '2026-03-05 04:45:29'),
(99, 69, 73, 'New ticket #000073 from Enzo Mendoza HR1 was assigned to your department.', 'dept_assigned', 1, '2026-03-05 04:46:59'),
(100, 58, 73, 'New Medium priority ticket #000073 from Enzo Mendoza HR1 - HR', 'new_ticket', 0, '2026-03-05 04:46:59'),
(101, 60, 73, 'Your ticket #73 was reassigned to HR at Malveda Holdings Corporation.', 'reassigned', 1, '2026-03-05 04:47:42'),
(102, 69, 74, 'New ticket #000074 from Matthew Pascua - Malveda IT was assigned to your department.', 'dept_assigned', 1, '2026-03-05 04:48:55'),
(103, 58, 74, 'New Medium priority ticket #000074 from Matthew Pascua - Malveda IT - IT', 'new_ticket', 0, '2026-03-05 04:48:55'),
(104, 63, 74, 'Your ticket #74 was reassigned to HR at Malveda Holdings Corporation.', 'reassigned', 0, '2026-03-05 04:49:42'),
(105, 65, 75, 'New ticket #000075 from Enzo Charles HR2 was assigned to your department.', 'dept_assigned', 1, '2026-03-05 04:50:55'),
(106, 66, 75, 'New ticket #000075 from Enzo Charles HR2 was assigned to your department.', 'dept_assigned', 0, '2026-03-05 04:50:55'),
(107, 58, 75, 'New Medium priority ticket #000075 from Enzo Charles HR2 - HR', 'new_ticket', 0, '2026-03-05 04:50:55'),
(108, 69, 75, 'New ticket #75 was assigned to your department by IT (FARMEX).', 'dept_assigned', 1, '2026-03-05 04:51:42'),
(109, 62, 75, 'Your ticket #75 was reassigned to HR at FARMEX.', 'reassigned', 0, '2026-03-05 04:51:42'),
(110, 62, 75, 'Your ticket #75 status was updated to In Progress by HR.', 'status_update', 0, '2026-03-05 04:52:42'),
(111, 65, 76, 'New ticket #000076 from Enzo Mendoza HR1 was assigned to your department.', 'dept_assigned', 1, '2026-03-05 04:54:41'),
(112, 66, 76, 'New ticket #000076 from Enzo Mendoza HR1 was assigned to your department.', 'dept_assigned', 0, '2026-03-05 04:54:41'),
(113, 58, 76, 'New Medium priority ticket #000076 from Enzo Mendoza HR1 - HR', 'new_ticket', 0, '2026-03-05 04:54:41'),
(114, 69, 76, 'New ticket #76 was assigned to your department by IT (FARMEX).', 'dept_assigned', 1, '2026-03-05 04:55:40'),
(115, 60, 76, 'Your ticket #76 was reassigned to HR at FARMEX.', 'reassigned', 0, '2026-03-05 04:55:40'),
(116, 60, 76, 'Your ticket #76 has been closed by HR.', 'ticket_closed', 0, '2026-03-05 04:56:53'),
(117, 62, 75, 'Your ticket #75 has been closed by HR.', 'ticket_closed', 0, '2026-03-05 04:57:18'),
(118, 60, 76, 'Your ticket #76 was reassigned to Marketing at Malveda Holdings Corporation.', 'reassigned', 0, '2026-03-05 05:33:49'),
(119, 65, 75, 'New ticket #75 was assigned to your department by HR (FARMEX).', 'dept_assigned', 1, '2026-03-05 05:35:42'),
(120, 66, 75, 'New ticket #75 was assigned to your department by HR (FARMEX).', 'dept_assigned', 0, '2026-03-05 05:35:42'),
(121, 62, 75, 'Your ticket #75 was reassigned to IT at FARMEX.', 'reassigned', 0, '2026-03-05 05:35:42'),
(122, 62, 75, 'Your ticket #75 status was updated to In Progress by IT.', 'status_update', 0, '2026-03-05 05:36:07'),
(123, 65, 78, 'New ticket #000078 from Enzo Charles HR2 was assigned to your department.', 'dept_assigned', 1, '2026-03-05 06:27:42'),
(124, 66, 78, 'New ticket #000078 from Enzo Charles HR2 was assigned to your department.', 'dept_assigned', 0, '2026-03-05 06:27:42'),
(125, 58, 78, 'New Medium priority ticket #000078 from Enzo Charles HR2 - HR', 'new_ticket', 0, '2026-03-05 06:27:42'),
(126, 62, 78, 'Your ticket #78 has been closed.', 'ticket_closed', 0, '2026-03-05 06:41:50'),
(127, 60, 68, 'Your ticket #68 has been closed.', 'ticket_closed', 0, '2026-03-05 06:42:26'),
(128, 65, 79, 'New ticket #000079 from Enzo Charles HR2 was assigned to your department.', 'dept_assigned', 1, '2026-03-05 07:03:10'),
(129, 66, 79, 'New ticket #000079 from Enzo Charles HR2 was assigned to your department.', 'dept_assigned', 0, '2026-03-05 07:03:10'),
(130, 58, 79, 'New Medium priority ticket #000079 from Enzo Charles HR2 - HR', 'new_ticket', 0, '2026-03-05 07:03:10'),
(131, 62, 79, 'Your ticket #79 status was updated to In Progress by IT.', 'status_update', 0, '2026-03-05 07:04:41'),
(132, 69, 79, 'New ticket #79 was assigned to your department by IT (FARMEX).', 'dept_assigned', 1, '2026-03-05 07:05:11'),
(133, 62, 79, 'Your ticket #79 was reassigned to HR at FARMEX.', 'reassigned', 0, '2026-03-05 07:05:11');

-- --------------------------------------------------------

--
-- Table structure for table `ticket_messages`
--

CREATE TABLE `ticket_messages` (
  `id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ticket_messages`
--

INSERT INTO `ticket_messages` (`id`, `ticket_id`, `sender_id`, `message`, `created_at`) VALUES
(5, 65, 63, 'goodmornming enzo', '2026-03-05 03:05:42'),
(6, 65, 63, 'hellooo', '2026-03-05 03:10:05'),
(7, 66, 70, 'eyooo', '2026-03-05 03:14:21');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `company` varchar(255) DEFAULT NULL,
  `department` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','employee') DEFAULT 'employee',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `otp_code` varchar(10) DEFAULT NULL,
  `is_verified` tinyint(1) DEFAULT 0,
  `reset_otp` varchar(10) DEFAULT NULL,
  `reset_otp_expiry` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `company`, `department`, `password`, `role`, `created_at`, `otp_code`, `is_verified`, `reset_otp`, `reset_otp_expiry`) VALUES
(58, 'System Admin', 'admin@gmail.com', 'FARMEX', 'IT', '$2y$10$Rvzs422tli4xWRD2cRc56.N0QMoP3zyvGzWqs4VPBfcayJWbCDtlO', 'admin', '2026-03-05 00:51:02', NULL, 0, NULL, NULL),
(60, 'Enzo Mendoza HR1', 'enzomendoza8teen@gmail.com', 'Malveda Holdings Corporation - MHC', 'HR', '$2y$10$efKRIJvY8DhsHF1ofOv8oOhqdy7uZJi5uJpmKUJTjogi1Qb8ZVyHG', 'employee', '2026-03-05 01:05:16', NULL, 1, NULL, NULL),
(62, 'Enzo Charles HR2', 'enzocharlesgarcia.21@gmail.com', 'Malveda Holdings Corporation - MHC', 'HR', '$2y$10$v7uZhO3iDPsDbZmAN6Me4uF6etJAR05p.hCeO3TK0Od/wmln3ekPO', 'employee', '2026-03-05 01:16:19', NULL, 1, '582640', '2026-03-05 08:30:27'),
(63, 'Matthew Pascua - Malveda IT', 'matthewpascua052203@gmail.com', 'Malveda Holdings Corporation - MHC', 'IT', '$2y$10$GfXnv5hJb.Y/Q2cZPDbt7usaHNFX5Px6FMgimTPHn69eaTEhBnmRO', 'admin', '2026-03-05 01:19:16', NULL, 1, NULL, NULL),
(65, 'Rachelle Ambayan - IT1', 'rachelleambayan@gmail.com', 'FARMEX', 'IT', '$2y$10$a0iWEwUby3yBYE8ruHKyXOq2WDf6CE6pSpkRGcAZIPB2C6K8jabB.', 'employee', '2026-03-05 01:24:41', NULL, 1, NULL, NULL),
(66, 'Chelle Ambayan - IT2', 'ambayanann@gmail.com', 'FARMEX', 'IT', '$2y$10$EqfSOvB3zc2dc3FNT6BNde8CrYvNASGuz/soPrV8hLY2XukB7.zAG', 'employee', '2026-03-05 01:27:07', NULL, 1, NULL, NULL),
(69, 'Angelica Herrera - HR', 'angelicalherrera02@gmail.com', 'FARMASEE', 'HR', '$2y$10$PkAFSMTQ/L1nAhZJQ4SY4ebqMnLWyfF2wqWCjhpsNoG4GaAIZxcT6', 'employee', '2026-03-05 01:29:56', NULL, 1, NULL, NULL),
(70, 'Chelle Escobarte Marketing', 'chelleescobarte@gmail.com', 'Golden Primestocks Chemical Inc - GPSCI', 'Marketing', '$2y$10$Kyuc3w41XmO2/KLraNGY9.xt8lT8J3pcocNop0iPT.PV3RmuNXSlu', 'employee', '2026-03-05 02:50:53', NULL, 1, NULL, NULL),
(71, 'Sales Department', 'sales_guest@leadsagri.com', 'Sales', 'Sales', '$2y$10$cmED9RKcXPPxRSaeuuSby.DlOBtmwIsQNZqJttl.ezwrpwBeGsuOK', 'employee', '2026-03-05 06:26:24', '000000', 1, NULL, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `employee_tickets`
--
ALTER TABLE `employee_tickets`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `knowledge_base`
--
ALTER TABLE `knowledge_base`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `ticket_messages`
--
ALTER TABLE `ticket_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ticket_id` (`ticket_id`),
  ADD KEY `sender_id` (`sender_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `employee_tickets`
--
ALTER TABLE `employee_tickets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=82;

--
-- AUTO_INCREMENT for table `knowledge_base`
--
ALTER TABLE `knowledge_base`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=134;

--
-- AUTO_INCREMENT for table `ticket_messages`
--
ALTER TABLE `ticket_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=72;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `knowledge_base`
--
ALTER TABLE `knowledge_base`
  ADD CONSTRAINT `knowledge_base_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `ticket_messages`
--
ALTER TABLE `ticket_messages`
  ADD CONSTRAINT `ticket_messages_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `employee_tickets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ticket_messages_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
