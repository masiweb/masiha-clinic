'use strict';
(() => {
  const normalize = s => String(s||'').normalize('NFKC').replace(/ي/g,'ی').replace(/ك/g,'ک').replace(/[\u200c\u200e\u200f]/g,' ').trim().toLowerCase();
  const notice = (message, bad=false) => {
    let box=document.getElementById('reception-live-alert');
    if(!box){box=document.createElement('div');box.id='reception-live-alert';box.setAttribute('role','status');box.setAttribute('aria-live','polite');document.body.append(box);}
    box.textContent=message;box.dataset.bad=bad?'1':'0';box.hidden=false;
    clearTimeout(notice.timeout);notice.timeout=setTimeout(()=>box.hidden=true,bad?6500:2400);
  };
  const findRow = id => [...document.querySelectorAll('tr.visit-row[data-session-id]')].find(row=>row.dataset.sessionId===String(id));
  const refreshRow = (id,html) => {
    const current=findRow(id);if(!current||!html)return;
    const body=document.createElement('tbody');body.innerHTML=html;
    const replacement=body.firstElementChild;
    if(!replacement||replacement.tagName!=='TR')return;
    current.replaceWith(replacement);
    window.MasihaInitReceptionEditors?.(replacement);
    replacement.querySelectorAll('[data-hashtag-color]').forEach(el=>{
      if(/^#[0-9a-f]{6}$/i.test(el.dataset.hashtagColor||''))el.style.setProperty('--hashtag-color',el.dataset.hashtagColor);
    });
  };
  const inFlight=new Set();
  function prepareHashtag(form){
    const input=form.querySelector('[data-smart-hashtag]');if(!input)return;
    const typed=input.value.trim().replace(/^#+/u,'');
    if(!typed)return;
    const existing=[...form.querySelectorAll('[data-choice-option]')].find(el=>normalize(el.querySelector('span')?.textContent)===normalize(typed));
    if(existing){existing.querySelector('input[type="checkbox"]').checked=true;input.value='';}
    else input.value=typed;
  }
  async function save(form){
    const id=form.querySelector('[name="session_id"]')?.value;if(!id||inFlight.has(id))return;
    inFlight.add(id);
    prepareHashtag(form);
    const data=new FormData(form);
    const fields=[...form.querySelectorAll('input,textarea,select,button')];
    fields.forEach(node=>node.disabled=true);
    form.setAttribute('aria-busy','true');
    try{
      const res=await fetch('/api/reception/save',{method:'POST',body:data,credentials:'same-origin',headers:{Accept:'application/json'}});
      const result=await res.json();
      if(!res.ok||!result.ok){const ex=new Error(result.error||'ثبت اطلاعات انجام نشد.');ex.status=res.status;throw ex;}
      refreshRow(id,result.html);
      notice('تغییرات مراجعه ذخیره شد.');
    }catch(err){
      if(err.status===409){
        try{
          const resp=await fetch('/api/reception/row?session_id='+encodeURIComponent(id),{credentials:'same-origin',headers:{Accept:'application/json'}});
          if(resp.ok){const current=await resp.json();if(current.ok)refreshRow(id,current.html);}
        }catch(e){}
      }
      notice(err.message||'ارتباط با سرور برقرار نشد.',true);
      if(form.isConnected){fields.forEach(node=>node.disabled=false);form.removeAttribute('aria-busy');}
    }finally{inFlight.delete(id);}
  }
  document.addEventListener('submit',e=>{
    const form=e.target;
    if(!form.matches('.reception-list form'))return;
    const action=form.querySelector('[name="action"]')?.value;
    if(action!=='reception_update'&&action!=='visit_state')return;
    e.preventDefault();e.stopImmediatePropagation();save(form);
  },true);

  function updateHashtagFeedback(editor){
    const input=editor.querySelector('[data-smart-hashtag]'),feedback=editor.querySelector('[data-smart-hashtag-feedback]');
    if(!input||!feedback)return;
    const query=normalize(input.value.replace(/^#+/u,''));
    const present=[...editor.querySelectorAll('[data-choice-option]')].some(node=>normalize(node.querySelector('span')?.textContent)===query);
    feedback.textContent=!query?'هشتگ موجود را انتخاب کنید یا برای ساخت هشتگ جدید نام آن را بنویسید.':present?'این هشتگ قبلاً ثبت شده؛ همان مورد هنگام ذخیره انتخاب می‌شود.':'این هشتگ جدید است و هنگام ذخیره ساخته خواهد شد.';
  }
  document.addEventListener('input',e=>{
    if(e.target.matches('[data-smart-hashtag]'))updateHashtagFeedback(e.target.closest('[data-row-editor]'));
  });
  document.addEventListener('keydown',e=>{
    if(!e.target.matches('[data-smart-hashtag]')||e.key!=='Enter')return;
    e.preventDefault();e.stopImmediatePropagation();e.target.form?.requestSubmit();
  },true);
})();