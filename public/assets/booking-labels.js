'use strict';
(() => {
 const workspace=document.querySelector('[data-booking-workspace]');if(!workspace)return;
 const form=workspace.closest('form');
 const preview=workspace.querySelector('[data-auto-label-preview]');
 const field=name=>form.elements.namedItem(name);
 if(!preview)return;
 let request=0, controller, timeout;
 const show=(name,detail,color)=>{
  preview.replaceChildren();
  const dot=document.createElement('span');dot.className='booking-label-indicator';
  if(/^#[0-9a-f]{6}$/i.test(color||''))dot.style.backgroundColor=color;
  const text=document.createElement('span');text.className='booking-label-description';
  const heading=document.createElement('strong');heading.textContent=name;
  const note=document.createElement('small');note.textContent=detail;
  text.append(heading,note);preview.append(dot,text);
 };
 async function refresh(){
  const id=++request;
  controller?.abort();
  const label=field('label_id');
  if(label?.value&&label.value!=='0'){
   show('لیبل انتخابی: '+label.selectedOptions[0]?.textContent,'انتخاب دستی شما بر قوانین خودکار اولویت دارد.','#0d9488');
   return;
  }
  show('در حال محاسبه لیبل پیشنهادی','بر اساس سوابق بیمار، خدمت و شرایط نوبت','#64748b');
  controller=new AbortController();
  try{
   const q=new URLSearchParams({pid:field('pid')?.value||'0',service_id:field('service_id')?.value||'0',date:field('date')?.value||'',booking_source:field('booking_source')?.value||'scheduled'});
   if(!q.get('date'))return;
   const response=await fetch('/api/booking-auto-label?'+q,{credentials:'same-origin',signal:controller.signal});
   const data=await response.json();
   if(id!==request)return;
   if(!response.ok)throw Error(data.error||'پیشنهاد در دسترس نیست.');
   show('لیبل خودکار: '+data.name,data.explanation,data.color);
  }catch(error){
   if(error.name!=='AbortError'&&id===request)show('پیشنهاد در دسترس نیست','انتخاب دستی لیبل همچنان امکان‌پذیر است.','#d97706');
  }
 }
 function schedule(){
  clearTimeout(timeout);
  timeout=setTimeout(refresh,170);
 }
 for(const name of ['pid','date','booking_source','service_id','label_id']){
  field(name)?.addEventListener('change',schedule);
 }
 const service=field('service_id');
 if(service)new MutationObserver(schedule).observe(service,{childList:true});
 document.addEventListener('booking-options-updated',schedule);
 schedule();
})();
