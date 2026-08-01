<?php

class memoriesPlugin extends PluginBase {
	private $tableName = 'plugin_memories_media';
	private $albumTable = 'plugin_memories_album';
	private $albumItemTable = 'plugin_memories_album_item';
	private $imageExt = array('jpg','jpeg','png','gif','webp','avif','heic','heif','bmp');
	private $videoExt = array('mp4','mov','m4v','webm','mkv','avi');
	private $recognizeTrace = array();

	public function __construct() {
		parent::__construct();
	}

	public function regist() {
		$this->hookRegist(array('user.commonJs.insert' => 'memoriesPlugin.echoJs'));
	}

	public function echoJs() {
		$this->echoFile('static/main.js');
	}

	public function onChangeStatus($status) {
		if ($status) $this->initTable();
		$this->updateTask($status ? 1 : 0);
	}

	public function onSetConfig($config) {
		$this->initTable();
		$this->updateTask(1);
		return $config;
	}

	public function onUninstall() {
		$task = Model('SystemTask')->findByKey('event', $this->pluginName.'Plugin.task');
		if ($task) Model('SystemTask')->remove($task['id'], true);
	}

	public function index() {
		$this->initTable();
		include($this->pluginPath.'static/page.html');
	}

	public function initTable() {
		$tables = Model()->db()->getTables();
		$required = array($this->tableName, $this->albumTable, $this->albumItemTable);
		if (!array_diff($required, $tables)) return true;
		$file = stristr($GLOBALS['config']['database']['DB_TYPE'], 'sqlite') ? 'sqlite.sql' : 'mysql.sql';
		foreach (sqlSplit(file_get_contents($this->pluginPath.'lib/data/'.$file)) as $sql) {
			if (trim($sql)) Model()->db()->execute($sql);
		}
		return true;
	}

	private function updateTask($enable) {
		$event = $this->pluginName.'Plugin.task';
		$task = Model('SystemTask')->findByKey('event', $event);
		$data = array(
			'name' => LNG('memories.meta.name'), 'type' => 'method', 'event' => $event,
			'time' => '{"type":"minute","month":"1","week":"1","day":"00:00","minute":"15"}',
			'desc' => LNG('memories.meta.desc'), 'enable' => $enable, 'system' => 1,
		);
		if ($task) return Model('SystemTask')->update($task['id'], array('name' => $data['name'], 'enable' => $enable));
		return Model('SystemTask')->add($data);
	}

	public function task() {
		$config = $this->getConfig();
		return $this->scanSources(null, max(1, min(500, intval(_get($config, 'scanBatch', 100)))));
	}

	public function scan() {
		$stage = 'request';
		$GLOBALS['SHOW_OUT_EXCEPTION'] = true;
		$GLOBALS['SHOW_OUT_PLUGINS'] = false;
		try {
			$this->checkRequest('POST');
			$stage = 'config';
			$config = $this->getConfig();
			$limit = max(1, min(500, intval(_get($config, 'scanBatch', 100))));
			$stage = 'scan';
			$result = $this->scanSources(KodUser::id(), $limit);
		} catch (Throwable $e) {
			unset($GLOBALS['SHOW_OUT_EXCEPTION'], $GLOBALS['SHOW_OUT_PLUGINS']);
			$message = 'Memories index ['.$stage.']: '.$e->getMessage();
			$this->log($message.' @ '.$e->getFile().':'.$e->getLine());
			return show_json(array(
				'message' => $message,
				'location' => basename($e->getFile()).':'.$e->getLine(),
			), false);
		}
		unset($GLOBALS['SHOW_OUT_EXCEPTION'], $GLOBALS['SHOW_OUT_PLUGINS']);
		return show_json($result);
	}

	private function scanSources($userID, $limit) {
		$this->initTable();
		$this->recognizeTrace = array('attempted' => 0, 'queued' => 0, 'hookCalls' => 0, 'directCalls' => 0, 'noReceiver' => 0, 'errors' => array());
		if (!in_array($this->tableName, Model()->db()->getTables())) {
			throw new Exception('Index table was not created: '.$this->tableName);
		}
		$config = $this->getConfig();
		$extensions = array_values(array_filter(array_map('strtolower', explode(',', _get($config, 'fileExt', implode(',', array_merge($this->imageExt, $this->videoExt)))))));
		$where = array('targetType' => 1, 'isFolder' => 0, 'isDelete' => 0, 'fileType' => array('in', $extensions));
		if ($userID !== null) $where['targetID'] = intval($userID);
		$sources = Model('Source')->where($where)->order('modifyTime desc')->select();
		if ($sources === false || !is_array($sources)) throw new Exception('Unable to read KodBox media sources');
		$processed = 0; $skipped = 0; $recognizeQueued = 0;
		foreach ($sources as $source) {
			$existing = Model($this->tableName)->where(array('userID' => $source['targetID'], 'sourceID' => $source['sourceID']))->find();
			if ($existing && intval($existing['sourceModifyTime']) === intval($source['modifyTime'])) {
				$skipped++;
				if ($this->recognizeDispatch('memories.media.discovered', $existing)) $recognizeQueued++;
				continue;
			}
			if ($this->indexSource($source, $existing)) $recognizeQueued++;
			$processed++;
			if ($processed >= $limit) break;
		}
		$removed = $this->cleanupMissing($userID, $sources);
		$this->recognizeTrace['queued'] = $recognizeQueued;
		$this->recognizeTrace['sourceTotal'] = count($sources);
		$this->recognizeTrace['processed'] = $processed;
		$this->recognizeTrace['skipped'] = $skipped;
		write_log('[memories.scan] '.json_encode_force($this->recognizeTrace), 'recognize', 'info');
		return array(
			'processed' => $processed,
			'skipped' => $skipped,
			'recognizeQueued' => $recognizeQueued,
			'recognizeTrace' => $this->recognizeTrace,
			'removed' => $removed,
			'total' => count($sources),
			'remainingHint' => max(0, count($sources) - $skipped - $processed),
		);
	}

	private function cleanupMissing($userID, $sources) {
		$active = array();
		foreach ((array)$sources as $source) {
			$active[intval($source['targetID']).':'.intval($source['sourceID'])] = true;
		}
		$where = array();
		if ($userID !== null) $where['userID'] = intval($userID);
		$indexed = Model($this->tableName)->where($where)->field('id,userID,sourceID')->select();
		$removed = 0;
		foreach ((array)$indexed as $item) {
			$key = intval($item['userID']).':'.intval($item['sourceID']);
			if (isset($active[$key])) continue;
			Model($this->tableName)->where(array('id' => intval($item['id'])))->delete();
			if (in_array($this->albumItemTable, Model()->db()->getTables())) Model($this->albumItemTable)->where(array('userID' => intval($item['userID']), 'sourceID' => intval($item['sourceID'])))->delete();
			$removed++;
			try { Hook::trigger('memories.media.removed', $item); } catch (Throwable $e) {
				$this->log('memories remove hook error='.$e->getMessage());
			}
		}
		return $removed;
	}

	private function indexSource($source, $existing) {
		$meta = $this->indexer()->metadata($source);
		$data = array_merge($meta, array(
			'userID' => intval($source['targetID']), 'sourceID' => intval($source['sourceID']), 'fileID' => intval($source['fileID']),
			'name' => $source['name'], 'ext' => strtolower($source['fileType']), 'size' => intval($source['size']),
			'sourceModifyTime' => intval($source['modifyTime']), 'modifyTime' => time(),
		));
		$model = Model($this->tableName); $model->setDataAuto(false);
		if ($existing) {
			unset($data['favorite'], $data['archived']);
			$model->where(array('id' => $existing['id']))->save($data);
		} else {
			$data['favorite'] = 0; $data['archived'] = 0; $data['createTime'] = time();
			$model->add($data);
		}
		return $this->recognizeDispatch('memories.media.indexed', $data);
	}

	private function recognizeDispatch($event, $media) {
		$this->recognizeTrace['attempted']++;
		try {
			$events = Hook::get();
			$listeners = isset($events[$event]) && is_array($events[$event]) ? count($events[$event]) : 0;
			if ($listeners > 0) {
				$this->recognizeTrace['hookCalls']++;
				return (bool)Hook::trigger($event, $media);
			}
			$pluginFile = PLUGIN_DIR.'recognize/app.php';
			if (is_file($pluginFile)) {
				$this->recognizeTrace['directCalls']++;
				$method = $event === 'memories.media.indexed' ? 'onMediaIndexed' : 'onMediaDiscovered';
				return (bool)Action('recognizePlugin')->{$method}($media);
			}
			$this->recognizeTrace['noReceiver']++;
			return false;
		} catch (Throwable $e) {
			$error = $event.' source='._get($media, 'sourceID', 0).' '.$e->getMessage();
			if (count($this->recognizeTrace['errors']) < 20) $this->recognizeTrace['errors'][] = $error;
			write_log('[memories.dispatch] '.$error, 'recognize', 'error');
			return false;
		}
	}

	public function listData() {
		$this->initTable();
		$page = max(1, intval(_get($this->in, 'page', 1)));
		$pageNum = max(20, min(500, intval(_get($this->in, 'pageNum', 120))));
		$view = _get($this->in, 'view', 'timeline');
		$where = array('userID' => KodUser::id());
		if ($view === 'archive') $where['archived'] = 1;
		elseif ($view !== 'album') $where['archived'] = 0;
		if ($view === 'favorites') $where['favorite'] = 1;
		if ($view === 'photos') $where['ext'] = array('in', $this->imageExt);
		if ($view === 'videos') $where['ext'] = array('in', $this->videoExt);
		$query = trim(_get($this->in, 'q', ''));
		if ($query !== '') {
			$searchIDs = $this->searchSourceIDs($query);
			if ($searchIDs) {
				$searchWhere = array(array('name' => array('like', '%'.$query.'%')), array('sourceID' => array('in', $searchIDs)), '_logic' => 'OR');
				$where[] = $searchWhere;
			} else {
				$where['name'] = array('like', '%'.$query.'%');
			}
		}
		$year = intval(_get($this->in, 'year', 0));
		if ($year >= 1970) $where['takenAt'] = array('between', array(strtotime($year.'-01-01'), strtotime(($year + 1).'-01-01') - 1));
		$tag = trim(_get($this->in, 'tag', ''));
		if ($tag) {
			$tables = Model()->db()->getTables();
			if (!in_array('plugin_recognize_tag', $tables)) return show_json(array('list' => array(), 'pageInfo' => array('totalNum' => 0)));
			$tagRows = Model('plugin_recognize_tag')->where(array('userID' => KodUser::id(), 'tag' => $tag))->field('sourceID')->select();
			$ids = array(); foreach ((array)$tagRows as $row) $ids[] = intval($row['sourceID']);
			if (!$ids) return show_json(array('list' => array(), 'pageInfo' => array('totalNum' => 0)));
			$where['sourceID'] = array('in', $ids);
		}
		$personID = intval(_get($this->in, 'personID', 0));
		if ($personID > 0) {
			$tables = Model()->db()->getTables();
			if (!in_array('plugin_recognize_face', $tables) || !in_array('plugin_recognize_person', $tables)) return show_json(array('list' => array(), 'pageInfo' => array('totalNum' => 0)));
			$person = Model('plugin_recognize_person')->where(array('id' => $personID, 'userID' => KodUser::id()))->find();
			if (!$person) return show_json(array('list' => array(), 'pageInfo' => array('totalNum' => 0)));
			$faceRows = Model('plugin_recognize_face')->where(array('userID' => KodUser::id(), 'clusterID' => $personID))->field('sourceID')->select();
			$ids = array(); foreach ((array)$faceRows as $row) $ids[intval($row['sourceID'])] = true;
			if (!$ids) return show_json(array('list' => array(), 'pageInfo' => array('totalNum' => 0)));
			$where['sourceID'] = array('in', array_keys($ids));
		}
		$albumID = intval(_get($this->in, 'albumID', 0));
		if ($albumID > 0) {
			$album = Model($this->albumTable)->where(array('id' => $albumID, 'userID' => KodUser::id()))->find();
			if (!$album) return show_json(array('list' => array(), 'pageInfo' => array('totalNum' => 0)));
			$rows = Model($this->albumItemTable)->where(array('albumID' => $albumID, 'userID' => KodUser::id()))->field('sourceID')->select();
			$ids = array(); foreach ((array)$rows as $row) $ids[] = intval($row['sourceID']);
			if (!$ids) return show_json(array('list' => array(), 'pageInfo' => array('totalNum' => 0)));
			$where['sourceID'] = array('in', $ids);
		}
		$result = Model($this->tableName)->where($where)->order('takenAt desc,id desc')->selectPage($pageNum, $page);
		if ($view === 'thisday') {
			$monthDay = date('m-d');
			$result['list'] = array_values(array_filter((array)$result['list'], function($item) use ($monthDay) { return date('m-d', $item['takenAt']) === $monthDay; }));
		}
		if ($view === 'places') {
			$result['list'] = array_values(array_filter((array)$result['list'], function($item) { return $item['latitude'] !== null && $item['latitude'] !== ''; }));
		}
		if (!isset($result['list']) || !is_array($result['list'])) $result['list'] = array();
		$sourceIDs = array(); foreach ($result['list'] as $item) $sourceIDs[] = intval($item['sourceID']);
		$tags = $this->recognizeTags($sourceIDs);
		foreach ($result['list'] as &$item) {
			$item['tags'] = _get($tags, $item['sourceID'], array());
			$item['isVideo'] = in_array($item['ext'], $this->videoExt);
			$item['path'] = KodIO::make(intval($item['sourceID']));
			$item['day'] = date('Y-m-d', $item['takenAt']);
			$item['thumb'] = $this->thumbnailUrl($item, 250);
			$item['preview'] = $this->thumbnailUrl($item, 1200);
			$item['fallbackThumb'] = $this->signedUrl('thumb', $item['sourceID']);
			$item['original'] = $this->signedUrl('original', $item['sourceID']);
		}
		unset($item);
		show_json($result);
	}

	private function searchSourceIDs($query) {
		$tables = Model()->db()->getTables(); $ids = array(); $queryLower = function_exists('mb_strtolower') ? mb_strtolower($query, 'UTF-8') : strtolower($query);
		if (in_array('plugin_recognize_tag', $tables)) {
			$rows = Model('plugin_recognize_tag')->where(array('userID' => KodUser::id()))->field('sourceID,tag')->select();
			foreach ((array)$rows as $row) {
				$tag = (string)$row['tag']; $label = $this->tagLabel($tag); $haystack = function_exists('mb_strtolower') ? mb_strtolower($tag.' '.$label, 'UTF-8') : strtolower($tag.' '.$label);
				if (strpos($haystack, $queryLower) !== false) $ids[intval($row['sourceID'])] = true;
			}
		}
		if (in_array('plugin_recognize_person', $tables) && in_array('plugin_recognize_face', $tables)) {
			$people = Model('plugin_recognize_person')->where(array('userID' => KodUser::id(), 'title' => array('like', '%'.$query.'%')))->field('id')->select();
			$personIDs = array(); foreach ((array)$people as $person) $personIDs[] = intval($person['id']);
			if ($personIDs) { $faces = Model('plugin_recognize_face')->where(array('userID' => KodUser::id(), 'clusterID' => array('in', $personIDs)))->field('sourceID')->select(); foreach ((array)$faces as $face) $ids[intval($face['sourceID'])] = true; }
		}
		return array_keys($ids);
	}

	public function albums() {
		$this->initTable(); $userID = KodUser::id();
		$albums = (array)Model($this->albumTable)->where(array('userID' => $userID))->order('modifyTime desc,id desc')->select();
		foreach ($albums as &$album) {
			$items = Model($this->albumItemTable)->where(array('albumID' => intval($album['id']), 'userID' => $userID))->order('id desc')->field('sourceID')->select();
			$album['count'] = count((array)$items); $album['cover'] = '';
			if ($items) { $sourceID = intval($items[0]['sourceID']); $media = Model($this->tableName)->where(array('userID' => $userID, 'sourceID' => $sourceID))->field('sourceID,sourceModifyTime')->find(); if ($media) $album['cover'] = $this->thumbnailUrl($media, 500); }
		}
		unset($album); show_json(array_values((array)$albums));
	}

	public function createAlbum() {
		$this->checkRequest('POST'); $this->initTable(); $name = $this->cleanAlbumName(Input::get('name', null, ''));
		if ($name === '') return show_json('请输入相册名称', false);
		if (Model($this->albumTable)->where(array('userID' => KodUser::id(), 'name' => $name))->find()) return show_json('已存在同名相册', false);
		$now = time(); $id = Model($this->albumTable)->add(array('userID' => KodUser::id(), 'name' => $name, 'description' => '', 'createTime' => $now, 'modifyTime' => $now));
		if (!$id) return show_json('创建相册失败', false); show_json(array('id' => intval($id), 'name' => $name));
	}

	public function renameAlbum() {
		$this->checkRequest('POST'); $album = $this->assertAlbum(intval(Input::get('albumID', 'require'))); $name = $this->cleanAlbumName(Input::get('name', null, ''));
		if ($name === '') return show_json('请输入相册名称', false);
		$exists = Model($this->albumTable)->where(array('userID' => KodUser::id(), 'name' => $name))->find(); if ($exists && intval($exists['id']) !== intval($album['id'])) return show_json('已存在同名相册', false);
		Model($this->albumTable)->where(array('id' => intval($album['id'])))->save(array('name' => $name, 'modifyTime' => time())); show_json(array('id' => intval($album['id']), 'name' => $name));
	}

	public function deleteAlbum() {
		$this->checkRequest('POST'); $album = $this->assertAlbum(intval(Input::get('albumID', 'require')));
		Model($this->albumItemTable)->where(array('albumID' => intval($album['id']), 'userID' => KodUser::id()))->delete(); Model($this->albumTable)->where(array('id' => intval($album['id'])))->delete(); show_json(array('id' => intval($album['id'])));
	}

	public function addToAlbum() {
		$this->checkRequest('POST'); $album = $this->assertAlbum(intval(Input::get('albumID', 'require'))); $sourceID = intval(Input::get('sourceID', 'require')); $this->assertMedia($sourceID);
		$where = array('albumID' => intval($album['id']), 'userID' => KodUser::id(), 'sourceID' => $sourceID); if (!Model($this->albumItemTable)->where($where)->find()) Model($this->albumItemTable)->add($where + array('createTime' => time()));
		Model($this->albumTable)->where(array('id' => intval($album['id'])))->save(array('modifyTime' => time())); show_json(array('albumID' => intval($album['id']), 'sourceID' => $sourceID));
	}

	public function removeFromAlbum() {
		$this->checkRequest('POST'); $album = $this->assertAlbum(intval(Input::get('albumID', 'require'))); $sourceID = intval(Input::get('sourceID', 'require'));
		Model($this->albumItemTable)->where(array('albumID' => intval($album['id']), 'userID' => KodUser::id(), 'sourceID' => $sourceID))->delete(); Model($this->albumTable)->where(array('id' => intval($album['id'])))->save(array('modifyTime' => time())); show_json(array('albumID' => intval($album['id']), 'sourceID' => $sourceID));
	}

	private function assertAlbum($albumID) { $this->initTable(); $album = Model($this->albumTable)->where(array('id' => $albumID, 'userID' => KodUser::id()))->find(); if (!$album) show_json('相册不存在或无权访问', false); return $album; }
	private function cleanAlbumName($name) { $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$name)); if ((function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name)) > 120) show_json('相册名称不能超过 120 个字符', false); return $name; }

	public function tags() {
		$this->initTable();
		if (!in_array('plugin_recognize_tag', Model()->db()->getTables())) return show_json(array());
		$rows = Model('plugin_recognize_tag')->where(array('userID' => KodUser::id()))->field('tag,sourceID')->select();
		$counts = array(); $covers = array();
		foreach ((array)$rows as $row) {
			$tag = trim($row['tag']);
			if ($tag === '') continue;
			$counts[$tag] = isset($counts[$tag]) ? $counts[$tag] + 1 : 1;
			if (!isset($covers[$tag])) $covers[$tag] = intval($row['sourceID']);
		}
		arsort($counts);
		$result = array();
		$media = array();
		if ($covers) {
			$items = Model($this->tableName)->where(array('userID' => KodUser::id(), 'sourceID' => array('in', array_values($covers))))->field('sourceID,sourceModifyTime')->select();
			foreach ((array)$items as $item) $media[intval($item['sourceID'])] = $item;
		}
		foreach (array_slice($counts, 0, 100, true) as $tag => $count) {
			$sourceID = intval(_get($covers, $tag, 0));
			$cover = _get($media, $sourceID, false);
			$result[] = array('tag' => $tag, 'label' => $this->tagLabel($tag), 'count' => $count, 'sourceID' => $sourceID, 'cover' => $cover ? $this->thumbnailUrl($cover, 500) : '');
		}
		show_json($result);
	}

	public function people() {
		$this->initTable();
		$tables = Model()->db()->getTables();
		if (!in_array('plugin_recognize_person', $tables)) return show_json(array());
		$rows = Model('plugin_recognize_person')->where(array('userID' => KodUser::id()))->order('faceCount desc,id asc')->select();
		$result = array();
		foreach ((array)$rows as $row) {
			$id = intval($row['id']); $count = intval($row['faceCount']);
			$result[] = array('id' => $id, 'title' => trim($row['title']) ?: '人物 '.$id, 'count' => $count, 'cover' => $this->signedFaceUrl(intval($row['coverFaceID'])));
		}
		show_json($result);
	}

	public function renamePerson() {
		$this->checkRequest('POST');
		$personID = intval(Input::get('personID', 'require'));
		// Input::get() 的第二个参数是校验规则，不是默认值；名称允许留空以恢复默认名称。
		$title = trim((string)Input::get('title', null, ''));
		if (!in_array('plugin_recognize_person', Model()->db()->getTables())) return show_json('人物数据表不存在', false);
		$person = Model('plugin_recognize_person')->where(array('id' => $personID, 'userID' => KodUser::id()))->find();
		if (!$person) return show_json(LNG('common.notExists'), false);
		if (function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') > 60 : strlen($title) > 180) return show_json('人物名称不能超过 60 个字符', false);
		$title = preg_replace('/[\x00-\x1F\x7F]/u', '', $title);
		$result = Model('plugin_recognize_person')->where(array('id' => $personID, 'userID' => KodUser::id()))->save(array('title' => $title, 'modifyTime' => time()));
		if ($result === false) return show_json('保存人物名称失败', false);
		show_json(array('id' => $personID, 'title' => $title !== '' ? $title : '人物 '.$personID));
	}

	public function faceThumb() {
		$faceID = intval(Input::get('faceid', 'require'));
		if (!in_array('plugin_recognize_face', Model()->db()->getTables())) return $this->placeholder('image');
		$face = Model('plugin_recognize_face')->where(array('id' => $faceID, 'userID' => KodUser::id()))->find();
		if (!$face) return $this->placeholder('image');
		$this->assertMedia(intval($face['sourceID']));
		$path = KodIO::make(intval($face['sourceID']));
		if (!function_exists('imagecreatefromstring')) return IO::fileOutImage($path, 500);
		$image = @imagecreatefromstring(IO::getContent($path));
		if (!$image) return IO::fileOutImage($path, 500);
		$sourceWidth = imagesx($image); $sourceHeight = imagesy($image);
		$cx = (floatval($face['x']) + floatval($face['width']) / 2) * $sourceWidth;
		$cy = (floatval($face['y']) + floatval($face['height']) / 2) * $sourceHeight;
		$size = max(floatval($face['width']) * $sourceWidth, floatval($face['height']) * $sourceHeight) * 1.8;
		$size = max(32, min($size, min($sourceWidth, $sourceHeight)));
		$x = max(0, min($sourceWidth - $size, $cx - $size / 2)); $y = max(0, min($sourceHeight - $size, $cy - $size / 2));
		$output = imagecreatetruecolor(420, 420);
		imagecopyresampled($output, $image, 0, 0, intval($x), intval($y), 420, 420, intval($size), intval($size));
		header('Content-Type: image/jpeg'); header('Cache-Control: private, max-age=86400'); imagejpeg($output, null, 88);
		imagedestroy($output); imagedestroy($image); return;
	}

	public function toggle() {
		$this->checkRequest('POST');
		$sourceID = intval(Input::get('sourceID', 'require'));
		$field = Input::get('field', 'in', null, array('favorite', 'archived'));
		$item = Model($this->tableName)->where(array('userID' => KodUser::id(), 'sourceID' => $sourceID))->find();
		if (!$item) show_json(LNG('common.notExists'), false);
		$value = intval(!$item[$field]);
		Model($this->tableName)->where(array('id' => $item['id']))->save(array($field => $value, 'modifyTime' => time()));
		show_json(array('field' => $field, 'value' => $value));
	}

	public function thumb() {
		$sourceID = intval(Input::get('sourceid', 'require'));
		$source = $this->assertMedia($sourceID);
		$path = KodIO::make($sourceID);
		$ext = strtolower($source['fileType']);
		if (in_array($ext, $this->videoExt)) {
			return $this->placeholder('video');
		}
		if (in_array($ext, array('jpg', 'jpeg', 'png', 'bmp'))) return IO::fileOutImage($path, 500);
		if (in_array($ext, array('gif', 'webp', 'avif'))) return IO::fileOut($path);
		return $this->placeholder('image');
	}

	private function placeholder($type) {
		header('Content-Type: image/svg+xml; charset=utf-8');
		$shape = $type === 'video'
			? '<path d="M205 155l145 95-145 95z" fill="#fff"/>'
			: '<path d="M115 345l95-105 65 70 45-50 65 85z" fill="#fff"/><circle cx="175" cy="165" r="32" fill="#fff"/>';
		echo '<svg xmlns="http://www.w3.org/2000/svg" width="500" height="500" viewBox="0 0 500 500"><rect width="500" height="500" fill="#30343b"/>'.$shape.'</svg>';
		return;
	}

	public function original() {
		$sourceID = intval(Input::get('sourceid', 'require'));
		$this->assertMedia($sourceID);
		return IO::fileOut(KodIO::make($sourceID));
	}

	private function assertMedia($sourceID) {
		$item = Model('Source')->where(array('sourceID' => $sourceID, 'targetType' => 1, 'targetID' => KodUser::id(), 'isDelete' => 0, 'isFolder' => 0))->find();
		if (!$item) show_json(LNG('explorer.noPermissionAction'), false);
		return $item;
	}

	private function checkRequest($type = 'POST') {
		if (_get($_SERVER, 'REQUEST_METHOD', '') !== $type) {
			show_json(LNG('common.illegalRequest'), false);
		}
	}

	private function log($message) {
		write_log('[memories] '.$message, 'error');
	}

	private function signedUrl($method, $sourceID) {
		return Action('user.index')->apiSignMake('plugin/memories/'.$method, array('sourceid' => intval($sourceID)), false, '', true);
	}

	private function signedFaceUrl($faceID) {
		return Action('user.index')->apiSignMake('plugin/memories/faceThumb', array('faceid' => intval($faceID)), false, '', true);
	}

	private function thumbnailUrl($item, $width) {
		$param = array(
			'path' => KodIO::make(intval($item['sourceID'])),
			'etag' => intval($item['sourceModifyTime']),
			'width' => intval($width),
		);
		return Action('user.index')->apiSignMake('plugin/fileThumb/cover', $param);
	}

	private function recognizeTags($sourceIDs) {
		if (!$sourceIDs || !in_array('plugin_recognize_tag', Model()->db()->getTables())) return array();
		$rows = Model('plugin_recognize_tag')->where(array('userID' => KodUser::id(), 'sourceID' => array('in', $sourceIDs)))->field('sourceID,tag')->select();
		$result = array(); foreach ((array)$rows as $row) $result[$row['sourceID']][] = $this->tagLabel($row['tag']);
		return $result;
	}

	private function tagLabel($tag) {
		$tag = trim((string)$tag);
		if ($tag === '' || stripos(I18n::getType(), 'zh') !== 0) return $tag;
		static $translations = null;
		if ($translations === null) {
			$file = $this->pluginPath.'i18n/tags.zh-CN.php';
			$translations = is_file($file) ? include($file) : array();
			if (!is_array($translations)) $translations = array();
		}
		return isset($translations[$tag]) ? $translations[$tag] : $tag;
	}

	private function indexer() {
		include_once($this->pluginPath.'lib/MediaIndexer.class.php');
		return new MemoriesMediaIndexer($this);
	}
}
