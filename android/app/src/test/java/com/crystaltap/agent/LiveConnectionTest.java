package com.crystaltap.agent;
import org.junit.*;
import org.junit.runner.RunWith;
import org.robolectric.*;
import org.robolectric.annotation.Config;
import android.os.Looper;
import org.json.JSONObject;
import java.util.*;
import java.util.concurrent.*;
import java.time.Duration;
import static org.junit.Assert.*;

@RunWith(RobolectricTestRunner.class)
@Config(sdk=28)
public class LiveConnectionTest {
    private LiveConnection live;
    private final List<String> states=new ArrayList<>();
    private final LiveConnection.Listener listener=new LiveConnection.Listener(){public void changed(){fail("No authorized event should be delivered in these cases");}public void state(String value){states.add(value);}};
    @After public void close(){if(live!=null)live.close();}
    private void awaitState()throws Exception{for(int i=0;i<100&&states.isEmpty();i++){Thread.sleep(5);Shadows.shadowOf(Looper.getMainLooper()).idle();}assertFalse("Expected a safe fallback status",states.isEmpty());}
    @Test public void disabledInstallationKeepsRegularRefresh()throws Exception{
        live=new LiveConnection(RuntimeEnvironment.getApplication(),()->new JSONObject().put("enabled",false),listener);live.start();awaitState();assertEquals("Regular refresh",states.get(0));
    }
    @Test public void failedCredentialRequestDoesNotExposeProviderDetails()throws Exception{
        live=new LiveConnection(RuntimeEnvironment.getApplication(),()->{throw new Exception("private-provider-response");},listener);live.start();awaitState();assertEquals("Live updates unavailable · regular refresh active",states.get(0));
    }
    @Test public void malformedFeedIsRejectedBeforeAnyFirebaseConnection()throws Exception{
        live=new LiveConnection(RuntimeEnvironment.getApplication(),()->new JSONObject().put("enabled",true).put("firebase",new JSONObject()).put("path","ctai/another/site"),listener);live.start();awaitState();assertTrue(states.get(0).contains("regular refresh active"));
    }
    @Test public void closingDuringCredentialRequestCannotRestartTheListener()throws Exception{
        CountDownLatch entered=new CountDownLatch(1),release=new CountDownLatch(1),returned=new CountDownLatch(1);
        live=new LiveConnection(RuntimeEnvironment.getApplication(),()->{entered.countDown();try{release.await();}catch(InterruptedException ignored){}finally{returned.countDown();}return new JSONObject().put("enabled",false);},listener);
        live.start();assertTrue(entered.await(2,TimeUnit.SECONDS));live.close();release.countDown();assertTrue(returned.await(2,TimeUnit.SECONDS));Shadows.shadowOf(Looper.getMainLooper()).idleFor(Duration.ofSeconds(45));assertTrue(states.isEmpty());
    }
}
