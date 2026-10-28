package com.crystaltap.agent;
import org.junit.Test;
import static org.junit.Assert.*;

public class NotificationTextTest {
    @Test public void displaysTheCustomerMessage(){assertEquals("Can we arrange installation on Friday?",NotificationText.body(false,"Can we arrange installation on Friday?"));}
    @Test public void testAlertsKeepTheirOwnText(){assertEquals("Test alert received on this Android app.",NotificationText.body(true,"Customer message"));}
    @Test public void missingPreviewHasAClearFallback(){assertEquals("New customer message",NotificationText.body(false,null));}
    @Test public void blankPreviewHasAClearFallback(){assertEquals("New customer message",NotificationText.body(false," \n "));}
    @Test public void normalizesControlCharacters(){assertEquals("Hello customer",NotificationText.body(false,"  Hello\n\tcustomer\u0000 "));}
    @Test public void truncatesWithoutBreakingEmoji(){String value=new String(new int[260],0,260).replace("\u0000","💧");String body=NotificationText.body(false,value);assertEquals(240,body.codePointCount(0,body.length()));assertTrue(body.endsWith("…"));assertFalse(Character.isHighSurrogate(body.charAt(body.length()-2)));}
}
