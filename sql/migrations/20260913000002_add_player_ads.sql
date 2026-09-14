-- 13/09/2026 — Anúncios inteligentes do player (tabela adv_player).
-- Seleção server-side via player_ads_schedule() (function_player_ads.php).
-- 5 tipos: preroll, midroll, pause, overlay, postroll.
-- Criativos: image, html, video (URL direta), vast (tag URL no campo code).
-- device: dm=desktop+mobile, d=desktop, m=mobile.
-- categories: '-' ou vazio=todas; senão "-CHID-CHID-".
-- schedule: CSV de % para midroll ('25,50,75'), segundos para overlay ('30').
-- cap_per_hour: 0=ilimitado; senão cap por IP/sessão via localStorage.
-- fake_progress: '1'=barra de progresso visual para image/html/video.
-- Reaplicável (CREATE TABLE IF NOT EXISTS + INSERT ... WHERE NOT EXISTS).

CREATE TABLE IF NOT EXISTS `adv_player` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL DEFAULT '',
  `type` enum('preroll','midroll','pause','overlay','postroll') NOT NULL DEFAULT 'preroll',
  `grp` varchar(20) NOT NULL DEFAULT '',
  `device` enum('dm','d','m') NOT NULL DEFAULT 'dm',
  `categories` text NOT NULL,
  `creative` enum('image','html','video','vast') NOT NULL DEFAULT 'image',
  `media_url` text NOT NULL,
  `code` text NOT NULL,
  `duration` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `fake_progress` enum('0','1') NOT NULL DEFAULT '1',
  `skip_after` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `schedule` varchar(255) NOT NULL DEFAULT '25,50,75',
  `cap_per_hour` int(11) UNSIGNED NOT NULL DEFAULT 3,
  `position` enum('bottom-right','bottom-left','top-right','top-left','center') NOT NULL DEFAULT 'bottom-right',
  `status` enum('0','1') NOT NULL DEFAULT '1',
  `views` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `clicks` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `adv_addtime` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `type` (`type`),
  KEY `status` (`status`),
  KEY `device` (`device`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Grupos de anúncio por tipo (adv_group).
-- admin filtra por advgrp_name LIKE 'player\_%'.
INSERT INTO `adv_group` (`advgrp_id`, `advgrp_name`, `total_advs`, `advgrp_rotate`, `advgrp_status`, `adv_width`, `adv_height`)
SELECT 22, 'player_preroll', 0, '1', '1', 0, 0
WHERE NOT EXISTS (SELECT 1 FROM `adv_group` WHERE `advgrp_name` = 'player_preroll');

INSERT INTO `adv_group` (`advgrp_id`, `advgrp_name`, `total_advs`, `advgrp_rotate`, `advgrp_status`, `adv_width`, `adv_height`)
SELECT 23, 'player_midroll', 0, '1', '1', 0, 0
WHERE NOT EXISTS (SELECT 1 FROM `adv_group` WHERE `advgrp_name` = 'player_midroll');

INSERT INTO `adv_group` (`advgrp_id`, `advgrp_name`, `total_advs`, `advgrp_rotate`, `advgrp_status`, `adv_width`, `adv_height`)
SELECT 24, 'player_pause', 0, '1', '1', 0, 0
WHERE NOT EXISTS (SELECT 1 FROM `adv_group` WHERE `advgrp_name` = 'player_pause');

INSERT INTO `adv_group` (`advgrp_id`, `advgrp_name`, `total_advs`, `advgrp_rotate`, `advgrp_status`, `adv_width`, `adv_height`)
SELECT 25, 'player_overlay', 0, '1', '1', 0, 0
WHERE NOT EXISTS (SELECT 1 FROM `adv_group` WHERE `advgrp_name` = 'player_overlay');

INSERT INTO `adv_group` (`advgrp_id`, `advgrp_name`, `total_advs`, `advgrp_rotate`, `advgrp_status`, `adv_width`, `adv_height`)
SELECT 26, 'player_postroll', 0, '1', '1', 0, 0
WHERE NOT EXISTS (SELECT 1 FROM `adv_group` WHERE `advgrp_name` = 'player_postroll');
