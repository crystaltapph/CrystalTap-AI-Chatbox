package com.crystaltap.agent;
import org.junit.Test;
import static org.junit.Assert.*;
public class ApiTest {
 @Test public void acceptsHttpsSubfolder()throws Exception{assertEquals("https://example.com/wordpress",Api.website("https://example.com/wordpress/"));}
 @Test public void rejectsCleartext(){try{Api.website("http://example.com");fail();}catch(Exception expected){}}
 @Test public void rejectsUserInfo(){try{Api.website("https://user:password@example.com");fail();}catch(Exception expected){}}
 @Test public void rejectsQuery(){try{Api.website("https://example.com/?token=x");fail();}catch(Exception expected){}}
 @Test public void rejectsFragment(){try{Api.website("https://example.com/#x");fail();}catch(Exception expected){}}
 @Test public void endpointsStayOnTrustedSite()throws Exception{assertTrue(Api.sameSite("https://example.com","https://example.com/wp-json/"));assertFalse(Api.sameSite("https://example.com","https://example.com.evil.invalid/wp-json/"));assertFalse(Api.sameSite("https://example.com","http://example.com/wp-json/"));assertFalse(Api.sameSite("https://example.com","https://example.com:8443/wp-json/"));}
}
