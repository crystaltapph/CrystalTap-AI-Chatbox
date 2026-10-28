/* Shared chat controls. Content stays text; private images are fetched with session authorization. */
(() => {
  'use strict';
  const make=(tag,cls,text)=>{const node=document.createElement(tag);if(cls)node.className=cls;if(text!==undefined)node.textContent=text;return node;};
  const blobs=new Map(),disposers=new Map();
  new MutationObserver(()=>{for(const[node,url]of blobs)if(!node.isConnected){URL.revokeObjectURL(url);blobs.delete(node);}for(const[node,dispose]of disposers)if(!node.isConnected){dispose();disposers.delete(node);}}).observe(document.documentElement,{childList:true,subtree:true});
  function text(node,value){
    const content=String(value??'');let end=0;
    for(const match of content.matchAll(/https?:\/\/[^\s<>"']+/gi)){
      const raw=match[0].replace(/[),.!?;:\]}]+$/,'');let url;
      try{url=new URL(raw);}catch(_){continue;}
      if(!['http:','https:'].includes(url.protocol)||url.username||url.password)continue;
      node.append(document.createTextNode(content.slice(end,match.index)));
      const link=make('a','ctai-message-link',raw);link.href=url.href;link.target='_blank';link.rel='noopener noreferrer';link.referrerPolicy='no-referrer';node.append(link);end=match.index+raw.length;
    }
    node.append(document.createTextNode(content.slice(end)));
  }
  function images(node,items,options){
    for(const item of items||[]){
      const figure=make('figure','ctai-image'),link=make('a'),img=make('img');
      img.alt='Chat image attachment';img.width=item.width;img.height=item.height;img.loading='lazy';link.target='_blank';link.rel='noopener noreferrer';link.setAttribute('aria-label','Open attached image');link.append(img);figure.append(link);node.append(figure);
      (async()=>{
        let response=await fetch(options.api+'images/'+Number(item.id),{credentials:'same-origin',cache:'no-store',headers:options.headers?.()||{}});
        if(!response.ok&&options.refresh){const error=await response.clone().json().catch(()=>({}));if(error.code==='rest_cookie_invalid_nonce'){await options.refresh();response=await fetch(options.api+'images/'+Number(item.id),{credentials:'same-origin',cache:'no-store',headers:options.headers()});}}
        if(!response.ok||!/^image\/(jpeg|png|webp)$/.test(response.headers.get('Content-Type')||''))throw new Error('Image unavailable.');
        const blob=await response.blob();if(!figure.isConnected)return;
        const url=URL.createObjectURL(blob);blobs.set(figure,url);img.src=url;link.href=url;
      })().catch(()=>{figure.replaceChildren(make('span','','Image unavailable. Refresh the conversation to try again.'));});
    }
  }
  function composer(input,holder,options){
    const tools=make('div','ctai-compose-tools'),emoji=make('button','','☺'),picker=make('div','ctai-emoji-picker'),label=make('label','ctai-file-label','Attach images'),file=make('input'),preview=make('div','ctai-selected-images'),status=make('span','ctai-compose-status');
    emoji.type='button';emoji.setAttribute('aria-label','Add emoji');emoji.setAttribute('aria-expanded','false');picker.hidden=true;picker.setAttribute('role','group');picker.setAttribute('aria-label','Smileys');
    emoji.onclick=()=>{picker.hidden=!picker.hidden;emoji.setAttribute('aria-expanded',String(!picker.hidden));};
    for(const face of ['😀','😊','😂','❤️','👍','🙏','😢','🎉']){const b=make('button','',face);b.type='button';b.setAttribute('aria-label','Insert '+face);b.onclick=()=>{if(input.disabled)return;const start=input.selectionStart??input.value.length,end=input.selectionEnd??start;if(input.maxLength>0&&input.value.length-(end-start)+face.length>input.maxLength)return;input.setRangeText(face,start,end,'end');input.dispatchEvent(new Event('input',{bubbles:true}));input.focus();picker.hidden=true;emoji.setAttribute('aria-expanded','false');};picker.append(b);}
    file.type='file';file.accept='image/jpeg,image/png,image/webp';file.multiple=true;file.setAttribute('aria-label','Attach images');label.append(file);tools.append(emoji,label,picker,status);holder.append(tools,preview);
    const selected=[];let locked=false,lastTyping=0,idle;
    const render=()=>{preview.replaceChildren();for(const item of selected){const row=make('span','ctai-selected-image'),name=make('span','',item.file.name),remove=make('button','','×');remove.type='button';remove.setAttribute('aria-label','Remove '+item.file.name);remove.disabled=locked;remove.onclick=()=>{selected.splice(selected.indexOf(item),1);render();};row.append(name,remove);preview.append(row);}};
    file.onchange=()=>{status.textContent='';for(const value of file.files){if(selected.length>=3){status.textContent='Attach up to three images.';break;}if(!['image/jpeg','image/png','image/webp'].includes(value.type)||value.size>2097152||!value.size){status.textContent='Choose JPG, PNG or WebP images up to 2 MB each.';continue;}selected.push({file:value,key:crypto.randomUUID(),id:null});}file.value='';render();};
    const stop=()=>{clearTimeout(idle);if(lastTyping){lastTyping=0;Promise.resolve(options.typing?.(false)).catch(()=>{});}};
    input.addEventListener('input',()=>{clearTimeout(idle);if(!input.value.trim()||options.private?.()){stop();return;}if(Date.now()-lastTyping>3000){lastTyping=Date.now();Promise.resolve(options.typing?.(true)).catch(()=>{});}idle=setTimeout(stop,3000);});
    input.addEventListener('blur',stop);const visibility=()=>{if(document.hidden)stop();};document.addEventListener('visibilitychange',visibility);disposers.set(holder,()=>{stop();document.removeEventListener('visibilitychange',visibility);});
    return {hasImages:()=>selected.length>0,signature:()=>selected.map(i=>i.key).join(','),stop,
      lock(value){locked=value;file.disabled=value;emoji.disabled=value;render();},
      async upload(){const ids=[];try{for(const item of selected){if(!item.id){status.textContent='Uploading image…';const result=await options.upload(item.file,item.key);item.id=result.id;}ids.push(item.id);}return ids.sort((a,b)=>a-b);}finally{status.textContent='';}},
      clear(){selected.length=0;status.textContent='';render();}
    };
  }
  function typing(node,active,label){node.textContent=active?label+' is typing…':'';node.classList.toggle('is-typing',!!active);}
  function watchTyping(node,read,label){let busy=false;const timer=setInterval(async()=>{if(busy||document.hidden||!navigator.onLine||!node.isConnected)return;busy=true;try{typing(node,await read(),label);}catch(_){}finally{busy=false;}},2000);disposers.set(node,()=>clearInterval(timer));}
  window.CTAIChatUI={text,images,composer,typing,watchTyping};
})();
