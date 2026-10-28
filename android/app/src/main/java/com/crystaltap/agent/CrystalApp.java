package com.crystaltap.agent;
import android.app.Application;
import com.google.firebase.FirebaseApp;
import com.google.firebase.FirebaseOptions;
import org.json.JSONObject;

public final class CrystalApp extends Application {
    @Override public void onCreate(){super.onCreate();Alerts.channel(this);initialize(new Store(this).read().optJSONObject("firebase"));}
    synchronized boolean initialize(JSONObject c){if(c==null||c.optString("app_id").isEmpty())return false;try{try{FirebaseApp existing=FirebaseApp.getInstance();if(existing.getOptions().getApplicationId().equals(c.getString("app_id")))return true;existing.delete();}catch(IllegalStateException ignored){}FirebaseApp.initializeApp(this,new FirebaseOptions.Builder().setApplicationId(c.getString("app_id")).setApiKey(c.getString("api_key")).setProjectId(c.getString("project_id")).setGcmSenderId(c.getString("sender_id")).build());return true;}catch(Exception e){return false;}}
}
