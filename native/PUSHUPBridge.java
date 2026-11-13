package com.pushup.lite;

import android.app.Activity;
import android.content.ContentValues;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.net.Uri;
import android.os.Build;
import android.os.Environment;
import android.provider.MediaStore;
import android.util.Base64;
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

    /** 把下载进度回报给网页侧，供更新弹窗显示百分比与已下载大小 */
    private void emitProgress(long loaded, long total, boolean done) {
        JSObject data = new JSObject();
        data.put("loaded", loaded);
        data.put("total", total);
        data.put("done", done);
        notifyListeners("downloadProgress", data);
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
                    long total = conn.getContentLength();   // 服务器不给长度时为 -1
                    InputStream in = conn.getInputStream();
                    OutputStream out = new FileOutputStream(f);
                    byte[] buf = new byte[8192];
                    int len;
                    long loaded = 0;
                    long lastEmit = 0;
                    emitProgress(0, total, false);
                    while ((len = in.read(buf)) > 0) {
                        out.write(buf, 0, len);
                        loaded += len;
                        long now = System.currentTimeMillis();
                        if (now - lastEmit >= 100) {
                            lastEmit = now;
                            emitProgress(loaded, total, false);
                        }
                    }
                    out.flush();
                    out.close();
                    in.close();
                    conn.disconnect();
                    conn = null;
                    emitProgress(loaded, total > 0 ? total : loaded, true);

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

    /* ================= v1.36：每日提醒 ================= */

    @PluginMethod
    public void setReminder(PluginCall call) {
        Boolean on = call.getBoolean("enabled");
        Integer hour = call.getInt("hour");
        Integer minute = call.getInt("minute");
        String lastDate = call.getString("lastDate");
        boolean enabled = (on == null) || on.booleanValue();
        int h = (hour == null) ? 20 : Math.max(0, Math.min(23, hour.intValue()));
        int m = (minute == null) ? 0 : Math.max(0, Math.min(59, minute.intValue()));
        SharedPreferences sp = getContext().getSharedPreferences(ReminderReceiver.PREFS, Context.MODE_PRIVATE);
        SharedPreferences.Editor ed = sp.edit()
                .putBoolean("enabled", enabled)
                .putInt("hour", h)
                .putInt("minute", m);
        if (lastDate != null) ed.putString("lastDate", lastDate);
        ed.apply();
        if (enabled) ReminderReceiver.schedule(getContext(), h, m);
        else ReminderReceiver.cancel(getContext());
        JSObject ret = new JSObject();
        ret.put("enabled", enabled);
        ret.put("hour", h);
        ret.put("minute", m);
        call.resolve(ret);
    }

    /** Android 13+ 通知需要运行时授权；不阻塞网页流程，授予与否由系统弹窗决定 */
    @PluginMethod
    public void requestNotifyPermission(PluginCall call) {
        JSObject ret = new JSObject();
        if (Build.VERSION.SDK_INT < 33) {
            ret.put("granted", true);
            call.resolve(ret);
            return;
        }
        Activity act = getActivity();
        if (act == null) {
            ret.put("granted", false);
            call.resolve(ret);
            return;
        }
        boolean granted = act.checkSelfPermission("android.permission.POST_NOTIFICATIONS")
                == android.content.pm.PackageManager.PERMISSION_GRANTED;
        if (!granted) {
            try {
                act.requestPermissions(new String[]{"android.permission.POST_NOTIFICATIONS"}, 7304);
            } catch (Exception ignored) {}
            ret.put("asked", true);
        }
        ret.put("granted", granted);
        call.resolve(ret);
    }

    /* ================= v1.36：周报图片保存到相册 ================= */

    @PluginMethod
    public void saveImage(PluginCall call) {
        final String dataUrl = call.getString("dataUrl");
        String n = call.getString("name");
        final String name = (n == null || n.length() == 0) ? ("PUSHUP-" + System.currentTimeMillis() + ".png") : n;
        if (dataUrl == null || dataUrl.length() == 0) {
            call.reject("缺少图片数据");
            return;
        }
        try {
            int comma = dataUrl.indexOf(',');
            String b64 = comma >= 0 ? dataUrl.substring(comma + 1) : dataUrl;
            byte[] bytes = Base64.decode(b64, Base64.DEFAULT);

            if (Build.VERSION.SDK_INT >= 29) {
                /* Android 10+ 直接写进系统相册，不需要存储权限 */
                ContentValues cv = new ContentValues();
                cv.put(MediaStore.Images.Media.DISPLAY_NAME, name);
                cv.put(MediaStore.Images.Media.MIME_TYPE, "image/png");
                cv.put(MediaStore.Images.Media.RELATIVE_PATH, Environment.DIRECTORY_PICTURES + "/PUSHUP");
                cv.put(MediaStore.Images.Media.IS_PENDING, 1);
                Uri uri = getContext().getContentResolver()
                        .insert(MediaStore.Images.Media.EXTERNAL_CONTENT_URI, cv);
                if (uri == null) {
                    call.reject("无法写入相册");
                    return;
                }
                OutputStream os = getContext().getContentResolver().openOutputStream(uri);
                os.write(bytes);
                os.flush();
                os.close();
                ContentValues done = new ContentValues();
                done.put(MediaStore.Images.Media.IS_PENDING, 0);
                getContext().getContentResolver().update(uri, done, null, null);
                JSObject ret = new JSObject();
                ret.put("uri", uri.toString());
                ret.put("saved", true);
                call.resolve(ret);
                return;
            }

            /* Android 9 及以下：写到 App 目录，再拉系统分享面板让用户存图 */
            File base = getContext().getExternalFilesDir(Environment.DIRECTORY_PICTURES);
            if (base == null) base = getContext().getCacheDir();
            File dir = new File(base, "PUSHUP");
            if (!dir.exists() && !dir.mkdirs()) {
                call.reject("无法创建图片目录");
                return;
            }
            File f = new File(dir, name);
            FileOutputStream fo = new FileOutputStream(f);
            fo.write(bytes);
            fo.flush();
            fo.close();
            Uri uri = uriFor(f);
            Intent send = new Intent(Intent.ACTION_SEND);
            send.setType("image/png");
            send.putExtra(Intent.EXTRA_STREAM, uri);
            send.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION);
            Intent chooser = Intent.createChooser(send, "保存或分享图片");
            chooser.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
            getContext().startActivity(chooser);
            JSObject ret = new JSObject();
            ret.put("path", f.getAbsolutePath());
            ret.put("saved", false);
            call.resolve(ret);
        } catch (Exception e) {
            call.reject("保存失败: " + e.getMessage());
        }
    }
}
