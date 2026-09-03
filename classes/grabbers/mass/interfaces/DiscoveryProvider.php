<?php
defined('_VALID') or die('Restricted Access!');

/**
 * DiscoveryProvider - Interface for page-level video discovery.
 *
 * A DiscoveryProvider takes a page/listing URL and extracts individual video URLs + metadata.
 * This is distinct from GrabberInterface which takes a single video URL and downloads it.
 */
interface DiscoveryProvider {

    /**
     * Human-readable site name (e.g. "YouTube", "XFree")
     * @return string
     */
    public function getSiteName();

    /**
     * Whether this provider can discover videos from the given URL.
     * @param string $url  A page/listing URL
     * @return bool
     */
    public function canDiscover($url);

    /**
     * Whether this provider can also grab (download) individual videos.
     * @return bool
     */
    public function supportsGrab();

    /**
     * Whether this provider supports metadata extraction.
     * @return bool
     */
    public function supportsMetadata();

    /**
     * Whether this provider supports quality version detection.
     * @return bool
     */
    public function supportsVersions();

    /**
     * Discover videos from a listing/page URL.
     *
     * @param string $url      The page URL to scan
     * @param array  $options  [
     *     'max_pages' => int,       // max pages to follow (default 1)
     *     'page'      => int,       // current page number (1-based)
     *     'timeout'   => int,       // request timeout in seconds
     * ]
     * @return array [
     *     'status'  => true|false,
     *     'error'   => string|null,
     *     'videos'  => [
     *         [
     *             'external_id'   => string,  // unique ID from source site
     *             'source_url'    => string,  // URL to the individual video page
     *             'canonical_url' => string,  // normalized URL (no tracking params)
     *             'title'         => string,
     *             'description'   => string,
     *             'tags'          => string,  // comma-separated
     *             'duration'      => int,     // seconds
     *             'duration_formatted' => string, // mm:ss
     *             'thumbnail_url' => string,
     *         ],
     *     'page'      => int,  // current page
     *     'has_more'  => bool, // whether more pages exist
     *     'total'     => int,  // total found on this page
     * ]
     */
    public function discover($url, $options = array());

    /**
     * Normalize a URL by removing tracking params, fragments, etc.
     * @param string $url
     * @return string  The canonical URL
     */
    public function normalizeUrl($url);

    /**
     * Extract the external video ID from a URL if possible.
     * @param string $url
     * @return string|null  The external ID, or null if not extractable
     */
    public function getExternalId($url);
}
