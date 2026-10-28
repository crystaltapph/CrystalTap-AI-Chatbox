/* Bundled with pinned Firebase modules. Credentials and signals are never logged. */
import {initializeApp, deleteApp} from 'firebase/app';
import {initializeAuth, inMemoryPersistence, signInWithCustomToken, signOut} from 'firebase/auth';
import {getDatabase, ref, onValue, goOffline} from 'firebase/database';

export function connect({grant,change,status=()=>{}}){
  let stopped=false,app=null,auth=null,db=null,timer=null,identity='',network=false,ready=false,seen=null,refreshing=false,renew=120000;
  let unsub=[],generation=0;
  const state=()=>{if(!stopped)status(network&&ready?'live':'connecting');};
  const detach=async()=>{const oldApp=app,oldAuth=auth,oldDb=db;app=null;auth=null;db=null;for(const stop of unsub)stop();unsub=[];network=false;ready=false;seen=null;if(oldDb)goOffline(oldDb);try{if(oldAuth)await signOut(oldAuth);if(oldApp)await deleteApp(oldApp);}catch(_){}};
  const schedule=delay=>{clearTimeout(timer);if(!stopped)timer=setTimeout(refresh,delay);};
  const failed=()=>{if(!stopped){generation++;clearTimeout(timer);status('fallback');identity='';detach().finally(()=>{if(!stopped)schedule(10000);});}};
  async function refresh(){
    if(stopped||refreshing)return;refreshing=true;const stamp=generation;
    try{
      const data=await grant();if(stopped||stamp!==generation)return;
      if(!data.enabled){await detach();identity='';status('polling');schedule(30000);return;}
      const next=JSON.stringify([data.firebase,data.path]);
      if(next!==identity||!app){await detach();if(stopped||stamp!==generation)return;app=initializeApp(data.firebase,'ctai-live-'+crypto.randomUUID());auth=initializeAuth(app,{persistence:inMemoryPersistence});db=getDatabase(app);identity=next;}
      await signInWithCustomToken(auth,data.token);if(stopped||stamp!==generation)return;
      renew=Math.max(1000,Math.min(120000,Number(data.renew_after)*1000||120000));
      if(!unsub.length){
        unsub.push(onValue(ref(db,'.info/connected'),value=>{const connected=value.val()===true,reconnected=connected&&!network;network=connected;state();if(reconnected&&ready)change();}));
        unsub.push(onValue(ref(db,data.path),value=>{const signal=value.val()?.change??null,first=!ready;ready=true;state();if(first||seen!==signal){seen=signal;change();}},failed));
      }
      schedule(renew);
    }catch(_){if(!stopped&&stamp===generation)failed();}finally{refreshing=false;}
  }
  refresh();
  return {close(){if(stopped)return;stopped=true;generation++;clearTimeout(timer);detach();}};
}
window.CTAILive={connect};
