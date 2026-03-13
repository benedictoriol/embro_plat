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
SET time_zone = "+08:00";


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
  `meta_json` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
  `order_id` int(11) DEFAULT NULL,
  `thread_key` varchar(191) DEFAULT NULL,
  `thread_topic` varchar(100) DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `is_thread_seed` tinyint(1) NOT NULL DEFAULT 0,
  `message` text NOT NULL,
  `read_status` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- --------------------------------------------------------

--
-- Table structure for table `digitized_designs`
--

CREATE TABLE `digitized_designs` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `digitizer_id` int(11) NOT NULL,
  `stitch_file_path` varchar(255) DEFAULT NULL,
  `stitch_file_name` varchar(255) DEFAULT NULL,
  `stitch_file_ext` varchar(20) DEFAULT NULL,
  `stitch_file_size_bytes` bigint(20) DEFAULT NULL,
  `stitch_file_mime` varchar(120) DEFAULT NULL,
  `stitch_count` int(11) DEFAULT NULL,
  `thread_colors` int(11) DEFAULT NULL,
  `estimated_thread_length` decimal(12,2) DEFAULT NULL,
  `width_px` int(11) DEFAULT NULL,
  `height_px` int(11) DEFAULT NULL,
  `detected_width_mm` decimal(10,2) DEFAULT NULL,
  `detected_height_mm` decimal(10,2) DEFAULT NULL,
  `suggested_width_mm` decimal(10,2) DEFAULT NULL,
  `suggested_height_mm` decimal(10,2) DEFAULT NULL,
  `scale_ratio` decimal(10,4) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `approved_at` timestamp NULL DEFAULT NULL
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
-- Table structure for table `design_projects`
--

CREATE TABLE `design_projects` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `shop_id` int(11) NOT NULL,
  `title` varchar(150) DEFAULT NULL,
  `status` enum('draft','submitted','archived') DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `design_versions`
--

CREATE TABLE `design_versions` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `version_no` int(11) NOT NULL,
  `design_json` longtext NOT NULL,
  `preview_file` varchar(255) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- --------------------------------------------------------

--
-- Table structure for table `saved_designs`
--

CREATE TABLE `saved_designs` (
  `id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `client_user_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `design_name` varchar(150) NOT NULL,
  `design_json` longtext NOT NULL,
  `preview_image_path` varchar(255) DEFAULT NULL,
  `application_mode` enum('patch','embroidery_preview') NOT NULL DEFAULT 'embroidery_preview',
  `patch_style_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`patch_style_json`)),
  `version_number` int(11) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `design_placements`
--

CREATE TABLE `design_placements` (
  `id` int(11) NOT NULL,
  `design_id` int(11) NOT NULL,
  `zone_id` int(11) DEFAULT NULL,
  `model_key` varchar(80) NOT NULL DEFAULT 'tshirt',
  `placement_key` varchar(80) NOT NULL,
  `placement_type` enum('patch','embroidery','print_preview') NOT NULL DEFAULT 'embroidery',
  `application_mode` enum('patch','embroidery_preview') NOT NULL DEFAULT 'embroidery_preview',
  `patch_style_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`patch_style_json`)),
  `transform_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`transform_json`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `placement_zones`
--

CREATE TABLE `placement_zones` (
  `id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `model_key` varchar(80) NOT NULL,
  `zone_key` varchar(120) NOT NULL,
  `label` varchar(120) NOT NULL,
  `placement_type` enum('patch','embroidery','print_preview') NOT NULL DEFAULT 'embroidery',
  `width_limit` decimal(10,2) NOT NULL,
  `height_limit` decimal(10,2) NOT NULL,
  `uv_meta_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`uv_meta_json`)),
  `transform_defaults_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`transform_defaults_json`)),
  `active` tinyint(1) NOT NULL DEFAULT 1,
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

-- --------------------------------------------------------

--
-- Table structure for table `staffs`
--

CREATE TABLE `staffs` (
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
-- Table structure for table `hiring_posts`
--

CREATE TABLE `hiring_posts` (
  `id` int(11) NOT NULL,
  `shop_id` int(11) NOT NULL,
  `created_by` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('draft','live','closed','expired') DEFAULT 'live',
  `expires_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

-- Table structure for table `client_community_posts`
CREATE TABLE `client_community_posts` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `category` varchar(50) NOT NULL,
  `description` text NOT NULL,
  `preferred_price` decimal(10,2) DEFAULT NULL,
  `desired_quantity` int(11) DEFAULT NULL,
  `target_date` date DEFAULT NULL,
   `image_path` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'open',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `community_post_comments` (
  `id` int(11) NOT NULL,
  `post_id` int(11) NOT NULL,
  `commenter_user_id` int(11) NOT NULL,
  `commenter_role` enum('client','shop') NOT NULL,
  `shop_id` int(11) DEFAULT NULL,
  `comment_text` text NOT NULL,
  `negotiated_price` decimal(10,2) DEFAULT NULL,
  `negotiated_quantity` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `job_schedule`
--

CREATE TABLE `job_schedule` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `staff_id` int(11) NOT NULL,
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
  `shop_id` int(11) NOT NULL,
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

-- --------------------------------------------------------

--
-- Table structure for table `notification_preferences`
--

CREATE TABLE `notification_preferences` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `event_key` varchar(100) NOT NULL,
  `channel` enum('in_app') DEFAULT 'in_app',
  `enabled` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
  `quote_status` enum('draft','sent','approved','rejected','expired','requested','issued') NOT NULL DEFAULT 'draft',
  `quote_approved_at` datetime DEFAULT NULL,
  `status` enum('pending','accepted','digitizing','production_pending','production','qc_pending','production_rework','ready_for_delivery','delivered','completed','cancelled') DEFAULT 'pending',
  `assigned_to` int(11) DEFAULT NULL,
  `progress` int(11) DEFAULT 0,
  `scheduled_date` date DEFAULT NULL,
  `estimated_completion` datetime DEFAULT NULL,
  `shop_notes` text DEFAULT NULL,
  `design_file` varchar(255) DEFAULT NULL,
  `width_px` int(11) DEFAULT NULL,
  `height_px` int(11) DEFAULT NULL,
  `detected_width_mm` decimal(10,2) DEFAULT NULL,
  `detected_height_mm` decimal(10,2) DEFAULT NULL,
  `fits_cap_area` tinyint(1) DEFAULT NULL,
  `suggested_width_mm` decimal(10,2) DEFAULT NULL,
  `suggested_height_mm` decimal(10,2) DEFAULT NULL,
  `scale_ratio` decimal(10,4) DEFAULT NULL,
  `design_version_id` int(11) DEFAULT NULL,
  `design_approved` tinyint(1) DEFAULT 0,
  `rating` tinyint(1) DEFAULT NULL,
  `payment_status` enum('unpaid','pending_verification','partially_paid','paid','failed','refunded','cancelled') DEFAULT 'unpaid',
  `payment_verified_at` datetime DEFAULT NULL,
  `payment_release_status` enum('none','awaiting_confirmation','held','release_pending','released','refunded') DEFAULT 'none',
  `payment_released_at` datetime DEFAULT NULL,
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
  `required_downpayment_amount` decimal(10,2) DEFAULT NULL,
  `delivery_confirmed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
  `delivery_method` varchar(100) DEFAULT NULL,
  `status` enum('pending','ready_for_pickup','out_for_delivery','delivered','claimed','failed') DEFAULT 'pending',
  `courier` varchar(100) DEFAULT NULL,
  `tracking_number` varchar(120) DEFAULT NULL,
  `pickup_location` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `ready_at` datetime DEFAULT NULL,
  `shipped_at` datetime DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `claimed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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

-- --------------------------------------------------------
--
-- Table structure for table `order_photos`
--

CREATE TABLE `order_photos` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `staff_id` int(11) NOT NULL,
  `photo_url` varchar(255) NOT NULL,
  `caption` varchar(255) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `order_status_history`
--

CREATE TABLE `order_status_history` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `staff_id` int(11) DEFAULT NULL,
  `status` enum('pending','accepted','digitizing','production_pending','production','qc_pending','production_rework','ready_for_delivery','delivered','completed','cancelled') NOT NULL,
  `progress` int(11) DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------


--
-- Table structure for table `order_progress_logs`
--

CREATE TABLE IF NOT EXISTS `order_progress_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `order_id` int(11) NOT NULL,
  `status` varchar(50) NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `actor_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  KEY `idx_order_progress_logs_order` (`order_id`),
  KEY `idx_order_progress_logs_status` (`status`),
  KEY `idx_order_progress_logs_actor` (`actor_user_id`)
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
  `payer_user_id` int(11) DEFAULT NULL,
  `expected_amount` decimal(10,2) DEFAULT NULL,
  `paid_amount` decimal(10,2) DEFAULT NULL,
  `proof_file` varchar(255) DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `provider_transaction_id` varchar(120) DEFAULT NULL,
  `status` enum('unpaid','pending_verification','partially_paid','paid','failed','refunded','cancelled') DEFAULT 'unpaid',
  `verified_by` int(11) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment_attempts`
--

CREATE TABLE `payment_attempts` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `payment_id` int(11) DEFAULT NULL,
  `client_id` int(11) NOT NULL,
  `shop_id` int(11) NOT NULL,
  `gateway` varchar(50) NOT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `reference_number` varchar(120) NOT NULL,
  `checkout_url` text DEFAULT NULL,
  `status` enum('created','pending','paid','failed','expired','cancelled') NOT NULL DEFAULT 'created',
  `expires_at` datetime DEFAULT NULL,
  `gateway_payload_json` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment_status_timeline`
--

CREATE TABLE `payment_status_timeline` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `payment_id` int(11) DEFAULT NULL,
  `payment_attempt_id` int(11) DEFAULT NULL,
  `actor_user_id` int(11) DEFAULT NULL,
  `actor_role` varchar(30) DEFAULT NULL,
  `status` varchar(50) NOT NULL,
  `notes` text DEFAULT NULL,
  `payload_json` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
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
  `staff_id` int(11) NOT NULL,
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
-- Table structure for table `shifts`
--

CREATE TABLE `shifts` (
  `id` int(11) NOT NULL,
  `shop_id` int(11) NOT NULL,
  `staff_user_id` int(11) NOT NULL,
  `start_time` datetime NOT NULL,
  `end_time` datetime NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance_logs`
--

CREATE TABLE `attendance_logs` (
  `id` int(11) NOT NULL,
  `shop_id` int(11) NOT NULL,
  `staff_user_id` int(11) NOT NULL,
  `clock_in` datetime DEFAULT NULL,
  `clock_out` datetime DEFAULT NULL,
  `method` enum('manual','self') DEFAULT 'self',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `raw_materials`
--

CREATE TABLE `raw_materials` (
  `id` int(11) NOT NULL,
  `shop_id` int(11) NOT NULL,
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
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL,
  `shop_id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `contact` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `rating` decimal(3,1) DEFAULT NULL,
  `status` enum('active','inactive','preferred','watchlist') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
--
-- Table structure for table `purchase_requests`
--

CREATE TABLE `purchase_requests` (
  `id` int(11) NOT NULL,
  `shop_id` int(11) NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `status` enum('draft','pending','approved','closed','cancelled') DEFAULT 'draft',
  `created_by` int(11) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
--
-- Table structure for table `purchase_request_items`
--

CREATE TABLE `purchase_request_items` (
  `id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `material_id` int(11) NOT NULL,
  `qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `unit_cost` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
--
-- Table structure for table `storage_locations`
--

CREATE TABLE `storage_locations` (
  `id` int(11) NOT NULL,
  `shop_id` int(11) NOT NULL,
  `code` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
--
-- Table structure for table `stock_placements`
--

CREATE TABLE `stock_placements` (
  `id` int(11) NOT NULL,
  `location_id` int(11) NOT NULL,
  `material_id` int(11) NOT NULL,
  `qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
--
-- Table structure for table `warehouse_stock_management`
--

CREATE TABLE `warehouse_stock_management` (
  `id` int(11) NOT NULL,
  `shop_id` int(11) NOT NULL,
  `material_id` int(11) NOT NULL,
  `opening_stock_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `warehouse_location` varchar(120) NOT NULL,
  `reorder_level` decimal(10,2) NOT NULL DEFAULT 0.00,
  `reorder_quantity` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
--
-- Table structure for table `finished_goods`
--

CREATE TABLE `finished_goods` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `shop_id` int(11) NOT NULL,
  `storage_location_id` int(11) DEFAULT NULL,
  `status` enum('stored','ready','released') DEFAULT 'stored',
  `stored_at` datetime DEFAULT NULL,
  `released_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
--
-- Table structure for table `inventory_transactions`
--

CREATE TABLE `inventory_transactions` (
  `id` int(11) NOT NULL,
  `shop_id` int(11) NOT NULL,
  `material_id` int(11) NOT NULL,
  `type` enum('issue','return','adjust','move','in','out') NOT NULL,
  `qty` decimal(10,2) NOT NULL,
  `ref_type` varchar(50) DEFAULT NULL,
  `ref_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
--
-- Audit immutability and sensitive change tracking
--

DELIMITER //

CREATE TRIGGER prevent_audit_logs_update
BEFORE UPDATE ON audit_logs
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit logs are immutable';
END//

CREATE TRIGGER prevent_audit_logs_delete
BEFORE DELETE ON audit_logs
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit logs are immutable';
END//

CREATE TRIGGER audit_payroll_update
BEFORE UPDATE ON payroll
FOR EACH ROW
BEGIN
  INSERT INTO audit_logs (actor_id, actor_role, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent)
  VALUES (
    NULL,
    'system',
    'payroll_updated',
    'payroll',
    OLD.id,
    JSON_OBJECT(
      'basic_salary', OLD.basic_salary,
      'allowances', OLD.allowances,
      'deductions', OLD.deductions,
      'net_salary', OLD.net_salary,
      'status', OLD.status,
      'paid_at', OLD.paid_at
    ),
    JSON_OBJECT(
      'basic_salary', NEW.basic_salary,
      'allowances', NEW.allowances,
      'deductions', NEW.deductions,
      'net_salary', NEW.net_salary,
      'status', NEW.status,
      'paid_at', NEW.paid_at
    ),
    NULL,
    NULL
  );
END//

CREATE TRIGGER audit_raw_materials_update
BEFORE UPDATE ON raw_materials
FOR EACH ROW
BEGIN
  INSERT INTO audit_logs (actor_id, actor_role, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent)
  VALUES (
    NULL,
    'system',
    'inventory_adjusted',
    'raw_materials',
    OLD.id,
    JSON_OBJECT(
      'current_stock', OLD.current_stock,
      'unit_cost', OLD.unit_cost,
      'status', OLD.status
    ),
    JSON_OBJECT(
      'current_stock', NEW.current_stock,
      'unit_cost', NEW.unit_cost,
      'status', NEW.status
    ),
    NULL,
    NULL
  );
END//

DELIMITER ;

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
  `profile_completed_at` timestamp NULL DEFAULT NULL,
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

INSERT INTO `shops` (`id`, `owner_id`, `shop_name`, `shop_description`, `address`, `phone`, `email`, `business_permit`, `permit_file`, `logo`, `opening_time`, `closing_time`, `operating_days`, `service_settings`, `pricing_settings`, `status`, `profile_completed_at`, `rejection_reason`, `rejected_at`, `rating`, `total_orders`, `total_earnings`, `created_at`, `updated_at`) VALUES
(1, 4, 'Thread & Needle Studio', 'Custom embroidery and uniform design services.', '123 Market Street', '09171234567', 'owner@embroidery.com', NULL, NULL, NULL, '08:00:00', '18:00:00', '[1,2,3,4,5,6]', '[\"T-shirt Embroidery\",\"Logo Embroidery\",\"Cap Embroidery\",\"Bag Embroidery\",\"Custom\"]', '{\"base_prices\":{\"T-shirt Embroidery\":180,\"Logo Embroidery\":160,\"Cap Embroidery\":150,\"Bag Embroidery\":200,\"Custom\":200},\"complexity_multipliers\":{\"Simple\":1,\"Standard\":1.15,\"Complex\":1.35},\"rush_fee_percent\":25,\"add_ons\":{\"Metallic Thread\":50,\"3D Puff\":75,\"Extra Color\":25,\"Applique\":60}}', 'active', '2026-01-22 12:20:00', NULL, NULL, 4.50, 5, 6400.00, '2026-01-22 12:20:00', '2026-01-22 12:20:00');
-- --------------------------------------------------------

--
-- Table structure for table `shop_portfolio`
--

CREATE TABLE `shop_portfolio` (
  `id` int(11) NOT NULL,
  `shop_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `image_path` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shop_staffs`
--

CREATE TABLE `shop_staffs` (
  `id` int(11) NOT NULL,
  `shop_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `staff_role` enum('hr','staff') DEFAULT 'staff',
  `position` varchar(100) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `salary` decimal(12,2) DEFAULT NULL,
  `employment_status` enum('active','inactive','on_leave') NOT NULL DEFAULT 'active',
  `permissions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`permissions`)),
  `availability_days` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`availability_days`)),
  `availability_start` time DEFAULT NULL,
  `availability_end` time DEFAULT NULL,
  `max_active_orders` int(11) DEFAULT 3,
  `hired_date` date DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL,
  `setting_group` varchar(100) NOT NULL DEFAULT 'general',
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `value_type` enum('string','int','float','bool','json') NOT NULL DEFAULT 'string',
  `description` text DEFAULT NULL,
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
  `role` enum('sys_admin','staff','owner','hr','client') DEFAULT 'client',
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
(2, 'Staff Member', 'staff@embroidery.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'staff', 'active', '2026-01-19 15:28:09', 0, NULL, 0, '2026-01-31 09:53:28'),
(3, 'Customer', 'customer@embroidery.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'client', 'active', '2026-01-19 15:28:09', 0, NULL, 0, '2026-01-27 20:53:59'),
(4, 'Shop Owner', 'owner@embroidery.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'owner', 'active', '2026-01-19 15:28:09', 0, NULL, 0, '2026-01-27 19:15:00'),
(5, 'HR', 'hr@embroidery.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'hr', 'active', '2026-01-31 14:10:00', 0, NULL, 0, NULL);

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
  ADD KEY `receiver_id` (`receiver_id`),
  ADD KEY `idx_chats_order_id` (`order_id`),
  ADD KEY `idx_chats_thread_key` (`thread_key`);


--
-- Indexes for table `digitized_designs`
--
ALTER TABLE `digitized_designs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_digitized_order_id` (`order_id`),
  ADD KEY `idx_digitized_digitizer_id` (`digitizer_id`);

--
-- Indexes for table `design_approvals`
--
ALTER TABLE `design_approvals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `service_provider_id` (`service_provider_id`);

--
-- Indexes for table `design_projects`
--
ALTER TABLE `design_projects`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `shop_id` (`shop_id`);

--
-- Indexes for table `design_versions`
--
ALTER TABLE `design_versions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_project_version` (`project_id`,`version_no`),
  ADD KEY `created_by` (`created_by`);


--
-- Indexes for table `saved_designs`
--
ALTER TABLE `saved_designs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_saved_design_version` (`client_user_id`,`order_id`,`version_number`),
  ADD KEY `idx_saved_designs_order` (`order_id`),
  ADD KEY `idx_saved_designs_client` (`client_user_id`),
  ADD KEY `idx_saved_designs_product` (`product_id`);

--
-- Indexes for table `design_placements`
--
ALTER TABLE `design_placements`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_design_placement` (`design_id`,`placement_key`),
  ADD KEY `idx_design_placements_zone` (`zone_id`),
  ADD KEY `idx_design_placements_model` (`model_key`);

--
-- Indexes for table `placement_zones`
--
ALTER TABLE `placement_zones`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_placement_zone` (`product_id`,`model_key`,`zone_key`,`placement_type`),
  ADD KEY `idx_placement_zones_model` (`model_key`),
  ADD KEY `idx_placement_zones_active` (`active`);

--
-- Indexes for table `dss_configurations`
--
ALTER TABLE `dss_configurations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `config_key` (`config_key`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `staffs`
--
ALTER TABLE `staffs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `financial_transactions`
--
ALTER TABLE `financial_transactions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `hiring_posts`
--
ALTER TABLE `hiring_posts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `shop_id` (`shop_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_hiring_posts_shop_status_expires` (`shop_id`,`status`,`expires_at`);

-- Indexes for table `client_community_posts`
ALTER TABLE `client_community_posts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_client_community_posts_client` (`client_id`),
  ADD KEY `idx_client_community_posts_status` (`status`);

-- Indexes for table `community_post_comments`
ALTER TABLE `community_post_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_community_post_comments_post` (`post_id`),
  ADD KEY `idx_community_post_comments_shop` (`shop_id`),
  ADD KEY `idx_community_post_comments_user` (`commenter_user_id`);
--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `shop_id` (`shop_id`),
  ADD KEY `assigned_to` (`assigned_to`),
  ADD KEY `design_version_id` (`design_version_id`),
  ADD KEY `idx_orders_shop_status_created` (`shop_id`,`status`,`created_at`),
  ADD KEY `idx_orders_assigned_status` (`assigned_to`,`status`);

--
-- Indexes for table `order_invoices`
--
ALTER TABLE `order_invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoice_number` (`invoice_number`),
  ADD KEY `order_id` (`order_id`);

--
-- Indexes for table `job_schedule`
--
ALTER TABLE `job_schedule`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `staff_id` (`staff_id`);

--
-- Indexes for table `material_orders`
--
ALTER TABLE `material_orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `material_id` (`material_id`),
  ADD KEY `idx_material_orders_shop` (`shop_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `idx_notifications_user_created` (`user_id`,`created_at`);

--
-- Indexes for table `notification_preferences`
--
ALTER TABLE `notification_preferences`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

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
  ADD KEY `staff_id` (`staff_id`);

--
-- Indexes for table `order_status_history`
--
ALTER TABLE `order_status_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `staff_id` (`staff_id`);

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
  ADD KEY `staff_id` (`staff_id`);

--
-- Indexes for table `shifts`
--
ALTER TABLE `shifts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `shop_id` (`shop_id`),
  ADD KEY `staff_user_id` (`staff_user_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `attendance_logs`
--
ALTER TABLE `attendance_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `shop_id` (`shop_id`),
  ADD KEY `staff_user_id` (`staff_user_id`);

--
-- Indexes for table `raw_materials`
--
ALTER TABLE `raw_materials`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_raw_materials_shop` (`shop_id`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_suppliers_shop` (`shop_id`);

--
-- Indexes for table `purchase_requests`
--
ALTER TABLE `purchase_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_purchase_requests_shop` (`shop_id`),
  ADD KEY `idx_purchase_requests_supplier` (`supplier_id`);

--
-- Indexes for table `purchase_request_items`
--
ALTER TABLE `purchase_request_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_request_items_request` (`request_id`),
  ADD KEY `idx_request_items_material` (`material_id`);

--
-- Indexes for table `storage_locations`
--
ALTER TABLE `storage_locations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_storage_location_code` (`shop_id`,`code`);

--
-- Indexes for table `stock_placements`
--
ALTER TABLE `stock_placements`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_location_material` (`location_id`,`material_id`),
  ADD KEY `idx_stock_material` (`material_id`);

--
-- Indexes for table `finished_goods`
--
ALTER TABLE `finished_goods`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_finished_goods_order` (`order_id`),
  ADD KEY `idx_finished_goods_shop` (`shop_id`),
  ADD KEY `idx_finished_goods_location` (`storage_location_id`);

--
-- Indexes for table `inventory_transactions`
--
ALTER TABLE `inventory_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_inventory_shop` (`shop_id`),
  ADD KEY `idx_inventory_material` (`material_id`),
  ADD KEY `idx_inventory_type` (`type`);

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
-- Indexes for table `shop_portfolio`
--
ALTER TABLE `shop_portfolio`
  ADD PRIMARY KEY (`id`),
  ADD KEY `shop_id` (`shop_id`);

--
-- Indexes for table `shop_staffs`
--
ALTER TABLE `shop_staffs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`),
  ADD KEY `shop_id` (`shop_id`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_system_settings_group_key` (`setting_group`,`setting_key`),
  ADD KEY `idx_system_settings_key` (`setting_key`),
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
-- AUTO_INCREMENT for table `digitized_designs`
--
ALTER TABLE `digitized_designs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `design_approvals`
--
ALTER TABLE `design_approvals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `design_projects`
--
ALTER TABLE `design_projects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `design_versions`
--
ALTER TABLE `design_versions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


--
-- AUTO_INCREMENT for table `saved_designs`
--
ALTER TABLE `saved_designs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `design_placements`
--
ALTER TABLE `design_placements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `placement_zones`
--
ALTER TABLE `placement_zones`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dss_configurations`
--
ALTER TABLE `dss_configurations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `staffs`
--
ALTER TABLE `staffs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `financial_transactions`
--
ALTER TABLE `financial_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hiring_posts`
--
ALTER TABLE `hiring_posts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- AUTO_INCREMENT for table `client_community_posts`
ALTER TABLE `client_community_posts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- AUTO_INCREMENT for table `community_post_comments`
ALTER TABLE `community_post_comments`
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
-- AUTO_INCREMENT for table `notification_preferences`
--
ALTER TABLE `notification_preferences`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

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
-- AUTO_INCREMENT for table `shifts`
--
ALTER TABLE `shifts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance_logs`
--
ALTER TABLE `attendance_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `raw_materials`
--
ALTER TABLE `raw_materials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_requests`
--
ALTER TABLE `purchase_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_request_items`
--
ALTER TABLE `purchase_request_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `storage_locations`
--
ALTER TABLE `storage_locations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_placements`
--
ALTER TABLE `stock_placements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `finished_goods`
--
ALTER TABLE `finished_goods`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_transactions`
--
ALTER TABLE `inventory_transactions`
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
-- AUTO_INCREMENT for table `shop_portfolio`
--
ALTER TABLE `shop_portfolio`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shop_staffs`
--
ALTER TABLE `shop_staffs`
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
  ADD CONSTRAINT `chats_ibfk_2` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `chats_ibfk_3` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL;


--
-- Constraints for table `digitized_designs`
--
ALTER TABLE `digitized_designs`
  ADD CONSTRAINT `fk_digitized_designs_digitizer` FOREIGN KEY (`digitizer_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_digitized_designs_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `design_approvals`
--
ALTER TABLE `design_approvals`
  ADD CONSTRAINT `design_approvals_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  ADD CONSTRAINT `design_approvals_ibfk_2` FOREIGN KEY (`service_provider_id`) REFERENCES `service_providers` (`id`);

--
-- Constraints for table `design_projects`
--
ALTER TABLE `design_projects`
  ADD CONSTRAINT `design_projects_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `design_projects_ibfk_2` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`);

--
-- Constraints for table `design_versions`
--
ALTER TABLE `design_versions`
  ADD CONSTRAINT `design_versions_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `design_projects` (`id`),
  ADD CONSTRAINT `design_versions_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);


--
-- Constraints for table `saved_designs`
--
ALTER TABLE `saved_designs`
  ADD CONSTRAINT `saved_designs_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `saved_designs_ibfk_2` FOREIGN KEY (`client_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `design_placements`
--
ALTER TABLE `design_placements`
  ADD CONSTRAINT `design_placements_ibfk_1` FOREIGN KEY (`design_id`) REFERENCES `saved_designs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `design_placements_ibfk_2` FOREIGN KEY (`zone_id`) REFERENCES `placement_zones` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `placement_zones`
--
ALTER TABLE `placement_zones`
  ADD CONSTRAINT `placement_zones_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `services` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `dss_configurations`
--
ALTER TABLE `dss_configurations`
  ADD CONSTRAINT `dss_configurations_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `staffs`
--
ALTER TABLE `staffs`
  ADD CONSTRAINT `staffs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `hiring_posts`
--
ALTER TABLE `hiring_posts`
  ADD CONSTRAINT `hiring_posts_ibfk_1` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`),
  ADD CONSTRAINT `hiring_posts_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

-- Constraints for table `client_community_posts`
ALTER TABLE `client_community_posts`
  ADD CONSTRAINT `client_community_posts_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`);

ALTER TABLE `community_post_comments`
  ADD CONSTRAINT `community_post_comments_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `client_community_posts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `community_post_comments_ibfk_2` FOREIGN KEY (`commenter_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `community_post_comments_ibfk_3` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE SET NULL;
--
-- Constraints for table `job_schedule`
--
ALTER TABLE `job_schedule`
  ADD CONSTRAINT `job_schedule_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  ADD CONSTRAINT `job_schedule_ibfk_2` FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`);

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
-- Constraints for table `notification_preferences`
--
ALTER TABLE `notification_preferences`
  ADD CONSTRAINT `notification_preferences_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `orders_ibfk_2` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`),
  ADD CONSTRAINT `orders_ibfk_3` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `orders_ibfk_4` FOREIGN KEY (`design_version_id`) REFERENCES `saved_designs` (`id`);

--
-- Constraints for table `order_progress_logs`
--
ALTER TABLE `order_progress_logs`
  ADD CONSTRAINT `fk_order_progress_logs_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_order_progress_logs_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `order_photos`
--
ALTER TABLE `order_photos`
  ADD CONSTRAINT `order_photos_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  ADD CONSTRAINT `order_photos_ibfk_2` FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `order_status_history`
--
ALTER TABLE `order_status_history`
  ADD CONSTRAINT `order_status_history_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  ADD CONSTRAINT `order_status_history_ibfk_2` FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `otp_verifications`
--
ALTER TABLE `otp_verifications`
  ADD CONSTRAINT `otp_verifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `payroll`
--
ALTER TABLE `payroll`
  ADD CONSTRAINT `payroll_ibfk_1` FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `shifts`
--
ALTER TABLE `shifts`
  ADD CONSTRAINT `shifts_ibfk_1` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`),
  ADD CONSTRAINT `shifts_ibfk_2` FOREIGN KEY (`staff_user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `shifts_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `attendance_logs`
--
ALTER TABLE `attendance_logs`
  ADD CONSTRAINT `attendance_logs_ibfk_1` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`),
  ADD CONSTRAINT `attendance_logs_ibfk_2` FOREIGN KEY (`staff_user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD CONSTRAINT `suppliers_ibfk_1` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`);

--
-- Constraints for table `purchase_requests`
--
ALTER TABLE `purchase_requests`
  ADD CONSTRAINT `purchase_requests_ibfk_1` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`),
  ADD CONSTRAINT `purchase_requests_ibfk_2` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  ADD CONSTRAINT `purchase_requests_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `purchase_requests_ibfk_4` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `purchase_request_items`
--
ALTER TABLE `purchase_request_items`
  ADD CONSTRAINT `purchase_request_items_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `purchase_requests` (`id`),
  ADD CONSTRAINT `purchase_request_items_ibfk_2` FOREIGN KEY (`material_id`) REFERENCES `raw_materials` (`id`);

--
-- Constraints for table `storage_locations`
--
ALTER TABLE `storage_locations`
  ADD CONSTRAINT `storage_locations_ibfk_1` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`);

--
-- Constraints for table `stock_placements`
--
ALTER TABLE `stock_placements`
  ADD CONSTRAINT `stock_placements_ibfk_1` FOREIGN KEY (`location_id`) REFERENCES `storage_locations` (`id`),
  ADD CONSTRAINT `stock_placements_ibfk_2` FOREIGN KEY (`material_id`) REFERENCES `raw_materials` (`id`);

--
-- Constraints for table `finished_goods`
--
ALTER TABLE `finished_goods`
  ADD CONSTRAINT `finished_goods_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  ADD CONSTRAINT `finished_goods_ibfk_2` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`),
  ADD CONSTRAINT `finished_goods_ibfk_3` FOREIGN KEY (`storage_location_id`) REFERENCES `storage_locations` (`id`);

--
-- Constraints for table `inventory_transactions`
--
ALTER TABLE `inventory_transactions`
  ADD CONSTRAINT `inventory_transactions_ibfk_1` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`),
  ADD CONSTRAINT `inventory_transactions_ibfk_2` FOREIGN KEY (`material_id`) REFERENCES `raw_materials` (`id`);

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
-- Constraints for table `shop_portfolio`
--
ALTER TABLE `shop_portfolio`
  ADD CONSTRAINT `shop_portfolio_ibfk_1` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`);

--
-- Constraints for table `shop_staffs`
--
ALTER TABLE `shop_staffs`
  ADD CONSTRAINT `shop_staffs_ibfk_1` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`),
  ADD CONSTRAINT `shop_staffs_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);


-- --------------------------------------------------------
-- Consolidated workflow tables (authoritative schema)
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `version` varchar(50) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_schema_migrations_version` (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `production_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `priority` int(11) NOT NULL DEFAULT 0,
  `estimated_duration` int(11) DEFAULT NULL,
  `scheduled_machine` varchar(100) DEFAULT NULL,
  `queue_position` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_production_queue_order_id` (`order_id`),
  KEY `idx_production_queue_priority` (`priority`),
  KEY `idx_production_queue_position` (`queue_position`),
  CONSTRAINT `fk_production_queue_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `support_tickets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `assigned_staff_id` int(11) DEFAULT NULL,
  `issue_type` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `status` enum('open','under_review','assigned','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `resolved_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_support_order_id` (`order_id`),
  KEY `idx_support_client_id` (`client_id`),
  KEY `idx_support_assigned_staff_id` (`assigned_staff_id`),
  CONSTRAINT `fk_support_ticket_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_support_ticket_client` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_support_ticket_assigned_staff` FOREIGN KEY (`assigned_staff_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `order_quality_checks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `qc_status` enum('pending','passed','failed') NOT NULL DEFAULT 'pending',
  `remarks` text DEFAULT NULL,
  `checked_by` int(11) DEFAULT NULL,
  `checked_at` timestamp NULL DEFAULT NULL,
  `attachment_url` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_order_quality_checks_order` (`order_id`),
  KEY `idx_order_quality_checks_status` (`qc_status`),
  KEY `idx_order_quality_checks_checked_by` (`checked_by`),
  CONSTRAINT `fk_order_quality_checks_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_order_quality_checks_checked_by` FOREIGN KEY (`checked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `order_quotes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `shop_id` int(11) NOT NULL,
  `owner_id` int(11) NOT NULL,
  `status` enum('draft','sent','approved','rejected','expired') NOT NULL DEFAULT 'draft',
  `quoted_price` decimal(10,2) NOT NULL,
  `base_price` decimal(10,2) DEFAULT NULL,
  `design_adjustment` decimal(10,2) DEFAULT NULL,
  `stitch_adjustment` decimal(10,2) DEFAULT NULL,
  `size_adjustment` decimal(10,2) DEFAULT NULL,
  `rush_fee` decimal(10,2) DEFAULT NULL,
  `quantity_breakdown` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`quantity_breakdown`)),
  `notes_terms` text DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `responded_at` datetime DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_order_quotes_order` (`order_id`),
  KEY `idx_order_quotes_shop` (`shop_id`),
  KEY `idx_order_quotes_status` (`status`),
  CONSTRAINT `fk_order_quotes_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_order_quotes_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_order_quotes_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `supplier_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `material_id` int(11) NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `supplier_name` varchar(150) DEFAULT NULL,
  `status` enum('draft','pending','ordered','cancelled','completed') NOT NULL DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_supplier_orders_material` (`material_id`),
  KEY `idx_supplier_orders_status` (`status`),
  CONSTRAINT `fk_supplier_orders_material` FOREIGN KEY (`material_id`) REFERENCES `raw_materials` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `order_material_reservations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `shop_id` int(11) NOT NULL,
  `material_id` int(11) NOT NULL,
  `reserved_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `consumed_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('reserved','consumed','released','cancelled') NOT NULL DEFAULT 'reserved',
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_material_reservation` (`order_id`,`material_id`),
  KEY `idx_omr_shop_status` (`shop_id`,`status`),
  CONSTRAINT `fk_omr_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_omr_material` FOREIGN KEY (`material_id`) REFERENCES `raw_materials` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `order_exceptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `exception_type` varchar(50) NOT NULL,
  `severity` enum('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `status` enum('open','in_progress','escalated','resolved','dismissed') NOT NULL DEFAULT 'open',
  `notes` text DEFAULT NULL,
  `assigned_handler_id` int(11) DEFAULT NULL,
  `escalated_at` datetime DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_order_exceptions_order` (`order_id`),
  KEY `idx_order_exceptions_type_status` (`exception_type`,`status`),
  KEY `idx_order_exceptions_assigned_handler` (`assigned_handler_id`),
  CONSTRAINT `fk_order_exceptions_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_order_exceptions_assigned_handler` FOREIGN KEY (`assigned_handler_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `content_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reporter_user_id` int(11) NOT NULL,
  `target_entity_type` varchar(50) NOT NULL,
  `target_entity_id` int(11) NOT NULL,
  `reason` varchar(150) NOT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('pending','reviewing','resolved','dismissed') NOT NULL DEFAULT 'pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_content_reports_status` (`status`),
  KEY `idx_content_reports_target` (`target_entity_type`,`target_entity_id`),
  KEY `idx_content_reports_reporter` (`reporter_user_id`),
  KEY `idx_content_reports_reviewed_by` (`reviewed_by`),
  CONSTRAINT `fk_content_reports_reporter` FOREIGN KEY (`reporter_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_content_reports_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `system_settings` (`setting_group`, `setting_key`, `setting_value`, `value_type`, `description`)
VALUES
('platform', 'schema_version', '2026.02.01', 'string', 'Current schema baseline version.'),
('notification', 'stale_quote_hours', '24', 'int', 'Hours before quote approval is considered stale.'),
('notification', 'unpaid_order_hours', '24', 'int', 'Hours before unpaid active orders receive reminder.'),
('notification', 'overdue_production_hours', '12', 'int', 'Hours past estimated completion to mark production overdue.'),
('notification', 'ready_pickup_hours', '24', 'int', 'Hours after ready-for-pickup before sending claim reminder.'),
('notification', 'overdue_order_hours', '24', 'int', 'Hours past estimated completion to trigger overdue-order alert.'),
('notification', 'reminder_cooldown_hours', '24', 'int', 'Cooldown between duplicate reminder messages.'),
('notification', 'overdue_exception_hours', '12', 'int', 'Hours before unresolved order exceptions are auto-escalated.')
ON DUPLICATE KEY UPDATE
`setting_value` = VALUES(`setting_value`),
`value_type` = VALUES(`value_type`),
`description` = VALUES(`description`);

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('2026.02.01');


-- Consolidated runtime-schema-removal migrations (2026-03-11)
-- Migration: baseline schema extracted from runtime bootstrap ensure_* fallbacks.

CREATE TABLE IF NOT EXISTS schema_migrations (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(80) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_schema_migrations_version (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS system_settings (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    setting_group VARCHAR(100) NOT NULL,
    setting_key VARCHAR(120) NOT NULL,
    setting_value TEXT DEFAULT NULL,
    value_type ENUM('string','int','float','bool','json') NOT NULL DEFAULT 'string',
    description VARCHAR(255) DEFAULT NULL,
    updated_by INT(11) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_system_setting (setting_group, setting_key),
    KEY idx_system_settings_updated_by (updated_by),
    CONSTRAINT fk_system_settings_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE shop_staffs
    ADD COLUMN IF NOT EXISTS position VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS department VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS salary DECIMAL(12,2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS employment_status ENUM('active','inactive','on_leave') NOT NULL DEFAULT 'active';

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS price DECIMAL(10,2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS width_px INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS height_px INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS detected_width_mm DECIMAL(10,2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS detected_height_mm DECIMAL(10,2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS fits_cap_area TINYINT(1) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS suggested_width_mm DECIMAL(10,2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS suggested_height_mm DECIMAL(10,2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS scale_ratio DECIMAL(10,4) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS quote_status ENUM('draft','sent','approved','rejected','expired','requested','issued') NOT NULL DEFAULT 'draft',
    ADD COLUMN IF NOT EXISTS quote_approved_at DATETIME DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS required_downpayment_amount DECIMAL(10,2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS delivery_confirmed_at DATETIME DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS estimated_completion DATETIME DEFAULT NULL;

ALTER TABLE chats
    ADD COLUMN IF NOT EXISTS order_id INT(11) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS thread_key VARCHAR(191) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS thread_topic VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS is_system TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS is_thread_seed TINYINT(1) NOT NULL DEFAULT 0,
    ADD INDEX IF NOT EXISTS idx_chats_order_id (order_id),
    ADD INDEX IF NOT EXISTS idx_chats_thread_key (thread_key);

CREATE TABLE IF NOT EXISTS digitized_designs (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id INT(11) NOT NULL,
    digitizer_id INT(11) NOT NULL,
    stitch_file_path VARCHAR(255) DEFAULT NULL,
    stitch_file_name VARCHAR(255) DEFAULT NULL,
    stitch_file_ext VARCHAR(20) DEFAULT NULL,
    stitch_file_size_bytes BIGINT(20) DEFAULT NULL,
    stitch_file_mime VARCHAR(120) DEFAULT NULL,
    stitch_count INT(11) DEFAULT NULL,
    thread_colors INT(11) DEFAULT NULL,
    estimated_thread_length DECIMAL(12,2) DEFAULT NULL,
    width_px INT(11) DEFAULT NULL,
    height_px INT(11) DEFAULT NULL,
    detected_width_mm DECIMAL(10,2) DEFAULT NULL,
    detected_height_mm DECIMAL(10,2) DEFAULT NULL,
    suggested_width_mm DECIMAL(10,2) DEFAULT NULL,
    suggested_height_mm DECIMAL(10,2) DEFAULT NULL,
    scale_ratio DECIMAL(10,4) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at TIMESTAMP NULL DEFAULT NULL,
    KEY idx_digitized_order_id (order_id),
    KEY idx_digitized_digitizer_id (digitizer_id),
    CONSTRAINT fk_digitized_designs_order FOREIGN KEY (order_id) REFERENCES orders(id),
    CONSTRAINT fk_digitized_designs_digitizer FOREIGN KEY (digitizer_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS production_queue (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id INT(11) NOT NULL,
    priority INT(11) NOT NULL DEFAULT 0,
    estimated_duration INT(11) DEFAULT NULL,
    scheduled_machine VARCHAR(100) DEFAULT NULL,
    queue_position INT(11) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_production_queue_order_id (order_id),
    KEY idx_production_queue_priority (priority),
    KEY idx_production_queue_position (queue_position),
    CONSTRAINT fk_production_queue_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS order_progress_logs (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id INT(11) NOT NULL,
    status VARCHAR(50) NOT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT DEFAULT NULL,
    actor_user_id INT(11) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_order_progress_logs_order (order_id),
    KEY idx_order_progress_logs_status (status),
    KEY idx_order_progress_logs_actor (actor_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS order_quality_checks (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id INT(11) NOT NULL,
    qc_status ENUM('pending','passed','failed') NOT NULL DEFAULT 'pending',
    remarks TEXT DEFAULT NULL,
    checked_by INT(11) DEFAULT NULL,
    checked_at TIMESTAMP NULL DEFAULT NULL,
    attachment_url VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_order_quality_checks_order (order_id),
    KEY idx_order_quality_checks_status (qc_status),
    KEY idx_order_quality_checks_checked_by (checked_by),
    CONSTRAINT fk_order_quality_checks_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_quality_checks_checked_by FOREIGN KEY (checked_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE orders MODIFY status ENUM('pending','accepted','digitizing','production_pending','production','qc_pending','production_rework','ready_for_delivery','delivered','completed','cancelled') DEFAULT 'pending';
ALTER TABLE order_status_history MODIFY status ENUM('pending','accepted','digitizing','production_pending','production','qc_pending','production_rework','ready_for_delivery','delivered','completed','cancelled') NOT NULL;
ALTER TABLE order_fulfillments
    ADD COLUMN IF NOT EXISTS delivery_method VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS shipped_at DATETIME DEFAULT NULL,
    MODIFY status ENUM('pending','ready_for_pickup','out_for_delivery','delivered','claimed','failed') DEFAULT 'pending';
ALTER TABLE order_fulfillment_history MODIFY status ENUM('pending','ready_for_pickup','out_for_delivery','delivered','claimed','failed') NOT NULL;
ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS meta_json TEXT DEFAULT NULL;


-- Migration: payment lifecycle + client/support/exception extensions.

ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS payment_method VARCHAR(50) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS payer_user_id INT(11) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS expected_amount DECIMAL(10,2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS paid_amount DECIMAL(10,2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS reference_number VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS provider_transaction_id VARCHAR(120) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS notes TEXT DEFAULT NULL;

UPDATE payments SET status = 'pending' WHERE status = 'refund_pending';
UPDATE payments SET status = 'pending' WHERE status = 'pending';
UPDATE payments SET status = 'paid' WHERE status = 'verified';
UPDATE payments SET status = 'failed' WHERE status = 'rejected';
ALTER TABLE payments MODIFY status ENUM('unpaid','pending_verification','partially_paid','paid','failed','refunded','cancelled') DEFAULT 'unpaid';
UPDATE payments SET status = 'pending_verification' WHERE status = 'pending';

UPDATE orders SET payment_status = 'pending' WHERE payment_status = 'pending';
UPDATE orders SET payment_status = 'pending' WHERE payment_status = 'refund_pending';
UPDATE orders SET payment_status = 'failed' WHERE payment_status = 'rejected';
ALTER TABLE orders MODIFY payment_status ENUM('unpaid','pending_verification','partially_paid','paid','failed','refunded','cancelled') DEFAULT 'unpaid';
UPDATE orders SET payment_status = 'pending_verification' WHERE payment_status = 'pending';
UPDATE orders SET payment_status = 'partially_paid' WHERE payment_status = 'pending_verification' AND payment_verified_at IS NOT NULL;

UPDATE payments SET payer_user_id = client_id WHERE payer_user_id IS NULL;
UPDATE payments SET expected_amount = amount WHERE expected_amount IS NULL;
UPDATE payments SET paid_amount = amount WHERE paid_amount IS NULL;
ALTER TABLE payments MODIFY proof_file VARCHAR(255) DEFAULT NULL;

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS payment_release_status ENUM('none','awaiting_confirmation','held','release_pending','released','refunded') DEFAULT 'none',
    ADD COLUMN IF NOT EXISTS payment_released_at DATETIME DEFAULT NULL;

CREATE TABLE IF NOT EXISTS payment_attempts (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id INT(11) NOT NULL,
    payment_id INT(11) DEFAULT NULL,
    client_id INT(11) NOT NULL,
    shop_id INT(11) NOT NULL,
    gateway VARCHAR(50) NOT NULL,
    payment_method VARCHAR(50) DEFAULT NULL,
    amount DECIMAL(10,2) NOT NULL,
    reference_number VARCHAR(120) NOT NULL,
    checkout_url TEXT DEFAULT NULL,
    status ENUM('created','pending','paid','failed','expired','cancelled') NOT NULL DEFAULT 'created',
    expires_at DATETIME DEFAULT NULL,
    gateway_payload_json LONGTEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_payment_attempts_order (order_id),
    KEY idx_payment_attempts_payment (payment_id),
    KEY idx_payment_attempts_reference (reference_number),
    KEY idx_payment_attempts_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS payment_status_timeline (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id INT(11) NOT NULL,
    payment_id INT(11) DEFAULT NULL,
    payment_attempt_id INT(11) DEFAULT NULL,
    actor_user_id INT(11) DEFAULT NULL,
    actor_role VARCHAR(30) DEFAULT NULL,
    status VARCHAR(50) NOT NULL,
    notes TEXT DEFAULT NULL,
    payload_json LONGTEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_payment_timeline_order (order_id),
    KEY idx_payment_timeline_payment (payment_id),
    KEY idx_payment_timeline_attempt (payment_attempt_id),
    KEY idx_payment_timeline_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS support_tickets (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id INT(11) NOT NULL,
    client_id INT(11) NOT NULL,
    assigned_staff_id INT(11) DEFAULT NULL,
    issue_type VARCHAR(100) NOT NULL,
    description TEXT NOT NULL,
    status ENUM('open','under_review','assigned','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL DEFAULT NULL,
    KEY idx_support_order_id (order_id),
    KEY idx_support_client_id (client_id),
    KEY idx_support_assigned_staff_id (assigned_staff_id),
    CONSTRAINT fk_support_ticket_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_support_ticket_client FOREIGN KEY (client_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_support_ticket_assigned_staff FOREIGN KEY (assigned_staff_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS order_exceptions (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id INT(11) NOT NULL,
    exception_type VARCHAR(50) NOT NULL,
    severity ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    status ENUM('open','in_progress','escalated','resolved','dismissed') NOT NULL DEFAULT 'open',
    notes TEXT DEFAULT NULL,
    assigned_handler_id INT(11) DEFAULT NULL,
    escalated_at DATETIME DEFAULT NULL,
    resolved_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_order_exceptions_order (order_id),
    KEY idx_order_exceptions_type_status (exception_type, status),
    KEY idx_order_exceptions_assigned_handler (assigned_handler_id),
    CONSTRAINT fk_order_exceptions_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_exceptions_assigned_handler FOREIGN KEY (assigned_handler_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS order_exception_history (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    exception_id INT(11) NOT NULL,
    order_id INT(11) NOT NULL,
    action ENUM('opened','updated','status_changed','escalated','resolved','dismissed') NOT NULL,
    previous_status VARCHAR(30) DEFAULT NULL,
    new_status VARCHAR(30) DEFAULT NULL,
    actor_id INT(11) DEFAULT NULL,
    actor_role VARCHAR(40) DEFAULT NULL,
    note TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_exception_history_exception (exception_id),
    KEY idx_exception_history_order (order_id),
    CONSTRAINT fk_exception_history_exception FOREIGN KEY (exception_id) REFERENCES order_exceptions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS order_quotes (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id INT(11) NOT NULL,
    quote_amount DECIMAL(10,2) NOT NULL,
    breakdown_json LONGTEXT DEFAULT NULL,
    status ENUM('draft','sent','approved','rejected','expired') NOT NULL DEFAULT 'draft',
    issued_by INT(11) DEFAULT NULL,
    issued_at DATETIME DEFAULT NULL,
    expires_at DATETIME DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_order_quotes_order (order_id),
    KEY idx_order_quotes_status (status),
    CONSTRAINT fk_order_quotes_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_quotes_issued_by FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS client_profiles (
    client_id INT(11) NOT NULL PRIMARY KEY,
    first_name VARCHAR(100) DEFAULT NULL,
    middle_name VARCHAR(100) DEFAULT NULL,
    last_name VARCHAR(100) DEFAULT NULL,
    contact_email VARCHAR(150) DEFAULT NULL,
    billing_contact_name VARCHAR(150) DEFAULT NULL,
    billing_phone VARCHAR(20) DEFAULT NULL,
    billing_email VARCHAR(150) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_client_profiles_user FOREIGN KEY (client_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS client_addresses (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id INT(11) NOT NULL,
    label VARCHAR(100) DEFAULT NULL,
    recipient_name VARCHAR(150) DEFAULT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    country VARCHAR(100) NOT NULL,
    province VARCHAR(120) NOT NULL,
    city VARCHAR(120) NOT NULL,
    barangay VARCHAR(120) NOT NULL,
    street_address VARCHAR(255) NOT NULL,
    address_line2 VARCHAR(255) DEFAULT NULL,
    postal_code VARCHAR(20) DEFAULT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_client_addresses_client (client_id),
    KEY idx_client_addresses_default (client_id, is_default),
    CONSTRAINT fk_client_addresses_user FOREIGN KEY (client_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS client_payment_preferences (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id INT(11) NOT NULL,
    payment_method ENUM('gcash','card','cod','pickup') NOT NULL,
    account_name VARCHAR(150) DEFAULT NULL,
    account_identifier VARCHAR(120) DEFAULT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 0,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    verification_status ENUM('not_required','pending','verified') NOT NULL DEFAULT 'not_required',
    verified_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_client_payment_method (client_id, payment_method),
    KEY idx_client_payment_default (client_id, is_default),
    CONSTRAINT fk_client_payment_user FOREIGN KEY (client_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Migration: analytics, scheduling, moderation, and reminder tables.

CREATE TABLE IF NOT EXISTS machines (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    shop_id INT(11) NOT NULL,
    machine_name VARCHAR(150) NOT NULL,
    max_stitches_per_hour INT(11) NOT NULL DEFAULT 12000,
    status ENUM('active','inactive','maintenance') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_machines_shop (shop_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS machine_jobs (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    machine_id INT(11) NOT NULL,
    order_id INT(11) NOT NULL,
    estimated_stitches INT(11) DEFAULT NULL,
    scheduled_start DATETIME DEFAULT NULL,
    scheduled_end DATETIME DEFAULT NULL,
    status ENUM('queued','running','completed','cancelled') NOT NULL DEFAULT 'queued',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_machine_jobs_machine (machine_id),
    KEY idx_machine_jobs_order (order_id),
    CONSTRAINT fk_machine_jobs_machine FOREIGN KEY (machine_id) REFERENCES machines(id) ON DELETE CASCADE,
    CONSTRAINT fk_machine_jobs_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS supplier_orders (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    material_id INT(11) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    supplier_name VARCHAR(150) DEFAULT NULL,
    status ENUM('draft', 'pending', 'ordered', 'cancelled', 'completed') NOT NULL DEFAULT 'draft',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_supplier_orders_material (material_id),
    KEY idx_supplier_orders_status (status),
    CONSTRAINT fk_supplier_orders_material FOREIGN KEY (material_id) REFERENCES raw_materials(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS order_material_reservations (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id INT(11) NOT NULL,
    shop_id INT(11) NOT NULL,
    material_id INT(11) NOT NULL,
    reserved_qty DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    consumed_qty DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status ENUM('reserved','consumed','released','cancelled') NOT NULL DEFAULT 'reserved',
    notes VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_order_material_reservation (order_id, material_id),
    KEY idx_omr_shop_status (shop_id, status),
    CONSTRAINT fk_omr_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_omr_material FOREIGN KEY (material_id) REFERENCES raw_materials(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS shop_metrics (
    shop_id INT(11) NOT NULL PRIMARY KEY,
    avg_rating DECIMAL(4,2) DEFAULT NULL,
    review_count INT(11) DEFAULT 0,
    completion_rate DECIMAL(6,4) DEFAULT NULL,
    avg_turnaround_days DECIMAL(8,2) DEFAULT NULL,
    price_index DECIMAL(8,4) DEFAULT NULL,
    cancellation_rate DECIMAL(6,4) DEFAULT NULL,
    availability_flag TINYINT(1) DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_shop_metrics_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS dss_logs (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    actor_user_id INT(11) DEFAULT NULL,
    shop_id INT(11) DEFAULT NULL,
    action VARCHAR(100) NOT NULL,
    context_json LONGTEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_dss_logs_actor (actor_user_id),
    KEY idx_dss_logs_shop (shop_id),
    KEY idx_dss_logs_action (action),
    CONSTRAINT fk_dss_logs_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_dss_logs_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS content_reports (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reporter_user_id INT(11) NOT NULL,
    target_entity_type VARCHAR(50) NOT NULL,
    target_entity_id INT(11) NOT NULL,
    reason VARCHAR(150) NOT NULL,
    notes TEXT DEFAULT NULL,
    status ENUM('pending','reviewing','resolved','dismissed') NOT NULL DEFAULT 'pending',
    reviewed_by INT(11) DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_content_reports_status (status),
    KEY idx_content_reports_target (target_entity_type, target_entity_id),
    KEY idx_content_reports_reporter (reporter_user_id),
    KEY idx_content_reports_reviewed_by (reviewed_by),
    CONSTRAINT fk_content_reports_reporter FOREIGN KEY (reporter_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_content_reports_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE client_community_posts
    ADD COLUMN IF NOT EXISTS is_hidden TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS is_removed TINYINT(1) NOT NULL DEFAULT 0,
    ADD INDEX IF NOT EXISTS idx_client_community_posts_hidden (is_hidden, is_removed);

ALTER TABLE community_post_comments
    ADD COLUMN IF NOT EXISTS is_hidden TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS is_removed TINYINT(1) NOT NULL DEFAULT 0,
    ADD INDEX IF NOT EXISTS idx_community_post_comments_hidden (is_hidden, is_removed);

CREATE TABLE IF NOT EXISTS automation_reminder_markers (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reminder_type VARCHAR(100) NOT NULL,
    entity_key VARCHAR(191) NOT NULL,
    context_order_id INT(11) DEFAULT NULL,
    reminded_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_automation_marker (reminder_type, entity_key),
    KEY idx_automation_marker_order (context_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Migration: supplier and warehouse column/table changes previously done in owner pages.

ALTER TABLE suppliers
    ADD COLUMN IF NOT EXISTS address VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS business_address VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS tin_permits VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS contact_person VARCHAR(150) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS phone_mobile VARCHAR(80) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS email_address VARCHAR(150) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS social_viber VARCHAR(150) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS item_category VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS price_list VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS moq VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS stock_availability VARCHAR(255) DEFAULT NULL;

CREATE TABLE IF NOT EXISTS warehouse_stock_management (
    id INT(11) NOT NULL AUTO_INCREMENT,
    shop_id INT(11) NOT NULL,
    material_id INT(11) DEFAULT NULL,
    material_input VARCHAR(120) DEFAULT NULL,
    opening_stock_qty DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    warehouse_location VARCHAR(120) NOT NULL,
    reorder_level DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    reorder_quantity DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_wsm_shop_id (shop_id),
    KEY idx_wsm_material_id (material_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


INSERT IGNORE INTO `schema_migrations` (`version`) VALUES
('2026.03.11.runtime_schema_baseline'),
('2026.03.11.payments_and_client_extensions'),
('2026.03.11.analytics_scheduling_moderation'),
('2026.03.11.supplier_and_warehouse_columns');

-- DSS / analytics schema alignment patch

ALTER TABLE shop_metrics
    ADD COLUMN IF NOT EXISTS avg_rating DECIMAL(4,2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS review_count INT(11) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS completion_rate DECIMAL(6,4) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS avg_turnaround_days DECIMAL(8,2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS price_index DECIMAL(8,4) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS cancellation_rate DECIMAL(6,4) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS availability_flag TINYINT(1) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE dss_logs
    ADD COLUMN IF NOT EXISTS actor_user_id INT(11) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS shop_id INT(11) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS action VARCHAR(100) NOT NULL DEFAULT 'unknown_action',
    ADD COLUMN IF NOT EXISTS context_json LONGTEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

SET @idx_actor_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'dss_logs' AND index_name = 'idx_dss_logs_actor'
);
SET @sql := IF(@idx_actor_exists = 0,
    'ALTER TABLE dss_logs ADD KEY idx_dss_logs_actor (actor_user_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_shop_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'dss_logs' AND index_name = 'idx_dss_logs_shop'
);
SET @sql := IF(@idx_shop_exists = 0,
    'ALTER TABLE dss_logs ADD KEY idx_dss_logs_shop (shop_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_action_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'dss_logs' AND index_name = 'idx_dss_logs_action'
);
SET @sql := IF(@idx_action_exists = 0,
    'ALTER TABLE dss_logs ADD KEY idx_dss_logs_action (action)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;