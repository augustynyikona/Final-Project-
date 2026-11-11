-- ============================================================
--  VaxiCare — local development database seed
-- ============================================================
--  Exported from the working local copy. Contains the seeded
--  demo data only (12 users, 8 children, 15 appointments).
--
--  IMPORT (MySQL / MariaDB / XAMPP / Laragon):
--      mysql -u root -p < vaxicare_local_seed.sql
--
--  Or in phpMyAdmin: Import -> choose this file -> Go.
--
--  After importing, copy .env.example to .env and fill in
--  DB_HOST / DB_NAME / DB_USER / DB_PASS. The app reads those
--  via config/env.php.
--
--  Demo logins (roles: admin / doctor / pharmacy):
--      admin    0970000011  -> /admin/login.php
--      doctor   0970000012  -> /auth/login.php
--      pharmacy 0970000013  -> /auth/login.php
--  Passwords are bcrypt hashes; ask the project owner.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `vaxicare`
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `vaxicare`;

-- MySQL dump 10.13  Distrib 8.0.46, for Linux (x86_64)
--
-- Host: 127.0.0.1    Database: vaxicare
-- ------------------------------------------------------
-- Server version	8.0.46-0ubuntu0.24.04.4

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `app_settings`
--

DROP TABLE IF EXISTS `app_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_settings` (
  `setting_key` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` text COLLATE utf8mb4_unicode_ci,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `app_settings`
--

/*!40000 ALTER TABLE `app_settings` DISABLE KEYS */;
INSERT INTO `app_settings` VALUES ('facility_address','Ndola','2026-11-11 14:52:11'),('facility_code','VXC-NDL-001','2026-11-11 14:54:15'),('facility_currency','ZMW','2026-11-11 14:36:45'),('facility_email','s@t.local','2026-11-11 14:52:11'),('facility_name','VaxiCare Health Centre','2026-11-11 14:54:15'),('facility_phone','0970000000','2026-11-11 14:52:11'),('facility_timezone','Africa/Lusaka','2026-11-11 14:36:45');
/*!40000 ALTER TABLE `app_settings` ENABLE KEYS */;

--
-- Table structure for table `appointments`
--

DROP TABLE IF EXISTS `appointments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `appointments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `mother_id` int DEFAULT NULL,
  `child_id` int DEFAULT NULL,
  `vaccine_id` int DEFAULT NULL,
  `appointment_date` date NOT NULL,
  `appointment_time` time NOT NULL,
  `status` enum('scheduled','confirmed','cancelled','arrived','no-show','completed','rescheduled') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'scheduled',
  `batch_number` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `site` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `route` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `manufacturer` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_general_ci,
  `administered_by` int DEFAULT NULL,
  `cancellation_reason` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by_id` int DEFAULT NULL,
  `room_number` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_archived` tinyint(1) NOT NULL DEFAULT '0',
  `archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `child_id` (`child_id`),
  KEY `vaccine_id` (`vaccine_id`),
  KEY `fk_mother_appt` (`mother_id`),
  KEY `fk_appt_administered_by` (`administered_by`),
  CONSTRAINT `appointments_ibfk_1` FOREIGN KEY (`child_id`) REFERENCES `children` (`id`) ON DELETE CASCADE,
  CONSTRAINT `appointments_ibfk_2` FOREIGN KEY (`vaccine_id`) REFERENCES `vaccines` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_appt_administered_by` FOREIGN KEY (`administered_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `appointments`
--

/*!40000 ALTER TABLE `appointments` DISABLE KEYS */;
INSERT INTO `appointments` VALUES (1,NULL,4,1,'2026-10-21','09:00:00','completed',NULL,NULL,NULL,NULL,'Routine immunisation visit',NULL,NULL,NULL,NULL,'Room 1','2026-11-11 14:27:13',0,NULL),(2,NULL,4,7,'2026-11-04','10:30:00','completed',NULL,NULL,NULL,NULL,'Routine immunisation visit',NULL,NULL,NULL,NULL,'Room 1','2026-11-11 14:27:13',0,NULL),(3,NULL,4,13,'2026-11-11','09:30:00','arrived',NULL,NULL,NULL,NULL,'Routine immunisation visit',NULL,NULL,NULL,NULL,'Room 2','2026-11-11 14:27:13',0,NULL),(4,NULL,5,9,'2026-10-28','11:00:00','completed',NULL,NULL,NULL,NULL,'Routine immunisation visit',NULL,NULL,NULL,NULL,'Room 1','2026-11-11 14:27:13',0,NULL),(5,NULL,5,10,'2026-11-12','08:30:00','scheduled',NULL,NULL,NULL,NULL,'Routine immunisation visit',NULL,NULL,NULL,NULL,'Room 1','2026-11-11 14:27:13',0,NULL),(6,NULL,6,11,'2026-11-08','14:00:00','no-show',NULL,NULL,NULL,NULL,'Routine immunisation visit',NULL,NULL,NULL,NULL,'Room 3','2026-11-11 14:27:13',0,NULL),(7,NULL,6,14,'2026-11-11','11:15:00','confirmed',NULL,NULL,NULL,NULL,'Routine immunisation visit',NULL,NULL,NULL,NULL,'Room 2','2026-11-11 14:27:13',0,NULL),(8,NULL,6,8,'2026-11-18','09:00:00','scheduled',NULL,NULL,NULL,NULL,'Routine immunisation visit',NULL,NULL,NULL,NULL,'Room 1','2026-11-11 14:27:13',0,NULL),(9,NULL,7,3,'2026-10-12','15:00:00','completed',NULL,NULL,NULL,NULL,'Routine immunisation visit',NULL,NULL,NULL,NULL,'Room 3','2026-11-11 14:27:13',0,NULL),(10,NULL,7,12,'2026-11-13','10:00:00','scheduled',NULL,NULL,NULL,NULL,'Routine immunisation visit',NULL,NULL,NULL,NULL,'Room 2','2026-11-11 14:27:13',0,NULL),(11,NULL,8,2,'2026-11-01','09:45:00','cancelled',NULL,NULL,NULL,NULL,'Routine immunisation visit',NULL,NULL,NULL,NULL,'Room 1','2026-11-11 14:27:13',0,NULL),(12,NULL,8,4,'2026-11-16','13:30:00','scheduled',NULL,NULL,NULL,NULL,'Routine immunisation visit',NULL,NULL,NULL,NULL,'Room 2','2026-11-11 14:27:13',0,NULL),(13,NULL,9,7,'2026-11-14','08:00:00','scheduled',NULL,NULL,NULL,NULL,'Routine immunisation visit',NULL,NULL,NULL,NULL,'Room 1','2026-11-11 14:27:13',0,NULL),(14,NULL,10,9,'2026-11-11','08:15:00','confirmed',NULL,NULL,NULL,NULL,'Routine immunisation visit',NULL,NULL,NULL,NULL,'Room 1','2026-11-11 14:27:13',0,NULL),(15,NULL,10,10,'2026-11-10','08:15:00','completed',NULL,NULL,NULL,NULL,'Routine immunisation visit',NULL,NULL,NULL,NULL,'Room 1','2026-11-11 14:27:13',0,NULL);
/*!40000 ALTER TABLE `appointments` ENABLE KEYS */;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `actor_id` int DEFAULT NULL,
  `actor_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `actor_role` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_type` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `target_id` int DEFAULT NULL,
  `details` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_created` (`created_at`),
  KEY `idx_audit_actor` (`actor_id`),
  KEY `idx_audit_action` (`action`)
) ENGINE=InnoDB AUTO_INCREMENT=63 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_logs`
--

/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
INSERT INTO `audit_logs` VALUES (2,12,'Demo Admin','admin','patient.registered','child',11,'Tawonga Banda (guardian #21)','127.0.0.1','2026-11-11 14:39:18'),(7,12,'Demo Admin','admin','admin.created','user',24,'Temp Admin','127.0.0.1','2026-11-11 14:44:21'),(8,12,'Demo Admin','admin','admin.created','user',25,'Short Pw','127.0.0.1','2026-11-11 14:44:21'),(9,12,'Demo Admin','admin','user.deleted','user',25,'Short Pw (admin)','127.0.0.1','2026-11-11 14:44:37'),(10,12,'Demo Admin','admin','admin.created','user',26,'Email Admin','127.0.0.1','2026-11-11 14:48:02'),(11,12,'Demo Admin','admin','duty_toggle','user',13,'Off duty','127.0.0.1','2026-11-11 14:48:10'),(12,12,'Demo Admin','admin','duty_toggle','user',13,'On duty','127.0.0.1','2026-11-11 14:48:10'),(13,12,'Demo Admin','admin','admin.created','user',27,'Sweep Admin','127.0.0.1','2026-11-11 14:50:32'),(14,12,'Demo Admin','admin','staff.created','user',28,'Sweep Doc (doctor)','127.0.0.1','2026-11-11 14:50:33'),(15,12,'Demo Admin','admin','duty_toggle','user',28,'Off duty','127.0.0.1','2026-11-11 14:50:38'),(16,12,'Demo Admin','admin','duty_toggle','user',28,'On duty','127.0.0.1','2026-11-11 14:50:38'),(17,12,'Demo Admin','admin','duty_toggle','user',28,'Off duty','127.0.0.1','2026-11-11 14:50:38'),(18,12,'Demo Admin','admin','duty_toggle','user',28,'On duty','127.0.0.1','2026-11-11 14:50:38'),(19,12,'Demo Admin','admin','guardian.created','user',29,'Sweep Guardian (0970000907)','127.0.0.1','2026-11-11 14:50:39'),(20,12,'Demo Admin','admin','patient.registered','child',13,'Sweep Child (guardian #29)','127.0.0.1','2026-11-11 14:50:39'),(21,12,'Demo Admin','admin','admin.created','user',30,'Sweep Admin','127.0.0.1','2026-11-11 14:52:05'),(22,12,'Demo Admin','admin','staff.created','user',31,'Sweep Doc (doctor)','127.0.0.1','2026-11-11 14:52:05'),(23,12,'Demo Admin','admin','duty_toggle','user',31,'Off duty','127.0.0.1','2026-11-11 14:52:11'),(24,12,'Demo Admin','admin','duty_toggle','user',31,'On duty','127.0.0.1','2026-11-11 14:52:11'),(25,12,'Demo Admin','admin','settings.facility_updated','setting',NULL,'Sweep Clinic','127.0.0.1','2026-11-11 14:52:11'),(26,12,'Demo Admin','admin','guardian.created','user',32,'Sweep Guardian (0970000907)','127.0.0.1','2026-11-11 14:52:12'),(27,12,'Demo Admin','admin','patient.registered','child',14,'Sweep Child (guardian #32)','127.0.0.1','2026-11-11 14:52:12'),(28,12,'Demo Admin','admin','user.deleted','user',32,'Sweep Guardian (guardian)','127.0.0.1','2026-11-11 14:52:12'),(29,12,'Demo Admin','admin','admin.created','user',33,'Sweep Admin','127.0.0.1','2026-11-11 14:53:08'),(30,12,'Demo Admin','admin','staff.created','user',34,'Sweep Doc (doctor)','127.0.0.1','2026-11-11 14:53:08'),(31,12,'Demo Admin','admin','duty_toggle','user',34,'Off duty','127.0.0.1','2026-11-11 14:53:12'),(32,12,'Demo Admin','admin','duty_toggle','user',34,'On duty','127.0.0.1','2026-11-11 14:53:12'),(33,12,'Demo Admin','admin','settings.facility_updated','setting',NULL,'Sweep Clinic','127.0.0.1','2026-11-11 14:53:13'),(34,12,'Demo Admin','admin','guardian.created','user',35,'Sweep Guardian (0970000907)','127.0.0.1','2026-11-11 14:53:13'),(35,12,'Demo Admin','admin','patient.registered','child',15,'Sweep Child (guardian #35)','127.0.0.1','2026-11-11 14:53:13'),(36,12,'Demo Admin','admin','user.deleted','user',35,'Sweep Guardian (guardian)','127.0.0.1','2026-11-11 14:53:13'),(37,12,'Demo Admin','admin','admin.created','user',36,'Sweep Admin','127.0.0.1','2026-11-11 14:53:32'),(38,12,'Demo Admin','admin','staff.created','user',37,'Sweep Doc (doctor)','127.0.0.1','2026-11-11 14:53:33'),(39,12,'Demo Admin','admin','duty_toggle','user',37,'Off duty','127.0.0.1','2026-11-11 14:53:37'),(40,12,'Demo Admin','admin','duty_toggle','user',37,'On duty','127.0.0.1','2026-11-11 14:53:37'),(41,12,'Demo Admin','admin','settings.facility_updated','setting',NULL,'Sweep Clinic','127.0.0.1','2026-11-11 14:53:37'),(42,12,'Demo Admin','admin','guardian.created','user',38,'Sweep Guardian (0970000907)','127.0.0.1','2026-11-11 14:53:38'),(43,12,'Demo Admin','admin','patient.registered','child',16,'Sweep Child (guardian #38)','127.0.0.1','2026-11-11 14:53:38'),(44,12,'Demo Admin','admin','user.deleted','user',38,'Sweep Guardian (guardian)','127.0.0.1','2026-11-11 14:53:38'),(45,12,'Demo Admin','admin','admin.created','user',39,'Sweep Admin','127.0.0.1','2026-11-11 14:53:49'),(46,12,'Demo Admin','admin','staff.created','user',40,'Sweep Doc (doctor)','127.0.0.1','2026-11-11 14:53:49'),(47,12,'Demo Admin','admin','duty_toggle','user',40,'Off duty','127.0.0.1','2026-11-11 14:53:53'),(48,12,'Demo Admin','admin','duty_toggle','user',40,'On duty','127.0.0.1','2026-11-11 14:53:53'),(49,12,'Demo Admin','admin','settings.facility_updated','setting',NULL,'Sweep Clinic','127.0.0.1','2026-11-11 14:53:54'),(50,12,'Demo Admin','admin','guardian.created','user',41,'Sweep Guardian (0970000907)','127.0.0.1','2026-11-11 14:53:54'),(51,12,'Demo Admin','admin','patient.registered','child',17,'Sweep Child (guardian #41)','127.0.0.1','2026-11-11 14:53:54'),(52,12,'Demo Admin','admin','user.deleted','user',41,'Sweep Guardian (guardian)','127.0.0.1','2026-11-11 14:53:54'),(53,12,'Demo Admin','admin','admin.created','user',42,'Sweep Admin','127.0.0.1','2026-11-11 14:54:10'),(54,12,'Demo Admin','admin','staff.created','user',43,'Sweep Doc (doctor)','127.0.0.1','2026-11-11 14:54:10'),(55,12,'Demo Admin','admin','duty_toggle','user',43,'Off duty','127.0.0.1','2026-11-11 14:54:14'),(56,12,'Demo Admin','admin','duty_toggle','user',43,'On duty','127.0.0.1','2026-11-11 14:54:14'),(57,12,'Demo Admin','admin','settings.facility_updated','setting',NULL,'Sweep Clinic','127.0.0.1','2026-11-11 14:54:15'),(58,12,'Demo Admin','admin','guardian.created','user',44,'Sweep Guardian (0970000907)','127.0.0.1','2026-11-11 14:54:15'),(59,12,'Demo Admin','admin','patient.registered','child',18,'Sweep Child (guardian #44)','127.0.0.1','2026-11-11 14:54:15'),(60,12,'Demo Admin','admin','user.deleted','user',44,'Sweep Guardian (guardian)','127.0.0.1','2026-11-11 14:54:15'),(61,12,'Demo Admin','admin','staff.created','user',45,'Mapalo Invite Test (doctor)','127.0.0.1','2026-11-11 15:11:07'),(62,12,'Demo Admin','admin','staff.created','user',46,'Mapalo Invite Test (doctor)','127.0.0.1','2026-11-11 15:14:30');
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;

--
-- Table structure for table `children`
--

DROP TABLE IF EXISTS `children`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `children` (
  `id` int NOT NULL AUTO_INCREMENT,
  `guardian_id` int NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `date_of_birth` date NOT NULL,
  `gender` enum('Male','Female') COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `guardian_id` (`guardian_id`),
  CONSTRAINT `children_ibfk_1` FOREIGN KEY (`guardian_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `children`
--

/*!40000 ALTER TABLE `children` DISABLE KEYS */;
INSERT INTO `children` VALUES (3,11,'Augustine Nquzayikona','2026-10-14','Male','2026-11-04 17:59:18'),(4,15,'Mapalo Mwale','2024-03-12','Male','2026-11-11 14:27:12'),(5,15,'Tawonga Mwale','2025-11-02','Female','2026-11-11 14:27:12'),(6,16,'Chisomo Banda','2025-06-18','Female','2026-11-11 14:27:13'),(7,17,'Kondwani Phiri','2024-09-05','Male','2026-11-11 14:27:13'),(8,18,'Luse Zulu','2025-01-22','Female','2026-11-11 14:27:13'),(9,19,'Bwalya Sakala','2023-12-01','Male','2026-11-11 14:27:13'),(10,20,'Mwansa Tembo','2026-09-15','Female','2026-11-11 14:27:13');
/*!40000 ALTER TABLE `children` ENABLE KEYS */;

--
-- Table structure for table `clinical_vitals`
--

DROP TABLE IF EXISTS `clinical_vitals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clinical_vitals` (
  `id` int NOT NULL AUTO_INCREMENT,
  `child_id` int DEFAULT NULL,
  `mother_id` int DEFAULT NULL,
  `recorded_by_id` int DEFAULT NULL,
  `temperature` decimal(4,1) DEFAULT NULL,
  `heart_rate` int DEFAULT NULL,
  `weight_kg` decimal(5,2) DEFAULT NULL,
  `symptoms` text COLLATE utf8mb4_general_ci,
  `is_critical` tinyint(1) DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `child_id` (`child_id`),
  KEY `recorded_by_id` (`recorded_by_id`),
  KEY `fk_mother_vitals` (`mother_id`),
  CONSTRAINT `clinical_vitals_ibfk_1` FOREIGN KEY (`child_id`) REFERENCES `children` (`id`) ON DELETE CASCADE,
  CONSTRAINT `clinical_vitals_ibfk_2` FOREIGN KEY (`recorded_by_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mother_vitals` FOREIGN KEY (`mother_id`) REFERENCES `mothers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `clinical_vitals`
--

/*!40000 ALTER TABLE `clinical_vitals` DISABLE KEYS */;
INSERT INTO `clinical_vitals` VALUES (1,4,NULL,8,38.4,118,12.40,'Fever, irritable',0,'2026-10-21 07:20:00'),(2,6,NULL,8,39.8,142,9.10,'High fever, poor feeding',1,'2026-11-11 07:20:00'),(3,10,NULL,8,37.1,130,4.20,'Routine check',0,'2026-11-11 07:20:00'),(4,3,NULL,47,37.4,88,12.50,'sweep mild fever',0,'2026-11-11 15:30:38'),(5,3,NULL,47,37.4,88,12.50,'sweep mild fever',0,'2026-11-11 15:31:57'),(6,3,NULL,47,37.4,88,12.50,'sweep mild fever',0,'2026-11-11 15:34:59'),(7,3,NULL,47,37.4,88,12.50,'sweep mild fever',0,'2026-11-11 15:37:01'),(8,3,NULL,47,37.4,88,12.50,'sweep mild fever',0,'2026-11-11 15:38:48'),(9,3,NULL,47,37.4,88,12.50,'sweep mild fever',0,'2026-11-11 15:39:30'),(10,3,NULL,47,37.4,88,12.50,'sweep mild fever',0,'2026-11-11 15:39:37');
/*!40000 ALTER TABLE `clinical_vitals` ENABLE KEYS */;

--
-- Table structure for table `consultations`
--

DROP TABLE IF EXISTS `consultations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `consultations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `child_id` int DEFAULT NULL,
  `mother_id` int DEFAULT NULL,
  `doctor_id` int NOT NULL,
  `notes` text COLLATE utf8mb4_general_ci NOT NULL,
  `next_review_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `child_id` (`child_id`),
  KEY `doctor_id` (`doctor_id`),
  CONSTRAINT `consultations_ibfk_1` FOREIGN KEY (`child_id`) REFERENCES `children` (`id`) ON DELETE CASCADE,
  CONSTRAINT `consultations_ibfk_2` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `consultations`
--

/*!40000 ALTER TABLE `consultations` DISABLE KEYS */;
INSERT INTO `consultations` VALUES (1,4,NULL,13,'Mild fever after MMR; advised paracetamol and fluids.','2026-11-04','2026-10-21 08:15:00'),(2,7,NULL,13,'Recurrent cough, chest clear on exam. Symptomatic treatment.','2026-10-26','2026-10-12 08:15:00'),(3,5,NULL,13,'BCG site healing well, no axillary lymphadenopathy.','2026-11-11','2026-10-28 08:15:00');
/*!40000 ALTER TABLE `consultations` ENABLE KEYS */;

--
-- Table structure for table `growth_logs`
--

DROP TABLE IF EXISTS `growth_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `growth_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `child_id` int NOT NULL,
  `record_date` date NOT NULL,
  `height_cm` decimal(5,2) NOT NULL,
  `weight_kg` decimal(5,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `child_id` (`child_id`),
  CONSTRAINT `growth_logs_ibfk_1` FOREIGN KEY (`child_id`) REFERENCES `children` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `growth_logs`
--

/*!40000 ALTER TABLE `growth_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `growth_logs` ENABLE KEYS */;

--
-- Table structure for table `invoices`
--

DROP TABLE IF EXISTS `invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoices` (
  `id` int NOT NULL AUTO_INCREMENT,
  `guardian_id` int NOT NULL,
  `child_id` int DEFAULT NULL,
  `amount_due` decimal(10,2) NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_general_ci DEFAULT 'Pending',
  `admission_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `guardian_id` (`guardian_id`),
  KEY `child_id` (`child_id`),
  CONSTRAINT `invoices_ibfk_1` FOREIGN KEY (`guardian_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `invoices_ibfk_2` FOREIGN KEY (`child_id`) REFERENCES `children` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `invoices`
--

/*!40000 ALTER TABLE `invoices` DISABLE KEYS */;
INSERT INTO `invoices` VALUES (1,15,4,250.00,'Paid','2026-10-21','2026-11-11 14:27:13'),(2,16,6,180.00,'Paid','2026-11-11','2026-11-11 14:27:13'),(3,17,7,320.00,'Paid','2026-10-12','2026-11-11 14:27:13'),(4,18,8,150.00,'Paid','2026-09-27','2026-11-11 14:27:13');
/*!40000 ALTER TABLE `invoices` ENABLE KEYS */;

--
-- Table structure for table `messages`
--

DROP TABLE IF EXISTS `messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sender_id` int NOT NULL,
  `recipient_role` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `receiver_id` int DEFAULT NULL,
  `subject` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `body` text COLLATE utf8mb4_general_ci NOT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `sender_id` (`sender_id`),
  CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=85 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `messages`
--

/*!40000 ALTER TABLE `messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `messages` ENABLE KEYS */;

--
-- Table structure for table `mothers`
--

DROP TABLE IF EXISTS `mothers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `mothers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `guardian_id` int NOT NULL,
  `date_of_birth` date DEFAULT NULL,
  `blood_group` varchar(5) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `gravida` int DEFAULT '0',
  `parity` int DEFAULT '0',
  `lmp` date DEFAULT NULL,
  `edd` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `guardian_id` (`guardian_id`),
  CONSTRAINT `mothers_ibfk_1` FOREIGN KEY (`guardian_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mothers`
--

/*!40000 ALTER TABLE `mothers` DISABLE KEYS */;
INSERT INTO `mothers` VALUES (1,15,'1996-04-18','O+',2,1,NULL,'2027-03-11','2026-11-11 14:27:12'),(2,16,'1999-08-30','A+',1,0,NULL,'2027-04-10','2026-11-11 14:27:12'),(3,17,'1994-01-11','B+',3,2,NULL,NULL,'2026-11-11 14:27:12'),(4,18,'2000-06-25','O-',2,1,NULL,'2027-02-14','2026-11-11 14:27:12');
/*!40000 ALTER TABLE `mothers` ENABLE KEYS */;

--
-- Table structure for table `nurse_tasks`
--

DROP TABLE IF EXISTS `nurse_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nurse_tasks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `child_id` int DEFAULT NULL,
  `mother_id` int DEFAULT NULL,
  `doctor_id` int NOT NULL,
  `task_description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending','completed') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `child_id` (`child_id`),
  KEY `doctor_id` (`doctor_id`),
  CONSTRAINT `nurse_tasks_ibfk_1` FOREIGN KEY (`child_id`) REFERENCES `children` (`id`) ON DELETE CASCADE,
  CONSTRAINT `nurse_tasks_ibfk_2` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nurse_tasks`
--

/*!40000 ALTER TABLE `nurse_tasks` DISABLE KEYS */;
INSERT INTO `nurse_tasks` VALUES (1,6,NULL,13,'Recheck temperature and report if above 38.5C','completed','2026-11-11 14:27:13','2026-11-11 15:30:38'),(2,8,NULL,13,'Confirm HPV dose 2 appointment before Friday','completed','2026-11-11 14:27:13','2026-11-11 15:31:57'),(3,10,NULL,13,'Weigh and plot growth chart at next visit','completed','2026-11-11 14:27:13','2026-11-11 15:34:59');
/*!40000 ALTER TABLE `nurse_tasks` ENABLE KEYS */;

--
-- Table structure for table `prescriptions`
--

DROP TABLE IF EXISTS `prescriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `prescriptions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `child_id` int NOT NULL,
  `guardian_id` int NOT NULL,
  `doctor_id` int NOT NULL,
  `consultation_id` int DEFAULT NULL,
  `medication_name` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `dosage` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `frequency` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `duration` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `instructions` text COLLATE utf8mb4_general_ci,
  `status` enum('active','completed','dispensed','cancelled') COLLATE utf8mb4_general_ci DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_child` (`child_id`),
  KEY `idx_guardian` (`guardian_id`),
  KEY `idx_doctor` (`doctor_id`),
  CONSTRAINT `fk_presc_child` FOREIGN KEY (`child_id`) REFERENCES `children` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_presc_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_presc_guardian` FOREIGN KEY (`guardian_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `prescriptions`
--

/*!40000 ALTER TABLE `prescriptions` DISABLE KEYS */;
INSERT INTO `prescriptions` VALUES (1,4,15,13,NULL,'Paracetamol Syrup','5ml','Every 6 hours','3 days','After food; stop when fever clears','active','2026-11-11 14:27:13'),(2,7,17,13,NULL,'Amoxicillin Syrup','5ml','Three times daily','5 days','Complete the full course','active','2026-11-11 14:27:13'),(3,6,16,13,NULL,'ORS Sachets','1 sachet in 200ml water','As needed','2 days','Give small sips after each stool','active','2026-11-11 14:27:13');
/*!40000 ALTER TABLE `prescriptions` ENABLE KEYS */;

--
-- Table structure for table `purchase_orders`
--

DROP TABLE IF EXISTS `purchase_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_orders` (
  `id` int NOT NULL AUTO_INCREMENT,
  `vaccine_id` int NOT NULL,
  `supplier_id` int NOT NULL,
  `quantity` int NOT NULL,
  `unit_type` varchar(20) COLLATE utf8mb4_general_ci DEFAULT 'Vials',
  `order_date` date NOT NULL,
  `requested_delivery` date NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_general_ci DEFAULT 'Pending',
  `approved_at` timestamp NULL DEFAULT NULL,
  `notes` text COLLATE utf8mb4_general_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `vaccine_id` (`vaccine_id`),
  KEY `supplier_id` (`supplier_id`),
  CONSTRAINT `purchase_orders_ibfk_1` FOREIGN KEY (`vaccine_id`) REFERENCES `vaccines` (`id`) ON DELETE CASCADE,
  CONSTRAINT `purchase_orders_ibfk_2` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchase_orders`
--

/*!40000 ALTER TABLE `purchase_orders` DISABLE KEYS */;
INSERT INTO `purchase_orders` VALUES (1,5,1,200,'vials','2026-11-11','2026-11-14','Pending',NULL,'Urgent - stock at zero','2026-11-11 14:27:13'),(2,6,4,150,'vials','2026-11-11','2026-11-18','Pending',NULL,'Routine replenishment','2026-11-11 14:27:13'),(3,2,3,300,'vials','2026-11-11','2026-11-21','Pending',NULL,'Below reorder threshold','2026-11-11 14:27:13');
/*!40000 ALTER TABLE `purchase_orders` ENABLE KEYS */;

--
-- Table structure for table `requisition_items`
--

DROP TABLE IF EXISTS `requisition_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `requisition_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `requisition_id` int NOT NULL,
  `vaccine_id` int NOT NULL,
  `requested_quantity` int NOT NULL,
  `fulfilled_quantity` int DEFAULT '0',
  `notes` text COLLATE utf8mb4_general_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `requisition_id` (`requisition_id`),
  KEY `vaccine_id` (`vaccine_id`),
  CONSTRAINT `requisition_items_ibfk_1` FOREIGN KEY (`requisition_id`) REFERENCES `requisitions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `requisition_items_ibfk_2` FOREIGN KEY (`vaccine_id`) REFERENCES `vaccines` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `requisition_items`
--

/*!40000 ALTER TABLE `requisition_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `requisition_items` ENABLE KEYS */;

--
-- Table structure for table `requisitions`
--

DROP TABLE IF EXISTS `requisitions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `requisitions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nurse_id` int NOT NULL,
  `shift_date` date NOT NULL,
  `status` enum('Pending','Approved','Rejected','Fulfilled') COLLATE utf8mb4_general_ci DEFAULT 'Pending',
  `approved_at` timestamp NULL DEFAULT NULL,
  `dispatch_type` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `dispatch_time` timestamp NULL DEFAULT NULL,
  `dispatch_notes` text COLLATE utf8mb4_general_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `nurse_id` (`nurse_id`),
  CONSTRAINT `requisitions_ibfk_1` FOREIGN KEY (`nurse_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `requisitions`
--

/*!40000 ALTER TABLE `requisitions` DISABLE KEYS */;
/*!40000 ALTER TABLE `requisitions` ENABLE KEYS */;

--
-- Table structure for table `risk_alerts`
--

DROP TABLE IF EXISTS `risk_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `risk_alerts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `child_id` int DEFAULT NULL,
  `mother_id` int DEFAULT NULL,
  `alert_type` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `description` text COLLATE utf8mb4_general_ci NOT NULL,
  `status` enum('active','resolved') COLLATE utf8mb4_general_ci DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `child_id` (`child_id`),
  KEY `mother_id` (`mother_id`),
  CONSTRAINT `risk_alerts_ibfk_1` FOREIGN KEY (`child_id`) REFERENCES `children` (`id`) ON DELETE CASCADE,
  CONSTRAINT `risk_alerts_ibfk_2` FOREIGN KEY (`mother_id`) REFERENCES `mothers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `risk_alerts`
--

/*!40000 ALTER TABLE `risk_alerts` DISABLE KEYS */;
INSERT INTO `risk_alerts` VALUES (1,6,NULL,'High temperature','Temperature 39.8C at triage - review urgently','active','2026-11-11 14:27:13'),(2,8,NULL,'Missed appointment','HPV dose 2 appointment was cancelled','active','2026-11-11 14:27:13');
/*!40000 ALTER TABLE `risk_alerts` ENABLE KEYS */;

--
-- Table structure for table `sms_logs`
--

DROP TABLE IF EXISTS `sms_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sms_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `appointment_id` int DEFAULT NULL,
  `recipient_phone` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `message` text COLLATE utf8mb4_general_ci NOT NULL,
  `reminder_type` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_general_ci DEFAULT 'Sent',
  `sent_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `appointment_id` (`appointment_id`),
  CONSTRAINT `sms_logs_ibfk_1` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sms_logs`
--

/*!40000 ALTER TABLE `sms_logs` DISABLE KEYS */;
INSERT INTO `sms_logs` VALUES (2,NULL,'260970000000','dry run probe',NULL,'DryRun','2026-11-11 15:05:09'),(3,NULL,'260971725900','VaxiCare test message: SMS gateway connected successfully. 11 Nov 2026 15:08','test','Sent','2026-11-11 15:08:20'),(5,17,'260963912783','Your appointment for Augustine Nquzayikona (MMR Vaccine) has been confirmed for Oct 12, 2026 at 09:00 AM at Kansenshi Pediatric Clinic.','booking','In-System','2026-11-11 15:23:13'),(13,22,'260963912783','Your appointment for Augustine Nquzayikona (MMR Vaccine) has been confirmed for Dec 21, 2026 at 11:30 AM at Kansenshi Pediatric Clinic.','booking','In-System','2026-11-11 15:30:37'),(14,NULL,'0977000111','Hello *Sweep Guardian*,\n\nWelcome to VaxiCare! Your infant *Sweep Child* has been successfully registered by Nurse Demo Nurse.\n\nAccess your interactive vaccination passport & schedule here:\nhttp://127.0.0.1:8090/auth/login.php?phone=0977000111\n\n*Login ID (Phone):* 0977000111\n*Temporary Password:* password123\n\n_Please change your temporary password after logging in for the first time._',NULL,'Sent','2026-11-11 15:30:43'),(15,23,'260963912783','Your appointment for Augustine Nquzayikona (MMR Vaccine) has been confirmed for Dec 21, 2026 at 11:30 AM at Kansenshi Pediatric Clinic.','booking','In-System','2026-11-11 15:31:56'),(16,24,'260963912783','Your appointment for Augustine Nquzayikona (MMR Vaccine) has been rescheduled to Jan 05, 2027 at 02:00 PM at Kansenshi Pediatric Clinic.','rescheduled','In-System','2026-11-11 15:31:57'),(17,NULL,'0977000111','Hello *Sweep Guardian*,\n\nWelcome to VaxiCare! Your infant *Sweep Child* has been successfully registered by Nurse Demo Nurse.\n\nAccess your interactive vaccination passport & schedule here:\nhttp://127.0.0.1:8090/auth/login.php?phone=0977000111\n\n*Login ID (Phone):* 0977000111\n*Temporary Password:* password123\n\n_Please change your temporary password after logging in for the first time._',NULL,'Sent','2026-11-11 15:32:03'),(18,25,'260963912783','Your appointment for Augustine Nquzayikona (MMR Vaccine) has been confirmed for Dec 21, 2026 at 11:30 AM at Kansenshi Pediatric Clinic.','booking','In-System','2026-11-11 15:34:58'),(19,26,'260963912783','Your appointment for Augustine Nquzayikona (MMR Vaccine) has been rescheduled to Jan 05, 2027 at 02:00 PM at Kansenshi Pediatric Clinic.','rescheduled','In-System','2026-11-11 15:34:58'),(20,NULL,'0977000111','Hello *Sweep Guardian*,\n\nWelcome to VaxiCare! Your infant *Sweep Child* has been successfully registered by Nurse Demo Nurse.\n\nAccess your interactive vaccination passport & schedule here:\nhttp://127.0.0.1:8090/auth/login.php?phone=0977000111\n\n*Login ID (Phone):* 0977000111\n*Temporary Password:* password123\n\n_Please change your temporary password after logging in for the first time._',NULL,'Sent','2026-11-11 15:35:04'),(21,27,'260963912783','Your appointment for Augustine Nquzayikona (MMR Vaccine) has been confirmed for Dec 21, 2026 at 11:30 AM at Kansenshi Pediatric Clinic.','booking','In-System','2026-11-11 15:37:00'),(22,28,'260963912783','Your appointment for Augustine Nquzayikona (MMR Vaccine) has been rescheduled to Jan 05, 2027 at 02:00 PM at Kansenshi Pediatric Clinic.','rescheduled','In-System','2026-11-11 15:37:00'),(23,29,'260963912783','Your appointment for Augustine Nquzayikona (MMR Vaccine) has been confirmed for Jan 20, 2027 at 08:15 AM at Kansenshi Pediatric Clinic.','booking','In-System','2026-11-11 15:37:01'),(24,NULL,'0977000111','Hello *Sweep Guardian*,\n\nWelcome to VaxiCare! Your infant *Sweep Child* has been successfully registered by Nurse Demo Nurse.\n\nAccess your interactive vaccination passport & schedule here:\nhttp://127.0.0.1:8090/auth/login.php?phone=0977000111\n\n*Login ID (Phone):* 0977000111\n*Temporary Password:* password123\n\n_Please change your temporary password after logging in for the first time._',NULL,'Sent','2026-11-11 15:37:05'),(25,30,'260963912783','Your appointment for Augustine Nquzayikona (MMR Vaccine) has been confirmed for Dec 21, 2026 at 11:30 AM at Kansenshi Pediatric Clinic.','booking','In-System','2026-11-11 15:38:47'),(26,31,'260963912783','Your appointment for Augustine Nquzayikona (MMR Vaccine) has been rescheduled to Jan 05, 2027 at 02:00 PM at Kansenshi Pediatric Clinic.','rescheduled','In-System','2026-11-11 15:38:48'),(27,32,'260963912783','Your appointment for Augustine Nquzayikona (MMR Vaccine) has been confirmed for Jan 20, 2027 at 08:15 AM at Kansenshi Pediatric Clinic.','booking','In-System','2026-11-11 15:38:49'),(28,NULL,'0977000111','Hello *Sweep Guardian*,\n\nWelcome to VaxiCare! Your infant *Sweep Child* has been successfully registered by Nurse Demo Nurse.\n\nAccess your interactive vaccination passport & schedule here:\nhttp://127.0.0.1:8090/auth/login.php?phone=0977000111\n\n*Login ID (Phone):* 0977000111\n*Temporary Password:* password123\n\n_Please change your temporary password after logging in for the first time._',NULL,'Sent','2026-11-11 15:38:53'),(29,33,'260963912783','Your appointment for Augustine Nquzayikona (MMR Vaccine) has been confirmed for Dec 21, 2026 at 11:30 AM at Kansenshi Pediatric Clinic.','booking','In-System','2026-11-11 15:39:29'),(30,34,'260963912783','Your appointment for Augustine Nquzayikona (MMR Vaccine) has been rescheduled to Jan 05, 2027 at 02:00 PM at Kansenshi Pediatric Clinic.','rescheduled','In-System','2026-11-11 15:39:29'),(31,35,'260963912783','Your appointment for Augustine Nquzayikona (MMR Vaccine) has been confirmed for Jan 20, 2027 at 08:15 AM at Kansenshi Pediatric Clinic.','booking','In-System','2026-11-11 15:39:30'),(32,NULL,'0977000111','Hello *Sweep Guardian*,\n\nWelcome to VaxiCare! Your infant *Sweep Child* has been successfully registered by Nurse Demo Nurse.\n\nAccess your interactive vaccination passport & schedule here:\nhttp://127.0.0.1:8090/auth/login.php?phone=0977000111\n\n*Login ID (Phone):* 0977000111\n*Temporary Password:* password123\n\n_Please change your temporary password after logging in for the first time._',NULL,'Sent','2026-11-11 15:39:36'),(33,36,'260963912783','Your appointment for Augustine Nquzayikona (MMR Vaccine) has been confirmed for Dec 21, 2026 at 11:30 AM at Kansenshi Pediatric Clinic.','booking','In-System','2026-11-11 15:39:37'),(34,37,'260963912783','Your appointment for Augustine Nquzayikona (MMR Vaccine) has been rescheduled to Jan 05, 2027 at 02:00 PM at Kansenshi Pediatric Clinic.','rescheduled','In-System','2026-11-11 15:39:37'),(35,38,'260963912783','Your appointment for Augustine Nquzayikona (MMR Vaccine) has been confirmed for Jan 20, 2027 at 08:15 AM at Kansenshi Pediatric Clinic.','booking','In-System','2026-11-11 15:39:38'),(36,NULL,'0977000111','Hello *Sweep Guardian*,\n\nWelcome to VaxiCare! Your infant *Sweep Child* has been successfully registered by Nurse Demo Nurse.\n\nAccess your interactive vaccination passport & schedule here:\nhttp://127.0.0.1:8090/auth/login.php?phone=0977000111\n\n*Login ID (Phone):* 0977000111\n*Temporary Password:* password123\n\n_Please change your temporary password after logging in for the first time._',NULL,'Sent','2026-11-11 15:39:44');
/*!40000 ALTER TABLE `sms_logs` ENABLE KEYS */;

--
-- Table structure for table `suppliers`
--

DROP TABLE IF EXISTS `suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `suppliers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_general_ci DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `suppliers`
--

/*!40000 ALTER TABLE `suppliers` DISABLE KEYS */;
INSERT INTO `suppliers` VALUES (1,'BioMed Global Ltd','Active','2026-09-23 19:25:33'),(2,'Zambia Medicines Agency','Active','2026-09-23 19:25:33'),(3,'PharmaCare Logistics','Active','2026-09-23 19:25:33'),(4,'MediSupply Africa','Active','2026-09-23 19:25:33');
/*!40000 ALTER TABLE `suppliers` ENABLE KEYS */;

--
-- Table structure for table `triage_referrals`
--

DROP TABLE IF EXISTS `triage_referrals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `triage_referrals` (
  `id` int NOT NULL AUTO_INCREMENT,
  `child_id` int DEFAULT NULL,
  `mother_id` int DEFAULT NULL,
  `nurse_id` int NOT NULL,
  `doctor_id` int DEFAULT NULL,
  `reason` text COLLATE utf8mb4_general_ci NOT NULL,
  `status` enum('pending','reviewed') COLLATE utf8mb4_general_ci DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `child_id` (`child_id`),
  KEY `nurse_id` (`nurse_id`),
  KEY `doctor_id` (`doctor_id`),
  KEY `fk_mother_triage` (`mother_id`),
  CONSTRAINT `fk_mother_triage` FOREIGN KEY (`mother_id`) REFERENCES `mothers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `triage_referrals_ibfk_1` FOREIGN KEY (`child_id`) REFERENCES `children` (`id`) ON DELETE CASCADE,
  CONSTRAINT `triage_referrals_ibfk_2` FOREIGN KEY (`nurse_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `triage_referrals_ibfk_3` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `triage_referrals`
--

/*!40000 ALTER TABLE `triage_referrals` DISABLE KEYS */;
INSERT INTO `triage_referrals` VALUES (2,3,NULL,47,13,'Sweep referral','pending','2026-11-11 15:30:38'),(3,3,NULL,47,13,'Sweep referral','pending','2026-11-11 15:31:57'),(4,3,NULL,47,13,'Sweep referral','pending','2026-11-11 15:34:59'),(5,3,NULL,47,13,'Sweep referral','pending','2026-11-11 15:37:01'),(6,3,NULL,47,13,'Sweep referral','pending','2026-11-11 15:38:48'),(7,3,NULL,47,13,'Sweep referral','pending','2026-11-11 15:39:30'),(8,3,NULL,47,13,'Sweep referral','pending','2026-11-11 15:39:37');
/*!40000 ALTER TABLE `triage_referrals` ENABLE KEYS */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `phone` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `email` varchar(150) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `role` enum('guardian','nurse','pharmacist','pharmacy','doctor','admin') COLLATE utf8mb4_general_ci DEFAULT 'guardian',
  `profile_pic` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `is_on_duty` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phone` (`phone`)
) ENGINE=InnoDB AUTO_INCREMENT=49 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'Admin Director Mukuka','0970000001',NULL,'$2y$10$j40rUqOriEiGTThLQFGlKOUvRNK2EAs0ye.93Y.KlLHUCwoTa/igi','admin',NULL,1,'2026-09-23 19:25:33'),(8,'Augustine Yikona','0971725900','augustynyikona10@gmail.com','$2y$10$BPssI7IF4bKbE/2Pv70.e.Jkam4HxB.vAvexoJCq.AIAkOLtq3JDO','nurse',NULL,1,'2026-11-04 17:14:16'),(11,'August Nguza','0963912783','2106656@northrise.net','$2y$10$VeTrz6EP9BwLSrsHf87j9.vnaqxzX6rEqENVgGJgkuoyH9CS08M1q','guardian',NULL,1,'2026-11-04 17:59:18'),(12,'Demo Admin','0970000011','demo.admin@vaxicare.local','$2y$10$0ELotiiZ/yVoSCVH3vwk0ei9EnaFDttX3AX79G1D4dkEQBgB3gbDK','admin',NULL,1,'2026-11-11 14:24:16'),(13,'Dr. Demo Doctor','0970000012','demo.doctor@vaxicare.local','$2y$10$0ELotiiZ/yVoSCVH3vwk0ei9EnaFDttX3AX79G1D4dkEQBgB3gbDK','doctor',NULL,1,'2026-11-11 14:24:16'),(14,'Demo Pharmacist','0970000013','demo.pharmacist@vaxicare.local','$2y$10$k7sXomdQpTnPzijoeVq0/Oh4p5RjCgotFi8SbX0RuXvNPRgkDDIgG','pharmacy',NULL,1,'2026-11-11 14:24:16'),(15,'Chanda Mwale','0970000101','chanda.mwale@example.com','$2y$10$A7x3r1gwCemBA/uh1ofrIe0WfPMM7TOJeq3ok.0gjtk4KyUGaCFrG','guardian',NULL,0,'2026-11-11 14:27:12'),(16,'Nsofwa Banda','0970000102','nsofwa.banda@example.com','$2y$10$A7x3r1gwCemBA/uh1ofrIe0WfPMM7TOJeq3ok.0gjtk4KyUGaCFrG','guardian',NULL,0,'2026-11-11 14:27:12'),(17,'Mutale Phiri','0970000103','mutale.phiri@example.com','$2y$10$A7x3r1gwCemBA/uh1ofrIe0WfPMM7TOJeq3ok.0gjtk4KyUGaCFrG','guardian',NULL,0,'2026-11-11 14:27:12'),(18,'Thandiwe Zulu','0970000104','thandiwe.zulu@example.com','$2y$10$A7x3r1gwCemBA/uh1ofrIe0WfPMM7TOJeq3ok.0gjtk4KyUGaCFrG','guardian',NULL,0,'2026-11-11 14:27:12'),(19,'Kelvin Sakala','0970000105','kelvin.sakala@example.com','$2y$10$A7x3r1gwCemBA/uh1ofrIe0WfPMM7TOJeq3ok.0gjtk4KyUGaCFrG','guardian',NULL,0,'2026-11-11 14:27:12'),(20,'Grace Tembo','0970000106','grace.tembo@example.com','$2y$10$A7x3r1gwCemBA/uh1ofrIe0WfPMM7TOJeq3ok.0gjtk4KyUGaCFrG','guardian',NULL,0,'2026-11-11 14:27:12');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;

--
-- Table structure for table `vaccine_batches`
--

DROP TABLE IF EXISTS `vaccine_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `vaccine_batches` (
  `id` int NOT NULL AUTO_INCREMENT,
  `vaccine_id` int NOT NULL,
  `batch_number` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `quantity` int NOT NULL,
  `expiry_date` date NOT NULL,
  `location` varchar(50) COLLATE utf8mb4_general_ci DEFAULT 'Main Storage',
  `supplier_id` int DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_general_ci DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `vaccine_id` (`vaccine_id`),
  KEY `supplier_id` (`supplier_id`),
  CONSTRAINT `vaccine_batches_ibfk_1` FOREIGN KEY (`vaccine_id`) REFERENCES `vaccines` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vaccine_batches_ibfk_2` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `vaccine_batches`
--

/*!40000 ALTER TABLE `vaccine_batches` DISABLE KEYS */;
INSERT INTO `vaccine_batches` VALUES (1,1,'BATCH-20881',22,'2028-02-23','Main Pharmacy Cold Storage',1,'Active','2026-09-23 19:25:33'),(2,2,'BATCH-32017',15,'2028-02-23','Main Pharmacy Cold Storage',4,'Active','2026-09-23 19:25:33'),(3,3,'BATCH-96621',10,'2027-06-23','Main Pharmacy Cold Storage',3,'Active','2026-09-23 19:25:33'),(4,4,'BATCH-75784',18,'2028-01-23','Main Pharmacy Cold Storage',3,'Active','2026-09-23 19:25:33'),(5,5,'BATCH-72715',0,'2028-09-23','Main Pharmacy Cold Storage',1,'Active','2026-09-23 19:25:33'),(6,6,'BATCH-65098',0,'2027-06-23','Main Pharmacy Cold Storage',4,'Active','2026-09-23 19:25:33'),(7,7,'BATCH-33295',499,'2028-04-23','Main Pharmacy Cold Storage',2,'Active','2026-09-23 19:25:33'),(8,8,'BATCH-87577',500,'2028-06-23','Main Pharmacy Cold Storage',3,'Active','2026-09-23 19:25:33'),(9,9,'BATCH-21015',710,'2028-04-23','Main Pharmacy Cold Storage',3,'Active','2026-09-23 19:25:33'),(10,10,'BATCH-15037',989,'2028-05-23','Main Pharmacy Cold Storage',2,'Active','2026-09-23 19:25:33'),(11,11,'BATCH-87470',380,'2027-07-23','Main Pharmacy Cold Storage',4,'Active','2026-09-23 19:25:33'),(12,12,'BATCH-67934',650,'2027-08-23','Main Pharmacy Cold Storage',3,'Active','2026-09-23 19:25:33'),(13,1,'B4518',100,'2028-06-13','Main Storage',4,'Active','2026-10-13 06:41:16');
/*!40000 ALTER TABLE `vaccine_batches` ENABLE KEYS */;

--
-- Table structure for table `vaccines`
--

DROP TABLE IF EXISTS `vaccines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `vaccines` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `description` text COLLATE utf8mb4_general_ci,
  `current_stock` int DEFAULT '500',
  `low_stock_threshold` int DEFAULT '50',
  `manufacturer` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `vaccines`
--

/*!40000 ALTER TABLE `vaccines` DISABLE KEYS */;
INSERT INTO `vaccines` VALUES (1,'MMR Vaccine','Measles, Mumps, Rubella',133,100,'GlaxoSmithKline'),(2,'HPV Vaccine','Gardasil 9 Human Papillomavirus',15,120,'Merck & Co'),(3,'Flu Vaccine','Quadrivalent Influenza Vaccine',10,150,'Sanofi Pasteur'),(4,'RotaVirus Vaccine','Rotarix Oral Suspension',18,100,'GSK Biologicals'),(5,'Yellow Fever Vaccine','Live attenuated yellow fever',0,50,'Sanofi Pasteur'),(6,'Typhoid Vaccine','Typhoid Vi polysaccharide',0,50,'Bharat Biotech'),(7,'DPT Vaccine','Diphtheria, Pertussis, Tetanus',499,100,'Serum Institute'),(8,'Measles Vaccine','Live Attenuated Measles Doses',500,100,'WHO Prequalified'),(9,'BCG Vaccine','Tuberculosis Protection',710,150,'Statens Serum Institut'),(10,'OPV (Oral Polio)','Bivalent Oral Poliovirus',989,200,'Bio-Med Pvt Ltd'),(11,'Hepatitis B Vaccine','Recombinant HepB Vaccine',380,80,'Sanofi'),(12,'Vitamin A Supplement','High-potency Vitamin A Capsules',650,100,'UNICEF Global'),(13,'PCV (Pneumococcal)','Protects against pneumonia, meningitis, and bacteremia.',500,50,'MCH Standard'),(14,'Pentavalent (DPT-HepB-Hib)','Protects against Diphtheria, Pertussis, Tetanus, Hepatitis B, and Hib.',500,50,'MCH Standard');
/*!40000 ALTER TABLE `vaccines` ENABLE KEYS */;

--
-- Dumping routines for database 'vaxicare'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-11 17:54:14

SET FOREIGN_KEY_CHECKS = 1;
