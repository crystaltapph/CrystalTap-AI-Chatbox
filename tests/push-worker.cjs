const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const handlers={},deleted=[];let unregistered=false,skipped=false;
const context={caches:{keys:async()=>['ctai-shell-0.1.5','other-site-cache'],delete:async key=>deleted.push(key)},self:{addEventListener:(type,fn)=>handlers[type]=fn,skipWaiting:async()=>{skipped=true;},registration:{unregister:async()=>{unregistered=true;}}}};
vm.runInNewContext(fs.readFileSync('mobile/sw.js','utf8'),context);
(async()=>{let pending;handlers.install({waitUntil:p=>pending=p});await pending;handlers.activate({waitUntil:p=>pending=p});await pending;assert.equal(skipped,true);assert.equal(unregistered,true);assert.deepEqual(deleted,['ctai-shell-0.1.5']);assert.equal(handlers.push,undefined);assert.equal(handlers.fetch,undefined);console.log('5 PWA retirement checks passed.');})().catch(e=>{console.error(e);process.exitCode=1;});
