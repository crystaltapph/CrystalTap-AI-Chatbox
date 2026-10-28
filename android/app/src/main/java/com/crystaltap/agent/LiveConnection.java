package com.crystaltap.agent;

import android.content.Context;
import android.os.Handler;
import android.os.Looper;
import com.google.firebase.FirebaseApp;
import com.google.firebase.FirebaseOptions;
import com.google.firebase.auth.FirebaseAuth;
import com.google.firebase.database.*;
import org.json.JSONObject;
import java.util.concurrent.*;

/** Foreground event hints only. Message contents always come from the WordPress API. */
final class LiveConnection {
    interface GrantSource {JSONObject get() throws Exception;}
    interface Listener {void changed();void state(String value);}
    private final Context context;private final GrantSource source;private final Listener listener;
    private final Handler handler=new Handler(Looper.getMainLooper());
    private final ExecutorService work=Executors.newSingleThreadExecutor();
    private FirebaseApp app;private FirebaseAuth auth;private FirebaseDatabase database;
    private DatabaseReference signal,connected;private ValueEventListener signalListener,connectedListener;
    private boolean closed=false,online=false,ready=false;private String identity="",seen="";private int generation=0;
    private final Runnable renew=this::request;
    LiveConnection(Context c,Api a,Listener l){this(c,()->a.post("agent/realtime",new JSONObject()),l);}
    LiveConnection(Context c,GrantSource s,Listener l){context=c.getApplicationContext();source=s;listener=l;}
    void start(){request();}
    private void schedule(long delay){handler.removeCallbacks(renew);if(!closed)handler.postDelayed(renew,delay);}
    private void request(){
        if(closed)return;final int stamp=generation;
        work.execute(()->{try{JSONObject grant=source.get();handler.post(()->{if(!closed&&stamp==generation)accept(grant,stamp);});}
        catch(Exception ignored){handler.post(()->{if(!closed&&stamp==generation)failed();});}});
    }
    private void accept(JSONObject grant,int stamp){
        try{
            if(!grant.optBoolean("enabled")){detach();listener.state("Regular refresh");schedule(30000);return;}
            JSONObject c=grant.getJSONObject("firebase");String path=grant.getString("path");
            if(!path.matches("ctai/[a-f0-9]{32}/[a-f0-9]{64}/signal"))throw new IllegalArgumentException();
            String next=c.toString()+path;
            if(app==null||!identity.equals(next)){
                detach();identity=next;app=FirebaseApp.initializeApp(context,new FirebaseOptions.Builder().setApplicationId(c.getString("appId")).setApiKey(c.getString("apiKey")).setProjectId(c.getString("projectId")).setDatabaseUrl(c.getString("databaseURL")).build(),"crystaltap-live");
                auth=FirebaseAuth.getInstance(app);database=FirebaseDatabase.getInstance(app);
            }
            auth.signInWithCustomToken(grant.getString("token")).addOnCompleteListener(result->{
                if(closed||stamp!=generation)return;if(!result.isSuccessful()){failed();return;}
                if(signal==null)listen(path);
                schedule(Math.max(1000,Math.min(120000,grant.optLong("renew_after",120)*1000)));
            });
        }catch(Exception ignored){failed();}
    }
    private void state(){if(!closed)listener.state(online&&ready?"Live updates connected":"Connecting live updates…");}
    private void listen(String path){
        final int stamp=generation;
        signal=database.getReference(path);connected=database.getReference(".info/connected");
        connectedListener=new ValueEventListener(){public void onDataChange(DataSnapshot value){if(closed||stamp!=generation)return;boolean next=Boolean.TRUE.equals(value.getValue(Boolean.class)),reconnected=next&&!online;online=next;state();if(reconnected&&ready)listener.changed();}public void onCancelled(DatabaseError error){if(!closed&&stamp==generation)failed();}};
        signalListener=new ValueEventListener(){public void onDataChange(DataSnapshot value){if(closed||stamp!=generation)return;String change=value.child("change").getValue(String.class);if(change==null)change="";boolean first=!ready;ready=true;state();if(first||!seen.equals(change)){seen=change;listener.changed();}}public void onCancelled(DatabaseError error){if(!closed&&stamp==generation)failed();}};
        connected.addValueEventListener(connectedListener);signal.addValueEventListener(signalListener);
    }
    private void failed(){if(closed)return;generation++;detach();listener.state("Live updates unavailable · regular refresh active");schedule(10000);}
    private void detach(){
        if(signal!=null&&signalListener!=null)signal.removeEventListener(signalListener);
        if(connected!=null&&connectedListener!=null)connected.removeEventListener(connectedListener);
        signal=null;connected=null;signalListener=null;connectedListener=null;online=false;ready=false;seen="";identity="";
        try{if(database!=null)database.goOffline();if(auth!=null)auth.signOut();if(app!=null)app.delete();}catch(Exception ignored){}
        database=null;auth=null;app=null;
    }
    void close(){if(closed)return;closed=true;generation++;handler.removeCallbacksAndMessages(null);detach();work.shutdownNow();}
}
