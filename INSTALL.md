# Memories for KodBox 安装说明

## 环境和配套插件

- 已安装并可正常登录的 KodBox。
- 必需：KodBox `fileThumb` 插件，用于时间线缩略图和大图预览资源。
- 建议：KodBox PhotoSwipe 图片预览组件和 DPlayer 视频播放器。
- 可选：本项目 `recognize` 插件，用于对象、地标、人脸和人物聚类。
- 数据库支持 MySQL/MariaDB 与 SQLite，数据表由插件自动创建。

## 安装步骤

1. 将整个 `memories` 目录复制到 `kodbox/plugins/memories/`，最终应存在 `plugins/memories/app.php`。
2. 在管理后台启用 `fileThumb`，再启用“Memories”。
3. 在插件权限中选择允许使用的用户或用户组，并启用“添加到菜单”。
4. 打开 Memories，进入“照片设置”选择需要索引的个人空间目录；默认扫描个人空间支持的图片和视频。
5. 点击“更新索引”。首次扫描量大时可多次执行，之后由每 15 分钟运行一次的系统任务增量维护。
6. 双击图片应进入 PhotoSwipe，双击视频应进入 DPlayer；关闭播放器后声音必须停止。

## 与 Recognize 联动

安装并启用 `recognize` 后，Memories 更新索引会将图片发送到识别队列。对象与地标显示在“标签”，人脸聚类显示在“人物”。Recognize 默认每 5 分钟消费任务，也可在其设置页“立即处理一个任务”验证。

如果 Memories 已经完成索引、Recognize 后安装，请再次点击“更新索引”或在 Recognize 中执行“重新扫描所有图片”。

## 数据库和文件

插件会根据数据库类型执行 `lib/data/mysql.sql` 或 `lib/data/sqlite.sql`。媒体索引保存在 `plugin_memories_media`，私有相册保存在 `plugin_memories_album` 和 `plugin_memories_album_item`；不会复制原始照片，缩略图和缓存由 KodBox/fileThumb 管理。升级到 1.7.0 后首次打开 Memories 会自动补建相册表。

卸载或升级前建议备份 KodBox 数据库与 `data/`。升级插件代码时不要删除业务数据库表，除非明确希望清空索引。

## 排错

- 页面请求 `/plugins/memories/` 返回 403：应从 KodBox 菜单或 `#memories` 进入，不要直接访问插件目录。
- JSON 出现 `<style>` 或 HTML：检查请求是否使用 `/?plugin/memories/...` 路由，并检查 PHP 错误日志。
- 缩略图不显示：确认 `fileThumb` 已启用，响应 Content-Type 是图片，路径中有有效 `sourceID`。
- 图片双击空白：确认 PhotoSwipe 已加载且原图/大图接口可访问。
- 视频关闭后仍播放：清浏览器缓存，确认已部署最新版 `static/page.js`。
- 列表偶发重复：清缓存并重新更新索引；索引以用户和 `sourceID` 去重。
- 标签或人物为空：检查 Recognize 状态、队列和 `data/temp/log/recognize/` 日志。

## Docker 部署

若 KodBox 目录通过 bind mount 映射到容器，将插件复制到宿主机映射目录即可。确认容器内 PHP 用户对插件目录可读，对 KodBox `data/`、临时目录和数据库可写。更新静态文件后建议硬刷新浏览器。
