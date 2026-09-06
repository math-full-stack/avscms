<?php
defined('_VALID') or die('Restricted Access!');
class images_to_sprite {
 
	public function __construct($folder,$output,$x,$y) {
		$this->folder = ($folder ? $folder : 'myfolder'); // Folder name to get images from, i.e. C:\myfolder or /home/user/Desktop/folder
		$this->filetypes = array('jpg'=>true,'png'=>true,'jpeg'=>true,'gif'=>true); // Acceptable file extensions to consider
		$this->output = ($output ? $output : 'mysprite'); // Output filenames, mysprite.png and mysprite.css
		$this->x = $x; // Width of images to consider
		$this->y = $y; // Heigh of images to consider
		$this->files = array();
	}
 
/**
 * Timeline scrub tiles are displayed at roughly 154x86 css px (256x144 at
 * 0.6), so 320x180 source tiles are ~2x / retina sharp without bloating the
 * sprite file that is loaded on every watch page.
 */
const SPRITE_TILE_W = 320;
const SPRITE_TILE_H = 180;

/**
 * High-quality sprite builder: resamples each available frame into a
 * uniform 320x180 tile row (smooth downscale, JPEG 88) and skips missing
 * frames instead of rendering black placeholders.
 *
 * @return bool true when the sprite was written
 */
private function build_sprite() {
	$out = $this->output . '.jpg';

	$frames = array();
	for ($i = 1; $i <= 20; $i++) {
		$f = $this->folder . '/' . $i . '.jpg';
		if (is_file($f) && is_readable($f)) {
			$frames[] = $f;
		}
	}
	if (empty($frames)) {
		return false;
	}

	$cols  = count($frames);
	$tileW = self::SPRITE_TILE_W;
	$tileH = self::SPRITE_TILE_H;

	$im    = imagecreatetruecolor($tileW * $cols, $tileH);
	$black = imagecolorallocate($im, 0, 0, 0);
	imagefilledrectangle($im, 0, 0, $tileW * $cols, $tileH, $black);

	$col = 0;
	foreach ($frames as $f) {
		$src = @imagecreatefromjpeg($f);
		if ($src) {
			imagecopyresampled($im, $src, $col * $tileW, 0, 0, 0, $tileW, $tileH, imagesx($src), imagesy($src));
			imagedestroy($src);
		}
		$col++;
	}

	imagejpeg($im, $out, 88);
	imagedestroy($im);
	return true;
}

/**
 * Rebuild the sprite only when it is missing or any 1..20 frame is newer
 * (thumbnails were regenerated) — avoids re-encoding it on every page view.
 *
 * @return bool true when the sprite should be (re)generated
 */
public function sprite_is_stale() {
	$out = $this->output . '.jpg';
	if (!is_file($out)) {
		return true;
	}
	$out_time = filemtime($out);
	for ($i = 1; $i <= 20; $i++) {
		$f = $this->folder . '/' . $i . '.jpg';
		if (is_file($f) && filemtime($f) > $out_time) {
			return true;
		}
	}
	return false;
}

	function create_sprite() {
		if ($this->build_sprite()) {
			return true;
		}
		// Legacy body runs only when no 1..20 frames were available to
		// build_sprite(). Each frame is checked before use so a missing file
		// (e.g. thumbs only in the GCS bucket) can never crash the page.

		$resize = 1;
		// Read through the directory for suitable images
		for ($i = 1; $i<=20; $i++) {
			$this->files[$i.'.jpg'] = $i.'.jpg';
		}

		// Keep only frames that actually exist locally
		$available = array();
		foreach ($this->files as $file) {
			if (is_file($this->folder.'/'.$file) && is_readable($this->folder.'/'.$file)) {
				$available[] = $file;
			}
		}
		if (empty($available)) {
			return false;
		}

		// xx is the width of the sprite to be created, basically X * number of images
		$this->xx = $this->x * count($available);
		$im = imagecreatetruecolor(round($this->xx*$resize),round($this->y*$resize));
 
		// Add alpha channel to image (transparency)
		imagesavealpha($im, true);
		$alpha = imagecolorallocatealpha($im, 0, 0, 0, 127);
		imagefill($im,0,0,$alpha);
 
		// Append images to sprite
		$i = 0;
		foreach($available as $file) {
			$im2 = @imagecreatefromjpeg($this->folder.'/'.$file);
			if (!$im2) {
				continue;
			}
			imagecopyresized($im,$im2,round(($this->x*$i)*$resize),0,0,0,round(($this->x)*$resize),round(($this->y)*$resize),$this->x,$this->y);
			imagedestroy($im2);
			$i++;
		}
		imagejpeg($im,$this->output.'.jpg'); // Save image to file
		imagedestroy($im);
		return true;
	}
}
?>