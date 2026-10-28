/* Administrative configuration only; never evaluates pasted JavaScript. */
(() => {
  function parse(source){
    try{const data=JSON.parse(source);if(data.private_key)throw new Error('Use the public web configuration, not a service-account key.');if(data.apiKey&&data.appId&&data.projectId)return Object.fromEntries(['apiKey','appId','projectId','authDomain','databaseURL'].filter(key=>typeof data[key]==='string').map(key=>[key,data[key]]));}catch(error){if(error.message.startsWith('Use the public'))throw error;}
    const result={};
    for(const match of source.matchAll(/\b(apiKey|appId|projectId|authDomain|databaseURL)\s*:\s*("(?:[^"\\]|\\.)*"|'[^'\r\n]*')/g)){
      result[match[1]]=match[2][0]==='"'?JSON.parse(match[2]):match[2].slice(1,-1);
    }
    if(!result.apiKey||!result.appId||!result.projectId)throw new Error('Paste the Firebase web app configuration from Project settings → General.');
    return result;
  }
  window.CTAIRealtimeSettings=async({api,view,message,run,current=()=>true})=>{
    const config=await api('admin/realtime');
    const card=document.createElement('section');card.className='ctai-card';
    card.innerHTML='<h2>Realtime chat</h2><p>Use your Firebase web app configuration and Realtime Database URL. Publish the supplied database rules before enabling live updates. Notifications continue using the existing cron worker.</p><form class="ctai-form"><label>Database URL<input name="database" type="url" required></label><label>Firebase web configuration<textarea name="web" rows="7" required></textarea></label><label><input type="checkbox" name="enabled"> Enable live chat updates</label><button>Save realtime settings</button></form><div class="ctai-toolbar"><button type="button" data-test>Test database connection</button><button type="button" data-rules>Download database rules</button></div><p data-status role="status"></p>';
    const form=card.querySelector('form');form.elements.database.value=config.database_url||'https://crystaltap-ph-default-rtdb.asia-southeast1.firebasedatabase.app';form.elements.web.value=Object.keys(config.firebase||{}).length?JSON.stringify(config.firebase,null,2):'';form.elements.web.placeholder='Paste the firebaseConfig block from your Firebase web app here.';form.elements.enabled.checked=!!config.enabled;
    const report=()=>{card.querySelector('[data-status]').textContent='Live updates: '+(config.enabled?'enabled':'disabled')+(config.status?.last_error?' · '+config.status.last_error:'');};report();
    form.onsubmit=e=>{e.preventDefault();run(async()=>{const saved=await api('admin/realtime','POST',{database_url:form.elements.database.value.trim(),firebase:parse(form.elements.web.value),enabled:form.elements.enabled.checked});Object.assign(config,saved);report();message('Realtime settings saved. Reload the website chat and reopen the Android app, then verify Live status.',false);});};
    card.querySelector('[data-test]').onclick=()=>run(async()=>{const result=await api('admin/realtime/test','POST',{});message(result.message,false);});
    card.querySelector('[data-rules]').onclick=()=>{const blob=new Blob([JSON.stringify(config.rules,null,2)],{type:'application/json'}),url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download='crystaltap-database.rules.json';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);};
    if(current())view.append(card);
  };
})();
