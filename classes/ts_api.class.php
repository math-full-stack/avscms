<?php
/**
 * TrafficStars Publisher API client.
 *
 * REST (OAuth 2.0). Viés publisher: applications (sites), spots (slots) e
 * relatório de receita por dia/spot. As operações de advertiser (campanhas/
 * banners) existem na mesma API, mas este client cobre o que monetiza os
 * grupos internos do site.
 *
 * Autenticação: API key (gerada em admin.trafficstars.com/profile/) usada como
 * refresh_token. O access_token expira (expires_in ~10h) e é renovado
 * automaticamente ANTES de vencer. Cache do token em arquivo (JSON).
 *
 * Credencial: lida de getenv('TS_API_KEY') — carregada via .env (dotenv.php)
 * ou ambiente real. Nunca hardcoded. Se não houver chave, os métodos de rede
 * lançam TsApiException (fail-closed).
 *
 * Retry/backoff: 429 (rate limit ~100 req/60s) e 5xx esperam 1s, 2s, 4s.
 * 401 (token expirou no meio da sessão): renova e tenta uma vez.
 */
defined('_VALID') or die('Restricted Access!');

if (!class_exists('TsApiException', false)) {
    class TsApiException extends Exception {}
}

class TsApi
{
    const BASE_URL    = 'https://api.trafficstars.com';
    const MAX_RETRIES = 3;

    private $_apiKey;
    private $_tokenFile;

    public function __construct($apiKey = null, $tokenFile = null)
    {
        $this->_apiKey    = $apiKey ?: getenv('TS_API_KEY');
        $this->_tokenFile = $tokenFile ?: (sys_get_temp_dir() . '/avscms_ts_token.json');
    }

    public function hasKey()
    {
        return !empty($this->_apiKey);
    }

    /**
     * OAuth access token, renovado antes de expirar. Cache em JSON.
     */
    public function getToken($force = false)
    {
        $data = array();
        if (!$force && is_file($this->_tokenFile)) {
            $raw = @file_get_contents($this->_tokenFile);
            $parsed = $raw ? json_decode($raw, true) : null;
            if (is_array($parsed)) {
                $data = $parsed;
                // Renova 60s antes do fim de validade (clock skew + latência).
                if (isset($data['expires_at']) && $data['expires_at'] > (time() + 60)) {
                    return $data['access_token'];
                }
            }
        }

        if (!$this->hasKey()) {
            throw new TsApiException('TS_API_KEY não configurada. Gere uma API key em admin.trafficstars.com/profile/ e defina no .env.');
        }

        $ch = curl_init(self::BASE_URL . '/v1/auth/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array(
            'grant_type'    => 'refresh_token',
            'refresh_token' => $this->_apiKey,
        )));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $body = curl_exec($ch);
        $code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        $err  = curl_error($ch);
        curl_close($ch);

        $json = json_decode($body, true);
        if ($err || $code != 200 || !is_array($json) || empty($json['access_token'])) {
            throw new TsApiException('Falha ao obter access_token: HTTP ' . $code . ($err ? ' / ' . $err : ''));
        }

        $data = array(
            'access_token' => $json['access_token'],
            'expires_at'   => time() + intval($json['expires_in']),
        );
        $this->_writeTokenCache($data);

        return $data['access_token'];
    }

    /**
     * GET /v2/userinfo/balance — saldo do publisher.
     */
    public function getBalance()
    {
        return $this->_call('GET', '/v2/userinfo/balance');
    }

    /**
     * GET /v1.1/applications — sites cadastrados.
     */
    public function getApplications()
    {
        $out = $this->_call('GET', '/v1.1/applications');
        return isset($out['response']) ? $out['response'] : $out;
    }

    /**
     * POST /v1.1/spots/list — lista spots (publik filter via $params).
     */
    public function listSpots($params = array())
    {
        $out = $this->_call('POST', '/v1.1/spots/list', $params);
        return isset($out['response']) ? $out['response'] : $out;
    }

    /**
     * GET /v1.1/spots/{id}
     */
    public function getSpot($id)
    {
        return $this->_call('GET', '/v1.1/spots/' . intval($id));
    }

    /**
     * POST /v1.1/spots — cria um spot novo.
     * $data: app_id, name, type, format_id, categories[], active, min_ecpm, ...
     */
    public function createSpot(array $data)
    {
        return $this->_call('POST', '/v1.1/spots', $data);
    }

    /**
     * PATCH /v1.1/spots/{id} — edição parcial (min_ecpm, revenue_share,
     * active, geo prices via price_by_country, etc).
     */
    public function patchSpot($id, array $data)
    {
        return $this->_call('PATCH', '/v1.1/spots/' . intval($id), $data);
    }

    /**
     * Relatório do publisher.
     * $groupType: site group types ("day", "spot", "country", "format", ...).
     * $params: date_from, date_to, spot_id[] (array), app_id[], etc.
     */
    public function getReport($groupType, $params = array())
    {
        $out = $this->_call('GET', '/v1.1/publisher/custom/report/by-' . $groupType, $params, true);
        return isset($out['response']) ? $out['response'] : $out;
    }

    /**
     * Receita diária de um spot: conveniência sobre getReport('day', ...).
     */
    public function getSpotDaily($spotId, $dateFrom, $dateTo)
    {
        return $this->getReport('day', array(
            'spot_id'   => array($spotId),
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
        ));
    }

    // ---------------------------------------------------------------- internos

    private function _call($method, $path, $params = array(), $asQuery = false)
    {
        $attempts = 0;
        while (true) {
            $attempts++;
            $response = $this->_doCall($method, $path, $params, $asQuery);
            $httpCode = $response['http_code'];
            $body     = $response['body'];
            $decoded  = json_decode($body, true);

            if ($httpCode == 401 && $attempts == 1) {
                // Token expirou no meio da sessão: renova e tenta uma vez.
                $this->getToken(true);
                continue;
            }

            if (in_array($httpCode, array(429, 500, 502, 503)) && $attempts < self::MAX_RETRIES) {
                sleep(pow(2, $attempts - 1)); // 1s, 2s, 4s
                continue;
            }

            if ($httpCode >= 400 || !is_array($decoded)) {
                $detail = '';
                if (is_array($decoded)) {
                    // Trata tanto {message: "..."} quanto {"errors": {...}}
                    if (!empty($decoded['message'])) {
                        $detail = $decoded['message'];
                    } elseif (!empty($decoded['details']) && is_array($decoded['details'])) {
                        $detail = json_encode($decoded['details'], JSON_UNESCAPED_UNICODE);
                    }
                }
                throw new TsApiException('TrafficStars ' . $method . ' ' . $path . ' -> HTTP ' . $httpCode
                    . ($detail ? ': ' . $detail : ''));
            }

            return $decoded;
        }
    }

    private function _doCall($method, $path, $params, $asQuery)
    {
        $url      = self::BASE_URL . $path;
        $token    = $this->getToken();
        $headers  = array('Authorization: Bearer ' . $token);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        if ($method == 'GET' || $asQuery) {
            $url = $url . '?' . http_build_query($this->_flatten($params));
            if ($method == 'GET') {
                curl_setopt($ch, CURLOPT_HTTPGET, true);
            } else {
                curl_setopt($ch, CURLOPT_POST, true);
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            }
        } else {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            $headers[] = 'Content-Type: application/json';
            if (!empty($params)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
            }
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $body = curl_exec($ch);
        $code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        curl_close($ch);

        return array('http_code' => $code, 'body' => $body === false ? '' : $body);
    }

    /**
     * Arrays viram query params repetidos: ['spot_id'=>[1,2]] -> spot_id[]=1&spot_id[]=2.
     * (Convenção da API, ver docs "How to send arrays".)
     */
    private function _flatten($params, $prefix = '')
    {
        $flat = array();
        foreach ((array) $params as $key => $val) {
            $name = ($prefix === '') ? $key : $prefix . '[' . $key . ']';
            if (is_array($val)) {
                $flat = array_merge($flat, $this->_flatten($val, $name));
            } else {
                $flat[] = $name . '=' . urlencode($val);
            }
        }
        // URL-encoded pairs; retorna como lista para http_build_query().
        $joined = implode('&', $flat);
        $out = array();
        foreach (explode('&', $joined) as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) == 2) {
                $out[$parts[0]] = urldecode($parts[1]);
            }
        }
        return $out;
    }

    private function _writeTokenCache(array $data)
    {
        $dir = dirname($this->_tokenFile);
        if ($dir && !is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        @file_put_contents($this->_tokenFile, json_encode($data));
        @chmod($this->_tokenFile, 0600);
    }
}