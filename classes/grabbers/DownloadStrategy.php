<?php
defined('_VALID') or die('Restricted Access!');

/**
 * DownloadStrategy - Comportamento padronizado de download para todos os grabbers.
 *
 * Prioriza preservação do arquivo original (especialmente vertical):
 *   1. Direct download (se stream_url disponível no fetchInfo) — byte-for-byte
 *   2. yt-dlp com seletor que evita re-encode/remux desnecessário
 *   3. Fallback direto via curl no stream_url extraído
 */
trait DownloadStrategy {

    /**
     * Executa download padronizado.
     *
     * @param string $url          URL original da página do vídeo
     * @param string $targetPath   Caminho destino do arquivo
     * @param string $quality      Qualidade solicitada (ignorado; sempre best preservando original)
     * @param array  $info         Resultado de fetchInfo() — deve conter 'stream_url' e 'embed_url'
     * @return array               ['status' => bool, 'file_path' => string, 'size' => int, 'error' => string?]
     */
    protected function downloadVideoStandard($url, $targetPath, $quality, array $info) {
        // Estratégia 0: Direct download do MP4 original (melhor para vertical)
        if ($info['status'] && !empty($info['stream_url'])) {
            $result = $this->tryDirectDownload($info['stream_url'], $targetPath);
            if ($result['status']) return $result;
        }

        // Estratégia 1: yt-dlp na URL da página com seletor "preserve original"
        $result = $this->tryYtdlpPreserveOriginal($url, $targetPath);
        if ($result['status']) return $result;

        // Estratégia 2: yt-dlp na embed_url (player page)
        if ($info['status'] && !empty($info['embed_url'])) {
            $result = $this->tryYtdlpPreserveOriginal($info['embed_url'], $targetPath);
            if ($result['status']) return $result;
        }

        // Estratégia 3: Direct download do stream_url extraído do player (fallback)
        if ($info['status'] && !empty($info['stream_url'])) {
            $result = $this->tryDirectDownload($info['stream_url'], $targetPath);
            if ($result['status']) return $result;
        }

        return [
            'status' => false,
            'error'  => 'Todas as estratégias de download falharam'
        ];
    }

    /**
     * Tenta download direto via curl (preserva arquivo original byte-for-byte).
     *
     * Playlists (HLS/DASH) não são mídia direta: o curl gravaria o texto do
     * manifesto como .mp4. Quando a playlist tem >1024 bytes isso passava no
     * guard de tamanho e gerava um "vídeo" morto — por isso manifests vão
     * sempre pelo yt-dlp, como o MP4 direto do Pornolandia vai pelo curl.
     */
    private function tryDirectDownload($streamUrl, $targetPath) {
        if (preg_match('/\.(m3u8|m3u|mpd|pls|xspf)(\?|#|$)/i', $streamUrl)) {
            return ['status' => false];
        }
        if ($this->downloadDirect($streamUrl, $targetPath)
            && file_exists($targetPath) && filesize($targetPath) > 1024) {
            // Fareja manifesto de playlist salvo como vídeo (URL sem extensão).
            $head = @file_get_contents($targetPath, false, null, 0, 16);
            if ($head !== false && strncmp(ltrim($head), '#EXTM3U', 7) === 0) {
                @unlink($targetPath);
                return ['status' => false];
            }
            return [
                'status'    => true,
                'file_path' => $targetPath,
                'size'      => filesize($targetPath)
            ];
        }
        @unlink($targetPath);
        return ['status' => false];
    }

    /**
     * yt-dlp com seletor que prioriza stream nativo MP4 (sem re-encode).
     *
     * Ordem de preferência:
     *   1. best[ext=mp4][protocol^=http]  — MP4 progressivo nativo (HTTP direto)
     *   2. best[ext=mp4]                  — Qualquer MP4 (pode ser remuxado)
     *   3. bestvideo[vcodec^=avc]+bestaudio/best  — H.264 nativo + áudio (evita VP9/AV1 re-encode)
     *   4. best                           — Último recurso
     */
    private function tryYtdlpPreserveOriginal($url, $targetPath) {
        $formatSelector = 'best[ext=mp4][protocol^=http]/best[ext=mp4]/bestvideo[vcodec^=avc]+bestaudio/best';
        $output = $this->downloadWithYtdlp($url, $targetPath, $formatSelector);

        if (file_exists($targetPath) && filesize($targetPath) > 1024) {
            return [
                'status'    => true,
                'file_path' => $targetPath,
                'size'      => filesize($targetPath)
            ];
        }
        @unlink($targetPath);
        return ['status' => false, 'error' => $this->truncateLog($output)];
    }
}