<?php

class MemoriesMediaIndexer {
	private $plugin;

	public function __construct($plugin) {
		$this->plugin = $plugin;
	}

	public function capabilities() {
		return array('exif' => function_exists('exif_read_data'), 'xmp' => true, 'quickTime' => defined('SDK_DIR') && is_file(SDK_DIR.'/getID3/getid3/getid3.php'));
	}

	public function fingerprint() {
		$cap = $this->capabilities();
		return 'geo-v3-exif'.intval($cap['exif']).'-xmp1-qt'.intval($cap['quickTime']);
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
		if ($local && is_file($local) && ($latitude === null || $longitude === null)) {
			$xmp = $this->xmpGps($local); if ($xmp) { $latitude = $xmp[0]; $longitude = $xmp[1]; }
		}
		if ($local && is_file($local) && ($latitude === null || $longitude === null) && $this->isVideo($source['fileType'])) {
			$videoGps = $this->getId3Gps($local); if ($videoGps) { $latitude = $videoGps[0]; $longitude = $videoGps[1]; }
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

	public function coordinate($value, $ref = '') {
		if (is_numeric($value)) $decimal = floatval($value);
		else {
			$value = trim((string)$value); if ($value === '') return null;
			if (preg_match('/^([+-]?\d+(?:\.\d+)?)[, ]+(\d+(?:\.\d+)?)(?:[, ]+(\d+(?:\.\d+)?))?\s*([NSEW])?$/i', $value, $m)) $decimal = abs(floatval($m[1])) + floatval($m[2]) / 60 + floatval(_get($m, 3, 0)) / 3600;
			elseif (preg_match('/[+-]?\d+(?:\.\d+)?/', $value, $m)) $decimal = floatval($m[0]); else return null;
			if (substr($value, 0, 1) === '-') $decimal = -abs($decimal);
			if (!empty($m[4])) $ref = $m[4];
		}
		if (in_array(strtoupper((string)$ref), array('S', 'W'))) $decimal = -abs($decimal);
		return round($decimal, 8);
	}

	private function xmpGps($file) {
		$handle = @fopen($file, 'rb'); if (!$handle) return null;
		$data = @fread($handle, 4 * 1024 * 1024); @fclose($handle); if (!$data) return null;
		$lat = $this->xmpValue($data, 'GPSLatitude'); $lon = $this->xmpValue($data, 'GPSLongitude');
		if ($lat === null || $lon === null) return null;
		$lat = $this->coordinate($lat); $lon = $this->coordinate($lon);
		return $this->validGps($lat, $lon) ? array($lat, $lon) : null;
	}

	private function xmpValue($data, $name) {
		$quoted = preg_quote($name, '/');
		if (preg_match('/(?:exif|exifEX|photoshop):'.$quoted.'\s*=\s*["\']([^"\']+)["\']/i', $data, $m)) return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
		if (preg_match('/<(?:exif|exifEX|photoshop):'.$quoted.'[^>]*>([^<]+)</i', $data, $m)) return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
		return null;
	}

	private function getId3Gps($file) {
		try {
			if (!class_exists('getID3')) { if (!defined('SDK_DIR') || !is_file(SDK_DIR.'/getID3/getid3/getid3.php')) return null; require_once SDK_DIR.'/getID3/getid3/getid3.php'; }
			$info = (new getID3())->analyze($file, @filesize($file), basename($file));
			$lat = _get($info, 'quicktime.comments.gps_latitude.0', _get($info, 'comments.gps_latitude.0'));
			$lon = _get($info, 'quicktime.comments.gps_longitude.0', _get($info, 'comments.gps_longitude.0'));
			$lat = $this->coordinate($lat); $lon = $this->coordinate($lon);
			return $this->validGps($lat, $lon) ? array($lat, $lon) : null;
		} catch (Throwable $e) { return null; }
	}

	private function validGps($lat, $lon) { return $lat !== null && $lon !== null && $lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180; }

	private function fraction($value) {
		if (is_numeric($value)) return floatval($value);
		$bits = explode('/', (string)$value, 2);
		return count($bits) === 2 && floatval($bits[1]) != 0.0 ? floatval($bits[0]) / floatval($bits[1]) : 0.0;
	}

	private function isImage($ext) {
		return in_array(strtolower($ext), array('jpg','jpeg','png','gif','webp','avif','heic','heif','bmp'));
	}

	private function isVideo($ext) { return in_array(strtolower($ext), array('mp4','mov','m4v','webm','mkv','avi')); }

	private function localFile($path) {
		if (!is_file(PLUGIN_DIR.'fileThumb/app.php')) return false;
		try { return Action('fileThumbPlugin')->localFile($path); } catch (Throwable $e) { return false; }
	}
}
