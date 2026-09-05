# PageRating

一个用于 MediaWiki 的文章评分（投票）扩展：为文章提供六档加权投票、可分组与锁定的评分排行、
以及高度可定制的 `{{投票}}` 模板。

兼容 MediaWiki **≥ 1.42**（当前开发目标 1.46）。

## 功能特性

- **六档投票**：`+1`（出类拔萃）、`+0.5`（笔酣墨饱）、`0`（差强人意）、
  `-0.5`（千篇一律）、`-1`（平淡无味），外加「取消我的投票」。
  评分即时写入数据库，投票成功后页面自动刷新并定位到投票栏。
- **评分排行**：`Special:查看文章评分`（`Special:ViewRatings`）以表格展示全部已登记页面，
  评分自高到低排序（同分时按总票数）。表格列含各档得票数与总票数。
- **查看投票用户**：排行表中票数 > 0 的档位可点击，就地展开「谁投了这一档」的用户名单；
  票数为 0 的档位不可点击。
- **分组评分（1.1.0）**：文章使用子模板 `{{投票/X}}`（如 `{{投票/Crossover}}`）时，
  评分自动归入同名分组，在 `Special:查看文章评分/X`（如 `Special:ViewRatings/Crossover`）
  子页面汇总展示；`Special:查看文章评分` 只显示使用基础 `{{投票}}` 的页面。
- **锁票（1.2.0）**：管理员/行政员可在 `Special:LockVoting`（中文别名「锁票」「锁定投票」）
  搜索已登记页面并逐个锁定/解锁投票。锁定的页面：
  - 投票按钮变为「投票已截止」并禁用，hover 无任何效果；
  - `action=vote` API 直接拒绝；
  - widget 附带 `pagerating-widget--locked` 类，可自行定制样式。
- **批量停止投票（1.2.0）**：`Special:BatchLockVoting`（中文别名「批量停止投票」「批量锁票」）
  列出所有**实际有投票页面**的 `{{投票/X}}` 子模板分组，可一键锁定/解锁整组。
- **自动登记与自愈清理**：
  - 页面首次渲染含投票模板时自动写入登记表（渲染兜底，不依赖保存钩子）；
  - `Special:查看文章评分` 渲染时惰性检查每个页面的最新 wikitext——
    模板已被删除、页面被移动/重命名的记录会自动取消登记并从排行中移除。
- **分组自动归属「页面投票」**：三个特殊页统一归入自定义分组（`specialpages-group-pagerating`），
  在 `Special:特殊页面` 中显示为「页面投票」标题。
- **深色主题友好**：样式基于 CSS 自定义属性，可整站覆盖。

## 评分公式

```
{[(投+1人数) − (投−1人数)] + [(投+0.5人数) − (投−0.5人数)] × 0.5}
──────────────────────────────────────────────────────────────────
                  (总投票人数，含投 0 的人数)
```

## 特殊页面

| 特殊页（英文名） | 中文别名 | 权限 | 说明 |
| --- | --- | --- | --- |
| `Special:ViewRatings` | `Special:查看文章评分` | 所有用户可看 | 基础 `{{投票}}` 页面排行；`/X` 子页面为对应分组排行 |
| `Special:ViewRatings/X` | `Special:查看文章评分/X` | 所有用户可看 | 子模板 `{{投票/X}}` 页面的分组排行 |
| `Special:LockVoting` | `Special:锁票` / `Special:锁定投票` | `protect`（管理员/行政员） | 搜索已登记页面并锁定/解锁投票 |
| `Special:BatchLockVoting` | `Special:批量停止投票` / `Special:批量锁票` | `protect` | 按子模板分组一键锁定/解锁整组 |

> 注：`LockVoting` / `BatchLockVoting` 需要 `protect` 权限，因此只会在**管理员登录**时出现在
> `Special:特殊页面` 的「页面投票」分组中（MediaWiki 会过滤普通用户无权访问的特殊页，属标准行为）。

## API

| 端点 | 方法 | 鉴权 | 说明 |
| --- | --- | --- | --- |
| `action=vote` | POST | CSRF token | 投票/改投/取消（`value=100` 为取消），需登录且页面未锁定 |
| `action=voters` | GET | 只读 | 返回某页面某档位的投票用户名单（供排行页展开） |

参数校验使用 MediaWiki 标准的 `ParamValidator`（整型范围通过 `IntegerDef::PARAM_MIN/MAX` 声明）。

## 数据库

由 `update.php` 通过 `LoadExtensionSchemaUpdates` 自动维护（或手动执行 `sql/page_rating.sql`）：

- **`page_rating_pages`** — 页面登记表
  `page_id`、`page_namespace`、`page_title`、`pr_group`（分组，空串=基础模板）、
  `pr_locked`（1.2.0 锁票标记）、`registered_at`、`last_vote_at`、`vote_count`。
- **`page_rating_votes`** — 投票记录表（每人每页一行）
  投票记录即使移除模板也**保留**，便于审计；取消投票即删除该行。

升级补丁：`sql/patch_add_pr_group.sql`（1.1.0）、`sql/patch_add_pr_locked.sql`（1.2.0）。

## 配置项

| 配置 | 默认值 | 说明 |
| --- | --- | --- |
| `$wgPageRatingTemplateName` | `'Vote'` | 基础模板名（不含 `Template:` 前缀），中文站通常设为 `'投票'` |
| `$wgPageRatingSpecialPagePath` | `'查看文章评分'` | `Special:ViewRatings` 的友好 URL 别名 |
| `$wgPageRatingAnonAllowed` | `false` | 是否允许匿名用户投票 |
| `$wgPageRatingSelfVoteAllowed` | `true` | 是否允许页面作者给自己投票 |

## 安装

完整安装步骤（建表、建模板、权限配置、升级与故障排查）见
[`docs/INSTALL.md`](docs/INSTALL.md)。核心三步：

1. 将 `PageRating/` 拷贝到 MediaWiki `extensions/` 目录，并在 `LocalSettings.php` 执行：
   ```php
   wfLoadExtension( 'PageRating' );
   $wgPageRatingTemplateName = '投票'; // 中文站
   ```
2. 运行 `php maintenance/run.php update.php` 建表。
3. 把 [`templates/Template-Vote.wikitext`](templates/Template-Vote.wikitext) 的内容
   创建为 `Template:投票`（或 `Template:Vote`）并保存。

## 使用模板

```
{{投票}}                                                        ← 默认外观
{{投票|Clipboard_Screenshot.png|标题一|标题二}}                 ← 背景图 + 两行文字
{{投票|background=https://example.com/bg.webp|title1=A|title2=B}} ← 远程图片
```

- 放在任意文章页即自动登记并显示投票卡片。
- 子分组：页面使用 `{{投票/Crossover}}`（把模板创建为
  `Template:投票/Crossover`，内容与基础模板一致）时，该页归入 `Crossover` 分组。

## 自定义样式

样式集中在 [`resources/ext.pageRating.css`](resources/ext.pageRating.css)，
视觉变量全部以 CSS 自定义属性（`--pr-*`）暴露，可在站点 `MediaWiki:Common.css`
（无 sanitizer 的入口）直接覆盖：

```css
:root {
  --pr-accent: #ff5577;
  --pr-bg-image: linear-gradient(135deg, #4a90e2, #1b3a6f);
}
```

锁定的投票栏可用 `.pagerating-widget--locked` 进一步定制。

## 维护备注

- **注册不依赖保存钩子**：MW 1.45+ 移除了 `PageSaveComplete` 钩子的可靠触发，
  因此登记由「widget 渲染兜底 + 排行页惰性清理」共同保证，删除模板后无需额外动作。
- 修改 `i18n/*.json` 后需运行 `php maintenance/run.php rebuildLocalisationCache.php --force`。
- 修改 PHP 代码后若站点开启 opcache，需重启（而非仅 reload）PHP-FPM/Apache 使改动生效。

## 许可证

MIT。
