/* Explicit AI consent, transient voice recording and human-approved form suggestions. */
document.querySelectorAll('[data-ai-voice]').forEach(box=>{
 const form=box.closest('form');if(!form)return;
 const status=box.querySelector('.ai-voice-status'),consent=box.querySelector('[data-ai-consent]');
 const start=box.querySelector('[data-ai-start]'),stop=box.querySelector('[data-ai-stop]');
 const textButton=box.querySelector('[data-ai-text]'),textarea=box.querySelector('[data-ai-transcript]');
 const results=box.querySelector('.ai-voice-results'),container=box.querySelector('[data-ai-fields]');
 const apply=box.querySelector('[data-ai-apply]');let rec=null,stream=null,pending={},busy=false;
 const show=s=>status.textContent=s;
 async function analyze(file=null){
  if(!consent.checked){show('ابتدا رضایت ارسال اطلاعات را تأیید کنید.');return}
  if(busy)return;busy=true;start.disabled=textButton.disabled=true;results.hidden=true;show('در حال پردازش…');
  try{
   const fd=new FormData();fd.append('csrf',form.querySelector('[name=csrf]')?.value||'');fd.append('mode',box.dataset.aiVoice);
   if(file)fd.append('audio',file,file.name);else fd.append('transcript',textarea.value.trim());
   const response=await fetch('/api/ai/form-fill',{method:'POST',credentials:'same-origin',body:fd,headers:{Accept:'application/json'}});
   const data=await response.json();if(!response.ok)throw Error(data.error||'سرویس پاسخ نداد.');
   textarea.value=data.transcript||textarea.value;pending=data.fields||{};container.replaceChildren();
   let found=0;
   for(const [key,value] of Object.entries(pending)){
    const target=form.elements.namedItem(key);if(!target||!('value'in target))continue;
    const row=document.createElement('label');row.className='ai-suggested-field';
    const tick=document.createElement('input');tick.type='checkbox';tick.checked=true;tick.dataset.aiField=key;
    const heading=document.createElement('strong');heading.textContent=target.closest('.field')?.querySelector('label')?.textContent?.trim()||key;
    const suggestion=document.createElement('span');
    const dateField=(key==='DOB'||key==='date')&&/^\d{4}-\d{2}-\d{2}$/.test(value);
    const jalali=dateField&&window.MasihaDate?.jal?.(value);
    suggestion.textContent=jalali?('تاریخ شمسی: '+jalali):value;
    if(jalali)suggestion.title='معادل میلادی برای ذخیره: '+value;
    row.append(tick,heading,suggestion);container.append(row);found++;
   }
   if(!found){show('فیلد قابل‌اعمال از متن استخراج نشد.');return;}
   results.hidden=false;show('پیشنهادها آماده‌اند؛ مقادیر را بررسی کنید.');
  }catch(e){show(e?.message||'خطای پردازش درخواست');}
  finally{busy=false;start.disabled=textButton.disabled=false;}
 }
 textButton.addEventListener('click',()=>analyze());
 start.addEventListener('click',async()=>{
  if(!consent.checked){show('ابتدا رضایت استفاده از سرویس را تأیید کنید.');return;}
  if(!window.isSecureContext||!navigator.mediaDevices?.getUserMedia){show('ضبط میکروفون فقط در HTTPS فعال است؛ از ورود متنی استفاده کنید.');return;}
  if(!window.MediaRecorder){show('مرورگر از ضبط صدا پشتیبانی نمی‌کند.');return;}
  try{
   stream=await navigator.mediaDevices.getUserMedia({audio:true});
   const codec=['audio/webm;codecs=opus','audio/ogg;codecs=opus','audio/mp4'].find(x=>MediaRecorder.isTypeSupported(x));
   rec=new MediaRecorder(stream,codec?{mimeType:codec}:{});
   const chunks=[];rec.ondataavailable=e=>{if(e.data.size)chunks.push(e.data)};
   rec.onstop=()=>{
    stream?.getTracks().forEach(t=>t.stop());stream=null;stop.disabled=true;start.disabled=false;
    const blob=new Blob(chunks,{type:rec.mimeType||'audio/webm'});
    const ext=blob.type.includes('mp4')?'m4a':blob.type.includes('ogg')?'ogg':'webm';
    if(blob.size>6*1024*1024){show('فایل صوتی بیشتر از ۶ مگابایت است.');return;}
    analyze(new File([blob],'voice.'+ext,{type:blob.type}));
   };
   rec.start(250);start.disabled=true;stop.disabled=false;show('در حال ضبط… پس از صحبت، پایان ضبط را بزنید.');
  }catch(e){show('دسترسی به میکروفون ممکن نشد.');stream?.getTracks().forEach(t=>t.stop());}
 });
 stop.addEventListener('click',()=>{if(rec&&rec.state==='recording')rec.stop()});
 apply.addEventListener('click',()=>{
  let count=0;
  container.querySelectorAll('input[data-ai-field]:checked').forEach(input=>{
   const key=input.dataset.aiField,target=form.elements.namedItem(key),value=pending[key];
   if(!target||!('value'in target)||typeof value!=='string')return;
   if(target.tagName==='SELECT'&&![...target.options].some(option=>option.value===value))return;
   target.value=value;target.dispatchEvent(new Event('input',{bubbles:true}));target.dispatchEvent(new Event('change',{bubbles:true}));count++;
  });
  results.hidden=true;show(count+' فیلد پیشنهادشده اعمال شد؛ پیش از ذخیره فرم را بررسی کنید.');
 });
});