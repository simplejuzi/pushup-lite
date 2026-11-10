/**
 * PUSHUP · Supabase 反向代理（阿里云函数计算 FC 3.0）
 * 作用：把 https://<你的函数域名>.scf.tencentcs.com 的请求原样转发到
 *       https://zqrlolyntlcewrwfhumd.supabase.co，解决国内直连 supabase 被阻断的问题。
 * 依赖：无（只用 Node 内置 https 模块，不用 npm install）
 * 兼容：FC 3.0「事件函数 + HTTP 触发器」（handler 名填 index.handler）
 */

const UPSTREAM = "https://zqrlolyntlcewrwfhumd.supabase.co";
// 只放行这几类路径，避免被别人拿去当任意网站的代理
const ALLOW = ["/rest/v1", "/auth/v1", "/storage/v1", "/realtime/v1"];

const PASS_REQ = [
  "apikey", "authorization", "content-type", "accept", "prefer",
  "accept-profile", "content-profile", "range", "x-client-info",
  "x-upsert", "x-supabase-api-version", "user-agent"
];
/* 注意：Node 的 res.headers 键名全是小写，这里也必须用小写，否则取不到值 */
const PASS_RES = [
  "content-type", "content-range", "prefer-applied", "location",
  "www-authenticate", "content-location", "cache-control", "etag", "range"
];

function cors(origin) {
  return {
    "Access-Control-Allow-Origin": origin || "*",
    "Access-Control-Allow-Methods": "GET,POST,PATCH,PUT,DELETE,HEAD,OPTIONS",
    "Access-Control-Allow-Headers": "*",
    "Access-Control-Max-Age": "86400",
    "Access-Control-Expose-Headers":
      "content-range, prefer-applied, content-location, location, etag, x-supabase-api-version",
    "Vary": "Origin"
  };
}

function reply(status, body, headers) {
  return { statusCode: status, headers: headers, body: body, isBase64Encoded: false };
}

/** 把 FC 3.0 事件函数 + HTTP 触发器的事件格式统一成 {method, path, query, headers, body} */
function parseEvent(event) {
  const e = event || {};
  const http = (e.requestContext && e.requestContext.http) || {};
  const method = (http.method || e.httpMethod || "GET").toUpperCase();
  const rawPath = e.rawPath || http.path || e.path || "/";

  const headers = {};
  for (const k in (e.headers || {})) headers[String(k).toLowerCase()] = e.headers[k];

  let query = "";
  const qp = e.queryParameters;
  if (qp && typeof qp === "object") {
    query = Object.keys(qp).map(function (k) {
      const v = qp[k];
      const s = Array.isArray(v) ? v[0] : v;
      return encodeURIComponent(k) + "=" + encodeURIComponent(s == null ? "" : s);
    }).join("&");
  } else if (typeof qp === "string") {
    query = qp;
  } else if (rawPath.indexOf("?") >= 0) {
    query = rawPath.slice(rawPath.indexOf("?") + 1);
  }

  let body = e.body;
  if (body && e.isBase64Encoded) {
    body = Buffer.from(String(body), "base64").toString("utf8");
  }
  if (body === undefined || body === null) body = "";
  return { method: method, path: rawPath.split("?")[0], query: query, headers: headers, body: body };
}

exports.handler = async function (event, context) {
  const req = parseEvent(event);
  const origin = req.headers["origin"] || "*";

  // 预检：直接放行，不转发
  if (req.method === "OPTIONS") {
    return reply(204, "", cors(origin));
  }

  const allowed = ALLOW.some(p => req.path === p || req.path.indexOf(p + "/") === 0 || req.path.indexOf(p + "?") === 0);
  if (!allowed) {
    return reply(403, JSON.stringify({ message: "此代理只转发 Supabase 接口", path: req.path }),
                 Object.assign({ "Content-Type": "application/json" }, cors(origin)));
  }

  const target = UPSTREAM + req.path + (req.query ? "?" + req.query : "");

  const outHeaders = {};
  for (const k of PASS_REQ) if (req.headers[k]) outHeaders[k] = req.headers[k];
  if (req.body && !outHeaders["content-type"]) outHeaders["content-type"] = "application/json";
  outHeaders["content-length"] = Buffer.byteLength(req.body);

  let res;
  try {
    res = await request(target, req.method, outHeaders, req.body);
  } catch (e) {
    // 上游连不上：给 502，让前端看到明确原因而不是一句 Failed to fetch
    return reply(502, JSON.stringify({
      message: "代理连不上 Supabase：" + (e && e.message ? e.message : e),
      hint: "检查云函数所在地域能否访问外网（VPC 内需要配 NAT 网关）"
    }), Object.assign({ "Content-Type": "application/json" }, cors(origin)));
  }

  const resHeaders = cors(origin);
  for (const k of PASS_RES) if (res.headers[k]) resHeaders[k] = res.headers[k];
  if (!resHeaders["content-type"]) resHeaders["content-type"] = "application/json";

  return reply(res.status, res.body, resHeaders);
};

function request(url, method, headers, body) {
  const { URL } = require("url");
  const u = new URL(url);
  const lib = (u.protocol === "http:") ? require("http") : require("https");
  return new Promise((resolve, reject) => {
    const opt = {
      method: method,
      hostname: u.hostname,
      port: u.port || (u.protocol === "http:" ? 80 : 443),
      path: u.pathname + u.search,
      headers: headers
    };
    const r = lib.request(opt, rs => {
      const chunks = [];
      rs.on("data", c => chunks.push(c));
      rs.on("end", () => resolve({
        status: rs.statusCode || 502,
        headers: rs.headers,
        body: Buffer.concat(chunks).toString("utf8")
      }));
    });
    r.on("error", reject);
    r.setTimeout(12000, () => { r.destroy(new Error("上游 12 秒超时")); });
    if (body) r.write(body);
    r.end();
  });
}
