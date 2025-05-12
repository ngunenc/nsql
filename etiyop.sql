-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Anamakine: 127.0.0.1:3306
-- Üretim Zamanı: 07 Nis 2025, 20:53:42
-- Sunucu sürümü: 11.7.2-MariaDB
-- PHP Sürümü: 8.4.5

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Veritabanı: `etiyop`
--

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `firmalar`
--

DROP TABLE IF EXISTS `firmalar`;
CREATE TABLE IF NOT EXISTS `firmalar` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `durum` int(11) NOT NULL DEFAULT 1,
  `sirket_unvani` text NOT NULL,
  `firma_yetkili` varchar(250) NOT NULL,
  `yetkili_telefon` varchar(12) NOT NULL,
  `ham_tarih` bigint(20) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

--
-- Tablo döküm verisi `firmalar`
--

INSERT INTO `firmalar` (`id`, `durum`, `sirket_unvani`, `firma_yetkili`, `yetkili_telefon`, `ham_tarih`) VALUES
(1, 1, 'MR Yazılım', ' Necip GÜNENÇ', '12345678901', 1742555671),
(2, 1, 'MR Yazılım', ' Ramazan KONUR', '05383921155', 1743968429);

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `giris_kayitlari`
--

DROP TABLE IF EXISTS `giris_kayitlari`;
CREATE TABLE IF NOT EXISTS `giris_kayitlari` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firma_id` int(11) NOT NULL,
  `kullanici_id` int(11) NOT NULL,
  `kullanici_kimlik` text NOT NULL,
  `token` varchar(250) NOT NULL,
  `giris_tarihi` bigint(20) NOT NULL,
  `browser` text NOT NULL,
  `platform` text NOT NULL,
  `user_agent` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

--
-- Tablo döküm verisi `giris_kayitlari`
--

INSERT INTO `giris_kayitlari` (`id`, `firma_id`, `kullanici_id`, `kullanici_kimlik`, `token`, `giris_tarihi`, `browser`, `platform`, `user_agent`) VALUES
(1, 1, 1, 'b1d820d58a6f1b6ee0482064fafb3c15', '6680572b49acecdfd60a465c3e2b9e5d', 1743975762, ' Not_A Brand', 'Windows', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 OPR/116.0.0.0'),
(2, 2, 2, '1630de9df0956c30053a52bd580b4847', '062ab31583cceacffc8301e10afd0329', 1743968433, ' Opera Air', 'Windows', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36 OPR/117.0.0.0');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `kullanicilar`
--

DROP TABLE IF EXISTS `kullanicilar`;
CREATE TABLE IF NOT EXISTS `kullanicilar` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `durum` int(11) NOT NULL DEFAULT 1,
  `kullanici_adi` varchar(250) DEFAULT NULL,
  `parola` text DEFAULT NULL,
  `isim` varchar(250) DEFAULT NULL,
  `soyisim` varchar(250) DEFAULT NULL,
  `tam_isim` varchar(250) DEFAULT NULL,
  `telefon` bigint(20) DEFAULT NULL,
  `eposta` varchar(250) DEFAULT NULL,
  `firma_id` int(11) DEFAULT NULL,
  `kullanici_kimlik` text DEFAULT NULL,
  `kayit_tam_tarih` bigint(20) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

--
-- Tablo döküm verisi `kullanicilar`
--

INSERT INTO `kullanicilar` (`id`, `durum`, `kullanici_adi`, `parola`, `isim`, `soyisim`, `tam_isim`, `telefon`, `eposta`, `firma_id`, `kullanici_kimlik`, `kayit_tam_tarih`) VALUES
(1, 1, 'admin', '1de4f4eb145d2d0887e695f0d5c5fdb5', 'Necip', 'GÜNENÇ', 'Necip GÜNENÇ', 12345678901, 'info@mryazilim.com.tr', 1, 'b1d820d58a6f1b6ee0482064fafb3c15', 1742555671),
(2, 1, 'admin', 'e3006f680086f379d9d38c18c532f91c', 'Ramazan', 'KONUR', 'Ramazan KONUR', 5383921155, 'ramazan.konur@mryazilim.com.tr', 2, '1630de9df0956c30053a52bd580b4847', 1743968429);

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `sayfalar`
--

DROP TABLE IF EXISTS `sayfalar`;
CREATE TABLE IF NOT EXISTS `sayfalar` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `durum` int(11) DEFAULT 1,
  `ust_sayfa_id` int(11) NOT NULL DEFAULT 0,
  `aktif` int(11) DEFAULT 1,
  `ana_menu` int(11) DEFAULT 1,
  `isim` varchar(250) DEFAULT NULL,
  `sef_isim` varchar(250) DEFAULT NULL,
  `title` text DEFAULT NULL,
  `klasor_yolu` text DEFAULT NULL,
  `ikon` varchar(250) DEFAULT NULL,
  `sira` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

--
-- Tablo döküm verisi `sayfalar`
--

INSERT INTO `sayfalar` (`id`, `durum`, `ust_sayfa_id`, `aktif`, `ana_menu`, `isim`, `sef_isim`, `title`, `klasor_yolu`, `ikon`, `sira`) VALUES
(1, 1, 0, 1, 2, 'Genel', 'genel', NULL, NULL, NULL, 0),
(2, 1, 0, 1, 2, 'Temel', 'temel', NULL, NULL, NULL, 0),
(3, 1, 0, 1, 2, 'Siparişler', 'siparis', NULL, NULL, NULL, 0),
(4, 1, 0, 1, 2, 'Stok ve Depo', 'stok-ve-depo', NULL, NULL, NULL, 0),
(5, 1, 0, 1, 2, 'Entegrasyonlar', 'entegrasyonlar', NULL, NULL, NULL, 0),
(6, 1, 0, 1, 2, 'Görevler', 'gorevler', NULL, NULL, NULL, 0),
(7, 1, 0, 1, 2, 'Raporlama', 'raporlama', NULL, NULL, NULL, 0),
(8, 1, 0, 1, 2, 'Destek ve Talep', 'destek-ve-talep', NULL, NULL, NULL, 0),
(9, 1, 0, 1, 2, 'Ayarlar', 'ayarlar', NULL, NULL, NULL, 0),
(10, 1, 1, 1, 1, 'Kontrol Paneli', 'kontrol-paneli', NULL, 'kontrol-paneli.php', 'bx bx-desktop', 0),
(11, 1, 2, 1, 1, 'Kullanici Yönetimi', 'kullanici-yonetimi', NULL, 'temel/kullanici-yonetimi.php', 'bx bx-user', 0),
(12, 1, 2, 1, 1, 'Firma Yönetimi', 'firma-yonetimi', NULL, 'temel/firma-yonetimi.php', 'bx bxs-buildings', 0),
(13, 1, 3, 1, 1, 'Sipariş Yönetimi', 'siparis-yonetimi', NULL, 'siparisler/siparis-yonetimi.php', 'las la-shopping-basket', 0),
(14, 1, 4, 1, 1, 'Stok ve Depo Yönetimi', 'stok-ve-depo-yonetimi', NULL, 'stok-depo/stok-ve-depo-yonetimi.php', 'las la-boxes', 0),
(15, 1, 5, 1, 1, 'Pazaryeri', 'pazaryeri', NULL, 'entegrasyon/pazaryeri.php', 'las la-store-alt', 0),
(16, 1, 5, 1, 1, 'Kargo', 'kargo', NULL, 'entegrasyon/kargo.php', 'las la-truck-moving', 0),
(17, 1, 5, 1, 1, 'Muhasebe', 'muhasebe', NULL, 'entegrasyon/muhasebe.php', 'las la-wallet', 0),
(18, 1, 6, 1, 1, 'Görevler ve Süreçler', 'gorevler-ve-surecler', NULL, 'gorevler-ve-surecler/gorevler-ve-surecler.php', 'las la-tasks', 0),
(19, 1, 7, 1, 1, 'Raporlama ve Analitik', 'raporlama-ve-analitik', NULL, 'raporlamalar/raporlama-ve-analitik.php', 'las la-chart-pie', 0),
(20, 1, 8, 1, 1, 'Destek', 'destek', NULL, 'destek-talep/destek.php', 'las la-comments', 0),
(21, 1, 9, 1, 1, 'Ayarlar', 'ayarlar', NULL, 'ayarlar/ayarlar.php', 'las la-cogs', 0),
(22, 1, 11, 1, 0, 'Yeni Kullanıcı Ekle', 'yeni-kullanici-ekle', NULL, 'kullanici/yeni-kullanici-ekle.php', NULL, 0),
(23, 1, 11, 1, 0, 'Kullanıcı Listesi', 'kullanici-listesi', NULL, 'kullanici/kullanici-listesi.php', NULL, 0),
(24, 1, 11, 1, 0, 'Roller ve Yetkiler', 'roller-ve-yetkiler', NULL, 'kullanici/roller-ve-yetkiler.php', NULL, 0),
(25, 1, 12, 1, 0, 'Firma Bilgileri', 'firma-bilgileri', NULL, 'firma/firma-bilgileri.php', NULL, 0),
(26, 1, 13, 1, 0, 'Sipariş Listesi', 'siparis-listesi', NULL, 'siparisler/siparis-listesi.php', NULL, 0),
(27, 1, 13, 1, 0, 'Sipariş Detayları', 'siparis-detaylari', NULL, 'siparisler/siparis-detaylari.php', NULL, 0),
(28, 1, 13, 1, 0, 'Sipariş Durumlari', 'siparis-durumlari', NULL, 'siparisler/siparis-durumlari.php', NULL, 0);

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `sayfa_yetkilileri`
--

DROP TABLE IF EXISTS `sayfa_yetkilileri`;
CREATE TABLE IF NOT EXISTS `sayfa_yetkilileri` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `durum` int(11) DEFAULT 1,
  `kullanici_id` int(11) DEFAULT NULL,
  `kullanici_kimlik` text DEFAULT NULL,
  `firma_id` int(11) NOT NULL,
  `sayfa_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

--
-- Tablo döküm verisi `sayfa_yetkilileri`
--

INSERT INTO `sayfa_yetkilileri` (`id`, `durum`, `kullanici_id`, `kullanici_kimlik`, `firma_id`, `sayfa_id`) VALUES
(1, 1, 1, 'b1d820d58a6f1b6ee0482064fafb3c15', 1, 1),
(2, 1, 1, 'b1d820d58a6f1b6ee0482064fafb3c15', 1, 2),
(3, 1, 1, 'b1d820d58a6f1b6ee0482064fafb3c15', 1, 3),
(4, 1, 1, 'b1d820d58a6f1b6ee0482064fafb3c15', 1, 4),
(5, 1, 1, 'b1d820d58a6f1b6ee0482064fafb3c15', 1, 5),
(6, 1, 1, 'b1d820d58a6f1b6ee0482064fafb3c15', 1, 6),
(7, 1, 1, 'b1d820d58a6f1b6ee0482064fafb3c15', 1, 7),
(8, 1, 1, 'b1d820d58a6f1b6ee0482064fafb3c15', 1, 8),
(9, 1, 1, 'b1d820d58a6f1b6ee0482064fafb3c15', 1, 9),
(10, 1, 1, 'b1d820d58a6f1b6ee0482064fafb3c15', 1, 10),
(11, 1, 1, 'b1d820d58a6f1b6ee0482064fafb3c15', 1, 11),
(12, 1, 1, 'b1d820d58a6f1b6ee0482064fafb3c15', 1, 12),
(13, 1, 1, 'b1d820d58a6f1b6ee0482064fafb3c15', 1, 13),
(14, 1, 1, 'b1d820d58a6f1b6ee0482064fafb3c15', 1, 14),
(15, 1, 1, 'b1d820d58a6f1b6ee0482064fafb3c15', 1, 15),
(16, 1, 1, 'b1d820d58a6f1b6ee0482064fafb3c15', 1, 16),
(17, 1, 1, 'b1d820d58a6f1b6ee0482064fafb3c15', 1, 17),
(18, 1, 1, 'b1d820d58a6f1b6ee0482064fafb3c15', 1, 18),
(19, 1, 1, 'b1d820d58a6f1b6ee0482064fafb3c15', 1, 19),
(20, 1, 1, 'b1d820d58a6f1b6ee0482064fafb3c15', 1, 20),
(21, 1, 1, 'b1d820d58a6f1b6ee0482064fafb3c15', 1, 21),
(22, 1, 1, 'b1d820d58a6f1b6ee0482064fafb3c15', 1, 22),
(23, 1, 1, 'b1d820d58a6f1b6ee0482064fafb3c15', 1, 23),
(24, 1, 1, 'b1d820d58a6f1b6ee0482064fafb3c15', 1, 24),
(25, 1, 1, 'b1d820d58a6f1b6ee0482064fafb3c15', 1, 25),
(26, 1, 1, 'b1d820d58a6f1b6ee0482064fafb3c15', 1, 26),
(27, 1, 1, 'b1d820d58a6f1b6ee0482064fafb3c15', 1, 27),
(28, 1, 1, 'b1d820d58a6f1b6ee0482064fafb3c15', 1, 28);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
