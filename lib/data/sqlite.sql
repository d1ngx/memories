CREATE TABLE IF NOT EXISTS "plugin_memories_media" (
  "id" integer NOT NULL PRIMARY KEY AUTOINCREMENT,
  "userID" integer NOT NULL,
  "sourceID" integer NOT NULL,
  "fileID" integer NOT NULL,
  "name" varchar(250) NOT NULL,
  "ext" varchar(16) NOT NULL,
  "size" integer NOT NULL,
  "takenAt" integer NOT NULL,
  "width" integer NOT NULL DEFAULT 0,
  "height" integer NOT NULL DEFAULT 0,
  "latitude" decimal(11,8) DEFAULT NULL,
  "longitude" decimal(11,8) DEFAULT NULL,
  "favorite" integer NOT NULL DEFAULT 0,
  "archived" integer NOT NULL DEFAULT 0,
  "sourceModifyTime" integer NOT NULL,
  "createTime" integer NOT NULL,
  "modifyTime" integer NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS "idx_memories_user_source" ON "plugin_memories_media" ("userID","sourceID");
CREATE INDEX IF NOT EXISTS "idx_memories_timeline" ON "plugin_memories_media" ("userID","archived","takenAt");
CREATE INDEX IF NOT EXISTS "idx_memories_favorite" ON "plugin_memories_media" ("userID","favorite");

CREATE TABLE IF NOT EXISTS "plugin_memories_album" (
  "id" integer NOT NULL PRIMARY KEY AUTOINCREMENT,
  "userID" integer NOT NULL,
  "name" varchar(120) NOT NULL,
  "description" varchar(500) NOT NULL DEFAULT '',
  "createTime" integer NOT NULL,
  "modifyTime" integer NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS "idx_memories_album_user_name" ON "plugin_memories_album" ("userID","name");
CREATE INDEX IF NOT EXISTS "idx_memories_album_user_modify" ON "plugin_memories_album" ("userID","modifyTime");

CREATE TABLE IF NOT EXISTS "plugin_memories_album_item" (
  "id" integer NOT NULL PRIMARY KEY AUTOINCREMENT,
  "albumID" integer NOT NULL,
  "userID" integer NOT NULL,
  "sourceID" integer NOT NULL,
  "createTime" integer NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS "idx_memories_album_source" ON "plugin_memories_album_item" ("albumID","sourceID");
CREATE INDEX IF NOT EXISTS "idx_memories_album_item_user" ON "plugin_memories_album_item" ("userID","albumID");
CREATE INDEX IF NOT EXISTS "idx_memories_album_item_source" ON "plugin_memories_album_item" ("sourceID");
