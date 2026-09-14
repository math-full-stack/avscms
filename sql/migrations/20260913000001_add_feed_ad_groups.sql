-- 13/09/2026 — Grupos de anúncio para faixas intercaladas no grid ("entre as paginações").
-- index_feed  = home (feed "Para Você"), faixa full-width a cada 8 cards — index.tpl + include/ajax/home_feed.php
-- videos_feed = listagem /videos, faixa full-width a cada 8 cards — videos.tpl
-- Rotação ligada, dimensão fluida (0x0 = Auto). Reaplicável (INSERT ... SELECT WHERE NOT EXISTS).
INSERT INTO `adv_group` (`advgrp_id`, `advgrp_name`, `total_advs`, `advgrp_rotate`, `advgrp_status`, `adv_width`, `adv_height`)
SELECT 19, 'index_feed', 0, '1', '1', 0, 0
WHERE NOT EXISTS (SELECT 1 FROM `adv_group` WHERE `advgrp_name` = 'index_feed');
INSERT INTO `adv_group` (`advgrp_id`, `advgrp_name`, `total_advs`, `advgrp_rotate`, `advgrp_status`, `adv_width`, `adv_height`)
SELECT 20, 'videos_feed', 0, '1', '1', 0, 0
WHERE NOT EXISTS (SELECT 1 FROM `adv_group` WHERE `advgrp_name` = 'videos_feed');