/* Persistent reception bar: one search, direct Jalali-date navigation, no submit button. */
(() => {
 'use strict';
 const bar=document.querySelector('.clinic-reception-bar');
 if(!bar)return;
 const dateForm=bar.querySelector('[data-clinic-date-nav]');
 const dateField=dateForm?.querySelector('input[name="from"]');
 const buildDateUrl=()=>{
  const selected=dateField?.value||'';
  if(!/^\d{4}-\d{2}-\d{2}$/.test(selected))return null;
  const params=new URLSearchParams(new FormData(dateForm));
  params.set('from',selected);
  params.set('to',selected);
  params.delete('p');
  params.delete('q');
  return '/appointments?'+params.toString();
 };
 if(dateForm&&dateField){
  let navigating=false;
  const navigate=()=>{
   if(navigating||!dateField.value)return;
   const href=buildDateUrl();if(!href)return;
   navigating=true;
   window.location.assign(href);
  };
  dateField.addEventListener('change',navigate);
  dateForm.addEventListener('submit',event=>{event.preventDefault();navigate();});
  dateField.addEventListener('input',event=>{
   if(dateField.value&&event.isTrusted&&!dateField.dataset.masihaDate)navigate();
  });
 }
 const search=bar.querySelector('[data-smart-search]');
 if(!search)return;
 const input=search.querySelector('input[type=search]');
 const resultBox=search.querySelector('.live-results');
 if(!input||!resultBox)return;
 let controller=null,timer=null,lastResults=[];
 const day=()=>dateField?.value||bar.dataset.selectedDate||'';
 const hide=()=>{resultBox.hidden=true;input.setAttribute('aria-expanded','false');};
 const show=()=>{resultBox.hidden=false;input.setAttribute('aria-expanded','true');};
 const resultLink=patient=>{
  const a=document.createElement('a');
  a.className='live-result clinic-smart-result';
  a.href=patient.href||'/patient?id='+encodeURIComponent(patient.id);
  const name=document.createElement('strong');
  name.textContent=patient.name;
  const status=document.createElement('span');
  status.className='clinic-search-context'+(patient.has_appointment?' has-visit':'');
  status.textContent=patient.has_appointment?'نوبت در روز انتخابی':'مشاهده پرونده بیمار';
  const subtitle=document.createElement('small');
  subtitle.textContent=patient.phone||patient.national_id||('پرونده '+patient.id);
  a.append(name,status,subtitle);
  return a;
 };
 async function fetchMatches(){
  const term=input.value.trim();
  if(term.length<2){lastResults=[];hide();return;}
  controller?.abort();
  controller=new AbortController();
  show();
  resultBox.textContent='در حال جستجو…';
  try{
   const params=new URLSearchParams({q:term,date:day()});
   const response=await fetch('/api/patients?'+params.toString(),{
    signal:controller.signal,
    credentials:'same-origin',
    cache:'no-store',
    headers:{Accept:'application/json'}
   });
   if(!response.ok)throw Error('جستجو انجام نشد.');
   const rows=await response.json();
   if(!Array.isArray(rows))throw Error('اطلاعات جستجو معتبر نیست.');
   lastResults=rows;
   if(term!==input.value.trim())return;
   resultBox.replaceChildren();
   if(!rows.length){
    resultBox.textContent='مراجعه‌کننده‌ای با این مشخصات پیدا نشد.';
   }else{
    const fragment=document.createDocumentFragment();
    rows.forEach(row=>fragment.append(resultLink(row)));
    resultBox.append(fragment);
   }
   show();
  }catch(error){
   if(error.name==='AbortError')return;
   lastResults=[];
   resultBox.textContent='جستجو انجام نشد؛ دوباره تلاش کنید.';
   show();
  }
 }
 input.addEventListener('input',()=>{
  clearTimeout(timer);
  controller?.abort();
  if(input.value.trim().length<2){lastResults=[];hide();return;}
  timer=setTimeout(fetchMatches,190);
 });
 input.addEventListener('keydown',event=>{
  if(event.key==='Escape'){hide();input.blur();}
  else if(event.key==='Enter'){
   event.preventDefault();
   const best=resultBox.querySelector('a[href]');
   if(best)window.location.assign(best.href);
   else if(input.value.trim().length>=2)fetchMatches();
  }else if(event.key==='ArrowDown'){
   const first=resultBox.querySelector('a[href]');
   if(first){event.preventDefault();first.focus();}
  }
 });
 search.addEventListener('focusout',event=>{
  if(!search.contains(event.relatedTarget))setTimeout(hide,90);
 });
 document.addEventListener('click',event=>{
  if(!search.contains(event.target))hide();
 });
})();
