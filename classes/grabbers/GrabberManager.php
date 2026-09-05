<?php
defined('_VALID') or die('Restricted Access!');

require_once dirname(__FILE__) . '/GrabberInterface.php';
require_once dirname(__FILE__) . '/YoutubeGrabber.php';
require_once dirname(__FILE__) . '/XfreeGrabber.php';
require_once dirname(__FILE__) . '/SonovinhasbrGrabber.php';
require_once dirname(__FILE__) . '/PornolandiaGrabber.php';

class GrabberManager {

    private static $grabbers = null;

    private static function init() {
        if (self::$grabbers === null) {
            self::$grabbers = array(
                new YoutubeGrabber(),
                new XfreeGrabber(),
                new SonovinhasbrGrabber(),
                new PornolandiaGrabber(),
            );
        }
    }

    /**
     * Retorna o grabber adequado para a URL
     * @param string $url
     * @return GrabberInterface|null
     */
    public static function getGrabberForUrl($url) {
        self::init();
        foreach (self::$grabbers as $grabber) {
            if ($grabber->canHandle($url)) {
                return $grabber;
            }
        }
        return null;
    }

    /**
     * Retorna lista de sites suportados
     * @return array
     */
    public static function getSupportedSites() {
        self::init();
        $sites = array();
        foreach (self::$grabbers as $grabber) {
            $sites[] = $grabber->getSiteName();
        }
        return $sites;
    }
}
