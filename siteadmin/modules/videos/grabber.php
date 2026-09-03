<?php
defined('_VALID') or die('Restricted Access!');
Auth::checkAdmin();

require_once $config['BASE_DIR'] . '/include/function_video.php';
require_once $config['BASE_DIR'] . '/include/function_queue.php';
require_once $config['BASE_DIR'] . '/include/function_thumbs.php';
require_once $config['BASE_DIR'] . '/classes/filter.class.php';
require_once $config['BASE_DIR'] . '/classes/validation.class.php';
require_once $config['BASE_DIR'] . '/classes/image.class.php';
require_once $config['BASE_DIR'] . '/classes/grabbers/GrabberManager.php';

@set_time_limit(0);
@ini_set('max_execution_time', 0);
@ini_set('memory_limit', '512M');

if (!function_exists('duration_to_seconds')) {
    function duration_to_seconds($duration) {
        $dur_arr = explode(':', $duration);
        if (!isset($dur_arr['1'])) {
            return is_numeric($duration) ? (int)$duration : 0;
        }
        $seconds = 0;
        if (isset($dur_arr['2'])) {
            $seconds = ((int)$dur_arr['0'] * 3600) + ((int)$dur_arr['1'] * 60) + (int)$dur_arr['2'];
        } else {
            $seconds = ((int)$dur_arr['0'] * 60) + (int)$dur_arr['1'];
        }
        return $seconds;
    }
}

if (!function_exists('run_in_background_grabber')) {
    function run_in_background_grabber($Command, $Priority = 0) {
        // sem nohup via web: nohup falha com "Inappropriate ioctl for device" quando roda como daemon
        if ($Priority) $PID = shell_exec("nice -n $Priority $Command > /dev/null 2>&1 & echo $!");
        else $PID = shell_exec("$Command > /dev/null 2>&1 & echo $!");
        return ($PID);
    }
}

// Tratar requisição AJAX para buscar metadados do vídeo
if (isset($_GET['a']) && $_GET['a'] == 'fetch') {
    header('Content-Type: application/json; charset=utf-8');
    $url = isset($_POST['url']) ? trim($_POST['url']) : (isset($_GET['url']) ? trim($_GET['url']) : '');

    if (empty($url)) {
        echo json_encode(array('status' => false, 'error' => 'Por favor, informe a URL do vídeo.'));
        exit();
    }

    $grabber = GrabberManager::getGrabberForUrl($url);
    if (!$grabber) {
        echo json_encode(array(
            'status' => false,
            'error'  => 'Nenhum grabber disponível para esta URL. Sites suportados: ' . implode(', ', GrabberManager::getSupportedSites())
        ));
        exit();
    }

    $info = $grabber->fetchInfo($url);
    echo json_encode($info);
    exit();
}

// Tratar requisição AJAX para adicionar uma nova categoria
if (isset($_GET['a']) && $_GET['a'] == 'add_category') {
    header('Content-Type: application/json; charset=utf-8');
    $name = isset($_POST['name']) ? trim(strip_tags($_POST['name'])) : '';
    $slug = isset($_POST['slug']) ? toAscii(trim($_POST['slug'])) : '';

    $response = array('status' => 0, 'id' => 0, 'name' => $name, 'slug' => $slug, 'error' => '');

    if ($name == '') {
        $response['error'] = 'O nome da categoria não pode ficar em branco!';
        echo json_encode($response);
        exit();
    }

    if ($slug == '') {
        $slug = toAscii($name);
    }

    if (channelNameExists($name, 0)) {
        $response['error'] = 'Já existe uma categoria com este nome!';
        echo json_encode($response);
        exit();
    }

    if (channelSlugExists($slug, 0)) {
        $response['error'] = 'Já existe uma categoria com este slug!';
        echo json_encode($response);
        exit();
    }

    $sql = "INSERT INTO channel (name, slug) VALUES (" . $conn->qStr($name) . ", " . $conn->qStr($slug) . ")";
    $conn->execute($sql);

    $response['status'] = 1;
    $response['id']     = intval($conn->Insert_ID());
    $response['name']   = $name;
    $response['slug']   = $slug;

    echo json_encode($response);
    exit();
}

$video = array(
    'url'         => '',
    'username'    => 'anonymous',
    'title'       => '',
    'description' => '',
    'category'    => 0,
    'tags'        => '',
    'type'        => 'public',
    'quality'     => 'best',
    'duration'    => '',
    'thumb_url'   => ''
);

// Tratar submissão do formulário de importação
if (isset($_POST['grab_video'])) {
    $filter      = new VFilter();
    // URL não deve passar por xss_filter (HTMLPurifier converte & para &amp; e quebra yt-dlp)
    $url         = isset($_POST['url']) ? trim($_POST['url']) : '';
    $url         = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $thumbUrl    = isset($_POST['thumb_url']) ? trim($_POST['thumb_url']) : '';
    $thumbUrl    = html_entity_decode($thumbUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $username    = $filter->get('username');
    $title       = $filter->get('title');
    $description = $filter->get('description');
    $category    = $filter->get('category', 'INT');
    $tags        = $filter->get('tags');
    $type        = $filter->get('type');
    $quality     = $filter->get('quality');
    $duration    = $filter->get('duration');

    $video['url']         = $url;
    $video['username']    = $username;
    $video['title']       = $title;
    $video['description'] = $description;
    $video['category']    = $category;
    $video['tags']        = $tags;
    $video['type']        = ($type == 'private') ? 'private' : 'public';
    $video['quality']     = $quality;
    $video['thumb_url']   = $thumbUrl;
    $video['duration']    = $duration;

    if (empty($url)) {
        $errors[] = 'Por favor, insira a URL do vídeo!';
    }

    $grabber = GrabberManager::getGrabberForUrl($url);
    if (!$grabber) {
        $errors[] = 'A URL fornecida não é suportada por nenhum Grabber ativo!';
    }

    if (empty($username)) {
        $errors[] = 'Por favor, insira o nome de usuário responsável pelo upload!';
    } else {
        $sql = "SELECT UID FROM signup WHERE username = " . $conn->qStr($username) . " LIMIT 1";
        $rs  = $conn->execute($sql);
        if ($conn->Affected_Rows() == 1) {
            $uid = intval($rs->fields['UID']);
        } else {
            $errors[] = 'O usuário "' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '" não existe!';
        }
    }

    if (empty($title)) {
        $errors[] = 'Por favor, insira o título do vídeo!';
    }

    if ($category === 0) {
        $errors[] = 'Por favor, selecione uma categoria!';
    }

    if (empty($tags)) {
        $errors[] = 'Por favor, insira as tags do vídeo!';
    } else {
        $tags = prepare_tags($tags);
    }

    if (!$errors) {
        // Inserção imediata (não bloqueia) - download em segundo plano
        $durationSeconds = is_numeric($duration) ? (int)$duration : duration_to_seconds($duration);

        // Garante diretório de logs
        if (!is_dir($config['LOG_DIR'])) {
            @mkdir($config['LOG_DIR'], 0777, true);
        }

        // 1. Inserir placeholder no banco (retorno instantâneo)
        $sql = "INSERT INTO video SET
                    UID = " . intval($uid) . ",
                    title = " . $conn->qStr($title) . ",
                    channel = " . intval($category) . ",
                    keyword = " . $conn->qStr($tags) . ",
                    description = " . $conn->qStr($description) . ",
                    space = '0',
                    duration = '" . $durationSeconds . "',
                    addtime = '" . time() . "',
                    adddate = '" . date('Y-m-d') . "',
                    vkey = '" . mt_rand() . "',
                    type = '" . $video['type'] . "',
                    source_url = " . $conn->qStr($url) . ",
                    active = '2'";

        $conn->execute($sql);
        $vid = (int)$conn->insert_Id();

        if ($vid > 0) {
            $vkey    = substr(md5($vid), 11, 20);
            $vdoname = $vid . '.mp4';
            $conn->execute("UPDATE video SET vkey = '" . $vkey . "', vdoname = " . $conn->qStr($vdoname) . " WHERE VID = " . intval($vid) . " LIMIT 1");

            // 2. Dispara worker em segundo plano (não bloqueia a requisição)
            $encodedUrl   = base64_encode($url);
            $encodedThumb = base64_encode($thumbUrl);
            $worker = $config['BASE_DIR'] . '/scripts/grabber_worker.php';
            // Garante que script existe
            if (file_exists($worker)) {
                $cmd = sprintf('%s %s %d %s %s %s > /dev/null 2>&1 & echo $!',
                    escapeshellarg($config['phppath']),
                    escapeshellarg($worker),
                    $vid,
                    escapeshellarg($encodedUrl),
                    escapeshellarg($quality),
                    escapeshellarg($encodedThumb)
                );
                $fullCmd = $cmd;
                @file_put_contents($config['LOG_DIR'] . '/' . $vid . '.grabber.log', date('Y-m-d H:i:s') . " - Spawning worker: $fullCmd\n", FILE_APPEND);
                $pid = @shell_exec($fullCmd);
            } else {
                // Fallback: tenta download síncrono se worker não existir (não deve ocorrer)
                error_log("grabber_worker.php não encontrado: $worker");
            }

            // 3. Mensagem de sucesso imediata (não espera download)
            $messages[] = 'Vídeo <b>"' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"</b> (ID: ' . $vid . ') <b>importado com sucesso!</b><br><small>Download em segundo plano iniciado - você já pode continuar navegando. O vídeo entrará na fila de conversão automaticamente após o download.</small>';

            // Limpar campos para próximo import
            $video = array(
                'url'         => '',
                'username'    => $username,
                'title'       => '',
                'description' => '',
                'category'    => $category,
                'tags'        => '',
                'type'        => 'public',
                'quality'     => 'best',
                'duration'    => '',
                'thumb_url'   => ''
            );
        } else {
            $errors[] = 'Erro ao registrar o vídeo no banco de dados.';
        }
    }
}

$smarty->assign('video', $video);
$smarty->assign('categories', get_categories());
$smarty->assign('supported_sites', GrabberManager::getSupportedSites());

// Variáveis exigidas pelo footer.tpl quando sub_menu == 'add-videos'
$smarty->assign('grabbing', '');
$smarty->assign('path', '');
$smarty->assign('filesize', '');
?>
