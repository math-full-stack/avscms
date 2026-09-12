<?php
defined('_VALID') or die('Restricted Access!');

// Guard de carregamento único: config.php dá require_once deste arquivo para
// aplicar a rotação de capas antes dos entrypoints, que depois fazem um
// `require` normal (não-once) do mesmo arquivo. Declarações de função no topo
// sofrem early binding (o PHP as registra ao compilar, antes de qualquer
// `return`), então o guard precisa envolver as declarações em um `if` — sem
// isso o segundo carregamento fataliza com "Cannot redeclare".
if ( !defined('AVS_FUNCTION_GLOBAL_LOADED') ) {
	define('AVS_FUNCTION_GLOBAL_LOADED', true);

function get_request()
{
    $request = ( isset($_SERVER['REQUEST_URI']) ) ? $_SERVER['REQUEST_URI'] : NULL;
    $request = ( isset($_SERVER['QUERY_STRING']) ) ? str_replace('?' .$_SERVER['QUERY_STRING'], '', $request) : $request;
	$request = urldecode($request);
    return ( isset($request) ) ? explode('/', $request) : array();
}

function get_request_arg($search, $type = 'INT')
{
    $arg    = NULL;
    $query  = get_request();
    foreach ($query as $key => $value) {
        if ( $value == $search ) {
            if ( isset($query[$key+1]) ) {
                $arg = $query[$key+1];
            }
        }
    }

    return ( $type == 'INT' ) ? intval($arg) : $arg;
}

/**
 * Rotação de capas: dado um vídeo, devolve o frame a mostrar como capa.
 *
 * Se o vídeo tem cover(s) escolhidos em `thumbnails_opt` (lista separada por
 * vírgula, frames 1..thumbs), escolhe um deles de forma "aleatória mas estável
 * por request" — ou seja, a cada página carregada o card pode exibir uma capa
 * diferente entre as selecionadas. Sem lista, mantém o comportamento legado
 * (usa sempre `thumb`).
 *
 * @param array $video Linha da tabela video (precisa de VID, thumb, thumbs, thumbnails_opt)
 * @return int Índice do frame a exibir
 */
function video_rotate_cover($video)
{
    static $rot_seed = null;
    if ($rot_seed === null) {
        $rot_seed = mt_rand(0, 100000);
    }

    $thumb = (int)$video['thumb'];
    $max   = ( isset($video['thumbs']) && (int)$video['thumbs'] > 0 ) ? (int)$video['thumbs'] : 20;
    $opt   = ( isset($video['thumbnails_opt']) ) ? trim($video['thumbnails_opt']) : '';

    if ($opt === '') {
        return $thumb;
    }

    $covers = array_values(array_unique(array_map('intval', explode(',', $opt))));
    $covers = array_values(array_filter($covers, function ($c) use ($max) {
        return $c >= 1 && $c <= $max;
    }));

    if ( count($covers) === 0 ) {
        return $thumb;
    }

    $idx = ($rot_seed + (int)$video['VID']) % count($covers);
    return (int)$covers[$idx];
}

/**
 * Aplica video_rotate_cover() a uma lista de vídeos (arrays associativos),
 * sobrescrevendo `thumb` com a capa sorteada para o request corrente.
 *
 * @param array|null $videos Lista de linhas de video (referência)
 */
function video_apply_cover_rotation(&$videos)
{
    if ( !is_array($videos) ) {
        return;
    }
    foreach ( $videos as $k => $v ) {
        if ( is_array($v) && isset($v['thumb']) && isset($v['VID']) ) {
            $videos[$k]['thumb'] = video_rotate_cover($v);
        }
    }
}

/**
 * Lista de capas para ciclagem (trio vertical + hover).
 *
 * Usa as capas escolhidas pelo admin (`thumbnails_opt`) quando há 3+ válidas;
 * senão completa com a sequência de frames a partir da capa atual. Mesma
 * sanitização de video_rotate_cover().
 *
 * @param array $video Linha da tabela video (VID, thumb, thumbs, thumbnails_opt)
 * @return int[] Frames 1..thumbs em ordem de ciclagem
 */
function video_cover_list($video)
{
    $max = ( isset($video['thumbs']) && (int)$video['thumbs'] > 0 ) ? (int)$video['thumbs'] : 20;
    $opt = ( isset($video['thumbnails_opt']) ) ? trim((string)$video['thumbnails_opt']) : '';

    $covers = array();
    if ( $opt !== '' ) {
        foreach ( explode(',', $opt) as $c ) {
            $c = (int)$c;
            if ( $c >= 1 && $c <= $max && !in_array($c, $covers, true) ) {
                $covers[] = $c;
            }
        }
    }

    if ( count($covers) >= 3 ) {
        return $covers;
    }

    $start = ( isset($video['thumb']) ) ? (int)$video['thumb'] : 1;
    if ( $start < 1 || $start > $max ) {
        $start = 1;
    }
    $covers = array();
    for ( $i = 0; $i < $max; $i++ ) {
        $covers[] = (($start - 1 + $i) % $max) + 1;
    }
    return $covers;
}

/**
 * Trio inicial do card vertical: 3 capas distintas da lista, com offset
 * estável por request (mesmo esquema de video_rotate_cover).
 *
 * @param array $video Linha da tabela video
 * @return array array($trio, $idx, $covers) — 3 frames, índice inicial e lista
 */
function video_cover_trio($video)
{
    $covers = video_cover_list($video);
    $n      = count($covers);

    $vid = ( isset($video['VID']) ) ? (int)$video['VID'] : 0;

    if ( $n <= 3 ) {
        $trio = $covers;
        return array($trio, 0, $covers);
    }

    mt_srand($vid ^ (int)microtime(true));
    $indices = range(0, $n - 1);
    for ( $i = $n - 1; $i > 0; $i-- ) {
        $j = mt_rand(0, $i);
        $tmp = $indices[$i];
        $indices[$i] = $indices[$j];
        $indices[$j] = $tmp;
    }

    $trio = array($covers[$indices[0]], $covers[$indices[1]], $covers[$indices[2]]);
    return array($trio, 0, $covers);
}

/**
 * HTML do trio vertical: 3 capas lado a lado (CSS faz a divisória preta de
 * 2px via gap). data-* alimenta o avanço no hover (jquery.rotator.js).
 */
function video_trio_html($vid, $thumb, $thumbs, $opt, $title, $type = 'public')
{
    global $config;

    require_once $config['BASE_DIR']. '/include/function_thumbs.php';

    $video = array('VID' => $vid, 'thumb' => $thumb, 'thumbs' => $thumbs, 'thumbnails_opt' => $opt);
    list($trio, $idx, $covers) = video_cover_trio($video);

    $base = get_video_thumb_base((int)$vid);
    $esc  = htmlspecialchars((string)$title, ENT_QUOTES, 'UTF-8');
    $cls  = ( $type === 'private' ) ? 'img-responsive img-private' : 'img-responsive';

    $html = '<div class="xb-trio" data-vid="'.(int)$vid.'" data-idx="'.(int)$idx.'" data-covers="'.implode(',', $covers).'" data-thumbs="'.(int)$thumbs.'">';
    foreach ( $trio as $f ) {
        $html .= '<img src="'.$base.'/'.(int)$f.'.jpg" title="'.$esc.'" alt="'.$esc.'" class="'.$cls.'" loading="lazy"/>';
    }
    return $html.'</div>';
}

function get_categories()
{
    global $conn;
    
    $sql        = "SELECT CHID, name, slug FROM channel ORDER BY name ASC";
    $rs         = $conn->execute($sql);
    $categories = $rs->getrows();
    
    return $categories;
}

function get_albums_categories()
{
    global $conn;
    
    $sql        = "SELECT CID, name, slug FROM album_categories ORDER BY name ASC";
    $rs         = $conn->execute($sql);
    $categories = $rs->getrows();
    
    return $categories;
}

function getCategoryCoverUrl($type, $id)
{
    global $config, $conn;

    if ($type === 'video') {
        $imgPath = $config['BASE_DIR'] . '/media/categories/video/' . intval($id) . '.jpg';
        if (file_exists($imgPath) && is_file($imgPath)) {
            return $config['BASE_URL'] . '/media/categories/video/' . intval($id) . '.jpg';
        }
        $sql = "SELECT VID FROM video WHERE channel = " . intval($id) . " AND active = '1' ORDER BY RAND() DESC LIMIT 1";
        $rs = $conn->execute($sql);
        if ($rs && $conn->Affected_Rows() > 0) {
            $vid = intval($rs->fields['VID']);
            $index = intval(($vid - 1) / $config['max_thumb_folders']);
            $tmb_folder = 'tmb';
            if ($index !== 0) {
                $tmb_folder = 'tmb' . $index;
            }
            return $config['BASE_URL'] . '/media/videos/' . $tmb_folder . '/' . $vid . '/default.jpg';
        }
        return $config['BASE_URL'] . '/media/categories/default.jpg';
    }

    if ($type === 'album') {
        $imgPath = $config['BASE_DIR'] . '/media/categories/album/' . intval($id) . '.jpg';
        if (file_exists($imgPath) && is_file($imgPath)) {
            return $config['BASE_URL'] . '/media/categories/album/' . intval($id) . '.jpg';
        }
        return $config['BASE_URL'] . '/media/categories/default.jpg';
    }

    return $config['BASE_URL'] . '/media/categories/default.jpg';
}

function get_popular_tags()
{
    global $conn;
    
    $tags       = array();
    $sql        = "SELECT keyword FROM video ORDER BY viewnumber LIMIT 10";
    $rs         = $conn->execute($sql);
    $rows       = $rs->getrows();
    foreach ( $rows as $row ) {
        $tag_arr = explode(' ', $row['keyword']);
        foreach ( $tag_arr as $tag ) {
            if ( strlen($tag) > 3 && !in_array($tag, $tags) ) {
                $tags[] = $tag;
            }
        }
    }    
}


function prepare_string( $string, $url=true )
{
	if (preg_match('/^.$/u', 'ñ')) {
  		$string = preg_replace('/[^\pL\pN\pZ]/u', ' ', $string);
  		$string = preg_replace('/\s\s+/', ' ', $string);
	} else {
		$string = preg_replace('/[^ 0-9a-zA-Z]/', ' ', $string);
  		$string = preg_replace('/\s\s+/', ' ', $string);
	}
    $string = trim($string);
    if ( $url === true ) {
        $string = str_replace(' ', '-', $string);
        $string = mb_strtolower($string);
    }
    
    return $string;
}

function check_string($string)
{
	if (preg_match('/^.$/u', 'ñ')) {
		return (bool) preg_match('/^[-\pL\pN_]++$/uD', $string);
	} else {
		return (bool) preg_match('/^[a-zA-Z0-9_\-\s]+$/', $string);
	}
}

function truncate( $string, $length=80)
{
    if ( $length == 0 ) {
        return '';
    }

    if (mb_strlen($string) > $length) {
        $etc     = ' ...';
        $length -= min($length, mb_strlen($etc));
        return mb_substr($string, 0, $length) . $etc;
    } else {
        return $string;
    }
}   

function duration( $duration)
{
    $duration_formated  = NULL;
    $duration           = round($duration);
    if ( $duration > 3600 ) {
        $hours              = floor($duration/3600);
        $duration_formated .= sprintf('%02d',$hours). ':';
        $duration           = round($duration-($hours*3600));
    }
    if ( $duration > 60 ) {
        $minutes            = floor($duration/60);
        $duration_formated .= sprintf('%02d', $minutes). ':';
        $duration           = round($duration-($minutes*60));
    } else {
        $duration_formated .= '00:';
    }
    
    return $duration_formated . sprintf('%02d', $duration);
}

function time_range( $time )
{	
	global $lang;
	
    $range          = NULL;
    $current_time   = time();
    $interval       = $current_time-$time;
	if ( $interval > 0 ) {
        $year    = $interval/(12*30.416*60*60*24);
        if ( $year >= 1 ) {
			if ($year < 2) {
				$range      = floor($year).' '.$lang['global.year'];				
			} else {
				$range      = floor($year).' '.$lang['global.years'];
			}
            $interval   = $interval-(12*30.416*60*60*24*floor($year));            
        }			        
        if ( $interval > 0 && $range == '' ) {
			$month    = $interval/(30.416*60*60*24);
			if ( $month >=1 ) {			
				if ($month < 2) {
					$range      = floor($month).' '.$lang['global.month'];				
				} else {
					$range      = floor($month).' '.$lang['global.months'];
				}
				$interval   = $interval-(30.416*60*60*24*floor($month));            
			}
        }		
        if ( $interval > 0 && $range == '' ) {
			$day    = $interval/(60*60*24);	
			if ( $day >=1 ) {
				if ($day < 2) {
					$range      = floor($day).' '.$lang['global.day'];				
				} else {
					$range      = floor($day).' '.$lang['global.days'];
				}
				$interval   = $interval-(60*60*24*floor($day));            
			}
        }
        if( $interval > 0 && $range == '' ) {
            $hour       = $interval/(60*60);
            if ( $hour >=1 ) {
				if ($hour < 2) {
					$range      = floor($hour). ' ' .$lang['global.hour'];					
				} else {
					$range      = floor($hour). ' ' .$lang['global.hours'];
				}
                $interval   = $interval-(60*60*floor($hour));
            }            
        }
        if ( $interval > 0 && $range == '' ) {
            $min        = $interval/(60);
            if ( $min >= 1 ) {
				if ($min < 2) {				
					$range=floor($min). ' '.$lang['global.minute'];
				} else {
					$range=floor($min). ' '.$lang['global.minutes'];					
				}
                $interval=$interval-(60*floor($min));
            }
        }
        if ( $interval > 0 && $range == '' ) {
            $scn        = $interval;
            if ( $scn < 2 ) {
				if ($min == 1) {				
					$range  = $scn. ' '.$lang['global.second'];
				} else {
					$range  = $scn. ' '.$lang['global.seconds'];					
				}
            }
        }
        
        return ( $range != '' ) ? $range. ' '.$lang['global.ago'] : $lang['global.just_now'];
    }
}

function video_rating_small( $rate )
{
    $class_1    = '';
    $class_2    = '';
    $class_3    = '';
    $class_4    = '';
    $class_5    = '';
    if ( $rate > 0.5 ) {
        $class_1 = ' class="half"';
        if ( $rate >= 1 ) {
            $class_1 = ' class="full"';
        }
        if ( $rate >= 2 ) {
            $class_2 = ' class="full"';
        } elseif ( $rate >= 1.5 ) {
            $class_2 = ' class="half"';
        }
        if ( $rate >= 3 ) {
            $class_3 = ' class="full"';
        } elseif ( $rate >= 2.5 ) {
            $class_3 = ' class="half"';
        }
        if ( $rate >= 4 ) {
            $class_4 = ' class="full"';
        } elseif ( $rate >= 3.5 ) {
            $class_4 = ' class="half"';
        }
        if ( $rate >= 5 ) {
            $class_5 = ' class="full"';
        } elseif ( $rate >= 4.5 ) {
            $class_5 = ' class="half"';
        }
    }
    
    $output     = array();
    $output[]   = '<ul class="rating_small">';
    $output[]   = '<li><span' .$class_5. '>&nbsp;</span></li>';
    $output[]   = '<li><span' .$class_4. '>&nbsp;</span></li>';
    $output[]   = '<li><span' .$class_3. '>&nbsp;</span></li>';
    $output[]   = '<li><span' .$class_2. '>&nbsp;</span></li>';
    $output[]   = '<li><span' .$class_1. '>&nbsp;</span></li>';
    $output[]   = '</ul>';

    return implode("\n", $output);
}

function translate($args)
{
	global $lang;
    if (!is_array($args)) {
        $args = func_get_args();
    }

    $code           = $args['0'];
    $translation	= FALSE;
    if (isset($lang[$code])) {
        $translation = $lang[$code];
    }

    if (isset($args['1']) && $translation) {
        $args   = array_slice($args, 1);
        return vsprintf($translation, $args);
    } else {
        return $translation;
    }

    return '';
}

function private_photo($type='video') {
	global $config;
	if (strpos($_SERVER['HTTP_USER_AGENT'], 'MSIE 6') === FALSE) {
        return 'private-'.$type.'.png';
    } else {
        return 'private-'.$type.'.gif';
    }
}

function check_image($path, $ext)
{
	$check = FALSE;
    if ($ext == 'gif') {
        $check = imagecreatefromgif($path);
    } elseif ($ext == 'png') {
        $check = imagecreatefrompng($path);
    } elseif ($ext == 'jpeg' OR $ext = 'jpg') {
        $check = imagecreatefromjpeg($path);
    }

	if ($ext == 'gif' && $check) {
  		$contents = file_get_contents($path);
  		if (strpos($contents, 'php') !== FALSE) {
      		$check = FALSE;
  		}
	}

    return ($check) ? TRUE : FALSE;
}

function show_err ($exp)
{
	return '<div class="alert alert-danger alert-dismissible fade show" role="alert">'.$exp.'<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>';
}

function show_msg ($exp)
{
	return '<div class="alert alert-success alert-dismissible fade show" role="alert">'.$exp.'<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div></div>';
}

function show_err_mb ($exp)
{
	return '<div class="alert alert-dismissable alert-danger m-b-15 m-b-0"><button type="button" class="close" data-dismiss="alert">×</button>'.$exp.'</div>';
}

function show_msg_mb ($exp)
{
	return '<div class="alert alert-dismissable alert-success m-b-15 m-b-0"><button type="button" class="close" data-dismiss="alert">×</button>'.$exp.'</div>';
}

function blog_output($content) 
{
	global $config;
	$search     = array('/\[b\](.*?)\[\/b\]/ms', '/\[i\](.*?)\[\/i\]/ms', '/\[u\](.*?)\[\/u\]/ms',
						'/\[img\](.*?)\[\/img\]/ms', '/\[email\](.*?)\[\/email\]/ms', '/\[url\="?(.*?)"?\](.*?)\[\/url\]/ms',
						'/\[size\="?(.*?)"?\](.*?)\[\/size\]/ms', '/\[color\="?(.*?)"?\](.*?)\[\/color\]/ms', '/\[quote](.*?)\[\/quote\]/ms',
						'/\[list\=(.*?)\](.*?)\[\/list\]/ms', '/\[list\](.*?)\[\/list\]/ms', '/\[\*\]\s?(.*?)\n/ms');
	$replace    = array('<strong>\1</strong>', '<em>\1</em>', '<u>\1</u>', '<img src="\1" alt="\1" />',
						'<a href="mailto:\1">\1</a>', '<a href="\1">\2</a>', '<span style="font-size:\1%">\2</span>',
						'<span style="color:\1">\2</span>', '<blockquote>\1</blockquote>', '<ol start="\1">\2</ol>',
						'<ul>\1</ul>', '<li>\1</li>');
	$content    = preg_replace($search, $replace, $content);
	$content    = preg_replace('/\[photo=(.*?)\]/ms', '<div class="row"><div class="col-md-8 col-md-offset-2"><center><img src="' .$config['BASE_URL']. '/media/photos/\1.jpg" alt="" class="blog_image" /></center></div></div>', $content);
	$content    = preg_replace('/\[video=(.*?)\]/ms', '<div class="row"><div class="col-md-8 col-md-offset-2"><div class="blog_video"><div id="blog_video_\1"><iframe src="' .$config['BASE_URL'].'/view.php?VID=\1" frameborder="0" allowfullscreen></iframe></div></div></div></div>', $content);
	$content    = str_replace("\r", "", $content);
	$content    = "<p>".preg_replace("/(\n)/", "</p><p>", $content)."</p>";
	
	return $content;
}

function prepare_tags ($string) {
	$string = strip_tags($string);
	$tags = explode (',', $string);
	foreach ($tags as $tag) {
		if (strlen($tag) > 1) {
			$tag = strtolower(trim($tag));
			if (strlen($tag) > 1) {
				$tag_arr[] = $tag;	
			}
		}
	}
	$tag_arr = array_unique($tag_arr);
	$result = implode(', ',$tag_arr);
	unset($tag_arr);
	return $result;	
}

function add_tags($string) {
	global $conn;
	$tags = explode (',', $string);
	foreach ($tags as $tag) {
		$tag = trim($tag);
		$sql = "SELECT id FROM tags WHERE tag = " .$conn->qStr($tag). " LIMIT 1";
		$rs  = $conn->execute($sql);
		if ( $conn->Affected_Rows() != 1 ) {
			$sql = "INSERT INTO tags(tag, counter) VALUES (" .$conn->qStr($tag). ",'1')";
			$conn->execute($sql);
		} else {
			$sql = "UPDATE tags SET counter = counter + 1 WHERE tag = " .$conn->qStr($tag);
			$conn->execute($sql);
		}			
	}
}

function remove_tags($string) {
	global $conn;	
	$tags = explode (',', $string);
	foreach ($tags as $tag) {
		$tag = trim($tag);
		$sql = "SELECT id, counter FROM tags WHERE tag = " .$conn->qStr($tag). " LIMIT 1";
		$rs  = $conn->execute($sql);
		if ( $conn->Affected_Rows() == 1 ) {
			$id  = intval($rs->fields['id']);
			$counter = intval($rs->fields['counter']);
			if ($counter > 1) {
				$sql = "UPDATE tags SET counter = counter - 1 WHERE tag = " .$conn->qStr($tag);
				$conn->execute($sql);
			} else {
				$sql = "DELETE FROM tags WHERE tag = " .$conn->qStr($tag);
				$conn->execute($sql);				
			}
		}			
	}
}

function tags_to_comma($string) {
	return implode(', ', array_unique(array_filter(array_map('trim', explode(' ', $string)))));
}

function cleanup_tags() {
	global $conn;
	$conn->execute("DELETE FROM tags WHERE counter <= 0");
}

function update_tags($vid, $string) {
	global $conn;
	$vid = intval($vid);
	$sql = "SELECT keyword FROM video WHERE VID = " .$vid. " LIMIT 1";
	$rs  = $conn->execute($sql);
	if ( $conn->Affected_Rows() == 1 ) {	
		$keyword = $rs->fields['keyword'];
		if ($keyword != $string) {
			remove_tags ($keyword);
			add_tags ($string);
		}
	}
}

function comment_output($comment) {
	global $config, $conn;	

	
	if (preg_match_all('/\[photo=(.*?)\]/', $comment, $matches_photo)) {	
		foreach($matches_photo[0] as $k => $v) {
			$sql        = "SELECT PID FROM photos WHERE PID = " .intval($matches_photo[1][$k]). " AND status = '1' LIMIT 1";
			$rs         = $conn->execute($sql);
			if ( $conn->Affected_Rows() == 1 ) {
				$comment = str_replace($v, '<div class="row justify-content-center"><div class="col-md-6"><img src="' .$config['BASE_URL']. '/media/photos/'.intval($matches_photo[1][$k]).'.jpg" alt="" /></div></div>', $comment);
			} else {
				$comment = str_replace($v, '<div class="row"><i class="fas fa-exclamation-circle"></i></div>', $comment);
			}
		}
	}
	unset($matches_photo);	
	
	if (preg_match_all('/\[video=(.*?)\]/', $comment, $matches_video)) {	
		foreach($matches_video[0] as $k => $v) {
			$sql        = "SELECT VID FROM video WHERE VID = " .intval($matches_video[1][$k]). " AND active = '1' AND type='public' LIMIT 1";
			$rs         = $conn->execute($sql);
			if ( $conn->Affected_Rows() == 1 ) {			
				$comment = str_replace($v, '<div class="row justify-content-center"><div class="col-md-6"><div><iframe src="' .$config['BASE_URL'].'/view.php?VID='.intval($matches_video[1][$k]).'" frameborder="0" allowfullscreen></iframe></div></div></div>', $comment);
			} else {
				$comment = str_replace($v, '<div class="row"><i class="fas fa-exclamation-circle"></i></div>', $comment);
			}
		}
	}
	unset($matches_video);	
	
	return $comment;
}

function comments_total($type, $id) {
	global $conn;
	$prefix			= substr(ucfirst($type),0,1);		
	$sql            = "SELECT COUNT(CID) AS total_comments FROM ".$type."_comments WHERE ".$prefix."ID = " .$id. " AND status = '1'";
	$rsc            = $conn->execute($sql);
	$comments_total = $rsc->fields['total_comments'];
	return $comments_total;
}

function replies_total($type, $id) {
	global $conn;	
	$sql            = "SELECT COUNT(CID) AS total_comments FROM ".$type."_comments WHERE PARENT_ID = " .$id. " AND status = '1'";
	$rsc            = $conn->execute($sql);
	$comments_total = $rsc->fields['total_comments'];
	return $comments_total;
}

function encryptPhp($string, $key, $iv) {
        $encrypt_method="AES-256-CBC";
        $secret_key=$key;
        $secret_iv=$iv;
        $key=hash('sha256',$secret_key);
        $iv=substr(hash('sha256',$secret_iv),0,16);
        $output=openssl_encrypt($string,$encrypt_method,$key,0,$iv);
        $output=base64_encode($output);
        return $output;
}

} // fim do guard de carregamento único (aberto no topo)

?>
