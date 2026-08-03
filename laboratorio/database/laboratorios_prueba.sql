CREATE DATABASE IF NOT EXISTS `laboratorios_prueba` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `laboratorios_prueba`;
-- MySQL dump 10.13  Distrib 8.0.45, for Win64 (x86_64)
--
-- Host: mysql-3baa13e1-cengicana1.e.aivencloud.com    Database: laboratorios_prueba
-- ------------------------------------------------------
-- Server version	8.0.45

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
--
-- Table structure for table `MO_Porcentaje`
--

DROP TABLE IF EXISTS `MO_Porcentaje`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `MO_Porcentaje` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `fecha` date DEFAULT NULL,
  `no_lab` varchar(50) DEFAULT NULL,
  `peso_muestra` decimal(10,2) DEFAULT NULL,
  `sulfato_ferroso_consumido` decimal(10,2) DEFAULT NULL,
  `porcentaje_carbono_organico` decimal(10,4) DEFAULT NULL,
  `porcentaje_materia_organica` decimal(10,4) DEFAULT NULL,
  `m1_dicromato` decimal(10,4) DEFAULT NULL,
  `m2_dicromato` decimal(10,4) DEFAULT NULL,
  `val_solucion_ferroso` decimal(10,4) DEFAULT NULL,
  `normalidad_sulfato_ferroso` decimal(10,6) DEFAULT NULL,
  `ml_util_sulfato_ferroso1N` decimal(10,2) DEFAULT NULL,
  `dicromato_potasio` decimal(10,4) DEFAULT NULL,
  `dicromato_consumido` decimal(10,4) DEFAULT NULL,
  `blanco_sulfato_ferroso` decimal(10,2) DEFAULT NULL,
  `blanco_sulfato_ferroso_2` decimal(10,2) DEFAULT NULL,
  `id_encabezado` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_MO_Porcentaje_encabezado` (`id_encabezado`),
  KEY `fk_MO_Porcentaje_solicitud` (`id_solicitud`),
  KEY `fk_MO_Porcentaje_lote` (`id_lote`),
  KEY `fk_MO_Porcentaje_formulario` (`id_formulario`),
  CONSTRAINT `fk_MO_Porcentaje_encabezado` FOREIGN KEY (`id_encabezado`) REFERENCES `encabezado` (`id_encabezado`),
  CONSTRAINT `fk_MO_Porcentaje_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `fk_MO_Porcentaje_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`),
  CONSTRAINT `fk_MO_Porcentaje_formulario` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `MO_Porcentaje`
--

LOCK TABLES `MO_Porcentaje` WRITE;
/*!40000 ALTER TABLE `MO_Porcentaje` DISABLE KEYS */;
/*!40000 ALTER TABLE `MO_Porcentaje` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `agua_alcalinidad`
--

DROP TABLE IF EXISTS `agua_alcalinidad`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agua_alcalinidad` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `ml_h2so4` decimal(10,4) DEFAULT NULL,
  `normalidad_h2so4` decimal(10,4) DEFAULT NULL,
  `vol_muestra` decimal(10,4) DEFAULT NULL,
  `alcalinidad_mgl` decimal(10,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  CONSTRAINT `agua_alcalinidad_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `agua_alcalinidad_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `agua_alcalinidad_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `agua_alcalinidad`
--

LOCK TABLES `agua_alcalinidad` WRITE;
/*!40000 ALTER TABLE `agua_alcalinidad` DISABLE KEYS */;
/*!40000 ALTER TABLE `agua_alcalinidad` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `agua_bicarbonatos`
--

DROP TABLE IF EXISTS `agua_bicarbonatos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agua_bicarbonatos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `ml_hcl` decimal(10,4) DEFAULT NULL,
  `ml_carbonatos` decimal(10,4) DEFAULT NULL,
  `normalidad_h2so4` decimal(10,4) DEFAULT NULL,
  `volumen_muestra` decimal(10,4) DEFAULT NULL,
  `bicarbonatos_mgl` decimal(10,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  CONSTRAINT `agua_bicarbonatos_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `agua_bicarbonatos_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `agua_bicarbonatos_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `agua_bicarbonatos`
--

LOCK TABLES `agua_bicarbonatos` WRITE;
/*!40000 ALTER TABLE `agua_bicarbonatos` DISABLE KEYS */;
/*!40000 ALTER TABLE `agua_bicarbonatos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `agua_boro`
--

DROP TABLE IF EXISTS `agua_boro`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agua_boro` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `abs_blanco` decimal(10,4) DEFAULT NULL,
  `absorbancia` decimal(10,4) DEFAULT NULL,
  `pendiente` decimal(10,4) DEFAULT NULL,
  `intercepto` decimal(10,4) DEFAULT NULL,
  `boro` decimal(10,4) DEFAULT NULL,
  `id_encabezado` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  KEY `fk_agua_boro_encabezado` (`id_encabezado`),
  CONSTRAINT `agua_boro_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `agua_boro_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `agua_boro_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`),
  CONSTRAINT `fk_agua_boro_encabezado` FOREIGN KEY (`id_encabezado`) REFERENCES `encabezado` (`id_encabezado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `agua_boro`
--

LOCK TABLES `agua_boro` WRITE;
/*!40000 ALTER TABLE `agua_boro` DISABLE KEYS */;
/*!40000 ALTER TABLE `agua_boro` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `agua_carbonatos`
--

DROP TABLE IF EXISTS `agua_carbonatos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agua_carbonatos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `ml_h2so4` decimal(10,4) DEFAULT NULL,
  `normalidad_h2so4` decimal(10,4) DEFAULT NULL,
  `volumen_muestra` decimal(10,4) DEFAULT NULL,
  `carbonatos` decimal(10,4) DEFAULT NULL,
  `id_encabezado` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  KEY `fk_agua_carbonatos_encabezado` (`id_encabezado`),
  CONSTRAINT `agua_carbonatos_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `agua_carbonatos_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `agua_carbonatos_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`),
  CONSTRAINT `fk_agua_carbonatos_encabezado` FOREIGN KEY (`id_encabezado`) REFERENCES `encabezado` (`id_encabezado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `agua_carbonatos`
--

LOCK TABLES `agua_carbonatos` WRITE;
/*!40000 ALTER TABLE `agua_carbonatos` DISABLE KEYS */;
/*!40000 ALTER TABLE `agua_carbonatos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `agua_cloruros`
--

DROP TABLE IF EXISTS `agua_cloruros`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agua_cloruros` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `ml_muestra` decimal(10,4) DEFAULT NULL,
  `ml_agno3_blanco` decimal(10,4) DEFAULT NULL,
  `ml_agno3_muestra` decimal(10,4) DEFAULT NULL,
  `normalidad_agno3` decimal(10,4) DEFAULT NULL,
  `cloruros_mgl` decimal(10,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  CONSTRAINT `agua_cloruros_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `agua_cloruros_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `agua_cloruros_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `agua_cloruros`
--

LOCK TABLES `agua_cloruros` WRITE;
/*!40000 ALTER TABLE `agua_cloruros` DISABLE KEYS */;
/*!40000 ALTER TABLE `agua_cloruros` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `agua_conductividad`
--

DROP TABLE IF EXISTS `agua_conductividad`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agua_conductividad` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `lectura_conductividad` decimal(10,4) DEFAULT NULL,
  `temperatura` decimal(10,4) DEFAULT NULL,
  `ce` decimal(10,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  CONSTRAINT `agua_conductividad_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `agua_conductividad_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `agua_conductividad_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `agua_conductividad`
--

LOCK TABLES `agua_conductividad` WRITE;
/*!40000 ALTER TABLE `agua_conductividad` DISABLE KEYS */;
/*!40000 ALTER TABLE `agua_conductividad` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `agua_dureza`
--

DROP TABLE IF EXISTS `agua_dureza`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agua_dureza` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `ml_edta` decimal(10,4) DEFAULT NULL,
  `ml_muestra` decimal(10,4) DEFAULT NULL,
  `dureza` decimal(10,4) DEFAULT NULL,
  `id_encabezado` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  KEY `fk_agua_dureza_encabezado` (`id_encabezado`),
  CONSTRAINT `agua_dureza_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `agua_dureza_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `agua_dureza_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`),
  CONSTRAINT `fk_agua_dureza_encabezado` FOREIGN KEY (`id_encabezado`) REFERENCES `encabezado` (`id_encabezado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `agua_dureza`
--

LOCK TABLES `agua_dureza` WRITE;
/*!40000 ALTER TABLE `agua_dureza` DISABLE KEYS */;
/*!40000 ALTER TABLE `agua_dureza` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `agua_fosforo`
--

DROP TABLE IF EXISTS `agua_fosforo`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agua_fosforo` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `abs_blanco` decimal(10,4) DEFAULT NULL,
  `absorbancia` decimal(10,4) DEFAULT NULL,
  `ppm_sol` decimal(10,4) DEFAULT NULL,
  `ppm_p` decimal(10,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  CONSTRAINT `agua_fosforo_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `agua_fosforo_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `agua_fosforo_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `agua_fosforo`
--

LOCK TABLES `agua_fosforo` WRITE;
/*!40000 ALTER TABLE `agua_fosforo` DISABLE KEYS */;
/*!40000 ALTER TABLE `agua_fosforo` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `agua_fosforo_curva`
--

DROP TABLE IF EXISTS `agua_fosforo_curva`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agua_fosforo_curva` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `id_fosforo` int DEFAULT NULL,
  `id_curva_fosforo` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  KEY `id_fosforo` (`id_fosforo`),
  KEY `id_curva_fosforo` (`id_curva_fosforo`),
  CONSTRAINT `agua_fosforo_curva_ibfk_1` FOREIGN KEY (`id_fosforo`) REFERENCES `agua_fosforo` (`id`),
  CONSTRAINT `agua_fosforo_curva_ibfk_2` FOREIGN KEY (`id_curva_fosforo`) REFERENCES `curva_fosforo_ag` (`id_curva`),
  CONSTRAINT `agua_fosforo_curva_ibfk_3` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `agua_fosforo_curva_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `agua_fosforo_curva_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `agua_fosforo_curva`
--

LOCK TABLES `agua_fosforo_curva` WRITE;
/*!40000 ALTER TABLE `agua_fosforo_curva` DISABLE KEYS */;
/*!40000 ALTER TABLE `agua_fosforo_curva` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `agua_macros`
--

DROP TABLE IF EXISTS `agua_macros`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agua_macros` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `factor_dilucion` decimal(10,4) DEFAULT NULL,
  `ca_ml` decimal(10,4) DEFAULT NULL,
  `mg_ml` decimal(10,4) DEFAULT NULL,
  `k_ml` decimal(10,4) DEFAULT NULL,
  `na_ml` decimal(10,4) DEFAULT NULL,
  `blanco_ca` decimal(10,4) DEFAULT NULL,
  `blanco_mg` decimal(10,4) DEFAULT NULL,
  `blanco_k` decimal(10,4) DEFAULT NULL,
  `blanco_na` decimal(10,4) DEFAULT NULL,
  `ca_mgl` decimal(10,4) DEFAULT NULL,
  `mg_mgl` decimal(10,4) DEFAULT NULL,
  `k_mgl` decimal(10,4) DEFAULT NULL,
  `na_mgl` decimal(10,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  CONSTRAINT `agua_macros_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `agua_macros_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `agua_macros_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `agua_macros`
--

LOCK TABLES `agua_macros` WRITE;
/*!40000 ALTER TABLE `agua_macros` DISABLE KEYS */;
/*!40000 ALTER TABLE `agua_macros` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `agua_micros`
--

DROP TABLE IF EXISTS `agua_micros`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agua_micros` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `conc_cu` decimal(10,4) DEFAULT NULL,
  `conc_zn` decimal(10,4) DEFAULT NULL,
  `conc_fe` decimal(10,4) DEFAULT NULL,
  `conc_mn` decimal(10,4) DEFAULT NULL,
  `blk_cu` decimal(10,4) DEFAULT NULL,
  `blk_zn` decimal(10,4) DEFAULT NULL,
  `blk_fe` decimal(10,4) DEFAULT NULL,
  `blk_mn` decimal(10,4) DEFAULT NULL,
  `cu_mgl` decimal(10,4) DEFAULT NULL,
  `zn_mgl` decimal(10,4) DEFAULT NULL,
  `fe_mgl` decimal(10,4) DEFAULT NULL,
  `mn_mgl` decimal(10,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  CONSTRAINT `agua_micros_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `agua_micros_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `agua_micros_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `agua_micros`
--

LOCK TABLES `agua_micros` WRITE;
/*!40000 ALTER TABLE `agua_micros` DISABLE KEYS */;
/*!40000 ALTER TABLE `agua_micros` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `agua_ph`
--

DROP TABLE IF EXISTS `agua_ph`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agua_ph` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int NOT NULL,
  `ph` decimal(10,4) DEFAULT NULL,
  `id_encabezado` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_agua_ph` (`id_formulario`),
  KEY `fk_agua_ph_encabezado` (`id_encabezado`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  CONSTRAINT `fk_agua_ph` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `fk_agua_ph_encabezado` FOREIGN KEY (`id_encabezado`) REFERENCES `encabezado` (`id_encabezado`),
  CONSTRAINT `agua_ph_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `agua_ph_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `agua_ph`
--

LOCK TABLES `agua_ph` WRITE;
/*!40000 ALTER TABLE `agua_ph` DISABLE KEYS */;
/*!40000 ALTER TABLE `agua_ph` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `agua_ras`
--

DROP TABLE IF EXISTS `agua_ras`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agua_ras` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `na_ug` decimal(10,4) DEFAULT NULL,
  `ca_ug` decimal(10,4) DEFAULT NULL,
  `mg_ug` decimal(10,4) DEFAULT NULL,
  `na_meq` decimal(10,4) DEFAULT NULL,
  `ca_meq` decimal(10,4) DEFAULT NULL,
  `mg_meq` decimal(10,4) DEFAULT NULL,
  `ras` decimal(10,4) DEFAULT NULL,
  `id_encabezado` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  KEY `fk_agua_ras_encabezado` (`id_encabezado`),
  CONSTRAINT `agua_ras_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `agua_ras_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `agua_ras_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`),
  CONSTRAINT `fk_agua_ras_encabezado` FOREIGN KEY (`id_encabezado`) REFERENCES `encabezado` (`id_encabezado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `agua_ras`
--

LOCK TABLES `agua_ras` WRITE;
/*!40000 ALTER TABLE `agua_ras` DISABLE KEYS */;
/*!40000 ALTER TABLE `agua_ras` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `agua_resistividad`
--

DROP TABLE IF EXISTS `agua_resistividad`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agua_resistividad` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `lectura_resistividad` decimal(10,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  CONSTRAINT `agua_resistividad_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `agua_resistividad_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `agua_resistividad_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `agua_resistividad`
--

LOCK TABLES `agua_resistividad` WRITE;
/*!40000 ALTER TABLE `agua_resistividad` DISABLE KEYS */;
/*!40000 ALTER TABLE `agua_resistividad` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `agua_salinidad`
--

DROP TABLE IF EXISTS `agua_salinidad`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agua_salinidad` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `lectura_psu` decimal(10,4) DEFAULT NULL,
  `id_encabezado` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  KEY `fk_agua_salinidad_encabezado` (`id_encabezado`),
  CONSTRAINT `agua_salinidad_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `agua_salinidad_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `agua_salinidad_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`),
  CONSTRAINT `fk_agua_salinidad_encabezado` FOREIGN KEY (`id_encabezado`) REFERENCES `encabezado` (`id_encabezado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `agua_salinidad`
--

LOCK TABLES `agua_salinidad` WRITE;
/*!40000 ALTER TABLE `agua_salinidad` DISABLE KEYS */;
/*!40000 ALTER TABLE `agua_salinidad` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `agua_tds`
--

DROP TABLE IF EXISTS `agua_tds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agua_tds` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `lectura_tds` decimal(10,4) DEFAULT NULL,
  `tds_mgl` decimal(10,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  CONSTRAINT `agua_tds_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `agua_tds_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `agua_tds_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `agua_tds`
--

LOCK TABLES `agua_tds` WRITE;
/*!40000 ALTER TABLE `agua_tds` DISABLE KEYS */;
/*!40000 ALTER TABLE `agua_tds` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `analisis_textura`
--

DROP TABLE IF EXISTS `analisis_textura`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `analisis_textura` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `no_lab` varchar(50) DEFAULT NULL,
  `fecha` date DEFAULT NULL,
  `porcentaje_hr` decimal(10,2) DEFAULT NULL,
  `lectura_1` decimal(10,2) DEFAULT NULL,
  `temp_1` decimal(10,2) DEFAULT NULL,
  `lectura_corregida_1` decimal(10,2) DEFAULT NULL,
  `porcentaje_l_a` decimal(10,2) DEFAULT NULL,
  `lectura_2` decimal(10,2) DEFAULT NULL,
  `temp_2` decimal(10,2) DEFAULT NULL,
  `lectura_corregida_2` decimal(10,2) DEFAULT NULL,
  `total` decimal(10,2) DEFAULT NULL,
  `porcentaje_arcilla` decimal(10,2) DEFAULT NULL,
  `porcentaje_limo` decimal(10,2) DEFAULT NULL,
  `porcentaje_arena` decimal(10,2) DEFAULT NULL,
  `textura` varchar(100) DEFAULT NULL,
  `id_encabezado` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  KEY `fk_analisis_textura_encabezado` (`id_encabezado`),
  CONSTRAINT `fk_analisis_textura_encabezado` FOREIGN KEY (`id_encabezado`) REFERENCES `encabezado` (`id_encabezado`),
  CONSTRAINT `analisis_textura_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `analisis_textura_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `analisis_textura_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `analisis_textura`
--

LOCK TABLES `analisis_textura` WRITE;
/*!40000 ALTER TABLE `analisis_textura` DISABLE KEYS */;
/*!40000 ALTER TABLE `analisis_textura` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `blanco`
--

DROP TABLE IF EXISTS `blanco`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `blanco` (
  `id_blanco` int NOT NULL AUTO_INCREMENT,
  `id_rango` int DEFAULT NULL,
  `id_tipo_analisis` int DEFAULT NULL,
  `codigo` varchar(255) DEFAULT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `valor` decimal(10,4) DEFAULT NULL,
  `activo` tinyint(1) DEFAULT NULL,
  PRIMARY KEY (`id_blanco`),
  KEY `fk_blanco_rango` (`id_rango`),
  KEY `fk_blanco_tipo` (`id_tipo_analisis`),
  CONSTRAINT `fk_blanco_rango` FOREIGN KEY (`id_rango`) REFERENCES `lote_rango` (`id_rango`),
  CONSTRAINT `fk_blanco_tipo` FOREIGN KEY (`id_tipo_analisis`) REFERENCES `tipo_analisis` (`id_tipo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `blanco`
--

LOCK TABLES `blanco` WRITE;
/*!40000 ALTER TABLE `blanco` DISABLE KEYS */;
/*!40000 ALTER TABLE `blanco` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cana_brixpol`
--

DROP TABLE IF EXISTS `cana_brixpol`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cana_brixpol` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `brix` decimal(10,4) DEFAULT NULL,
  `pol` decimal(10,4) DEFAULT NULL,
  `peso_torta` decimal(10,4) DEFAULT NULL,
  `pureza_jugo` decimal(10,4) DEFAULT NULL,
  `porcentaje_jugo` decimal(10,4) DEFAULT NULL,
  `rendimiento_comercial_lbs` decimal(10,4) DEFAULT NULL,
  `rendimiento_comercial_kg` decimal(10,4) DEFAULT NULL,
  `rendimiento_real_lbs` decimal(10,4) DEFAULT NULL,
  `rendimiento_real_kg` decimal(10,4) DEFAULT NULL,
  `porcentaje_pol_cana` decimal(10,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  CONSTRAINT `cana_brixpol_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `cana_brixpol_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `cana_brixpol_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cana_brixpol`
--

LOCK TABLES `cana_brixpol` WRITE;
/*!40000 ALTER TABLE `cana_brixpol` DISABLE KEYS */;
/*!40000 ALTER TABLE `cana_brixpol` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cana_fibra`
--

DROP TABLE IF EXISTS `cana_fibra`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cana_fibra` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `vd` varchar(255) DEFAULT NULL,
  `brix_jugo` decimal(10,4) DEFAULT NULL,
  `torta_humeda` decimal(10,4) DEFAULT NULL,
  `torta_seca` decimal(10,4) DEFAULT NULL,
  `fibra` decimal(10,4) DEFAULT NULL,
  `id_encabezado` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  KEY `fk_cana_fibra_encabezado` (`id_encabezado`),
  CONSTRAINT `cana_fibra_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `cana_fibra_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `cana_fibra_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`),
  CONSTRAINT `fk_cana_fibra_encabezado` FOREIGN KEY (`id_encabezado`) REFERENCES `encabezado` (`id_encabezado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cana_fibra`
--

LOCK TABLES `cana_fibra` WRITE;
/*!40000 ALTER TABLE `cana_fibra` DISABLE KEYS */;
/*!40000 ALTER TABLE `cana_fibra` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cana_humedad`
--

DROP TABLE IF EXISTS `cana_humedad`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cana_humedad` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `no_bandeja` decimal(10,4) DEFAULT NULL,
  `peso_bandeja` decimal(10,4) DEFAULT NULL,
  `peso_muestra` decimal(10,4) DEFAULT NULL,
  `peso_bandeja_seca` decimal(10,4) DEFAULT NULL,
  `peso_bandeja_humedad` decimal(10,4) DEFAULT NULL,
  `porcentaje_humedad` decimal(10,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  CONSTRAINT `cana_humedad_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `cana_humedad_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `cana_humedad_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cana_humedad`
--

LOCK TABLES `cana_humedad` WRITE;
/*!40000 ALTER TABLE `cana_humedad` DISABLE KEYS */;
/*!40000 ALTER TABLE `cana_humedad` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cana_peso_seco`
--

DROP TABLE IF EXISTS `cana_peso_seco`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cana_peso_seco` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `lote` varchar(255) DEFAULT NULL,
  `num_bandeja` int DEFAULT NULL,
  `peso_bandeja` decimal(10,4) DEFAULT NULL,
  `peso_muestra` decimal(10,4) DEFAULT NULL,
  `bandeja_humeda` decimal(10,4) DEFAULT NULL,
  `bandeja_seca` decimal(10,4) DEFAULT NULL,
  `torta_seca` decimal(10,4) DEFAULT NULL,
  `id_encabezado` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  KEY `fk_cana_peso_seco_encabezado` (`id_encabezado`),
  CONSTRAINT `cana_peso_seco_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `cana_peso_seco_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `cana_peso_seco_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`),
  CONSTRAINT `fk_cana_peso_seco_encabezado` FOREIGN KEY (`id_encabezado`) REFERENCES `encabezado` (`id_encabezado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cana_peso_seco`
--

LOCK TABLES `cana_peso_seco` WRITE;
/*!40000 ALTER TABLE `cana_peso_seco` DISABLE KEYS */;
/*!40000 ALTER TABLE `cana_peso_seco` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cana_ph`
--

DROP TABLE IF EXISTS `cana_ph`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cana_ph` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `ph` decimal(10,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  CONSTRAINT `cana_ph_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `cana_ph_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `cana_ph_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cana_ph`
--

LOCK TABLES `cana_ph` WRITE;
/*!40000 ALTER TABLE `cana_ph` DISABLE KEYS */;
/*!40000 ALTER TABLE `cana_ph` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `control_laboratorio`
--

DROP TABLE IF EXISTS `control_laboratorio`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `control_laboratorio` (
  `id_control` int NOT NULL AUTO_INCREMENT,
  `id_rango` int DEFAULT NULL,
  `id_tipo_analisis` int DEFAULT NULL,
  `codigo` varchar(255) DEFAULT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `valor` decimal(10,4) DEFAULT NULL,
  `minimo` decimal(10,4) DEFAULT NULL,
  `maximo` decimal(10,4) DEFAULT NULL,
  `activo` tinyint(1) DEFAULT NULL,
  PRIMARY KEY (`id_control`),
  KEY `fk_control_rango` (`id_rango`),
  KEY `fk_control_tipo` (`id_tipo_analisis`),
  CONSTRAINT `fk_control_rango` FOREIGN KEY (`id_rango`) REFERENCES `lote_rango` (`id_rango`),
  CONSTRAINT `fk_control_tipo` FOREIGN KEY (`id_tipo_analisis`) REFERENCES `tipo_analisis` (`id_tipo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `control_laboratorio`
--

LOCK TABLES `control_laboratorio` WRITE;
/*!40000 ALTER TABLE `control_laboratorio` DISABLE KEYS */;
/*!40000 ALTER TABLE `control_laboratorio` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `correlativo_envio_solicitud`
--

DROP TABLE IF EXISTS `correlativo_envio_solicitud`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `correlativo_envio_solicitud` (
  `id_correlativo` int NOT NULL AUTO_INCREMENT,
  `tipo_muestra` varchar(50) NOT NULL,
  `prefijo` char(1) NOT NULL,
  `ultimo_numero` int NOT NULL DEFAULT '0',
  `descripcion` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_correlativo`),
  UNIQUE KEY `uq_prefijo` (`prefijo`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `correlativo_envio_solicitud`
--

LOCK TABLES `correlativo_envio_solicitud` WRITE;
/*!40000 ALTER TABLE `correlativo_envio_solicitud` DISABLE KEYS */;
INSERT INTO `correlativo_envio_solicitud` VALUES (1,'suelos','S',108,'Alias incluido en suelos','2026-06-10 21:26:02'),(2,'caÃ±a','C',501,'Correlativo para solicitudes de caÃ±a','2026-06-10 20:42:58'),(3,'foliares','F',491,'Correlativo para solicitudes foliares','2026-06-01 22:48:40'),(4,'mieles','M',491,'Correlativo para solicitudes de mieles','2026-06-01 22:48:40'),(5,'agua','A',658,'Correlativo para solicitudes de agua','2026-06-04 20:50:22'),(6,'caÃ±a brix','B',491,'Correlativo para caÃ±a brix','2026-06-01 22:48:40'),(7,'agua carbonatos','D',491,'Correlativo para agua carbonatos','2026-06-01 22:48:40'),(8,'mieles brix','R',491,'Correlativo para mieles brix','2026-06-01 22:48:40'),(11,'enmiendas','E',491,'Correlativo para solicitudes de enmiendas','2026-06-01 22:55:04'),(12,'granos','G',491,'Correlativo para solicitudes de granos','2026-06-01 22:55:04');
/*!40000 ALTER TABLE `correlativo_envio_solicitud` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `curva_azufre`
--

DROP TABLE IF EXISTS `curva_azufre`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `curva_azufre` (
  `id_curva` int NOT NULL AUTO_INCREMENT,
  `id_formulario` int DEFAULT NULL,
  `punto_curva` decimal(10,4) DEFAULT NULL,
  `absorbancia` decimal(10,4) DEFAULT NULL,
  PRIMARY KEY (`id_curva`),
  KEY `id_formulario` (`id_formulario`),
  CONSTRAINT `curva_azufre_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `curva_azufre`
--

LOCK TABLES `curva_azufre` WRITE;
/*!40000 ALTER TABLE `curva_azufre` DISABLE KEYS */;
/*!40000 ALTER TABLE `curva_azufre` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `curva_boro`
--

DROP TABLE IF EXISTS `curva_boro`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `curva_boro` (
  `id_curva` int NOT NULL AUTO_INCREMENT,
  `id_formulario` int DEFAULT NULL,
  `punto_curva` decimal(10,4) DEFAULT NULL,
  `absorbancia` decimal(10,4) DEFAULT NULL,
  PRIMARY KEY (`id_curva`),
  KEY `id_formulario` (`id_formulario`),
  CONSTRAINT `curva_boro_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `curva_boro`
--

LOCK TABLES `curva_boro` WRITE;
/*!40000 ALTER TABLE `curva_boro` DISABLE KEYS */;
/*!40000 ALTER TABLE `curva_boro` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `curva_fosforo`
--

DROP TABLE IF EXISTS `curva_fosforo`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `curva_fosforo` (
  `id_curva` int NOT NULL AUTO_INCREMENT,
  `id_formulario` int DEFAULT NULL,
  `punto_curva` decimal(10,4) DEFAULT NULL,
  `absorbancia` decimal(10,4) DEFAULT NULL,
  PRIMARY KEY (`id_curva`),
  KEY `id_formulario` (`id_formulario`),
  CONSTRAINT `curva_fosforo_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `curva_fosforo`
--

LOCK TABLES `curva_fosforo` WRITE;
/*!40000 ALTER TABLE `curva_fosforo` DISABLE KEYS */;
/*!40000 ALTER TABLE `curva_fosforo` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `curva_fosforo_ag`
--

DROP TABLE IF EXISTS `curva_fosforo_ag`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `curva_fosforo_ag` (
  `id_curva` int NOT NULL AUTO_INCREMENT,
  `id_formulario` int DEFAULT NULL,
  `punto_curva` decimal(10,4) DEFAULT NULL,
  `absorbancia` decimal(10,4) DEFAULT NULL,
  PRIMARY KEY (`id_curva`),
  KEY `id_formulario` (`id_formulario`),
  CONSTRAINT `curva_fosforo_ag_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `curva_fosforo_ag`
--

LOCK TABLES `curva_fosforo_ag` WRITE;
/*!40000 ALTER TABLE `curva_fosforo_ag` DISABLE KEYS */;
/*!40000 ALTER TABLE `curva_fosforo_ag` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `curva_fosforo_fo`
--

DROP TABLE IF EXISTS `curva_fosforo_fo`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `curva_fosforo_fo` (
  `id_curva` int NOT NULL AUTO_INCREMENT,
  `id_formulario` int DEFAULT NULL,
  `punto_curva` decimal(10,4) DEFAULT NULL,
  `absorbancia` decimal(10,4) DEFAULT NULL,
  PRIMARY KEY (`id_curva`),
  KEY `id_formulario` (`id_formulario`),
  CONSTRAINT `curva_fosforo_fo_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `curva_fosforo_fo`
--

LOCK TABLES `curva_fosforo_fo` WRITE;
/*!40000 ALTER TABLE `curva_fosforo_fo` DISABLE KEYS */;
/*!40000 ALTER TABLE `curva_fosforo_fo` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `curva_calibracion`
--

DROP TABLE IF EXISTS `curva_calibracion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `curva_calibracion` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_formulario` int DEFAULT NULL,
  `concentracion` decimal(10,4) DEFAULT NULL,
  `absorbancia` decimal(10,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_curva_formulario` (`id_formulario`),
  CONSTRAINT `fk_curva_formulario` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `curva_calibracion`
--

LOCK TABLES `curva_calibracion` WRITE;
/*!40000 ALTER TABLE `curva_calibracion` DISABLE KEYS */;
/*!40000 ALTER TABLE `curva_calibracion` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `encabezado`
--

DROP TABLE IF EXISTS `encabezado`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `encabezado` (
  `id_encabezado` int NOT NULL AUTO_INCREMENT,
  `codigo` varchar(100) DEFAULT NULL,
  `nombre` varchar(255) DEFAULT NULL,
  `fecha_documento` date DEFAULT NULL,
  `edicion` varchar(50) DEFAULT NULL,
  `vf` varchar(50) DEFAULT NULL,
  `activo` tinyint(1) DEFAULT '1',
  PRIMARY KEY (`id_encabezado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `encabezado`
--

LOCK TABLES `encabezado` WRITE;
/*!40000 ALTER TABLE `encabezado` DISABLE KEYS */;
/*!40000 ALTER TABLE `encabezado` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `estado_formulario`
--

DROP TABLE IF EXISTS `estado_formulario`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `estado_formulario` (
  `id_estado` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) DEFAULT NULL,
  `descripcion` text,
  PRIMARY KEY (`id_estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `estado_formulario`
--

LOCK TABLES `estado_formulario` WRITE;
/*!40000 ALTER TABLE `estado_formulario` DISABLE KEYS */;
/*!40000 ALTER TABLE `estado_formulario` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `foliar_boro`
--

DROP TABLE IF EXISTS `foliar_boro`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `foliar_boro` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `absorbancia` decimal(10,4) DEFAULT NULL,
  `resultado` decimal(10,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  CONSTRAINT `foliar_boro_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `foliar_boro_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `foliar_boro_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `foliar_boro`
--

LOCK TABLES `foliar_boro` WRITE;
/*!40000 ALTER TABLE `foliar_boro` DISABLE KEYS */;
/*!40000 ALTER TABLE `foliar_boro` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `foliar_fosforo`
--

DROP TABLE IF EXISTS `foliar_fosforo`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `foliar_fosforo` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `peso` decimal(10,4) DEFAULT NULL,
  `abs_blanco` decimal(10,4) DEFAULT NULL,
  `absorbancia` decimal(10,4) DEFAULT NULL,
  `ppm_p_sol` decimal(10,4) DEFAULT NULL,
  `porcentaje_p` decimal(10,4) DEFAULT NULL,
  `control` decimal(10,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  CONSTRAINT `foliar_fosforo_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `foliar_fosforo_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `foliar_fosforo_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `foliar_fosforo`
--

LOCK TABLES `foliar_fosforo` WRITE;
/*!40000 ALTER TABLE `foliar_fosforo` DISABLE KEYS */;
/*!40000 ALTER TABLE `foliar_fosforo` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `foliar_fosforo_curva`
--

DROP TABLE IF EXISTS `foliar_fosforo_curva`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `foliar_fosforo_curva` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `id_fosforo` int DEFAULT NULL,
  `id_curva_fosforo` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  KEY `id_fosforo` (`id_fosforo`),
  KEY `id_curva_fosforo` (`id_curva_fosforo`),
  CONSTRAINT `foliar_fosforo_curva_ibfk_1` FOREIGN KEY (`id_fosforo`) REFERENCES `foliar_fosforo` (`id`),
  CONSTRAINT `foliar_fosforo_curva_ibfk_2` FOREIGN KEY (`id_curva_fosforo`) REFERENCES `curva_fosforo_fo` (`id_curva`),
  CONSTRAINT `foliar_fosforo_curva_ibfk_3` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `foliar_fosforo_curva_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `foliar_fosforo_curva_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `foliar_fosforo_curva`
--

LOCK TABLES `foliar_fosforo_curva` WRITE;
/*!40000 ALTER TABLE `foliar_fosforo_curva` DISABLE KEYS */;
/*!40000 ALTER TABLE `foliar_fosforo_curva` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `foliar_humedad`
--

DROP TABLE IF EXISTS `foliar_humedad`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `foliar_humedad` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `humedad` decimal(10,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  CONSTRAINT `foliar_humedad_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `foliar_humedad_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `foliar_humedad_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `foliar_humedad`
--

LOCK TABLES `foliar_humedad` WRITE;
/*!40000 ALTER TABLE `foliar_humedad` DISABLE KEYS */;
/*!40000 ALTER TABLE `foliar_humedad` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `foliar_macros`
--

DROP TABLE IF EXISTS `foliar_macros`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `foliar_macros` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `calcio` decimal(10,4) DEFAULT NULL,
  `magnesio` decimal(10,4) DEFAULT NULL,
  `potasio` decimal(10,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  CONSTRAINT `foliar_macros_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `foliar_macros_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `foliar_macros_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `foliar_macros`
--

LOCK TABLES `foliar_macros` WRITE;
/*!40000 ALTER TABLE `foliar_macros` DISABLE KEYS */;
/*!40000 ALTER TABLE `foliar_macros` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `foliar_micros`
--

DROP TABLE IF EXISTS `foliar_micros`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `foliar_micros` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `peso` decimal(10,4) DEFAULT NULL,
  `conc_cu` decimal(10,4) DEFAULT NULL,
  `conc_zn` decimal(10,4) DEFAULT NULL,
  `conc_fe` decimal(10,4) DEFAULT NULL,
  `conc_mn` decimal(10,4) DEFAULT NULL,
  `blk_cu` decimal(10,4) DEFAULT NULL,
  `blk_zn` decimal(10,4) DEFAULT NULL,
  `blk_fe` decimal(10,4) DEFAULT NULL,
  `blk_mn` decimal(10,4) DEFAULT NULL,
  `ppm_cu` decimal(10,4) DEFAULT NULL,
  `ppm_zn` decimal(10,4) DEFAULT NULL,
  `ppm_fe` decimal(10,4) DEFAULT NULL,
  `ppm_mn` decimal(10,4) DEFAULT NULL,
  `control` decimal(10,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  CONSTRAINT `foliar_micros_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `foliar_micros_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `foliar_micros_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `foliar_micros`
--

LOCK TABLES `foliar_micros` WRITE;
/*!40000 ALTER TABLE `foliar_micros` DISABLE KEYS */;
/*!40000 ALTER TABLE `foliar_micros` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `foliar_nitrogeno`
--

DROP TABLE IF EXISTS `foliar_nitrogeno`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `foliar_nitrogeno` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `ml_gastado` decimal(10,4) DEFAULT NULL,
  `normalidad` decimal(10,4) DEFAULT NULL,
  `resultado` decimal(10,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  CONSTRAINT `foliar_nitrogeno_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `foliar_nitrogeno_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `foliar_nitrogeno_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `foliar_nitrogeno`
--

LOCK TABLES `foliar_nitrogeno` WRITE;
/*!40000 ALTER TABLE `foliar_nitrogeno` DISABLE KEYS */;
/*!40000 ALTER TABLE `foliar_nitrogeno` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `foliar_quimicos`
--

DROP TABLE IF EXISTS `foliar_quimicos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `foliar_quimicos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `ph` decimal(10,4) DEFAULT NULL,
  `ce` decimal(10,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  CONSTRAINT `foliar_quimicos_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `foliar_quimicos_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `foliar_quimicos_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `foliar_quimicos`
--

LOCK TABLES `foliar_quimicos` WRITE;
/*!40000 ALTER TABLE `foliar_quimicos` DISABLE KEYS */;
/*!40000 ALTER TABLE `foliar_quimicos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `formulario`
--

DROP TABLE IF EXISTS `formulario`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `formulario` (
  `id_formulario` int NOT NULL AUTO_INCREMENT,
  `id_estado` int DEFAULT NULL,
  `id_rango` int DEFAULT NULL,
  `id_tipo_analisis` int DEFAULT NULL,
  `fecha` date DEFAULT NULL,
  `analista` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_formulario`),
  KEY `fk_formulario_estado` (`id_estado`),
  KEY `fk_formulario_rango` (`id_rango`),
  KEY `fk_formulario_tipo` (`id_tipo_analisis`),
  CONSTRAINT `fk_formulario_estado` FOREIGN KEY (`id_estado`) REFERENCES `estado_formulario` (`id_estado`),
  CONSTRAINT `fk_formulario_rango` FOREIGN KEY (`id_rango`) REFERENCES `lote_rango` (`id_rango`),
  CONSTRAINT `fk_formulario_tipo` FOREIGN KEY (`id_tipo_analisis`) REFERENCES `tipo_analisis` (`id_tipo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `formulario`
--

LOCK TABLES `formulario` WRITE;
/*!40000 ALTER TABLE `formulario` DISABLE KEYS */;
/*!40000 ALTER TABLE `formulario` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `formulario_error`
--

DROP TABLE IF EXISTS `formulario_error`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `formulario_error` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_formulario` int DEFAULT NULL,
  `id_error` int DEFAULT NULL,
  `detectado_por` varchar(255) DEFAULT NULL,
  `fecha` datetime DEFAULT NULL,
  `activo` tinyint(1) DEFAULT NULL,
  `comentario` text,
  PRIMARY KEY (`id`),
  KEY `fk_form_error_formulario` (`id_formulario`),
  KEY `fk_form_error_tipo` (`id_error`),
  CONSTRAINT `fk_form_error_formulario` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `fk_form_error_tipo` FOREIGN KEY (`id_error`) REFERENCES `tipo_error` (`id_error`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `formulario_error`
--

LOCK TABLES `formulario_error` WRITE;
/*!40000 ALTER TABLE `formulario_error` DISABLE KEYS */;
/*!40000 ALTER TABLE `formulario_error` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `historial_formulario`
--

DROP TABLE IF EXISTS `historial_formulario`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `historial_formulario` (
  `id_historial` int NOT NULL AUTO_INCREMENT,
  `id_formulario` int DEFAULT NULL,
  `accion` varchar(255) DEFAULT NULL,
  `estado_anterior` varchar(255) DEFAULT NULL,
  `estado_nuevo` varchar(255) DEFAULT NULL,
  `usuario` varchar(255) DEFAULT NULL,
  `fecha` datetime DEFAULT NULL,
  `comentario` text,
  PRIMARY KEY (`id_historial`),
  KEY `fk_historial_formulario` (`id_formulario`),
  CONSTRAINT `fk_historial_formulario` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `historial_formulario`
--

LOCK TABLES `historial_formulario` WRITE;
/*!40000 ALTER TABLE `historial_formulario` DISABLE KEYS */;
/*!40000 ALTER TABLE `historial_formulario` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `laboratorio_humedad`
--

DROP TABLE IF EXISTS `laboratorio_humedad`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `laboratorio_humedad` (
  `id` int NOT NULL AUTO_INCREMENT,
  `no_lab` varchar(50) NOT NULL,
  `fecha` date NOT NULL,
  `NoCaja` int NOT NULL,
  `PesoCaja` decimal(10,2) NOT NULL,
  `PesoCajaMHumeda` decimal(10,2) NOT NULL,
  `PesoCajaMseca` decimal(10,2) NOT NULL,
  `PesoSeco` decimal(10,2) NOT NULL,
  `PesoHumedo` decimal(10,2) NOT NULL,
  `PorHGrav` decimal(10,2) NOT NULL,
  `id_encabezado` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_laboratorio_humedad_encabezado` (`id_encabezado`),
  CONSTRAINT `fk_laboratorio_humedad_encabezado` FOREIGN KEY (`id_encabezado`) REFERENCES `encabezado` (`id_encabezado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `laboratorio_humedad`
--

LOCK TABLES `laboratorio_humedad` WRITE;
/*!40000 ALTER TABLE `laboratorio_humedad` DISABLE KEYS */;
/*!40000 ALTER TABLE `laboratorio_humedad` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `lote`
--

DROP TABLE IF EXISTS `lote`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lote` (
  `id_lote` int NOT NULL AUTO_INCREMENT,
  `codigo_lote` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id_lote`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `lote`
--

LOCK TABLES `lote` WRITE;
/*!40000 ALTER TABLE `lote` DISABLE KEYS */;
INSERT INTO `lote` VALUES (1,'85'),(2,'85548'),(3,'25'),(4,'8524'),(5,'1'),(6,'162006');
/*!40000 ALTER TABLE `lote` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `lote_analisis`
--

DROP TABLE IF EXISTS `lote_analisis`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lote_analisis` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_rango` int DEFAULT NULL,
  `id_tipo_analisis` int DEFAULT NULL,
  `estado` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_lote_analisis_rango` (`id_rango`),
  KEY `fk_lote_analisis_tipo` (`id_tipo_analisis`),
  CONSTRAINT `fk_lote_analisis_rango` FOREIGN KEY (`id_rango`) REFERENCES `lote_rango` (`id_rango`),
  CONSTRAINT `fk_lote_analisis_tipo` FOREIGN KEY (`id_tipo_analisis`) REFERENCES `tipo_analisis` (`id_tipo`)
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `lote_analisis`
--

LOCK TABLES `lote_analisis` WRITE;
/*!40000 ALTER TABLE `lote_analisis` DISABLE KEYS */;
INSERT INTO `lote_analisis` VALUES (1,2,1,'Pendiente'),(2,3,2,'Pendiente'),(3,3,3,'Pendiente'),(4,3,4,'Pendiente'),(5,3,5,'Pendiente'),(6,3,6,'Pendiente'),(7,3,7,'Pendiente'),(8,4,2,'Pendiente'),(9,4,3,'Pendiente'),(10,4,4,'Pendiente'),(11,4,5,'Pendiente'),(12,4,6,'Pendiente'),(13,4,7,'Pendiente'),(14,5,8,'Pendiente'),(15,5,9,'Pendiente'),(16,5,10,'Pendiente'),(17,6,8,'Pendiente'),(18,6,11,'Pendiente'),(19,6,12,'Pendiente'),(20,6,9,'Pendiente'),(21,6,10,'Pendiente'),(22,6,13,'Pendiente'),(23,6,14,'Pendiente'),(24,6,15,'Pendiente'),(25,6,16,'Pendiente'),(26,6,17,'Pendiente'),(27,6,18,'Pendiente'),(28,7,19,'Pendiente'),(29,8,8,'Pendiente'),(30,8,11,'Pendiente'),(31,8,12,'Pendiente');
/*!40000 ALTER TABLE `lote_analisis` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `lote_rango`
--

DROP TABLE IF EXISTS `lote_rango`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lote_rango` (
  `id_rango` int NOT NULL AUTO_INCREMENT,
  `id_lote` int DEFAULT NULL,
  `inicio` int DEFAULT NULL,
  `fin` int DEFAULT NULL,
  PRIMARY KEY (`id_rango`),
  KEY `fk_lote_rango_lote` (`id_lote`),
  CONSTRAINT `fk_lote_rango_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `lote_rango`
--

LOCK TABLES `lote_rango` WRITE;
/*!40000 ALTER TABLE `lote_rango` DISABLE KEYS */;
INSERT INTO `lote_rango` VALUES (1,1,85,91),(2,2,492,498),(3,3,499,578),(4,3,579,658),(5,1,92,93),(6,4,94,103),(7,5,492,501),(8,6,104,108);
/*!40000 ALTER TABLE `lote_rango` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mieles_brix`
--

DROP TABLE IF EXISTS `mieles_brix`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `mieles_brix` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `producto` varchar(255) DEFAULT NULL,
  `ingenio` varchar(255) DEFAULT NULL,
  `dia_zafra` varchar(255) DEFAULT NULL,
  `brix_obs` decimal(10,4) DEFAULT NULL,
  `brix_corr` decimal(10,4) DEFAULT NULL,
  `id_encabezado` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  KEY `fk_mieles_brix_encabezado` (`id_encabezado`),
  CONSTRAINT `fk_mieles_brix_encabezado` FOREIGN KEY (`id_encabezado`) REFERENCES `encabezado` (`id_encabezado`),
  CONSTRAINT `mieles_brix_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `mieles_brix_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `mieles_brix_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mieles_brix`
--

LOCK TABLES `mieles_brix` WRITE;
/*!40000 ALTER TABLE `mieles_brix` DISABLE KEYS */;
/*!40000 ALTER TABLE `mieles_brix` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `muestra`
--

DROP TABLE IF EXISTS `muestra`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `muestra` (
  `id_muestra` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_muestra` int DEFAULT NULL,
  `codigo_lab` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id_muestra`),
  KEY `fk_muestra_solicitud` (`id_solicitud`),
  CONSTRAINT `fk_muestra_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`)
) ENGINE=InnoDB AUTO_INCREMENT=202 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `muestra`
--

LOCK TABLES `muestra` WRITE;
/*!40000 ALTER TABLE `muestra` DISABLE KEYS */;
INSERT INTO `muestra` VALUES (1,1,1,'S-85-06-26'),(2,1,2,'S-86-06-26'),(3,1,3,'S-87-06-26'),(4,1,4,'S-88-06-26'),(5,1,5,'S-89-06-26'),(6,1,6,'S-90-06-26'),(7,1,7,'S-91-06-26'),(8,2,492,'A-492-06-26'),(9,2,493,'A-493-06-26'),(10,2,494,'A-494-06-26'),(11,2,495,'A-495-06-26'),(12,2,496,'A-496-06-26'),(13,2,497,'A-497-06-26'),(14,2,498,'A-498-06-26'),(15,3,499,'A-499-06-26'),(16,3,500,'A-500-06-26'),(17,3,501,'A-501-06-26'),(18,3,502,'A-502-06-26'),(19,3,503,'A-503-06-26'),(20,3,504,'A-504-06-26'),(21,3,505,'A-505-06-26'),(22,3,506,'A-506-06-26'),(23,3,507,'A-507-06-26'),(24,3,508,'A-508-06-26'),(25,3,509,'A-509-06-26'),(26,3,510,'A-510-06-26'),(27,3,511,'A-511-06-26'),(28,3,512,'A-512-06-26'),(29,3,513,'A-513-06-26'),(30,3,514,'A-514-06-26'),(31,3,515,'A-515-06-26'),(32,3,516,'A-516-06-26'),(33,3,517,'A-517-06-26'),(34,3,518,'A-518-06-26'),(35,3,519,'A-519-06-26'),(36,3,520,'A-520-06-26'),(37,3,521,'A-521-06-26'),(38,3,522,'A-522-06-26'),(39,3,523,'A-523-06-26'),(40,3,524,'A-524-06-26'),(41,3,525,'A-525-06-26'),(42,3,526,'A-526-06-26'),(43,3,527,'A-527-06-26'),(44,3,528,'A-528-06-26'),(45,3,529,'A-529-06-26'),(46,3,530,'A-530-06-26'),(47,3,531,'A-531-06-26'),(48,3,532,'A-532-06-26'),(49,3,533,'A-533-06-26'),(50,3,534,'A-534-06-26'),(51,3,535,'A-535-06-26'),(52,3,536,'A-536-06-26'),(53,3,537,'A-537-06-26'),(54,3,538,'A-538-06-26'),(55,3,539,'A-539-06-26'),(56,3,540,'A-540-06-26'),(57,3,541,'A-541-06-26'),(58,3,542,'A-542-06-26'),(59,3,543,'A-543-06-26'),(60,3,544,'A-544-06-26'),(61,3,545,'A-545-06-26'),(62,3,546,'A-546-06-26'),(63,3,547,'A-547-06-26'),(64,3,548,'A-548-06-26'),(65,3,549,'A-549-06-26'),(66,3,550,'A-550-06-26'),(67,3,551,'A-551-06-26'),(68,3,552,'A-552-06-26'),(69,3,553,'A-553-06-26'),(70,3,554,'A-554-06-26'),(71,3,555,'A-555-06-26'),(72,3,556,'A-556-06-26'),(73,3,557,'A-557-06-26'),(74,3,558,'A-558-06-26'),(75,3,559,'A-559-06-26'),(76,3,560,'A-560-06-26'),(77,3,561,'A-561-06-26'),(78,3,562,'A-562-06-26'),(79,3,563,'A-563-06-26'),(80,3,564,'A-564-06-26'),(81,3,565,'A-565-06-26'),(82,3,566,'A-566-06-26'),(83,3,567,'A-567-06-26'),(84,3,568,'A-568-06-26'),(85,3,569,'A-569-06-26'),(86,3,570,'A-570-06-26'),(87,3,571,'A-571-06-26'),(88,3,572,'A-572-06-26'),(89,3,573,'A-573-06-26'),(90,3,574,'A-574-06-26'),(91,3,575,'A-575-06-26'),(92,3,576,'A-576-06-26'),(93,3,577,'A-577-06-26'),(94,3,578,'A-578-06-26'),(95,4,579,'A-579-06-26'),(96,4,580,'A-580-06-26'),(97,4,581,'A-581-06-26'),(98,4,582,'A-582-06-26'),(99,4,583,'A-583-06-26'),(100,4,584,'A-584-06-26'),(101,4,585,'A-585-06-26'),(102,4,586,'A-586-06-26'),(103,4,587,'A-587-06-26'),(104,4,588,'A-588-06-26'),(105,4,589,'A-589-06-26'),(106,4,590,'A-590-06-26'),(107,4,591,'A-591-06-26'),(108,4,592,'A-592-06-26'),(109,4,593,'A-593-06-26'),(110,4,594,'A-594-06-26'),(111,4,595,'A-595-06-26'),(112,4,596,'A-596-06-26'),(113,4,597,'A-597-06-26'),(114,4,598,'A-598-06-26'),(115,4,599,'A-599-06-26'),(116,4,600,'A-600-06-26'),(117,4,601,'A-601-06-26'),(118,4,602,'A-602-06-26'),(119,4,603,'A-603-06-26'),(120,4,604,'A-604-06-26'),(121,4,605,'A-605-06-26'),(122,4,606,'A-606-06-26'),(123,4,607,'A-607-06-26'),(124,4,608,'A-608-06-26'),(125,4,609,'A-609-06-26'),(126,4,610,'A-610-06-26'),(127,4,611,'A-611-06-26'),(128,4,612,'A-612-06-26'),(129,4,613,'A-613-06-26'),(130,4,614,'A-614-06-26'),(131,4,615,'A-615-06-26'),(132,4,616,'A-616-06-26'),(133,4,617,'A-617-06-26'),(134,4,618,'A-618-06-26'),(135,4,619,'A-619-06-26'),(136,4,620,'A-620-06-26'),(137,4,621,'A-621-06-26'),(138,4,622,'A-622-06-26'),(139,4,623,'A-623-06-26'),(140,4,624,'A-624-06-26'),(141,4,625,'A-625-06-26'),(142,4,626,'A-626-06-26'),(143,4,627,'A-627-06-26'),(144,4,628,'A-628-06-26'),(145,4,629,'A-629-06-26'),(146,4,630,'A-630-06-26'),(147,4,631,'A-631-06-26'),(148,4,632,'A-632-06-26'),(149,4,633,'A-633-06-26'),(150,4,634,'A-634-06-26'),(151,4,635,'A-635-06-26'),(152,4,636,'A-636-06-26'),(153,4,637,'A-637-06-26'),(154,4,638,'A-638-06-26'),(155,4,639,'A-639-06-26'),(156,4,640,'A-640-06-26'),(157,4,641,'A-641-06-26'),(158,4,642,'A-642-06-26'),(159,4,643,'A-643-06-26'),(160,4,644,'A-644-06-26'),(161,4,645,'A-645-06-26'),(162,4,646,'A-646-06-26'),(163,4,647,'A-647-06-26'),(164,4,648,'A-648-06-26'),(165,4,649,'A-649-06-26'),(166,4,650,'A-650-06-26'),(167,4,651,'A-651-06-26'),(168,4,652,'A-652-06-26'),(169,4,653,'A-653-06-26'),(170,4,654,'A-654-06-26'),(171,4,655,'A-655-06-26'),(172,4,656,'A-656-06-26'),(173,4,657,'A-657-06-26'),(174,4,658,'A-658-06-26'),(175,5,92,'S-092-06-26'),(176,5,93,'S-093-06-26'),(177,6,94,'S-094-06-26'),(178,6,95,'S-095-06-26'),(179,6,96,'S-096-06-26'),(180,6,97,'S-097-06-26'),(181,6,98,'S-098-06-26'),(182,6,99,'S-099-06-26'),(183,6,100,'S-100-06-26'),(184,6,101,'S-101-06-26'),(185,6,102,'S-102-06-26'),(186,6,103,'S-103-06-26'),(187,7,492,'C-492-06-26'),(188,7,493,'C-493-06-26'),(189,7,494,'C-494-06-26'),(190,7,495,'C-495-06-26'),(191,7,496,'C-496-06-26'),(192,7,497,'C-497-06-26'),(193,7,498,'C-498-06-26'),(194,7,499,'C-499-06-26'),(195,7,500,'C-500-06-26'),(196,7,501,'C-501-06-26'),(197,8,104,'S-104-06-26'),(198,8,105,'S-105-06-26'),(199,8,106,'S-106-06-26'),(200,8,107,'S-107-06-26'),(201,8,108,'S-108-06-26');
/*!40000 ALTER TABLE `muestra` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `solicitud`
--

DROP TABLE IF EXISTS `solicitud`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `solicitud` (
  `id_solicitud` int NOT NULL AUTO_INCREMENT,
  `id_tipo` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `codigo_muestreo` varchar(255) DEFAULT NULL,
  `fecha_muestreo` date DEFAULT NULL,
  `numero_muestras` int DEFAULT NULL,
  `numero_laboratorio_inicio` int DEFAULT NULL,
  `numero_laboratorio_final` int DEFAULT NULL,
  `institucion` varchar(255) DEFAULT NULL,
  `responsable_envio` varchar(255) DEFAULT NULL,
  `ingresado_por` varchar(255) DEFAULT NULL,
  `correo_ingresado` varchar(255) DEFAULT NULL,
  `recibido_por` varchar(255) DEFAULT NULL,
  `correo_recibido` varchar(255) DEFAULT NULL,
  `fecha_ingreso` date DEFAULT NULL,
  `fecha_estimada` date DEFAULT NULL,
  `observaciones` text,
  PRIMARY KEY (`id_solicitud`),
  KEY `fk_solicitud_tipo` (`id_tipo`),
  KEY `fk_solicitud_lote` (`id_lote`),
  CONSTRAINT `fk_solicitud_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`),
  CONSTRAINT `fk_solicitud_tipo` FOREIGN KEY (`id_tipo`) REFERENCES `tipo_muestra` (`id_tipo`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `solicitud`
--

LOCK TABLES `solicitud` WRITE;
/*!40000 ALTER TABLE `solicitud` DISABLE KEYS */;
INSERT INTO `solicitud` (`id_solicitud`,`id_tipo`,`id_lote`,`codigo_muestreo`,`fecha_muestreo`,`numero_muestras`,`numero_laboratorio_inicio`,`numero_laboratorio_final`,`institucion`,`responsable_envio`,`ingresado_por`,`correo_ingresado`,`fecha_ingreso`,`fecha_estimada`,`observaciones`) VALUES (1,1,1,'','2026-06-11',7,NULL,NULL,'',NULL,'',NULL,'2026-06-02',NULL,''),(2,4,2,'','2026-06-08',7,NULL,NULL,'',NULL,'',NULL,'2026-06-02',NULL,''),(3,4,3,'2547','2026-06-04',80,NULL,NULL,'YO',NULL,'YO',NULL,'2026-06-04','2026-06-04','DFDFS'),(4,4,3,'2547','2026-06-04',80,NULL,NULL,'YO',NULL,'YO',NULL,'2026-06-04','2026-06-04','DFDFS'),(5,1,1,'','2026-06-08',2,NULL,NULL,'psepe',NULL,'dasd',NULL,'2026-06-08','2026-06-27','nadota'),(6,1,4,'','2026-06-10',10,NULL,NULL,'CAPIBARA BEBE',NULL,'CAPIBARA MAYOR',NULL,'2026-06-09',NULL,'Capicomentarios'),(7,2,5,'','2026-06-10',10,NULL,NULL,'Diego',NULL,'Andre',NULL,'2026-06-10','2026-06-30',''),(8,1,6,'','2026-06-11',5,NULL,NULL,'YO',NULL,'YO',NULL,'2026-06-10','2026-06-10','jkjhkjh');
/*!40000 ALTER TABLE `solicitud` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `solicitud_analisis`
--

DROP TABLE IF EXISTS `solicitud_analisis`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `solicitud_analisis` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `id_tipo_analisis` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_sol_analisis_solicitud` (`id_solicitud`),
  KEY `fk_sol_analisis_tipo` (`id_tipo_analisis`),
  CONSTRAINT `fk_sol_analisis_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `fk_sol_analisis_tipo` FOREIGN KEY (`id_tipo_analisis`) REFERENCES `tipo_analisis` (`id_tipo`)
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `solicitud_analisis`
--

LOCK TABLES `solicitud_analisis` WRITE;
/*!40000 ALTER TABLE `solicitud_analisis` DISABLE KEYS */;
INSERT INTO `solicitud_analisis` VALUES (1,2,1),(2,3,2),(3,3,3),(4,3,4),(5,3,5),(6,3,6),(7,3,7),(8,4,2),(9,4,3),(10,4,4),(11,4,5),(12,4,6),(13,4,7),(14,5,8),(15,5,9),(16,5,10),(17,6,8),(18,6,11),(19,6,12),(20,6,9),(21,6,10),(22,6,13),(23,6,14),(24,6,15),(25,6,16),(26,6,17),(27,6,18),(28,7,19),(29,8,8),(30,8,11),(31,8,12);
/*!40000 ALTER TABLE `solicitud_analisis` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `suelo_azufre`
--

DROP TABLE IF EXISTS `suelo_azufre`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `suelo_azufre` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `abs_blanco` decimal(10,4) DEFAULT NULL,
  `absorbancia` decimal(10,4) DEFAULT NULL,
  `ppm_so4` decimal(10,4) DEFAULT NULL,
  `control` decimal(10,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  CONSTRAINT `suelo_azufre_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `suelo_azufre_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `suelo_azufre_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `suelo_azufre`
--

LOCK TABLES `suelo_azufre` WRITE;
/*!40000 ALTER TABLE `suelo_azufre` DISABLE KEYS */;
/*!40000 ALTER TABLE `suelo_azufre` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `suelo_azufre_curva`
--

DROP TABLE IF EXISTS `suelo_azufre_curva`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `suelo_azufre_curva` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `id_azufre` int DEFAULT NULL,
  `id_curva_azufre` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  KEY `id_azufre` (`id_azufre`),
  KEY `id_curva_azufre` (`id_curva_azufre`),
  CONSTRAINT `suelo_azufre_curva_ibfk_1` FOREIGN KEY (`id_azufre`) REFERENCES `suelo_azufre` (`id`),
  CONSTRAINT `suelo_azufre_curva_ibfk_2` FOREIGN KEY (`id_curva_azufre`) REFERENCES `curva_azufre` (`id_curva`),
  CONSTRAINT `suelo_azufre_curva_ibfk_3` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `suelo_azufre_curva_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `suelo_azufre_curva_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `suelo_azufre_curva`
--

LOCK TABLES `suelo_azufre_curva` WRITE;
/*!40000 ALTER TABLE `suelo_azufre_curva` DISABLE KEYS */;
/*!40000 ALTER TABLE `suelo_azufre_curva` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `suelo_ce`
--

DROP TABLE IF EXISTS `suelo_ce`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `suelo_ce` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `lectura` decimal(10,4) DEFAULT NULL,
  `temperatura` decimal(10,4) DEFAULT NULL,
  `ce` decimal(10,4) DEFAULT NULL,
  `id_encabezado` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  KEY `fk_suelo_ce_encabezado` (`id_encabezado`),
  CONSTRAINT `fk_suelo_ce_encabezado` FOREIGN KEY (`id_encabezado`) REFERENCES `encabezado` (`id_encabezado`),
  CONSTRAINT `suelo_ce_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `suelo_ce_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `suelo_ce_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `suelo_ce`
--

LOCK TABLES `suelo_ce` WRITE;
/*!40000 ALTER TABLE `suelo_ce` DISABLE KEYS */;
/*!40000 ALTER TABLE `suelo_ce` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `suelo_dap`
--

DROP TABLE IF EXISTS `suelo_dap`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `suelo_dap` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `peso_caja` decimal(10,4) DEFAULT NULL,
  `peso_muestra_seca` decimal(10,4) DEFAULT NULL,
  `volumen_final` decimal(10,4) DEFAULT NULL,
  `peso_suelo_seco` decimal(10,4) DEFAULT NULL,
  `densidad` decimal(10,4) DEFAULT NULL,
  `id_encabezado` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  KEY `fk_suelo_dap_encabezado` (`id_encabezado`),
  CONSTRAINT `fk_suelo_dap_encabezado` FOREIGN KEY (`id_encabezado`) REFERENCES `encabezado` (`id_encabezado`),
  CONSTRAINT `suelo_dap_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `suelo_dap_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `suelo_dap_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `suelo_dap`
--

LOCK TABLES `suelo_dap` WRITE;
/*!40000 ALTER TABLE `suelo_dap` DISABLE KEYS */;
/*!40000 ALTER TABLE `suelo_dap` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `suelo_fosforo`
--

DROP TABLE IF EXISTS `suelo_fosforo`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `suelo_fosforo` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `blanco` decimal(10,4) DEFAULT NULL,
  `absorbancia` decimal(10,4) DEFAULT NULL,
  `ppm_solucion` decimal(10,4) DEFAULT NULL,
  `ppm_suelo` decimal(10,4) DEFAULT NULL,
  `control` decimal(10,4) DEFAULT NULL,
  `id_encabezado` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  KEY `fk_suelo_fosforo_encabezado` (`id_encabezado`),
  CONSTRAINT `fk_suelo_fosforo_encabezado` FOREIGN KEY (`id_encabezado`) REFERENCES `encabezado` (`id_encabezado`),
  CONSTRAINT `suelo_fosforo_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `suelo_fosforo_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `suelo_fosforo_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `suelo_fosforo`
--

LOCK TABLES `suelo_fosforo` WRITE;
/*!40000 ALTER TABLE `suelo_fosforo` DISABLE KEYS */;
/*!40000 ALTER TABLE `suelo_fosforo` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `suelo_fosforo_curva`
--

DROP TABLE IF EXISTS `suelo_fosforo_curva`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `suelo_fosforo_curva` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `id_fosforo` int DEFAULT NULL,
  `id_curva_fosforo` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  KEY `id_fosforo` (`id_fosforo`),
  KEY `id_curva_fosforo` (`id_curva_fosforo`),
  CONSTRAINT `suelo_fosforo_curva_ibfk_1` FOREIGN KEY (`id_fosforo`) REFERENCES `suelo_fosforo` (`id`),
  CONSTRAINT `suelo_fosforo_curva_ibfk_2` FOREIGN KEY (`id_curva_fosforo`) REFERENCES `curva_fosforo` (`id_curva`),
  CONSTRAINT `suelo_fosforo_curva_ibfk_3` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `suelo_fosforo_curva_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `suelo_fosforo_curva_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `suelo_fosforo_curva`
--

LOCK TABLES `suelo_fosforo_curva` WRITE;
/*!40000 ALTER TABLE `suelo_fosforo_curva` DISABLE KEYS */;
/*!40000 ALTER TABLE `suelo_fosforo_curva` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `suelo_humedad`
--

DROP TABLE IF EXISTS `suelo_humedad`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `suelo_humedad` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `humedad` decimal(10,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  CONSTRAINT `suelo_humedad_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `suelo_humedad_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `suelo_humedad_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `suelo_humedad`
--

LOCK TABLES `suelo_humedad` WRITE;
/*!40000 ALTER TABLE `suelo_humedad` DISABLE KEYS */;
/*!40000 ALTER TABLE `suelo_humedad` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `suelo_macros`
--

DROP TABLE IF EXISTS `suelo_macros`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `suelo_macros` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `peso` decimal(10,4) DEFAULT NULL,
  `ppm_ca` decimal(10,4) DEFAULT NULL,
  `ppm_mg` decimal(10,4) DEFAULT NULL,
  `ppm_k` decimal(10,4) DEFAULT NULL,
  `ppm_na` decimal(10,4) DEFAULT NULL,
  `blk_ca` decimal(10,4) DEFAULT NULL,
  `blk_mg` decimal(10,4) DEFAULT NULL,
  `blk_k` decimal(10,4) DEFAULT NULL,
  `blk_na` decimal(10,4) DEFAULT NULL,
  `cic_blanco` decimal(10,4) DEFAULT NULL,
  `cic_muestra` decimal(10,4) DEFAULT NULL,
  `meq_ca` decimal(10,4) DEFAULT NULL,
  `meq_mg` decimal(10,4) DEFAULT NULL,
  `meq_k` decimal(10,4) DEFAULT NULL,
  `meq_na` decimal(10,4) DEFAULT NULL,
  `cic_meq` decimal(10,4) DEFAULT NULL,
  `control` decimal(10,4) DEFAULT NULL,
  `id_encabezado` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  KEY `fk_suelo_macros_encabezado` (`id_encabezado`),
  CONSTRAINT `fk_suelo_macros_encabezado` FOREIGN KEY (`id_encabezado`) REFERENCES `encabezado` (`id_encabezado`),
  CONSTRAINT `suelo_macros_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `suelo_macros_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `suelo_macros_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `suelo_macros`
--

LOCK TABLES `suelo_macros` WRITE;
/*!40000 ALTER TABLE `suelo_macros` DISABLE KEYS */;
/*!40000 ALTER TABLE `suelo_macros` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `suelo_materia_organica`
--

DROP TABLE IF EXISTS `suelo_materia_organica`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `suelo_materia_organica` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `materia_organica` decimal(10,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  CONSTRAINT `suelo_materia_organica_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `suelo_materia_organica_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `suelo_materia_organica_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `suelo_materia_organica`
--

LOCK TABLES `suelo_materia_organica` WRITE;
/*!40000 ALTER TABLE `suelo_materia_organica` DISABLE KEYS */;
/*!40000 ALTER TABLE `suelo_materia_organica` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `suelo_micros`
--

DROP TABLE IF EXISTS `suelo_micros`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `suelo_micros` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `peso` decimal(10,4) DEFAULT '0.0000',
  `conc_cu` decimal(10,4) DEFAULT '0.0000',
  `conc_zn` decimal(10,4) DEFAULT '0.0000',
  `conc_fe` decimal(10,4) DEFAULT '0.0000',
  `conc_mn` decimal(10,4) DEFAULT '0.0000',
  `conc_k` decimal(10,4) DEFAULT '0.0000',
  `blk_cu` decimal(10,4) DEFAULT '0.0000',
  `blk_zn` decimal(10,4) DEFAULT '0.0000',
  `blk_fe` decimal(10,4) DEFAULT '0.0000',
  `blk_mn` decimal(10,4) DEFAULT '0.0000',
  `blk_k` decimal(10,4) DEFAULT '0.0000',
  `ppm_cu` decimal(10,4) DEFAULT '0.0000',
  `ppm_zn` decimal(10,4) DEFAULT '0.0000',
  `ppm_fe` decimal(10,4) DEFAULT '0.0000',
  `ppm_mn` decimal(10,4) DEFAULT '0.0000',
  `ppm_k` decimal(10,4) DEFAULT '0.0000',
  `control` decimal(10,4) DEFAULT '0.0000',
  `id_encabezado` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  KEY `fk_suelo_micros_encabezado` (`id_encabezado`),
  CONSTRAINT `fk_suelo_micros_encabezado` FOREIGN KEY (`id_encabezado`) REFERENCES `encabezado` (`id_encabezado`),
  CONSTRAINT `suelo_micros_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `suelo_micros_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `suelo_micros_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `suelo_micros`
--

LOCK TABLES `suelo_micros` WRITE;
/*!40000 ALTER TABLE `suelo_micros` DISABLE KEYS */;
/*!40000 ALTER TABLE `suelo_micros` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `suelo_nitrogeno`
--

DROP TABLE IF EXISTS `suelo_nitrogeno`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `suelo_nitrogeno` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `peso` decimal(10,4) DEFAULT NULL,
  `hcl_blanco` decimal(10,4) DEFAULT NULL,
  `hcl_muestra` decimal(10,4) DEFAULT NULL,
  `porcentaje_n` decimal(10,4) DEFAULT NULL,
  `normalidad` decimal(10,4) DEFAULT NULL,
  `x_nitrogeno` varchar(255) DEFAULT NULL,
  `control` decimal(10,4) DEFAULT NULL,
  `id_encabezado` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  KEY `fk_suelo_nitrogeno_encabezado` (`id_encabezado`),
  CONSTRAINT `fk_suelo_nitrogeno_encabezado` FOREIGN KEY (`id_encabezado`) REFERENCES `encabezado` (`id_encabezado`),
  CONSTRAINT `suelo_nitrogeno_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `suelo_nitrogeno_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `suelo_nitrogeno_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `suelo_nitrogeno`
--

LOCK TABLES `suelo_nitrogeno` WRITE;
/*!40000 ALTER TABLE `suelo_nitrogeno` DISABLE KEYS */;
/*!40000 ALTER TABLE `suelo_nitrogeno` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `suelo_ph`
--

DROP TABLE IF EXISTS `suelo_ph`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `suelo_ph` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `ph` decimal(10,4) DEFAULT NULL,
  `temperatura` decimal(10,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  CONSTRAINT `suelo_ph_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `suelo_ph_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `suelo_ph_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `suelo_ph`
--

LOCK TABLES `suelo_ph` WRITE;
/*!40000 ALTER TABLE `suelo_ph` DISABLE KEYS */;
/*!40000 ALTER TABLE `suelo_ph` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `suelo_quimicos`
--

DROP TABLE IF EXISTS `suelo_quimicos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `suelo_quimicos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `nitrogeno` decimal(10,4) DEFAULT NULL,
  `fosforo` decimal(10,4) DEFAULT NULL,
  `potasio` decimal(10,4) DEFAULT NULL,
  `calcio` decimal(10,4) DEFAULT NULL,
  `magnesio` decimal(10,4) DEFAULT NULL,
  `sodio` decimal(10,4) DEFAULT NULL,
  `azufre` decimal(10,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  CONSTRAINT `suelo_quimicos_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `suelo_quimicos_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `suelo_quimicos_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `suelo_quimicos`
--

LOCK TABLES `suelo_quimicos` WRITE;
/*!40000 ALTER TABLE `suelo_quimicos` DISABLE KEYS */;
/*!40000 ALTER TABLE `suelo_quimicos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `suelo_cc`
--

DROP TABLE IF EXISTS `suelo_cc`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `suelo_cc` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `peso_caja` decimal(10,2) DEFAULT '0.00',
  `peso_caja_mhumeda` decimal(10,2) DEFAULT '0.00',
  `peso_caja_mseca` decimal(10,2) DEFAULT '0.00',
  `psh` decimal(10,2) DEFAULT '0.00',
  `pss` decimal(10,2) DEFAULT '0.00',
  `porcentaje_cc` decimal(10,2) DEFAULT '0.00',
  `no_caja` varchar(50) DEFAULT NULL,
  `control` decimal(10,4) DEFAULT '0.0000',
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  CONSTRAINT `suelo_cc_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `suelo_cc_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `suelo_cc_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `suelo_cc`
--

LOCK TABLES `suelo_cc` WRITE;
/*!40000 ALTER TABLE `suelo_cc` DISABLE KEYS */;
/*!40000 ALTER TABLE `suelo_cc` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `suelo_pmp`
--

DROP TABLE IF EXISTS `suelo_pmp`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `suelo_pmp` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `peso_caja` decimal(10,4) DEFAULT '0.0000',
  `peso_caja_mhumeda` decimal(10,4) DEFAULT '0.0000',
  `peso_caja_mseca` decimal(10,4) DEFAULT '0.0000',
  `psh` decimal(10,4) DEFAULT '0.0000',
  `pss` decimal(10,4) DEFAULT '0.0000',
  `porcentaje_pmp` decimal(10,4) DEFAULT '0.0000',
  `no_caja` varchar(50) DEFAULT NULL,
  `control` decimal(10,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  CONSTRAINT `suelo_pmp_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `suelo_pmp_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `suelo_pmp_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `suelo_pmp`
--

LOCK TABLES `suelo_pmp` WRITE;
/*!40000 ALTER TABLE `suelo_pmp` DISABLE KEYS */;
/*!40000 ALTER TABLE `suelo_pmp` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `suelo_boro`
--

DROP TABLE IF EXISTS `suelo_boro`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `suelo_boro` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `abs_blanco` decimal(10,4) DEFAULT '0.0000',
  `absorbancia` decimal(10,4) DEFAULT '0.0000',
  `ppm_b` decimal(10,4) DEFAULT '0.0000',
  `control` decimal(10,4) DEFAULT '0.0000',
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  CONSTRAINT `suelo_boro_ibfk_1` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `suelo_boro_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `suelo_boro_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `suelo_boro`
--

LOCK TABLES `suelo_boro` WRITE;
/*!40000 ALTER TABLE `suelo_boro` DISABLE KEYS */;
/*!40000 ALTER TABLE `suelo_boro` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `suelo_boro_curva`
--

DROP TABLE IF EXISTS `suelo_boro_curva`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `suelo_boro_curva` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_solicitud` int DEFAULT NULL,
  `numero_laboratorio` int DEFAULT NULL,
  `id_lote` int DEFAULT NULL,
  `id_formulario` int DEFAULT NULL,
  `id_boro` int DEFAULT NULL,
  `id_curva_boro` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_formulario` (`id_formulario`),
  KEY `id_solicitud` (`id_solicitud`),
  KEY `id_lote` (`id_lote`),
  KEY `id_boro` (`id_boro`),
  KEY `id_curva_boro` (`id_curva_boro`),
  CONSTRAINT `suelo_boro_curva_ibfk_1` FOREIGN KEY (`id_boro`) REFERENCES `suelo_boro` (`id`),
  CONSTRAINT `suelo_boro_curva_ibfk_2` FOREIGN KEY (`id_curva_boro`) REFERENCES `curva_boro` (`id_curva`),
  CONSTRAINT `suelo_boro_curva_ibfk_3` FOREIGN KEY (`id_formulario`) REFERENCES `formulario` (`id_formulario`),
  CONSTRAINT `suelo_boro_curva_ibfk_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitud` (`id_solicitud`),
  CONSTRAINT `suelo_boro_curva_ibfk_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `suelo_boro_curva`
--

LOCK TABLES `suelo_boro_curva` WRITE;
/*!40000 ALTER TABLE `suelo_boro_curva` DISABLE KEYS */;
/*!40000 ALTER TABLE `suelo_boro_curva` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tipo_analisis`
--

DROP TABLE IF EXISTS `tipo_analisis`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tipo_analisis` (
  `id_tipo` int NOT NULL AUTO_INCREMENT,
  `id_tipo_muestra` int DEFAULT NULL,
  `nombre` varchar(255) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id_tipo`),
  KEY `fk_tipo_analisis_tipo_muestra` (`id_tipo_muestra`),
  CONSTRAINT `fk_tipo_analisis_tipo_muestra` FOREIGN KEY (`id_tipo_muestra`) REFERENCES `tipo_muestra` (`id_tipo`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tipo_analisis`
--

LOCK TABLES `tipo_analisis` WRITE;
/*!40000 ALTER TABLE `tipo_analisis` DISABLE KEYS */;
INSERT INTO `tipo_analisis` VALUES (1,4,'SÃ³lidos totales disueltos (STD)',1),(2,4,'pH',1),(3,4,'Conductividad elÃƒÂ©ctrica (CE)',1),(4,4,'SÃƒÂ³lidos totales disueltos (STD)',1),(5,4,'Dureza total (CaCOÃ¢â€šÆ’)',1),(6,4,'Coliformes totales y fecales',1),(7,4,'Nitratos / Nitritos',1),(8,1,'Textura',1),(9,1,'Humedad gravimÃƒÂ©trica',1),(10,1,'Porosidad total',1),(11,1,'Densidad aparente',1),(12,1,'Densidad real',1),(13,1,'pH',1),(14,1,'Materia orgÃƒÂ¡nica',1),(15,1,'NitrÃƒÂ³geno total',1),(16,1,'FÃƒÂ³sforo disponible',1),(17,1,'Potasio intercambiable',1),(18,1,'CIC (capacidad de intercambio catiÃƒÂ³nico)',1),(19,2,'Fibra bruta',1);
/*!40000 ALTER TABLE `tipo_analisis` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tipo_error`
--

DROP TABLE IF EXISTS `tipo_error`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tipo_error` (
  `id_error` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) DEFAULT NULL,
  `descripcion` text,
  `severidad` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id_error`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tipo_error`
--

LOCK TABLES `tipo_error` WRITE;
/*!40000 ALTER TABLE `tipo_error` DISABLE KEYS */;
/*!40000 ALTER TABLE `tipo_error` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tipo_muestra`
--

DROP TABLE IF EXISTS `tipo_muestra`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tipo_muestra` (
  `id_tipo` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) DEFAULT NULL,
  `prefijo` char(1) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id_tipo`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tipo_muestra`
--

LOCK TABLES `tipo_muestra` WRITE;
/*!40000 ALTER TABLE `tipo_muestra` DISABLE KEYS */;
INSERT INTO `tipo_muestra` (`id_tipo`, `nombre`, `prefijo`, `activo`) VALUES (1,'suelos','s',1),(2,'cañas','c',1),(3,'mieles','m',1),(4,'agua','a',1),(5,'foliares','f',1);
/*!40000 ALTER TABLE `tipo_muestra` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-06-10 15:32:02
