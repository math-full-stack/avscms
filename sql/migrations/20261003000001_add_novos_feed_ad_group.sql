-- novos_feed = seção "Novos Vídeos" na home, faixa full-width a cada 8 cards — index.tpl
INSERT INTO `adv_group` (`advgrp_id`, `advgrp_name`, `total_advs`, `advgrp_rotate`, `advgrp_status`, `adv_width`, `adv_height`)
SELECT 22, 'novos_feed', 0, '1', '1', 0, 0
WHERE NOT EXISTS (SELECT 1 FROM `adv_group` WHERE `advgrp_name` = 'novos_feed');