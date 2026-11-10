#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""PUSHUP Lite · 反代请求量基准测试（不用真机、不连真库）

做法：起一个本地假后台冒充 Supabase 反代，把 index.html 复制一份并注入
「登录态种子 + 把服务器地址指向本地」，再用无头 Chrome 打开，
统计每个接口被调用了几次、轮询间隔是否真的是 8 秒。

用法：
    python tools/poll-bench.py            # 默认跑 40 秒
    python tools/poll-bench.py 90         # 跑 90 秒

依赖：Python 3.8+、本机装了 Chrome（路径见下方 CHROME 常量）
预期结果（v1.1）：轮询间隔 8.0 秒，外推 10 分钟约 90 次。

⚠️ 不要给 Chrome 传 --virtual-time-budget：虚拟时间会让 setInterval 疯狂推进，
   8 秒的间隔会被压缩成 0.0 秒，测出来的数字完全失真。
"""

import http.server
import json
import os
import re
import socketserver
import subprocess
import sys
import threading
import time
from pathlib import Path

PORT = 8899
CHROME = r"C:\Program Files\Google\Chrome\Application\chrome.exe"

ROOT = Path(__file__).resolve().parent.parent
INDEX = ROOT / "index.html"
TMP = ROOT / "_poll_bench.html"

COUNT = {}
LOG = []
START = time.time()

# 假数据：结构与 lite_bundle() 的返回一致
BUNDLE = {
    "profiles": [{"id": "u1", "name": "测试君", "emoji": "💪"},
                 {"id": "u2", "name": "路人甲", "emoji": "🔥"}],
    "posts": [{"id": "p1", "uid": "u2", "text": "今天练完了 100 个俯卧撑",
               "workout": {"pushup": 100}, "ts": int(time.time() * 1000) - 60000}],
    "likes": [{"post_id": "p1", "uid": "u1", "ts": int(time.time() * 1000)}],
    "comments": [{"id": "c1", "post_id": "p1", "uid": "u1", "text": "厉害",
                  "ts": int(time.time() * 1000)}],
}


class Handler(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def _send(self, obj):
        body = json.dumps(obj, ensure_ascii=False).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Headers", "*")
        self.send_header("Access-Control-Allow-Methods", "GET,POST,PATCH,DELETE,OPTIONS")
        self.end_headers()
        self.wfile.write(body)

    def do_OPTIONS(self):
        self.send_response(204)
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Headers", "*")
        self.send_header("Access-Control-Allow-Methods", "GET,POST,PATCH,DELETE,OPTIONS")
        self.send_header("Content-Length", "0")
        self.end_headers()

    def do_GET(self):
        self.route("GET")

    def do_POST(self):
        n = int(self.headers.get("Content-Length") or 0)
        if n:
            try:
                self.rfile.read(n)
            except Exception:
                pass
        self.route("POST")

    def do_PATCH(self):
        n = int(self.headers.get("Content-Length") or 0)
        if n:
            try:
                self.rfile.read(n)
            except Exception:
                pass
        self.route("PATCH")

    def route(self, method):
        base = self.path.split("?")[0]
        key = method + " " + base
        COUNT[key] = COUNT.get(key, 0) + 1
        LOG.append((round(time.time() - START, 1), key))
        if "/auth/v1/user" in base:
            self._send({"id": "u1", "email": "t@t.com"})
        elif "/rpc/lite_bundle" in base:
            self._send(BUNDLE)
        elif "/rest/v1/" in base:
            tbl = base.split("/rest/v1/")[1].split("/")[0]
            self._send(BUNDLE.get(tbl, []))
        else:
            self._send({})

    def log_message(self, *a):
        pass


def build_page():
    html = INDEX.read_text(encoding="utf-8")
    seed = """
<script>
try{localStorage.setItem("pushup_sb_url","http://127.0.0.1:%d");}catch(e){}
window.__PREVIEW_SEED__={users:[{id:"u1",name:"测试君",emoji:"💪"}],session:"u1",
  posts:[],likes:[],comments:[],token:"faketoken",rtoken:"fakertoken"};
window.__ERRS__=[];window.addEventListener("error",function(e){window.__ERRS__.push(String(e.message));});
/* 1.2 秒后切到「动态」页：只有这个页会触发轮询 */
setTimeout(function(){try{switchTab("feed");}catch(e){window.__ERRS__.push("switch:"+e);}},1200);
</script>
""" % PORT
    TMP.write_text(html.replace("<head>", "<head>" + seed, 1), encoding="utf-8")


def main():
    secs = int(sys.argv[1]) if len(sys.argv) > 1 else 40
    if not INDEX.exists():
        sys.exit("找不到 index.html：%s" % INDEX)

    srv = socketserver.ThreadingTCPServer(("127.0.0.1", PORT), Handler)
    srv.daemon_threads = True
    threading.Thread(target=srv.serve_forever, daemon=True).start()

    build_page()
    cmd = [CHROME, "--headless=new", "--disable-gpu", "--no-first-run", "--no-default-browser-check",
           "--user-data-dir=" + str(ROOT / "_poll_bench_profile"),
           "--window-size=500,900",
           "file:///" + str(TMP).replace("\\", "/")]
    proc = subprocess.Popen(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    time.sleep(secs)
    proc.terminate()
    try:
        proc.wait(timeout=5)
    except Exception:
        proc.kill()

    total = sum(COUNT.values())
    print("=== 实测 %d 秒 ===" % secs)
    for k, v in sorted(COUNT.items(), key=lambda x: -x[1]):
        print("  %-42s %4d" % (k, v))
    print("  合计 %d 次 → 每分钟 %.1f 次" % (total, total * 60.0 / secs))
    print("  外推 10 分钟 ≈ %d 次" % round(total * 600.0 / secs))
    hits = [t for t, k in LOG if "lite_bundle" in k or "/rest/v1/posts" in k]
    if len(hits) > 1:
        print("  轮询间隔实测:", [round(hits[i + 1] - hits[i], 1) for i in range(len(hits) - 1)])

    srv.shutdown()
    TMP.unlink(missing_ok=True)
    prof = ROOT / "_poll_bench_profile"
    if prof.exists():
        subprocess.run(["cmd", "/c", "rmdir", "/s", "/q", str(prof)], capture_output=True)


if __name__ == "__main__":
    main()
