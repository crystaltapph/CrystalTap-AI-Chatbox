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
