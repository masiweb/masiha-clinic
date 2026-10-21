'use strict';
(() => {
 const workspace=document.querySelector('[data-booking-workspace]');if(!workspace)return;
 const form=workspace.closest('form'),field=name=>form.elements.namedItem(name);
 const output=workspace.querySelector('[data-slot-results]'),status=workspace.querySelector('[data-booking-status]'),summary=workspace.querySelector('[data-booking-summary]');
 const identityBox=workspace.querySelector('[data-booking-patient-status]');
 const statusTitle=identityBox.querySelector('strong'),statusDetail=identityBox.querySelector('p');
 let identityState=Number(field('pid').value)?'existing':'empty',identityTimer,identityAbort,identityRequest=0;
 const persianDigits=s=>String(s).replace(/[۰-۹٠-٩]/g,c=>('۰۱۲۳۴۵۶۷۸۹'.includes(c)?'۰۱۲۳۴۵۶۷۸۹':'٠١٢٣٤٥٦٧٨٩').indexOf(c));
 const nationalValue=()=>persianDigits(field('national_id').value).replace(/[\s\-]/g,'');
 const mobileValue=()=>{
  let v=persianDigits(field('phone_cell').value).replace(/[\s()+\-]/g,'');
  if(v.startsWith('0098'))v='0'+v.slice(4);else if(v.startsWith('98'))v='0'+v.slice(2);
  return v;
 };
 const canCreate=workspace.dataset.canCreate==='1';
 const state=(type,title,detail)=>{
  identityState=type;identityBox.dataset.state=type;
  statusTitle.textContent=title;statusDetail.textContent=detail;
 };
 function changePid(value){
  if(field('pid').value===String(value))return;
  field('pid').value=String(value);field('pid').dispatchEvent(new Event('change',{bubbles:true}));
  if(typeof bookingOptions==='function')bookingOptions();
 }
 async function identify(){
  const serial=++identityRequest;identityAbort?.abort();identityAbort=new AbortController();
  const national=nationalValue(),mobile=mobileValue();
  if(!national&&!mobile){
   changePid(0);state('empty','اطلاعات بیمار','کد ملی یا شماره موبایل را وارد کنید.');
   return;
  }
  if((national&&national.length!==10)||(mobile&& !/^09\d{9}$/.test(mobile))){
   changePid(0);state('pending','در انتظار تکمیل شناسه','کد ملی ۱۰ رقمی یا موبایل ۱۱ رقمی معتبر وارد کنید.');
   return;
  }
  state('pending','در حال بررسی پرونده','مشخصات در سامانه بررسی می‌شوند.');
  try{
   const url='/api/booking-patient-lookup?'+new URLSearchParams({national_id:national,phone_cell:mobile});
   const response=await fetch(url,{signal:identityAbort.signal,credentials:'same-origin'});
   const data=await response.json();if(serial!==identityRequest)return;
   if(!response.ok)throw Error(data.error||'بررسی پرونده انجام نشد.');
   if(data.state==='existing'){
    changePid(data.pid);
    state('existing','پرونده موجود: '+data.name,'شماره پرونده '+data.pid+' · نوبت برای همین بیمار ثبت می‌شود.');
    if(!field('fname').value)field('fname').value=data.name.split(/\s+/)[0]||'';
    if(!field('lname').value)field('lname').value=data.name.split(/\s+/).slice(1).join(' ');
   }else if(data.state==='new'&&canCreate){
    changePid(0);state('new','مراجعه‌کننده جدید','با ثبت نوبت، پرونده این بیمار هم به‌صورت خودکار تشکیل خواهد شد.');
   }else{
    changePid(0);state('conflict',data.state==='new'?'مجوز ایجاد پرونده ندارید':'اطلاعات هویتی نیازمند بررسی است',data.message||'لطفاً پرونده را بررسی کنید.');
   }
   updateSummary();load();
  }catch(err){
   if(err.name==='AbortError')return;
   changePid(0);state('conflict','بررسی بیمار ناموفق بود',err.message||'ارتباط با سرور برقرار نشد.');
   updateSummary();
  }
 }
 for(const name of ['national_id','phone_cell']){
  field(name).addEventListener('input',()=>{
   clearTimeout(identityTimer);identityRequest++;identityAbort?.abort();
   changePid(0);state('pending','در حال بررسی','پس از تکمیل شناسه، نتیجه نمایش داده می‌شود.');
   identityTimer=setTimeout(identify,420);
  });
 }
 const dateFormat=new Intl.DateTimeFormat('fa-IR',{weekday:'long',day:'numeric',month:'long'}),number=new Intl.NumberFormat('fa-IR');
 const dayDate=day=>new Date(day+'T12:00:00Z'),iso=d=>d.toISOString().slice(0,10);
 // Keep the native select as the canonical value for dependent service loading.
 const therapist=field('therapist_id'),choices=document.createElement('div');choices.className='booking-therapists';choices.setAttribute('role','radiogroup');choices.setAttribute('aria-label','انتخاب درمانگر');
 for(const option of therapist.options){if(!option.value)continue;const label=document.createElement('label'),input=document.createElement('input');input.type='radio';input.required=true;input.name='booking_therapist_choice';input.value=option.value;input.checked=option.selected;label.append(input,document.createTextNode(option.textContent));choices.append(label);input.addEventListener('change',()=>{therapist.value=input.value;therapist.dispatchEvent(new Event('change',{bubbles:true}));});}
 therapist.hidden=true;therapist.required=false;therapist.after(choices);
 // Hidden manual fields are populated from the board and checked on submit/server.
 for(const name of ['date','time'])if(field(name).closest('.booking-field')?.hidden)field(name).required=false;
 let request=0,controller,timer,selecting=false;
 const watched=['pid','therapist_id','clinic_id','date','duration','room'];
 const signature=()=>watched.map(name=>field(name)?.value||'').join('|');let lastSignature=signature();
 const today=()=>new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Tehran',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
 function updateSummary(){
  const patient=identityState==='existing'?statusTitle.textContent:(identityState==='new'?'بیمار جدید: '+[field('fname').value,field('lname').value].join(' '):'منتظر شناسایی بیمار'),therapist=field('therapist_id').selectedOptions[0]?.textContent||'';
  const date=field('date').value,time=field('time').value;
  const parts=[
   ['بیمار',patient],
   ['درمانگر',therapist],
   ['تاریخ',date?dateFormat.format(dayDate(date)):'انتخاب نشده'],
   ['ساعت / مدت',time?time+' · '+number.format(Number(field('duration').value))+' دقیقه':'انتخاب نشده']
  ];
  summary.replaceChildren();
  for(const [label,value] of parts){
   const cell=document.createElement('span');cell.className='booking-summary-item';
   const caption=document.createElement('small');caption.textContent=label;
   const content=document.createElement('strong');content.textContent=value;content.title=value;
   cell.append(caption,content);summary.append(cell);
  }
 }
 function invalidate(){request++;controller?.abort();field('time').value='';field('equipment').value='';output.replaceChildren();updateSummary();}
 function setDate(day){field('date').value=day;field('date').dispatchEvent(new Event('change',{bubbles:true}));}
 function monthButtons(){
  const target=workspace.querySelector('[data-booking-months]');target.replaceChildren();const api=window.MasihaDate;if(!api)return;
  const [year,month]=api.parts(dayDate(today()));
  for(let i=0;i<5;i++){const m=(month-1+i)%12+1,y=year+Math.floor((month-1+i)/12),d=api.from(y,m,1);const b=document.createElement('button');b.type='button';b.className='button secondary';b.textContent=new Intl.DateTimeFormat('fa-IR',{month:'long',year:'numeric'}).format(d);b.addEventListener('click',()=>setDate(i===0?today():iso(d)));target.append(b);}
 }
 async function load(){
  updateSummary();
  const id=++request;controller?.abort();controller=new AbortController();output.replaceChildren();
  if(!Number(field('pid').value)&&identityState!=='new'){status.textContent='برای دیدن زمان‌های آزاد، ابتدا کد ملی یا موبایل بیمار را بررسی کنید.';return;}
  const from=field('date').value,duration=Number(field('duration').value);
  if(!from||!Number.isInteger(duration)||duration<5||duration>480){status.textContent='تاریخ و مدت جلسه معتبر انتخاب کنید.';return;}
  const params=new URLSearchParams({pid:field('pid').value,therapist:field('therapist_id').value,clinic:field('clinic_id').value,from,duration,room:field('room').value,days:7,board:1});
  status.textContent='در حال بررسی برنامه حضور و تداخل نوبت‌ها…';output.setAttribute('aria-busy','true');
  try{
   const response=await fetch('/api/appointment-slots?'+params,{signal:controller.signal});const data=await response.json();if(id!==request)return;if(!response.ok)throw Error(data.error||'دریافت زمان‌ها انجام نشد.');
   for(let i=0;i<7;i++){
    const d=dayDate(from);d.setUTCDate(d.getUTCDate()+i);const day=iso(d),slots=data.slots.filter(s=>s.starts_at.startsWith(day));
    const card=document.createElement('section');card.className='booking-day';const h=document.createElement('h3');h.textContent=dateFormat.format(d);card.append(h);
    const count=document.createElement('small');count.textContent=number.format(slots.filter(s=>!s.booked).length)+' آزاد · '+number.format(slots.filter(s=>s.booked).length)+' رزروشده';card.append(count);
    if(!slots.length){const empty=document.createElement('p');empty.className='booking-empty';empty.textContent='زمان آزادی در برنامه ثبت‌شده نیست';card.append(empty);}
    for(const slot of slots){
     const button=document.createElement('button');button.type='button';button.className='booking-slot';button.setAttribute('aria-pressed','false');button.textContent=slot.starts_at.slice(11,16)+' – '+slot.ends_at.slice(11,16)+(slot.room?' · '+slot.room:'');
     if(slot.booked){button.classList.add('booked');button.disabled=true;button.setAttribute('aria-label',button.textContent+'، رزروشده');const names=document.createElement('small');names.textContent=(slot.patient_names||[]).join('، ')||'رزروشده';button.append(names);card.append(button);continue;}
     button.addEventListener('click',()=>{
      selecting=true;setDate(day);field('time').value=slot.starts_at.slice(11,16);field('room').value=slot.room;field('equipment').value=slot.equipment;selecting=false;lastSignature=signature();
      output.querySelectorAll('.booking-slot').forEach(b=>{b.classList.remove('selected');b.setAttribute('aria-pressed','false');});button.classList.add('selected');button.setAttribute('aria-pressed','true');
      status.textContent='زمان انتخاب شد؛ برای رزرو، «ثبت نوبت» را بزنید.';updateSummary();
     });card.append(button);
    }output.append(card);
   }
   status.textContent=data.slots.length?'روز و ساعت دلخواه را انتخاب کنید.':'در این هفته زمان آزادی ثبت نشده است؛ هفته دیگر یا برنامه حضور درمانگر را بررسی کنید.';
  }catch(error){if(error.name!=='AbortError'&&id===request)status.textContent=error.message||'اتصال برقرار نشد؛ دوباره تلاش کنید.';}finally{if(id===request)output.removeAttribute('aria-busy');}
 }
 for(const name of watched)field(name)?.addEventListener('change',()=>{if(selecting||signature()===lastSignature)return;lastSignature=signature();invalidate();clearTimeout(timer);timer=setTimeout(load,180);});
 field('time').addEventListener('input',()=>{output.querySelectorAll('.booking-slot').forEach(b=>{b.classList.remove('selected');b.setAttribute('aria-pressed','false');});updateSummary();});
 workspace.querySelector('[data-find-slots]').addEventListener('click',()=>{clearTimeout(timer);invalidate();load();});
 workspace.querySelectorAll('[data-week-step]').forEach(b=>b.addEventListener('click',()=>{const d=dayDate(field('date').value||today());d.setUTCDate(d.getUTCDate()+Number(b.dataset.weekStep));setDate(iso(d));}));
 workspace.querySelector('[data-booking-today]').addEventListener('click',()=>setDate(today()));
 form.addEventListener('submit',e=>{if(!therapist.value||!field('date').value||!field('time').value){e.preventDefault();status.textContent='درمانگر و روز و ساعت نوبت را انتخاب کنید.';status.scrollIntoView({block:'center'});}if(!['existing','new'].includes(identityState)){
   e.preventDefault();status.textContent='شناسایی بیمار کامل نیست. مشخصات را دوباره بررسی کنید.';status.scrollIntoView({block:'center'});
  }else if(identityState==='new'&&(!field('fname').value.trim()||!field('lname').value.trim())){
   e.preventDefault();status.textContent='برای بیمار جدید، نام و نام خانوادگی را وارد کنید.';field('fname').focus();
  }});
 document.querySelector('[data-open-quick-patient]')?.addEventListener('click',()=>{const quick=document.querySelector('#quick-patient');quick.open=true;quick.querySelector('input[name=fname]')?.focus();});
 for(const name of ['fname','lname'])field(name).addEventListener('input',updateSummary);
 monthButtons();updateSummary();
 if(identityState==='existing'&&!nationalValue()&&!mobileValue())load();
 else identify();
})();
