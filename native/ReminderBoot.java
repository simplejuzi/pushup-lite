package com.pushup.lite;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;

/**
 * v1.36：开机后把每日提醒重新挂上。
 * 系统闹钟在重启后会全部清空，不重新注册的话用户会以为提醒坏了。
 */
public class ReminderBoot extends BroadcastReceiver {
    @Override
    public void onReceive(Context ctx, Intent intent) {
        if (intent == null) return;
        String a = intent.getAction();
        if (!Intent.ACTION_BOOT_COMPLETED.equals(a)
                && !"android.intent.action.QUICKBOOT_POWERON".equals(a)) return;
        SharedPreferences sp = ctx.getSharedPreferences(ReminderReceiver.PREFS, Context.MODE_PRIVATE);
        if (sp.getBoolean("enabled", false)) {
            ReminderReceiver.schedule(ctx, sp.getInt("hour", 20), sp.getInt("minute", 0));
        }
    }
}
