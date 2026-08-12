CREATE TABLE IF NOT EXISTS `plugin_memories_media` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `userID` bigint(20) unsigned NOT NULL,
  `sourceID` bigint(20) unsigned NOT NULL,
  `fileID` bigint(20) unsigned NOT NULL,
  `name` varchar(250) NOT NULL,
  `ext` varchar(16) NOT NULL,
  `size` bigint(20) unsigned NOT NULL,
  `takenAt` int(11) unsigned NOT NULL,
  `width` int(11) unsigned NOT NULL DEFAULT 0,
  `height` int(11) unsigned NOT NULL DEFAULT 0,
  `latitude` decimal(11,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `favorite` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `archived` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `sourceModifyTime` int(11) unsigned NOT NULL,
  `createTime` int(11) unsigned NOT NULL,
  `modifyTime` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_source` (`userID`,`sourceID`),
  KEY `timeline` (`userID`,`archived`,`takenAt`),
  KEY `favorite` (`userID`,`favorite`),
  KEY `ext` (`ext`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `plugin_memories_album` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `userID` bigint(20) unsigned NOT NULL,
  `name` varchar(120) NOT NULL,
  `description` varchar(500) NOT NULL DEFAULT '',
  `createTime` int(11) unsigned NOT NULL,
  `modifyTime` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_name` (`userID`,`name`),
  KEY `user_modify` (`userID`,`modifyTime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `plugin_memories_album_item` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `albumID` bigint(20) unsigned NOT NULL,
  `userID` bigint(20) unsigned NOT NULL,
  `sourceID` bigint(20) unsigned NOT NULL,
  `createTime` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `album_source` (`albumID`,`sourceID`),
  KEY `user_album` (`userID`,`albumID`),
  KEY `source` (`sourceID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `plugin_memories_metadata_state` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `userID` bigint(20) unsigned NOT NULL,
  `sourceID` bigint(20) unsigned NOT NULL,
  `fingerprint` varchar(64) NOT NULL,
  `modifyTime` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_source` (`userID`,`sourceID`),
  KEY `user_fingerprint` (`userID`,`fingerprint`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
