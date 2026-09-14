-- 15/09/2026 — Define tamanhos padrão para todas as zonas de anúncios.
-- Lateralias *_right: 300x250 (Medium Rectangle padrão).
-- index_right: 300x300 (quadrado, já existente).
-- Rodapés *_bottom: 728x90 (Leaderboard padrão).
-- Feed zones (index_feed, videos_feed, shorts_feed): 0x0 (fluido, controlado pelo CSS).

UPDATE adv_group SET adv_width = 300, adv_height = 250
WHERE advgrp_name IN ('albums_right', 'blogs_right', 'categories_right', 'photo_right', 'video_right', 'videos_right');

UPDATE adv_group SET adv_width = 300, adv_height = 300
WHERE advgrp_name = 'index_right';

UPDATE adv_group SET adv_width = 728, adv_height = 90
WHERE advgrp_name IN ('albums_bottom', 'blog_bottom', 'blogs_bottom', 'categories_bottom', 'community_bottom', 'index_bottom', 'photo_bottom', 'users_bottom', 'video_player_bottom', 'video_bottom', 'videos_bottom');

-- Feeds mantêm dimensão fluida (0x0 = Auto), tamanho controlado por CSS (.xb-feed-ad).
UPDATE adv_group SET adv_width = 0, adv_height = 0
WHERE advgrp_name IN ('index_feed', 'videos_feed', 'shorts_feed');
