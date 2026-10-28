package com.crystaltap.agent;

import android.app.*;
import android.graphics.*;
import android.view.*;
import android.widget.*;
import org.json.*;
import org.junit.*;
import org.junit.runner.RunWith;
import org.robolectric.*;
import org.robolectric.annotation.*;
import org.robolectric.android.controller.ActivityController;
import java.io.*;
import java.lang.reflect.*;
import java.util.concurrent.ExecutorService;
import static org.junit.Assert.*;

/** Render the real native views with synthetic data, without calling any website. */
@RunWith(RobolectricTestRunner.class)
@Config(sdk=28,qualifiers="w393dp-h852dp-mdpi")
@GraphicsMode(GraphicsMode.Mode.NATIVE)
public class UiSmokeTest {
    private ActivityController<MainActivity> controller;
    private MainActivity activity;
    @Before public void start()throws Exception{
        controller=Robolectric.buildActivity(MainActivity.class).create().start().visible();activity=controller.get();
        ((ExecutorService)field("network")).shutdownNow();
    }
    @After public void stop(){controller.stop().destroy();}
    private Object field(String name)throws Exception{Field f=MainActivity.class.getDeclaredField(name);f.setAccessible(true);return f.get(activity);}
    private void field(String name,Object value)throws Exception{Field f=MainActivity.class.getDeclaredField(name);f.setAccessible(true);f.set(activity,value);}
    private Object invoke(String name,Class<?>[] types,Object...args)throws Exception{Method m=MainActivity.class.getDeclaredMethod(name,types);m.setAccessible(true);return m.invoke(activity,args);}
    private void invoke(String name)throws Exception{invoke(name,new Class<?>[0]);}
    private void signedIn()throws Exception{field("session",new JSONObject().put("token","synthetic-render-session").put("name","Maya Reyes").put("site","https://example.invalid").put("user",new JSONObject().put("supervise",true)).put("firebase",new JSONObject().put("app_id","synthetic-client")).put("worker",new JSONObject().put("last_worker_age_seconds",18).put("worker_status","Recently active").put("pending_push_jobs",0).put("oldest_pending_push_age_seconds",0)));}
    private View find(View view,String label){if(view instanceof TextView&&((TextView)view).getText().toString().equals(label))return view;if(view instanceof ViewGroup)for(int i=0;i<((ViewGroup)view).getChildCount();i++){View found=find(((ViewGroup)view).getChildAt(i),label);if(found!=null)return found;}return null;}
    private View root()throws Exception{return (View)field("root");}
    private void snapshot(String name,int width,int height)throws Exception{
        View root=root();root.measure(View.MeasureSpec.makeMeasureSpec(width,View.MeasureSpec.EXACTLY),View.MeasureSpec.makeMeasureSpec(height,View.MeasureSpec.EXACTLY));root.layout(0,0,width,height);
        Bitmap bitmap=Bitmap.createBitmap(width,height,Bitmap.Config.ARGB_8888);root.draw(new Canvas(bitmap));File directory=new File("build/reports/ui");assertTrue(directory.isDirectory()||directory.mkdirs());try(FileOutputStream stream=new FileOutputStream(new File(directory,name+".png"))){assertTrue(bitmap.compress(Bitmap.CompressFormat.PNG,100,stream));}bitmap.recycle();
    }
    @Test public void signInHasWebsiteAndUsablePrimaryAction()throws Exception{
        assertNotNull(find(root(),"https://crystaltapwater.com"));assertNotNull(find(root(),"Continue with WordPress"));snapshot("sign-in",393,852);
        Button button=(Button)find(root(),"Continue with WordPress");assertTrue(button.getHeight()>=48);assertEquals(View.VISIBLE,button.getVisibility());
    }
    @Test public void inboxShowsUnreadStatusAndPersistentNavigation()throws Exception{
        signedIn();invoke("inbox");field("availability","online");invoke("updateAvailability");
        JSONArray items=new JSONArray().put(new JSONObject().put("id",1042).put("status","waiting").put("assigned_agent",JSONObject.NULL).put("preview","A customer is waiting for your team.").put("updated_at","2026-10-28 06:35:00").put("version",3)).put(new JSONObject().put("id",1041).put("status","human_active").put("assigned_agent",1).put("preview","Thank you! Can we schedule the installation for Friday?").put("unread",2).put("updated_at","2026-10-28 06:29:00")).put(new JSONObject().put("id",1039).put("status","assigned").put("assigned_agent",1).put("preview","I’d like to ask about a water filter for our home.").put("unread",1).put("updated_at","2026-10-28 06:15:00"));
        invoke("renderInbox",new Class<?>[]{JSONArray.class},items);snapshot("inbox",393,852);
        assertNotNull(find(root(),"Waiting"));assertNotNull(find(root(),"2"));View nav=(View)field("bottomNav");assertTrue(nav.getBottom()<=852);assertTrue(nav.getHeight()>=56);assertNotNull(find(nav,"Profile"));
        ((LinearLayout)field("inboxItems")).getChildAt(0).performClick();assertTrue(org.robolectric.shadows.ShadowAlertDialog.getLatestAlertDialog().isShowing());
    }
    @Test public void conversationKeepsPrivateNotesLinksAndComposer()throws Exception{
        signedIn();invoke("openConversation",new Class<?>[]{int.class},1041);field("current",new JSONObject().put("id",1041).put("status","human_active").put("version",4));invoke("renderActions");((TextView)field("status")).setText("In progress · Conversation #1041");
        String[] senders={"customer","agent","customer","agent"},texts={"Hi! We’re looking for a water filter for our home.","Hi there! I’m happy to help. What type of water source do you use?","We use district water. Can we arrange an installation?","Customer prefers a Friday appointment. Confirm availability with the installation team."};
        for(int i=0;i<senders.length;i++)invoke("message",new Class<?>[]{JSONObject.class},new JSONObject().put("sender",senders[i]).put("content",texts[i]).put("visibility",i==3?"internal":"public").put("created_at","2026-10-28 06:30:00"));
        snapshot("conversation",393,852);assertNotNull(find(root(),"PRIVATE NOTE · TEAM ONLY"));assertNotNull(find(root(),"Send reply"));assertTrue(((EditText)field("reply")).getHeight()>=48);LinearLayout history=(LinearLayout)field("history");ViewGroup bubbleRow=(ViewGroup)history.getChildAt(0);assertTrue("Message bubbles must have readable width",bubbleRow.getChildAt(0).getWidth()>=180);
        CheckBox note=(CheckBox)field("note");note.setChecked(true);assertTrue(((EditText)field("reply")).getHint().toString().contains("private"));
    }
    @Test public void backButtonPreservesAnInFlightDraft()throws Exception{
        signedIn();invoke("openConversation",new Class<?>[]{int.class},1041);EditText reply=(EditText)field("reply");reply.setText("A draft typed during a send");field("sending",true);invoke("onBackPressed");assertEquals(1041,field("conversation"));assertEquals("A draft typed during a send",reply.getText().toString());
    }
    @Test public void profileGroupsNotificationAndAccountControls()throws Exception{
        signedIn();invoke("profile");snapshot("profile",393,852);assertNotNull(find(root(),"Notifications"));assertNotNull(find(root(),"Test Firebase push"));assertNotNull(find(root(),"Sign out"));
    }
    @Test public void conversationShowsAuthorizedCustomerContactDetails()throws Exception{
        signedIn();invoke("openConversation",new Class<?>[]{int.class},1041);invoke("renderCustomer",new Class<?>[]{JSONObject.class},new JSONObject().put("name","Alex Santos").put("email","alex@example.invalid").put("phone","+63 900 000 0000"));snapshot("customer-details",393,852);String value=((TextView)field("customerDetails")).getText().toString();assertTrue(value.contains("Alex Santos"));assertTrue(value.contains("alex@example.invalid"));assertTrue(value.contains("+63 900 000 0000"));
    }
    @Test public void foregroundAlertUsesTheActualCustomerMessage(){
        android.app.NotificationManager manager=activity.getSystemService(android.app.NotificationManager.class);manager.cancelAll();Alerts.show(activity,1041,false,"customer-preview-fixture","Can we arrange installation on Friday?");android.service.notification.StatusBarNotification[] active=manager.getActiveNotifications();assertEquals(1,active.length);Notification n=active[0].getNotification();assertEquals("Can we arrange installation on Friday?",n.extras.getCharSequence(Notification.EXTRA_TEXT).toString());assertEquals(Notification.VISIBILITY_PRIVATE,n.visibility);manager.cancelAll();
    }
    @Test public void suppliedBrandIsAvailableAsAnAdaptiveLauncherIcon()throws Exception{
        android.graphics.drawable.Drawable icon=activity.getDrawable(R.mipmap.ic_launcher);assertTrue("Launcher resource must be adaptive",icon instanceof android.graphics.drawable.AdaptiveIconDrawable);Bitmap bitmap=Bitmap.createBitmap(108,108,Bitmap.Config.ARGB_8888);icon.setBounds(0,0,108,108);icon.draw(new Canvas(bitmap));int colored=0;for(int y=0;y<108;y++)for(int x=0;x<108;x++){int color=bitmap.getPixel(x,y);if(Color.alpha(color)>0&&Color.blue(color)>Color.red(color)+20)colored++;}assertTrue("Blue/cyan brand artwork should be visible",colored>250);File directory=new File("build/reports/ui");assertTrue(directory.isDirectory()||directory.mkdirs());try(FileOutputStream stream=new FileOutputStream(new File(directory,"launcher-icon.png"))){assertTrue(bitmap.compress(Bitmap.CompressFormat.PNG,100,stream));}bitmap.recycle();
    }
    @Test public void alertsAndLeadsKeepTheirConversationActions()throws Exception{
        signedIn();invoke("alerts");JSONArray alerts=new JSONArray().put(new JSONObject().put("id",7).put("title","A customer requested human support").put("conversation_id",1042).put("read_at",JSONObject.NULL).put("created_at","2026-10-28 06:35:00")).put(new JSONObject().put("id",6).put("title","New customer message").put("conversation_id",1041).put("read_at","2026-10-28 06:32:00").put("created_at","2026-10-28 06:30:00"));
        invoke("renderAlerts",new Class<?>[]{JSONArray.class},alerts);snapshot("alerts",393,852);assertNotNull(find(root(),"New customer message"));
        invoke("leads");JSONArray leads=new JSONArray().put(new JSONObject().put("id",3).put("name","Alex Santos").put("conversation_id",1042).put("status","interested").put("phone","+63 900 000 0000").put("email","alex@example.invalid").put("interest","Home water filtration and installation"));invoke("renderLeads",new Class<?>[]{JSONArray.class},leads);snapshot("leads",393,852);assertNotNull(find(root(),"Open chat"));assertNotNull(find(root(),"Update status"));
    }
    @Test @Config(qualifiers="w320dp-h760dp-mdpi") public void compactPhoneKeepsChatControlsInsideViewport()throws Exception{
        android.content.res.Configuration config=new android.content.res.Configuration(activity.getResources().getConfiguration());config.fontScale=1.3f;activity.getResources().updateConfiguration(config,activity.getResources().getDisplayMetrics());signedIn();invoke("openConversation",new Class<?>[]{int.class},12);snapshot("compact-chat",320,760);View send=find(root(),"Send reply");int[] position=new int[2];send.getLocationOnScreen(position);assertTrue(position[0]>=0);assertTrue(position[0]+send.getWidth()<=320);assertTrue(send.getHeight()>=48);
    }
}
