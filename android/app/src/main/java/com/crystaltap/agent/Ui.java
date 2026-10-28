package com.crystaltap.agent;

import android.content.Context;
import android.content.res.ColorStateList;
import android.graphics.*;
import android.graphics.drawable.*;
import android.view.*;
import android.widget.*;

/** Shared native styles. All dimensions are dp and text respects system font scaling. */
final class Ui {
    static final int BG=0xfff4f7fa, INK=0xff173042, MUTED=0xff5d7382;
    static final int TEAL=0xff07777e, SOFT=0xffe6f4f3, LINE=0xffe1e9ee;
    static final int WHITE=Color.WHITE, NAVY=0xff143645, AMBER=0xff805c14;
    static int dp(Context c,int value){return Math.round(value*c.getResources().getDisplayMetrics().density);}
    static GradientDrawable shape(Context c,int color,int radius,int border){
        GradientDrawable d=new GradientDrawable();d.setColor(color);d.setCornerRadius(dp(c,radius));
        if(border!=0)d.setStroke(dp(c,1),border);return d;
    }
    static void surface(View view,int color,int radius,int border,boolean ripple){
        GradientDrawable d=shape(view.getContext(),color,radius,border);
        view.setBackground(ripple?new RippleDrawable(ColorStateList.valueOf(0x18087f86),d,shape(view.getContext(),WHITE,radius,0)):d);
    }
    static TextView text(Context c,String value,int size,int color,boolean bold){
        TextView v=new TextView(c);v.setText(value);v.setTextSize(size);v.setTextColor(color);
        v.setFontFeatureSettings("kern");v.setIncludeFontPadding(false);
        v.setTypeface(Typeface.create(bold?"sans-serif-medium":"sans-serif",Typeface.NORMAL));
        v.setLineSpacing(dp(c,3),1);return v;
    }
    static LinearLayout column(Context c){LinearLayout v=new LinearLayout(c);v.setOrientation(LinearLayout.VERTICAL);return v;}
    static LinearLayout row(Context c){LinearLayout v=new LinearLayout(c);v.setOrientation(LinearLayout.HORIZONTAL);v.setGravity(Gravity.CENTER_VERTICAL);return v;}
    static LinearLayout.LayoutParams params(int w,int h){return new LinearLayout.LayoutParams(w,h);}
    static void space(LinearLayout parent,int height){parent.addView(new View(parent.getContext()),params(1,dp(parent.getContext(),height)));}
    static void line(LinearLayout parent){View v=new View(parent.getContext());v.setBackgroundColor(LINE);parent.addView(v,params(-1,dp(parent.getContext(),1)));}
    static LinearLayout card(Context c){
        LinearLayout v=column(c);surface(v,WHITE,20,LINE,false);int p=dp(c,18);v.setPadding(p,p,p,p);
        LinearLayout.LayoutParams lp=params(-1,-2);lp.bottomMargin=dp(c,14);v.setLayoutParams(lp);return v;
    }
    static Button button(Context c,String title,boolean primary,Runnable click){
        Button b=new Button(c);b.setText(title);b.setAllCaps(false);b.setTextSize(14);b.setTypeface(Typeface.create("sans-serif-medium",Typeface.NORMAL));
        b.setTextColor(primary?WHITE:TEAL);b.setGravity(Gravity.CENTER);b.setMinHeight(dp(c,48));b.setMinimumHeight(dp(c,48));b.setMinWidth(0);b.setMinimumWidth(0);
        b.setPadding(dp(c,16),dp(c,10),dp(c,16),dp(c,10));b.setStateListAnimator(null);
        surface(b,primary?TEAL:SOFT,14,0,true);b.setOnClickListener(v->click.run());
        LinearLayout.LayoutParams lp=params(-1,-2);lp.topMargin=dp(c,8);b.setLayoutParams(lp);return b;
    }
    static EditText input(Context c,String hint){
        EditText v=new EditText(c);v.setHint(hint);v.setTextSize(16);v.setTextColor(INK);v.setHintTextColor(MUTED);
        v.setTypeface(Typeface.create("sans-serif",Typeface.NORMAL));v.setPadding(dp(c,14),dp(c,14),dp(c,14),dp(c,14));
        v.setMinHeight(dp(c,52));surface(v,BG,14,LINE,false);v.setBackgroundTintList(null);
        LinearLayout.LayoutParams lp=params(-1,-2);lp.topMargin=dp(c,8);lp.bottomMargin=dp(c,4);v.setLayoutParams(lp);return v;
    }
    static TextView avatar(Context c,String label,int color,int background,int size){
        TextView v=text(c,label,18,color,true);v.setGravity(Gravity.CENTER);surface(v,background,size/2,0,false);
        v.setLayoutParams(params(dp(c,size),dp(c,size)));return v;
    }
    static TextView chip(Context c,String label,int color,int background){
        TextView v=text(c,label,11,color,true);v.setPadding(dp(c,9),dp(c,5),dp(c,9),dp(c,5));surface(v,background,8,0,false);v.setLayoutParams(params(-2,-2));return v;
    }
    static ImageButton iconButton(Context c,String name,String label,Runnable click){
        ImageButton b=new ImageButton(c);b.setImageDrawable(new Icon(c,name,TEAL));b.setContentDescription(label);
        b.setPadding(dp(c,12),dp(c,12),dp(c,12),dp(c,12));surface(b,SOFT,14,0,true);
        b.setLayoutParams(params(dp(c,48),dp(c,48)));b.setOnClickListener(v->click.run());return b;
    }
    static TextView heading(Context c,String title){TextView v=text(c,title,16,INK,true);v.setPadding(0,dp(c,4),0,dp(c,12));return v;}
    static LinearLayout setting(Context c,String icon,String title,String subtitle,Runnable click){
        LinearLayout row=row(c);row.setPadding(0,dp(c,12),0,dp(c,12));row.setMinimumHeight(dp(c,64));
        ImageView glyph=new ImageView(c);glyph.setImageDrawable(new Icon(c,icon,TEAL));glyph.setPadding(dp(c,10),dp(c,10),dp(c,10),dp(c,10));surface(glyph,SOFT,13,0,false);
        row.addView(glyph,params(dp(c,42),dp(c,42)));LinearLayout labels=column(c);labels.setPadding(dp(c,12),0,dp(c,8),0);
        labels.addView(text(c,title,14,INK,true));if(!subtitle.isEmpty()){space(labels,4);labels.addView(text(c,subtitle,12,MUTED,false));}
        row.addView(labels,new LinearLayout.LayoutParams(0,-2,1));
        if(click!=null){ImageView arrow=new ImageView(c);arrow.setImageDrawable(new Icon(c,"chevron",MUTED));row.addView(arrow,params(dp(c,18),dp(c,18)));surface(row,WHITE,12,0,true);row.setOnClickListener(v->click.run());row.setFocusable(true);}
        return row;
    }
    /** Small consistent stroke icons, independent of emoji fonts or downloaded assets. */
    static final class Icon extends android.graphics.drawable.Drawable {
        private final Paint paint=new Paint(Paint.ANTI_ALIAS_FLAG);private final String name;private final int size;
        Icon(Context c,String value,int color){name=value;size=dp(c,24);paint.setColor(color);paint.setStyle(Paint.Style.STROKE);paint.setStrokeWidth(1.8f);paint.setStrokeCap(Paint.Cap.ROUND);paint.setStrokeJoin(Paint.Join.ROUND);}
        private void path(Canvas c,float...points){Path p=new Path();p.moveTo(points[0],points[1]);for(int i=2;i<points.length;i+=2)p.lineTo(points[i],points[i+1]);c.drawPath(p,paint);}
        @Override public void draw(Canvas c){android.graphics.Rect b=getBounds();c.save();c.translate(b.left,b.top);c.scale(b.width()/24f,b.height()/24f);
            switch(name){
                case "inbox":c.drawRoundRect(3,4,21,18,4,4,paint);path(c,7,18,6,22,12,18);path(c,7,9,17,9);path(c,7,13,13,13);break;
                case "bell":path(c,5,17,6,14,6,10);c.drawArc(6,4,18,16,180,180,false,paint);path(c,18,10,18,14,19,17,5,17);c.drawArc(10,17,14,22,0,180,false,paint);break;
                case "leads":c.drawRoundRect(4,4,20,21,3,3,paint);c.drawCircle(10,10,2,paint);c.drawArc(7,13,13,18,180,180,false,paint);path(c,16,9,17,9);path(c,16,13,17,13);break;
                case "profile":c.drawCircle(12,8,4,paint);c.drawArc(4,14,20,28,180,180,false,paint);break;
                case "refresh":c.drawArc(4,4,20,20,40,285,false,paint);path(c,20,4,20,9,15,9);break;
                case "back":path(c,14,5,7,12,14,19);path(c,7,12,21,12);break;
                case "chevron":path(c,9,6,15,12,9,18);break;
                case "down":path(c,7,10,12,15,17,10);break;
                case "send":path(c,3,4,21,12,3,20,7,12,3,4);path(c,7,12,21,12);break;
                case "photo":c.drawRoundRect(3,3,21,21,4,4,paint);c.drawCircle(8,8,1.5f,paint);path(c,4,17,10,12,14,16,18,12,21,15);break;
                case "smile":c.drawCircle(12,12,9,paint);c.drawPoint(9,9,paint);c.drawPoint(15,9,paint);c.drawArc(7,9,17,17,15,150,false,paint);break;
                case "check":path(c,5,12,10,17,20,7);break;
                case "globe":c.drawCircle(12,12,9,paint);c.drawOval(8,3,16,21,paint);path(c,3,12,21,12);break;
                case "sound":path(c,3,9,7,9,12,5,12,19,7,15,3,15,3,9);c.drawArc(10,6,21,18,-60,120,false,paint);break;
                case "shield":path(c,12,3,20,6,19,15,12,21,5,15,4,6,12,3);path(c,8,11,11,14,16,9);break;
                case "clock":c.drawCircle(12,12,9,paint);path(c,12,7,12,12,16,14);break;
                case "logout":path(c,10,4,4,4,4,20,10,20);path(c,10,12,21,12,17,8);path(c,21,12,17,16);break;
                case "battery":c.drawRoundRect(3,6,20,18,3,3,paint);path(c,22,10,22,14);path(c,7,10,7,14);path(c,11,10,11,14);break;
                default:Path drop=new Path();drop.moveTo(12,2);drop.cubicTo(9,7,5,10,5,15);drop.cubicTo(5,24,19,24,19,15);drop.cubicTo(19,10,15,7,12,2);c.drawPath(drop,paint);path(c,9,16,11,18,15,13);break;
            }c.restore();}
        @Override public void setAlpha(int alpha){paint.setAlpha(alpha);}
        @Override public void setColorFilter(ColorFilter filter){paint.setColorFilter(filter);}
        @Override public int getOpacity(){return PixelFormat.TRANSLUCENT;}
        @Override public int getIntrinsicWidth(){return size;}
        @Override public int getIntrinsicHeight(){return size;}
    }
}
