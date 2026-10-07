<?php
defined('_VALID') or die('Restricted Access!');

interface GrabberInterface {
    /**
     * Retorna o nome amigável do site suportado (ex: YouTube)
     */
    public function getSiteName();

    /**
     * Verifica se a URL informada pertence a este grabber
     */
    public function canHandle($url);

    /**
     * Extrai os metadados do vídeo (título, descrição, tags, categoria, duração, miniatura, qualidades)
     * @return array [
     *   'status' => true|false,
     *   'error' => string|null,
     *   'title' => string,
     *   'description' => string,
     *   'tags' => string,
     *   'category' => string (categoria do site-fonte; campo SEPARADO das tags,
     *                  categoria nunca vira tag; '' quando a página não expõe),
     *   'duration' => int (segundos),
     *   'duration_formatted' => string (mm:ss),
     *   'thumbnail' => string (url),
     *   'qualities' => array,
     *   'site' => string
     * ]
     */
    public function fetchInfo($url);

    /**
     * Realiza o download do arquivo de vídeo
     * @param string $url
     * @param string $targetPath
     * @param string $quality (ex: 'best', '1080', '720', '480', etc.)
     * @return array ['status' => true|false, 'error' => string|null, 'file_path' => string]
     */
    public function downloadVideo($url, $targetPath, $quality = 'best');

    /**
     * Realiza o download da miniatura para o destino local
     * @param string $thumbUrl
     * @param string $targetPath
     * @return bool
     */
    public function downloadThumbnail($thumbUrl, $targetPath);
}
