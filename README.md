# MiniBlog 单文件版

![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue)
![SQLite](https://img.shields.io/badge/SQLite-Yes-green)
![License](https://img.shields.io/badge/License-MIT-yellow)

一个只需上传 **1 个 PHP 文件**即可运行的博客系统。程序逻辑全部集中在 `index.php`，Editor.md 编辑器所需的 37 个资源文件已内嵌，首次访问自动写入服务器，无需单独部署前端资源。

## 功能特性

### 文章
- 发布、草稿、定时发布、回收站
- 父子分类、标签、相关文章
- 私密文章密码（bcrypt）
- 批量发布 / 转草稿 / 删除 / 改分类 / 加标签 / 去标签

### 编辑与阅读
- Editor.md Markdown 编辑器
- 表格、字号、颜色、行内标签
- highlight.js 代码高亮、Mermaid 流程图
- 自动保存草稿（localStorage，7 天）
- 目录 TOC、上一篇 / 下一篇、阅读进度条
- 浅色 / 深色主题、响应式布局、打印样式

### 互动
- 评论审核、回复层级、算术验证码
- 敏感词过滤、IP 黑名单、频率限制
- 新评论通知：邮件 / Server酱 / Telegram

### 媒体
- 图片自动压缩 WebP、生成缩略图、去除 EXIF
- 支持图片、RAR、EXE、文本等上传
- 文件管理：批量删除、引用保护、清理未引用

### 数据
- 完整 / 增量备份、定时自动备份
- 支持远程备份目录（NAS）
- `.zip` / `.db` 恢复
- 数据看板：PV / UV、热门文章 Top 10

### SEO 与安全
- sitemap.xml、robots.txt、RSS、OG、JSON-LD
- bcrypt 密码、CSRF 校验、安全响应头
- 操作日志（北京时间）

## 截图

```markdown
![首页](screenshots/home.png)
![后台](screenshots/admin.png)
```

## 在线演示

```markdown
[演示地址](https://你的演示地址)
```

## 环境要求

| 项目 | 要求 |
| --- | --- |
| PHP | 7.4+ |
| 必装扩展 | `pdo_sqlite` |
| 推荐扩展 | `gd`、`zip`、`mbstring` |
| 数据库 | SQLite（自动创建） |

## 快速开始

### 1. 上传

将 `index.php` 上传到站点根目录：

```text
/volume1/web/blog/index.php
```

### 2. 权限

```bash
chmod -R 755 /volume1/web/blog
chmod -R 775 /volume1/web/blog/data
chmod -R 775 /volume1/web/blog/uploads
```

### 3. 首次访问

浏览器打开站点地址，程序自动完成：

1. 创建 `data/`、`data/backup/`、`uploads/`
2. 写入内嵌的 37 个 `editormd/` 资源文件
3. 创建 SQLite 数据库
4. 进入安装页，设置管理员账号

### 4. 后台

```text
?admin=posts
```

## 技术栈

- PHP（单文件入口）
- SQLite
- Editor.md + CodeMirror
- highlight.js + Mermaid
- 原生 JavaScript，无框架依赖

## 目录结构

```text
blog/
├── index.php       # 单文件主程序
├── editormd/       # 自动写入的编辑器资源
├── data/           # 数据库与备份
├── uploads/        # 上传文件
└── .htaccess       # 数据目录保护
```

## 配置

`index.php` 顶部常量：

```php
define('SITE_NAME', 'MiniBlog');
define('POSTS_PER_PAGE', 10);
define('REMOTE_IMAGE_MAX_BYTES', 10 * 1024 * 1024);
```

后台“系统设置”可配置：

- 站点名称、描述、页脚
- 上传大小限制（MB）
- 允许上传后缀
- 通知方式
- 备份目录

上传大小同时受服务器限制：

```ini
upload_max_filesize = 200M
post_max_size = 200M
```

## 备份与恢复

- **立即备份到服务器**：生成快照，支持指定 NAS 目录
- **下载备份**：`.zip` 完整包（数据库 + 上传文件 + 程序文件）
- **恢复**：上传 `.zip` 或 `.db`，或直接恢复服务器快照

## FAQ

**编辑器资源没有生成？**

检查目录写权限、`open_basedir` 限制、PHP 错误日志。

**上传图片没有压缩？**

确认已启用 `gd` 扩展。

**修改后页面没变化？**

强制刷新：`Ctrl + F5`。

**备份到 NAS 失败？**

确认目录存在，且 PHP 用户（如 `http`）有读写权限。

## 贡献

欢迎提交 Issue 和 Pull Request。

```bash
git checkout -b feature/xxx
git commit -m "feat: xxx"
git push origin feature/xxx
```

## License

[MIT](LICENSE)
