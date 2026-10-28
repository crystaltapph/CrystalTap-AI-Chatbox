/* CrystalTap customer widget: no private credentials or conversation storage in localStorage. */
(() => {
  'use strict';
  const boot = window.CTAI_WIDGET;
  if (!boot || window.ctaiWidgetMounted) return;
  const roots = [...document.querySelectorAll('[data-ctai-mode]')];
  const root = roots.find(r => r.dataset.ctaiMode === 'embedded') || roots[0];
  if (!root) return;
  window.ctaiWidgetMounted = true;
  roots.filter(r => r !== root).forEach(r => r.remove());
  const embedded = root.dataset.ctaiMode === 'embedded';
  const cfg = boot.config, ui = window.CTAIChatUI;
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };
  const button = (text, cls, fn) => { const n = el('button', cls, text); n.type = 'button'; n.addEventListener('click', fn); return n; };
  root.className = `ctai-widget ${embedded ? 'ctai-embedded' : 'ctai-floating'} ctai-${cfg.position}`;
  root.style.setProperty('--ctai-color', cfg.color);
  root.style.setProperty('--ctai-offset', `${cfg.offset}px`);
  root.style.setProperty('--ctai-mobile-offset', `${cfg.mobile_offset}px`);
  root.style.setProperty('--ctai-width', `${cfg.width}px`);
  root.style.setProperty('--ctai-height', `${cfg.height}px`);
  let open = embedded, csrf = '', conversation = null, lastSeq = 0, timer, pending = null, busy = false, initialized = false, conversationEpoch = 0, sessionExpired = false;
  const launcher = button('Chat with CrystalTap', 'ctai-launcher', () => toggle(true));
  const launcherText=el('span','ctai-launcher-text','Chat with CrystalTap');launcher.replaceChildren();
  const launcherIcon=cfg.launcher_icon?el('img','ctai-launcher-image'):el('span','ctai-launcher-symbol','💬');
  if(cfg.launcher_icon){launcherIcon.src=cfg.launcher_icon;launcherIcon.alt='';}else launcherIcon.setAttribute('aria-hidden','true');
  launcher.append(launcherIcon,launcherText);launcher.setAttribute('aria-label','Chat with CrystalTap');
  launcher.setAttribute('aria-expanded', 'false');
  launcher.setAttribute('aria-controls', 'ctai-chat-panel');
  const panel = el('section', 'ctai-panel'); panel.id = 'ctai-chat-panel'; panel.setAttribute('aria-label', 'CrystalTap customer support');
  const header = el('header', 'ctai-header');
  if (cfg.logo) { const img = el('img'); img.src = cfg.logo; img.alt = ''; header.append(img); }
  const heading = el('div'); heading.append(el('strong', '', cfg.bot_name));
  const status = el('div', 'ctai-status', 'AI and human support'); heading.append(status); header.append(heading);
  const close = button('−', 'ctai-icon', () => toggle(false)); close.setAttribute('aria-label', 'Minimize chat');
  if (!embedded) header.append(close);
  const messages = el('div', 'ctai-messages'); messages.setAttribute('role', 'log'); messages.setAttribute('aria-live', 'polite'); messages.setAttribute('aria-relevant', 'additions');
  const welcome = el('div', 'ctai-bubble ctai-ai', cfg.welcome); messages.append(welcome);
  const chips = el('div', 'ctai-chips');
  cfg.suggestions.forEach(text => chips.append(button(text, 'ctai-chip', () => { input.value = text; input.focus(); })));
  messages.append(chips);
  const notice = el('details', 'ctai-privacy'); notice.append(el('summary', '', 'Privacy and your conversation'), el('p', '', cfg.privacy_notice));
  const error = el('div', 'ctai-error'); error.setAttribute('role', 'status');
  const channels = el('nav', 'ctai-channels'); channels.setAttribute('aria-label', 'Other ways to contact us');
  function renderChannels() {
    channels.replaceChildren();
    const links = (Array.isArray(cfg.channels) ? cfg.channels : []).filter(item => ['whatsapp','messenger','viber'].includes(item.channel));
    for (const item of links) {
      // Restrict destinations again in the browser; no customer text is appended.
      const safe = item.channel === 'whatsapp' ? /^https:\/\/wa\.me\/[1-9][0-9]{6,14}$/.test(item.url) : item.channel === 'messenger' ? /^https:\/\/m\.me\/[A-Za-z0-9.]{1,100}$/.test(item.url) : /^(?:viber:\/\/(?:chat\?number=%2B[1-9][0-9]{6,14}|pa\?chatURI=[A-Za-z0-9_.-]{1,100})|https:\/\/(?:vb\.me|invite\.viber\.com)\/[^\s]*)$/.test(item.url);
      if (!safe) continue;
      const link = el('a', 'ctai-channel ctai-channel-' + item.channel, {whatsapp:'WhatsApp',messenger:'Messenger',viber:'Viber'}[item.channel]);
      link.href = item.url; link.rel = 'noopener noreferrer';
      if (item.url.startsWith('https:')) link.target = '_blank';
      link.setAttribute('aria-label', 'Contact us on ' + link.textContent); channels.append(link);
    }
    if (channels.children.length) channels.append(el('small', '', 'Opens another app. Replies stay in that app. Viber requires Viber installed.'));
    channels.hidden = !channels.children.length;
  }
  renderChannels();
  const tools = el('div', 'ctai-tools');
  tools.append(button('Talk to a person', '', async () => run(async () => { await ensureConversation(); conversation = await api(`public/conversations/${conversation.id}/handoff`, 'POST', {}); updateStatus(); error.textContent = 'Your request is in the support queue. An agent will respond when available.'; })), button('Leave contact details', '', () => showLead()), button('New chat', '', async () => run(async () => { if (!confirm('Start a new conversation? Your existing history follows the retention policy.')) return; await initialize(); conversationEpoch++; conversation = await api('public/conversations', 'POST', {}); sessionExpired = false; lastSeq = 0; messages.replaceChildren(el('div', 'ctai-bubble ctai-ai', cfg.welcome)); pending = null; input.value = ''; attachments.clear();attachments.stop();updateStatus(); })));
  const form = el('form', 'ctai-composer');
  const input = el('textarea'); input.rows = 1; input.maxLength = 4000; input.placeholder = 'Type your question…'; input.setAttribute('aria-label', 'Message');
  const send = el('button', 'ctai-send', 'Send'); send.type = 'submit';
  form.append(input, send);
  const composerTools=el('div','ctai-composer-controls');
  const attachments=ui.composer(input,composerTools,{typing:active=>conversation?api(`public/conversations/${conversation.id}/typing`,'POST',{active}):null,upload:async(file,key)=>{await ensureConversation();const body=new FormData();body.append('image',file);body.append('key',key);const response=await fetch(boot.api+`public/conversations/${conversation.id}/images`,{method:'POST',credentials:'same-origin',headers:{'X-CTAI-CSRF':csrf},body});const result=await response.json();if(!response.ok)throw requestError(result);return result;}});
  input.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); form.requestSubmit(); } });
  const peerTyping=el('div','ctai-peer-typing');peerTyping.setAttribute('role','status');
  const thinking = el('div', 'ctai-thinking'); thinking.setAttribute('role', 'status');
  form.addEventListener('submit', async e => {
    e.preventDefault(); if (busy || (!input.value.trim()&&!attachments.hasImages()) || !navigator.onLine) return;
    await run(async () => {
      await ensureConversation();
      const ids=await attachments.upload();
      if (!pending || pending.content !== input.value.trim() || JSON.stringify(pending.attachments)!==JSON.stringify(ids)) pending = { key: crypto.randomUUID(), content: input.value.trim(), attachments:ids };
      attachments.stop();
      thinking.textContent = conversation.status==='ai_active'&&!ids.length ? 'AI assistant is thinking…' : 'Sending your message…';thinking.classList.add('is-typing');
      const sent = cfg.streaming_enabled && window.ReadableStream ? await streamMessage(pending) : await api(`public/conversations/${conversation.id}/messages`, 'POST', pending);
      conversation = sent.conversation; input.value = ''; pending = null; attachments.clear();
      await refresh();
    });
    thinking.textContent = '';thinking.classList.remove('is-typing');
  });
  panel.append(header, messages, notice, channels, error, tools, peerTyping, thinking, composerTools, form); root.append(launcher, panel);
  ui.watchTyping(peerTyping,async()=>conversation&&!busy?(await api(`public/conversations/${conversation.id}/typing`)).agent:false,'Support agent');
  async function api(path, method = 'GET', data) {
    const response = await fetch(boot.api + path, { method, credentials: 'same-origin', cache: 'no-store', headers: { 'Content-Type': 'application/json', 'X-CTAI-CSRF': csrf }, body: data === undefined ? undefined : JSON.stringify(data) });
    const result = await response.json();
    if (!response.ok) {
      throw requestError(result);
    }
    return result;
  }
  function requestError(result) {
    if (result.code === 'session_expired') { sessionExpired = true; initialized = false; conversationEpoch++; return new Error('Your chat session expired. Select New chat to continue. Your draft is still here.'); }
    return new Error(result.message || 'Connection failed. Please retry.');
  }
  async function streamMessage(payload) {
    const response = await fetch(boot.api + `public/conversations/${conversation.id}/stream`, { method:'POST', credentials:'same-origin', cache:'no-store', headers:{'Content-Type':'application/json','X-CTAI-CSRF':csrf,'Accept':'text/event-stream'}, body:JSON.stringify(payload) });
    if (!response.ok) { const error = await response.json(); throw requestError(error); }
    if (!response.body) throw new Error('Response interrupted. Retry to check delivery.');
    const reader=response.body.getReader(), decoder=new TextDecoder(); let buffer='', completed=null, provisional=null, provisionalText=null;
    try {
      while(true) {
        const {value,done}=await reader.read(); buffer+=decoder.decode(value||new Uint8Array(),{stream:!done});
        let end;
        while((end=buffer.indexOf('\n\n'))!==-1) {
          const event=buffer.slice(0,end);buffer=buffer.slice(end+2);
          const type=event.match(/^event: (.+)$/m)?.[1], raw=event.match(/^data: (.+)$/m)?.[1]; if(!raw)continue;
          const data=JSON.parse(raw);
          if(type==='delta') {
            if(!provisional){provisional=el('article','ctai-bubble ctai-ai');provisional.append(el('small','ctai-sender','AI reply · generating'));provisionalText=el('div');provisional.append(provisionalText);messages.append(provisional);}
            provisionalText.textContent=data.text; messages.scrollTop=messages.scrollHeight;thinking.textContent='AI is responding…';
          }
          if(type==='complete')completed=data;
          if(type==='error')throw new Error(data.message);
        }
        if(done)break;
      }
      if(!completed)throw new Error('The response was interrupted. Retry your message to check delivery safely.');
      provisional?.remove();return completed;
    } catch(error) {
      if(provisional)provisional.querySelector('small').textContent='Interrupted reply · not confirmed';
      throw error;
    } finally { reader.releaseLock(); }
  }
  async function initialize() {
    if (initialized) return;
    const data = await api('public/session'); Object.assign(cfg,data.config); renderChannels(); csrf = data.csrf; initialized = true;
    conversation = data.conversations.find(c => !['resolved', 'closed'].includes(c.status)) || data.conversations[0] || null;
    updateStatus();
  }
  async function ensureConversation() { if(sessionExpired)throw new Error('Your chat session expired. Select New chat to continue.'); await initialize(); if (!conversation) conversation = await api('public/conversations', 'POST', {}); }
  function updateStatus() {
    const labels = { ai_active: 'AI assistance', human_requested: 'Requesting human support', waiting: 'Waiting for an agent', assigned: 'An agent is joining', human_active: 'Human support', resolved: 'Conversation resolved', closed: 'Conversation closed' };
    status.textContent = conversation ? labels[conversation.status] || 'Support' : 'AI and human support';
    const ended = conversation && ['resolved','closed'].includes(conversation.status);
    attachments.lock(busy||sessionExpired||!!ended||!navigator.onLine);
    input.disabled = sessionExpired || !!ended || busy || !navigator.onLine; send.disabled = input.disabled;
    input.placeholder = ended ? 'Start a new chat to continue' : 'Type your question…';
  }
  function appendMessage(m) {
    const bubble = el('article', `ctai-bubble ctai-${m.sender}`);
    const label = {customer: 'You', ai: cfg.bot_name, agent: 'CrystalTap support', system: 'Support update'}[m.sender] || 'Support';
    const body=el('div');ui.text(body,m.content);bubble.append(el('small', 'ctai-sender', label),body);ui.images(bubble,m.attachments,{api:boot.api});
    for (const p of m.products || []) {
      const card = el('div', 'ctai-product'); card.append(el('strong', '', p.title));
      const d = p.details || {};
      if (d.image) { const image = el('img'); image.src = d.image; image.alt = p.title; image.loading = 'lazy'; card.append(image); }
      if (d.price !== null && d.price !== undefined) card.append(el('p', '', `${d.currency || ''} ${d.price}`));
      if (d.availability) card.append(el('p', '', d.availability));
      if (d.url && /^https:\/\//.test(d.url)) { const a = el('a', '', 'View product'); a.href = d.url; a.target = '_blank'; a.rel = 'noopener noreferrer'; card.append(a); }
      bubble.append(card);
    }
    const time = el('time', '', new Date(m.created_at.replace(' ', 'T') + 'Z').toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })); bubble.append(time);
    messages.append(bubble);
  }
  async function refresh() {
    if (!conversation || sessionExpired || !navigator.onLine) return;
    const id = conversation.id, epoch = conversationEpoch;
    let batch;
    do {
      batch = await api(`public/conversations/${id}/messages?after=${lastSeq}`);
      if (epoch !== conversationEpoch || +conversation?.id !== +id) return;
      conversation = batch.conversation;ui.typing(peerTyping,batch.typing?.agent,'Support agent');
      for (const m of batch.messages) { if (+m.seq > lastSeq) { appendMessage(m); lastSeq = +m.seq; } }
    } while (batch.messages.length === 100);
    updateStatus(); messages.scrollTop = messages.scrollHeight;
  }
  async function run(fn) { if (busy) return; busy = true; error.textContent = ''; updateStatus(); try { await fn(); } catch (e) { error.textContent = e.message; } finally { busy = false; updateStatus(); } }
  async function poll() {
    clearTimeout(timer);
    if (open && !document.hidden && !busy && navigator.onLine) { try { await refresh(); } catch (e) { error.textContent = e.message; } }
    timer = setTimeout(poll, Math.max(3, +cfg.poll_seconds) * 1000);
  }
  function toggle(value) {
    open = value; panel.hidden = !value; launcher.hidden = value || embedded; launcher.setAttribute('aria-expanded', String(value));
    if (value) { run(async () => { await initialize(); await refresh(); }).then(() => { input.focus(); poll(); }); }
    else { clearTimeout(timer); launcher.focus(); }
  }
  function showLead() {
    if (panel.querySelector('.ctai-lead')) return;
    const f = el('form', 'ctai-lead'); f.append(el('strong', '', 'How can our team reach you?'));
    for (const [name, label, type] of [['name','Your name','text'],['email','Email (or provide phone)','email'],['phone','Phone (or provide email)','tel'],['location','City / delivery location','text'],['interest','Product interest','text'],['water_source','Water source','text']]) { const l = el('label', '', label); const i = el('input'); i.name = name; i.type = type; i.maxLength = 190; i.required = name === 'name'; l.append(i); f.append(l); }
    const consent = el('label', 'ctai-consent'); const checkbox = el('input'); checkbox.type = 'checkbox'; checkbox.required = true; checkbox.name = 'consent'; consent.append(checkbox, document.createTextNode('I agree to use these details to respond to this inquiry.')); f.append(consent);
    const submit = el('button', 'ctai-send', 'Send details'); submit.type = 'submit'; f.append(submit, button('Cancel', '', () => f.remove()));
    f.addEventListener('submit', e => { e.preventDefault(); run(async () => { await ensureConversation(); await api(`public/conversations/${conversation.id}/lead`, 'POST', { ...Object.fromEntries(new FormData(f)), consent: checkbox.checked }); f.remove(); error.textContent = 'Thank you. Your contact details have been saved for this inquiry.'; }); });
    panel.insertBefore(f, tools);
  }
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && !embedded && open) toggle(false); });
  document.addEventListener('visibilitychange', () => { if (!document.hidden && open) poll(); });
  window.addEventListener('online', () => { error.textContent = ''; updateStatus(); if (open) poll(); });
  window.addEventListener('offline', () => { error.textContent = 'You are offline. Your message has not been sent.'; updateStatus(); });
  panel.hidden = !open; launcher.hidden = open;
  if (embedded) toggle(true);
  else if (boot.autoGreeting) { launcherText.textContent = cfg.welcome; }
})();
