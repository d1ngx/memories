<?php

class MemoriesMediaIndexer {
	private $plugin;

	public function __construct($plugin) {
		$this->plugin = $plugin;
	}

	public function metadata($source) {
		$path = KodIO::make($source['sourceID']);
		$local = $this->localFile($path);
		$width = 0; $height = 0; $takenAt = intval($source['modifyTime']);
		$latitude = null; $longitude = null;
		if ($local && is_file($local) && $this->isImage($source['fileType'])) {
			$size = @getimagesize($local);
			if ($size) { $width = intval($size[0]); $height = intval($size[1]); }
			if (function_exists('exif_read_data')) {
				$exif = @exif_read_data($local, null, true, false);
				if (is_array($exif)) {
					$date = _get($exif, 'EXIF.DateTimeOriginal', _get($exif, 'IFD0.DateTime', ''));
					$parsed = $date ? $this->parseExifDate($date) : false;
					if ($parsed) $takenAt = $parsed;
					$latitude = $this->gps(_get($exif, 'GPS.GPSLatitude'), _get($exif, 'GPS.GPSLatitudeRef'));
					$longitude = $this->gps(_get($exif, 'GPS.GPSLongitude'), _get($exif, 'GPS.GPSLongitudeRef'));
				}
			}
		}
		return array('takenAt' => $takenAt ?: time(), 'width' => $width, 'height' => $height, 'latitude' => $latitude, 'longitude' => $longitude);
	}

	public function parseExifDate($value) {
		if (!is_string($value) || !preg_match('/^(\d{4}):(\d{2}):(\d{2}) (\d{2}):(\d{2}):(\d{2})/', trim($value), $m)) return false;
		if (intval($m[1]) < 1970) return false;
		return mktime(intval($m[4]), intval($m[5]), intval($m[6]), intval($m[2]), intval($m[3]), intval($m[1]));
	}

	public function gps($parts, $ref) {
		if (!is_array($parts) || count($parts) < 3) return null;
		$decimal = $this->fraction($parts[0]) + $this->fraction($parts[1]) / 60 + $this->fraction($parts[2]) / 3600;
		if (in_array(strtoupper((string)$ref), array('S', 'W'))) $decimal *= -1;
		return round($decimal, 8);
	}

	private function fraction($value) {
		if (is_numeric($value)) return floatval($value);
		$bits = explode('/', (string)$value, 2);
		return count($bits) === 2 && floatval($bits[1]) != 0.0 ? floatval($bits[0]) / floatval($bits[1]) : 0.0;
	}

	private function isImage($ext) {
		return in_array(strtolower($ext), array('jpg','jpeg','png','gif','webp','avif','heic','heif','bmp'));
	}

	private function localFile($path) {
		if (!is_file(PLUGIN_DIR.'fileThumb/app.php')) return false;
		try { return Action('fileThumbPlugin')->localFile($path); } catch (Throwable $e) { return false; }
	}
}
