# PUSHUP Lite

极简版俯卧撑训练记录 App。只留下真正每天会用的部分：**记录 · 数据 · 动态 · 我的**。

> 与完整版 PUSHUP 的区别：没有聊天、没有加好友、没有通知，**没有任何图片上传**。
> 包名 `com.pushup.lite`，可以和完整版同时装在一台手机上。

| 文档 | 内容 |
|---|---|
| **[HANDOFF.md](./HANDOFF.md)** | **交接文档：完整状态、架构、数据库、反代消耗账、踩过的坑、下一步** |
| [CHANGELOG.md](./CHANGELOG.md) | 版本更新日志 |
| [`supabase.sql`](./supabase.sql) | 建表脚本（6 张表 + 省流函数 `lite_bundle()`） |
| [`supabase-proxy/`](./supabase-proxy/) | 反代云函数源码（腾讯云 SCF） |
| [`tools/poll-bench.py`](./tools/poll-bench.py) | 反代请求量基准测试（不用真机） |

---

## 四个页面

| 页面 | 做什么 |
|---|---|
| **记录** | 每天打卡：俯卧撑个数、体重、臂围、备注，常用动作一按就填 |
| **数据** | 日历热力、累计统计、体重折线图 |
| **动态** | 发纯文字动态 + 今日成果快照、点赞、评论、我的动态、详情页 |
| **我的** | 资料（昵称 + emoji 头像）、训练概览、设置、更新日志 |

---

## 三个刻意做减法的地方

### 1. 头像是一个 emoji，不是图片

完整版的头像是 256×256 JPEG 存成 base64 塞进 `profiles.avatar`，每次同步都会把所有人的头像一起拉下来。
Lite 版改成 `profiles.emoji`，一个字符，24 个可选（💪🔥🐼🦾🏆…），四台设备换着用也一致。

### 2. 动态只发文字

完整版的动态图片是 720px JPEG base64 存在 `posts.images`，一条动态最多 3 张，最坏情况一次同步要拉十几 MB。
Lite 版直接不发图，`posts` 表也删掉了 `images` 和 `vis` 两列。

### 3. 同步请求砍到最低

| 项目 | 完整版 | Lite v1.1 |
|---|---|---|
| 轮询间隔 | 1 秒 | 8 秒 |
| 每轮请求次数 | 6 个 | **1 个**（`lite_bundle()` 一次返回 4 张表） |
| 查询字段 | `select=*`（连 base64 一起拉） | 显式列出需要的列 |
| 前台限制 | 无 | App 切后台就完全停 |
| 页面限制 | 无 | 只有停在动态相关页面才拉 |
| 停在动态页 10 分钟 | ~3720 次 | **~90 次** |
| 单次最坏体积 | ~29 MB | ~30 KB |

记录页、数据页、我的页**一个社区请求都不发**。

---

## 数据库

6 张表：`profiles`、`posts`、`post_likes`、`post_comments`、`records`、`settings`，外加 1 个省流函数 `lite_bundle()`。

在 Supabase 后台 **SQL Editor** 里整段执行 [`supabase.sql`](./supabase.sql) 即可。
脚本是幂等的，可以重复跑；里面也包含了从完整版升级过来的清理语句（会删 `messages` / `follows` 表，以及 `avatar` / `images` / `vis` 列）。

前置设置：**Authentication → Providers → Email** 里关掉 `Confirm email`，`Minimum password length` 调到 1。

---

## 部署反代（国内访问必需）

`supabase-proxy/scf/index.js` 是一个云函数，同时服务完整版和 Lite 版：

- 根路径 `/rest/v1/...` → 完整版的 Supabase 项目
- `/lite/rest/v1/...` → Lite 版的 Supabase 项目
- 上游地址可用环境变量 `UPSTREAM` / `UPSTREAM_LITE` 覆盖，不用改代码

部署：腾讯云 SCF 控制台 → 函数代码 → 把 `index.js` 整段粘贴 → 部署。
**换过 Supabase 项目时也要重新粘贴一次**（上游地址写在文件顶部）。

> 图片说明：App 内所有图片（开屏图标、favicon、PWA 图标）都是本地文件，没有联网图片、没有网络字体；头像是 emoji 字符。唯一外链是「关于作者」的酷安主页，点击才会跳转。

---

## 技术栈

- 单文件 `index.html`（内嵌 CSS + JS，无构建步骤）
- Supabase（Auth + PostgREST），经自建腾讯云 SCF 反代访问
- Capacitor 6 打包 Android，包名 `com.pushup.lite`
- Service Worker 离线缓存（`sw.js`）

## 打包

推 `main` 分支后 GitHub Actions 自动构建，产物见 Releases。
本地构建：

```bash
npx cap add android
npx cap sync android
cd android && ./gradlew assembleDebug
```
