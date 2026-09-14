<?php
/**
 * Anúncios inteligentes do player mediabunny (tabela adv_player).
 *
 * Fonte única da seleção de anúncios do player — substitui a duplicação dos
 * blocos de ads em video.php / view.php / embed.php / siteadmin/view.php.
 * O player nunca vê a tabela: recebe `player_ads_json` (JSON seguro p/ script
 * inline) e reporta views/clicks por ajax (prepared statements).
 *
 * Regras:
 *   - kill switch: $config['player_ads'] == '0' desliga tudo.
 *   - midroll/postroll são cortados quando o vídeo é mais curto que
 *     player_ads_min_duration.
 *   - device: coluna é 'dm'|'d'|'m'; LIKE '%d%' casa dm e d (padrão legado).
 *   - categories: '-' ou vazio = todas; senão "-CHID-CHID-".
 */
if (!defined('_VALID')) {
    die('Restricted Access!');
}

if (!function_exists('player_ads_schedule')) {
    /**
     * Seleciona no máximo 1 anúncio por tipo (preroll, midroll, pause,
     * overlay, postroll) para a requisição atual. Retorna array numérico.
     */
    function player_ads_schedule($category, $device = 'd', $duration = 0)
    {
        global $conn, $config;

        if (!isset($config['player_ads']) || $config['player_ads'] == '0') {
            return array();
        }

        $device       = ($device == 'm') ? 'm' : 'd';
        $min_duration = (isset($config['player_ads_min_duration'])) ? max(0, intval($config['player_ads_min_duration'])) : 60;
        $category     = trim((string)$category);

        $schedule = array();
        $types    = array('preroll', 'midroll', 'pause', 'overlay', 'postroll');
        foreach ($types as $type) {
            if (($type == 'midroll' || $type == 'postroll')
                && $duration > 0 && $duration < $min_duration) {
                continue;
            }
            $like  = ($category == '') ? '%' : '%-' . intval($category) . '-%';
            $sql   = "SELECT * FROM adv_player
                      WHERE `type` = " . $conn->qStr($type) . "
                        AND `status` = '1'
                        AND device LIKE '%" . $device . "%'
                        AND (categories = '' OR categories = '-' OR categories LIKE " . $conn->qStr($like) . ")
                      ORDER BY RAND()
                      LIMIT 1";
            $rs    = $conn->execute($sql);
            if ($rs && $conn->Affected_Rows() == 1) {
                $schedule[] = $rs->fields;
            }
        }
        return $schedule;
    }

    /**
     * Calcula a agenda e a expõe ao template do player. Usada por
     * video.php / view.php / embed.php / siteadmin/view.php.
     */
    function player_ads_assign($category, $device = 'd', $duration = 0)
    {
        global $smarty, $config;

        $schedule = player_ads_schedule($category, $device, $duration);
        $json     = json_encode($schedule);
        if ($json === false) {
            // Código do admin com utf-8 inválido: joga fora em vez de quebrar o JS.
            $json = '[]';
        }
        // HTML ad pode conter "</script>" — escapar fecha-tag p/ não encerrar o
        // script inline do player_settings.tpl (json_encode não faz isso).
        $json = str_replace('</', '<\\/', $json);

        $smarty->assign('player_ads_json', $json);
        $smarty->assign('player_ads_per_hour', (isset($config['player_ads_per_hour'])) ? intval($config['player_ads_per_hour']) : 8);
        $smarty->assign('player_ads_min_duration', (isset($config['player_ads_min_duration'])) ? intval($config['player_ads_min_duration']) : 60);
    }

    /**
     * Incrementa a métrica de views/clicks de um anúncio. Prepared statement
     * (o id chega por query string e nunca é concatenado).
     */
    function player_ads_track($aid, $metric)
    {
        global $conn;

        $aid = intval($aid);
        if ($aid < 1) {
            return 0;
        }
        $col = ($metric == 'click') ? 'clicks' : 'views';
        $sql = "UPDATE adv_player SET `" . $col . "` = `" . $col . "` + 1 WHERE id = ?";
        $ok  = (bool)$conn->execute($sql, array($aid));
        return ($conn->Affected_Rows() > 0 && $ok) ? 1 : 0;
    }
}