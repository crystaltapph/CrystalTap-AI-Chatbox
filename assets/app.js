/* Shared WordPress and PWA agent interface. All permissions are enforced again by the API. */
(() => {
  'use strict';
  const boot = window.CTAI_APP, root = document.getElementById('ctai-app');
  if (!boot || !root) return;
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const time = value => value ? new Date(value.replace(' ', 'T') + 'Z').toLocaleString([], { month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' }) : '—';
  const label = value => String(value || '').replaceAll('_', ' ').replaceAll('-', ' ');
  const mobile = root.classList.contains('ctai-mobile');
  let route = '', selected = null, lastSeq = 0, sending = false, page = 1, filter = 'all', bellItems = [], notificationBefore = 0;
  const ui=window.CTAIChatUI;
  const state = { content: [], settings: null, agents: [] };
  let audio = null, soundEnabled = false, bellThrough = null, navigationId = 0, conversationRefresh = 0;
  const tabs = [['home','Overview','◉'],['inbox','Inbox','▤'],['notifications','Notifications','🔔'],['leads','Leads','◇'],['profile','Profile','○']];
  const more = boot.user.manage ? [['products','Products'],['knowledge','Knowledge base'],['ai','AI configuration'],['widget','Chat widget'],['business','Business information'],['settings','Settings'],['logs','Logs']] : [];
  if (boot.user.supervise) more.unshift(['agents','Live agents'], ['analytics','Analytics']);
  root.innerHTML = `<header class="ctai-top"><a class="ctai-brand" href="#home"><span class="ctai-brand-icon">C</span><span>CrystalTap <small>Agent workspace</small></span></a><div class="ctai-top-actions"><span id="ctai-connection">Connected</span><a href="#notifications" class="ctai-bell" aria-label="Notifications"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M9 21h6"/></svg> <span id="ctai-badge">0</span></a><a class="ctai-avatar" href="#profile" aria-label="Your profile">${esc(boot.user.name.slice(0,1).toUpperCase())}</a></div></header><div class="ctai-layout"><nav class="ctai-nav" aria-label="Main navigation">${tabs.map(([id,name,icon]) => `<a href="#${id}" data-nav="${id}"><span>${icon}</span>${name}</a>`).join('')}${!mobile ? `<div class="ctai-nav-label">MANAGE</div>${more.map(([id,name]) => `<a href="#${id}" data-nav="${id}">${name}</a>`).join('')}<a href="${esc(boot.app)}" target="_blank" rel="noopener">Open agent workspace ↗</a>` : ''}</nav><main class="ctai-main"><div id="ctai-update" class="ctai-alert" role="status" hidden></div><div id="ctai-alert" role="status" hidden></div><div id="ctai-view"></div></main></div>`;
  const view = root.querySelector('#ctai-view'), alert = root.querySelector('#ctai-alert');
  function message(text, error = true) { alert.textContent = text; alert.hidden = !text; alert.className = error ? 'ctai-alert ctai-danger' : 'ctai-alert'; }
  async function api(path, method = 'GET', body, retry = true) {
    if (!navigator.onLine) throw new Error('You are offline. No message has been sent.');
    const response = await fetch(boot.api + path, { method, credentials:'same-origin', cache:'no-store', headers:{'Content-Type':'application/json','X-WP-Nonce':boot.nonce}, body: body === undefined ? undefined : JSON.stringify(body) });
    const result = await response.json();
    if (!response.ok) {
      if (retry && result.code === 'rest_cookie_invalid_nonce') {
        const auth = await fetch(boot.ajax, { credentials:'same-origin', cache:'no-store' });
        const fresh = await auth.json(); if (auth.ok && fresh.nonce) { boot.nonce = fresh.nonce; return api(path, method, body, false); }
      }
      throw new Error(result.message || 'Request could not be completed.');
    }
    return result;
  }
  async function run(fn) { const visit=navigationId; try { message(''); return await fn(); } catch(e) { if(visit===navigationId)message(e.message); } }
  function title(name, subtitle = '') { return `<div class="ctai-page-heading"><div><p class="ctai-eyebrow">CRYSTALTAP AI</p><h1>${esc(name)}</h1>${subtitle ? `<p>${esc(subtitle)}</p>` : ''}</div></div>`; }
  function empty(text) { return `<div class="ctai-empty"><span>◇</span><h3>${esc(text)}</h3><p>Updates will appear here when there is activity.</p></div>`; }
  function field(name, text, value = '', type = 'text', required = false) {
    return `<label>${esc(text)}${type === 'textarea' ? `<textarea name="${esc(name)}" rows="4" ${required ? 'required' : ''}>${esc(value)}</textarea>` : type === 'checkbox' ? `<input type="checkbox" name="${esc(name)}" ${value ? 'checked' : ''}>` : `<input type="${esc(type)}" name="${esc(name)}" value="${esc(value)}" ${required ? 'required' : ''} ${type === 'number' ? 'step="any" min="0"' : ''}>`}</label>`;
  }
  function table(rows, columns) {
    if (!rows.length) return empty('No records yet');
    return `<div class="ctai-table-wrap"><table><thead><tr>${columns.map(([key,name]) => `<th>${esc(name || label(key))}</th>`).join('')}</tr></thead><tbody>${rows.map(row => `<tr>${columns.map(([key,name,format]) => `<td>${format ? format(row[key],row) : esc(row[key])}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
  }
  function pagination(next, prev = page > 1) { return `<div class="ctai-pagination"><button data-page="${page-1}" ${prev ? '' : 'disabled'}>Previous</button><span>Page ${page}</span><button data-page="${page+1}" ${next ? '' : 'disabled'}>Next</button></div>`; }
  async function bell() {
    const data = await api('agent/notifications'); bellItems = data.items;
    const latest = Math.max(0, ...bellItems.map(item => +item.id));
    const fresh = bellThrough !== null && bellItems.some(item => +item.id > bellThrough && !item.read_at);
    bellThrough = Math.max(bellThrough ?? 0, latest);
    if (fresh && soundEnabled && !document.hidden && audio?.state === 'running') chime();
    const badge = root.querySelector('#ctai-badge'); badge.textContent = data.unread > 99 ? '99+' : data.unread; badge.classList.toggle('has-unread', data.unread > 0);
  }
  function chime() {
    // Audio is unlocked only by the explicit sound button. No customer data is stored.
    const start = audio.currentTime;
    for (const [delay,frequency] of [[0,660],[0.18,880]]) {
      const tone = audio.createOscillator(), gain = audio.createGain();
      tone.frequency.value = frequency; gain.gain.setValueAtTime(0,start+delay);
      gain.gain.linearRampToValueAtTime(0.12,start+delay+0.02);
      gain.gain.exponentialRampToValueAtTime(0.001,start+delay+0.25);
      tone.connect(gain); gain.connect(audio.destination);
      tone.start(start+delay); tone.stop(start+delay+0.26);
    }
  }
  async function enableSound() {
    const Audio = window.AudioContext || window.webkitAudioContext;
    if (!Audio) throw new Error('In-app sound is unavailable in this browser.');
    if (!audio) audio = new Audio();
    await audio.resume();
    if (audio.state !== 'running') throw new Error('The browser could not enable sound. Tap the sound button again.');
    soundEnabled = true; chime();
    message('Test chime played. Sound is enabled for new bell alerts while this workspace stays open and visible. Enable it again after reopening or reloading.',false);
  }
  async function home() {
    const visit=navigationId;
    const [items, availability, notifications] = await Promise.all([api('agent/conversations?filter=active'), api('agent/availability'), api('agent/notifications')]);
    if(visit!==navigationId||route!=='home')return;
    view.innerHTML = title(`Hello, ${boot.user.name.split(' ')[0]}`, 'A clear view of the conversations that need you.') + `<section class="ctai-welcome"><div><span class="ctai-pill">YOUR AVAILABILITY</span><h2>Ready to help, one conversation at a time.</h2><p>Set your status so the team knows when you can take new requests.</p></div><select id="ctai-availability" aria-label="Your availability">${['online','away','offline'].map(s => `<option value="${s}" ${availability.availability === s ? 'selected' : ''}>${label(s)}</option>`).join('')}</select></section><div class="ctai-stats">${[['Assigned to you',items.filter(c => +c.assigned_agent === +boot.user.id).length],['Waiting for an agent',items.filter(c => c.status === 'waiting').length],['Unread notifications',notifications.unread]].map(([name,n]) => `<div class="ctai-stat"><span>${name}</span><strong>${n}</strong><small>${name.includes('notification') ? 'Across your devices' : 'In the current inbox page'}</small></div>`).join('')}</div><section class="ctai-section"><div class="ctai-section-heading"><h2>Recent conversations</h2><a href="#inbox">View inbox →</a></div>${conversationList(items.slice(0,6))}</section>`;
    view.querySelector('#ctai-availability').onchange = e => run(() => api('agent/availability','PUT',{status:e.target.value}));
    bindConversationLinks();
  }
  function conversationList(items) {
    if (!items.length) return empty('Your inbox is clear');
    return `<div class="ctai-conversation-list">${items.map(c => `<button class="ctai-conversation-row" data-conversation="${c.id}" data-version="${c.version}" data-claimed="${c.assigned_agent ? 'yes' : 'no'}"><span class="ctai-contact-icon">G</span><span class="ctai-conversation-info"><strong>${esc(c.customer?.name||'Guest')} · #${c.id}</strong><span>${esc(c.preview || 'No messages yet')}</span><small><span class="ctai-state ctai-state-${esc(c.status)}">${esc(label(c.status))}</span>${c.assigned_agent ? ` · Agent #${esc(c.assigned_agent)}` : ''}</small></span><span class="ctai-conversation-meta">${esc(time(c.updated_at))}${+c.unread ? `<b>${c.unread}</b>` : ''}</span></button>`).join('')}</div>`;
  }
  function bindConversationLinks() {
    view.querySelectorAll('[data-conversation]').forEach(b => b.onclick = () => run(async () => {
      if (b.dataset.claimed === 'no' && !boot.user.supervise) {
        if (!confirm('Claim this conversation to view its messages and respond?')) return;
        await api(`agent/conversations/${b.dataset.conversation}/actions/claim`,'POST',{version:+b.dataset.version});
      }
      location.hash = 'conversation/' + b.dataset.conversation;
    }));
  }
  async function inbox() {
    const visit=navigationId;
    const search = new URLSearchParams(location.hash.split('?')[1] || '').get('search') || '';
    const items = await api(`agent/conversations?filter=${encodeURIComponent(filter)}&page=${page}&search=${encodeURIComponent(search)}`);
    if(visit!==navigationId||!route.startsWith('inbox'))return;
    view.innerHTML = title('Conversation inbox','AI assistance and your support team, working together.') + `<div class="ctai-toolbar"><select id="ctai-filter" aria-label="Conversation filter">${[['all','All conversations'],['waiting','Waiting for agent'],['mine','Assigned to me'],['active','Active'],['resolved','Resolved']].map(([id,name]) => `<option value="${id}" ${filter === id ? 'selected' : ''}>${name}</option>`).join('')}</select><form id="ctai-search"><input name="search" aria-label="Search conversations" placeholder="Search name, message or #" value="${esc(search)}"><button>Search</button></form></div>${conversationList(items)}${pagination(items.length === 40)}`;
    view.querySelector('#ctai-filter').onchange = e => { filter = e.target.value; page = 1; run(inbox); };
    view.querySelector('#ctai-search').onsubmit = e => { e.preventDefault(); page = 1; location.hash = 'inbox?search=' + encodeURIComponent(new FormData(e.target).get('search')); };
    bindConversationLinks(); bindPages(inbox);
  }
  async function conversation(id) {
    const visit=navigationId;
    const data = await api(`agent/conversations/${id}`); if(visit!==navigationId)return; selected = data.conversation; lastSeq = 0;
    view.innerHTML = `<div class="ctai-chat-heading"><a href="#inbox" class="ctai-back">← Inbox</a><h1>Conversation #${id}</h1><span id="ctai-chat-status" class="ctai-state">${esc(label(selected.status))}</span></div><div id="ctai-actions" class="ctai-toolbar"></div><section class="ctai-chat"><div id="ctai-history" class="ctai-history" role="log" aria-label="Conversation history" aria-live="polite"></div><form id="ctai-reply" class="ctai-reply"><label class="ctai-note-toggle"><input type="checkbox" name="internal"> Private note (team only)</label><textarea name="content" rows="3" maxlength="4000" placeholder="Write your response…" aria-label="Agent reply"></textarea><div><span id="ctai-send-status">Messages are sent only while connected.</span><button class="ctai-primary" type="submit">Send message</button></div></form></section><div class="ctai-toolbar" id="ctai-privacy-actions"></div>`;
    appendMessages(data.messages); renderActions();
    if (data.customer) {
      const info = document.createElement('section'); info.className='ctai-card';
      info.innerHTML='<h2>Customer information</h2>'+Object.entries(data.customer).filter(([,v])=>v).map(([k,v])=>`<p><strong>${esc(label(k))}:</strong> ${esc(v)}</p>`).join('');
      view.append(info);
    }
    const reply=view.querySelector('#ctai-reply'),input=reply.elements.content,controls=document.createElement('div');controls.className='ctai-composer-controls';reply.insertBefore(controls,input);
    const peer=document.createElement('div');peer.className='ctai-peer-typing';peer.id='ctai-peer-typing';peer.setAttribute('role','status');reply.before(peer);ui.typing(peer,data.typing?.customer,'Customer');ui.watchTyping(peer,async()=>(await api(`agent/conversations/${id}/typing`)).customer,'Customer');
    const attachments=ui.composer(input,controls,{private:()=>reply.elements.internal.checked,typing:active=>visit===navigationId?api(`agent/conversations/${id}/typing`,'POST',{active}):null,upload:async(file,key)=>{const fresh=await api('agent/bootstrap');boot.nonce=fresh.nonce;const body=new FormData();body.append('image',file);body.append('key',key);const response=await fetch(boot.api+`agent/conversations/${id}/images`,{method:'POST',credentials:'same-origin',headers:{'X-WP-Nonce':boot.nonce},body});const result=await response.json();if(!response.ok)throw new Error(result.message||'Image upload failed.');return result;}});
    reply.elements.internal.addEventListener('change',()=>attachments.stop());
    let pending = null;
    view.querySelector('#ctai-reply').onsubmit = e => { e.preventDefault(); if (sending) return;
      const f = e.target, draft = f.elements.content.value, content = draft.trim(), internal = f.elements.internal.checked;
      if (!content&&!attachments.hasImages()) return;
      const signature=attachments.signature();
      if (!pending || pending.content !== content || pending.internal !== internal || pending.signature!==signature) pending = {key:crypto.randomUUID(),content,internal,signature};
      sending = true;attachments.lock(true);attachments.stop(); const b = f.querySelector('button[type=submit]'); b.disabled = true; view.querySelector('#ctai-send-status').textContent = 'Sending…';
      run(async () => { const ids=await attachments.upload();await api(`agent/conversations/${id}/messages`,'POST',{...pending,attachments:ids});attachments.clear(); if(f.elements.content.value===draft)f.elements.content.value = ''; pending = null; if(visit===navigationId)await updateConversation(); }).finally(() => { sending = false;attachments.lock(false); b.disabled = !navigator.onLine; const s = f.querySelector('#ctai-send-status'); if(s) s.textContent = pending ? 'Not confirmed. Retry to check delivery safely.' : 'Message saved on the server.'; });
    };
    if (boot.user.manage) {
      const p = view.querySelector('#ctai-privacy-actions');
      p.innerHTML = '<button data-export>Export conversation</button><button data-erase class="ctai-text-danger">Erase conversation</button>';
      p.querySelector('[data-export]').onclick = () => run(async () => download(`conversation-${id}.json`,await api(`admin/conversations/${id}/export`)));
      p.querySelector('[data-erase]').onclick = () => run(async () => { if(confirm('Permanently erase this conversation and associated customer details? This cannot be undone.')) { await api(`admin/conversations/${id}`,'DELETE'); location.hash='inbox'; } });
    }
    await markConversationRead();
  }
  function appendMessages(messages) {
    const history = view.querySelector('#ctai-history'); if (!history) return;
    const atBottom = history.scrollTop + history.clientHeight >= history.scrollHeight - 90;
    for (const m of messages) {
      if (+m.seq <= lastSeq) continue;
      const article = document.createElement('article'); article.className = `ctai-message ctai-from-${m.sender} ${m.visibility === 'internal' ? 'ctai-internal' : ''}`;
      article.innerHTML = `<div><strong>${esc(m.visibility === 'internal' ? 'Private team note' : ({customer:'Customer',ai:'AI assistant',agent:'Support agent',system:'System'}[m.sender] || 'Support'))}</strong><time>${esc(time(m.created_at))}</time></div><p>${esc(m.content)}</p>`;
      const body=article.querySelector('p');body.replaceChildren();ui.text(body,m.content);ui.images(article,m.attachments,{api:boot.api,headers:()=>({'X-WP-Nonce':boot.nonce}),refresh:async()=>{boot.nonce=(await api('agent/bootstrap')).nonce;}});
      history.append(article); lastSeq = +m.seq;
    }
    if (atBottom) history.scrollTop = history.scrollHeight;
  }
  function renderActions() {
    if (!selected) return;
    const c = selected, toolbar = view.querySelector('#ctai-actions'); if (!toolbar) return;
    const actions = [];
    if (['waiting','ai_active'].includes(c.status) && !c.assigned_agent) actions.push(['claim','Take over']);
    if (['assigned','human_active'].includes(c.status)) { actions.push(['resolve','Resolve'],['return-to-ai','Return to AI']); if(boot.user.supervise) actions.push(['transfer','Transfer']); }
    if (c.status === 'resolved') actions.push(['reopen','Reopen'],['close','Close']);
    if (c.status === 'closed') actions.push(['reopen','Reopen']);
    toolbar.innerHTML = actions.map(([id,name])=>`<button data-action="${id}" class="${id==='claim'?'ctai-primary':''}">${name}</button>`).join('');
    toolbar.querySelectorAll('[data-action]').forEach(b => b.onclick = () => run(async () => {
      const action=b.dataset.action, visit=navigationId; let agent=0;
      if(action==='transfer') { const users=await api('admin/agents'); if(visit!==navigationId)return; const pick=prompt('Enter agent ID:\n'+users.map(u=>`${u.id}: ${u.name} (${u.availability})`).join('\n')); if(!pick)return; agent=+pick; }
      const updated=await api(`agent/conversations/${c.id}/actions/${action}`,'POST',{version:+c.version,agent}); if(visit!==navigationId||+selected?.id!==+c.id)return; conversationRefresh++; selected=updated; renderActions(); view.querySelector('#ctai-chat-status').textContent=label(selected.status);
    }));
  }
  async function markConversationRead() { if (selected && lastSeq && !document.hidden) await api(`agent/conversations/${selected.id}/read`,'PUT',{seq:lastSeq}); }
  async function updateConversation() {
    if (!selected || !route.startsWith('conversation/')) return;
    const id=selected.id, visit=navigationId, refresh=++conversationRefresh;
    let data;
    do {
      data=await api(`agent/conversations/${id}?after=${lastSeq}`);
      if(visit!==navigationId||+selected?.id!==+id||refresh!==conversationRefresh)return;
      selected=data.conversation; appendMessages(data.messages);const peer=view.querySelector('#ctai-peer-typing');if(peer)ui.typing(peer,data.typing?.customer,'Customer');ui.watchTyping(peer,async()=>(await api(`agent/conversations/${id}/typing`)).customer,'Customer');
    } while(data.messages.length===100);
    const s=view.querySelector('#ctai-chat-status'); if(s)s.textContent=label(selected.status); renderActions(); await markConversationRead();
  }
  async function notifications() {
    const visit=navigationId;
    const data=await api(`agent/notifications${notificationBefore?'?before='+notificationBefore:''}`); if(visit!==navigationId||!route.startsWith('notifications'))return; bellItems=data.items;
    view.innerHTML=title('Notifications','Your support updates, synchronized across devices.')+`<div class="ctai-toolbar"><button id="ctai-read-all">Mark displayed and earlier as read</button></div>`+(data.items.length?`<div class="ctai-notifications">${data.items.map(n=>`<article class="ctai-notification ${n.read_at?'':'ctai-unread'}"><span class="ctai-notification-dot"></span><div><strong>${esc(n.title)}</strong><p>${esc(label(n.type))} · ${esc(time(n.created_at))}</p>${n.preview?`<p>${esc(n.preview)}</p>`:''}<a href="#conversation/${n.conversation_id}">Open conversation #${n.conversation_id} →</a></div><button data-notification="${n.id}" ${n.read_at?'disabled':''}>${n.read_at?'Read':'Mark read'}</button></article>`).join('')}</div>`:empty('No notifications yet'))+`<div class="ctai-pagination"><button id="ctai-notification-newest">Newest</button><button id="ctai-notification-older" ${data.items.length===40?'':'disabled'}>Older</button></div>`;
    view.querySelector('#ctai-read-all').onclick=()=>run(async()=>{ if(data.items.length)await api('agent/notifications/read-all','POST',{through:+data.items[0].id}); await notifications(); await bell(); });
    view.querySelectorAll('[data-notification]').forEach(b=>b.onclick=()=>run(async()=>{ await api(`agent/notifications/${b.dataset.notification}/read`,'PUT',{}); await notifications(); await bell(); }));
    view.querySelector('#ctai-notification-newest').onclick=()=>{notificationBefore=0;run(notifications);};
    view.querySelector('#ctai-notification-older').onclick=()=>{notificationBefore=+data.items.at(-1).id;run(notifications);};
  }
  async function content(type) {
    const visit=navigationId;
    const items=await api(`admin/content/${type}?page=${page}`); if(visit!==navigationId)return; state.content=items;
    view.innerHTML=title(type==='products'?'Product catalog':'Knowledge base','Only enabled, administrator-approved information is used by AI.')+`<div class="ctai-toolbar"><button id="ctai-add-content" class="ctai-primary">Add ${type==='products'?'product':'knowledge entry'}</button></div><div id="ctai-content-editor"></div>`+table(state.content,[['title','Title'],['category','Category'],['enabled','Published',v=>+v?'Approved & enabled':'Draft'],['updated_at','Updated',v=>esc(time(v))],['id','Actions',v=>`<button data-edit="${v}">Edit</button> <button data-delete="${v}" class="ctai-text-danger">Delete</button>`]])+pagination(state.content.length===50);
    view.querySelector('#ctai-add-content').onclick=()=>editContent(type,{});
    view.querySelectorAll('[data-edit]').forEach(b=>b.onclick=()=>editContent(type,state.content.find(r=>r.id===b.dataset.edit)));
    view.querySelectorAll('[data-delete]').forEach(b=>b.onclick=()=>run(async()=>{if(confirm('Delete this approved-content record?')){await api(`admin/content/${type}/${b.dataset.delete}`,'DELETE');await content(type);}}));
    bindPages(()=>content(type));
  }
  function editContent(type,item) {
    const details=typeof item.details==='string'?JSON.parse(item.details||'{}'):(item.details||{}), editor=view.querySelector('#ctai-content-editor');
    editor.innerHTML=`<form class="ctai-card ctai-form" id="ctai-content-form"><h2>${item.id?'Edit':'Add'} ${type==='products'?'product':'knowledge'}</h2>${field('title','Title',item.title,'text',true)}${field('category','Category',item.category)}${field('content','Approved content / description',item.content,'textarea',true)}${field('keywords','Search keywords (English, Filipino, Taglish)',item.keywords)}${field('source','Source / approval reference',item.source)}${field('priority','Priority (0–100)',item.priority||0,'number')}${type==='products'?['price','currency','availability','specifications','warranty','url','image'].map(k=>field('detail_'+k,label(k),details[k]??'',k==='price'?'number':(['specifications','warranty'].includes(k)?'textarea':(['url','image'].includes(k)?'url':'text')))).join(''):''}${field('enabled','I approve this information for AI use',+item.enabled===1,'checkbox')}<div class="ctai-toolbar"><button class="ctai-primary">Save record</button><button type="button" id="ctai-cancel-content">Cancel</button></div></form>`;
    editor.querySelector('#ctai-cancel-content').onclick=()=>editor.replaceChildren();
    editor.querySelector('form').onsubmit=e=>{e.preventDefault();run(async()=>{const values=Object.fromEntries(new FormData(e.target)); values.enabled=e.target.elements.enabled.checked; values.details={}; for(const k of ['price','currency','availability','specifications','warranty','url','image'])values.details[k]=values['detail_'+k]||''; await api(`admin/content/${type}${item.id?'/'+item.id:''}`,item.id?'PUT':'POST',values); await content(type); message('Record saved.',false);});};
    if(type==='products') {
      const controls=document.createElement('label');controls.textContent='Upload product image (JPEG, PNG or WebP, up to 5 MB)';
      const file=document.createElement('input');file.type='file';file.accept='image/jpeg,image/png,image/webp';controls.append(file);editor.querySelector('form').insertBefore(controls,editor.querySelector('form>.ctai-toolbar'));
      file.onchange=()=>run(async()=>{const image=file.files[0];if(!image)return;if(image.size>5*1024*1024)throw new Error('Image exceeds 5 MB.');const fresh=await api('agent/bootstrap');boot.nonce=fresh.nonce;const body=new FormData();body.append('file',image);const response=await fetch(boot.media,{method:'POST',credentials:'same-origin',headers:{'X-WP-Nonce':boot.nonce},body});const result=await response.json();if(!response.ok)throw new Error(result.message||'Image upload failed.');editor.querySelector('[name=detail_image]').value=result.source_url;message('Image uploaded. Save the product to use it.',false);});
    }
    editor.scrollIntoView({behavior:'smooth',block:'start'});
  }
  const settingsGroups={
    ai:['ai_enabled','streaming_enabled','summarization_enabled','model','reasoning','max_output_tokens','context_messages','timeout','retry_limit','daily_budget','monthly_budget','input_price','output_price','instructions'],
    widget:['widget_enabled','bot_name','welcome','suggestions','color','logo','launcher_icon','position','offset','mobile_offset','width','height','disabled_pages','auto_greeting'],
    business:['business_name','contact','support_hours','whatsapp_number','messenger_url','viber_number','viber_url'],
    settings:['session_hours','retention_days','session_limit','poll_seconds','followup_minutes','privacy_notice']
  };
  async function settings(group) {
    const visit=navigationId;
    const [s,health]=await Promise.all([api('admin/settings'),api('admin/health')]); if(visit!==navigationId)return; state.settings=s;
    view.innerHTML=title({ai:'AI configuration',widget:'Chat widget',business:'Business information',settings:'Platform settings'}[group],group==='ai'?'Usage costs are local estimates, not official OpenAI billing.':'Configure your standalone support platform.')+`${group==='business'?'<section class="ctai-card"><h2>Messaging apps</h2><p>Enter your public business contacts below. Configured buttons appear in the customer chatbox. Leave a field blank to hide it. Customers continue in the selected app; those messages are handled there.</p></section>':''}<form class="ctai-card ctai-form" id="ctai-settings-form">${settingsGroups[group].map(k=>field(k,({input_price:'Input price — USD per million tokens (verify current model pricing)',output_price:'Output price — USD per million tokens (verify current model pricing)',session_hours:'Visitor session lifetime — hours',retention_days:'Conversation retention — days',suggestions:'Suggested questions — one per line',disabled_pages:'Disabled WordPress page IDs — one per line',followup_minutes:'Unanswered handoff alert — minutes',whatsapp_number:'WhatsApp number — country code and number, e.g. +63…',messenger_url:'Messenger link — https://m.me/YourPage',viber_number:'Viber number — country code and number, e.g. +63…',viber_url:'Viber share or bot link — optional, overrides Viber number',welcome:'Welcome message — use {name} for the customer’s name',width:'Chat window width (pixels)',height:'Chat window height (pixels)',launcher_icon:'Chat launcher icon URL — leave blank for the built-in icon'})[k]||label(k),Array.isArray(s[k])?s[k].join('\n'):s[k],typeof s[k]==='boolean'?'checkbox':typeof s[k]==='number'?'number':(['instructions','welcome','suggestions','disabled_pages','contact','support_hours','privacy_notice'].includes(k)?'textarea':k==='color'?'color':'text'))).join('')}<button class="ctai-primary">Save settings</button></form>${group==='ai'?'<div class="ctai-card"><h2>AI connection</h2><p>The API key must be configured on the server as <code>CTAI_OPENAI_KEY</code>. It is never returned to this app.</p><button id="ctai-ai-test">Test connection</button><p>Connection tests consume a small amount of your configured budget.</p></div>':''}${group==='settings'?'<div class="ctai-card"><h2>Operational requirements</h2><p>Use HTTPS, exclude private REST responses from caching, and run WordPress cron every minute for timely background alerts. Configure Firebase for the dedicated Android app below.</p><p>Deactivation preserves customer records. See the deployment guide before upgrades or uninstalling.</p></div>':''}<div class="ctai-card"><h2>Environment health</h2>${table(Object.entries(health).map(([name,value])=>({name,value:typeof value==='boolean'?(value?'Configured':'Not configured'):value})),[['name','Check'],['value','Status']])}</div>`;
    view.querySelector('#ctai-settings-form').onsubmit=e=>{e.preventDefault();run(async()=>{const data=Object.fromEntries(new FormData(e.target));for(const k of settingsGroups[group]){if(typeof s[k]==='boolean')data[k]=e.target.elements[k].checked;else if(typeof s[k]==='number')data[k]=+data[k];else if(Array.isArray(s[k]))data[k]=data[k].split('\n').map(v=>v.trim()).filter(Boolean);}await api('admin/settings','PUT',data);message('Settings saved.',false);});};
    if(group==='widget') {
      const box=document.createElement('section');box.className='ctai-card';box.innerHTML='<h2>Chat launcher icon</h2><p>Upload a JPG, PNG or WebP icon, or enter an HTTPS image URL above. Save settings to apply it.</p><label>Upload chat icon<input type="file" accept="image/jpeg,image/png,image/webp" aria-label="Upload chat icon"></label>';
      view.append(box);box.querySelector('input').onchange=e=>run(async()=>{const image=e.target.files[0];if(!image)return;if(!['image/jpeg','image/png','image/webp'].includes(image.type)||image.size>2097152)throw new Error('Choose a JPG, PNG or WebP icon up to 2 MB.');boot.nonce=(await api('agent/bootstrap')).nonce;const body=new FormData();body.append('file',image);const response=await fetch(boot.media,{method:'POST',credentials:'same-origin',headers:{'X-WP-Nonce':boot.nonce},body});const result=await response.json();if(!response.ok)throw new Error(result.message||'Icon upload failed.');const url=new URL(result.source_url);if(url.protocol!=='https:'&&!['localhost','127.0.0.1'].includes(url.hostname))throw new Error('Use an HTTPS image URL for the icon.');view.querySelector('[name=launcher_icon]').value=url.href;message('Icon uploaded. Save settings to apply it.',false);});
    }
    if(group==='ai') {
      const card=document.createElement('section');card.className='ctai-card';
      card.innerHTML='<h2>Secure API credential</h2><p>Server configuration takes precedence. You may instead save a key encrypted with WordPress salts; the saved value is never returned to this interface. Back up your WordPress configuration securely before rotating salts.</p><form id="ctai-key-form" class="ctai-form"><label>New OpenAI API key<input type="password" name="key" autocomplete="new-password" required maxlength="500"></label><button class="ctai-primary">Save encrypted key</button></form>';
      view.append(card);card.querySelector('form').onsubmit=e=>{e.preventDefault();run(async()=>{const key=e.target.elements.key.value;await api('admin/credentials','PUT',{openai_key:key});e.target.reset();message('Credential saved securely. Test the connection before enabling AI.',false);});};
    }
    if(group==='settings') {
      const box=document.createElement('section');box.className='ctai-card';box.innerHTML='<h2>Android Firebase notifications</h2><p>Register Android package <code>com.crystaltap.agent</code> in your Firebase project. Upload its google-services.json and a service-account JSON with Firebase Messaging permission. Files must belong to the same project. Private signing credentials remain encrypted on the server.</p><form id="ctai-firebase"><label>Android google-services.json<input type="file" name="android" accept="application/json,.json"></label><label>Firebase service-account JSON<input type="file" name="service" accept="application/json,.json"></label><button>Save Firebase configuration</button></form>';view.append(box);
      box.querySelector('form').onsubmit=e=>{e.preventDefault();run(async()=>{const f=e.target,data={};if(f.elements.android.files[0])data.android_config=await f.elements.android.files[0].text();if(f.elements.service.files[0])data.service_account=await f.elements.service.files[0].text();if(!Object.keys(data).length)throw new Error('Choose a Firebase configuration file.');await api('admin/firebase','POST',data);f.reset();message('Firebase configuration saved. Reopen the Android app and enable notifications, then send a test.',false);});};
    }
    const test=view.querySelector('#ctai-ai-test');if(test)test.onclick=()=>run(async()=>{test.disabled=true;try{await api('admin/ai-test','POST',{});message('OpenAI connection succeeded.',false);}finally{test.disabled=false;}});
  }
  async function leads() {
    const visit=navigationId;
    const items=await api(`agent/leads?page=${page}`); if(visit!==navigationId)return;
    view.innerHTML=title('Customer leads','Contact details shared voluntarily for customer inquiries.')+table(items,[['name','Customer'],['phone','Phone'],['email','Email'],['interest','Interest'],['status','Status',v=>esc(label(v))],['assigned_agent','Agent'],['id','Actions',(v,r)=>`<button data-lead="${v}">Update</button> <a href="#conversation/${r.conversation_id}">Chat</a>`]])+pagination(items.length===40);
    view.querySelectorAll('[data-lead]').forEach(b=>b.onclick=()=>{
      const item=items.find(r=>r.id===b.dataset.lead),old=view.querySelector('#ctai-lead-editor');if(old)old.remove();
      const f=document.createElement('form');f.id='ctai-lead-editor';f.className='ctai-card ctai-form';f.innerHTML=`<h2>${esc(item.name)}</h2><p>${esc(item.location)} · ${esc(item.water_source)}</p><p>${esc(item.inquiry)}</p><label>Status<select name="status">${['new','contacted','interested','quotation_sent','converted','closed'].map(s=>`<option value="${s}" ${s===item.status?'selected':''}>${label(s)}</option>`).join('')}</select></label>${boot.user.supervise?field('assigned_agent','Assign to agent ID',item.assigned_agent||0,'number'):''}${field('note','Add internal note','','textarea')}<button class="ctai-primary">Save lead</button>`;
      f.onsubmit=e=>{e.preventDefault();run(async()=>{const data=Object.fromEntries(new FormData(f));if('assigned_agent'in data)data.assigned_agent=+data.assigned_agent;await api(`agent/leads/${item.id}`,'PATCH',data);await leads();});};view.prepend(f);
    });if(boot.user.manage) {
      const controls=document.createElement('div');controls.className='ctai-toolbar';controls.innerHTML='<button id="ctai-export-leads">Export this page (JSON)</button>';
      controls.querySelector('button').onclick=()=>run(async()=>download('crystaltap-leads-'+page+'.json',await api('admin/leads/export?page='+page)));
      view.prepend(controls);
    }
    bindPages(leads);
  }
  async function profile() {
    const visit=navigationId;const[prefs,devices]=await Promise.all([api('agent/preferences'),api('agent/native-devices')]);if(visit!==navigationId)return;
    const linkId=new URLSearchParams(location.search).get('android_link');
    view.innerHTML=title('Your workspace',boot.user.name)+`<section class="ctai-card"><h2>CrystalTap Android</h2><p>Use the dedicated Android app for native notifications and its own alert tone. Sign in from the app, approve its matching code here, then return to the app. Browser installation and browser push have been retired.</p><div id="ctai-android-link"></div></section><section class="ctai-card"><h2>In-app browser sound</h2><button id="ctai-test-sound">Enable / test in-app sound</button> <button id="ctai-mute-sound">Mute in-app sound</button></section><form id="ctai-preferences" class="ctai-card ctai-form"><h2>Notification preferences</h2>${Object.entries(prefs).map(([k,v])=>field(k,label(k),v,typeof v==='boolean'?'checkbox':'time')).join('')}<button class="ctai-primary">Save preferences</button></form><section class="ctai-card"><h2>Android devices</h2>${table(devices,[['label','Device'],['created_at','Linked',v=>esc(time(v))],['expires_at','Expires',v=>esc(time(v))],['push_registered','Push',v=>+v?'Registered':'Not registered'],['id','Action',v=>`<button data-revoke="${v}">Sign out device</button>`]])}</section>${mobile&&more.length?`<section class="ctai-card"><h2>Management</h2><div class="ctai-toolbar">${more.map(([id,name])=>`<a href="#${id}">${name}</a>`).join('')}</div></section>`:''}<a class="ctai-logout" href="${esc(boot.logout)}">Sign out securely →</a>`;
    view.querySelector('#ctai-preferences').onsubmit=e=>{e.preventDefault();run(async()=>{const data=Object.fromEntries(new FormData(e.target));for(const[k,v]of Object.entries(prefs))if(typeof v==='boolean')data[k]=e.target.elements[k].checked;await api('agent/preferences','PUT',data);message('Preferences saved.',false);});};
    view.querySelectorAll('[data-revoke]').forEach(b=>b.onclick=()=>run(async()=>{await api(`agent/native-devices/${b.dataset.revoke}`,'DELETE');await profile();}));
    view.querySelector('#ctai-test-sound').onclick=()=>run(enableSound);view.querySelector('#ctai-mute-sound').onclick=()=>{soundEnabled=false;message('In-app sound muted.',false);};
    if(linkId&&/^[a-f0-9]{48}$/.test(linkId)){const info=await api('agent/android-link/'+linkId);if(visit!==navigationId)return;const box=view.querySelector('#ctai-android-link');box.innerHTML=`<h3>Link your Android app</h3><p>Approve only if this code matches the code shown in your CrystalTap Android app: <strong>${esc(info.code)}</strong></p><button id="ctai-approve-android" ${info.approved?'disabled':''}>${info.approved?'Approved — return to the Android app':'Approve matching Android code'}</button>`;box.querySelector('button').onclick=()=>run(async()=>{await api('agent/android-link/'+linkId,'POST',{code:info.code});box.textContent='Android sign-in approved. Return to the CrystalTap Android app.';});}
  }
  async function agents() { const visit=navigationId; const items=await api('admin/agents'); if(visit!==navigationId)return;view.innerHTML=title('Live agents','Availability requires an explicit status and a recent heartbeat.')+table(items,[['id','Agent ID'],['name','Name'],['availability','Availability'],['heartbeat','Last seen',v=>esc(time(v))]])+'<div class="ctai-card"><p>Create WordPress users with the CrystalTap Support Agent or Supervisor role in the WordPress Users screen. Agents do not need WordPress administrator access.</p></div>'; }
  async function analytics() {
    const visit=navigationId;
    const from=view.querySelector('[name=from]')?.value||'',to=view.querySelector('[name=to]')?.value||'';
    const d=await api(`admin/analytics?from=${from}&to=${to}`); if(visit!==navigationId)return;
    view.innerHTML=title('Analytics','Date-filtered conversations and locally estimated AI usage.')+`<form id="ctai-date-filter" class="ctai-toolbar">${field('from','From',from,'date')}${field('to','To',to,'date')}<button>Apply dates</button></form><div class="ctai-stats">${[['Conversations',d.conversations],['Conversations today',d.today],['Leads',d.leads],['AI requests',d.usage.requests],['Estimated USD',d.usage.estimated_usd],['AI failures / uncertain',d.usage.failures||0]].map(([n,v])=>`<div class="ctai-stat"><span>${n}</span><strong>${esc(v)}</strong></div>`).join('')}</div><div class="ctai-card"><h2>Current conversation states (all time)</h2>${table(d.states,[['status','State',v=>esc(label(v))],['count','Count']])}</div><p>Token usage: ${esc(d.usage.input_tokens)} input · ${esc(d.usage.output_tokens)} output. Estimates can differ from provider billing.</p>`;
    view.querySelector('#ctai-date-filter').onsubmit=e=>{e.preventDefault();run(analytics);};
  }
  async function logs(){const visit=navigationId;const d=await api('admin/logs');if(visit!==navigationId)return;view.innerHTML=title('Operational logs','Redacted audit and AI usage records. Customer message bodies are excluded.')+`<div class="ctai-card"><h2>Audit</h2>${table(d.audit,[['created_at','Time',v=>esc(time(v))],['user_id','Actor'],['action','Action'],['target_id','Target'],['outcome','Outcome']])}</div><div class="ctai-card"><h2>AI usage</h2>${table(d.usage,[['created_at','Time',v=>esc(time(v))],['model','Model'],['status','Status'],['input_tokens','Input'],['output_tokens','Output'],['estimated_usd','Estimated USD']])}</div>`;}
  function download(name,data){const url=URL.createObjectURL(new Blob([JSON.stringify(data,null,2)],{type:'application/json'}));const a=document.createElement('a');a.href=url;a.download=name;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);}
  function bindPages(fn){view.querySelectorAll('[data-page]').forEach(b=>b.onclick=()=>{page=+b.dataset.page;run(fn);});}
  async function navigate(){
    navigationId++;
    route=location.hash.slice(1)||'home';selected=null;message('');
    root.querySelectorAll('[data-nav]').forEach(a=>a.classList.toggle('active',route.startsWith(a.dataset.nav)||(a.dataset.nav==='inbox'&&route.startsWith('conversation/'))));
    view.innerHTML='<div class="ctai-empty" role="status">Loading your workspace…</div>';
    await run(async()=>{const section=route.split('?')[0];if(section.startsWith('conversation/'))return conversation(+section.split('/')[1]);const pages={home,inbox,notifications,leads,profile,agents,analytics,logs,products:()=>content('products'),knowledge:()=>content('knowledge'),ai:()=>settings('ai'),widget:()=>settings('widget'),business:()=>settings('business'),settings:()=>settings('settings')};if(!pages[section])throw new Error('This page is not available.');await pages[section]();});
  }
  window.addEventListener('hashchange',()=>{page=1;navigate();});
  window.addEventListener('offline',()=>{root.querySelector('#ctai-connection').textContent='Offline';message('Connection lost. Messages will not be sent while offline.');root.querySelectorAll('button[type=submit]').forEach(b=>b.disabled=true);});
  window.addEventListener('online',()=>{root.querySelector('#ctai-connection').textContent='Connected';message('Connection restored. Refreshing your workspace.',false);root.querySelectorAll('button[type=submit]').forEach(b=>b.disabled=false);run(async()=>{await bell();await updateConversation();});});
  let polling=false;
  async function poll(){if(polling||document.hidden||!navigator.onLine)return;polling=true;try{await bell();await api('agent/availability');if(route.startsWith('conversation/'))await updateConversation();else if(!['INPUT','TEXTAREA','SELECT'].includes(document.activeElement?.tagName)){if(route.startsWith('inbox'))await inbox();else if(route==='home')await home();else if(route.startsWith('notifications'))await notifications();}}catch(e){message(e.message);}finally{polling=false;}}
  document.addEventListener('visibilitychange',()=>{if(!document.hidden)poll();});
  setInterval(poll,Math.max(5,+boot.poll)*1000);
  // Unregister only the retired CrystalTap scope. Leave other site workers untouched.
  if('serviceWorker'in navigator)navigator.serviceWorker.getRegistrations().then(async regs=>{const owned=regs.filter(reg=>reg.scope===boot.app);await Promise.all(owned.map(async reg=>{try{const subscription=await reg.pushManager?.getSubscription();if(subscription)await subscription.unsubscribe();}finally{await reg.unregister();}}));if(owned.length&&'caches'in window){const keys=await caches.keys();await Promise.all(keys.filter(key=>key.startsWith('ctai-shell-')).map(key=>caches.delete(key)));}}).catch(()=>{});
  if(!location.hash){const wpPage=new URLSearchParams(location.search).get('page')||'';const map={dashboard:'home',conversations:'inbox'};const initial=wpPage.replace('ctai-','');if(initial&&initial!=='ctai')history.replaceState(null,'','#'+(map[initial]||initial));}
  navigate();run(bell);
})();
