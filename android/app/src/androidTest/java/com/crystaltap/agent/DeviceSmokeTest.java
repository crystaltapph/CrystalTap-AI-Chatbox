package com.crystaltap.agent;
import android.app.*;
import android.content.*;
import android.media.AudioAttributes;
import android.view.*;
import android.widget.*;
import androidx.test.ext.junit.runners.AndroidJUnit4;
import androidx.test.platform.app.InstrumentationRegistry;
import org.junit.*;
import org.junit.runner.RunWith;
import org.json.JSONObject;
import static org.junit.Assert.*;

@RunWith(AndroidJUnit4.class)
public class DeviceSmokeTest {
 private Context context(){return InstrumentationRegistry.getInstrumentation().getTargetContext();}
 @Test public void ownNotificationChannelUsesBundledTone(){Context c=context();Alerts.channel(c);NotificationChannel channel=c.getSystemService(NotificationManager.class).getNotificationChannel(Alerts.CHANNEL);assertEquals(NotificationManager.IMPORTANCE_HIGH,channel.getImportance());assertTrue(channel.getSound().toString().endsWith("/raw/crystaltap_alert"));assertEquals(AudioAttributes.USAGE_NOTIFICATION,channel.getAudioAttributes().getUsage());assertTrue(channel.shouldVibrate());Alerts.show(c,0,true,"instrumentation-fixture");boolean found=false;for(int attempt=0;attempt<25&&!found;attempt++){for(android.service.notification.StatusBarNotification n:c.getSystemService(NotificationManager.class).getActiveNotifications())if(n.getNotification().getChannelId().equals(Alerts.CHANNEL)){found=true;assertEquals("Test alert received on this Android app.",n.getNotification().extras.getCharSequence(Notification.EXTRA_TEXT).toString());}if(!found)android.os.SystemClock.sleep(200);}assertTrue(found);c.getSystemService(NotificationManager.class).cancelAll();}
 @Test public void sessionUsesAuthenticatedAndroidKeystoreEncryption()throws Exception{Store store=new Store(context());store.clear();store.write(new JSONObject().put("token","native-fixture-not-real-token"));String raw=context().getSharedPreferences("private-session",0).getString("data","");assertFalse(raw.contains("native-fixture-not-real-token"));assertEquals("native-fixture-not-real-token",store.read().getString("token"));store.clear();}
 @Test public void launchesNativeSignInWithCrystalTapWebsite(){Instrumentation instrumentation=InstrumentationRegistry.getInstrumentation();Activity activity=instrumentation.startActivitySync(new Intent(context(),MainActivity.class).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK));instrumentation.waitForIdleSync();assertTrue((activity.getWindow().getAttributes().flags&WindowManager.LayoutParams.FLAG_SECURE)!=0);assertTrue(contains(activity.getWindow().getDecorView(),"https://crystaltapwater.com"));assertFalse(hasWebView(activity.getWindow().getDecorView()));instrumentation.runOnMainSync(activity::finish);}
 private boolean contains(View view,String value){if(view instanceof TextView&&((TextView)view).getText().toString().contains(value))return true;if(view instanceof ViewGroup)for(int i=0;i<((ViewGroup)view).getChildCount();i++)if(contains(((ViewGroup)view).getChildAt(i),value))return true;return false;}
 private boolean hasWebView(View view){if(view instanceof android.webkit.WebView)return true;if(view instanceof ViewGroup)for(int i=0;i<((ViewGroup)view).getChildCount();i++)if(hasWebView(((ViewGroup)view).getChildAt(i)))return true;return false;}
}
