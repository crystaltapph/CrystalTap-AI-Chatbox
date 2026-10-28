package com.crystaltap.agent;

final class NotificationText {
    static String body(boolean test,String preview) {
        if(test)return "Test alert received on this Android app.";
        String value=preview==null?"":preview.replaceAll("[\\p{Cntrl}\\s]+"," ").trim();
        if(value.isEmpty())return "New customer message";
        if(value.codePointCount(0,value.length())>240)return value.substring(0,value.offsetByCodePoints(0,239))+"…";
        return value;
    }
}
