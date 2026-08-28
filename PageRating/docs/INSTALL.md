# 安装指南 / Installation

本页给出 **PageRating** 扩展的完整安装步骤。
扩展兼容 MediaWiki ≥ 1.39。

## 1. 部署扩展文件

将整个 `PageRating/` 目录拷贝到你的 MediaWiki 安装的
`extensions/` 目录下，最终结构如下：

```
extensions/
└── PageRating/
    ├── extension.json
    ├── includes/
    ├── i18n/
    ├── resources/
    ├── sql/
    ├── templates/
    └── docs/
```

如果你是从 GitHub 安装，可以直接 `git clone`。

## 2. 注册扩展

在 `LocalSettings.php` 文件末尾添加：

```php
wfLoadExtension( 'PageRating' );

// 可选：自定义模板名（默认 Vote，对应 Template:Vote）
$wgPageRatingTemplateName = '投票';

// 可选：Special 页 URL 别名（默认 "查看文章评分"）
$wgPageRatingSpecialPagePath = '查看文章评分';

// 可选：是否允许匿名用户投票（默认 false）
$wgPageRatingAnonAllowed = false;

// 可选：是否允许页面作者给自己投票（默认 true）
$wgPageRatingSelfVoteAllowed = true;
```

完成后保存，然后执行：

```bash
php maintenance/run.php update.php
```

MediaWiki 会自动通过 `LoadExtensionSchemaUpdates` 创建
`page_rating_pages` 与 `page_rating_votes` 两张表。

如果你想手动建表，运行：

```bash
mysql -u<user> -p<pass> <dbname> < extensions/PageRating/sql/page_rating.sql
```

## 3. 创建 `Template:Vote` 页面

把 [`templates/Template-Vote.wikitext`](../templates/Template-Vote.wikitext)
的内容原样粘贴到 wiki 的 `Template:Vote`（中文 wiki 可命名为 `Template:投票`）
页面中并保存。

模板内容非常简单：

```wikitext
{{#pagerating:{{{background|{{{1|}}}}}}|{{{title1|{{{2|2025第一届}}}}}}|{{{title2|{{{3|优竞强化月}}}}}}}}
```

如果你的 wiki 主要是中文，也可以直接建一个 `Template:投票`
并让 `$wgPageRatingTemplateName = '投票'`。

## 4. 在页面中调用

最简单的方式：

```
{{Vote}}
```

带参数：

```
{{Vote
|background=Clipboard_Screenshot.png
|title1=2025第一届
|title2=优竞强化月
}}
```

或直接以位置参数传入：

```
{{Vote|Clipboard_Screenshot.png|2025第一届|优竞强化月}}
```

外链背景（不能直接用文件名时可用）：

```
{{Vote|background=https://example.com/bg.webp|title1=A|title2=B}}
```

## 5. 配置权限

投票默认需要登录。可以编辑 `LocalSettings.php` 控制：

```php
$wgPageRatingAnonAllowed = true;     // 允许匿名投票
$wgPageRatingSelfVoteAllowed = false; // 禁止自评
```

API 端点只有登录用户可调。
为非匿名投票，客户端会自动随 `mw.Api()` 携带 CSRF token。

## 6. Special 页

访问：

```
https://your-wiki/wiki/Special:查看文章评分
```

或（英文环境）：

```
https://your-wiki/wiki/Special:ViewRatings
```

表格列：

| 排名 | 文章 | 评分 | +1 | +0.5 | 0 | -0.5 | -1 | 总票数 |
| ---- | ---- | ---- | -- | ---- | - | ---- | -- | ------ |

排序：评分 DESC；同分时总票数 DESC。

## 7. 模板自动登记/移除

`Hooks::onArticleSaveComplete()` 会在每次页面保存时扫描 wikitext，
检测是否包含 `{{Vote|...}}` 或 `{{投票|...}}` 调用：

- **首次包含** → 在 `page_rating_pages` 创建记录。
- **之后又被移除** → 在 `page_rating_pages` 删除该页记录。

注意:

- 投票历史行（`page_rating_votes`）即使移除模板也仍保留，
  便于审计和恢复。要彻底清空请运行：
  ```sql
  DELETE FROM page_rating_votes WHERE page_id IN (
      SELECT page_id FROM page_rating_pages WHERE ...
  );
  ```
- 此扫描是**轻量文本扫描**，不解析 wikitext。
  支持形如 `{{Vote}}`、`{{Vote|...}}`、`{{Vote \n| ... }}` 的写法。
  允许空参数（已适配默认占位）。如果使用其他复杂模板别名，
  请在 `LocalSettings.php` 中显式声明：

  ```php
  $wgPageRatingTemplateName = 'MyVote';
  ```

## 8. 清除缓存

如果样式没有立刻生效，到 `Special:Purge` 清理缓存。
对样式做大幅修改后，推荐运行：

```bash
php maintenance/run.php rebuildFileCache.php --all
```

## 9. 故障排查

| 现象                                          | 可能原因                                                 |
| --------------------------------------------- | -------------------------------------------------------- |
| 模板出现但无投票接口                          | ResourceLoader 未加载；按 Ctrl+F5 强制刷新               |
| 点击后弹错「This page is not registered」     | 模板被加于非主命名空间；临时禁用 `hooks`(见 `Hooks.php`) |
| 评分始终为 0                                  | 没有投票记录；到 `Special:查看文章评分` 查看是否登记    |
| `Special:ViewRatings` 404                     | URL 别名问题；尝试 `Special:查看文章评分`               |
| API 提示 `pagerating-err-permission`          | 用户未登录且 `$wgPageRatingAnonAllowed` 为 false         |

## 10. 升级

直接替换 `extensions/PageRating/` 整个目录即可，
运行 `php maintenance/run.php update.php` 同步表结构。
DB schema 在不破坏旧版本兼容的前提下进行渐进式升级。

## 11. 卸载

```bash
php maintenance/run.php update.php --skip-confirmed
```

并在 `LocalSettings.php` 中注释掉 `wfLoadExtension( 'PageRating' )`。
如需彻底清理，运行：

```sql
DROP TABLE page_rating_votes;
DROP TABLE page_rating_pages;
```
