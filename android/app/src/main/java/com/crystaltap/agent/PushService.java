package com.crystaltap.agent;
import com.google.firebase.messaging.FirebaseMessagingService;
import com.google.firebase.messaging.RemoteMessage;
import org.json.JSONObject;

public final class PushService extends FirebaseMessagingService {
    @Override public void onNewToken(String token){JSONObject s=new Store(this).read();if(s.optString("token").isEmpty())return;try{new Api(s.getString("api"),s.getString("token")).request("native/device","PUT",new JSONObject().put("token",token).put("system_notifications",true));}catch(Exception ignored){/* Retried whenever the signed-in app resumes. */}}
    @Override public void onMessageReceived(RemoteMessage message){JSONObject s=new Store(this).read();if(s.optString("token").isEmpty()||!s.optString("site").equals(message.getData().get("site")))return;int conversation;try{conversation=Math.max(0,Integer.parseInt(message.getData().getOrDefault("conversation","0")));}catch(NumberFormatException e){return;}Alerts.show(this,conversation,"1".equals(message.getData().get("test")),message.getData().getOrDefault("event",java.util.UUID.randomUUID().toString()));}
}
