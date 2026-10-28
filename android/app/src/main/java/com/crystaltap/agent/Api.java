package com.crystaltap.agent;
import org.json.JSONObject;
import java.net.*;
import java.io.*;
import java.nio.charset.StandardCharsets;

final class Api {
    static String website(String value)throws Exception{URI u=new URI(value.trim());if(!"https".equals(u.getScheme())||u.getHost()==null||u.getUserInfo()!=null||u.getQuery()!=null||u.getFragment()!=null)throw new Exception("Enter the public HTTPS website address, including any WordPress subfolder.");return u.toString().replaceAll("/+$","");}
    static boolean sameSite(String site,String endpoint)throws Exception{URI a=new URI(website(site)),b=new URI(endpoint);return "https".equals(b.getScheme())&&b.getUserInfo()==null&&a.getHost().equalsIgnoreCase(b.getHost())&&(a.getPort()<0?443:a.getPort())==(b.getPort()<0?443:b.getPort());}
    static class Failure extends Exception{final int status;Failure(int s,String message){super(message);status=s;}}
    final String base,token;
    Api(String base,String token){this.base=base;this.token=token;}
    private HttpURLConnection connection(String path,String method)throws Exception{URL u=new URL(base+path);if(!u.getProtocol().equals("https"))throw new Exception("HTTPS is required.");HttpURLConnection c=(HttpURLConnection)u.openConnection();c.setInstanceFollowRedirects(false);c.setConnectTimeout(15000);c.setReadTimeout(25000);c.setRequestMethod(method);c.setRequestProperty("Accept","application/json");c.setRequestProperty("Cache-Control","no-store");if(!token.isEmpty())c.setRequestProperty("Authorization","Bearer "+token);return c;}
    private byte[] result(HttpURLConnection c,int limit)throws Exception{try{int status=c.getResponseCode();InputStream input=status>=200&&status<300?c.getInputStream():c.getErrorStream();byte[] bytes=read(input,limit);if(status<200||status>=300){String message="Request failed. Check your connection and retry.";try{message=new JSONObject(new String(bytes,StandardCharsets.UTF_8)).optString("message",message);}catch(Exception ignored){}if(status>=300&&status<400)message="The website redirected the request. Use its canonical HTTPS address.";throw new Failure(status,message);}return bytes;}finally{c.disconnect();}}
    static byte[] read(InputStream input,int limit)throws Exception{if(input==null)return new byte[0];try(input;ByteArrayOutputStream out=new ByteArrayOutputStream()){byte[] chunk=new byte[8192];int n;while((n=input.read(chunk))!=-1){if(out.size()+n>limit)throw new Exception("Response exceeds the allowed size.");out.write(chunk,0,n);}return out.toByteArray();}}
    Object request(String path,String method,JSONObject body)throws Exception{HttpURLConnection c=connection(path,method);if(body!=null){c.setDoOutput(true);c.setRequestProperty("Content-Type","application/json");try(OutputStream out=c.getOutputStream()){out.write(body.toString().getBytes(StandardCharsets.UTF_8));}}String text=new String(result(c,8*1024*1024),StandardCharsets.UTF_8);return text.trim().startsWith("[")?new org.json.JSONArray(text):new JSONObject(text);}
    JSONObject get(String path)throws Exception{return (JSONObject)request(path,"GET",null);}
    JSONObject post(String path,JSONObject body)throws Exception{return (JSONObject)request(path,"POST",body);}
    byte[] image(int id)throws Exception{return result(connection("images/"+id,"GET"),3*1024*1024);}
    JSONObject upload(int conversation,byte[] bytes,String mime,String extension,String key)throws Exception{String boundary="ctai-"+java.util.UUID.randomUUID();HttpURLConnection c=connection("agent/conversations/"+conversation+"/images","POST");c.setDoOutput(true);c.setRequestProperty("Content-Type","multipart/form-data; boundary="+boundary);try(OutputStream out=c.getOutputStream()){out.write(("--"+boundary+"\r\nContent-Disposition: form-data; name=\"key\"\r\n\r\n"+key+"\r\n--"+boundary+"\r\nContent-Disposition: form-data; name=\"image\"; filename=\"image."+extension+"\"\r\nContent-Type: "+mime+"\r\n\r\n").getBytes(StandardCharsets.UTF_8));out.write(bytes);out.write(("\r\n--"+boundary+"--\r\n").getBytes(StandardCharsets.UTF_8));}return new JSONObject(new String(result(c,65536),StandardCharsets.UTF_8));}
}
