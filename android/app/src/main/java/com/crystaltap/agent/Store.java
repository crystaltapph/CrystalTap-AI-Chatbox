package com.crystaltap.agent;
import android.content.Context;
import android.security.keystore.KeyGenParameterSpec;
import android.security.keystore.KeyProperties;
import android.util.Base64;
import org.json.JSONObject;
import java.security.KeyStore;
import javax.crypto.Cipher;
import javax.crypto.KeyGenerator;
import javax.crypto.SecretKey;
import javax.crypto.spec.GCMParameterSpec;

final class Store {
    private final Context context;
    Store(Context c){context=c.getApplicationContext();}
    private SecretKey key()throws Exception{KeyStore s=KeyStore.getInstance("AndroidKeyStore");s.load(null);if(!s.containsAlias("crystaltap-session")){KeyGenerator g=KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES,"AndroidKeyStore");g.init(new KeyGenParameterSpec.Builder("crystaltap-session",KeyProperties.PURPOSE_ENCRYPT|KeyProperties.PURPOSE_DECRYPT).setBlockModes(KeyProperties.BLOCK_MODE_GCM).setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE).build());g.generateKey();}return (SecretKey)s.getKey("crystaltap-session",null);}
    synchronized JSONObject read(){try{String raw=context.getSharedPreferences("private-session",0).getString("data","");if(raw.isEmpty())return new JSONObject();String[] parts=raw.split(":");Cipher c=Cipher.getInstance("AES/GCM/NoPadding");c.init(Cipher.DECRYPT_MODE,key(),new GCMParameterSpec(128,Base64.decode(parts[0],Base64.NO_WRAP)));return new JSONObject(new String(c.doFinal(Base64.decode(parts[1],Base64.NO_WRAP)),java.nio.charset.StandardCharsets.UTF_8));}catch(Exception e){clear();return new JSONObject();}}
    synchronized void write(JSONObject data)throws Exception{Cipher c=Cipher.getInstance("AES/GCM/NoPadding");c.init(Cipher.ENCRYPT_MODE,key());String value=Base64.encodeToString(c.getIV(),Base64.NO_WRAP)+":"+Base64.encodeToString(c.doFinal(data.toString().getBytes(java.nio.charset.StandardCharsets.UTF_8)),Base64.NO_WRAP);context.getSharedPreferences("private-session",0).edit().putString("data",value).commit();}
    void clear(){context.getSharedPreferences("private-session",0).edit().clear().commit();}
}
