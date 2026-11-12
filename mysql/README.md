# PUSHUP · MySQL 8.0 后端部署（宝塔 / PHP）

这套东西替代 Supabase + 腾讯云反代。App 里只填一个地址：`https://你的域名/pushup/api.php`。

```
App  ──https──▶  api.php（PHP，自己的服务器）  ──本地 3306──▶  MySQL 8.0
```

不是反向代理：接口和数据库都在你自己机器上，不再经过 Supabase，也不再消耗云函数流量。

## 本次实际部署（154.12.36.171）

服务商只放行了 **27022 / 27080 / 27443** 三个端口（22、80、443 从外网连不上），
所以服务器上 Nginx 同时监听 80/443/27080/27443，App 用的是：

```
http://154.12.36.171:27080/pushup/api.php
```

因为 **80/443 不可达 → Let's Encrypt 的 HTTP-01 签不下来 → 27443 目前只有自签证书**，
App 端因此开了明文 HTTP（`capacitor.config.json` 里 `server.cleartext` + `android.allowMixedContent`，
构建时还会给清单加 `usesCleartextTraffic="true"`）。

> 想要真正的 https：买一个域名（10 块一年也行）解析到这台机，用 DNS-01 签证书，
> 再把地址换成 `https://你的域名:27443/pushup/api.php`，不用重新打包，在
> 设置 → 关于 → 接口 里改即可。

Nginx 里已经屏蔽了 `config*.php` / `*.sql` / `import.php` / `*.md`，这些文件不需要也不应该
放在网站目录里；`import.php` 放 `/www/server/pushup-backend/`。

## 文件

| 文件 | 用途 |
|---|---|
| `api.php` | 唯一接口文件，注册 / 登录 / 同步 / 动态 / 评论 / 点赞 |
| `config.php` | 数据库配置（读环境变量或 `config.local.php`） |
| `config.local.example.php` | 复制成 `config.local.php` 后填密码 |
| `schema.sql` | MySQL 8.0 建库建表，导入一次 |
| `export-supabase.sql` | 在 Supabase SQL Editor 跑，导出老数据 |
| `import.php` | 把导出的 JSON 灌进 MySQL（命令行执行） |

## 一、建库

宝塔 → **数据库** → 添加数据库：

- 数据库名：`pushup`
- 用户名：`pushup`（或默认同名）
- 密码：自己设一个强密码
- 字符集：`utf8mb4`

建好后点「管理」进 phpMyAdmin → 选 `pushup` → **导入** → 选 `schema.sql` → 执行。

> 也可以在一个已有的库里执行 `schema.sql`，把里面 `CREATE DATABASE` / `USE` 两行删掉即可。

## 二、上传接口

1. 在站点根目录建一个子目录，例如 `/www/wwwroot/你的站/pushup/`。
2. 上传 `api.php`、`config.php`、`config.local.example.php`。
3. 把 `config.local.example.php` 复制成 `config.local.php`，填数据库密码。
4. 宝塔 → 网站 → 你的站 → **PHP 版本** 选 7.4 / 8.0 / 8.1 均可；确认装过 `pdo_mysql`（宝塔默认有）。
5. 有域名就开 HTTPS（宝塔 → SSL → Let's Encrypt）；**端口受限、签不了证书时用 http 也可以**，App 端已允许明文请求。

## 三、自检

浏览器打开：

```
https://你的域名/pushup/api.php?path=/ping
```

正常返回：

```json
{"ok":true,"service":"pushup-api","php":"8.1.x","db":true,"time":"..."}
```

`db` 是 `false` 就是数据库没连上：看 `config.local.php` 的库名 / 用户 / 密码，以及宝塔里该用户有没有 `pushup` 库的权限。

## 四、迁移 Supabase 老数据（账号 + 记录 + 动态）

1. Supabase Dashboard → SQL Editor → 粘贴 `export-supabase.sql` → Run。
2. 结果是**一行 JSON**（列名 `dump`）。完整复制这一格，存成 `supabase-dump.json`。
3. 把 `import.php` 和 `supabase-dump.json` 传到服务器同一目录，SSH 执行：

```bash
cd /www/wwwroot/你的站/pushup
php import.php supabase-dump.json
```

4. 看到 `users / profiles / posts / comments / records / settings` 的条数就成功了。重复执行安全。

> 密码是 bcrypt，能直接复用，老账号密码不变。
> 如果某个账号原来是第三方登录（没有密码），脚本会列出来，需要重置密码。

## 五、App 里填地址

App → 我的 → 设置 → 关于 → **接口** → 填：

```
https://你的域名/pushup/api.php
```

保存后会重启。之后注册 / 登录 / 同步 / 动态都走这套接口。

## 六、请求量

- 冷启动：`/ping` 1 次 + `lite_bundle` 1 次；
- 点底栏「动态」/ 下拉刷新：`lite_bundle` 1 次；
- 停在动态页不动：0 次；
- 发一条动态 / 评论：1 次写请求；
- 改一条训练记录：1 次 upsert。

和以前一样是手动同步，没有定时器。区别只是这些请求打到你自己的服务器，不再有云函数流量费。

## 七、注意

- `import.php` 只允许命令行运行，浏览器打开会 403。
- 不要把 `config.local.php` 提交到 Git，也不要把数据库 3306 暴露到公网；接口和 MySQL 在同一台机器时用 `127.0.0.1`。
- 备份：宝塔 → 数据库 → 备份，或 `mysqldump -u pushup -p pushup > pushup.sql`。
- 换服务器只改 App 里的接口地址，不用重新打包。
