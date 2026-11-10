package com.pushup.lite;

import android.os.Bundle;
import android.webkit.ValueCallback;
import android.webkit.WebView;

import com.getcapacitor.Bridge;
import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        registerPlugin(PUSHUPBridge.class);
        super.onCreate(savedInstanceState);
    }

    /**
     * v1.7：系统返回键 / 全面屏返回手势统一交给网页处理。
     *
     * 网页里的 window.__pushupBack() 返回 true 表示「我自己处理了」——
     * 关掉当前弹窗、从子页面回到上一级、从别的 Tab 回到记录页；
     * 返回 false 表示「已经站在记录首页，可以退出」。
     * 网页那边还做了「两秒内连按两次才退出」的防误触。
     */
    @Override
    public void onBackPressed() {
        Bridge bridge = getBridge();
        WebView web = bridge == null ? null : bridge.getWebView();
        if (web == null) {
            super.onBackPressed();
            return;
        }
        try {
            web.evaluateJavascript(
                "(function(){try{if(window.__pushupBack&&window.__pushupBack()===true)return true}" +
                "catch(e){}return false})()",
                new ValueCallback<String>() {
                    @Override
                    public void onReceiveValue(String value) {
                        // 网页没处理（或脚本异常）才真正退出
                        if (!"true".equals(value)) {
                            finish();
                        }
                    }
                });
        } catch (Exception e) {
            super.onBackPressed();
        }
    }
}
