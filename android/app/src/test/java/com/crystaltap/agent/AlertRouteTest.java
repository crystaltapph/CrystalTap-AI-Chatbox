package com.crystaltap.agent;
import org.junit.Test;
import static org.junit.Assert.*;

public class AlertRouteTest {
    @Test public void routesFirebaseStringExtra(){assertEquals(42,AlertRoute.conversation("42"));}
    @Test public void routesLocalIntegerExtra(){assertEquals(42,AlertRoute.conversation(42));}
    @Test public void handlesTestAndMissingExtras(){assertEquals(0,AlertRoute.conversation("0"));assertEquals(0,AlertRoute.conversation(null));}
    @Test public void rejectsMalformedAndOutOfRangeExtras(){for(Object value:new Object[]{"-1","2147483648","999999999999","42x","https://example.com",true,42.5})assertEquals(0,AlertRoute.conversation(value));}
}
