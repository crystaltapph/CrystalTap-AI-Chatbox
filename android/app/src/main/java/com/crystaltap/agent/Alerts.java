package com.crystaltap.agent;
import android.app.*;
import android.content.*;
import android.media.AudioAttributes;
import android.net.Uri;

final class Alerts {
    static final String CHANNEL="crystaltap_support_v1";
    static void channel(Context c){NotificationChannel channel=new NotificationChannel(CHANNEL,"CrystalTap support alerts",NotificationManager.IMPORTANCE_HIGH);channel.setDescription("Customer support alerts with the CrystalTap tone");channel.enableVibration(true);channel.setVibrationPattern(new long[]{0,350,180,350});channel.setSound(Uri.parse("android.resource://"+c.getPackageName()+"/raw/"+c.getResources().getResourceEntryName(R.raw.crystaltap_alert)),new AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_NOTIFICATION).setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION).build());c.getSystemService(NotificationManager.class).createNotificationChannel(channel);}
    static void show(Context c,int conversation,boolean test,String event){show(c,conversation,test,event,null);}
    static void show(Context c,int conversation,boolean test,String event,String preview){channel(c);String body=NotificationText.body(test,preview);Intent open=new Intent(c,MainActivity.class).putExtra("conversation",conversation).setFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP|Intent.FLAG_ACTIVITY_SINGLE_TOP);PendingIntent pending=PendingIntent.getActivity(c,conversation,open,PendingIntent.FLAG_UPDATE_CURRENT|PendingIntent.FLAG_IMMUTABLE);Notification n=new Notification.Builder(c,CHANNEL).setSmallIcon(R.drawable.ic_notification).setContentTitle("CrystalTap Agent").setContentText(body).setStyle(new Notification.BigTextStyle().bigText(body)).setOnlyAlertOnce(true).setVisibility(Notification.VISIBILITY_PRIVATE).setAutoCancel(true).setContentIntent(pending).build();try{c.getSystemService(NotificationManager.class).notify(event.hashCode(),n);}catch(SecurityException ignored){}}
}
