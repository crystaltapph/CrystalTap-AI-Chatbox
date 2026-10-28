package com.crystaltap.agent;

import android.Manifest;
import android.app.*;
import android.content.*;
import android.content.pm.PackageManager;
import android.graphics.*;
import android.graphics.drawable.GradientDrawable;
import android.net.Uri;
import android.os.*;
import android.provider.Settings;
import android.text.*;
import android.text.method.LinkMovementMethod;
import android.text.style.URLSpan;
import android.view.*;
import android.widget.*;
import com.google.firebase.messaging.FirebaseMessaging;
import org.json.*;
import java.security.*;
import java.util.*;
import java.util.concurrent.*;
import java.util.regex.*;

public final class MainActivity extends Activity {
    private final Handler handler=new Handler(Looper.getMainLooper());
    private final ExecutorService network=Executors.newFixedThreadPool(3);
    private Store store; private JSONObject session; private Api api;
    private LinearLayout root,body,history,actions,bottomNav;private ScrollView scroll;private TextView availabilityLabel,inboxSummary;private Button sendButton;private String availability="offline";private TextView notice,typing,status;private EditText reply;private CheckBox note;
    private int epoch=0,conversation=0,lastSeq=0,pickingConversation=0;private JSONObject current;private boolean active=false,polling=false,sending=false;
    private long lastTyping=0,lastHeartbeat=0;private String section="";private LinearLayout inboxItems;private String pendingKey="",pendingText="",pendingSignature="";private boolean pendingPrivate=false;
    private final ArrayList<Photo> photos=new ArrayList<>();private TextView photoNames;
    interface Work {Object call()throws Exception;} interface Done {void accept(Object value)throws Exception;}
    private static final class Photo{Uri uri;String key=UUID.randomUUID().toString();int id;Photo(Uri value){uri=value;}}
    private final Runnable poll=new Runnable(){public void run(){if(!active)return;if(!polling&&!sending){if(session.optString("token").isEmpty()){if(session.has("pair"))complete();}else if(conversation>0){refresh();}else if(section.equals("Inbox")){loadInbox();} }if(!session.optString("token").isEmpty()&&System.currentTimeMillis()-lastHeartbeat>60000){lastHeartbeat=System.currentTimeMillis();network.execute(()->{try{api.get("agent/availability");}catch(Exception ignored){}});}handler.postDelayed(this,4000);}};
    @Override public void onCreate(Bundle state){super.onCreate(state);getWindow().addFlags(WindowManager.LayoutParams.FLAG_SECURE);store=new Store(this);session=store.read();init();}
    private void init(){api=new Api(session.optString("api"),session.optString("token"));if(session.optString("token").isEmpty()){if(session.has("pair"))waiting();else signIn();}else{inbox();configurePush();int id=notificationConversation(getIntent());if(id>0)openConversation(id);}}
    @Override protected void onResume(){super.onResume();active=true;handler.removeCallbacks(poll);handler.post(poll);if(session!=null&&!session.optString("token").isEmpty())configurePush();}
    @Override protected void onPause(){active=false;handler.removeCallbacks(poll);sendTyping(false);super.onPause();}
    @Override protected void onDestroy(){handler.removeCallbacksAndMessages(null);network.shutdown();super.onDestroy();}
    @Override protected void onNewIntent(Intent intent){super.onNewIntent(intent);setIntent(intent);int id=notificationConversation(intent);if(id>0&&!session.optString("token").isEmpty())openConversation(id);}
    private int notificationConversation(Intent intent){if(intent==null)return 0;String site=intent.getStringExtra("site");if(site!=null&&!site.equals(session.optString("site")))return 0;Bundle extras=intent.getExtras();return AlertRoute.conversation(extras==null?null:extras.get("conversation"));}
    @Override public void onBackPressed(){if(sending){show("Wait for the send to finish, or retry if it fails.");return;}if(conversation>0)inbox();else super.onBackPressed();}
    private int dp(int n){return (int)(n*getResources().getDisplayMetrics().density);}
    private TextView text(String value,int size){return Ui.text(this,value,size,Ui.INK,false);}
    private LinearLayout column(){return Ui.column(this);}
    private Button button(String label,Runnable click){return Ui.button(this,label,false,click);}
    private Button primary(String label,Runnable click){return Ui.button(this,label,true,click);}
    private EditText input(String hint){return Ui.input(this,hint);}
    private void label(LinearLayout parent,String value){parent.addView(Ui.heading(this,value));}
    private void gap(LinearLayout parent,int value){Ui.space(parent,value);}
    private String friendly(String value){switch(value){case "ai_active":return "AI assistant";case "waiting":return "Waiting";case "human_requested":return "Support requested";case "assigned":return "Assigned";case "human_active":return "In progress";case "quotation_sent":return "Quotation sent";default:return value.isEmpty()?"New":value.substring(0,1).toUpperCase(Locale.ROOT)+value.substring(1).replace('_',' ');}}
    private String initials(String value){String[] parts=value.trim().split("\\s+");if(value.trim().isEmpty())return "CT";String result=parts[0].substring(0,1);if(parts.length>1)result+=parts[parts.length-1].substring(0,1);return result.toUpperCase(Locale.ROOT);}
    private String localTime(String raw){try{java.text.SimpleDateFormat parser=new java.text.SimpleDateFormat("yyyy-MM-dd HH:mm:ss",Locale.US);parser.setTimeZone(TimeZone.getTimeZone("UTC"));Date date=parser.parse(raw);java.text.DateFormat f=android.text.format.DateFormat.getTimeFormat(this);return f.format(date);}catch(Exception ignored){return "";}}
    private String shortDate(String raw){try{java.text.SimpleDateFormat parser=new java.text.SimpleDateFormat("yyyy-MM-dd HH:mm:ss",Locale.US);parser.setTimeZone(TimeZone.getTimeZone("UTC"));Date date=parser.parse(raw);java.text.DateFormat f=new java.text.SimpleDateFormat("MMM d · h:mm a",Locale.getDefault());return f.format(date);}catch(Exception ignored){return raw;}}
    private String duration(int seconds){return seconds<60?seconds+"s":seconds<3600?(seconds/60)+"m":(seconds/3600)+"h";}
    private TextView stateChip(String state){boolean waiting=state.equals("waiting")||state.equals("human_requested");return Ui.chip(this,friendly(state),waiting?Ui.AMBER:Ui.TEAL,waiting?0xfffff3d8:Ui.SOFT);}
    private void screen(String title){
        section=title;inboxItems=null;epoch++;conversation=0;polling=false;current=null;reply=null;sendButton=null;photos.clear();pendingKey="";
        root=column();root.setBackgroundColor(Ui.BG);
        root.setOnApplyWindowInsetsListener((v,i)->{v.setPadding(i.getSystemWindowInsetLeft(),i.getSystemWindowInsetTop(),i.getSystemWindowInsetRight(),i.getSystemWindowInsetBottom());return i;});
        setContentView(root);
        boolean chat=title.startsWith("Conversation #"),signed=!session.optString("token").isEmpty();
        LinearLayout header=Ui.row(this);header.setPadding(dp(20),dp(16),dp(20),dp(14));
        if(chat){header.addView(Ui.iconButton(this,"back","Back to inbox",this::onBackPressed));gapHorizontal(header,12);}
        else{ImageView logo=new ImageView(this);logo.setImageDrawable(new Ui.Icon(this,"drop",Ui.TEAL));logo.setPadding(dp(8),dp(8),dp(8),dp(8));Ui.surface(logo,Ui.SOFT,14,0,false);header.addView(logo,Ui.params(dp(44),dp(44)));gapHorizontal(header,10);}
        LinearLayout brand=column();brand.addView(Ui.text(this,chat?title:"CrystalTap",chat?18:21,Ui.INK,true));gap(brand,3);brand.addView(Ui.text(this,chat?"Customer conversation":"AGENT WORKSPACE",10,Ui.MUTED,true));header.addView(brand,new LinearLayout.LayoutParams(0,-2,1));
        if(signed&&!chat){TextView avatar=Ui.avatar(this,initials(session.optString("name","Agent")),Ui.TEAL,Ui.SOFT,40);avatar.setContentDescription("Open your profile");avatar.setOnClickListener(v->{if(!sending)profile();});avatar.setFocusable(true);header.addView(avatar);}
        root.addView(header);
        notice=Ui.text(this,"",13,0xff895714,false);notice.setPadding(dp(16),dp(12),dp(16),dp(12));Ui.surface(notice,0xfffff3d8,12,0,false);notice.setAccessibilityLiveRegion(View.ACCESSIBILITY_LIVE_REGION_POLITE);notice.setVisibility(View.GONE);LinearLayout.LayoutParams np=Ui.params(-1,-2);np.setMargins(dp(20),0,dp(20),dp(10));root.addView(notice,np);
        scroll=new ScrollView(this);scroll.setFillViewport(true);scroll.setClipToPadding(false);scroll.setVerticalScrollBarEnabled(false);body=column();body.setPadding(dp(chat?16:20),dp(4),dp(chat?16:20),dp(16));scroll.addView(body);root.addView(scroll,new LinearLayout.LayoutParams(-1,0,1));
        bottomNav=null;if(signed&&!chat)buildNavigation();
    }
    private void gapHorizontal(LinearLayout row,int width){row.addView(new View(this),Ui.params(dp(width),1));}
    private void buildNavigation(){
        bottomNav=Ui.row(this);bottomNav.setBackgroundColor(Ui.WHITE);bottomNav.setPadding(dp(8),dp(8),dp(8),dp(8));
        String[] names={"Inbox","Alerts","Leads","Profile"},icons={"inbox","bell","leads","profile"};Runnable[] clicks={this::inbox,this::alerts,this::leads,this::profile};
        for(int i=0;i<names.length;i++){boolean selected=section.equals(names[i])||(i==1&&section.equals("Notifications"))||(i==2&&section.equals("Customer leads"))||(i==3&&section.equals("Profile & notifications"));LinearLayout item=column();item.setGravity(Gravity.CENTER);item.setMinimumHeight(dp(56));item.setPadding(dp(4),dp(6),dp(4),dp(6));Ui.surface(item,selected?Ui.SOFT:Ui.WHITE,16,0,true);ImageView icon=new ImageView(this);icon.setImageDrawable(new Ui.Icon(this,icons[i],selected?Ui.TEAL:Ui.MUTED));item.addView(icon,Ui.params(dp(23),dp(23)));gap(item,5);TextView navLabel=Ui.text(this,names[i],11,selected?Ui.TEAL:Ui.MUTED,selected);navLabel.setGravity(Gravity.CENTER);item.addView(navLabel);Runnable action=clicks[i];item.setOnClickListener(v->{if(!sending)action.run();else show("Wait for sending to finish.");});item.setFocusable(true);item.setContentDescription(names[i]+(selected?", selected":""));item.setSelected(selected);bottomNav.addView(item,new LinearLayout.LayoutParams(0,-2,1));}
        Ui.line(root);root.addView(bottomNav);
    }
    private void pageTitle(String title,String subtitle){gap(body,10);body.addView(Ui.text(this,title,28,Ui.INK,true));gap(body,7);body.addView(Ui.text(this,subtitle,14,Ui.MUTED,false));gap(body,22);}
    private void empty(LinearLayout target,String icon,String title,String subtitle){LinearLayout box=Ui.card(this);box.setGravity(Gravity.CENTER);box.setPadding(dp(24),dp(36),dp(24),dp(36));ImageView glyph=new ImageView(this);glyph.setImageDrawable(new Ui.Icon(this,icon,Ui.TEAL));glyph.setPadding(dp(18),dp(18),dp(18),dp(18));Ui.surface(glyph,Ui.SOFT,32,0,false);box.addView(glyph,Ui.params(dp(64),dp(64)));gap(box,18);TextView heading=Ui.text(this,title,19,Ui.INK,true);heading.setGravity(Gravity.CENTER);box.addView(heading);gap(box,8);TextView copy=Ui.text(this,subtitle,14,Ui.MUTED,false);copy.setGravity(Gravity.CENTER);box.addView(copy);target.addView(box);}
    private void show(String value){if(notice!=null){notice.setText(value);notice.setVisibility(value.isEmpty()?View.GONE:View.VISIBLE);}}

    private void task(Work work,Done done){task(work,done,()->{});}
    private void task(Work work,Done done,Runnable failed){if(network.isShutdown())return;int stamp=epoch;network.execute(()->{try{Object value=work.call();handler.post(()->{if(isFinishing()||isDestroyed()||stamp!=epoch)return;try{done.accept(value);}catch(Exception e){show("The response could not be displayed. Refresh and retry.");failed.run();}});}catch(Exception e){handler.post(()->{if(isFinishing()||isDestroyed()||stamp!=epoch)return;if(e instanceof Api.Failure&&((Api.Failure)e).status==401){store.clear();session=new JSONObject();signIn();show("Your Android session expired. Sign in again.");}else show(e instanceof Api.Failure?e.getMessage():"Connection failed. Check your internet connection and retry.");failed.run();});}});}
    private JSONObject json(String key,Object value)throws JSONException{return new JSONObject().put(key,value);}
    private void browser(String url){try{Uri uri=Uri.parse(url);if(!"https".equals(uri.getScheme())&&!"http".equals(uri.getScheme()))return;startActivity(new Intent(Intent.ACTION_VIEW,uri));}catch(Exception e){show("No browser is available to open this link.");}}
    private void signIn(){
        screen("Sign in");gap(body,18);LinearLayout hero=Ui.card(this);Ui.surface(hero,Ui.NAVY,24,0,false);hero.setPadding(dp(24),dp(30),dp(24),dp(28));
        hero.addView(Ui.chip(this,"CRYSTALTAP AGENT",0xffa9e7df,0xff264d59));gap(hero,22);hero.addView(Ui.text(this,"Great support.\nWherever you are.",30,Ui.WHITE,true));gap(hero,14);hero.addView(Ui.text(this,"A calm space for your customer conversations.",15,0xffc0d6dc,false));body.addView(hero);gap(body,8);
        LinearLayout form=Ui.card(this);form.addView(Ui.text(this,"Welcome back",23,Ui.INK,true));gap(form,8);form.addView(Ui.text(this,"Connect to your team’s website to get started.",14,Ui.MUTED,false));gap(form,20);label(form,"Your workspace");
        EditText site=input("https://your-website.com");site.setText("https://crystaltapwater.com");site.setInputType(android.text.InputType.TYPE_CLASS_TEXT|android.text.InputType.TYPE_TEXT_VARIATION_URI);site.setSingleLine(true);form.addView(site);gap(form,10);
        form.addView(primary("Continue with WordPress",()->task(()->{String address=Api.website(site.getText().toString());String verifier=hex(random(32));Api a=new Api(address+"/wp-json/crystaltap-ai/v1/","");JSONObject pair=a.post("native/link",json("challenge",hex(MessageDigest.getInstance("SHA-256").digest(verifier.getBytes(java.nio.charset.StandardCharsets.US_ASCII)))));String trusted=Api.website(pair.getString("site"));if(!Api.sameSite(address,trusted)||!Api.sameSite(address,pair.getString("api"))||!Api.sameSite(address,pair.getString("verification_url")))throw new Exception("Site mismatch");JSONObject next=new JSONObject().put("site",trusted).put("api",pair.getString("api")).put("pair",pair).put("verifier",verifier);store.write(next);return next;},value->{session=(JSONObject)value;api=new Api(session.getString("api"),"");waiting();browser(session.getJSONObject("pair").getString("verification_url"));})));
        gap(form,12);form.addView(Ui.setting(this,"shield","Secure website sign-in","Approve the matching code in your browser.",null));body.addView(form);
    }

    private static byte[] random(int n){byte[] b=new byte[n];new SecureRandom().nextBytes(b);return b;}
    private static String hex(byte[] b){StringBuilder s=new StringBuilder();for(byte value:b)s.append(String.format(java.util.Locale.ROOT,"%02x",value&255));return s.toString();}
    private void waiting(){
        screen("Approve sign-in");JSONObject pair=session.optJSONObject("pair");if(pair==null){signIn();return;}
        pageTitle("One last step","Approve this sign-in on your website.");LinearLayout card=Ui.card(this);label(card,"Your verification code");TextView code=Ui.text(this,pair.optString("code"),32,Ui.TEAL,true);code.setLetterSpacing(.13f);code.setGravity(Gravity.CENTER);code.setPadding(dp(6),dp(24),dp(6),dp(24));Ui.surface(code,Ui.SOFT,16,0,false);card.addView(code);gap(card,16);card.addView(Ui.text(this,"Continue only if the code in your browser matches this one.",14,Ui.MUTED,false));gap(card,12);card.addView(primary("Open website approval",()->browser(pair.optString("verification_url"))));card.addView(button("I approved · continue",this::complete));body.addView(card);body.addView(button("Start again",()->{store.clear();session=new JSONObject();signIn();}));
    }

    private void complete(){if(polling)return;polling=true;task(()->api.post("native/link/complete",new JSONObject().put("id",session.getJSONObject("pair").getString("id")).put("verifier",session.getString("verifier"))),value->{polling=false;JSONObject r=(JSONObject)value;if(r.optBoolean("pending")){show("Waiting for you to approve the code in WordPress.");return;}if(!Api.sameSite(session.getString("site"),r.getString("api")))throw new Exception("Site mismatch");session=r;store.write(session);api=new Api(session.getString("api"),session.getString("token"));inbox();configurePush();},()->polling=false);}
    private void configurePush(){if(api==null||session.optString("token").isEmpty())return;task(()->api.get("native/config"),value->{JSONObject r=(JSONObject)value;session.put("firebase",r.optJSONObject("firebase")).put("user",r.optJSONObject("user")).put("worker",r.optJSONObject("worker"));store.write(session);if(((CrystalApp)getApplication()).initialize(r.optJSONObject("firebase"))){FirebaseMessaging.getInstance().getToken().addOnSuccessListener(token->{if(network.isShutdown())return;network.execute(()->{try{api.request("native/device","PUT",json("token",token).put("system_notifications",true));}catch(Exception ignored){handler.post(()->show("Could not save Android notification registration. Check your connection and reopen Profile."));}});}).addOnFailureListener(error->show("Android push registration failed. Check Google Play services and Firebase configuration."));}});}
    private void inbox(){
        screen("Inbox");pageTitle("Your inbox","Good conversations start here.");
        LinearLayout toolbar=Ui.row(this);availabilityLabel=Ui.chip(this,"Availability",Ui.MUTED,Ui.WHITE);availabilityLabel.setPadding(dp(14),dp(13),dp(14),dp(13));availabilityLabel.setMinimumHeight(dp(48));availabilityLabel.setGravity(Gravity.CENTER);availabilityLabel.setCompoundDrawablesWithIntrinsicBounds(null,null,new Ui.Icon(this,"down",Ui.TEAL),null);availabilityLabel.setCompoundDrawablePadding(dp(8));Ui.surface(availabilityLabel,Ui.WHITE,14,Ui.LINE,true);availabilityLabel.setContentDescription("Change your availability");availabilityLabel.setOnClickListener(v->chooseAvailability());availabilityLabel.setFocusable(true);toolbar.addView(availabilityLabel,new LinearLayout.LayoutParams(0,-2,1));gapHorizontal(toolbar,12);toolbar.addView(Ui.iconButton(this,"refresh","Refresh inbox",this::loadInbox));body.addView(toolbar);gap(body,22);
        inboxSummary=Ui.text(this,"Loading conversations…",12,Ui.MUTED,true);body.addView(inboxSummary);gap(body,12);inboxItems=column();body.addView(inboxItems);loadInbox();
        task(()->api.get("agent/availability"),value->{availability=((JSONObject)value).optString("availability","offline");updateAvailability();});
    }
    private void updateAvailability(){if(availabilityLabel!=null){availabilityLabel.setText("●  "+friendly(availability));availabilityLabel.setTextColor(availability.equals("online")?Ui.TEAL:Ui.MUTED);}}
    private void chooseAvailability(){new AlertDialog.Builder(this).setTitle("Your availability").setItems(new String[]{"Online · receive queue alerts","Away","Offline"},(d,index)->{String next=new String[]{"online","away","offline"}[index];task(()->api.request("agent/availability","PUT",json("status",next)),v->{availability=next;updateAvailability();show("Availability set to "+next+".");});}).show();}
    private void loadInbox(){
        if(polling||inboxItems==null)return;polling=true;
        task(()->api.request("agent/conversations?filter=active","GET",null),value->{polling=false;renderInbox((JSONArray)value);},()->polling=false);
    }
    private void renderInbox(JSONArray items)throws Exception{
        if(inboxItems==null)return;inboxItems.removeAllViews();if(inboxSummary!=null)inboxSummary.setText(items.length()+" ACTIVE CONVERSATION"+(items.length()==1?"":"S"));
        if(items.length()==0){empty(inboxItems,"inbox","You’re all caught up","New customer conversations will appear here.");return;}
        for(int i=0;i<items.length();i++){
            JSONObject c=items.getJSONObject(i);int id=c.getInt("id");LinearLayout card=Ui.card(this);Ui.surface(card,Ui.WHITE,20,Ui.LINE,true);
            LinearLayout top=Ui.row(this);top.addView(Ui.avatar(this,"C",Ui.TEAL,Ui.SOFT,44));gapHorizontal(top,12);LinearLayout details=column();details.addView(Ui.text(this,"Conversation #"+id,15,Ui.INK,true));gap(details,5);details.addView(Ui.text(this,localTime(c.optString("updated_at")),11,Ui.MUTED,false));top.addView(details,new LinearLayout.LayoutParams(0,-2,1));
            if(c.optInt("unread")>0)top.addView(Ui.chip(this,String.valueOf(c.optInt("unread")),Ui.WHITE,Ui.TEAL));card.addView(top);gap(card,14);
            String preview=c.optString("preview","");if(preview.isEmpty()||preview.equals("null"))preview="A customer conversation is ready for your team.";TextView summary=Ui.text(this,preview,14,Ui.MUTED,false);summary.setMaxLines(2);summary.setEllipsize(android.text.TextUtils.TruncateAt.END);card.addView(summary);gap(card,14);LinearLayout foot=Ui.row(this);foot.addView(stateChip(c.optString("status")));View spacer=new View(this);foot.addView(spacer,new LinearLayout.LayoutParams(0,1,1));TextView open=Ui.text(this,c.isNull("assigned_agent")?"Take over":"Open chat",12,Ui.TEAL,true);foot.addView(open);card.addView(foot);
            card.setOnClickListener(v->{if(c.isNull("assigned_agent"))new AlertDialog.Builder(this).setTitle("Take over #"+id+"?").setMessage("Claim this conversation to read its messages and respond.").setPositiveButton("Take over",(d,w)->task(()->api.post("agent/conversations/"+id+"/actions/claim",json("version",c.getInt("version"))),r->openConversation(id))).setNegativeButton("Cancel",null).show();else openConversation(id);});card.setFocusable(true);inboxItems.addView(card);
        }
    }

    private void openConversation(int id){
        if(sending){show("Wait for sending to finish.");return;}screen("Conversation #"+id);conversation=id;lastSeq=0;
        status=Ui.text(this,"Connecting to conversation…",12,Ui.MUTED,false);status.setPadding(dp(20),0,dp(20),dp(8));root.addView(status,2);
        HorizontalScrollView actionScroll=new HorizontalScrollView(this);actionScroll.setHorizontalScrollBarEnabled(false);actions=Ui.row(this);actions.setPadding(dp(16),0,dp(16),dp(8));actionScroll.addView(actions);root.addView(actionScroll,3);
        history=column();body.addView(history);typing=Ui.text(this,"",12,Ui.TEAL,false);typing.setPadding(dp(20),dp(4),dp(20),dp(4));typing.setVisibility(View.GONE);root.addView(typing);
        LinearLayout composer=column();composer.setPadding(dp(16),dp(10),dp(16),dp(12));composer.setBackgroundColor(Ui.WHITE);Ui.line(root);root.addView(composer);
        note=new CheckBox(this);note.setText("Private note · team only");note.setTextSize(12);note.setTextColor(Ui.MUTED);note.setButtonTintList(android.content.res.ColorStateList.valueOf(Ui.TEAL));note.setMinimumHeight(dp(40));composer.addView(note);
        reply=input("Write a reply…");reply.setMinLines(1);reply.setMaxLines(4);reply.setGravity(Gravity.TOP|Gravity.START);reply.setInputType(android.text.InputType.TYPE_CLASS_TEXT|android.text.InputType.TYPE_TEXT_FLAG_MULTI_LINE|android.text.InputType.TYPE_TEXT_FLAG_CAP_SENTENCES);composer.addView(reply);
        note.setOnCheckedChangeListener((b,checked)->{sendTyping(false);reply.setHint(checked?"Write a private team note…":"Write a reply…");Ui.surface(reply,checked?0xfffff8e8:Ui.BG,14,checked?0xffeedba7:Ui.LINE,false);});
        photoNames=Ui.text(this,"",12,Ui.TEAL,false);photoNames.setPadding(0,dp(8),0,dp(4));photoNames.setVisibility(View.GONE);composer.addView(photoNames);photoNames.setOnClickListener(v->{if(!sending){photos.clear();photoNames.setText("");photoNames.setVisibility(View.GONE);}});
        gap(composer,8);LinearLayout tools=Ui.row(this);tools.addView(Ui.iconButton(this,"smile","Add an emoji",()->new AlertDialog.Builder(this).setTitle("Add an emoji").setItems(new String[]{"😀","😊","😂","❤️","👍","🙏","😢","🎉"},(d,index)->{String[] faces={"😀","😊","😂","❤️","👍","🙏","😢","🎉"};int start=Math.max(0,reply.getSelectionStart());reply.getText().insert(start,faces[index]);}).show()));gapHorizontal(tools,8);tools.addView(Ui.iconButton(this,"photo","Attach a photo",this::pickImage));tools.addView(new View(this),new LinearLayout.LayoutParams(0,1,1));sendButton=primary("Send reply",this::send);sendButton.setCompoundDrawablesWithIntrinsicBounds(new Ui.Icon(this,"send",Ui.WHITE),null,null,null);sendButton.setCompoundDrawablePadding(dp(8));tools.addView(sendButton,Ui.params(-2,-2));composer.addView(tools);
        reply.addTextChangedListener(new TextWatcher(){public void beforeTextChanged(CharSequence value,int a,int count,int after){}public void onTextChanged(CharSequence value,int a,int before,int count){if(value.length()>0&&!note.isChecked()&&System.currentTimeMillis()-lastTyping>3000){lastTyping=System.currentTimeMillis();sendTyping(true);}handler.removeCallbacks(stopTyping);handler.postDelayed(stopTyping,3000);}public void afterTextChanged(Editable value){}});refresh();
    }

    private final Runnable stopTyping=()->sendTyping(false);
    private void sendTyping(boolean activeTyping){if(api==null||conversation<1||network.isShutdown())return;int id=conversation;network.execute(()->{try{api.post("agent/conversations/"+id+"/typing",json("active",activeTyping));}catch(Exception ignored){}});}
    private void refresh(){if(conversation<1||polling)return;polling=true;int id=conversation,after=lastSeq;task(()->api.get("agent/conversations/"+id+"?after="+after),value->{polling=false;JSONObject r=(JSONObject)value;boolean follow=lastSeq==0||scroll.getChildAt(0).getHeight()-scroll.getHeight()-scroll.getScrollY()<dp(100);current=r.getJSONObject("conversation");JSONArray messages=r.getJSONArray("messages");for(int i=0;i<messages.length();i++){JSONObject m=messages.getJSONObject(i);if(m.getInt("seq")>lastSeq){message(m);lastSeq=m.getInt("seq");}}typing.setText(r.optJSONObject("typing")!=null&&r.getJSONObject("typing").optBoolean("customer")?"Customer is typing…":"");typing.setVisibility(typing.getText().length()>0?View.VISIBLE:View.GONE);status.setText(friendly(current.optString("status"))+" · Conversation #"+id);renderActions();if(follow&&messages.length()>0)scroll.post(()->scroll.fullScroll(View.FOCUS_DOWN));task(()->api.request("agent/conversations/"+id+"/read","PUT",json("seq",lastSeq)),v->{});if(messages.length()==100)handler.post(this::refresh);},()->polling=false);}
    private void renderActions(){
        actions.removeAllViews();String state=current.optString("status");
        if(state.equals("assigned")||state.equals("human_active")){
            action("Resolve","resolve");action("Return to AI","return-to-ai");
            if(session.optJSONObject("user")!=null&&session.optJSONObject("user").optBoolean("supervise")){
                Button transfer=button("Transfer",()->{
                    EditText id=input("Agent ID");id.setInputType(2);
                    new AlertDialog.Builder(this).setTitle("Transfer to agent").setView(id)
                        .setPositiveButton("Transfer",(d,w)->task(()->api.post("agent/conversations/"+conversation+"/actions/transfer",new JSONObject().put("version",current.getInt("version")).put("agent",Integer.parseInt(id.getText().toString()))),v->refresh()))
                        .setNegativeButton("Cancel",null).show();
                });LinearLayout.LayoutParams lp=Ui.params(-2,-2);lp.setMargins(dp(4),0,dp(4),0);actions.addView(transfer,lp);
            }
        }
        if(state.equals("resolved")){action("Reopen","reopen");action("Close","close");}
        if(state.equals("closed"))action("Reopen","reopen");
    }
    private void action(String label,String name){Button control=button(label,()->{if(sending)return;task(()->api.post("agent/conversations/"+conversation+"/actions/"+name,json("version",current.getInt("version"))),v->{current=(JSONObject)v;if(current.isNull("assigned_agent")&&(session.optJSONObject("user")==null||!session.optJSONObject("user").optBoolean("supervise")))inbox();else refresh();});});LinearLayout.LayoutParams p=Ui.params(-2,-2);p.setMargins(dp(4),0,dp(4),0);actions.addView(control,p);}
    private void message(JSONObject m)throws Exception{
        boolean internal=m.optString("visibility").equals("internal"),agent=m.optString("sender").equals("agent");
        LinearLayout row=Ui.row(this);row.setGravity(agent&&!internal?Gravity.END:Gravity.START);LinearLayout.LayoutParams rp=Ui.params(-1,-2);rp.setMargins(0,dp(6),0,dp(6));history.addView(row,rp);
        LinearLayout card=column();card.setPadding(dp(14),dp(12),dp(14),dp(10));Ui.surface(card,internal?0xfffff4d9:agent?Ui.TEAL:Ui.WHITE,18,agent||internal?0:Ui.LINE,false);row.addView(card,Ui.params(-2,-2));
        int color=agent&&!internal?Ui.WHITE:Ui.INK,muted=agent&&!internal?Ui.WHITE:internal?Ui.AMBER:Ui.MUTED;
        String author=internal?"PRIVATE NOTE · TEAM ONLY":agent?"Support team":m.optString("sender").equals("customer")?"Customer":m.optString("sender").equals("assistant")?"CrystalTap AI":"Conversation update";
        card.addView(Ui.text(this,author,10,muted,true),Ui.params(-2,-2));gap(card,7);
        TextView content=Ui.text(this,"",16,color,false);content.setMaxWidth(Math.max(dp(160),(int)(getResources().getDisplayMetrics().widthPixels*.78)-dp(48)));content.setLinkTextColor(agent&&!internal?0xffe0ffff:Ui.TEAL);content.setTextIsSelectable(true);
        SpannableString span=new SpannableString(m.optString("content"));Matcher matcher=Pattern.compile("https?://[^\\s<>\"']+",Pattern.CASE_INSENSITIVE).matcher(span);while(matcher.find())span.setSpan(new URLSpan(matcher.group()),matcher.start(),matcher.end(),Spanned.SPAN_EXCLUSIVE_EXCLUSIVE);content.setText(span);content.setMovementMethod(LinkMovementMethod.getInstance());if(span.length()>0)card.addView(content,Ui.params(-2,-2));
        JSONArray images=m.optJSONArray("attachments");if(images!=null)for(int i=0;i<images.length();i++){int id=images.getJSONObject(i).getInt("id");ImageView image=new ImageView(this);image.setContentDescription("Chat image attachment · tap to enlarge");image.setAdjustViewBounds(true);image.setScaleType(ImageView.ScaleType.CENTER_CROP);Ui.surface(image,Ui.SOFT,12,0,false);image.setClipToOutline(true);gap(card,8);card.addView(image,Ui.params(Math.min(dp(220),(int)(getResources().getDisplayMetrics().widthPixels*.7)),dp(180)));task(()->{byte[] bytes=api.image(id);BitmapFactory.Options bounds=new BitmapFactory.Options();bounds.inJustDecodeBounds=true;BitmapFactory.decodeByteArray(bytes,0,bytes.length,bounds);BitmapFactory.Options options=new BitmapFactory.Options();options.inSampleSize=Math.max(1,Math.max(bounds.outWidth/1000,bounds.outHeight/1000));return BitmapFactory.decodeByteArray(bytes,0,bytes.length,options);},bitmap->{image.setImageBitmap((Bitmap)bitmap);image.setOnClickListener(v->{ImageView full=new ImageView(this);full.setImageBitmap((Bitmap)bitmap);full.setAdjustViewBounds(true);new AlertDialog.Builder(this).setView(full).setPositiveButton("Close",null).show();});});}
        gap(card,7);TextView time=Ui.text(this,localTime(m.optString("created_at")),10,muted,false);time.setGravity(Gravity.END);card.addView(time);
    }

    private void pickImage(){if(sending)return;if(photos.size()>=3){show("Attach up to three images.");return;}pickingConversation=conversation;Intent pick=new Intent(Intent.ACTION_OPEN_DOCUMENT).setType("image/*").addCategory(Intent.CATEGORY_OPENABLE).putExtra(Intent.EXTRA_MIME_TYPES,new String[]{"image/jpeg","image/png","image/webp"});try{startActivityForResult(pick,10);}catch(Exception e){show("No file picker is available.");}}
    @Override protected void onActivityResult(int request,int result,Intent data){super.onActivityResult(request,result,data);if(request==10&&result==RESULT_OK&&data!=null&&data.getData()!=null&&pickingConversation==conversation&&conversation>0){photos.add(new Photo(data.getData()));photoNames.setText(photos.size()+" photo"+(photos.size()==1?"":"s")+" attached · tap to remove");photoNames.setVisibility(View.VISIBLE);}}
    private void sendingState(boolean value){if(actions!=null)for(int i=0;i<actions.getChildCount();i++)actions.getChildAt(i).setEnabled(!value);if(sendButton!=null){sendButton.setEnabled(!value);sendButton.setAlpha(value?.6f:1f);sendButton.setText(value?"Sending…":"Send reply");}}
    private void send(){if(sending||reply==null||current==null)return;String text=reply.getText().toString().trim();if(text.isEmpty()&&photos.isEmpty())return;boolean internal=note.isChecked();String signature=photos.stream().map(p->p.key).collect(java.util.stream.Collectors.joining(","));if(!text.equals(pendingText)||internal!=pendingPrivate||!signature.equals(pendingSignature)||pendingKey.isEmpty()){pendingKey=UUID.randomUUID().toString();pendingText=text;pendingPrivate=internal;pendingSignature=signature;}String key=pendingKey;int id=conversation;sending=true;sendTyping(false);sendingState(true);show("");task(()->{JSONArray ids=new JSONArray();for(Photo p:photos){if(p.id==0){String mime=getContentResolver().getType(p.uri);String ext="image/jpeg".equals(mime)?"jpg":"image/png".equals(mime)?"png":"image/webp".equals(mime)?"webp":"";if(ext.isEmpty())throw new Api.Failure(400,"Choose JPG, PNG or WebP images.");byte[] bytes;try{bytes=Api.read(getContentResolver().openInputStream(p.uri),2097152);}catch(Exception e){throw new Api.Failure(413,"Image unavailable or larger than 2 MB. Choose a smaller JPG, PNG or WebP image.");}p.id=api.upload(id,bytes,mime,ext,p.key).getInt("id");}ids.put(p.id);}return api.post("agent/conversations/"+id+"/messages",new JSONObject().put("content",text).put("key",key).put("internal",internal).put("attachments",ids));},v->{sending=false;sendingState(false);if(reply.getText().toString().trim().equals(text))reply.setText("");photos.clear();photoNames.setText("");photoNames.setVisibility(View.GONE);pendingKey="";show("");refresh();},()->{sending=false;sendingState(false);});}
    private void alerts(){
        screen("Notifications");pageTitle("Your alerts","Updates that need your attention.");
        task(()->api.get("agent/notifications"),value->renderAlerts(((JSONObject)value).getJSONArray("items")));
    }
    private void renderAlerts(JSONArray items)throws Exception{
        if(items.length()==0){empty(body,"bell","A little peace and quiet","Customer support alerts will show up here.");return;}
        for(int i=0;i<items.length();i++){
            JSONObject n=items.getJSONObject(i);boolean unread=n.isNull("read_at");LinearLayout card=Ui.card(this);Ui.surface(card,unread?0xfff0f9f8:Ui.WHITE,18,unread?0xffcae8e3:Ui.LINE,true);
            LinearLayout row=Ui.row(this);ImageView icon=new ImageView(this);icon.setImageDrawable(new Ui.Icon(this,"bell",unread?Ui.TEAL:Ui.MUTED));row.addView(icon,Ui.params(dp(24),dp(24)));gapHorizontal(row,12);LinearLayout copy=column();copy.addView(Ui.text(this,n.optString("title"),15,Ui.INK,unread));gap(copy,7);copy.addView(Ui.text(this,"Conversation #"+n.optInt("conversation_id")+" · "+shortDate(n.optString("created_at")),12,Ui.MUTED,false));row.addView(copy,new LinearLayout.LayoutParams(0,-2,1));if(unread){gapHorizontal(row,8);row.addView(Ui.avatar(this,"",Ui.TEAL,Ui.TEAL,8));}card.addView(row);
            card.setOnClickListener(v->task(()->api.request("agent/notifications/"+n.getInt("id")+"/read","PUT",new JSONObject()),r->openConversation(n.optInt("conversation_id"))));card.setFocusable(true);body.addView(card);
        }
    }
    private void leads(){screen("Customer leads");pageTitle("Customer leads","Keep every inquiry moving forward.");task(()->api.get("agent/leads"),value->renderLeads((JSONArray)value));}
    private void renderLeads(JSONArray items)throws Exception{
        if(items.length()==0){empty(body,"leads","Ready for new connections","Customer inquiries assigned to you appear here.");return;}
        for(int i=0;i<items.length();i++){
            JSONObject lead=items.getJSONObject(i);String name=lead.optString("name","");if(name.isEmpty())name="New inquiry";LinearLayout card=Ui.card(this);LinearLayout top=Ui.row(this);top.addView(Ui.avatar(this,initials(name),Ui.TEAL,Ui.SOFT,44));gapHorizontal(top,12);LinearLayout identity=column();identity.addView(Ui.text(this,name,16,Ui.INK,true));gap(identity,5);identity.addView(Ui.text(this,"Conversation #"+lead.optInt("conversation_id"),12,Ui.MUTED,false));top.addView(identity,new LinearLayout.LayoutParams(0,-2,1));card.addView(top);gap(card,14);card.addView(stateChip(lead.optString("status")));gap(card,10);
            String phone=lead.optString("phone"),email=lead.optString("email"),interest=lead.optString("interest");if(!phone.isEmpty())card.addView(Ui.text(this,phone,14,Ui.MUTED,false));if(!email.isEmpty()){gap(card,4);card.addView(Ui.text(this,email,14,Ui.MUTED,false));}if(!interest.isEmpty()){gap(card,10);card.addView(Ui.text(this,interest,14,Ui.INK,false));}
            gap(card,10);LinearLayout actions=Ui.row(this);Button chat=button("Open chat",()->openConversation(lead.optInt("conversation_id")));actions.addView(chat,new LinearLayout.LayoutParams(0,-2,1));gapHorizontal(actions,8);Button update=button("Update status",()->new AlertDialog.Builder(this).setTitle("Lead status").setItems(new String[]{"New","Contacted","Interested","Quotation sent","Converted","Closed"},(d,index)->task(()->api.request("agent/leads/"+lead.getInt("id"),"PATCH",json("status",new String[]{"new","contacted","interested","quotation_sent","converted","closed"}[index])),v->leads())).show());actions.addView(update,new LinearLayout.LayoutParams(0,-2,1));card.addView(actions);body.addView(card);
        }
    }
    private void notificationHealth(){
        NotificationManager manager=getSystemService(NotificationManager.class);NotificationChannel channel=manager.getNotificationChannel(Alerts.CHANNEL);
        boolean sound=channel!=null&&channel.getImportance()>=NotificationManager.IMPORTANCE_DEFAULT&&channel.getSound()!=null;
        LinearLayout card=Ui.card(this);label(card,"Notifications");card.addView(Ui.setting(this,"bell",manager.areNotificationsEnabled()?"Alerts are allowed":"Enable your alerts",sound?"CrystalTap sound is configured":"Check your notification sound",()->startActivity(new Intent(Settings.ACTION_CHANNEL_NOTIFICATION_SETTINGS).putExtra(Settings.EXTRA_APP_PACKAGE,getPackageName()).putExtra(Settings.EXTRA_CHANNEL_ID,Alerts.CHANNEL))));
        card.addView(button("Enable Android notifications",()->{if(Build.VERSION.SDK_INT>=33&&checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS)!=PackageManager.PERMISSION_GRANTED)requestPermissions(new String[]{Manifest.permission.POST_NOTIFICATIONS},20);configurePush();show("Notification registration refreshed. Allow notifications when Android asks.");}));gap(card,8);
        card.addView(Ui.setting(this,"sound","Test local sound","Check the app’s notification tone.",()->{Alerts.show(this,0,true,UUID.randomUUID().toString());show("Local test requested. Check your phone’s notification tray.");}));Ui.line(card);
        card.addView(Ui.setting(this,"send","Test Firebase push","Send a test directly to this phone.",()->task(()->api.post("native/push-test",new JSONObject()),v->show(((JSONObject)v).getString("message")))));Ui.line(card);
        card.addView(Ui.setting(this,"sound","Sound settings","Choose the tone and alert behavior.",()->startActivity(new Intent(Settings.ACTION_CHANNEL_NOTIFICATION_SETTINGS).putExtra(Settings.EXTRA_APP_PACKAGE,getPackageName()).putExtra(Settings.EXTRA_CHANNEL_ID,Alerts.CHANNEL))));body.addView(card);
        JSONObject worker=session.optJSONObject("worker");
        if(worker!=null){LinearLayout delivery=Ui.card(this);label(delivery,"Website delivery");String age=worker.isNull("last_worker_age_seconds")?"Not processed yet":"Last processed "+duration(worker.optInt("last_worker_age_seconds"))+" ago";delivery.addView(Ui.setting(this,"clock",worker.optString("worker_status"),age,null));LinearLayout counts=Ui.row(this);metric(counts,"Queued alerts",String.valueOf(worker.optInt("pending_push_jobs")));metric(counts,"Oldest waiting",duration(worker.optInt("oldest_pending_push_age_seconds")));delivery.addView(counts);gap(delivery,8);delivery.addView(button("Refresh delivery status",()->task(()->api.get("native/config"),value->{session.put("worker",((JSONObject)value).optJSONObject("worker"));store.write(session);profile();})));body.addView(delivery);}
    }
    private void metric(LinearLayout row,String label,String value){LinearLayout box=column();box.setPadding(dp(12),dp(14),dp(12),dp(14));box.addView(Ui.text(this,value,23,Ui.INK,true));gap(box,5);box.addView(Ui.text(this,label,11,Ui.MUTED,false));row.addView(box,new LinearLayout.LayoutParams(0,-2,1));}
    private void profile(){
        screen("Profile & notifications");pageTitle("Your workspace","A few settings. A smoother day.");
        LinearLayout account=Ui.card(this);LinearLayout row=Ui.row(this);row.addView(Ui.avatar(this,initials(session.optString("name")),Ui.WHITE,Ui.TEAL,52));gapHorizontal(row,14);LinearLayout who=column();who.addView(Ui.text(this,session.optString("name","Support agent"),18,Ui.INK,true));gap(who,5);who.addView(Ui.text(this,session.optString("site").replaceFirst("https://",""),12,Ui.MUTED,false));row.addView(who,new LinearLayout.LayoutParams(0,-2,1));account.addView(row);gap(account,16);boolean configured=session.optJSONObject("firebase")!=null&&!session.optJSONObject("firebase").optString("app_id").isEmpty();account.addView(Ui.chip(this,configured?"Firebase configured":"Notification setup needed",configured?Ui.TEAL:Ui.AMBER,configured?Ui.SOFT:0xfffff3d8));body.addView(account);
        notificationHealth();LinearLayout preferences=Ui.card(this);label(preferences,"Alert preferences");body.addView(preferences);task(()->api.get("agent/preferences"),value->renderPreferences((JSONObject)value,preferences));
        LinearLayout settings=Ui.card(this);label(settings,"App & account");settings.addView(Ui.setting(this,"battery","Background & battery","Allow background activity on your phone.",()->startActivity(new Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS,Uri.parse("package:"+getPackageName())))));Ui.line(settings);settings.addView(Ui.setting(this,"globe","Website management","Open your website’s agent workspace.",()->browser(session.optString("site")+"/crystaltap-agent/")));Ui.line(settings);settings.addView(Ui.setting(this,"logout","Sign out","Stop alerts on this Android device.",this::confirmSignOut));body.addView(settings);
        TextView version=Ui.text(this,"CrystalTap Agent · "+BuildConfig.VERSION_NAME,12,Ui.MUTED,false);version.setGravity(Gravity.CENTER);version.setPadding(0,dp(10),0,dp(16));body.addView(version);
    }
    private void renderPreferences(JSONObject prefs,LinearLayout form){
        String[] keys={"push","message","handoff","assignment","lead","followup"},labels={"Push notifications","Customer messages","Support requests","Conversation assignments","New customer leads","Waiting reminders"};ArrayList<CheckBox> checks=new ArrayList<>();
        for(int i=0;i<keys.length;i++){String key=keys[i];if(!(prefs.opt(key) instanceof Boolean))continue;CheckBox check=new CheckBox(this);check.setText(labels[i]);check.setTag(key);check.setTextSize(14);check.setTextColor(Ui.INK);check.setMinimumHeight(dp(48));check.setButtonTintList(android.content.res.ColorStateList.valueOf(Ui.TEAL));check.setChecked(prefs.optBoolean(key));form.addView(check);checks.add(check);}
        gap(form,16);label(form,"Quiet hours");form.addView(Ui.text(this,"Uses your website’s timezone. Leave blank for all-day alerts.",12,Ui.MUTED,false));gap(form,8);
        LinearLayout times=Ui.row(this);LinearLayout from=column(),until=column();from.addView(Ui.text(this,"From",12,Ui.MUTED,true));until.addView(Ui.text(this,"Until",12,Ui.MUTED,true));EditText start=input("HH:MM"),end=input("HH:MM");start.setSingleLine(true);end.setSingleLine(true);start.setInputType(android.text.InputType.TYPE_CLASS_DATETIME|android.text.InputType.TYPE_DATETIME_VARIATION_TIME);end.setInputType(android.text.InputType.TYPE_CLASS_DATETIME|android.text.InputType.TYPE_DATETIME_VARIATION_TIME);start.setText(prefs.optString("quiet_start"));end.setText(prefs.optString("quiet_end"));from.addView(start);until.addView(end);times.addView(from,new LinearLayout.LayoutParams(0,-2,1));gapHorizontal(times,12);times.addView(until,new LinearLayout.LayoutParams(0,-2,1));form.addView(times);gap(form,10);
        form.addView(primary("Save preferences",()->task(()->{JSONObject p=new JSONObject().put("quiet_start",start.getText().toString()).put("quiet_end",end.getText().toString());for(CheckBox check:checks)p.put((String)check.getTag(),check.isChecked());return api.request("agent/preferences","PUT",p);},v->show("Preferences saved."))));
    }
    private void confirmSignOut(){new AlertDialog.Builder(this).setTitle("Sign out of CrystalTap?").setMessage("You’ll stop receiving alerts on this device until you sign in again.").setPositiveButton("Sign out",(d,w)->task(()->api.post("native/logout",new JSONObject()),v->{try{FirebaseMessaging.getInstance().deleteToken();}catch(Exception ignored){}getSystemService(NotificationManager.class).cancelAll();store.clear();session=new JSONObject();signIn();})).setNegativeButton("Stay signed in",null).show();}
}
