/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19-11.8.6-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: firol
-- ------------------------------------------------------
-- Server version	11.8.6-MariaDB-ubu2404

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;

--
-- Table structure for table `account_users`
--

DROP TABLE IF EXISTS `account_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `account_users` (
  `account_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `role` varchar(32) NOT NULL DEFAULT 'technician',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`account_id`,`user_id`),
  KEY `fk_au_user` (`user_id`),
  CONSTRAINT `fk_au_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_au_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `account_users`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `account_users` WRITE;
/*!40000 ALTER TABLE `account_users` DISABLE KEYS */;
INSERT INTO `account_users` VALUES
(1,1,'main',1,'2026-05-07 09:53:04'),
(2,2,'main',1,'2026-05-07 10:10:45'),
(3,3,'main',1,'2026-05-07 10:11:36'),
(4,4,'main',1,'2026-05-07 12:30:19'),
(5,5,'main',1,'2026-05-07 12:30:47'),
(6,6,'main',1,'2026-05-07 13:13:19'),
(7,7,'main',1,'2026-05-08 09:18:59'),
(8,8,'main',1,'2026-05-08 13:07:38'),
(9,9,'main',1,'2026-05-08 13:10:27'),
(10,10,'main',1,'2026-05-08 13:10:48'),
(11,11,'main',1,'2026-05-08 13:29:57'),
(12,12,'main',1,'2026-05-11 07:43:03'),
(12,13,'technician',1,'2026-05-11 07:43:50'),
(13,14,'main',1,'2026-05-11 09:38:59'),
(14,15,'main',1,'2026-05-11 09:39:16'),
(15,16,'main',1,'2026-05-11 10:08:37');
/*!40000 ALTER TABLE `account_users` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `accounts`
--

DROP TABLE IF EXISTS `accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_company_name` varchar(191) NOT NULL,
  `invoice_street` varchar(191) DEFAULT NULL,
  `invoice_postal_code` varchar(32) DEFAULT NULL,
  `invoice_city` varchar(128) DEFAULT NULL,
  `invoice_country` varchar(64) NOT NULL DEFAULT 'Slovensko',
  `invoice_ico` varchar(32) DEFAULT NULL,
  `invoice_dic` varchar(32) DEFAULT NULL,
  `invoice_ic_dph` varchar(32) DEFAULT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `theme_color` varchar(7) DEFAULT NULL,
  `stripe_customer_id` varchar(64) DEFAULT NULL,
  `stripe_subscription_id` varchar(64) DEFAULT NULL,
  `idoklad_contact_id` int(10) unsigned DEFAULT NULL,
  `stripe_status` varchar(32) DEFAULT NULL,
  `billing_period` varchar(16) DEFAULT NULL,
  `subscription_end_date` date NOT NULL,
  `main_user_id` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_accounts_stripe_customer` (`stripe_customer_id`),
  KEY `fk_accounts_main_user` (`main_user_id`),
  CONSTRAINT `fk_accounts_main_user` FOREIGN KEY (`main_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `accounts`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `accounts` WRITE;
/*!40000 ALTER TABLE `accounts` DISABLE KEYS */;
INSERT INTO `accounts` VALUES
(1,'Firol Test s.r.o.',NULL,NULL,NULL,'Slovensko',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-21',1,'2026-05-07 09:53:04','2026-05-07 09:53:04'),
(2,'Test Company',NULL,NULL,NULL,'Slovensko',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-21',2,'2026-05-07 10:10:45','2026-05-07 10:10:45'),
(3,'Petra Test s.r.o.',NULL,NULL,NULL,'Slovensko',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-21',3,'2026-05-07 10:11:36','2026-05-07 10:11:36'),
(4,'Test SK',NULL,NULL,NULL,'Slovensko',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-21',4,'2026-05-07 12:30:19','2026-05-07 12:30:19'),
(5,'Test SK',NULL,NULL,NULL,'Slovensko',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-21',5,'2026-05-07 12:30:47','2026-05-07 12:30:47'),
(6,'PDF Test SK',NULL,NULL,NULL,'Slovensko',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-21',6,'2026-05-07 13:13:19','2026-05-07 13:13:19'),
(7,'Cstudios s.r.o.',NULL,NULL,NULL,'Slovensko',NULL,NULL,NULL,'accounts/7/logo.png','#613498',NULL,NULL,NULL,NULL,NULL,'2026-05-22',7,'2026-05-08 09:18:59','2026-05-08 13:25:35'),
(8,'Test 2',NULL,NULL,NULL,'Slovensko',NULL,NULL,NULL,NULL,'#ff00ff',NULL,NULL,NULL,NULL,NULL,'2026-05-22',8,'2026-05-08 13:07:38','2026-05-08 13:08:53'),
(9,'Brand Test s.r.o.',NULL,NULL,NULL,'Slovensko',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-22',9,'2026-05-08 13:10:27','2026-05-08 13:10:28'),
(10,'PDF Brand s.r.o.',NULL,NULL,NULL,'Slovensko',NULL,NULL,NULL,'accounts/10/logo.png','#0a7d3a',NULL,NULL,NULL,NULL,NULL,'2026-05-22',10,'2026-05-08 13:10:48','2026-05-08 13:10:48'),
(11,'5b s.r.o.',NULL,NULL,NULL,'Slovensko',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-22',11,'2026-05-08 13:29:57','2026-05-08 13:29:57'),
(12,'Test 5c',NULL,NULL,NULL,'Slovensko',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2027-12-31',12,'2026-05-11 07:43:03','2026-05-11 08:04:55'),
(13,'Test 6b',NULL,NULL,NULL,'Slovensko',NULL,NULL,NULL,NULL,NULL,'cus_UUpyzQFUTrAPQ3',NULL,NULL,NULL,'monthly','2026-05-25',14,'2026-05-11 09:38:59','2026-05-11 09:39:01'),
(14,'Test 6b',NULL,NULL,NULL,'Slovensko',NULL,NULL,NULL,NULL,NULL,'cus_UUpygq9Es9aH8Z','sub_1TVqXeCzYcIyoDNG3XBKiBdA',24415813,'active','monthly','2026-06-11',15,'2026-05-11 09:39:16','2026-05-11 10:39:25'),
(15,'Firol sro','Testovacia 45','123 45','Dunajska Streda','Slovensko','123','456','789',NULL,NULL,'cus_UUqSIEThG542el','sub_1TVqmpCzYcIyoDNG57lYYTYj',NULL,'trialing','monthly','2026-05-25',16,'2026-05-11 10:08:37','2026-05-11 10:57:08');
/*!40000 ALTER TABLE `accounts` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `companies`
--

DROP TABLE IF EXISTS `companies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `companies` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int(10) unsigned NOT NULL,
  `name` varchar(191) NOT NULL,
  `ico` varchar(32) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `contact` text DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_companies_account` (`account_id`,`archived_at`),
  KEY `idx_companies_name` (`account_id`,`name`),
  CONSTRAINT `fk_companies_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `companies`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `companies` WRITE;
/*!40000 ALTER TABLE `companies` DISABLE KEYS */;
INSERT INTO `companies` VALUES
(1,1,'ALFA Trade s.r.o.','12345678','Hlavná 12, 851 01 Bratislava','info@alfa.sk · +421 900 111 222',NULL,'2026-05-07 11:22:09','2026-05-07 11:22:09'),
(2,1,'BETA Logistics s.r.o.','87654321',NULL,NULL,NULL,'2026-05-07 11:27:23','2026-05-07 11:27:23'),
(3,2,'Test s.r.o.','789',NULL,NULL,NULL,'2026-05-07 12:17:58','2026-05-07 12:17:58'),
(4,5,'Phase3 Test','12345678','x','y',NULL,'2026-05-07 12:31:04','2026-05-07 12:31:04'),
(5,6,'PDF Demo s.r.o.','99887766','Test 1, Bratislava','info@pdf.sk',NULL,'2026-05-07 13:13:20','2026-05-07 13:13:20'),
(6,7,'ALFA Trade s.r.o.','12345678','Hlavná, 12, Bratislava','info@firma.sk',NULL,'2026-05-08 09:23:53','2026-05-08 09:23:53'),
(7,10,'Test Firma','12345678','Test ul. 1, Bratislava',NULL,NULL,'2026-05-08 13:10:49','2026-05-08 13:10:49'),
(8,11,'Test',NULL,NULL,NULL,NULL,'2026-05-08 13:29:57','2026-05-08 13:29:57'),
(9,12,'Test','12345678',NULL,NULL,NULL,'2026-05-11 08:04:18','2026-05-11 08:04:18');
/*!40000 ALTER TABLE `companies` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `document_sequences`
--

DROP TABLE IF EXISTS `document_sequences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_sequences` (
  `account_id` int(10) unsigned NOT NULL,
  `type` varchar(32) NOT NULL,
  `year` smallint(5) unsigned NOT NULL,
  `last_seq` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`account_id`,`type`,`year`),
  CONSTRAINT `fk_sequences_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `document_sequences`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `document_sequences` WRITE;
/*!40000 ALTER TABLE `document_sequences` DISABLE KEYS */;
INSERT INTO `document_sequences` VALUES
(2,'hydranty',2026,1),
(2,'pu_udrzba',2026,1),
(2,'rphp',2026,2),
(6,'hydranty',2026,1),
(6,'nudzove_osvetlenie',2026,1),
(6,'oprava_ts_rphp',2026,1),
(6,'poziarna_kniha',2026,1),
(6,'pu_akcieschopnost',2026,1),
(6,'pu_udrzba',2026,1),
(6,'rphp',2026,1),
(6,'rphp',2027,1),
(6,'skolenie',2026,1),
(6,'ts_hadic',2026,1),
(10,'rphp',2026,1);
/*!40000 ALTER TABLE `document_sequences` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `documents`
--

DROP TABLE IF EXISTS `documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `documents` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int(10) unsigned NOT NULL,
  `parent_type` enum('inspection','training') NOT NULL,
  `parent_id` int(10) unsigned NOT NULL,
  `type` varchar(32) NOT NULL,
  `number` varchar(40) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `generated_at` datetime NOT NULL DEFAULT current_timestamp(),
  `signed` tinyint(1) NOT NULL DEFAULT 1,
  `signed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_documents_number` (`account_id`,`number`),
  KEY `idx_documents_account` (`account_id`,`generated_at`),
  KEY `idx_documents_parent` (`parent_type`,`parent_id`),
  CONSTRAINT `fk_documents_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `documents`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `documents` WRITE;
/*!40000 ALTER TABLE `documents` DISABLE KEYS */;
INSERT INTO `documents` VALUES
(1,6,'inspection',6,'rphp','RPHP-2026-001','documents/6/2026/RPHP-2026-001.pdf','2026-05-07 13:13:24',1,'2026-05-07 13:13:24'),
(2,2,'inspection',5,'rphp','RPHP-2026-001','documents/2/2026/RPHP-2026-001.pdf','2026-05-07 13:20:12',1,'2026-05-07 13:20:12'),
(3,6,'inspection',7,'rphp','RPHP-2027-001','documents/6/2027/RPHP-2027-001.pdf','2026-05-07 13:23:38',1,'2026-05-07 13:23:38'),
(4,2,'inspection',8,'rphp','RPHP-2026-002','documents/2/2026/RPHP-2026-002.pdf','2026-05-07 13:29:22',1,'2026-05-07 13:29:22'),
(5,6,'inspection',10,'hydranty','HYD-2026-001','documents/6/2026/HYD-2026-001.pdf','2026-05-07 13:57:37',1,'2026-05-07 13:57:37'),
(6,2,'inspection',11,'hydranty','HYD-2026-001','documents/2/2026/HYD-2026-001.pdf','2026-05-07 14:15:26',1,'2026-05-07 14:15:26'),
(7,6,'inspection',12,'oprava_ts_rphp','OPR-RPHP-2026-001','documents/6/2026/OPR-RPHP-2026-001.pdf','2026-05-07 14:20:20',1,'2026-05-07 14:20:20'),
(8,6,'inspection',13,'poziarna_kniha','PK-2026-001','documents/6/2026/PK-2026-001.pdf','2026-05-07 14:25:58',1,'2026-05-07 14:25:58'),
(9,6,'inspection',15,'pu_akcieschopnost','PU-AK-2026-001','documents/6/2026/PU-AK-2026-001.pdf','2026-05-07 14:33:06',1,'2026-05-07 14:33:06'),
(10,6,'inspection',16,'pu_udrzba','PU-UD-2026-001','documents/6/2026/PU-UD-2026-001.pdf','2026-05-07 14:33:07',1,'2026-05-07 14:33:07'),
(11,6,'inspection',17,'nudzove_osvetlenie','NO-2026-001','documents/6/2026/NO-2026-001.pdf','2026-05-07 14:41:15',1,'2026-05-07 14:41:15'),
(12,6,'inspection',18,'ts_hadic','TS-HAD-2026-001','documents/6/2026/TS-HAD-2026-001.pdf','2026-05-07 14:41:16',1,'2026-05-07 14:41:16'),
(13,2,'inspection',19,'pu_udrzba','PU-UD-2026-001','documents/2/2026/PU-UD-2026-001.pdf','2026-05-08 08:01:13',1,'2026-05-08 08:01:13'),
(14,6,'training',1,'skolenie','SKO-2026-001','documents/6/2026/SKO-2026-001.pdf','2026-05-08 08:59:51',1,'2026-05-08 08:59:51'),
(15,10,'inspection',20,'rphp','RPHP-2026-001','documents/10/2026/RPHP-2026-001.pdf','2026-05-08 13:10:51',1,'2026-05-08 13:10:51');
/*!40000 ALTER TABLE `documents` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `facilities`
--

DROP TABLE IF EXISTS `facilities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `facilities` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int(10) unsigned NOT NULL,
  `company_id` int(10) unsigned NOT NULL,
  `name` varchar(191) NOT NULL,
  `address` text DEFAULT NULL,
  `contact_person` varchar(191) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_facilities_account` (`account_id`,`archived_at`),
  KEY `idx_facilities_company` (`company_id`,`archived_at`),
  CONSTRAINT `fk_facilities_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_facilities_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `facilities`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `facilities` WRITE;
/*!40000 ALTER TABLE `facilities` DISABLE KEYS */;
INSERT INTO `facilities` VALUES
(1,1,1,'Sklad A','Priemyselná 5','Peter Vedúci','vstup len cez recepciu',NULL,'2026-05-07 11:22:09','2026-05-07 11:22:09'),
(2,2,3,'Test sklad',NULL,NULL,NULL,'2026-05-07 12:19:29','2026-05-07 12:18:54','2026-05-07 12:19:29'),
(3,2,3,'Test prevadzka',NULL,NULL,NULL,NULL,'2026-05-07 12:22:07','2026-05-07 12:22:07'),
(4,5,4,'Sklad RPHP','Sklad ul 5','Vedouci',NULL,NULL,'2026-05-07 12:31:24','2026-05-07 12:31:24'),
(5,6,5,'Hala A','Test 1','Janko',NULL,NULL,'2026-05-07 13:13:20','2026-05-07 13:13:20'),
(6,10,7,'Test Prevadzka','Adresa 1',NULL,NULL,NULL,'2026-05-08 13:10:49','2026-05-08 13:10:49'),
(7,11,8,'Prevadzka A',NULL,NULL,NULL,NULL,'2026-05-08 13:29:57','2026-05-08 13:29:57'),
(8,7,6,'Sklad A',NULL,NULL,NULL,NULL,'2026-05-08 13:38:46','2026-05-08 13:38:46');
/*!40000 ALTER TABLE `facilities` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `inspection_items`
--

DROP TABLE IF EXISTS `inspection_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inspection_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `inspection_id` int(10) unsigned NOT NULL,
  `position` smallint(5) unsigned NOT NULL,
  `fields` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`fields`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_items_inspection` (`inspection_id`,`position`),
  CONSTRAINT `fk_items_inspection` FOREIGN KEY (`inspection_id`) REFERENCES `inspections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inspection_items`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `inspection_items` WRITE;
/*!40000 ALTER TABLE `inspection_items` DISABLE KEYS */;
INSERT INTO `inspection_items` VALUES
(2,1,2,'{\"manufacturer\":\"Hastex\",\"type\":\"CO2-5\",\"serial\":\"HX-22-099\",\"year\":2022,\"location\":\"Sklad B\",\"status\":\"O\",\"notes\":\"Treba opravu\"}','2026-05-07 12:53:36','2026-05-07 12:53:50'),
(3,5,1,'{\"manufacturer\":\"Test Manufacturer\",\"type\":\"P6\",\"serial\":\"asdasd\",\"year\":2025,\"location\":\"vcod\",\"status\":\"A\",\"notes\":\"ok\"}','2026-05-07 12:59:51','2026-05-07 12:59:51'),
(4,5,2,'{\"manufacturer\":\"Dalsi\",\"type\":\"Stroj\",\"serial\":\"dasds\",\"year\":2022,\"location\":\"A\",\"status\":\"TS\",\"notes\":\"nevhodny\"}','2026-05-07 13:00:19','2026-05-07 13:00:19'),
(5,5,3,'{\"manufacturer\":\"vyrobca\",\"type\":\"P2\",\"serial\":\"asdad\",\"year\":2024,\"location\":\"hala\",\"status\":\"O\",\"notes\":null}','2026-05-07 13:00:44','2026-05-07 13:00:44'),
(7,5,5,'{\"manufacturer\":\"Test Company\",\"type\":\"P2\",\"serial\":\"4456\",\"year\":2025,\"location\":\"hala\",\"status\":\"A\",\"notes\":\"ok\"}','2026-05-07 13:01:55','2026-05-07 13:01:55'),
(8,6,1,'{\"manufacturer\":\"Gloria\",\"type\":\"P6\",\"serial\":\"S-1\",\"year\":2021,\"location\":\"Hala 1\",\"status\":\"A\",\"notes\":null}','2026-05-07 13:13:21','2026-05-07 13:13:21'),
(9,6,2,'{\"manufacturer\":\"Gloria\",\"type\":\"P6\",\"serial\":\"S-2\",\"year\":2022,\"location\":\"Hala 2\",\"status\":\"TS\",\"notes\":null}','2026-05-07 13:13:21','2026-05-07 13:13:21'),
(10,6,3,'{\"manufacturer\":\"Gloria\",\"type\":\"P6\",\"serial\":\"S-3\",\"year\":2023,\"location\":\"Hala 3\",\"status\":\"O\",\"notes\":null}','2026-05-07 13:13:21','2026-05-07 13:13:21'),
(11,7,1,'{\"manufacturer\":\"Gloria\",\"type\":\"P6\",\"serial\":\"S-1\",\"year\":2021,\"location\":\"Hala 1\",\"status\":\"A\",\"notes\":null}','2026-05-07 13:23:23','2026-05-07 13:23:23'),
(12,7,2,'{\"manufacturer\":\"Gloria\",\"type\":\"P6\",\"serial\":\"S-2\",\"year\":2022,\"location\":\"Hala 2\",\"status\":\"TS\",\"notes\":null}','2026-05-07 13:23:23','2026-05-07 13:23:23'),
(13,7,3,'{\"manufacturer\":\"Gloria\",\"type\":\"P6\",\"serial\":\"S-3\",\"year\":2023,\"location\":\"Hala 3\",\"status\":\"O\",\"notes\":null}','2026-05-07 13:23:23','2026-05-07 13:23:23'),
(14,8,1,'{\"manufacturer\":\"Test Manufacturer\",\"type\":\"P6\",\"serial\":\"asdasd\",\"year\":2025,\"location\":\"vcod\",\"status\":\"A\",\"notes\":\"ok\"}','2026-05-07 13:28:54','2026-05-07 13:28:54'),
(15,8,2,'{\"manufacturer\":\"Dalsi\",\"type\":\"Stroj\",\"serial\":\"dasds\",\"year\":2022,\"location\":\"A\",\"status\":\"TS\",\"notes\":\"nevhodny\"}','2026-05-07 13:28:54','2026-05-07 13:28:54'),
(16,8,3,'{\"manufacturer\":\"vyrobca\",\"type\":\"P2\",\"serial\":\"asdad\",\"year\":2024,\"location\":\"hala\",\"status\":\"O\",\"notes\":null}','2026-05-07 13:28:54','2026-05-07 13:28:54'),
(17,8,5,'{\"manufacturer\":\"Test Company\",\"type\":\"P2\",\"serial\":\"4456\",\"year\":2025,\"location\":\"hala\",\"status\":\"A\",\"notes\":\"ok\"}','2026-05-07 13:28:54','2026-05-07 13:28:54'),
(21,9,1,'{\"manufacturer\":\"Test Manufacturer\",\"type\":\"P6\",\"serial\":\"asdasd\",\"year\":2025,\"location\":\"vcod\",\"status\":\"A\",\"notes\":\"ok\"}','2026-05-07 13:30:26','2026-05-07 13:30:26'),
(22,9,2,'{\"manufacturer\":\"Dalsi\",\"type\":\"Stroj\",\"serial\":\"dasds\",\"year\":2022,\"location\":\"A\",\"status\":\"TS\",\"notes\":\"nevhodny\"}','2026-05-07 13:30:26','2026-05-07 13:30:26'),
(23,9,3,'{\"manufacturer\":\"vyrobca\",\"type\":\"P2\",\"serial\":\"asdad\",\"year\":2024,\"location\":\"hala\",\"status\":\"O\",\"notes\":null}','2026-05-07 13:30:26','2026-05-07 13:30:26'),
(24,9,5,'{\"manufacturer\":\"Test Company\",\"type\":\"P2\",\"serial\":\"4456\",\"year\":2025,\"location\":\"hala\",\"status\":\"A\",\"notes\":\"ok\"}','2026-05-07 13:30:26','2026-05-07 13:30:26'),
(28,10,1,'{\"type\":\"DN52\",\"type_other\":null,\"location\":\"Vchod hala A\",\"hose_count\":2,\"hs\":0.55,\"hd\":0.42,\"q\":1.8,\"defects\":null,\"result\":\"vyhovuje\"}','2026-05-07 13:57:36','2026-05-07 13:57:36'),
(29,10,2,'{\"type\":\"other\",\"type_other\":\"DN40 specialny\",\"location\":\"Sklad B\",\"hose_count\":1,\"hs\":0.3,\"hd\":0.2,\"q\":0.8,\"defects\":\"Korozia spojky\",\"result\":\"nevyhovuje\"}','2026-05-07 13:57:36','2026-05-07 13:57:36'),
(30,11,1,'{\"type\":\"DN52\",\"type_other\":null,\"location\":\"P6\",\"hose_count\":1,\"hs\":0.55,\"hd\":0.42,\"q\":1.8,\"defects\":\"korozia\",\"result\":\"vyhovuje\"}','2026-05-07 14:14:58','2026-05-07 14:14:58'),
(31,11,2,'{\"type\":\"other\",\"type_other\":\"DN40\",\"location\":\"vchod\",\"hose_count\":1,\"hs\":0.55,\"hd\":0.46,\"q\":2.1,\"defects\":null,\"result\":\"vyhovuje\"}','2026-05-07 14:15:18','2026-05-07 14:15:18'),
(32,12,1,'{\"manufacturer\":\"Gloria\",\"type\":\"P6\",\"serial\":\"GLR-19-001\",\"year\":2019,\"location\":\"Hala A\",\"actions\":[\"tlakova_skuska\",\"plnenie\"],\"notes\":\"5-rocna TS\"}','2026-05-07 14:20:19','2026-05-07 14:20:19'),
(33,12,2,'{\"manufacturer\":\"Hastex\",\"type\":\"CO2-5\",\"serial\":\"HX-20-022\",\"year\":2020,\"location\":\"Sklad\",\"actions\":[\"oprava\"],\"notes\":\"Vymeneny manometer\"}','2026-05-07 14:20:19','2026-05-07 14:20:19'),
(34,13,1,'{\"workspaces\":\"Hala A, kancelarie 1.NP\",\"activities\":[\"visual_check\",\"rphp_check\",\"escape_routes_check\"],\"activities_other\":\"Skuska poplachu\",\"result\":\"bez_nedostatkov\",\"notes\":null}','2026-05-07 14:25:57','2026-05-07 14:25:57'),
(35,15,1,'{\"kind\":\"dvere\",\"identifier\":\"PD-A1\",\"manufacturer\":\"Hormann\",\"location\":\"Hala A vchod\",\"result\":\"vyhovuje\",\"notes\":null}','2026-05-07 14:33:05','2026-05-07 14:33:05'),
(36,16,1,'{\"kind\":\"klapka\",\"identifier\":\"KL-03\",\"location\":\"VZT - 2.NP\",\"maintenance_work\":\"Mazanie pantov\",\"result\":\"vyhovuje\",\"notes\":null}','2026-05-07 14:33:06','2026-05-07 14:33:06'),
(37,17,1,'{\"luminaire_type\":\"LED 3W\",\"manufacturer\":\"Eaton\",\"location\":\"Chodba 2.NP\",\"duration_min\":180,\"result\":\"vyhovuje\",\"notes\":null}','2026-05-07 14:41:14','2026-05-07 14:41:14'),
(38,18,1,'{\"hose_type\":\"C52\",\"serial\":\"ZP-2020-014\",\"location\":\"Hydrant H-2\",\"test_pressure\":1.2,\"result\":\"vyhovuje\",\"notes\":null}','2026-05-07 14:41:16','2026-05-07 14:41:16'),
(39,19,1,'{\"kind\":\"klapka\",\"identifier\":\"55s6sd5d4\",\"location\":\"hala A\",\"maintenance_work\":\"mazanie\",\"result\":\"vyhovuje\",\"notes\":\"vymenit zamok\"}','2026-05-08 08:01:00','2026-05-08 08:01:00'),
(40,20,1,'{\"manufacturer\":\"GLORIA\",\"type\":\"P6\",\"serial\":\"SN001\",\"year\":2024,\"location\":\"Vstup\",\"status\":\"A\",\"notes\":null}','2026-05-08 13:10:49','2026-05-08 13:10:49');
/*!40000 ALTER TABLE `inspection_items` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `inspections`
--

DROP TABLE IF EXISTS `inspections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inspections` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int(10) unsigned NOT NULL,
  `company_id` int(10) unsigned NOT NULL,
  `facility_id` int(10) unsigned NOT NULL,
  `type` varchar(32) NOT NULL,
  `periodicity_months` tinyint(3) unsigned NOT NULL,
  `executed_on` date DEFAULT NULL,
  `inspector_user_id` int(10) unsigned NOT NULL,
  `status` enum('draft','finalized') NOT NULL DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_inspections_account` (`account_id`,`archived_at`),
  KEY `idx_inspections_facility` (`facility_id`,`archived_at`,`executed_on`),
  KEY `idx_inspections_company` (`company_id`,`archived_at`,`executed_on`),
  KEY `idx_inspections_type_date` (`account_id`,`type`,`executed_on`),
  KEY `fk_inspections_inspector` (`inspector_user_id`),
  CONSTRAINT `fk_inspections_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inspections_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inspections_facility` FOREIGN KEY (`facility_id`) REFERENCES `facilities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inspections_inspector` FOREIGN KEY (`inspector_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inspections`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `inspections` WRITE;
/*!40000 ALTER TABLE `inspections` DISABLE KEYS */;
INSERT INTO `inspections` VALUES
(1,5,4,4,'rphp',12,'2026-05-07',5,'draft','test inspection',NULL,'2026-05-07 12:31:24','2026-05-07 12:31:24'),
(2,2,3,3,'rphp',24,'2026-05-07',2,'draft','pokazene dvere',NULL,'2026-05-07 12:43:53','2026-05-07 12:43:53'),
(3,2,3,3,'rphp',24,'2026-05-07',2,'draft',NULL,NULL,'2026-05-07 12:44:51','2026-05-07 12:44:51'),
(4,2,3,3,'rphp',12,'2026-05-07',2,'draft',NULL,NULL,'2026-05-07 12:46:02','2026-05-07 12:46:02'),
(5,2,3,3,'rphp',12,'2026-05-22',2,'finalized',NULL,NULL,'2026-05-07 12:59:12','2026-05-07 13:20:12'),
(6,6,5,5,'rphp',12,'2026-05-07',6,'finalized','Testovacia kontrola pre PDF',NULL,'2026-05-07 13:13:20','2026-05-07 13:13:24'),
(7,6,5,5,'rphp',12,'2027-05-07',6,'finalized','Testovacia kontrola pre PDF',NULL,'2026-05-07 13:23:23','2026-05-07 13:23:38'),
(8,2,3,3,'rphp',12,'2026-05-22',2,'finalized',NULL,NULL,'2026-05-07 13:28:54','2026-05-07 13:29:22'),
(9,2,3,3,'rphp',12,NULL,2,'draft',NULL,NULL,'2026-05-07 13:30:26','2026-05-07 13:30:26'),
(10,6,5,5,'hydranty',12,'2026-05-07',6,'finalized','Test hydrants',NULL,'2026-05-07 13:57:35','2026-05-07 13:57:37'),
(11,2,3,3,'hydranty',12,'2026-05-23',2,'finalized',NULL,NULL,'2026-05-07 14:14:20','2026-05-07 14:15:26'),
(12,6,5,5,'oprava_ts_rphp',60,'2026-05-07',6,'finalized',NULL,NULL,'2026-05-07 14:20:19','2026-05-07 14:20:20'),
(13,6,5,5,'poziarna_kniha',6,'2026-05-07',6,'finalized',NULL,NULL,'2026-05-07 14:25:56','2026-05-07 14:25:58'),
(14,6,5,5,'pu_akcieschopnost',3,'2026-05-07',6,'draft',NULL,NULL,'2026-05-07 14:32:37','2026-05-07 14:32:37'),
(15,6,5,5,'pu_akcieschopnost',3,'2026-05-07',6,'finalized',NULL,NULL,'2026-05-07 14:33:05','2026-05-07 14:33:06'),
(16,6,5,5,'pu_udrzba',12,'2026-05-07',6,'finalized',NULL,NULL,'2026-05-07 14:33:06','2026-05-07 14:33:07'),
(17,6,5,5,'nudzove_osvetlenie',12,'2026-05-07',6,'finalized',NULL,NULL,'2026-05-07 14:41:14','2026-05-07 14:41:15'),
(18,6,5,5,'ts_hadic',60,'2026-05-07',6,'finalized',NULL,NULL,'2026-05-07 14:41:16','2026-05-07 14:41:16'),
(19,2,3,3,'pu_udrzba',12,'2026-05-08',2,'finalized',NULL,NULL,'2026-05-08 08:00:29','2026-05-08 08:01:13'),
(20,10,7,6,'rphp',12,'2026-05-08',10,'finalized',NULL,NULL,'2026-05-08 13:10:49','2026-05-08 13:10:51'),
(21,11,8,7,'rphp',24,'2026-05-08',11,'draft',NULL,NULL,'2026-05-08 13:29:58','2026-05-08 13:29:58'),
(22,11,8,7,'poziarna_kniha',6,'2026-05-08',11,'draft',NULL,NULL,'2026-05-08 13:29:58','2026-05-08 13:29:58');
/*!40000 ALTER TABLE `inspections` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `inspector_profiles`
--

DROP TABLE IF EXISTS `inspector_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inspector_profiles` (
  `user_id` int(10) unsigned NOT NULL,
  `account_id` int(10) unsigned NOT NULL,
  `signature_path` varchar(255) DEFAULT NULL,
  `certification_number` varchar(64) DEFAULT NULL,
  `valid_from` date DEFAULT NULL,
  `valid_to` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`,`account_id`),
  KEY `fk_inspector_account` (`account_id`),
  CONSTRAINT `fk_inspector_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inspector_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inspector_profiles`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `inspector_profiles` WRITE;
/*!40000 ALTER TABLE `inspector_profiles` DISABLE KEYS */;
INSERT INTO `inspector_profiles` VALUES
(2,2,'signatures/2/2.png','OP-1226654','2026-05-07','2026-06-19',1,'2026-05-07 12:48:30','2026-05-07 13:19:42'),
(5,5,NULL,'OPR-2024-001','2024-01-01','2029-01-01',1,'2026-05-07 12:31:52','2026-05-07 12:31:52'),
(7,7,NULL,NULL,NULL,NULL,1,'2026-05-08 09:19:30','2026-05-08 09:19:30'),
(15,14,NULL,NULL,NULL,NULL,1,'2026-05-11 09:52:49','2026-05-11 09:52:49'),
(16,15,NULL,NULL,NULL,NULL,1,'2026-05-11 10:08:49','2026-05-11 10:08:49');
/*!40000 ALTER TABLE `inspector_profiles` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `invoices`
--

DROP TABLE IF EXISTS `invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoices` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int(10) unsigned NOT NULL,
  `stripe_invoice_id` varchar(64) NOT NULL,
  `idoklad_invoice_id` int(10) unsigned DEFAULT NULL,
  `document_number` varchar(64) DEFAULT NULL,
  `amount_cents` int(10) unsigned NOT NULL,
  `currency` varchar(8) NOT NULL DEFAULT 'EUR',
  `status` varchar(32) NOT NULL DEFAULT 'paid',
  `issued_at` datetime NOT NULL,
  `error_message` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_invoices_stripe` (`stripe_invoice_id`),
  KEY `idx_invoices_account` (`account_id`,`issued_at` DESC),
  CONSTRAINT `fk_invoices_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `invoices`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `invoices` WRITE;
/*!40000 ALTER TABLE `invoices` DISABLE KEYS */;
INSERT INTO `invoices` VALUES
(3,14,'in_1TVqXfCzYcIyoDNGYd9iD27w',62866494,'20260001',1900,'EUR','draft','2026-05-11 10:40:00',NULL,'2026-05-11 10:40:00');
/*!40000 ALTER TABLE `invoices` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `name` varchar(191) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES
('001_base_schema.sql','2026-05-07 09:47:46'),
('002_companies_facilities.sql','2026-05-07 11:21:25'),
('003_inspections_documents.sql','2026-05-07 12:25:57'),
('004_trainings.sql','2026-05-08 08:03:25'),
('005_stripe_billing.sql','2026-05-11 09:30:31'),
('006_idoklad_invoices.sql','2026-05-11 10:33:57');
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `password_resets`
--

DROP TABLE IF EXISTS `password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_resets` (
  `token` char(64) NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`token`),
  KEY `idx_pr_user` (`user_id`),
  CONSTRAINT `fk_pr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_resets`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `password_resets` WRITE;
/*!40000 ALTER TABLE `password_resets` DISABLE KEYS */;
INSERT INTO `password_resets` VALUES
('c9accf25ecf0aa8cede2404dd311eb0eaaec6dbd2d1c06c950eb034533d32f20',1,'2026-05-07 12:54:08',NULL,'2026-05-07 09:54:08'),
('f49074f434e8b154ce60c1714bdc877722103b2363bef625e408101cbe9748f4',13,'2026-05-18 09:43:04',NULL,'2026-05-11 07:43:04');
/*!40000 ALTER TABLE `password_resets` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `system_settings`
--

DROP TABLE IF EXISTS `system_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_settings` (
  `setting_key` varchar(64) NOT NULL,
  `setting_value` varchar(512) NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `system_settings`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `system_settings` WRITE;
/*!40000 ALTER TABLE `system_settings` DISABLE KEYS */;
INSERT INTO `system_settings` VALUES
('price_monthly_eur','19','2026-05-07 09:47:46'),
('price_yearly_eur','199','2026-05-07 09:47:46'),
('trial_days','7','2026-05-11 10:59:47');
/*!40000 ALTER TABLE `system_settings` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `trainees`
--

DROP TABLE IF EXISTS `trainees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `trainees` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `training_id` int(10) unsigned NOT NULL,
  `fullname` varchar(191) NOT NULL,
  `position` varchar(191) DEFAULT NULL,
  `signature_path` varchar(255) DEFAULT NULL,
  `signed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_trainees_training` (`training_id`),
  CONSTRAINT `fk_trainees_training` FOREIGN KEY (`training_id`) REFERENCES `trainings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `trainees`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `trainees` WRITE;
/*!40000 ALTER TABLE `trainees` DISABLE KEYS */;
INSERT INTO `trainees` VALUES
(1,1,'Maria Novotna','Veduca skladu','trainings/1/1.png','2026-05-08 08:59:49','2026-05-08 08:59:49','2026-05-08 08:59:49');
/*!40000 ALTER TABLE `trainees` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `trainers`
--

DROP TABLE IF EXISTS `trainers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `trainers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int(10) unsigned NOT NULL,
  `fullname` varchar(191) NOT NULL,
  `certification_number` varchar(64) DEFAULT NULL,
  `signature_path` varchar(255) DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_trainers_account` (`account_id`,`archived_at`),
  CONSTRAINT `fk_trainers_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `trainers`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `trainers` WRITE;
/*!40000 ALTER TABLE `trainers` DISABLE KEYS */;
INSERT INTO `trainers` VALUES
(1,6,'Jan Skolitel','OPP-SK-2024-001','trainers/6/1.png',NULL,'2026-05-08 08:05:42','2026-05-08 08:59:49'),
(2,2,'Jana Lektorov','4564as','trainers/2/2.png',NULL,'2026-05-08 08:18:24','2026-05-08 08:18:48'),
(3,7,'Jana Lektorová','OPP-SK-456-CC','trainers/7/3.png',NULL,'2026-05-08 09:22:12','2026-05-08 09:22:30');
/*!40000 ALTER TABLE `trainers` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `trainings`
--

DROP TABLE IF EXISTS `trainings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `trainings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int(10) unsigned NOT NULL,
  `company_id` int(10) unsigned NOT NULL,
  `facility_id` int(10) unsigned DEFAULT NULL,
  `type` varchar(32) NOT NULL,
  `date` date DEFAULT NULL,
  `trainer_id` int(10) unsigned DEFAULT NULL,
  `topics` text DEFAULT NULL,
  `duration_min` smallint(5) unsigned DEFAULT NULL,
  `status` enum('draft','finalized') NOT NULL DEFAULT 'draft',
  `archived_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_trainings_account` (`account_id`,`archived_at`),
  KEY `idx_trainings_company` (`company_id`,`archived_at`,`date`),
  KEY `idx_trainings_facility` (`facility_id`,`archived_at`,`date`),
  KEY `fk_trainings_trainer` (`trainer_id`),
  CONSTRAINT `fk_trainings_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_trainings_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_trainings_facility` FOREIGN KEY (`facility_id`) REFERENCES `facilities` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_trainings_trainer` FOREIGN KEY (`trainer_id`) REFERENCES `trainers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `trainings`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `trainings` WRITE;
/*!40000 ALTER TABLE `trainings` DISABLE KEYS */;
INSERT INTO `trainings` VALUES
(1,6,5,5,'vstupne','2026-05-08',1,'OPP osnova, evakuacia',120,'finalized',NULL,'2026-05-08 08:05:42','2026-05-08 08:59:51'),
(2,2,3,3,'vstupne','2026-05-09',2,'prva pomoc',150,'draft',NULL,'2026-05-08 08:20:09','2026-05-08 08:20:09');
/*!40000 ALTER TABLE `trainings` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `fullname` varchar(191) NOT NULL,
  `email` varchar(191) NOT NULL,
  `phone` varchar(64) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES
(1,'Ján Novák','jan@example.com','0901123456','$2y$12$bDc30/hUYdn5YV.bgnRtqeUp3vJ2CjZDQef.Qq5WyOudk0.yxUZPO','2026-05-07 09:53:04','2026-05-07 09:53:04'),
(2,'Peter Puhovich','pepuhovich@gmail.com',NULL,'$2y$12$UxnjfRcmeckrmweW8lGSFei2HzgTbnK1d66H/CinQnhqOWuax1RoG','2026-05-07 10:10:45','2026-05-07 10:10:45'),
(3,'Petra Nová','petra@example.com','0901999111','$2y$12$01ZP0TLEkhbG7BLStYJspObXnwX7BqmQjZdGQzvRdcGqYal.bpEk2','2026-05-07 10:11:36','2026-05-07 10:11:36'),
(4,'Test Tester','insp-1778157018@firol.test','+421900000000','$2y$12$VptXOLlIVHqb8mAWkc8jJOsL6ASK.rJJ6WjNaNzuUitLFkvBp2siu','2026-05-07 12:30:19','2026-05-07 12:30:19'),
(5,'Test Tester','insp-1778157045@firol.test','+421900000000','$2y$12$VMlJcMzjbxgWL.hnb5n5y.AHEiYwRE6LPTDHUziaViG1.EyimR8vO','2026-05-07 12:30:47','2026-05-07 12:30:47'),
(6,'PDF Tester','pdftest-1778159599@firol.test','+421900000000','$2y$12$2L65il1rqok1Xfv6LZ1FL.qSchkVIu9T936cGiif/CnefaJQcFXC6','2026-05-07 13:13:19','2026-05-07 13:13:19'),
(7,'Cstudios','admin@cstudios.sk',NULL,'$2y$12$PcigzEuFII4hDOjeKT7fZ.OnrTvNt74c2TXvqJ5hTPkOu1Vo58lV6','2026-05-08 09:18:59','2026-05-08 09:18:59'),
(8,'Brand Test','brand1778245657@test.sk','+421900000000','$2y$12$iWIe/jC.dJNk.J9.X9i7AegTkhrUVkhEFQVz11RTL0awvjxVE8Sii','2026-05-08 13:07:38','2026-05-08 13:07:38'),
(9,'Brand Test','brand1778245827@test.sk','+421900000000','$2y$12$dlRL/4b2gNuL3TtpckcCGOz2H00Hg7oKmbQwxPnGp9sU7NVbH2bp2','2026-05-08 13:10:27','2026-05-08 13:10:27'),
(10,'PDF Test','pdf1778245846@test.sk','+421900000000','$2y$12$RjmMLn/vYCEQFP5TGmU8qeA0mZbv4d3OHBsCL64M3ZBvarnHJKZKK','2026-05-08 13:10:48','2026-05-08 13:10:48'),
(11,'5b Test','p5b1778246998@test.sk','+421900000000','$2y$12$TR1.kIA9.MlWsJ7wGiVHue4DhO24/38LK1R1FiLov/Pvu.7oEUeFm','2026-05-08 13:29:57','2026-05-08 13:29:57'),
(12,'Hlavny User','main+5c@test.sk','+421900000001','$2y$12$s13JL1GlYO0ga05iVifuue48.3Smska8wK2HV5SffSaECdZi4PyFK','2026-05-11 07:43:03','2026-05-11 07:43:03'),
(13,'Jana Technik','jana+5c@test.sk','','$2y$12$lsDibAb/8m81opjr/3Keo.gnq0xvf6QzL9JXkV8nUrnvrQiNweQka','2026-05-11 07:43:04','2026-05-11 07:43:04'),
(14,'Stripe Test','stripe+6b@test.sk','+421900111111','$2y$12$JAghZ4BDs8ZQ3p58ylod/uSRYomMhkW4PdUfVxTlLBYbvj9QF/HSC','2026-05-11 09:38:59','2026-05-11 09:38:59'),
(15,'Stripe Test','stripe2+6b@test.sk','+421900111111','$2y$12$J29baABQ77Uwpk.biNn1ou5MEeB6l6HdM9XlN/Qn0VEEHwtwt/h6C','2026-05-11 09:39:16','2026-05-11 09:39:16'),
(16,'Test user 2','h6546e@hfadhaskjdh.com',NULL,'$2y$12$PQMbvYLHKAzCY.nfFXUUGe5B9vdN/9lHeVCC70TF5M0tfhRG1WBsO','2026-05-11 10:08:37','2026-05-11 10:08:37');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

-- Dump completed on 2026-05-12  8:15:23
