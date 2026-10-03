<?php
define('_VALID', true);
define('_ADMIN', true);
require 'include/config.php';
require 'include/function_admin.php';

header("Content-type: text/xml"); 
echo '<?xml version="1.0" encoding="UTF-8"?>';

$characters_to_remove = array('&',"'",'"','>','<','-',',','/'); 
$replace_with = array('&','&apos;','"','>','<','','',''); 

$base_url = rtrim($config['BASE_URL'], '/');
$today = date('Y-m-d');

echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" 
    xmlns:video="http://www.google.com/schemas/sitemap-video/1.1"
    xmlns:xhtml="http://www.w3.org/1999/xhtml">';

// --- Home page ---
echo '<url>
    <loc>' . $base_url . '/</loc>
    <lastmod>' . $today . '</lastmod>
    <changefreq>daily</changefreq>
    <priority>1.0</priority>
</url>';

// --- Static pages ---
$static_pages = array(
    '/videos' => array('daily', '0.9'),
    '/shorts' => array('daily', '0.9'),
    '/categories' => array('daily', '0.8'),
    '/tags' => array('weekly', '0.7'),
    '/albums' => array('weekly', '0.6'),
    '/blogs' => array('weekly', '0.6'),
    '/users' => array('weekly', '0.6'),
    '/login' => array('monthly', '0.3'),
    '/signup' => array('monthly', '0.3'),
    '/upload' => array('monthly', '0.4'),
    '/feeds' => array('daily', '0.5'),
);

foreach ($static_pages as $path => $params) {
    echo '<url>
    <loc>' . $base_url . $path . '</loc>
    <lastmod>' . $today . '</lastmod>
    <changefreq>' . $params[0] . '</changefreq>
    <priority>' . $params[1] . '</priority>
</url>';
}

// --- Categories ---
$sql = "SELECT CHID, name, slug FROM channel WHERE total_videos > 0 ORDER BY total_videos DESC";
$rs = $conn->execute($sql);
$categories = $rs->getrows();
foreach ($categories as $cat) {
    $cat_url = $base_url . '/videos/' . $cat['slug'];
    echo '<url>
    <loc>' . $cat_url . '</loc>
    <lastmod>' . $today . '</lastmod>
    <changefreq>daily</changefreq>
    <priority>0.8</priority>
</url>';
}

// --- Tags (top 5000 by counter) ---
$sql = "SELECT tag, counter FROM tags WHERE counter >= 5 ORDER BY counter DESC LIMIT 5000";
$rs = $conn->execute($sql);
$tags = $rs->getrows();
foreach ($tags as $tag) {
    $tag_slug = str_replace(' ', '-', $tag['tag']);
    $tag_url = $base_url . '/search/tags/' . urlencode($tag_slug);
    echo '<url>
    <loc>' . $tag_url . '</loc>
    <lastmod>' . $today . '</lastmod>
    <changefreq>weekly</changefreq>
    <priority>0.6</priority>
</url>';
}

// --- Videos with video schema (latest 50000) ---
$sql = "SELECT VID, title, description, thumb, duration, addtime, viewnumber FROM video WHERE active = '1' ORDER BY addtime DESC LIMIT 50000";
$rs = $conn->execute($sql);
$videos = $rs->getrows();
foreach ($videos as $video) {
    $title = str_replace($characters_to_remove, $replace_with, $video['title']);
    $description = str_replace($characters_to_remove, $replace_with, strip_tags($video['description']));
    $VID = $video['VID'];
    $thumbnail = get_thumb_url($video['VID']) . '/' . $video['thumb'] . '.jpg';
    $duration = round($video['duration']);
    $lastmod = date('Y-m-d', strtotime($video['addtime']));
    if ($description == '') {
        $description = strip_tags($title);
    }
    
    $video_url = $base_url . '/video/' . $VID . '/' . toAscii($title);
    echo '<url>
    <loc>' . $video_url . '</loc>
    <lastmod>' . $lastmod . '</lastmod>
    <changefreq>weekly</changefreq>
    <priority>0.7</priority>
    <video:video>
        <video:thumbnail_loc>' . $thumbnail . '</video:thumbnail_loc>
        <video:title>' . $title . '</video:title>
        <video:description>' . $description . '</video:description>
        <video:duration>' . $duration . '</video:duration>
        <video:publication_date>' . $lastmod . '</video:publication_date>
        <video:view_count>' . intval($video['viewnumber']) . '</video:view_count>
        <video:family_friendly>false</video:family_friendly>
        <video:requires_subscription>no</video:requires_subscription>
    </video:video>
</url>';
}

// --- Users with videos (top 5000 by total_videos) ---
$sql = "SELECT UID, username, total_videos FROM signup WHERE account_status = 'Active' AND total_videos > 0 ORDER BY total_videos DESC LIMIT 5000";
$rs = $conn->execute($sql);
$users = $rs->getrows();
foreach ($users as $user) {
    $user_url = $base_url . '/user/' . urlencode($user['username']);
    echo '<url>
    <loc>' . $user_url . '</loc>
    <lastmod>' . $today . '</lastmod>
    <changefreq>weekly</changefreq>
    <priority>0.5</priority>
</url>';
}

echo '</urlset>';