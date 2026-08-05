/*
SQLyog Ultimate v9.10 
MySQL - 5.5.5-10.1.26-MariaDB : Database - cengi_cursos
*********************************************************************
*/

/*!40101 SET NAMES utf8 */;

/*!40101 SET SQL_MODE=''*/;

/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
CREATE DATABASE /*!32312 IF NOT EXISTS*/`cengi_cursos` /*!40100 DEFAULT CHARACTER SET utf8 */;

USE `cengi_cursos`;

/*Table structure for table `asignaciones` */

DROP TABLE IF EXISTS `asignaciones`;

CREATE TABLE `asignaciones` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `participantes_id` int(10) unsigned NOT NULL,
  `usuarios_id` int(10) unsigned NOT NULL,
  `cursos_id` int(10) unsigned NOT NULL,
  `estado_asignaciones` int(11) NOT NULL DEFAULT '1',
  `creado` timestamp NULL DEFAULT NULL,
  `actualizado` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `particip_curso` (`participantes_id`,`cursos_id`),
  KEY `asignaciones_participantes_id_foreign` (`participantes_id`),
  KEY `asignaciones_usuarios_id_foreign` (`usuarios_id`),
  KEY `asignaciones_cursos_id_foreign` (`cursos_id`),
  CONSTRAINT `asignaciones_cursos_id_foreign` FOREIGN KEY (`cursos_id`) REFERENCES `cursos` (`id`),
  CONSTRAINT `asignaciones_participantes_id_foreign` FOREIGN KEY (`participantes_id`) REFERENCES `participantes` (`id`),
  CONSTRAINT `asignaciones_usuarios_id_foreign` FOREIGN KEY (`usuarios_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*Data for the table `asignaciones` */

LOCK TABLES `asignaciones` WRITE;

insert  into `asignaciones`(`id`,`participantes_id`,`usuarios_id`,`cursos_id`,`estado_asignaciones`,`creado`,`actualizado`) values (1,1,1,1,1,'2019-02-11 12:26:39','2019-02-11 12:26:39'),(2,2,1,1,1,'2019-02-11 12:26:39','2019-02-11 12:26:39'),(3,3,1,1,1,'2019-02-11 12:26:39','2019-02-11 12:26:39');

UNLOCK TABLES;

/*Table structure for table `asignaciones_import` */

DROP TABLE IF EXISTS `asignaciones_import`;

CREATE TABLE `asignaciones_import` (
  `id` int(10) unsigned DEFAULT NULL,
  `participantes_id` int(10) unsigned NOT NULL,
  `usuarios_id` int(10) unsigned NOT NULL,
  `cursos_id` int(10) unsigned NOT NULL,
  `estado_asignaciones` int(11) NOT NULL DEFAULT '1',
  `creado` timestamp NULL DEFAULT NULL,
  `actualizado` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `asignaciones_participantes_id_foreign` (`participantes_id`),
  KEY `asignaciones_usuarios_id_foreign` (`usuarios_id`),
  KEY `asignaciones_cursos_id_foreign` (`cursos_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*Data for the table `asignaciones_import` */

LOCK TABLES `asignaciones_import` WRITE;

insert  into `asignaciones_import`(`id`,`participantes_id`,`usuarios_id`,`cursos_id`,`estado_asignaciones`,`creado`,`actualizado`) values (NULL,1,1,1,1,'2019-02-11 12:26:39','2019-02-11 12:26:39'),(NULL,2,1,1,1,'2019-02-11 12:26:39','2019-02-11 12:26:39'),(NULL,3,1,1,1,'2019-02-11 12:26:39','2019-02-11 12:26:39');

UNLOCK TABLES;

/*Table structure for table `asistencia` */

DROP TABLE IF EXISTS `asistencia`;

CREATE TABLE `asistencia` (
  `id_asistencia` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_asignacion` int(10) unsigned DEFAULT NULL,
  `fecha` date DEFAULT NULL,
  PRIMARY KEY (`id_asistencia`),
  KEY `FK_asistencia_asignacion` (`id_asignacion`),
  CONSTRAINT `FK_asistencia_asignacion` FOREIGN KEY (`id_asignacion`) REFERENCES `asignaciones` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

/*Data for the table `asistencia` */

LOCK TABLES `asistencia` WRITE;

UNLOCK TABLES;

/*Table structure for table `categorias_cursos` */

DROP TABLE IF EXISTS `categorias_cursos`;

CREATE TABLE `categorias_cursos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `descripcion_categorias_cursos` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `estado_categorias_cursos` int(11) NOT NULL DEFAULT '1',
  `creado` timestamp NULL DEFAULT NULL,
  `actualizado` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*Data for the table `categorias_cursos` */

LOCK TABLES `categorias_cursos` WRITE;

insert  into `categorias_cursos`(`id`,`descripcion_categorias_cursos`,`estado_categorias_cursos`,`creado`,`actualizado`) values (1,'Informática',1,NULL,'2019-02-11 12:25:42');

UNLOCK TABLES;

/*Table structure for table `cursos` */

DROP TABLE IF EXISTS `cursos`;

CREATE TABLE `cursos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `categoria_curso_id` int(10) unsigned NOT NULL,
  `ingenio_id` int(10) unsigned NOT NULL,
  `nombre_cursos` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `jornada_cursos` enum('Matutina','Vespertina','Todo Completo') COLLATE utf8mb4_unicode_ci NOT NULL,
  `dias` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `horario` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `inicio` date NOT NULL,
  `fin` date NOT NULL,
  `creado` timestamp NULL DEFAULT NULL,
  `actualizado` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `cursos_categoria_curso_id_foreign` (`categoria_curso_id`),
  KEY `cursos_ingenio_id_foreign` (`ingenio_id`),
  CONSTRAINT `cursos_categoria_curso_id_foreign` FOREIGN KEY (`categoria_curso_id`) REFERENCES `categorias_cursos` (`id`),
  CONSTRAINT `cursos_ingenio_id_foreign` FOREIGN KEY (`ingenio_id`) REFERENCES `ingenios` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*Data for the table `cursos` */

LOCK TABLES `cursos` WRITE;

insert  into `cursos`(`id`,`categoria_curso_id`,`ingenio_id`,`nombre_cursos`,`jornada_cursos`,`dias`,`horario`,`inicio`,`fin`,`creado`,`actualizado`) values (1,1,1,'Excel Avanzado','Vespertina','Lunes, Miércoles, Viernes','8:00 - 12:00','2015-05-20','0000-00-00','2019-02-11 12:26:21','2019-02-11 12:26:21'),(2,1,1,'Programación Orientada a objetos','Vespertina','miercoles, viernes','8:00 a 13:00','0000-00-00','0000-00-00','2019-04-01 09:52:09','2019-04-01 09:52:09'),(3,1,1,'Automatas','Matutina','lunes','13:00 a 17:00','0000-00-00','0000-00-00','2019-04-01 09:56:13','2019-04-01 09:56:13'),(4,1,1,'metodos','Matutina','lunes','8:00-12:00','2019-06-07','0000-00-00','2019-04-01 11:30:37','2019-04-01 11:30:37');

UNLOCK TABLES;

/*Table structure for table `ingenios` */

DROP TABLE IF EXISTS `ingenios`;

CREATE TABLE `ingenios` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre_ingenios` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `creado` timestamp NULL DEFAULT NULL,
  `actualizado` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8;

/*Data for the table `ingenios` */

LOCK TABLES `ingenios` WRITE;

insert  into `ingenios`(`id`,`nombre_ingenios`,`creado`,`actualizado`) values (1,'CENGICAÑA','2019-02-11 12:22:08',NULL),(2,'Pantaleón','2019-04-01 11:33:24',NULL);

UNLOCK TABLES;

/*Table structure for table `participantes` */

DROP TABLE IF EXISTS `participantes`;

CREATE TABLE `participantes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ingenio_id` int(10) unsigned NOT NULL,
  `usuarios_id` int(10) unsigned NOT NULL,
  `cui_participantes` varchar(25) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nombre_participantes` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `puesto_participantes` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `area_participantes` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `correo_participantes` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `grado_academico_participantes` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `telefono_participantes` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `estado_participantes` int(11) NOT NULL DEFAULT '1',
  `creado` timestamp NULL DEFAULT NULL,
  `actualizado` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `participantes_cui_participantes_unique` (`cui_participantes`),
  KEY `participantes_cui_participantes_index` (`cui_participantes`),
  KEY `participantes_ingenio_id_foreign` (`ingenio_id`),
  CONSTRAINT `participantes_ingenio_id_foreign` FOREIGN KEY (`ingenio_id`) REFERENCES `ingenios` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*Data for the table `participantes` */

LOCK TABLES `participantes` WRITE;

insert  into `participantes`(`id`,`ingenio_id`,`usuarios_id`,`cui_participantes`,`nombre_participantes`,`puesto_participantes`,`area_participantes`,`estado_participantes`,`creado`,`actualizado`) values (1,1,0,'2207734160502','Monica de Bran','TÃ©cnico','Transferencia de TecnologÃ­a',1,'2019-02-11 12:26:39','2019-02-11 12:26:39'),(2,1,0,'2207734160503','Dulce Castillo','Auxiliar','CapacitaciÃ³n',1,'2019-02-11 12:26:39','2019-02-11 12:26:39'),(3,1,0,'2207734160504','Argentina Abalos','Secretaria','CapacitaciÃ³n',1,'2019-02-11 12:26:39','2019-02-11 12:26:39');

UNLOCK TABLES;

/*Table structure for table `participantes_import` */

DROP TABLE IF EXISTS `participantes_import`;

CREATE TABLE `participantes_import` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ingenio_id` int(10) unsigned NOT NULL,
  `usuarios_id` int(10) unsigned NOT NULL,
  `cui_participantes` varchar(25) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nombre_participantes` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `puesto_participantes` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `area_participantes` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `correo_participantes` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `grado_academico_participantes` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `telefono_participantes` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `estado_participantes` int(11) NOT NULL DEFAULT '1',
  `creado` timestamp NULL DEFAULT NULL,
  `actualizado` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `participantes_cui_participantes_unique` (`cui_participantes`),
  KEY `participantes_cui_participantes_index` (`cui_participantes`),
  KEY `participantes_ingenio_id_foreign` (`ingenio_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*Data for the table `participantes_import` */

LOCK TABLES `participantes_import` WRITE;

UNLOCK TABLES;

/*Table structure for table `users` */

DROP TABLE IF EXISTS `users`;

CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ingenio_id` int(10) unsigned NOT NULL DEFAULT '2',
  `rol` enum('Administrador','Delegado') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Delegado',
  `creado` timestamp NULL DEFAULT NULL,
  `actualizado` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `usuarios_email_unique` (`email`),
  UNIQUE KEY `email` (`email`),
  KEY `FK_usuarios_ingenios` (`ingenio_id`),
  CONSTRAINT `FK_users` FOREIGN KEY (`id`) REFERENCES `participantes` (`id`),
  CONSTRAINT `FK_usuarios_ingenios` FOREIGN KEY (`ingenio_id`) REFERENCES `ingenios` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*Data for the table `users` */

LOCK TABLES `users` WRITE;

insert  into `users`(`id`,`nombre`,`email`,`password`,`ingenio_id`,`rol`,`creado`,`actualizado`) values (1,'Monica Galiego','mgaliego@cengicana.org','30c6ca9a7a564e962a84dddb32f0fa3d',1,'Administrador',NULL,NULL);

UNLOCK TABLES;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
