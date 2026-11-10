package com.pushup.lite;

import android.content.Intent;
import android.net.Uri;
import android.os.Environment;
import androidx.core.content.FileProvider;
import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

import java.io.File;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

/**
 * PUSHUP v1.1 原生能力：
 *  - saveFile:  把导出的备份 / 表格写到 App 外部私有目录，并调起系统分享面板
 *  - installApk: 后台下载新版 APK，然后拉起系统安装界面（同签名覆盖升级，数据保留）
 * 网页 / PWA 环境没有这个插件，JS 端自动回退到浏览器下载。
 */
@CapacitorPlugin(name = "PUSHUPBridge")
public class PUSHUPBridge extends Plugin {

    private final ExecutorService pool = Executors.newSingleThreadExecutor();

    private Uri uriFor(File f) {
        return FileProvider.getUriForFile(getContext(),
                getContext().getPackageName() + ".fileprovider", f);
    }

    private void resolveOnUi(final PluginCall call, final JSObject data) {
        getActivity().runOnUiThread(new Runnable() {
            @Override public void run() { call.resolve(data); }
        });
    }

    private void rejectOnUi(final PluginCall call, final String msg) {
        getActivity().runOnUiThread(new Runnable() {
            @Override public void run() { call.reject(msg); }
        });
    }

    @PluginMethod
    public void saveFile(PluginCall call) {
        final String name = call.getString("name");
        final String content = call.getString("content");
        String mime = call.getString("mime");
        if (mime == null || mime.length() == 0) mime = "text/plain";
        final String type = mime;
        if (name == null || content == null) {
            call.reject("缺少参数");
            return;
        }
        try {
            File base = getContext().getExternalFilesDir(Environment.DIRECTORY_DOCUMENTS);
            if (base == null) base = getContext().getFilesDir();
            File dir = new File(base, "PUSHUP");
            if (!dir.exists() && !dir.mkdirs()) {
                call.reject("无法创建目录");
                return;
            }
            File f = new File(dir, name);
            OutputStream os = new FileOutputStream(f);
            os.write(content.getBytes(StandardCharsets.UTF_8));
            os.flush();
            os.close();

            Uri uri = uriFor(f);
            Intent send = new Intent(Intent.ACTION_SEND);
            send.setType(type);
            send.putExtra(Intent.EXTRA_STREAM, uri);
            send.putExtra(Intent.EXTRA_SUBJECT, name);
            send.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION);
            Intent chooser = Intent.createChooser(send, "保存或分享");
            chooser.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
            getContext().startActivity(chooser);

            JSObject ret = new JSObject();
            ret.put("path", f.getAbsolutePath());
            ret.put("name", name);
            ret.put("uri", uri.toString());
            call.resolve(ret);
        } catch (Exception e) {
            call.reject("保存失败: " + e.getMessage());
        }
    }

    @PluginMethod
    public void installApk(final PluginCall call) {
        final String url = call.getString("url");
        String n = call.getString("name");
        final String name = (n == null || n.length() == 0) ? "PUSHUP-update.apk" : n;
        if (url == null || url.length() == 0) {
            call.reject("缺少下载地址");
            return;
        }
        pool.execute(new Runnable() {
            @Override public void run() {
                HttpURLConnection conn = null;
                try {
                    File base = getContext().getExternalFilesDir(Environment.DIRECTORY_DOWNLOADS);
                    if (base == null) base = getContext().getCacheDir();
                    File dir = new File(base, "PUSHUP");
                    if (!dir.exists() && !dir.mkdirs()) {
                        rejectOnUi(call, "无法创建下载目录");
                        return;
                    }
                    File f = new File(dir, name);
                    conn = (HttpURLConnection) new URL(url).openConnection();
                    conn.setConnectTimeout(20000);
                    conn.setReadTimeout(90000);
                    conn.setInstanceFollowRedirects(true);
                    conn.setRequestMethod("GET");
                    conn.connect();
                    int code = conn.getResponseCode();
                    if (code < 200 || code >= 300) {
                        rejectOnUi(call, "HTTP " + code);
                        return;
                    }
                    InputStream in = conn.getInputStream();
                    OutputStream out = new FileOutputStream(f);
                    byte[] buf = new byte[8192];
                    int len;
                    while ((len = in.read(buf)) > 0) out.write(buf, 0, len);
                    out.flush();
                    out.close();
                    in.close();
                    conn.disconnect();
                    conn = null;

                    Uri uri = uriFor(f);
                    Intent i = new Intent(Intent.ACTION_VIEW);
                    i.setDataAndType(uri, "application/vnd.android.package-archive");
                    i.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION);
                    i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
                    getContext().startActivity(i);

                    JSObject ret = new JSObject();
                    ret.put("started", true);
                    ret.put("path", f.getAbsolutePath());
                    resolveOnUi(call, ret);
                } catch (Exception e) {
                    if (conn != null) conn.disconnect();
                    rejectOnUi(call, "下载未完成");
                }
            }
        });
    }
}
