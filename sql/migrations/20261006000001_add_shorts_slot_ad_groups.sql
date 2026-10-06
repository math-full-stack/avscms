-- 06/10/2026 — Grupos de anúncio dos SLOTS individuais do feed de Shorts.
-- Antes, os companheiros laterais e a faixa inferior do card de vídeo usavam a
-- mesma peça do grupo 'shorts_feed' (o do card full-screen intercalado). Agora
-- cada slot tem o próprio grupo, resolvido uma vez por request em shorts.php e
-- include/ajax/shorts_feed.php:
--   shorts_left   = companheiro lateral ESQUERDO do card de vídeo (desktop)
--   shorts_right  = companheiro lateral DIREITO do card de vídeo (desktop)
--   shorts_bottom = faixa inferior do card de vídeo (no lugar do título/descrição)
-- Dimensão fluida (0x0 = Auto), controlada pelo CSS (avs-shorts.css).
-- Reaplicável (INSERT ... SELECT WHERE NOT EXISTS).
INSERT INTO `adv_group` (`advgrp_id`, `advgrp_name`, `total_advs`, `advgrp_rotate`, `advgrp_status`, `adv_width`, `adv_height`)
SELECT 23, 'shorts_left', 0, '1', '1', 0, 0
WHERE NOT EXISTS (SELECT 1 FROM `adv_group` WHERE `advgrp_name` = 'shorts_left');
INSERT INTO `adv_group` (`advgrp_id`, `advgrp_name`, `total_advs`, `advgrp_rotate`, `advgrp_status`, `adv_width`, `adv_height`)
SELECT 24, 'shorts_right', 0, '1', '1', 0, 0
WHERE NOT EXISTS (SELECT 1 FROM `adv_group` WHERE `advgrp_name` = 'shorts_right');
INSERT INTO `adv_group` (`advgrp_id`, `advgrp_name`, `total_advs`, `advgrp_rotate`, `advgrp_status`, `adv_width`, `adv_height`)
SELECT 25, 'shorts_bottom', 0, '1', '1', 0, 0
WHERE NOT EXISTS (SELECT 1 FROM `adv_group` WHERE `advgrp_name` = 'shorts_bottom');
