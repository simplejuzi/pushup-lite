# 怎么把 PUSHUP Lite 交接给别的 AI

有三种方式，按可靠性从高到低排。**推荐方式 1**，它不依赖任何网络。

---

## 方式 1：同一个工作区开新会话（最稳，推荐）

在这台电脑上打开一个新的 WorkBuddy 会话（工作区还是 `2026-11-09-17-40-06`），
把下面这段话整段发过去：

~~~~text
你接手一个已开发到 v1.1 的 Android App 项目：PUSHUP Lite（极简俯卧撑训练记录）。

第一步：完整读这份交接文档，不要凭猜测动手。
本地路径：C:\Users\11922\WorkBuddy\2026-11-09-17-40-06\PUSHUP-LITE\HANDOFF.md
（如果读不到，读同目录的 README.md 和 CHANGELOG.md）

读完先用 5 行复述：这个项目是什么、现在卡在哪、你打算先做什么。我确认后你再动代码。

【一句话现状】
单文件 index.html（约 5100 行，无构建步骤）+ Capacitor 打包 Android，云端是 Supabase，
国内必须经自建腾讯云 SCF 反代。代码 v1.1 已推到 GitHub，但一次 APK 都没构建过。

【五条硬约束，违反会出事】
1. 不要触发 APK 构建：.github/workflows/build-apk.yml 里的 on.push 是故意注释掉的，
   只保留 workflow_dispatch。我明确要求先不打包，别"顺手"改回来。
2. 不要直连 *.supabase.co：国内 TLS 握手会被 reset，必须走反代 .../lite 路径。
3. 不要把轮询改回 4 秒、不要增加请求频率：反代调用次数是这个项目唯一的成本命门。
   现在是 8 秒一次 + 1 个 RPC（lite_bundle），停动态页 10 分钟约 90 次。
4. 不要用 select=*：永远显式列字段。上一版就是因为拉 base64 图片把反代打爆的。
5. 改完必须实测，不要假设："python tools/poll-bench.py" 测请求量，
   无头 Chrome 抓 DOM / 截图测页面。这个项目没有单元测试。

【还没做的事，可能会卡住你】
- 新 Supabase 项目 ctcqmjssqineuubadqty 还没跑过 supabase.sql（表可能还不存在）
- 反代云函数还没部署新版（没部署的话 /lite 会返回 403，App 完全连不上）
- v1.1 没在真机上验证过

【环境注意】
这台机器 git 全局代理 127.0.0.1:7897 可能是失效的，push 会报 "Failed to connect ... proxy"，
用下面这条绕开：
  git -c http.proxy= -c https.proxy= push
~~~~

---

## 方式 2：把文件直接发给别的 AI（ChatGPT / Claude / 其他）

`HANDOFF.md` 只有约 20KB，任何 AI 都能吃下。

1. 打开 `PUSHUP-Lite/HANDOFF.md`，全选复制
2. 在对话框里先粘贴上面方式 1 的那段「五条硬约束」+ 现状说明
3. 再把 `HANDOFF.md` 全文粘在后面，或者说"以下是交接文档"然后贴

> 顺序很重要：**先说约束，再给文档**。反过来的话 AI 常常读完就开始动手改。

---

## 方式 3：只给 GitHub 地址（依赖对方能访问 GitHub）

仓库：`https://github.com/simplejuzi/pushup-lite`

让 AI 读：`https://github.com/simplejuzi/pushup-lite/blob/main/HANDOFF.md`

⚠️ 别指望 `raw.githubusercontent.com` 和 `cdn.jsdelivr.net`——实测这两个在国内都会被拦，
只有 `github.com` 的网页能打开。如果对方连 GitHub 都上不去，就走方式 1 或 2。

---

## 交接完之后，让对方先回答这三个问题

这是检查"对方真的读懂了"最快的方法：

1. 这个项目现在**最贵的资源**是什么，为什么？（答案：反代调用次数；因为国内必须走反代，
   而 Supabase 免费档 + 腾讯云 SCF 免费额度都靠它）
2. 停在哪一页会发请求、停在哪一页一个请求都不发？（答案：动态/详情/我的动态/他人主页会发；
   记录/数据/我的不发；切后台不发）
3. 我把 public 里的表全清空了，为什么还能登录？（答案：账号在 auth.users，public 只是业务数据）

三个都答对，才可以让它动代码。
