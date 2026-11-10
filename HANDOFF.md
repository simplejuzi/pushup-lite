# PUSHUP Lite · 交接文档

> 面向接手的 AI / 开发者。读完这份再动手，可以省掉至少两天踩坑时间。
> 最后更新：2026-11-10，对应版本 **v1.1**

---

## 0. 三十秒速览

| 项 | 内容 |
|---|---|
| 是什么 | 极简俯卧撑训练记录 Android App，单页 Web（`index.html`）+ Capacitor 壳 |
| 仓库 | `simplejuzi/pushup-lite`（GitHub） |
| 包名 | `com.pushup.lite`（可与完整版 `com.simplejuzi.pushup` 同机共存） |
| 云端 | Supabase 项目 `ctcqmjssqineuubadqty` |
| 网络方案 | **必须经自建腾讯云 SCF 反代**，直连 supabase.co 在国内被 TLS 阻断 |
| 页面 | 记录 · 数据 · 动态 · 我的（4 个 tab） |
| 当前状态 | 代码 v1.1 完成，**尚未构建过 APK**（自动构建已按要求关闭） |
| 最大风险点 | 反代调用次数 = 腾讯云免费额度，是这个项目唯一的成本命门 |

---

## 1. 这个项目和「完整版 PUSHUP」什么关系

完整版是同一个工作区里的 `../PUSHUP/`（版本走到 v1.28），功能齐全：聊天、加好友、通知、动态发图、头像上传（base64 塞数据库）。

问题：每次同步要把所有人的 base64 头像和动态图片一起拉下来，最坏一次同步约 **29 MB**，每 1 秒一次。国内走反代，等于拿云函数当流量黑洞。

所以复制了一份做 Lite：**只砍功能，不动核心体验**。Lite 与完整版是**两个独立项目、独立数据库、独立包名**，互不影响。完整版继续用老的 Supabase 项目和根路径反代。

---

## 2. 已完成的工作

### v1.0（首个版本，从完整版 v1.28 精简）

**删掉的整块功能**
- 聊天：`page-chats` / `page-chat` / 输入条 / 聊天图片 / 表情包
- 好友：`page-friends` / 关注·粉丝·找人三个 tab / 关注按钮 / 用户主页的私聊入口
- 通知：`page-notifs` / 消息红点 / 未读角标 / 点赞评论通知
- 图片：动态发图（九宫格预览、压缩）、头像上传、全屏图片查看器（手势缩放）
- 可见性：动态的「全部人可见 / 仅好友可见」开关

**连带的数据模型变化**
- `profiles.avatar`（base64 JPEG）→ `profiles.emoji`（单个字符）
- `posts` 删掉 `images`、`vis` 两列
- 数据库删掉 `follows`、`messages` 两张表
- 编辑资料页改成 **24 宫格 emoji 选择器** + 实时预览，默认 `💪`

**保留的能力**（都能正常用）
- 记录页：打卡、体重、臂围、备注、动作库、常用动作、悬浮保存球
- 数据页：日历热力、累计统计、体重折线（SVG 手绘，无图表库）
- 动态：纯文字 + 今日成果快照、点赞、评论、我的动态、详情页
- 账号：邮箱 + 密码登录注册；**账号被删自动退出**，不会「复活」
- 云同步：训练记录、设置、动态、点赞、评论
- 删动态时**三表联删**（`post_likes` → `post_comments` → `posts`）
- 导出 / 导入 JSON、CSV、清空数据、更新日志、检查更新

### v1.1（当前版本）

1. **切换独立云端**：新 Supabase 项目 `ctcqmjssqineuubadqty`，与完整版数据彻底分开
2. **反代多项目路由**：同一个云函数，根路径走完整版、`/lite` 走 Lite
3. **反代消耗砍 86%**：见第 7 节，从 ~630 次/10 分钟降到 ~90 次
4. 删除无引用的死文件 `icons/default-avatar.jpg`（18KB）
5. 关闭自动构建（本文件同批改动）

### 明确没做的

- **没有构建过 APK**。`build-apk.yml` 现在只剩手动触发（`workflow_dispatch`）
- 没有在真机上跑过 v1.1（v1.0 的页面交互已用无头浏览器验证过）
- 没有做 Supabase 新库的实测（需要用户先跑 `supabase.sql`）

---

## 3. 关键地址与凭据

这些值在设计上都是**可公开**的（publishable key 本来就会打进 APK），但仍建议只在私有渠道传。

| 用途 | 值 |
|---|---|
| Supabase URL | `https://ctcqmjssqineuubadqty.supabase.co` |
| publishable key | `sb_publishable_5YyGhtbw404N3whh9EhLRw_5DCQnEHT` |
| 反代基址（Lite） | `https://1301392226-ickz22vd03.ap-guangzhou.tencentscf.com/lite` |
| 反代基址（完整版） | 同上，去掉 `/lite` |
| GitHub 仓库 | `https://github.com/simplejuzi/pushup-lite` |
| 完整版 Supabase | `https://zqrlolyntlcewrwfhumd.supabase.co`（key 是 `sb_publishable_O7Am…`） |

这些常量在 `index.html` 第 3725~3735 行附近：

```js
const SB_URL_DEFAULT="https://ctcqmjssqineuubadqty.supabase.co";
const SB_ANON="sb_publishable_5YyGhtbw404N3whh9EhLRw_5DCQnEHT";
const SB_URL_RELAY="https://1301392226-ickz22vd03.ap-guangzhou.tencentscf.com/lite";
```

运行时实际用哪个：`SB_URL = localStorage["pushup_sb_url"] || SB_URL_RELAY`。也就是**默认走反代**，用户手动在设置里填过就用填的。

---

## 4. 架构：三层，一条数据流

```
index.html（本地状态 + 渲染）
   ↓ fetch（纯 fetch，无 supabase-js SDK、无构建步骤）
腾讯云 SCF 反代（国内可达，透传 header + CORS）
   ↓ https
Supabase（Auth 发 token + PostgREST 读写 public.*）
```

**为什么不用 supabase-js**：整个 App 是单文件 HTML，用 SDK 要打包。现在只用 `fetch` + 手写 header（`apikey` / `Authorization: Bearer`）。

**双模式**：`SB_URL` 或 `SB_ANON` 为空时进入**纯本地模式**，数据只在 localStorage，功能可以完整点动（预览版就是这样用的）。填了才走云端。

**数据流（关键）**：本地 localStorage 是**唯一真相**，云端是备份 + 多设备同步。所有写操作先落本地、再静默上行；读操作（轮询）以云端为准做全量替换，但保留「本机有、云端没有」的自己的记录（`mineOff` 机制，防止网络抖动把自己刚发的东西抹掉）。

---

## 5. 代码地图（`index.html`，约 5100 行，单文件）

无构建、无模块、全部函数挂在全局。改之前先 `Ctrl+F` 找函数名。

### 本地状态与渲染

| 函数 | 作用 |
|---|---|
| `sLoad()` / `sSave()` | 社区状态的读写（`SOC` 对象：users / session / posts / likes / comments / token） |
| `sMe()` / `sUser(id)` | 当前登录用户 / 按 id 取用户 |
| `sAv(u)` | 头像渲染，返回 `'<span>emoji</span>'` |
| `renderFeed()` | 动态列表（读 `sLoad().posts`，按 ts 倒序；空态文案在这里） |
| `renderPostDetail()` | 动态详情页 + 评论区自适应布局 |
| `renderMyPosts()` / `renderUserProfile()` | 我的动态 / 他人主页 |
| `postCardHtml(p, me)` | 单条动态卡片 HTML |
| `switchTab(name)` | 切页面，同时写 `CUR_TAB`（轮询靠它判断要不要同步） |
| `socialOnEnter(name)` / `refreshSocialUI()` | 进页面时的刷新钩子；后者会刷新「当前 on 的那个页」 |
| `applyTheme()` / `applyPalette()` | 主题与配色 |

### 云端

| 函数 | 作用 |
|---|---|
| `sbH()` | 组装请求头（apikey / Bearer / Prefer） |
| `sbFetch(p, o)` | GET，成功返 JSON、失败返 `null`；401 自动用 refresh_token 续期后重试一次 |
| `sbPush(t, row)` / `sbDel(t, q)` | 写 / 删 |
| `sbAuth(p, b)` | 调 `/auth/v1/*`（登录、注册、刷新） |
| `syncCloud()` | **核心同步**：见第 7 节，1 次 RPC 或降级 4 表，然后合并进 `sLoad()` |
| `pullFour()` | 老的 4 表并行拉取（降级路径 + RPC 未部署时用） |
| `startPolling()` / `pollSocial()` | 轮询（8 秒，`_polling` 锁防重入） |
| `syncEnabled()` | 是否该同步：有登录 + 有 token + 有云端配置 |
| `verifyAccount()` | 校验账号是否还存在（60 秒缓存，冷启动必验） |
| `kickAccount(reason)` | 账号没了 → 清登录态、回默认昵称头像、关弹窗、toast |
| `pullRecords()` / `pullSettings()` / `pullMyProfile()` | 训练记录 / 设置 / 自己资料 |
| `onLoginMerge()` | 登录后的合并流程：拉设置 → 拉记录 → 推本地独有 → 校资料 → 推设置 |
| `netProbe()` | 探一次网络（30 秒内不重复），驱动顶部「连不上服务器」提示条 |

### 训练记录

| 名称 | 说明 |
|---|---|
| `DATA` / `save()` | 训练记录数组，键 `fitness_proto_v03` |
| `TMAP` / `saveTimes()` | 每条记录的修改时间，键 `fitness_times_v1`；用来做「本地新还是云端新」的判断 |
| `REMOTE` | 云端已有哪几天，用来算「本机独有」 |
| `pushAllRecords()` | 批量上行（首次登录 / 恢复时用） |

### 打包相关

- `capacitor.config.json`：`appId=com.pushup.lite`、`webDir=www`、状态栏深色
- `native/*.java`：`MainActivity`（WebView + 状态栏）、`PUSHUPBridge`（JS ↔ 原生桥，用于震动/保存文件）
- `.github/workflows/build-apk.yml`：**手动触发**，产 release 产物 + APK

---

## 6. 数据库

### 6 张表 + 1 个函数

| 表 | 主键 | 关键列 | 说明 |
|---|---|---|---|
| `profiles` | `id` → `auth.users` cascade | `name`, `emoji` | 昵称 + 头像 emoji；**不存邮箱** |
| `posts` | `id`（text） | `uid`, `text`, `workout`(jsonb), `ts`(bigint) | 动态；`workout` 是当日成果快照 `[{n,v,u}]` |
| `post_likes` | `(post_id, uid)` | `ts` | 复合主键天然去重 |
| `post_comments` | `id`（text） | `post_id`, `uid`, `text`, `ts`(bigint) | |
| `records` | `(uid, d)` | `payload`(jsonb), `updated_at` | 一天一条，`d` 是 `YYYY-MM-DD` |
| `settings` | `uid` | `favs`, `custom`, `cats`, `nickname`, `theme`, `palette` | 按字段 PATCH |

函数 `lite_bundle()` —— 省流用，见第 7 节。触发器 `on_auth_user_created` 在注册时自动插 `profiles` 行。

### 账号到底存在哪（重要，曾是用户的重大疑惑）

- **账号（邮箱 + 密码哈希 + uid）= `auth.users`**，由 Supabase Auth 管
- **`public.*` 只是业务数据**
- 所以：把 `public` 里的表全清空，**照样能登录**——登录只查 `auth.users`
- 「清空后登录发现资料又回来了」不是 bug：登录成功后 App 会 `sbUpsertProfile` 补建 `profiles` 行（`on conflict do nothing/update`）
- 要真正删账号：Supabase 后台 → Authentication → Users → Delete。删完后客户端下次进页面 `verifyAccount()` 会 401/404 → `kickAccount()` 自动退出

### 前置设置（漏掉会卡在注册）

Authentication → Providers → Email：
1. **关闭 `Confirm email`**（否则注册完登不上，要先去邮箱点链接）
2. `Minimum password length` 调到 **1**

### RLS

所有表都开了 RLS。读：`profiles` / `posts` / `post_likes` / `post_comments` / `records` 对 `authenticated` 可读（`records` 本来只有自己能读写，但为了「所有人可读」加了一条 select 策略）；写：只允许 `uid = auth.uid()`。

---

## 7. 反代消耗（这个项目最贵的资源）

### 为什么必须反代

`*.supabase.co` 在国内**不是 DNS 或 TCP 的问题，是 TLS 握手被重置**。实测：

| 测试 | 结果 |
|---|---|
| 境外出口访问 `https://<proj>.supabase.co/rest/v1/` | `{"message":"No API key found"}` 正常 |
| 国内直连同一地址 | DNS 通、TCP 通、**TLS 阶段被 reset** |
| `curl` 从国内 | `exit 35 (SSL connect error)` |

所以 App 里默认 `SB_URL` 指向反代。

### 反代的多项目路由（v1.1 新增）

`supabase-proxy/scf/index.js` 里：

```js
const UPSTREAM_MAP = {
  "":     process.env.UPSTREAM      || "https://zqrlolyntlcewrwfhumd.supabase.co",  // 完整版
  "/lite": process.env.UPSTREAM_LITE || "https://ctcqmjssqineuubadqty.supabase.co"   // Lite
};
```

请求 `/lite/rest/v1/posts` → 去掉 `/lite` → 转发到 Lite 项目的 `/rest/v1/posts`。
好处：**一个云函数服务两个 App，不用买新域名、不用备案、不用开新函数**。
换上游不用改代码，在 SCF 控制台配 `UPSTREAM` / `UPSTREAM_LITE` 环境变量即可。

反代只放行 `/rest/v1`、`/auth/v1`、`/storage/v1`、`/realtime/v1`，其它路径一律 403（避免变成公开代理被人白嫖）。

### 消耗账：优化前后

| 场景 | v1.0 | v1.1 |
|---|---|---|
| 停在**动态页** 10 分钟 | ~630 次 | **~90 次** |
| 停在**记录/数据/我的**页 10 分钟 | 2~3 次 | 2~3 次 |
| 切后台 / 锁屏 | 0 | 0 |
| 单次响应体积（空库） | ~1 KB | ~1 KB |
| 单次响应体积（30 人 300 条动态） | 15~25 KB | 15~25 KB |

v1.0 那 630 次的构成：首屏 ~11 次 + 每 4 秒 4 个请求 × 150 轮 = 600 + 账号校验 ~20。

### v1.1 的三招

1. **4 表合并成 1 次 RPC**（省 75%）
   `POST /rest/v1/rpc/lite_bundle` 一次返回 `{profiles, posts, likes, comments}`。
   数据库侧是一个 `security definer` 的 SQL 函数，绕过 RLS 一次聚合，比四次请求更省。
   前端有**自动降级**：函数不存在（404）或返回结构不对 → `_rpcOff=true` → 之后都走 `pullFour()` 四表并行，**不会崩，只是没那么省**。降级只判断一次，不会每轮多打一个空请求。

2. **轮询 4 秒 → 8 秒**（省 50%）

3. **账号校验缓存 30 秒 → 60 秒**；冷启动仍然强制验一次，所以「被删账号秒退」的体验不变

### 换算成额度

~90 次/10 分钟 ≈ 540 次/小时。每天用 1 小时 ≈ 1.6 万次/月，10 个日活 = 16 万次/月。
腾讯云 SCF 免费额度 100 万次/月 + 40 万 GBs/月 → **几十个日活以内完全安全**。

⚠️ **如果以后要加回「实时感」**，不要降间隔，先考虑：长连接（Supabase Realtime）、或者只在「有变化时」才拉（`?ts=gt.<last>` 增量查询）。直接改回 4 秒会重新踩回额度问题。

---

## 8. 本地存储键一览

| 键 | 内容 |
|---|---|
| `fitness_proto_v03` | 训练记录 `DATA` |
| `fitness_times_v1` | 每条记录修改时间 `TMAP` |
| `fitness_nickname_v1` / `fitness_avatar_v1` | 昵称 / 头像 emoji |
| `pushup_social_v1` | 社区状态（users / session / posts / likes / comments / token / rtoken） |
| `pushup_sb_url` | 手动覆盖的服务器地址（空 = 用反代） |
| `pushup_mails_v1` | 本机登录过的邮箱（用于提示「没查到这个账号」） |
| `fitness_palette_v1` / `fitness_fab_v17` | 配色 / 悬浮球状态 |
| `pushup_trash_v1` | 「恢复上次清空」用的回收站 |

「清空数据」按钮（`clearKeys()`）会清上表前 9 个键，但**不动 `pushup_sb_url`**。

---

## 9. 怎么验证（不用真机、不用连真库）

### 无头浏览器跑真实页面 + 本地假服务器

仓库里 `tools/poll-bench.py` 就是干这个的：

```bash
python tools/poll-bench.py 40      # 跑 40 秒，打印每个接口被调用几次
```

输出示例（v1.1 实测）：

```
=== 实测 40 秒 ===
  POST /rest/v1/rpc/lite_bundle    4
  GET /rest/v1/                    1
  GET /auth/v1/user                1
  合计 6 次 → 每分钟 9.0 次
  外推 10 分钟 ≈ 90 次
  轮询间隔实测: [8.0, 8.0, 8.0]
```

它的原理，值得记住：
1. 起一个 Python `http.server` 假后台，按路径返回假数据（含 `lite_bundle` 的四键结构）
2. 把 `index.html` 复制一份，在 `<head>` 后面注入 `localStorage.setItem("pushup_sb_url","http://127.0.0.1:8899")` + `window.__PREVIEW_SEED__`（登录态种子）+ `setTimeout(switchTab("feed"))`
3. `chrome --headless=new --dump-dom` / `--screenshot` 抓结果

### 几个 headless 的坑（踩过）

- **不要加 `--virtual-time-budget=0`**：虚拟时间会让 `setInterval` 疯狂推进，8 秒的轮询被压缩成 0.0 秒，测出来的数字全错。要测真实间隔就别传这个参数。
- 要抓中间状态，就在种子脚本里 `setTimeout(..., N)` 里写 `document.title = ...`，然后 `--dump-dom | grep title`。
- **窗口宽度用 500**：`.phone` 在桌面模式固定 500px，用 390 会截掉右侧。
- `headless` 下 `document.hidden` 可能是 true，会让轮询直接 return，测不到请求。

---

## 10. 怎么打包

```bash
npx cap add android      # 首次
npx cap sync android
cd android && ./gradlew assembleDebug
```

Actions 上：`build-apk.yml` **只能手动触发**（Actions → 选工作流 → Run workflow）。
想恢复 push 自动构建，把文件里 `#  push:` 那一整段注释取消即可。

`version.json` 是版本唯一来源，脚本会校验 `index.html` 里的 `const APP_VERSION="v1.1";` 是否与它一致，不一致直接失败。**发版时两个地方都要改，还要改 `sw.js` 的缓存名**（`pushup-lite-v2` → `v3`），否则老用户装完还是旧缓存。

`signing/debug.keystore` 是**故意入库**的固定 debug 签名，为了让每次云端构建签名一致、可直接覆盖升级。私钥是公开的，**绝不能用于上架**。

---

## 11. 踩过的坑（血泪，别再踩）

1. **TLS 阻断 ≠ 网络问题**
   用户报「连不上服务器」时，先分清是直连被 reset（要反代）还是反代挂了（502）。反代代码里对上游失败返回 502 + 明确 message，不要吞成 `Failed to fetch`。

2. **`verifyAccount()` 的 catch 分支千万别返回 false**
   初版网络异常就 `return false`，结果用户一断网就被踢出登录。正确做法：**网络不通 → 保留登录态**（返回 true），只有服务器明确 401/404 才踢。

3. **`pd-scroll` 的 padding 不要写死**
   详情页评论区曾经用固定 `padding-bottom:56px` 给输入框让位，换个手机尺寸就留白或遮挡。最终方案：把输入条 `pdBar` 挪进 `#page-post` 的 flex 列里，`.pd-scroll{flex:1;min-height:0}`，彻底自适应。

4. **删动态要显式删三张表**
   虽然外键有 `on delete cascade`，但客户端走的是 REST，不保证服务端策略齐全。现在 `doDelPost` 显式 `DELETE post_likes → post_comments → posts`，顺序不能反。

5. **`select=*` 是性能杀手**（完整版事故根源）
   永远显式列字段。加新列时如果忘了加进 `select`，前端会拿到 `undefined` 而不是报错，很难查。

6. **弹窗的 `margin`/`border-radius` 会被 `.sheet` 的贴底样式覆盖**
   删除确认框要居中且圆角，得用 `.confirm-sheet` 覆盖 `margin:0 auto 34px; border-radius:18px`。

7. **预览花名册**：`*-预览.html` 是本地验证用（图标内联成 base64、强制本地模式、跳过开屏），**不要入库**（`.gitignore` 已排除）。

8. **`sbUrlNorm()` 会截掉误贴的接口路径**
   用户把 `https://xxx.supabase.co/rest/v1/` 整个粘进去时，会自动截成域名。改这块时注意别把 `/lite` 这种合法前缀也截掉。

---

## 12. 接下来该干什么（按优先级）

### 必须先做（否则 App 连不上）

1. **新 Supabase 项目跑 `supabase.sql`**
   SQL Editor → 整段粘贴 → Run。幂等，可重复跑。
   跑完验证：`select public.lite_bundle();` 应返回含四个键的 JSON（空库时都是 `[]`）。
2. **重新部署 SCF 反代**
   腾讯云控制台 → 函数代码 → 粘贴新的 `supabase-proxy/scf/index.js` → 部署。
   不部署的话 `/lite` 会 403，App 完全连不上。**完整版不受影响**（根路径行为没变）。
3. 验证反代：浏览器直接开
   `https://1301392226-ickz22vd03.ap-guangzhou.tencentscf.com/lite/rest/v1/profiles?select=id&limit=1`
   带 `apikey` 头应返回 `[]` 而不是 403 / 502。

### 然后

4. 真机跑一遍：注册 → 发动态 → 点赞 → 评论 → 删动态 → 编辑资料换 emoji → 记录页打卡 → 换设备登录看同步
5. 构建 APK（手动触发 Actions）
6. 确认 `keep-alive.yml` 已指向新项目（v1.1 已改），否则新库 7 天没请求会被免费档暂停

### 可以考虑的（用户提过但没做）

- 动态的增量同步（`?ts=gt.`）而不是全量替换，这样可以把间隔再放短而不涨请求数
- 反代加一层短缓存（同一秒内的相同 GET 直接返回），能再省一截
- 记录页的「本月合计」可视化

---

## 13. 和完整版共存要注意

| | 完整版 | Lite |
|---|---|---|
| 包名 | `com.simplejuzi.pushup` | `com.pushup.lite` |
| 仓库目录 | `../PUSHUP/` | 本目录 |
| Supabase | `zqrlolyntlcewrwfhumd` | `ctcqmjssqineuubadqty` |
| 反代路径 | `/`（根） | `/lite` |
| 表数 | 7 张（含 follows / messages） | 6 张 |

改反代代码时**两个 App 都要回归一遍**：根路径请求必须还能正常走，否则完整版会一起挂。
