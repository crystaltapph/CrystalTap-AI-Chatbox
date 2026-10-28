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
async function startChat(page,name='Browser fixture visitor'){
  const form=page.getByRole('form',{name:'Customer details'});await expect(form).toBeVisible();
  await form.getByLabel('Name (required)',{exact:true}).fill(name);await form.getByRole('button',{name:'Start chat',exact:true}).click();
  await expect(page.getByRole('textbox',{name:'Message',exact:true})).toBeVisible();
}
test('a slow session bootstrap preserves the customer name being typed',async({page})=>{
  let started,release;const pending=new Promise(resolve=>{started=resolve;});await page.route('**/crystaltap-ai/v1/public/session',async r=>{const response=await r.fetch();started();await new Promise(resolve=>{release=resolve;});await r.fulfill({response});},{times:1});
  await page.goto('/chat-demo/');await pending;const form=page.getByRole('form',{name:'Customer details'});await form.getByLabel('Name (required)',{exact:true}).fill('Alex Reyes');await form.getByLabel('Email (optional)',{exact:true}).fill('alex@example.invalid');release();await expect(form.getByRole('button',{name:'Start chat',exact:true})).toBeEnabled();await expect(form.getByLabel('Name (required)',{exact:true})).toHaveValue('Alex Reyes');await expect(form.getByLabel('Email (optional)',{exact:true})).toHaveValue('alex@example.invalid');await form.getByRole('button',{name:'Start chat',exact:true}).click();await expect(page.locator('.ctai-welcome')).toContainText('Alex Reyes');
});
test('configured welcome uses the saved name after start, reload and a new chat',async({page,browser})=>{
  await signIn(page);await page.goto('/crystaltap-agent/#widget');await expect(page.locator('[name=welcome]')).toBeVisible();const old=await page.locator('[name=welcome]').inputValue();
  const visitor=await browser.newContext({viewport:{width:390,height:844}});
  try{
    await page.locator('[name=welcome]').fill('Welcome, {name}! Your CrystalTap team is here.');await page.getByRole('button',{name:'Save settings',exact:true}).click();await expect(page.locator('#ctai-alert')).toHaveText('Settings saved.');
    const customer=await visitor.newPage();await customer.goto('http://localhost:8080/chat-demo/');await startChat(customer,'Alex Reyes');await expect(customer.locator('.ctai-welcome')).toHaveText('Welcome, Alex Reyes! Your CrystalTap team is here.');await expect(customer.locator('.ctai-welcome')).toHaveCount(1);
    await customer.getByRole('button',{name:'Maximize chat',exact:true}).click();const bounds=await customer.locator('.ctai-panel').boundingBox();expect(bounds.y).toBeGreaterThanOrEqual(0);expect(bounds.height).toBeGreaterThan(800);expect(await customer.locator('.ctai-messages').evaluate(e=>e.clientHeight)).toBeGreaterThan(400);await customer.screenshot({path:'.runtime/widget-mobile-maximized.png'});
    await expect(customer.getByRole('button',{name:'Leave contact details',exact:true})).toBeHidden();await customer.locator('.ctai-more>summary').click();await expect(customer.getByRole('button',{name:'Leave contact details',exact:true})).toBeVisible();await customer.locator('.ctai-more>summary').click();
    await customer.getByRole('button',{name:'Restore chat size',exact:true}).click();customer.once('dialog',d=>d.accept());await customer.getByRole('button',{name:'New chat',exact:true}).click();await startChat(customer,'Jamie Santos');await expect(customer.locator('.ctai-welcome')).toHaveText('Welcome, Jamie Santos! Your CrystalTap team is here.');await expect(customer.locator('.ctai-welcome')).toHaveCount(1);
    await customer.reload();await expect(customer.locator('.ctai-welcome')).toHaveText('Welcome, Jamie Santos! Your CrystalTap team is here.');
    const input=customer.getByRole('textbox',{name:'Message',exact:true});await input.fill('Human-only support request');await customer.getByRole('button',{name:'Send',exact:true}).click();await expect(customer.locator('.ctai-status')).toHaveText('Waiting for an agent');await expect(customer.locator('.ctai-system')).toHaveCount(0);
  }finally{await visitor.close();await page.evaluate(async welcome=>{const b=window.CTAI_APP;const r=await fetch(b.api+'admin/settings',{method:'PUT',headers:{'Content-Type':'application/json','X-WP-Nonce':b.nonce},body:JSON.stringify({welcome})});if(!r.ok)throw new Error('Could not restore welcome fixture');},old);}
});
test('floating chat maximizes on desktop and fills a compact phone without hiding the composer',async({page})=>{
  await page.setViewportSize({width:1280,height:900});await page.goto('/');await page.evaluate(()=>document.body.style.overflow='auto');await page.getByRole('button',{name:'Chat with CrystalTap',exact:true}).click();await startChat(page,'Alex Reyes');
  const normal=await page.locator('.ctai-panel').boundingBox();await page.getByRole('button',{name:'Maximize chat',exact:true}).click();const expanded=await page.locator('.ctai-panel').boundingBox();expect(expanded.width).toBeGreaterThan(normal.width);expect(expanded.height).toBeGreaterThan(850);await page.screenshot({path:'.runtime/widget-desktop-maximized.png'});
  await page.keyboard.press('Escape');await expect(page.getByRole('button',{name:'Maximize chat',exact:true})).toBeVisible();expect(await page.evaluate(()=>document.body.style.overflow)).toBe('auto');
  await page.setViewportSize({width:320,height:500});await expect.poll(()=>page.locator('.ctai-panel').evaluate(e=>Math.round(e.getBoundingClientRect().height))).toBe(500);await expect(page.getByRole('textbox',{name:'Message',exact:true})).toBeInViewport();expect(await page.locator('.ctai-messages').evaluate(e=>e.clientHeight)).toBeGreaterThan(100);expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
  await page.getByRole('button',{name:'Minimize chat',exact:true}).click();expect(await page.evaluate(()=>document.body.style.overflow)).toBe('auto');
});
test('customer form requires only name and shows saved optional contacts to agents',async({page})=>{
  await page.setViewportSize({width:390,height:844});await page.goto('/chat-demo/');
  const form=page.getByRole('form',{name:'Customer details'});await expect(form).toBeVisible();
  await expect(form.getByLabel('Name (required)',{exact:true})).toHaveAttribute('required','');
  expect(await form.getByLabel('Email (optional)',{exact:true}).evaluate(e=>e.required)).toBe(false);
  expect(await form.getByLabel('Phone (optional)',{exact:true}).evaluate(e=>e.required)).toBe(false);
  await form.getByRole('button',{name:'Start chat',exact:true}).click();await expect(form).toBeVisible();
  const session=await page.evaluate(async()=>await(await fetch('/wp-json/crystaltap-ai/v1/public/session')).json());expect(session.conversations).toHaveLength(0);
  const rejected=await page.request.post('/wp-json/crystaltap-ai/v1/public/conversations',{headers:{'X-CTAI-CSRF':session.csrf},data:{}});expect(rejected.status()).toBe(400);
  await form.getByLabel('Name (required)',{exact:true}).fill('Alex Santos');await form.getByLabel('Email (optional)',{exact:true}).fill('alex@example.invalid');await form.getByLabel('Phone (optional)',{exact:true}).fill('+63 900 000 0000');
  await page.screenshot({path:'.runtime/customer-prechat.png'});
  await page.setViewportSize({width:320,height:500});await form.getByRole('button',{name:'Start chat',exact:true}).scrollIntoViewIfNeeded();await expect(form.getByRole('button',{name:'Start chat',exact:true})).toBeInViewport();expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);await page.setViewportSize({width:390,height:844});
  await form.getByRole('button',{name:'Start chat',exact:true}).click();await expect(page.getByRole('textbox',{name:'Message',exact:true})).toBeVisible();
  const saved=await page.evaluate(async()=>await(await fetch('/wp-json/crystaltap-ai/v1/public/session')).json());expect(saved.customer.name).toBe('Alex Santos');expect(saved.customer.email).toBe('alex@example.invalid');expect(saved.customer.phone).toBe('+63 900 000 0000');
  await signIn(page);await page.goto('/crystaltap-agent/#conversation/'+saved.conversations[0].id);const card=page.locator('.ctai-card').filter({has:page.getByRole('heading',{name:'Customer information',exact:true})});await expect(card).toContainText('Alex Santos');await expect(card).toContainText('alex@example.invalid');await expect(card).toContainText('+63 900 000 0000');
});
test('customer widget resumes, sends, hands off and rejects a different visitor',async({page,browser})=>{
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto('/chat-demo/');await startChat(page);
  await expect(page.locator('.ctai-widget')).toHaveCount(1);
  await expect(page.getByRole('region',{name:'CrystalTap customer support'})).toBeVisible();
  await page.getByRole('textbox',{name:'Message',exact:true}).fill('Browser test: need a purifier');
  await page.getByRole('button',{name:'Send',exact:true}).click();
  await expect(page.locator('.ctai-customer')).toContainText('Browser test: need a purifier');
  await expect(page.locator('.ctai-system')).toHaveCount(0);await expect(page.locator('.ctai-status')).toHaveText('Waiting for an agent');await expect(page.locator('.ctai-welcome')).toContainText('Browser fixture visitor');
  const data=await page.evaluate(async()=>{const r=await fetch('/wp-json/crystaltap-ai/v1/public/session');return r.json();});
  const id=data.conversations[0].id;
  const other=await browser.newContext();
  await other.request.get('http://localhost:8080/wp-json/crystaltap-ai/v1/public/session');
  const denied=await other.request.get(`http://localhost:8080/wp-json/crystaltap-ai/v1/public/conversations/${id}/messages`);
  expect(denied.status()).toBe(404);await other.close();
  await page.reload();
  await expect(page.locator('.ctai-customer')).toContainText('Browser test: need a purifier');
  await expect(page.getByRole('button',{name:'Talk to a person',exact:true})).toBeHidden();
  await expect(page.locator('.ctai-status')).toHaveText('Waiting for an agent');
  expect(errors).toEqual([]);
});
test('WordPress agent takes over and replies to a visitor; notes stay private',async({page,browser})=>{
  const visitor=await browser.newContext();const customer=await visitor.newPage();
  await customer.goto('http://localhost:8080/chat-demo/');await startChat(customer);
  await customer.getByRole('textbox',{name:'Message',exact:true}).fill('Browser conversation requiring support');
  await customer.getByRole('button',{name:'Send',exact:true}).click();
  await expect(customer.locator('.ctai-customer')).toBeVisible();
  await expect(customer.getByRole('button',{name:'Talk to a person',exact:true})).toBeHidden();
  const data=await customer.evaluate(async()=>{return(await fetch('/wp-json/crystaltap-ai/v1/public/session')).json();});const id=data.conversations[0].id;
  await signIn(page);await page.goto('/crystaltap-agent/#conversation/'+id);
  await expect(page.getByRole('heading',{name:'Conversation #'+id})).toBeVisible();
  await page.getByRole('button',{name:'Take over',exact:true}).click();
  await expect(page.locator('#ctai-chat-status')).toHaveText('assigned');
  const supportName=await page.evaluate(()=>window.CTAI_APP.user.name);await expect(customer.locator('.ctai-status')).toContainText(supportName,{timeout:15000});
  await page.getByRole('textbox',{name:'Agent reply'}).fill('Hello po, a human agent is here.');
  await page.getByRole('button',{name:'Send message',exact:true}).click();
  await expect(page.locator('.ctai-from-agent')).toContainText('Hello po, a human agent is here.');
  await expect(customer.locator('.ctai-agent')).toContainText('Hello po, a human agent is here.',{timeout:15000});
  await expect(customer.locator('.ctai-status')).toContainText(supportName);await expect(customer.locator('.ctai-status')).not.toContainText('is joining');
  await page.getByRole('checkbox',{name:'Private note (team only)'}).check();
  await page.getByRole('textbox',{name:'Agent reply'}).fill('Internal fixture note, not for customer.');
  await page.getByRole('button',{name:'Send message',exact:true}).click();
  await expect(page.locator('.ctai-internal')).toContainText('Internal fixture note');
  await customer.reload();await expect(customer.locator('.ctai-customer')).toBeVisible();
  await expect(customer.locator('.ctai-messages')).not.toContainText('Internal fixture note');
  await page.getByRole('button',{name:'Resolve',exact:true}).click();
  await expect(page.locator('#ctai-chat-status')).toHaveText('resolved');await visitor.close();
});
test('browser agent workspace, notification bell, content and settings render without script errors',async({page})=>{
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
  const sw=await page.request.get('/crystaltap-agent/sw.js');expect(sw.status()).toBe(200);expect(sw.headers()['content-type']).toContain('javascript');expect(await sw.text()).not.toContain('showNotification');
  const manifest=await page.request.get('/crystaltap-agent/manifest.webmanifest');expect(manifest.status()).toBe(410);await expect(page.locator('link[rel=manifest]')).toHaveCount(0);
  expect(errors).toEqual([]);
});
test('public SSE fallback completes cleanly and mutation CSRF is enforced',async({request})=>{
  const session=await(await request.get('/wp-json/crystaltap-ai/v1/public/session')).json();
  const noCsrf=await request.post('/wp-json/crystaltap-ai/v1/public/conversations');expect(noCsrf.status()).toBe(403);
  const headers={'X-CTAI-CSRF':session.csrf};
  const c=await(await request.post('/wp-json/crystaltap-ai/v1/public/conversations',{headers,data:{name:'SSE fixture visitor'}})).json();
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

test('browser profile shows Android controls and opt-in foreground sound',async({page})=>{
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
  await expect(page.getByRole('heading',{name:'CrystalTap Android',exact:true})).toBeVisible();
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
  await expect(page.getByRole('button',{name:'Enable push on this device'})).toHaveCount(0);
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
    const customer=await visitor.newPage();await customer.goto('http://localhost:8080/chat-demo/');await startChat(customer);
    const channels=customer.getByRole('navigation',{name:'Other ways to contact us'});
    await expect(channels).toBeVisible();
    await expect(channels.getByRole('link',{name:'Contact us on WhatsApp',exact:true})).toHaveAttribute('href','https://wa.me/639123456789');
    await expect(channels.getByRole('link',{name:'Contact us on Messenger',exact:true})).toHaveAttribute('href','https://m.me/CrystalTapFixture');
    await expect(channels.getByRole('link',{name:'Contact us on Viber',exact:true})).toHaveAttribute('href','viber://chat?number=%2B639123456789');
    await expect(channels.getByRole('link',{name:'Contact us on WhatsApp',exact:true})).toHaveAttribute('rel','noopener noreferrer');
    await expect(channels).not.toContainText('Replies stay in that app');
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
  await page.goto('/chat-demo/');await startChat(page);
  const input=page.getByRole('textbox',{name:'Message',exact:true});await input.fill('Session recovery fixture');
  await page.getByRole('button',{name:'Send',exact:true}).click();await expect(page.locator('.ctai-customer')).toBeVisible();
  await page.context().clearCookies();
  await input.fill('Retain my unsent draft');await page.getByRole('button',{name:'Send',exact:true}).click();
  await expect(page.locator('.ctai-error')).toContainText('Select New chat to continue');
  await expect(input).toHaveValue('Retain my unsent draft');
  page.once('dialog',d=>d.accept());await page.getByRole('button',{name:'New chat',exact:true}).click();await startChat(page);
  await expect(input).toBeEnabled();await expect(input).toHaveValue('');
  await expect(page.locator('.ctai-customer')).toHaveCount(0);
  await input.fill('Fresh session works');await page.getByRole('button',{name:'Send',exact:true}).click();
  await expect(page.locator('.ctai-customer')).toHaveText(/Fresh session works/);
});

test('a delayed visitor history response cannot replace a new chat',async({page})=>{
  await page.goto('/chat-demo/');await startChat(page);
  const input=page.getByRole('textbox',{name:'Message',exact:true});await input.fill('Old conversation marker');
  await page.getByRole('button',{name:'Send',exact:true}).click();await expect(page.locator('.ctai-customer')).toContainText('Old conversation marker');
  let release,started;const pending=new Promise(resolve=>{started=resolve;});
  await page.route('**/crystaltap-ai/v1/public/conversations/*/messages?after=*',async r=>{
    const response=await r.fetch();started();await new Promise(resolve=>{release=resolve;});await r.fulfill({response});
  },{times:1});
  // Visibility restoration triggers the same history refresh used by the polling timer.
  await page.evaluate(()=>document.dispatchEvent(new Event('visibilitychange')));await pending;
  page.once('dialog',d=>d.accept());await page.getByRole('button',{name:'New chat',exact:true}).click();await startChat(page);
  await expect(page.locator('.ctai-customer')).toHaveCount(0);
  const complete=page.waitForResponse(r=>r.url().includes('/messages?after='));release();await complete;
  await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
  await input.fill('New conversation marker');await page.getByRole('button',{name:'Send',exact:true}).click();
  await expect(page.locator('.ctai-customer')).toHaveCount(1);
  await expect(page.locator('.ctai-customer')).toContainText('New conversation marker');
  await expect(page.locator('.ctai-messages')).not.toContainText('Old conversation marker');
});

test('visitor and agent exchange private images, emoji, links and real typing indicators',async({page,browser})=>{
  const visitor=await browser.newContext({viewport:{width:390,height:844}}),customer=await visitor.newPage();
  const errors=[];customer.on('pageerror',e=>errors.push(e.message));page.on('pageerror',e=>errors.push(e.message));
  try {
    await customer.goto('http://localhost:8080/chat-demo/');await startChat(customer);
    await customer.getByRole('button',{name:'Add emoji',exact:true}).click();
    await customer.getByRole('button',{name:'Insert 😊',exact:true}).click();
    const input=customer.getByRole('textbox',{name:'Message',exact:true});await expect(input).toHaveValue('😊');
    await input.fill('Please inspect these photos 😊 https://example.com/help javascript:alert(1)');
    await customer.getByLabel('Attach images',{exact:true}).setInputFiles(['tests/fixtures/chat-image.jpg','tests/fixtures/chat-image.png','tests/fixtures/chat-image.webp']);
    await customer.getByRole('button',{name:'Send',exact:true}).click();
    const bubble=customer.locator('.ctai-customer');await expect(bubble).toHaveCount(1);
    await expect(bubble.locator('.ctai-image img')).toHaveCount(3);
    await expect.poll(()=>bubble.locator('.ctai-image img').evaluateAll(items=>items.every(img=>img.complete&&img.naturalWidth>0))).toBe(true);
    await expect(bubble.getByRole('link',{name:'https://example.com/help',exact:true})).toHaveAttribute('rel','noopener noreferrer');
    await expect(bubble.locator('a[href^="javascript:"]')).toHaveCount(0);
    await expect(customer.locator('.ctai-status')).toHaveText('Waiting for an agent');
    const session=await customer.evaluate(async()=>await(await fetch('/wp-json/crystaltap-ai/v1/public/session')).json()),id=session.conversations[0].id;
    const history=await customer.evaluate(async id=>await(await fetch('/wp-json/crystaltap-ai/v1/public/conversations/'+id+'/messages')).json(),id);
    const imageId=history.messages[0].attachments[0].id;
    const stranger=await browser.newContext();const denied=await stranger.request.get('http://localhost:8080/wp-json/crystaltap-ai/v1/images/'+imageId);expect(denied.status()).toBe(401);await stranger.close();
    const raw=await customer.request.get('http://localhost:8080/wp-json/crystaltap-ai/v1/images/'+imageId);expect(raw.status()).toBe(200);expect(raw.headers()['content-type']).toBe('image/jpeg');expect(raw.headers()['cache-control']).toContain('no-store');
    await signIn(page);await page.goto('/crystaltap-agent/#conversation/'+id);await page.getByRole('button',{name:'Take over',exact:true}).click();
    await expect(page.locator('#ctai-history .ctai-image img')).toHaveCount(3);
    await expect.poll(()=>page.locator('#ctai-history .ctai-image img').evaluateAll(items=>items.every(img=>img.complete&&img.naturalWidth>0))).toBe(true);
    // Keep the visitor's field focused while polling from the agent view.
    await input.fill('Customer is writing');
    await expect(page.locator('#ctai-peer-typing')).toContainText('Customer is typing',{timeout:10000});
    await input.fill('');
    const reply=page.getByRole('textbox',{name:'Agent reply'});await reply.fill('Agent is writing');
    const supportName=await page.evaluate(()=>window.CTAI_APP.user.name);await expect(customer.locator('.ctai-peer-typing')).toContainText(supportName+' is typing',{timeout:10000});
    await reply.fill('Here is our response 👍 https://example.com/answer');
    await page.getByLabel('Attach images',{exact:true}).setInputFiles('tests/fixtures/chat-image.png');
    await page.getByRole('button',{name:'Send message',exact:true}).click();
    await expect(customer.locator('.ctai-agent .ctai-image img')).toHaveCount(1,{timeout:10000});
    await expect(customer.locator('.ctai-agent').getByRole('link',{name:'https://example.com/answer',exact:true})).toBeVisible();
    await page.getByRole('checkbox',{name:'Private note (team only)'}).check();
    await page.getByLabel('Attach images',{exact:true}).setInputFiles('tests/fixtures/chat-image.webp');
    await page.getByRole('button',{name:'Send message',exact:true}).click();
    await expect(page.locator('.ctai-internal .ctai-image img')).toHaveCount(1);
    const privateId=await page.evaluate(async id=>{const b=window.CTAI_APP;const h=await(await fetch(b.api+'agent/conversations/'+id,{headers:{'X-WP-Nonce':b.nonce}})).json();return h.messages.find(m=>m.visibility==='internal').attachments[0].id;},id);
    expect((await customer.request.get('http://localhost:8080/wp-json/crystaltap-ai/v1/images/'+privateId)).status()).toBe(404);
    await customer.reload();await expect(customer.locator('.ctai-image')).toHaveCount(4);
    expect(errors).toEqual([]);
  } finally {await visitor.close();}
});

test('uploaded launcher icon is saved and shown in the floating widget',async({page,browser})=>{
  await signIn(page);await page.goto('/crystaltap-agent/#widget');
  await expect(page.getByRole('heading',{name:'Chat widget',exact:true})).toBeVisible();
  const old=await page.locator('[name=launcher_icon]').inputValue();const oldGreeting=await page.locator('[name=auto_greeting]').isChecked();
  const visitor=await browser.newContext({viewport:{width:390,height:844}});
  try {
    await page.getByLabel('Upload chat icon',{exact:true}).setInputFiles('tests/fixtures/chat-image.png');
    await expect(page.locator('#ctai-alert')).toContainText('Icon uploaded. Save settings');
    await page.locator('[name=auto_greeting]').uncheck();
    await page.getByRole('button',{name:'Save settings',exact:true}).click();await expect(page.locator('#ctai-alert')).toHaveText('Settings saved.');
    const customer=await visitor.newPage();await customer.goto('http://localhost:8080/');
    const launcher=customer.getByRole('button',{name:'Chat with CrystalTap',exact:true});await expect(launcher).toBeVisible();
    await expect.poll(()=>launcher.locator('img').evaluate(img=>img.complete&&img.naturalWidth>0)).toBe(true);
    await launcher.click();await startChat(customer);await expect(customer.getByRole('textbox',{name:'Message',exact:true})).toBeVisible();
    const bounds=await customer.evaluate(()=>{const p=document.querySelector('.ctai-panel').getBoundingClientRect(),f=document.querySelector('.ctai-composer').getBoundingClientRect();return {panel:p.bottom,composer:f.bottom,width:document.documentElement.scrollWidth,viewport:innerWidth};});
    expect(bounds.composer).toBeLessThanOrEqual(bounds.panel+1);expect(bounds.width).toBeLessThanOrEqual(bounds.viewport);
    await customer.setViewportSize({width:320,height:500});await customer.getByRole('textbox',{name:'Message',exact:true}).scrollIntoViewIfNeeded();await expect(customer.getByRole('textbox',{name:'Message',exact:true})).toBeInViewport();
  } finally {
    await visitor.close();await page.evaluate(async data=>{const b=window.CTAI_APP;const r=await fetch(b.api+'admin/settings',{method:'PUT',headers:{'Content-Type':'application/json','X-WP-Nonce':b.nonce},body:JSON.stringify(data)});if(!r.ok)throw new Error('Could not restore icon fixture');},{launcher_icon:old,auto_greeting:oldGreeting});
  }
});

test('Android sign-in is approved by a matching code in the browser',async({page,request})=>{
  const crypto=require('node:crypto'),verifier=crypto.randomBytes(32).toString('hex'),challenge=crypto.createHash('sha256').update(verifier).digest('hex');
  const started=await request.post('/wp-json/crystaltap-ai/v1/native/link',{data:{challenge}});expect(started.status()).toBe(200);const pair=await started.json();let access='';
  try {
    const pending=await request.post('/wp-json/crystaltap-ai/v1/native/link/complete',{data:{id:pair.id,verifier}});expect((await pending.json()).pending).toBe(true);
    await signIn(page);await page.goto(pair.verification_url);await expect(page.locator('#ctai-android-link')).toContainText(pair.code);
    await page.getByRole('button',{name:'Approve matching Android code',exact:true}).click();await expect(page.locator('#ctai-android-link')).toContainText('Android sign-in approved');
    const complete=await request.post('/wp-json/crystaltap-ai/v1/native/link/complete',{data:{id:pair.id,verifier}});expect(complete.status()).toBe(200);access=(await complete.json()).token;
    const inbox=await request.get('/wp-json/crystaltap-ai/v1/agent/conversations',{headers:{Authorization:'Bearer '+access}});expect(inbox.status()).toBe(200);
    await page.goto('/crystaltap-agent/#profile');await expect(page.getByRole('heading',{name:'Android devices',exact:true})).toBeVisible();await expect(page.locator('#ctai-view')).toContainText('CrystalTap Android');
    await expect(page.getByRole('button',{name:'Install app',exact:true})).toHaveCount(0);
  } finally {if(access)await request.post('/wp-json/crystaltap-ai/v1/native/logout',{headers:{Authorization:'Bearer '+access},data:{}});}
});


test('PWA cleanup touches only the retired CrystalTap worker and caches',async({page})=>{
  await page.addInitScript(()=>{
    window.__retired={unsubscribed:false,own:false,other:false,deleted:[]};
    Object.defineProperty(navigator,'serviceWorker',{configurable:true,value:{getRegistrations:async()=>[{scope:location.origin+'/crystaltap-agent/',pushManager:{getSubscription:async()=>({unsubscribe:async()=>{window.__retired.unsubscribed=true;}})},unregister:async()=>{window.__retired.own=true;}},{scope:location.origin+'/',unregister:async()=>{window.__retired.other=true;}}]}});
    Object.defineProperty(window,'caches',{configurable:true,value:{keys:async()=>['ctai-shell-old','other-cache'],delete:async key=>window.__retired.deleted.push(key)}});
  });
  await signIn(page);await page.goto('/crystaltap-agent/#profile');
  await expect.poll(()=>page.evaluate(()=>window.__retired.own)).toBe(true);
  expect(await page.evaluate(()=>window.__retired)).toEqual({unsubscribed:true,own:true,other:false,deleted:['ctai-shell-old']});
});
