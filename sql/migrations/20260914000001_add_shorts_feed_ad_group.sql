-- 14/09/2026 — Grupo de anúncio para o feed de Shorts.
-- shorts_feed = card híbrido full-screen (banner + thumbnail do próximo short),
--               intercalado em posição ALEATÓRIA no feed — shorts.php + include/ajax/shorts_feed.php
-- Rotação ligada, dimensão fluida (0x0 = Auto). Reaplicável (INSERT ... SELECT WHERE NOT EXISTS).
INSERT INTO `adv_group` (`advgrp_id`, `advgrp_name`, `total_advs`, `advgrp_rotate`, `advgrp_status`, `adv_width`, `adv_height`)
SELECT 21, 'shorts_feed', 0, '1', '1', 0, 0
WHERE NOT EXISTS (SELECT 1 FROM `adv_group` WHERE `advgrp_name` = 'shorts_feed');