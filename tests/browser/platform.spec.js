const {test,expect}=require('@playwright/test');
const fs=require('node:fs');
const login=JSON.parse(fs.readFileSync('.runtime/dev-login.json','utf8'));
async function signIn(page){
  await page.goto('/wp-login.php');
  // WordPress schedules a delayed username focus; wait for it before filling credentials.
  await expect(page.locator('#user_login')).toBeFocused();
  await page.locator('#user_login').fill(login.username);
  await page.locator('#user_pass').fill(login.password);
  await page.locator('#wp-submit').click();
  await page.waitForURL(/wp-admin/);
}
test('customer widget resumes, sends, hands off and rejects a different visitor',async({page,browser})=>{
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto('/chat-demo/');
  await expect(page.locator('.ctai-widget')).toHaveCount(1);
  await expect(page.getByRole('region',{name:'CrystalTap customer support'})).toBeVisible();
  await page.getByRole('textbox',{name:'Message',exact:true}).fill('Browser test: need a purifier');
  await page.getByRole('button',{name:'Send',exact:true}).click();
  await expect(page.locator('.ctai-customer')).toContainText('Browser test: need a purifier');
  await expect(page.locator('.ctai-system')).toContainText('AI assistance is currently unavailable');
  const data=await page.evaluate(async()=>{const r=await fetch('/wp-json/crystaltap-ai/v1/public/session');return r.json();});
  const id=data.conversations[0].id;
  const other=await browser.newContext();
  await other.request.get('http://localhost:8080/wp-json/crystaltap-ai/v1/public/session');
  const denied=await other.request.get(`http://localhost:8080/wp-json/crystaltap-ai/v1/public/conversations/${id}/messages`);
  expect(denied.status()).toBe(404);await other.close();
  await page.reload();
  await expect(page.locator('.ctai-customer')).toContainText('Browser test: need a purifier');
  await page.locator('.ctai-tools').getByRole('button',{name:'Talk to a person',exact:true}).click();
  await expect(page.locator('.ctai-status')).toHaveText('Waiting for an agent');
  expect(errors).toEqual([]);
});
test('WordPress agent takes over and replies to a visitor; notes stay private',async({page,browser})=>{
  const visitor=await browser.newContext();const customer=await visitor.newPage();
  await customer.goto('http://localhost:8080/chat-demo/');
  await customer.getByRole('textbox',{name:'Message',exact:true}).fill('Browser conversation requiring support');
  await customer.getByRole('button',{name:'Send',exact:true}).click();
  await expect(customer.locator('.ctai-customer')).toBeVisible();
  await customer.locator('.ctai-tools').getByRole('button',{name:'Talk to a person',exact:true}).click();
  const data=await customer.evaluate(async()=>{return(await fetch('/wp-json/crystaltap-ai/v1/public/session')).json();});const id=data.conversations[0].id;
  await signIn(page);await page.goto('/crystaltap-agent/#conversation/'+id);
  await expect(page.getByRole('heading',{name:'Conversation #'+id})).toBeVisible();
  await page.getByRole('button',{name:'Take over',exact:true}).click();
  await expect(page.locator('#ctai-chat-status')).toHaveText('assigned');
  await page.getByRole('textbox',{name:'Agent reply'}).fill('Hello po, a human agent is here.');
  await page.getByRole('button',{name:'Send message',exact:true}).click();
  await expect(page.locator('.ctai-from-agent')).toContainText('Hello po, a human agent is here.');
  await expect(customer.locator('.ctai-agent')).toContainText('Hello po, a human agent is here.',{timeout:15000});
  await page.getByRole('checkbox',{name:'Private note (team only)'}).check();
  await page.getByRole('textbox',{name:'Agent reply'}).fill('Internal fixture note, not for customer.');
  await page.getByRole('button',{name:'Send message',exact:true}).click();
  await expect(page.locator('.ctai-internal')).toContainText('Internal fixture note');
  await customer.reload();await expect(customer.locator('.ctai-customer')).toBeVisible();
  await expect(customer.locator('.ctai-messages')).not.toContainText('Internal fixture note');
  await page.getByRole('button',{name:'Resolve',exact:true}).click();
  await expect(page.locator('#ctai-chat-status')).toHaveText('resolved');await visitor.close();
});
test('mobile app, notification bell, content and settings render without script errors',async({page})=>{
  const errors=[];page.on('pageerror',e=>errors.push(e.message));await signIn(page);
  await page.setViewportSize({width:390,height:844});await page.goto('/crystaltap-agent/');
  await expect(page.getByRole('heading',{name:/Hello,/})).toBeVisible();
  await expect(page.locator('#ctai-badge')).toBeVisible();
  expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
  await page.goto('/crystaltap-agent/#notifications');await expect(page.getByRole('heading',{name:'Notifications',exact:true})).toBeVisible();
  await page.getByRole('button',{name:'Mark displayed and earlier as read'}).click();
  await expect(page.locator('#ctai-badge')).toHaveText('0');
  await page.goto('/crystaltap-agent/#products');await expect(page.getByRole('heading',{name:'Product catalog'})).toBeVisible();
  await page.getByRole('button',{name:'Add product',exact:true}).click();
  await expect(page.getByLabel('Approved content / description')).toBeVisible();
  await page.goto('/crystaltap-agent/#settings');await expect(page.getByRole('heading',{name:'Platform settings'})).toBeVisible();
  await expect(page.getByRole('heading',{name:'Environment health'})).toBeVisible();
  const sw=await page.request.get('/crystaltap-agent/sw.js');expect(sw.status()).toBe(200);expect(sw.headers()['content-type']).toContain('javascript');
  const manifest=await(await page.request.get('/crystaltap-agent/manifest.webmanifest')).json();expect(manifest.scope).toBe('http://localhost:8080/crystaltap-agent/');
  expect(errors).toEqual([]);
});
test('public SSE fallback completes cleanly and mutation CSRF is enforced',async({request})=>{
  const session=await(await request.get('/wp-json/crystaltap-ai/v1/public/session')).json();
  const noCsrf=await request.post('/wp-json/crystaltap-ai/v1/public/conversations');expect(noCsrf.status()).toBe(403);
  const headers={'X-CTAI-CSRF':session.csrf};
  const c=await(await request.post('/wp-json/crystaltap-ai/v1/public/conversations',{headers})).json();
  const stream=await request.post(`/wp-json/crystaltap-ai/v1/public/conversations/${c.id}/stream`,{headers,data:{content:'SSE fallback fixture',key:'streamfallback123456'}});
  expect(stream.headers()['content-type']).toContain('text/event-stream');
  const events=(await stream.text()).trim().split('\n\n');
  expect(events[0]).toContain('event: accepted');
  const complete=events.find(e=>e.startsWith('event: complete'));expect(complete).toBeTruthy();
  expect(JSON.parse(complete.split('\ndata: ')[1]).accepted).toBe(true);
  const crossOrigin=await request.get('/wp-json/crystaltap-ai/v1/public/session',{headers:{Origin:'https://untrusted.example'}});expect(crossOrigin.status()).toBe(403);
  for(const path of ['.runtime/dev-login.json','.git/config','composer.json']) {
    const denied=await request.get('/wp-content/plugins/crystaltap-ai-chatbox/'+path);expect([403,404]).toContain(denied.status());
  }
});

test('profile separates push delivery checks and opt-in foreground sound',async({page})=>{
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.addInitScript(()=>{
    const Native=window.AudioContext;
    window.__tones=0;
    window.AudioContext=class extends Native {
      createOscillator(){const tone=super.createOscillator();const start=tone.start.bind(tone);tone.start=(...args)=>{window.__tones++;return start(...args);};return tone;}
    };
  });
  let id=100;
  await page.route('**/crystaltap-ai/v1/agent/notifications',r=>r.fulfill({json:{unread:1,items:[{id,read_at:null,title:'Test notification'}]}}));
  await signIn(page);await page.goto('/crystaltap-agent/#profile');
  await expect(page.getByRole('heading',{name:'Push delivery checks'})).toBeVisible();
  expect(await page.evaluate(()=>window.__tones)).toBe(0);
  await page.getByRole('button',{name:'Enable / test in-app sound',exact:true}).click();
  await expect(page.locator('#ctai-alert')).toContainText('Test chime played');
  expect(await page.evaluate(()=>window.__tones)).toBe(2);
  await page.clock.install();
  await page.clock.runFor(6000);expect(await page.evaluate(()=>window.__tones)).toBe(2);
  id=101;await page.clock.runFor(6000);
  await expect.poll(()=>page.evaluate(()=>window.__tones)).toBe(4);
  await page.clock.runFor(6000);expect(await page.evaluate(()=>window.__tones)).toBe(4);
  await page.getByRole('button',{name:'Mute in-app sound',exact:true}).click();
  id=102;await page.clock.runFor(6000);expect(await page.evaluate(()=>window.__tones)).toBe(4);
  await page.getByRole('button',{name:'Test push on this device',exact:true}).click();
  await expect(page.locator('#ctai-alert')).toContainText('Enable push on this device first');
  expect(errors).toEqual([]);
});

test('business contact settings publish safe app links in a phone-sized widget',async({page,browser})=>{
  await signIn(page);await page.goto('/crystaltap-agent/#business');
  await expect(page.getByRole('heading',{name:'Business information',exact:true})).toBeVisible();
  const old=await page.evaluate(async()=>{
    const b=window.CTAI_APP;const s=await(await fetch(b.api+'admin/settings',{headers:{'X-WP-Nonce':b.nonce}})).json();
    return Object.fromEntries(['whatsapp_number','messenger_url','viber_number','viber_url'].map(k=>[k,s[k]]));
  });
  const visitor=await browser.newContext({viewport:{width:390,height:844}});
  try {
    await page.locator('[name=whatsapp_number]').fill('+63 (912) 345-6789');
    await page.locator('[name=messenger_url]').fill('https://m.me/CrystalTapFixture');
    await page.locator('[name=viber_number]').fill('+63 912 345 6789');await page.locator('[name=viber_url]').fill('');
    await page.getByRole('button',{name:'Save settings',exact:true}).click();
    await expect(page.locator('#ctai-alert')).toHaveText('Settings saved.');
    const customer=await visitor.newPage();await customer.goto('http://localhost:8080/chat-demo/');
    const channels=customer.getByRole('navigation',{name:'Other ways to contact us'});
    await expect(channels).toBeVisible();
    await expect(channels.getByRole('link',{name:'Contact us on WhatsApp',exact:true})).toHaveAttribute('href','https://wa.me/639123456789');
    await expect(channels.getByRole('link',{name:'Contact us on Messenger',exact:true})).toHaveAttribute('href','https://m.me/CrystalTapFixture');
    await expect(channels.getByRole('link',{name:'Contact us on Viber',exact:true})).toHaveAttribute('href','viber://chat?number=%2B639123456789');
    await expect(channels.getByRole('link',{name:'Contact us on WhatsApp',exact:true})).toHaveAttribute('rel','noopener noreferrer');
    await expect(channels).toContainText('Replies stay in that app');
    expect(await customer.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
    for(const width of [390,320]) {
      await customer.setViewportSize({width,height:844});
      const bounds=await customer.evaluate(()=>{const panel=document.querySelector('.ctai-panel').getBoundingClientRect(),form=document.querySelector('.ctai-composer').getBoundingClientRect();return {panelBottom:panel.bottom,formBottom:form.bottom,formHeight:form.height};});
      expect(bounds.formBottom).toBeLessThanOrEqual(bounds.panelBottom+1);expect(bounds.formHeight).toBeGreaterThan(40);
    }

    await customer.getByRole('textbox',{name:'Message',exact:true}).fill('Contact buttons regression: website chat still works');
    await customer.getByRole('button',{name:'Send',exact:true}).click();
    await expect(customer.locator('.ctai-customer')).toContainText('Contact buttons regression: website chat still works');
  } finally {
    await visitor.close();
    await page.evaluate(async data=>{const b=window.CTAI_APP;const r=await fetch(b.api+'admin/settings',{method:'PUT',headers:{'Content-Type':'application/json','X-WP-Nonce':b.nonce},body:JSON.stringify(data)});if(!r.ok)throw new Error('Could not restore local contact fixture');},old);
  }
});

test('delayed agent requests cannot overwrite a newly opened conversation',async({page})=>{
  await signIn(page);
  let release,started;
  const pending=new Promise(resolve=>{started=resolve;});
  await page.route('**/crystaltap-ai/v1/agent/conversations/8100*',async r=>{
    const id=Number(new URL(r.request().url()).pathname.split('/').at(-1));
    if(id===81001){started();await new Promise(resolve=>{release=resolve;});}
    await r.fulfill({json:{conversation:{id,status:'waiting',assigned_agent:null,version:1},messages:[],customer:null}});
  });
  await page.goto('/crystaltap-agent/#conversation/81001');await pending;
  await page.evaluate(()=>{location.hash='conversation/81002';});
  await expect(page.getByRole('heading',{name:'Conversation #81002',exact:true})).toBeVisible();
  const complete=page.waitForResponse(r=>r.url().endsWith('/agent/conversations/81001'));
  release();await complete;
  // Await a fresh page turn after the delayed fetch has delivered its body.
  await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
  await expect(page.getByRole('heading',{name:'Conversation #81002',exact:true})).toBeVisible();
  await expect(page.getByRole('heading',{name:'Conversation #81001',exact:true})).toHaveCount(0);
});

test('typing a new draft during a slow agent send preserves the draft',async({page})=>{
  await signIn(page);
  await page.route('**/crystaltap-ai/v1/agent/conversations/82001**',async r=>{
    if(r.request().url().endsWith('/messages'))return;
    await r.fulfill({json:{conversation:{id:82001,status:'human_active',assigned_agent:1,version:2},messages:[],customer:null}});
  });
  let release,started;const pending=new Promise(resolve=>{started=resolve;});
  await page.route('**/crystaltap-ai/v1/agent/conversations/82001/messages',async r=>{started();await new Promise(resolve=>{release=resolve;});await r.fulfill({json:{id:1,seq:1}});});
  await page.goto('/crystaltap-agent/#conversation/82001');
  const reply=page.getByRole('textbox',{name:'Agent reply'});await reply.fill('First reply');
  await page.getByRole('button',{name:'Send message',exact:true}).click();await pending;
  await reply.fill('Next unsent draft');release();
  await expect(page.locator('#ctai-send-status')).toHaveText('Message saved on the server.');
  await expect(reply).toHaveValue('Next unsent draft');
});

test('expired visitor session can start a new chat without reloading the page',async({page})=>{
  await page.goto('/chat-demo/');
  const input=page.getByRole('textbox',{name:'Message',exact:true});await input.fill('Session recovery fixture');
  await page.getByRole('button',{name:'Send',exact:true}).click();await expect(page.locator('.ctai-customer')).toBeVisible();
  await page.context().clearCookies();
  await input.fill('Retain my unsent draft');await page.getByRole('button',{name:'Send',exact:true}).click();
  await expect(page.locator('.ctai-error')).toContainText('Select New chat to continue');
  await expect(input).toHaveValue('Retain my unsent draft');
  page.once('dialog',d=>d.accept());await page.getByRole('button',{name:'New chat',exact:true}).click();
  await expect(input).toBeEnabled();await expect(input).toHaveValue('');
  await expect(page.locator('.ctai-customer')).toHaveCount(0);
  await input.fill('Fresh session works');await page.getByRole('button',{name:'Send',exact:true}).click();
  await expect(page.locator('.ctai-customer')).toHaveText(/Fresh session works/);
});

test('an already-waiting app update stays visible while using the workspace',async({page})=>{
  await page.addInitScript(()=>{
    const worker={postMessage(){}};
    Object.defineProperty(navigator,'serviceWorker',{configurable:true,value:{controller:{},register:async()=>({waiting:worker,installing:null,addEventListener(){}}),addEventListener(){}}});
  });
  await signIn(page);await page.goto('/crystaltap-agent/#profile');
  await expect(page.getByRole('heading',{name:'Your workspace'})).toBeVisible();
  await expect(page.getByRole('button',{name:'Apply update',exact:true})).toBeVisible();
  await page.getByRole('button',{name:'Mute in-app sound',exact:true}).click();
  await expect(page.locator('#ctai-alert')).toHaveText('In-app sound muted.');
  await expect(page.getByRole('button',{name:'Apply update',exact:true})).toBeVisible();
});

test('a delayed visitor history response cannot replace a new chat',async({page})=>{
  await page.goto('/chat-demo/');
  const input=page.getByRole('textbox',{name:'Message',exact:true});await input.fill('Old conversation marker');
  await page.getByRole('button',{name:'Send',exact:true}).click();await expect(page.locator('.ctai-customer')).toContainText('Old conversation marker');
  let release,started;const pending=new Promise(resolve=>{started=resolve;});
  await page.route('**/crystaltap-ai/v1/public/conversations/*/messages?after=*',async r=>{
    const response=await r.fetch();started();await new Promise(resolve=>{release=resolve;});await r.fulfill({response});
  },{times:1});
  // Visibility restoration triggers the same history refresh used by the polling timer.
  await page.evaluate(()=>document.dispatchEvent(new Event('visibilitychange')));await pending;
  page.once('dialog',d=>d.accept());await page.getByRole('button',{name:'New chat',exact:true}).click();
  await expect(page.locator('.ctai-customer')).toHaveCount(0);
  const complete=page.waitForResponse(r=>r.url().includes('/messages?after='));release();await complete;
  await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
  await input.fill('New conversation marker');await page.getByRole('button',{name:'Send',exact:true}).click();
  await expect(page.locator('.ctai-customer')).toHaveCount(1);
  await expect(page.locator('.ctai-customer')).toContainText('New conversation marker');
  await expect(page.locator('.ctai-messages')).not.toContainText('Old conversation marker');
});
