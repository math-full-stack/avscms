<?php
defined('_VALID') or die('Restricted Access!');

/**
 * CategoryResolver — resolve a categoria externa do site-fonte em um CHID
 * de FOLHA da árvore `channel` (o mesmo papel que o MetadataMerger tem para tags).
 *
 * Cascata (parada no primeiro que devolver folha):
 *   1. mapping manual: grabber_category_mappings (source_id + external_category)
 *   2. auto-match: tagKey() do candidato vs nome/slug de canais folha
 *   3. grabber_sources.category_id (categoria fixa da fonte)
 *   4. grabber_sources.fallback_category_id (folha de reserva configurada no admin)
 *   5. FALLBACK_CHID (Caseiro) — neutra, configurada por fonte nos passos 3/4
 *
 * GUARDA: nenhum passo pode devolver parent_id = 0 (categoria PAI) — é isso que
 * enchia o "Amador" antes. Se tudo falhar devolve [0, 'none'] e o chamador decide.
 *
 * Candidato múltiplo: o KVS e alguns scrapers devolvem "Cat1, Cat2" — cada
 * candidato é testado na ordem, mapping e auto-match por candidato.
 */
class CategoryResolver {

    /** Caseiro (CHID 39, folha) — neutra, usada quando a fonte não tem fallback. */
    const FALLBACK_CHID = 39;

    /** Folhas carregadas uma vez por request. */
    private static $leaves = null;

    /** Conjuntos de tokens por folha (matchKey do nome e do slug), p/ matchSubset. */
    private static $leafSets = null;

    /**
     * @param array  $source  linha de grabber_sources (id/category_id/fallback_category_id)
     * @param string $external external_category pego no discovery/re-fetch
     * @return array [chid(int), origin(string)]  origin: mapping|auto|source|fallback|default|none
     */
    public static function resolve($source, $externalCategory) {
        global $conn;
        $source = is_array($source) ? $source : array();
        $sourceId = intval(isset($source['id']) ? $source['id'] : 0);
        $candidates = self::splitCandidates($externalCategory);

        // 1. Mapping manual (por candidato, na ordem recebida)
        if ($sourceId > 0 && !empty($candidates)) {
            foreach ($candidates as $candidate) {
                $chid = self::lookupMapping($conn, $sourceId, $candidate);
                if ($chid > 0) return array($chid, 'mapping');
            }
        }

        // 2. Auto-match por nome/slug (folhas)
        if (!empty($candidates)) {
            $leaves = self::leaves($conn);
            foreach ($candidates as $candidate) {
                $key = self::matchKey($candidate);
                if ($key === '') continue;
                if (isset($leaves[$key])) return array(intval($leaves[$key]), 'auto');
            }
            // 2b. Subconjunto de tokens com candidato ÚNICO — cobre slugs compostos
            // do site ("sexo-anal" -> Anal, "novinhas" -> Novinhas +18). Ambíguo
            // (2+ folhas) não arrisca: deixa o passo seguinte decidir.
            foreach ($candidates as $candidate) {
                $key = self::matchKey($candidate);
                if ($key === '') continue;
                $chid = self::matchSubset($key, self::$leafSets);
                if ($chid > 0) return array($chid, 'auto');
            }
        }

        // 3. Categoria fixa da fonte — só se for folha
        $chid = intval(isset($source['category_id']) ? $source['category_id'] : 0);
        if ($chid > 0 && self::isLeaf($conn, $chid)) return array($chid, 'source');

        // 4. Folha de reserva configurada por fonte
        $chid = intval(isset($source['fallback_category_id']) ? $source['fallback_category_id'] : 0);
        if ($chid > 0 && self::isLeaf($conn, $chid)) return array($chid, 'fallback');

        // 5. Default do sistema (Caseiro)
        if (self::isLeaf($conn, self::FALLBACK_CHID)) return array(self::FALLBACK_CHID, 'default');

        return array(0, 'none');
    }

    /** True quando o CHID existe e é folha (parent_id > 0) — validação do admin. */
    public static function isLeafCategory($chid) {
        global $conn;
        return self::isLeaf($conn, $chid);
    }

    /** "Cat1, Cat2" / "Cat1; Cat2" / "a | b" → array de candidatos (máx. 8). */
    public static function splitCandidates($externalCategory) {
        $text = strip_tags((string) $externalCategory);
        $parts = preg_split('/[,;|\/]+|\s+e\s+/u', $text);
        $out = array();
        foreach ((array) $parts as $p) {
            $p = trim(preg_replace('/\s+/u', ' ', $p), " \t\"'.-_");
            if ($p === '' || mb_strlen($p) > 120) continue;
            if (in_array(mb_strtolower($p), array('amateur', 'amadores', 'videos', 'porno'))) continue;
            if (in_array($p, $out, true)) continue;
            $out[] = $p;
            if (count($out) >= 8) break;
        }
        return $out;
    }

    /** Chave de comparação: tagKey + plural dobrado em TODAS as palavras. */
    public static function matchKey($value) {
        require_once dirname(__FILE__) . '/MetadataMerger.php';
        $k = MetadataMerger::tagKey($value);
        if ($k === '') return '';
        $words = explode(' ', $k);
        foreach ($words as $i => $w) {
            if (mb_strlen($w) > 3 && substr($w, -1) === 's' && substr($w, -2) !== 'ss') {
                $words[$i] = mb_substr($w, 0, -1);
            }
        }
        return implode(' ', $words);
    }

    /** Mapa matchKey => CHID das folhas (parent_id > 0), cacheado por request. */
    private static function leaves($conn) {
        if (self::$leaves !== null) return self::$leaves;
        self::$leaves = array();
        self::$leafSets = array();
        try {
            $rs = $conn->Execute("SELECT CHID, name, slug FROM channel WHERE parent_id > 0");
        } catch (Exception $e) { $rs = null; } catch (Throwable $e) { $rs = null; }
        if ($rs) {
            while (!$rs->EOF) {
                $chid = intval($rs->fields['CHID']);
                $sets = array();
                foreach (array($rs->fields['name'], $rs->fields['slug']) as $label) {
                    $key = self::matchKey($label);
                    if ($key === '') continue;
                    if (!isset(self::$leaves[$key])) {
                        self::$leaves[$key] = $chid;
                    }
                    $toks = explode(' ', $key);
                    $sets[implode('|', $toks)] = $toks;
                }
                if ($sets) {
                    self::$leafSets[] = array('chid' => $chid, 'sets' => array_values($sets));
                }
                $rs->MoveNext();
            }
        }
        return self::$leaves;
    }

    /**
     * Subconjunto de tokens, candidato ÚNICO: o externo está contido na folha ou
     * a folha no externo ("sexo anal" ⊇ {anal}; "novinha" ⊆ {novinha,18}).
     * 2+ folhas candidatas = ambíguo => 0 (não arrisca — mapeamento manual resolve).
     */
    private static function matchSubset($candidateKey, $leafSets) {
        if (empty($leafSets)) return 0;
        $ctoks = array_values(array_unique(explode(' ', $candidateKey)));
        if (!$ctoks) return 0;
        $hits = array();
        foreach ($leafSets as $entry) {
            foreach ($entry['sets'] as $set) {
                if (self::isSubset($ctoks, $set) || self::isSubset($set, $ctoks)) {
                    $hits[$entry['chid']] = true;
                    break;
                }
            }
        }
        if (count($hits) !== 1) return 0;
        return intval(key($hits));
    }

    /** Todos os tokens de $a existem em $b? */
    private static function isSubset($a, $b) {
        $map = array_flip($b);
        foreach ($a as $tok) {
            if (!isset($map[$tok])) return false;
        }
        return true;
    }

    private static function isLeaf($conn, $chid) {
        $chid = intval($chid);
        if ($chid <= 0) return false;
        try {
            $rs = $conn->Execute("SELECT parent_id FROM channel WHERE CHID = " . $chid . " LIMIT 1");
        } catch (Exception $e) { return false; } catch (Throwable $e) { return false; }
        if (!$rs || $rs->EOF) return false;
        return intval($rs->fields['parent_id']) > 0;
    }

    private static function lookupMapping($conn, $sourceId, $candidate) {
        try {
            $rs = $conn->Execute("SELECT m.chid FROM grabber_category_mappings m
                                  JOIN channel c ON c.CHID = m.chid
                                  WHERE m.source_id = " . intval($sourceId) . "
                                    AND m.enabled = 1 AND c.parent_id > 0
                                    AND m.external_category = " . $conn->qStr($candidate) . "
                                  LIMIT 1");
        } catch (Exception $e) { return 0; } catch (Throwable $e) { return 0; }
        if (!$rs || $rs->EOF) return 0;
        return intval($rs->fields['chid']);
    }
}
