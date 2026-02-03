-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 31, 2026 at 02:28 PM
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
-- Database: `embroidery_platform`
--

DELIMITER $$
--
-- Functions
--
CREATE DEFINER=`root`@`localhost` FUNCTION `generate_order_number` () RETURNS VARCHAR(20) CHARSET utf8mb4 COLLATE utf8mb4_general_ci  BEGIN
    DECLARE new_order_num VARCHAR(20);
    SET new_order_num = CONCAT('ORD-', DATE_FORMAT(NOW(), '%Y%m%d-'), LPAD(FLOOR(RAND() * 10000), 4, '0'));
    RETURN new_order_num;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `activities`
--

CREATE TABLE `activities` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `user_name` varchar(100) DEFAULT NULL,
  `activity` varchar(255) NOT NULL,
  `status` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `activities`
--

INSERT INTO `activities` (`id`, `user_id`, `user_name`, `activity`, `status`, `created_at`) VALUES
(1, NULL, 'John Smith', 'New user registered', 'completed', '2026-01-22 12:31:55'),
(2, NULL, 'Jane Doe', 'User role changed', 'pending', '2026-01-22 12:31:55'),
(3, NULL, 'System', 'System backup', 'completed', '2026-01-22 12:31:55'),
(4, NULL, 'John Smith', 'New user registered', 'completed', '2026-01-22 12:35:37'),
(5, NULL, 'Jane Doe', 'User role changed', 'pending', '2026-01-22 12:35:37'),
(6, NULL, 'System', 'System backup', 'completed', '2026-01-22 12:35:37'),
(7, NULL, 'Robert Johnson', 'New service request submitted', 'pending', '2026-01-22 12:35:37'),
(8, NULL, 'Sarah Wilson', 'Order #001 completed', 'completed', '2026-01-22 12:35:37');

-- --------------------------------------------------------

--
-- Table structure for table `analytics_data`
--

CREATE TABLE `analytics_data` (
  `id` int(11) NOT NULL,
  `metric_date` date NOT NULL,
  `metric_type` varchar(50) NOT NULL,
  `shop_id` int(11) DEFAULT NULL,
  `value` decimal(12,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `appointments`
--

CREATE TABLE `appointments` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `staff_id` int(11) DEFAULT NULL,
  `appointment_date` date NOT NULL,
  `appointment_time` time NOT NULL,
  `service_type` varchar(100) DEFAULT NULL,
  `status` enum('scheduled','completed','cancelled') DEFAULT 'scheduled',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `appointments`
--

INSERT INTO `appointments` (`id`, `customer_id`, `staff_id`, `appointment_date`, `appointment_time`, `service_type`, `status`, `notes`, `created_at`) VALUES
(1, 3, 2, '2026-01-22', '10:30:00', 'Consultation', 'scheduled', NULL, '2026-01-22 12:35:37'),
(2, 3, 2, '2026-01-24', '14:00:00', 'Design Review', 'scheduled', NULL, '2026-01-22 12:35:37');

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `actor_id` int(11) DEFAULT NULL,
  `actor_role` varchar(50) DEFAULT NULL,
  `action` varchar(150) NOT NULL,
  `entity_type` varchar(100) NOT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `old_values` text DEFAULT NULL,
  `new_values` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `actor_id`, `actor_role`, `action`, `entity_type`, `entity_id`, `old_values`, `new_values`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 1, 'sys_admin', 'approve_shop', 'shops', 2, '{\"status\":\"pending\"}', '{\"status\":\"active\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-01-30 13:18:18'),
(2, 1, 'sys_admin', 'activate_shop_owner', 'users', 14, '{\"status\":\"\"}', '{\"status\":\"active\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-01-30 13:18:18'),
(3, 14, 'owner', 'accept_order', 'orders', 6, '{\"status\":\"pending\"}', '{\"status\":\"accepted\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-01-30 13:19:24'),
(4, 14, 'owner', 'accept_order', 'orders', 7, '{\"status\":\"pending\"}', '{\"status\":\"accepted\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-01-30 13:20:47'),
(5, 14, 'owner', 'accept_order', 'orders', 8, '{\"status\":\"pending\"}', '{\"status\":\"accepted\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-01-30 13:49:39'),
(6, 14, 'owner', 'accept_order', 'orders', 9, '{\"status\":\"pending\"}', '{\"status\":\"accepted\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-01-31 01:31:44'),
(7, 1, 'sys_admin', 'approve_shop', 'shops', 3, '{\"status\":\"pending\"}', '{\"status\":\"active\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-01-31 06:00:13'),
(8, 1, 'sys_admin', 'activate_shop_owner', 'users', 17, '{\"status\":\"pending\"}', '{\"status\":\"active\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-01-31 06:00:13');

-- --------------------------------------------------------

--
-- Table structure for table `budgets`
--

CREATE TABLE `budgets` (
  `id` int(11) NOT NULL,
  `department` varchar(100) DEFAULT NULL,
  `budget_year` year(4) NOT NULL,
  `budget_month` int(11) DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `allocated_amount` decimal(12,2) NOT NULL,
  `spent_amount` decimal(12,2) DEFAULT 0.00,
  `remaining_amount` decimal(12,2) GENERATED ALWAYS AS (`allocated_amount` - `spent_amount`) STORED,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chats`
--

CREATE TABLE `chats` (
  `id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `receiver_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `read_status` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `design_approvals`
--

CREATE TABLE `design_approvals` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `service_provider_id` int(11) NOT NULL,
  `design_file` varchar(255) DEFAULT NULL,
  `customer_notes` text DEFAULT NULL,
  `provider_notes` text DEFAULT NULL,
  `status` enum('pending','approved','rejected','revision') DEFAULT 'pending',
  `revision_count` int(11) DEFAULT 0,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dss_configurations`
--

CREATE TABLE `dss_configurations` (
  `id` int(11) NOT NULL,
  `config_key` varchar(100) NOT NULL,
  `config_value` text DEFAULT NULL,
  `config_type` enum('system','shop','user') DEFAULT 'system',
  `description` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `dss_configurations`
--

INSERT INTO `dss_configurations` (`id`, `config_key`, `config_value`, `config_type`, `description`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'order_acceptance_threshold', '0.7', 'system', 'Minimum rating threshold for auto-order acceptance', NULL, '2026-01-22 12:44:22', '2026-01-22 12:44:22'),
(2, 'employee_performance_weight', '{\"quality\":0.4,\"speed\":0.3,\"customer_feedback\":0.3}', 'system', 'Weight for employee performance calculation', NULL, '2026-01-22 12:44:22', '2026-01-22 12:44:22'),
(3, 'shop_rating_algorithm', 'weighted_average', 'system', 'Algorithm for calculating shop ratings', NULL, '2026-01-22 12:44:22', '2026-01-22 12:44:22'),
(4, 'auto_schedule_enabled', 'true', 'system', 'Enable automatic job scheduling', NULL, '2026-01-22 12:44:22', '2026-01-22 12:44:22'),
(5, 'notification_days_before_due', '2', 'system', 'Days before due date to send notifications', NULL, '2026-01-22 12:44:22', '2026-01-22 12:44:22');

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `department` varchar(100) DEFAULT NULL,
  `position` varchar(100) DEFAULT NULL,
  `salary` decimal(12,2) DEFAULT NULL,
  `hire_date` date DEFAULT NULL,
  `status` enum('active','inactive','on_leave') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `financial_transactions`
--

CREATE TABLE `financial_transactions` (
  `id` int(11) NOT NULL,
  `type` enum('income','expense') NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `description` text DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `transaction_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `job_schedule`
--

CREATE TABLE `job_schedule` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `scheduled_date` date NOT NULL,
  `scheduled_time` time DEFAULT NULL,
  `task_description` text DEFAULT NULL,
  `status` enum('scheduled','in_progress','completed') DEFAULT 'scheduled',
  `actual_start` datetime DEFAULT NULL,
  `actual_end` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `material_orders`
--

CREATE TABLE `material_orders` (
  `id` int(11) NOT NULL,
  `material_id` int(11) NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `order_date` date NOT NULL,
  `expected_delivery` date DEFAULT NULL,
  `actual_delivery` date DEFAULT NULL,
  `status` enum('ordered','received','cancelled') DEFAULT 'ordered',
  `unit_cost` decimal(10,2) DEFAULT NULL,
  `total_cost` decimal(12,2) DEFAULT NULL,
  `supplier` varchar(200) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `type` varchar(50) NOT NULL,
  `message` varchar(255) NOT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `order_id`, `type`, `message`, `read_at`, `created_at`) VALUES
(1, 12, 6, 'info', 'Your order #ORD-20260130-B540E6 has been submitted and is awaiting shop acceptance.', NULL, '2026-01-30 13:19:07'),
(2, 12, 6, 'order_status', 'Your order #ORD-20260130-B540E6 has been accepted by Ailly Mae Ramil\'s Shop.', NULL, '2026-01-30 13:19:24'),
(3, 12, 6, 'success', 'Your order #ORD-20260130-B540E6 has been accepted and will be scheduled shortly.', NULL, '2026-01-30 13:19:24'),
(4, 12, 6, 'order_status', 'Order #ORD-20260130-B540E6 status updated to completed by Ailly Mae Ramil\'s Shop.', NULL, '2026-01-30 13:19:46'),
(5, 14, 6, 'order_status', 'Order #ORD-20260130-B540E6 status updated to completed by Ailly Mae Ramil\'s Shop.', NULL, '2026-01-30 13:19:46'),
(6, 12, 6, 'success', 'Order #ORD-20260130-B540E6 has been updated to completed.', NULL, '2026-01-30 13:19:46'),
(7, 12, 7, 'info', 'Your order #ORD-20260130-74686B has been submitted and is awaiting shop acceptance.', NULL, '2026-01-30 13:20:39'),
(8, 12, 7, 'order_status', 'Your order #ORD-20260130-74686B has been accepted by Ailly Mae Ramil\'s Shop.', NULL, '2026-01-30 13:20:47'),
(9, 12, 7, 'success', 'Your order #ORD-20260130-74686B has been accepted and will be scheduled shortly.', NULL, '2026-01-30 13:20:47'),
(10, 12, 7, 'order_status', 'Order #ORD-20260130-74686B status updated to in progress by Ailly Mae Ramil\'s Shop.', NULL, '2026-01-30 13:21:08'),
(11, 14, 7, 'order_status', 'Order #ORD-20260130-74686B status updated to in progress by Ailly Mae Ramil\'s Shop.', NULL, '2026-01-30 13:21:08'),
(12, 12, 7, 'info', 'Order #ORD-20260130-74686B has been updated to in progress.', NULL, '2026-01-30 13:21:08'),
(13, 12, 7, 'order_status', 'Order #ORD-20260130-74686B status updated to completed by Ailly Mae Ramil\'s Shop.', NULL, '2026-01-30 13:39:27'),
(14, 14, 7, 'order_status', 'Order #ORD-20260130-74686B status updated to completed by Ailly Mae Ramil\'s Shop.', NULL, '2026-01-30 13:39:27'),
(15, 12, 7, 'success', 'Order #ORD-20260130-74686B has been updated to completed.', NULL, '2026-01-30 13:39:27'),
(16, 15, 8, 'info', 'Your order #ORD-20260130-5960D2 has been submitted and is awaiting shop acceptance.', NULL, '2026-01-30 13:48:37'),
(17, 15, 8, 'order_status', 'Your order #ORD-20260130-5960D2 has been accepted by Ailly Mae Ramil\'s Shop.', NULL, '2026-01-30 13:49:39'),
(18, 15, 8, 'success', 'Your order #ORD-20260130-5960D2 has been accepted and will be scheduled shortly.', NULL, '2026-01-30 13:49:39'),
(19, 15, 8, 'order_status', 'Order #ORD-20260130-5960D2 status updated to in progress by Ailly Mae Ramil\'s Shop.', NULL, '2026-01-30 13:50:02'),
(20, 14, 8, 'order_status', 'Order #ORD-20260130-5960D2 status updated to in progress by Ailly Mae Ramil\'s Shop.', NULL, '2026-01-30 13:50:02'),
(21, 15, 8, 'info', 'Order #ORD-20260130-5960D2 has been updated to in progress.', NULL, '2026-01-30 13:50:02'),
(22, 15, 8, 'order_status', 'Order #ORD-20260130-5960D2 status updated to completed by Ailly Mae Ramil\'s Shop.', NULL, '2026-01-30 13:52:51'),
(23, 14, 8, 'order_status', 'Order #ORD-20260130-5960D2 status updated to completed by Ailly Mae Ramil\'s Shop.', NULL, '2026-01-30 13:52:51'),
(24, 15, 8, 'success', 'Order #ORD-20260130-5960D2 has been updated to completed.', NULL, '2026-01-30 13:52:51'),
(25, 15, 9, 'info', 'Your order #ORD-20260131-E9DC0B has been submitted and is awaiting shop acceptance.', NULL, '2026-01-31 01:31:26'),
(26, 15, 9, 'order_status', 'Your order #ORD-20260131-E9DC0B has been accepted by Ailly Mae Ramil\'s Shop.', NULL, '2026-01-31 01:31:44'),
(27, 15, 9, 'success', 'Your order #ORD-20260131-E9DC0B has been accepted and will be scheduled shortly.', NULL, '2026-01-31 01:31:44'),
(28, 15, 9, 'order_status', 'Order #ORD-20260131-E9DC0B status updated to  by Ailly Mae Ramil\'s Shop.', NULL, '2026-01-31 01:32:06'),
(29, 14, 9, 'order_status', 'Order #ORD-20260131-E9DC0B status updated to  by Ailly Mae Ramil\'s Shop.', NULL, '2026-01-31 01:32:06'),
(30, 15, 9, 'info', 'Order #ORD-20260131-E9DC0B has been updated to .', NULL, '2026-01-31 01:32:06'),
(31, 15, 9, 'order_status', 'Order #ORD-20260131-E9DC0B status updated to in progress by Ailly Mae Ramil\'s Shop.', NULL, '2026-01-31 01:32:23'),
(32, 14, 9, 'order_status', 'Order #ORD-20260131-E9DC0B status updated to in progress by Ailly Mae Ramil\'s Shop.', NULL, '2026-01-31 01:32:24'),
(33, 15, 9, 'info', 'Order #ORD-20260131-E9DC0B has been updated to in progress.', NULL, '2026-01-31 01:32:24'),
(34, 15, 9, 'order_status', 'Order #ORD-20260131-E9DC0B status updated to completed by Ailly Mae Ramil\'s Shop.', NULL, '2026-01-31 01:32:39'),
(35, 14, 9, 'order_status', 'Order #ORD-20260131-E9DC0B status updated to completed by Ailly Mae Ramil\'s Shop.', NULL, '2026-01-31 01:32:39'),
(36, 15, 9, 'success', 'Order #ORD-20260131-E9DC0B has been updated to completed.', NULL, '2026-01-31 01:32:39'),
(37, 15, 9, 'order_status', 'Order #ORD-20260131-E9DC0B status updated to in progress by Ailly Mae Ramil\'s Shop.', NULL, '2026-01-31 01:32:43'),
(38, 14, 9, 'order_status', 'Order #ORD-20260131-E9DC0B status updated to in progress by Ailly Mae Ramil\'s Shop.', NULL, '2026-01-31 01:32:43'),
(39, 15, 9, 'info', 'Order #ORD-20260131-E9DC0B has been updated to in progress.', NULL, '2026-01-31 01:32:43'),
(40, 16, 10, 'info', 'Your order #ORD-20260131-B29BA3 has been submitted and is awaiting shop acceptance.', NULL, '2026-01-31 06:00:59'),
(41, 16, 10, 'warning', 'Order #ORD-20260131-B29BA3 was cancelled per your request.', NULL, '2026-01-31 06:02:25');

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(11) NOT NULL,
  `order_number` varchar(50) NOT NULL,
  `client_id` int(11) NOT NULL,
  `shop_id` int(11) NOT NULL,
  `service_type` varchar(100) NOT NULL,
  `design_description` text DEFAULT NULL,
  `quantity` int(11) DEFAULT 1,
  `price` decimal(10,2) DEFAULT NULL,
  `client_notes` text DEFAULT NULL,
  `quote_details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`quote_details`)),
  `status` enum('pending','accepted','in_progress','completed','cancelled') DEFAULT 'pending',
  `assigned_to` int(11) DEFAULT NULL,
  `progress` int(11) DEFAULT 0,
  `scheduled_date` date DEFAULT NULL,
  `shop_notes` text DEFAULT NULL,
  `design_file` varchar(255) DEFAULT NULL,
  `design_approved` tinyint(1) DEFAULT 0,
  `rating` tinyint(1) DEFAULT NULL,
  `payment_status` enum('unpaid','pending','paid','rejected','refund_pending','refunded') DEFAULT 'unpaid',
  `payment_verified_at` datetime DEFAULT NULL,
  `rating_title` varchar(150) DEFAULT NULL,
  `rating_comment` text DEFAULT NULL,
  `rating_submitted_at` datetime DEFAULT NULL,
  `rating_status` enum('pending','approved','rejected') DEFAULT 'approved',
  `rating_response` text DEFAULT NULL,
  `rating_response_at` datetime DEFAULT NULL,
  `rating_moderated_at` datetime DEFAULT NULL,
  `revision_count` int(11) DEFAULT 0,
  `revision_notes` text DEFAULT NULL,
  `revision_requested_at` datetime DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`id`, `order_number`, `client_id`, `shop_id`, `service_type`, `design_description`, `quantity`, `price`, `client_notes`, `quote_details`, `status`, `assigned_to`, `progress`, `scheduled_date`, `shop_notes`, `design_file`, `design_approved`, `rating`, `payment_status`, `payment_verified_at`, `rating_title`, `rating_comment`, `rating_submitted_at`, `revision_count`, `revision_notes`, `revision_requested_at`, `cancellation_reason`, `cancelled_at`, `completed_at`, `created_at`, `updated_at`) VALUES
(1, 'ORD-20260122-ABC123', 3, 1, 'Custom Logo Embroidery', 'Logo for company uniforms', 50, 2500.00, 'Please match our brand colors.', NULL, 'in_progress', NULL, 75, '2026-01-28', NULL, NULL, 0, NULL, 'unpaid', NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, '2026-01-22 12:31:55', '2026-01-22 12:31:55'),
(2, 'ORD-20260122-DEF456', 3, 1, 'Name Patch Embroidery', 'Name patches for employees', 25, 1200.00, 'Include last names only.', NULL, 'completed', NULL, 100, '2026-01-27', 'Completed successfully.', NULL, 0, 5, 'unpaid', NULL, 'Clean finish', 'Great quality, stitches are consistent.', '2026-01-28 10:05:00', 0, NULL, NULL, NULL, NULL, '2026-01-28 10:00:00', '2026-01-22 12:31:55', '2026-01-22 12:31:55'),
(3, 'ORD-20260122-GHI789', 3, 1, 'Custom Logo Embroidery', 'Cap embroidery', 30, 900.00, NULL, NULL, 'accepted', NULL, 20, '2026-01-29', NULL, NULL, 0, NULL, 'unpaid', NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, '2026-01-22 12:35:37', '2026-01-22 12:35:37'),
(4, 'ORD-20260122-JKL012', 3, 1, 'Name Patch Embroidery', 'Uniform patches', 10, 300.00, NULL, NULL, 'completed', NULL, 100, '2026-01-26', 'Delivered to client.', NULL, 0, 4, 'unpaid', NULL, 'Quick turnaround', 'Fast delivery and accurate sizing.', '2026-01-27 10:00:00', 0, NULL, NULL, NULL, NULL, '2026-01-27 09:30:00', '2026-01-22 12:35:37', '2026-01-22 12:35:37'),
(5, 'ORD-20260122-MNO345', 3, 1, 'Uniform Design', 'Seasonal uniform design', 15, 1500.00, 'Need before end of month.', NULL, 'pending', NULL, 0, '2026-02-02', NULL, NULL, 0, NULL, 'unpaid', NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, '2026-01-22 12:35:37', '2026-01-22 12:35:37'),
(6, 'ORD-20260130-B540E6', 12, 2, 'T-shirt Embroidery', 'add', 1, 290.00, 'adad', NULL, 'completed', 13, 17, NULL, '\n', '6_download.png', 0, NULL, 'unpaid', NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, '2026-01-30 21:19:46', '2026-01-30 13:19:07', '2026-01-30 13:19:46'),
(7, 'ORD-20260130-74686B', 12, 2, 'Logo Embroidery', 'adad', 1, 200.00, 'adad', NULL, 'completed', 13, 50, NULL, '\nmalapit na po\n\n\n', '7_download.png', 0, NULL, 'unpaid', NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, '2026-01-30 21:39:33', '2026-01-30 13:20:39', '2026-01-30 13:39:33'),
(8, 'ORD-20260130-5960D2', 15, 2, 'Bag Embroidery', 'adad', 1, 123.00, '', NULL, 'completed', 13, 35, NULL, '\n\n\n', '8_download.png', 0, 5, 'unpaid', NULL, '', '', '2026-01-30 21:53:11', 0, NULL, NULL, NULL, NULL, '2026-01-30 21:52:54', '2026-01-30 13:48:37', '2026-01-30 13:53:11'),
(9, 'ORD-20260131-E9DC0B', 15, 2, 'T-shirt Embroidery', 'malupet', 2, NULL, '', NULL, 'in_progress', 13, 50, NULL, '\n\n\n\n', NULL, 0, NULL, 'unpaid', NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, '2026-01-31 09:32:39', '2026-01-31 01:31:26', '2026-01-31 01:32:43'),
(10, 'ORD-20260131-B29BA3', 16, 3, 'T-shirt Embroidery', 'master kim', 1, NULL, 'master ko si kim', NULL, 'cancelled', NULL, 0, NULL, NULL, '10_download.png', 0, NULL, 'unpaid', NULL, NULL, NULL, NULL, 0, NULL, NULL, '', '2026-01-31 14:02:25', NULL, '2026-01-31 06:00:59', '2026-01-31 06:02:25');

-- --------------------------------------------------------

--
-- Table structure for table `order_photos`
--

-- --------------------------------------------------------
--
-- Table structure for table `order_fulfillments`
--

CREATE TABLE `order_fulfillments` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `fulfillment_type` enum('delivery','pickup') DEFAULT 'pickup',
  `status` enum('pending','ready_for_pickup','out_for_delivery','delivered','claimed','failed') DEFAULT 'pending',
  `courier` varchar(100) DEFAULT NULL,
  `tracking_number` varchar(120) DEFAULT NULL,
  `pickup_location` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `ready_at` datetime DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `claimed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order_fulfillments`
--

INSERT INTO `order_fulfillments` (`id`, `order_id`, `fulfillment_type`, `status`, `courier`, `tracking_number`, `pickup_location`, `notes`, `ready_at`, `delivered_at`, `claimed_at`, `created_at`, `updated_at`) VALUES
(1, 2, 'delivery', 'delivered', 'LBC Express', 'LBX-2026-0012', NULL, 'Delivered to client reception.', NULL, '2026-01-28 13:30:00', NULL, '2026-01-28 08:15:00', '2026-01-28 13:30:00'),
(2, 4, 'pickup', 'ready_for_pickup', NULL, NULL, 'Shop front desk', 'Notify client via text upon arrival.', '2026-01-27 09:00:00', NULL, NULL, '2026-01-27 08:45:00', '2026-01-27 09:00:00');

-- --------------------------------------------------------
--
-- Table structure for table `order_fulfillment_history`
--

CREATE TABLE `order_fulfillment_history` (
  `id` int(11) NOT NULL,
  `fulfillment_id` int(11) NOT NULL,
  `status` enum('pending','ready_for_pickup','out_for_delivery','delivered','claimed','failed') NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order_fulfillment_history`
--

INSERT INTO `order_fulfillment_history` (`id`, `fulfillment_id`, `status`, `notes`, `created_at`) VALUES
(1, 1, 'out_for_delivery', 'Courier collected the parcel.', '2026-01-28 09:30:00'),
(2, 1, 'delivered', 'Client signed at reception.', '2026-01-28 13:30:00'),
(3, 2, 'ready_for_pickup', 'Pickup window opened.', '2026-01-27 09:00:00');

-- --------------------------------------------------------
--
-- Table structure for table `order_photos`
--

CREATE TABLE `order_photos` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `photo_url` varchar(255) NOT NULL,
  `caption` varchar(255) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order_photos`
--

INSERT INTO `order_photos` (`id`, `order_id`, `employee_id`, `photo_url`, `caption`, `uploaded_at`) VALUES
(1, 8, 13, 'job_photos/8_13_job_697cb728a84af1.78263580.png', 'adadad', '2026-01-30 13:50:32');

-- --------------------------------------------------------

--
-- Table structure for table `order_status_history`
--

CREATE TABLE `order_status_history` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `status` enum('pending','accepted','in_progress','completed','cancelled') NOT NULL,
  `progress` int(11) DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `otp_verifications`
--

CREATE TABLE `otp_verifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `otp_code` varchar(10) NOT NULL,
  `type` enum('registration','reset','upgrade') NOT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `verified` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `shop_id` int(11) NOT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `proof_file` varchar(255) NOT NULL,
  `status` enum('pending','verified','rejected') DEFAULT 'pending',
  `verified_by` int(11) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `order_invoices`
--

CREATE TABLE `order_invoices` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `invoice_number` varchar(50) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` enum('open','paid','cancelled','refunded') DEFAULT 'open',
  `issued_at` datetime NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment_receipts`
--

CREATE TABLE `payment_receipts` (
  `id` int(11) NOT NULL,
  `payment_id` int(11) NOT NULL,
  `receipt_number` varchar(50) NOT NULL,
  `issued_by` int(11) NOT NULL,
  `issued_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment_refunds`
--

CREATE TABLE `payment_refunds` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `payment_id` int(11) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `reason` text DEFAULT NULL,
  `requested_by` int(11) DEFAULT NULL,
  `refunded_by` int(11) DEFAULT NULL,
  `status` enum('pending','refunded') DEFAULT 'pending',
  `requested_at` datetime NOT NULL,
  `refunded_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payroll`
--

CREATE TABLE `payroll` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `pay_period_start` date NOT NULL,
  `pay_period_end` date NOT NULL,
  `basic_salary` decimal(12,2) DEFAULT NULL,
  `allowances` decimal(12,2) DEFAULT NULL,
  `deductions` decimal(12,2) DEFAULT NULL,
  `net_salary` decimal(12,2) DEFAULT NULL,
  `status` enum('pending','paid','cancelled') DEFAULT 'pending',
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `raw_materials`
--

CREATE TABLE `raw_materials` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `category` varchar(50) DEFAULT NULL,
  `unit` varchar(20) DEFAULT NULL,
  `current_stock` decimal(10,2) DEFAULT 0.00,
  `min_stock_level` decimal(10,2) DEFAULT NULL,
  `max_stock_level` decimal(10,2) DEFAULT NULL,
  `unit_cost` decimal(10,2) DEFAULT NULL,
  `supplier` varchar(200) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `services`
--

CREATE TABLE `services` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `category` varchar(50) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `services`
--

INSERT INTO `services` (`id`, `name`, `description`, `price`, `category`, `status`, `created_at`) VALUES
(1, 'Custom T-shirt Embroidery', 'Custom embroidery on t-shirts with your design', 25.00, 'custom_apparel', 'active', '2026-01-22 12:31:55'),
(2, 'Logo Embroidery', 'Company logo embroidery on caps and uniforms', 45.00, 'logo_embroidery', 'active', '2026-01-22 12:31:55'),
(3, 'Personalized Gift', 'Custom embroidered gifts and souvenirs', 35.00, 'personalized_gifts', 'active', '2026-01-22 12:31:55'),
(4, 'Uniform Embroidery', 'Name and badge embroidery on uniforms', 30.00, 'custom_apparel', 'active', '2026-01-22 12:31:55'),
(5, 'Custom T-shirt Embroidery', 'Custom embroidery on t-shirts with your design', 25.00, 'custom_apparel', 'active', '2026-01-22 12:35:36'),
(6, 'Logo Embroidery', 'Company logo embroidery on caps and uniforms', 45.00, 'logo_embroidery', 'active', '2026-01-22 12:35:36'),
(7, 'Personalized Gift', 'Custom embroidered gifts and souvenirs', 35.00, 'personalized_gifts', 'active', '2026-01-22 12:35:36'),
(8, 'Uniform Embroidery', 'Name and badge embroidery on uniforms', 30.00, 'custom_apparel', 'active', '2026-01-22 12:35:36');

-- --------------------------------------------------------

--
-- Table structure for table `service_providers`
--

CREATE TABLE `service_providers` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `business_name` varchar(200) NOT NULL,
  `business_permit` varchar(100) DEFAULT NULL,
  `permit_file` varchar(255) DEFAULT NULL,
  `status` enum('pending','verified','rejected') DEFAULT 'pending',
  `verified_at` timestamp NULL DEFAULT NULL,
  `verified_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `service_requests`
--

CREATE TABLE `service_requests` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `service_type` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('pending','in_progress','completed','cancelled') DEFAULT 'pending',
  `assigned_to` int(11) DEFAULT NULL,
  `priority` enum('low','medium','high') DEFAULT 'medium',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `service_requests`
--

INSERT INTO `service_requests` (`id`, `customer_id`, `service_type`, `description`, `status`, `assigned_to`, `priority`, `created_at`, `updated_at`) VALUES
(1, 3, 'Logo Design', 'Need company logo embroidery on 50 caps', 'pending', NULL, 'high', '2026-01-22 12:35:10', '2026-01-22 12:35:10'),
(2, 3, 'Uniform Patch', 'Name patches for employee uniforms', 'pending', NULL, 'medium', '2026-01-22 12:35:10', '2026-01-22 12:35:10'),
(3, 3, 'Logo Design', 'Need company logo embroidery on 50 caps', 'pending', NULL, 'high', '2026-01-22 12:35:37', '2026-01-22 12:35:37'),
(4, 3, 'Uniform Patch', 'Name patches for employee uniforms', 'pending', NULL, 'medium', '2026-01-22 12:35:37', '2026-01-22 12:35:37'),
(5, 3, 'Custom Gift', 'Embroidered towels for company anniversary', 'pending', NULL, 'low', '2026-01-22 12:35:37', '2026-01-22 12:35:37');

-- --------------------------------------------------------

--
-- Table structure for table `shops`
--

CREATE TABLE `shops` (
  `id` int(11) NOT NULL,
  `owner_id` int(11) NOT NULL,
  `shop_name` varchar(200) NOT NULL,
  `shop_description` text DEFAULT NULL,
  `address` text DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `business_permit` varchar(100) DEFAULT NULL,
  `permit_file` varchar(255) DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `opening_time` time DEFAULT NULL,
  `closing_time` time DEFAULT NULL,
  `operating_days` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`operating_days`)),
  `service_settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`service_settings`)),
  `pricing_settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`pricing_settings`)),
  `status` enum('pending','active','suspended','rejected') DEFAULT 'pending',
  `rejection_reason` text DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `rating` decimal(3,2) DEFAULT 0.00,
  `rating_count` int(11) DEFAULT 0,
  `total_orders` int(11) DEFAULT 0,
  `total_earnings` decimal(12,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `shops`
--

INSERT INTO `shops` (`id`, `owner_id`, `shop_name`, `shop_description`, `address`, `phone`, `email`, `business_permit`, `permit_file`, `logo`, `opening_time`, `closing_time`, `operating_days`, `service_settings`, `pricing_settings`, `status`, `rejection_reason`, `rejected_at`, `rating`, `total_orders`, `total_earnings`, `created_at`, `updated_at`) VALUES
(1, 5, 'Thread & Needle Studio', 'Custom embroidery and uniform design services.', '123 Market Street', '09171234567', 'owner@embroidery.com', NULL, NULL, NULL, '08:00:00', '18:00:00', '[1,2,3,4,5,6]', '[\"T-shirt Embroidery\",\"Logo Embroidery\",\"Cap Embroidery\",\"Bag Embroidery\",\"Custom\"]', '{\"base_prices\":{\"T-shirt Embroidery\":180,\"Logo Embroidery\":160,\"Cap Embroidery\":150,\"Bag Embroidery\":200,\"Custom\":200},\"complexity_multipliers\":{\"Simple\":1,\"Standard\":1.15,\"Complex\":1.35},\"rush_fee_percent\":25,\"add_ons\":{\"Metallic Thread\":50,\"3D Puff\":75,\"Extra Color\":25,\"Applique\":60}}', 'active', NULL, NULL, 4.50, 5, 6400.00, '2026-01-22 12:20:00', '2026-01-22 12:20:00'),
(2, 14, 'Ailly Mae Ramil\'s Shop', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '09:00:00', '17:00:00', '[1,2,3,4,5]', '[\"T-shirt Embroidery\",\"Logo Embroidery\",\"Custom\"]', '{\"base_prices\":{\"T-shirt Embroidery\":170,\"Logo Embroidery\":150,\"Cap Embroidery\":140,\"Bag Embroidery\":190,\"Custom\":190},\"complexity_multipliers\":{\"Simple\":1,\"Standard\":1.15,\"Complex\":1.35},\"rush_fee_percent\":20,\"add_ons\":{\"Metallic Thread\":45,\"3D Puff\":70,\"Extra Color\":20,\"Applique\":55}}', 'active', NULL, NULL, 5.00, 4, 0.00, '2026-01-30 13:17:57', '2026-01-31 01:31:26'),
(3, 17, 'sean\'s Shop', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '10:00:00', '19:00:00', '[2,3,4,5,6]', '[\"Cap Embroidery\",\"Bag Embroidery\",\"Custom\"]', '{\"base_prices\":{\"T-shirt Embroidery\":160,\"Logo Embroidery\":140,\"Cap Embroidery\":130,\"Bag Embroidery\":180,\"Custom\":180},\"complexity_multipliers\":{\"Simple\":1,\"Standard\":1.1,\"Complex\":1.3},\"rush_fee_percent\":15,\"add_ons\":{\"Metallic Thread\":40,\"3D Puff\":60,\"Extra Color\":15,\"Applique\":50}}', 'active', NULL, NULL, 0.00, 1, 0.00, '2026-01-31 05:59:47', '2026-01-31 06:00:59');

-- --------------------------------------------------------

--
-- Table structure for table `shop_employees`
--

CREATE TABLE `shop_employees` (
  `id` int(11) NOT NULL,
  `shop_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `position` varchar(100) DEFAULT NULL,
  `permissions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`permissions`)),
  `availability_days` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`availability_days`)),
  `availability_start` time DEFAULT NULL,
  `availability_end` time DEFAULT NULL,
  `max_active_orders` int(11) DEFAULT 3,
  `hired_date` date DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `shop_employees`
--

INSERT INTO `shop_employees` (`id`, `shop_id`, `user_id`, `position`, `permissions`, `availability_days`, `availability_start`, `availability_end`, `max_active_orders`, `hired_date`, `status`, `created_at`) VALUES
(1, 2, 13, 'Designer', '{\"view_jobs\":true,\"update_status\":true,\"upload_photos\":true}', '[1,2,3,4,5]', '09:00:00', '18:00:00', 3, '2026-01-30', 'active', '2026-01-30 13:18:40'),
(2, 3, 18, 'Designer', '{\"view_jobs\":true,\"update_status\":true,\"upload_photos\":true}', '[2,3,4,5,6]', '10:00:00', '19:00:00', 3, '2026-01-31', 'active', '2026-01-31 06:03:26');

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `fullname` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `role` enum('sys_admin','owner','employee','client') DEFAULT 'client',
  `status` enum('pending','active','inactive','rejected') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `email_verified` tinyint(1) DEFAULT 0,
  `phone` varchar(20) DEFAULT NULL,
  `phone_verified` tinyint(1) DEFAULT 0,
  `last_login` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `fullname`, `email`, `password`, `role`, `status`, `created_at`, `email_verified`, `phone`, `phone_verified`, `last_login`) VALUES
(1, 'Administrator', 'admin@embroidery.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'sys_admin', 'active', '2026-01-19 15:28:09', 0, NULL, 0, '2026-01-31 14:00:06'),
(2, 'Staff Member', 'staff@embroidery.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'employee', 'active', '2026-01-19 15:28:09', 0, NULL, 0, '2026-01-31 09:53:28'),
(3, 'Customer', 'customer@embroidery.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'client', 'active', '2026-01-19 15:28:09', 0, NULL, 0, '2026-01-27 20:53:59'),
(4, 'kim', 'kim@gmail.com', '$2y$12$/6/fhAh8USgtPJsGpB9IAea3NzCCjVSlC7KMiflw9wkKLeiS1f7B2', 'client', 'active', '2026-01-20 12:22:00', 0, NULL, 0, NULL),
(5, 'Shop Owner', 'owner@embroidery.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'owner', 'active', '2026-01-19 15:28:09', 0, NULL, 0, '2026-01-27 19:15:00'),
(12, 'gab', 'gab@embroidery.com', '$2y$10$//MQlEjnhjCYGL1Ar7bDx.y2yGNY4emXxfwDSNjrr1MI.AUK29Uj.', 'client', 'active', '2026-01-30 13:15:16', 0, '123', 0, '2026-01-30 21:40:47'),
(13, 'gwen', 'gwen@embroidery.com', '$2y$10$PaxfOvmaVJYT4erUZ66kz.V56FPbfI5DOWyhenalStgJVi69pZ8lO', 'employee', 'active', '2026-01-30 13:17:06', 0, '12345', 0, '2026-01-31 09:31:53'),
(14, 'Ailly Mae Ramil', 'ailly@embroidery.com', '$2y$10$rGZxnZC3cW6Zkd5BpwIoJ.nylwG.Ur3/8Yq/B4MYU4Oa190fwf9be', 'owner', 'active', '2026-01-30 13:17:57', 0, '123', 0, '2026-01-31 09:53:03'),
(15, 'kiel', 'kiel@embroidery.com', '$2y$10$ZXU.KPOxbnwaOqcSDTfrtOrmrKpZLFkYYJrNFed6JbJb0UXyq0Acq', 'client', 'active', '2026-01-30 13:42:10', 0, '1234', 0, '2026-01-31 09:33:11'),
(16, 'ivypowers', 'ivy@embroidery.com', '$2y$10$3V1I8sJVsbfFManng09SJ.bT45vM1jWy67tvnGmI98IK7J4lGVE.e', 'client', 'active', '2026-01-31 05:58:32', 0, '123456789', 0, '2026-01-31 14:01:15'),
(17, 'sean', 'sean@embroidery.com', '$2y$10$wGSzmQiQxanKmURc254kW.wV5bE4pQdpYCq30ZCCCvLA0bltzX9fa', 'owner', 'active', '2026-01-31 05:59:47', 0, '123', 0, '2026-01-31 14:02:42'),
(18, 'jvee', 'jvee@embroidery.com', '$2y$10$gzMdBcfmS3DsojkpWrWiEO0yLAhx62XwyvmFBg9eH.G8ZCrHUsJau', 'employee', 'active', '2026-01-31 06:03:26', 0, '123', 0, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activities`
--
ALTER TABLE `activities`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `analytics_data`
--
ALTER TABLE `analytics_data`
  ADD PRIMARY KEY (`id`),
  ADD KEY `shop_id` (`shop_id`),
  ADD KEY `idx_metric_date` (`metric_date`),
  ADD KEY `idx_metric_type` (`metric_type`);

--
-- Indexes for table `appointments`
--
ALTER TABLE `appointments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `staff_id` (`staff_id`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `actor_id` (`actor_id`),
  ADD KEY `entity_type` (`entity_type`),
  ADD KEY `entity_id` (`entity_id`),
  ADD KEY `action` (`action`);

--
-- Indexes for table `budgets`
--
ALTER TABLE `budgets`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `chats`
--
ALTER TABLE `chats`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sender_id` (`sender_id`),
  ADD KEY `receiver_id` (`receiver_id`);

--
-- Indexes for table `design_approvals`
--
ALTER TABLE `design_approvals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `service_provider_id` (`service_provider_id`);

--
-- Indexes for table `dss_configurations`
--
ALTER TABLE `dss_configurations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `config_key` (`config_key`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `financial_transactions`
--
ALTER TABLE `financial_transactions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `job_schedule`
--
ALTER TABLE `job_schedule`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `employee_id` (`employee_id`);

--
-- Indexes for table `material_orders`
--
ALTER TABLE `material_orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `material_id` (`material_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `order_id` (`order_id`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `shop_id` (`shop_id`),
  ADD KEY `assigned_to` (`assigned_to`);

--
-- Indexes for table `order_invoices`
--
ALTER TABLE `order_invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoice_number` (`invoice_number`),
  ADD KEY `order_id` (`order_id`);

--
-- Indexes for table `order_fulfillments`
--
ALTER TABLE `order_fulfillments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_id` (`order_id`);

--
-- Indexes for table `order_fulfillment_history`
--
ALTER TABLE `order_fulfillment_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fulfillment_id` (`fulfillment_id`);

--
-- Indexes for table `order_photos`
--
ALTER TABLE `order_photos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `employee_id` (`employee_id`);

--
-- Indexes for table `order_status_history`
--
ALTER TABLE `order_status_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `employee_id` (`employee_id`);

--
-- Indexes for table `otp_verifications`
--
ALTER TABLE `otp_verifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `shop_id` (`shop_id`);

--
-- Indexes for table `payment_receipts`
--
ALTER TABLE `payment_receipts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `receipt_number` (`receipt_number`),
  ADD KEY `payment_id` (`payment_id`),
  ADD KEY `issued_by` (`issued_by`);

--
-- Indexes for table `payment_refunds`
--
ALTER TABLE `payment_refunds`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `payment_id` (`payment_id`),
  ADD KEY `requested_by` (`requested_by`),
  ADD KEY `refunded_by` (`refunded_by`);

--
-- Indexes for table `payroll`
--
ALTER TABLE `payroll`
  ADD PRIMARY KEY (`id`),
  ADD KEY `employee_id` (`employee_id`);

--
-- Indexes for table `raw_materials`
--
ALTER TABLE `raw_materials`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `services`
--
ALTER TABLE `services`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `service_providers`
--
ALTER TABLE `service_providers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`),
  ADD KEY `verified_by` (`verified_by`);

--
-- Indexes for table `service_requests`
--
ALTER TABLE `service_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `assigned_to` (`assigned_to`);

--
-- Indexes for table `shops`
--
ALTER TABLE `shops`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `owner_id` (`owner_id`);

--
-- Indexes for table `shop_employees`
--
ALTER TABLE `shop_employees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`),
  ADD KEY `shop_id` (`shop_id`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`),
  ADD KEY `updated_by` (`updated_by`);

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
-- AUTO_INCREMENT for table `activities`
--
ALTER TABLE `activities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `analytics_data`
--
ALTER TABLE `analytics_data`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `appointments`
--
ALTER TABLE `appointments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `budgets`
--
ALTER TABLE `budgets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chats`
--
ALTER TABLE `chats`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `design_approvals`
--
ALTER TABLE `design_approvals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dss_configurations`
--
ALTER TABLE `dss_configurations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `financial_transactions`
--
ALTER TABLE `financial_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `job_schedule`
--
ALTER TABLE `job_schedule`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `material_orders`
--
ALTER TABLE `material_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=42;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `order_fulfillments`
--
ALTER TABLE `order_fulfillments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `order_fulfillment_history`
--
ALTER TABLE `order_fulfillment_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `order_invoices`
--
ALTER TABLE `order_invoices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order_photos`
--
ALTER TABLE `order_photos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `order_status_history`
--
ALTER TABLE `order_status_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otp_verifications`
--
ALTER TABLE `otp_verifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_receipts`
--
ALTER TABLE `payment_receipts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_refunds`
--
ALTER TABLE `payment_refunds`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payroll`
--
ALTER TABLE `payroll`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `raw_materials`
--
ALTER TABLE `raw_materials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `services`
--
ALTER TABLE `services`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `service_providers`
--
ALTER TABLE `service_providers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `service_requests`
--
ALTER TABLE `service_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `shops`
--
ALTER TABLE `shops`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `shop_employees`
--
ALTER TABLE `shop_employees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `analytics_data`
--
ALTER TABLE `analytics_data`
  ADD CONSTRAINT `analytics_data_ibfk_1` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`);

--
-- Constraints for table `appointments`
--
ALTER TABLE `appointments`
  ADD CONSTRAINT `appointments_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `appointments_ibfk_2` FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `chats`
--
ALTER TABLE `chats`
  ADD CONSTRAINT `chats_ibfk_1` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `chats_ibfk_2` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `design_approvals`
--
ALTER TABLE `design_approvals`
  ADD CONSTRAINT `design_approvals_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  ADD CONSTRAINT `design_approvals_ibfk_2` FOREIGN KEY (`service_provider_id`) REFERENCES `service_providers` (`id`);

--
-- Constraints for table `dss_configurations`
--
ALTER TABLE `dss_configurations`
  ADD CONSTRAINT `dss_configurations_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `employees`
--
ALTER TABLE `employees`
  ADD CONSTRAINT `employees_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `job_schedule`
--
ALTER TABLE `job_schedule`
  ADD CONSTRAINT `job_schedule_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  ADD CONSTRAINT `job_schedule_ibfk_2` FOREIGN KEY (`employee_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `material_orders`
--
ALTER TABLE `material_orders`
  ADD CONSTRAINT `material_orders_ibfk_1` FOREIGN KEY (`material_id`) REFERENCES `raw_materials` (`id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`);

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `orders_ibfk_2` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`),
  ADD CONSTRAINT `orders_ibfk_3` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`);

--
-- Constraints for table `order_photos`
--
ALTER TABLE `order_photos`
  ADD CONSTRAINT `order_photos_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  ADD CONSTRAINT `order_photos_ibfk_2` FOREIGN KEY (`employee_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `order_status_history`
--
ALTER TABLE `order_status_history`
  ADD CONSTRAINT `order_status_history_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  ADD CONSTRAINT `order_status_history_ibfk_2` FOREIGN KEY (`employee_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `otp_verifications`
--
ALTER TABLE `otp_verifications`
  ADD CONSTRAINT `otp_verifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `payroll`
--
ALTER TABLE `payroll`
  ADD CONSTRAINT `payroll_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`);

--
-- Constraints for table `service_providers`
--
ALTER TABLE `service_providers`
  ADD CONSTRAINT `service_providers_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `service_providers_ibfk_2` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `service_requests`
--
ALTER TABLE `service_requests`
  ADD CONSTRAINT `service_requests_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `service_requests_ibfk_2` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`);

--
-- Constraints for table `shops`
--
ALTER TABLE `shops`
  ADD CONSTRAINT `shops_ibfk_1` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `shop_employees`
--
ALTER TABLE `shop_employees`
  ADD CONSTRAINT `shop_employees_ibfk_1` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`),
  ADD CONSTRAINT `shop_employees_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
