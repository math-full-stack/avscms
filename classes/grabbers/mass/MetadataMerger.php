<?php
defined('_VALID') or die('Restricted Access!');

/**
 * MetadataMerger - Builds the title/description/tags actually stored on `video`
 * from the two places the grabber knows about them.
 *
 * Why this exists: discovery (scrapers + `grabber_discovered_videos`) and the
 * import-time re-fetch (`GrabberInterface::fetchInfo`) parse the same page with
 * different code, so they disagree. Measured on the live DB: every sampled pair
 * differed (discovered "Shorts, Cacheada, Natural" vs stored "masturbação,
 * morena, exibicionismo"). The admin therefore approves one tag set in the
 * Discover tab and the video lands with another one - silently.
 *
 * Rule: discovered is what the admin saw, so it comes first and gets half the
 * slots; the re-fetch fills the rest and stays the only source of technical data
 * (duration, qualities, thumbnail). Tags are lowercased/deduped here because
 * prepare_tags() was never called on the grabber path - the only normalizing
 * path (siteadmin/modules/videos/edit.php) lowercases, so lowercase is already
 * the de-facto canonical form.
 */
class MetadataMerger {

    const MAX_TAGS = 15;
    const DESC_MAX = 400;

    /** Source-name boilerplate that ends up in `video.description` verbatim. */
    private static $boilerplate = array(
        '/\b[\w-]+\.(?:com|net|org|br|tv|cc|vip|xyz|io|app)\b/i',
        '/\b(?:v[íi]deo|videos|cena|clipe)\s+porn[oô](?:\s+(?:gr[áa]tis|de|da|do|em|no|na))+/i',
        '/\bem\s+videos\s+(?:selecionados|escolhidos|do\s+dia)\b/i',
        '/\bass?istir\b.*\bgr[áa]tis\b/i',
        '/\bwatch\b.*\bfree\b/i',
        '/\bveja\s+tamb[ée]m\b/i',
    );

    /**
     * @param array $disc  Discovered row: title, description, tags, duration
     * @param array $fetch fetchInfo() result: title, description, tags, duration
     * @return array title, description, tags (', ' joined), duration,
     *               needs_description (bool), report (array for logging)
     */
    public static function merge($disc, $fetch) {
        $disc = is_array($disc) ? $disc : array();
        $fetch = is_array($fetch) ? $fetch : array();

        $dTitle = isset($disc['title']) ? trim(strip_tags($disc['title'])) : '';
        $fTitle = isset($fetch['title']) ? trim(strip_tags($fetch['title'])) : '';
        $title = $dTitle !== '' ? $dTitle : $fTitle;

        $dDesc = isset($disc['description']) ? $disc['description'] : '';
        $fDesc = isset($fetch['description']) ? $fetch['description'] : '';

        // The re-fetch wins on description when both have one: it parsed the
        // video page, discovery often only had the listing's teaser.
        $descRaw = trim($fDesc) !== '' ? $fDesc : $dDesc;
        $descInfo = self::sanitizeDescription($descRaw, $title);
        $description = $descInfo['text'];

        $tags = self::mergeTags(
            isset($disc['tags']) ? $disc['tags'] : '',
            isset($fetch['tags']) ? $fetch['tags'] : ''
        );

        $duration = intval(isset($fetch['duration']) ? $fetch['duration'] : 0);
        if ($duration <= 0) $duration = intval(isset($disc['duration']) ? $disc['duration'] : 0);

        return array(
            'title'             => $title,
            'description'       => $description,
            'tags'              => $tags['text'],
            'duration'          => $duration,
            'needs_description' => ($description === ''),
            'report'            => array(
                'title_origin'     => $dTitle !== '' ? 'discovered' : ($fTitle !== '' ? 'fetch' : 'none'),
                'desc_origin'      => trim($fDesc) !== '' ? 'fetch' : ($dDesc !== '' ? 'discovered' : 'none'),
                'desc_chars'       => mb_strlen($description),
                'desc_trimmed'     => $descInfo['trimmed'],
                'desc_dropped'     => $descInfo['dropped'],
                'tags_disc'        => $tags['disc_count'],
                'tags_fetch'       => $tags['fetch_count'],
                'tags_kept'        => $tags['kept'],
                'tags_deduped'     => $tags['deduped'],
                'needs_description' => ($description === ''),
            ),
        );
    }

    /**
     * Union of both tag sets, interleaved so each source gets slots, deduped by
     * a folded key (accent-free, plural-folded) and lowercased for storage.
     */
    public static function mergeTags($discTags, $fetchTags) {
        $disc  = self::splitTags($discTags);
        $fetch = self::splitTags($fetchTags);

        $kept = array();
        $seen = array();
        $deduped = 0;
        $max = max(count($disc), count($fetch));
        for ($i = 0; $i < $max && count($kept) < self::MAX_TAGS; $i++) {
            foreach (array($disc, $fetch) as $set) {
                if (count($kept) >= self::MAX_TAGS) break;
                if (!isset($set[$i])) continue;
                $tag = $set[$i];
                $key = self::tagKey($tag);
                if ($key === '' || isset($seen[$key])) { $deduped++; continue; }
                $seen[$key] = true;
                $kept[] = $tag;
            }
        }

        return array(
            'text'        => implode(', ', $kept),
            'kept'        => count($kept),
            'deduped'     => $deduped,
            'disc_count'  => count($disc),
            'fetch_count' => count($fetch),
        );
    }

    /**
     * Split a ', ' separated tag string. Tolerates the space-separated shape
     * xfree_scrape.py emits by treating runs of spaces inside a token as part of
     * it only when the whole value has no comma.
     */
    private static function splitTags($string) {
        $string = strip_tags((string) $string);
        $string = str_replace(array("\r", "\n", "\t"), ' ', $string);
        $parts = (strpos($string, ',') === false && strpos($string, ' ') !== false)
            ? preg_split('/\s+/', $string)
            : explode(',', $string);

        $out = array();
        foreach ($parts as $p) {
            $p = trim(preg_replace('/\s+/u', ' ', $p));
            $p = trim($p, " \t\"'.-_");
            if ($p === '' || mb_strlen($p) < 2) continue;
            // Reject pure noise: "12", "--", "()" carry no taxonomy signal.
            if (!preg_match('/\p{L}/u', $p)) continue;
            $out[] = mb_strtolower($p, 'UTF-8');
        }
        return $out;
    }

    /**
     * Dedup key: lowercase, accent-free, punctuation-free, plural-folded.
     * "Morena", "morenas" and "Morêna" collapse to one entry.
     */
    public static function tagKey($tag) {
        $k = self::accentless($tag);
        $k = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $k);
        $k = trim(preg_replace('/\s+/u', ' ', $k));
        if ($k === '') return '';
        $words = explode(' ', $k);
        $last = count($words) - 1;
        if ($last >= 0 && mb_strlen($words[$last]) > 3 && substr($words[$last], -1) === 's'
            && substr($words[$last], -2) !== 'ss') {
            $words[$last] = mb_substr($words[$last], 0, -1);
            $k = implode(' ', $words);
        }
        return $k;
    }

    /**
     * Lowercase first, then fold accents with an explicit PT-BR table. iconv's
     // //TRANSLIT is not usable as the primary path: on this host it turns "Ê"
     * into "~" (which then becomes a space) and leaves case alone, so "Morena"
     * and "morena" would produce different keys.
     */
    private static function accentless($s) {
        $s = mb_strtolower((string) $s, 'UTF-8');
        $s = strtr($s, array(
            'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','å'=>'a',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
            'ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
            'ç'=>'c','ñ'=>'n','ý'=>'y','ÿ'=>'y','š'=>'s','ž'=>'z',
        ));
        if (preg_match('/[^\x00-\x7F]/', $s) && function_exists('iconv')) {
            $out = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if ($out !== false && $out !== '') $s = $out;
        }
        return $s;
    }

    /**
     * True when a sentence is the title restated. Prefix matching alone is not
     * enough: the sources reorder and decorate the title ("...bucetinha com forca
     * pro cunhado" vs "...bucetinha gostosa pro seu cunhado macetar com forca"),
     * so this compares word sets instead.
     */
    private static function repeatsTitle($sentenceKey, $titleKey) {
        if ($sentenceKey === '' || $titleKey === '') return false;
        if (strpos($sentenceKey, $titleKey) === 0 || strpos($titleKey, $sentenceKey) === 0) return true;

        $sWords = array_filter(explode(' ', $sentenceKey), function ($w) { return mb_strlen($w) > 2; });
        $tWords = array_filter(explode(' ', $titleKey), function ($w) { return mb_strlen($w) > 2; });
        if (count($tWords) < 3 || !$sWords) return false;

        $shared = count(array_intersect($sWords, $tWords));
        return ($shared / count($tWords)) >= 0.8 && ($shared / count($sWords)) >= 0.6;
    }

    /**
     * Strip boilerplate, drop the part that just repeats the title, collapse
     * whitespace and truncate on a sentence boundary.
     *
     * @return array text, trimmed (bool), dropped (int sentences)
     */
    public static function sanitizeDescription($desc, $title = '') {
        $s = (string) $desc;
        $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
        // Replace tags with a space, not nothing: "</p><p>" would otherwise glue
        // two sentences together.
        $s = preg_replace('/<[^>]*>/', ' ', $s);
        $s = strip_tags($s);
        $s = str_replace(array("\xc2\xa0", "\r\n", "\r", "\n", "\t"), ' ', $s);
        $s = trim(preg_replace('/\s+/u', ' ', $s));
        if ($s === '') return array('text' => '', 'trimmed' => false, 'dropped' => 0);

        $sentences = preg_split('/(?<=[.!?])\s+/u', $s);
        $titleKey = self::tagKey($title);

        $out = array();
        $dropped = 0;
        foreach ($sentences as $idx => $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '') { continue; }

            $isBoiler = false;
            foreach (self::$boilerplate as $re) {
                if (preg_match($re, $sentence)) { $isBoiler = true; break; }
            }
            if ($isBoiler) { $dropped++; continue; }

            // Leading sentence that is just the title again (78% of the rows in
            // the live DB) adds nothing to the description or to og:description.
            if ($idx === 0 && mb_strlen($titleKey) > 3
                && self::repeatsTitle(self::tagKey($sentence), $titleKey)) {
                $dropped++;
                continue;
            }

            $out[] = $sentence;
        }

        $text = trim(implode(' ', $out));
        $trimmed = false;
        if (mb_strlen($text) > self::DESC_MAX) {
            $cut = mb_substr($text, 0, self::DESC_MAX);
            $lastStop = mb_strrpos($cut, '. ');
            if ($lastStop === false) $lastStop = mb_strrpos($cut, '.');
            if ($lastStop !== false && $lastStop > self::DESC_MAX / 2) {
                $cut = mb_substr($cut, 0, $lastStop + 1);
            } else {
                $r = mb_strrpos($cut, ' ');
                if ($r !== false) $cut = mb_substr($cut, 0, $r);
                $cut = rtrim($cut, ' ,;:') . '…';
            }
            $text = $cut;
            $trimmed = true;
        }

        return array('text' => $text, 'trimmed' => $trimmed, 'dropped' => $dropped);
    }
}
