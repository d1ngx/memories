# Nextcloud 到 KodBox 的适配说明

| 能力 | Nextcloud 实现 | KodBox 适配 |
|---|---|---|
| 文件标识 | `OCP\Files\Node` / fileid | `io_source.sourceID` / `KodIO::make()` |
| 权限边界 | 用户 Folder、Share、DAV | 每个媒体请求重新校验 `targetType=1`、`targetID=当前用户` |
| 时间线 | Memories timeline 数据表与查询构造器 | `plugin_memories_media`，按 `takenAt` 分页 |
| 私有相册 | Nextcloud Photos/DAV album | `plugin_memories_album` + `plugin_memories_album_item`，按用户隔离 |
| 元数据 | ExifTool 常驻进程 | PHP EXIF + KodBox 本地驱动，缺失时回退修改时间 |
| 缩略图 | Nextcloud Preview Provider | KodBox `fileThumb`，无插件时图片走 `IO::fileOutImage` |
| 智能标签 | Collaborative Tags + Recognize | `plugin_recognize_queue` / `plugin_recognize_tag` |
| 人脸与人物 | Recognize face detections / clusters | `plugin_recognize_face` / `plugin_recognize_person` |
| 后台处理 | Nextcloud BackgroundJob / OCC | KodBox `SystemTask` + 插件方法 |
| 前端入口 | Nextcloud Vue 导航应用 | KodBox 左侧菜单 + iframe 原生页面 |

## 设计选择

`memories` 和 `recognize` 保持为两个插件：图库在没有机器学习运行时的情况下仍可工作，识别模块也可以独立扩展模型。两者通过 KodBox Hook、标签表和按用户隔离的人脸/人物表连接。

Recognize 的模型及 Node 依赖接近 GB 级，适配层不复制这些内容，而是引用管理员配置的本地上游运行时目录。这样可避免双份模型、安装包膨胀和模型版本漂移。

## 当前范围

已移植照片/视频索引、EXIF 时间、GPS 元数据、时间线、复合搜索、收藏、归档、用户私有相册、视频筛选、对象识别队列、标签筛选、人脸检测及人物聚类。Nextcloud 特有的 DAV 公共相册、协作相册、系统标签写回、地点反查、Live Photo 与 HLS 尚未映射。
