package com.pushup.lite;

import android.app.AlarmManager;
import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.os.Build;

import java.util.Calendar;

/**
 * v1.36：每日提醒。
 *
 * 全部在本机完成，不接任何第三方推送服务，也不上传任何数据：
 *   - AlarmManager 每天同一时间投递一次（非精确闹钟，不需要额外权限）
 *   - 收到广播后发一条系统通知，点通知回到 App
 *   - 开关与时间存在 SharedPreferences，重启后由 ReminderBoot 重新挂上
 */
public class ReminderReceiver extends BroadcastReceiver {

    public static final String PREFS = "pushup_reminder";
    public static final String ACTION = "com.pushup.lite.REMIND";
    private static final String CHANNEL = "pushup_daily";
    private static final int ALARM_ID = 7301;
    private static final int NOTI_ID = 7302;
    private static final int OPEN_ID = 7303;

    private static PendingIntent alarmIntent(Context ctx) {
        Intent i = new Intent(ctx, ReminderReceiver.class);
        i.setAction(ACTION);
        int flags = PendingIntent.FLAG_UPDATE_CURRENT;
        if (Build.VERSION.SDK_INT >= 23) flags |= PendingIntent.FLAG_IMMUTABLE;
        return PendingIntent.getBroadcast(ctx, ALARM_ID, i, flags);
    }

    public static void schedule(Context ctx, int hour, int minute) {
        AlarmManager am = (AlarmManager) ctx.getSystemService(Context.ALARM_SERVICE);
        if (am == null) return;
        Calendar c = Calendar.getInstance();
        c.set(Calendar.HOUR_OF_DAY, hour);
        c.set(Calendar.MINUTE, minute);
        c.set(Calendar.SECOND, 0);
        c.set(Calendar.MILLISECOND, 0);
        long at = c.getTimeInMillis();
        if (at <= System.currentTimeMillis()) at += AlarmManager.INTERVAL_DAY;
        try {
            am.setInexactRepeating(AlarmManager.RTC_WAKEUP, at, AlarmManager.INTERVAL_DAY, alarmIntent(ctx));
        } catch (Exception e) {
            try { am.set(AlarmManager.RTC_WAKEUP, at, alarmIntent(ctx)); } catch (Exception ignored) {}
        }
    }

    public static void cancel(Context ctx) {
        AlarmManager am = (AlarmManager) ctx.getSystemService(Context.ALARM_SERVICE);
        PendingIntent pi = alarmIntent(ctx);
        try { if (am != null) am.cancel(pi); } catch (Exception ignored) {}
        try { pi.cancel(); } catch (Exception ignored) {}
    }

    @Override
    public void onReceive(Context ctx, Intent intent) {
        SharedPreferences sp = ctx.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
        if (!sp.getBoolean("enabled", false)) return;
        show(ctx, sp);
    }

    /** v1.38：按「最近一次训练日期」算断练天数，文案跟着变；今天已经练过就不打扰 */
    private static void show(Context ctx, SharedPreferences sp) {
        NotificationManager nm = (NotificationManager) ctx.getSystemService(Context.NOTIFICATION_SERVICE);
        if (nm == null) return;
        if (Build.VERSION.SDK_INT >= 26) {
            NotificationChannel ch = new NotificationChannel(CHANNEL, "每日训练提醒", NotificationManager.IMPORTANCE_DEFAULT);
            ch.setDescription("提醒你记录今天的训练");
            nm.createNotificationChannel(ch);
        }

        String last = sp.getString("lastDate", "");
        int gap = -1;
        if (last != null && last.length() >= 10) {
            try {
                java.text.SimpleDateFormat f = new java.text.SimpleDateFormat("yyyy-MM-dd", java.util.Locale.CHINA);
                f.setLenient(false);
                java.util.Date today = f.parse(f.format(new java.util.Date()));
                java.util.Date prev = f.parse(last.substring(0, 10));
                if (today != null && prev != null) {
                    gap = (int) Math.round((today.getTime() - prev.getTime()) / 86400000.0);
                }
            } catch (Exception ignored) {}
        }
        if (gap <= 0) return;   /* 今天已经记录过，这条不发了 */

        String title;
        String text;
        if (gap >= 7) {
            title = "已经 " + gap + " 天没动了";
            text = "不用补，从今天做 10 个就算重新开始";
        } else if (gap >= 2) {
            title = "已经 " + gap + " 天没动了";
            text = "先把节奏接回来，做几个也算";
        } else {
            title = "今天还没记录吧";
            text = "几十秒记一笔，坚持就算赢";
        }

        Intent open = ctx.getPackageManager().getLaunchIntentForPackage(ctx.getPackageName());
        int flags = PendingIntent.FLAG_UPDATE_CURRENT;
        if (Build.VERSION.SDK_INT >= 23) flags |= PendingIntent.FLAG_IMMUTABLE;
        PendingIntent pi = PendingIntent.getActivity(ctx, OPEN_ID, open == null ? new Intent() : open, flags);

        Notification.Builder b = (Build.VERSION.SDK_INT >= 26)
                ? new Notification.Builder(ctx, CHANNEL)
                : new Notification.Builder(ctx);
        int icon;
        try { icon = ctx.getApplicationInfo().icon; } catch (Exception e) { icon = android.R.drawable.ic_menu_edit; }
        b.setContentTitle(title)
                .setContentText(text)
                .setSmallIcon(icon)
                .setAutoCancel(true)
                .setContentIntent(pi);
        try { nm.notify(NOTI_ID, b.build()); } catch (Exception ignored) {}
    }
}
