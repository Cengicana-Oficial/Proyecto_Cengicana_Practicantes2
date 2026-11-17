const users={
  admin:{email:'admin@salaflow.demo',password:'admin123',name:'Ana Méndez',role:'admin',label:'Administradora',initials:'AM'},
  user:{email:'usuario@salaflow.demo',password:'usuario123',name:'Carlos Ruiz',role:'user',label:'Usuario',initials:'CR'}
};

const rooms=[
  {id:1,name:'Salón 1',floor:'Nivel 1',u:14,school:24},
  {id:2,name:'Salón 2',floor:'Nivel 1',u:16,school:24},
  {id:3,name:'Salón 3',floor:'Nivel 1',u:12,school:20},
  {id:4,name:'Salón 4',floor:'Nivel 2',u:14,school:22},
  {id:5,name:'Salón 5',floor:'Nivel 2',u:10,school:18},
  {id:6,name:'Salón 6',floor:'Nivel 2',u:16,school:28},
  {id:7,name:'Salón 7',floor:'Nivel 3',u:18,school:32},
  {id:8,name:'Salón 8',floor:'Nivel 3',u:20,school:36}
];

let meetings=[
  {id:1,title:'Revisión comercial',description:'Resultados y oportunidades del mes',date:'2026-08-14',start:'08:00',end:'09:00',roomIds:[1],configuration:'Tipo U',attendees:9,status:'approved',owner:'Carlos Ruiz',area:'Ventas'},
  {id:2,title:'Planeación trimestral',description:'Definición de prioridades estratégicas',date:'2026-08-14',start:'09:00',end:'11:00',roomIds:[1,2],configuration:'Tipo Escuela',attendees:38,status:'approved',owner:'Ana Méndez',area:'Dirección'},
  {id:3,title:'Entrevista UX',description:'Sesión de investigación con usuarios',date:'2026-08-14',start:'10:00',end:'11:00',roomIds:[3],configuration:'Tipo U',attendees:6,status:'approved',owner:'Ana Méndez',area:'Producto'},
  {id:4,title:'Comité financiero',description:'Revisión del cierre mensual',date:'2026-08-14',start:'11:00',end:'12:30',roomIds:[4],configuration:'Tipo U',attendees:12,status:'pending',owner:'Lucía García',area:'Finanzas'},
  {id:5,title:'Capacitación de seguridad',description:'Inducción para personal nuevo',date:'2026-08-14',start:'13:00',end:'15:00',roomIds:[7],configuration:'Tipo Escuela',attendees:26,status:'approved',owner:'María López',area:'Talento'},
  {id:6,title:'Sincronización TI',description:'Seguimiento de incidencias',date:'2026-08-14',start:'14:00',end:'15:00',roomIds:[5],configuration:'Tipo U',attendees:8,status:'approved',owner:'Carlos Ruiz',area:'Tecnología'},
  {id:7,title:'Presentación de campaña',description:'Campaña de septiembre',date:'2026-08-14',start:'15:00',end:'16:00',roomIds:[6],configuration:'Tipo Escuela',attendees:21,status:'pending',owner:'Sofía Castillo',area:'Mercadeo'},
  {id:8,title:'Mesa de proveedores',description:'Evaluación de propuestas',date:'2026-08-14',start:'16:00',end:'17:00',roomIds:[8],configuration:'Tipo U',attendees:15,status:'approved',owner:'Roberto Díaz',area:'Compras'},
  {id:9,title:'Revisión de presupuesto',description:'Ajustes del siguiente período',date:'2026-08-15',start:'09:00',end:'10:00',roomIds:[2],configuration:'Tipo U',attendees:8,status:'pending',owner:'Roberto Díaz',area:'Finanzas'},
  {id:10,title:'Demo a cliente',description:'Presentación de la nueva plataforma',date:'2026-08-18',start:'10:00',end:'11:00',roomIds:[3],configuration:'Tipo Escuela',attendees:15,status:'approved',owner:'Carlos Ruiz',area:'Ventas'},
  {id:11,title:'Retrospectiva mensual',description:'Lecciones aprendidas del equipo',date:'2026-08-20',start:'15:00',end:'16:00',roomIds:[5],configuration:'Tipo U',attendees:7,status:'rejected',owner:'Carlos Ruiz',area:'Tecnología'}
];

let currentUser=null,currentDate=new Date('2026-08-14T12:00:00'),mineFilter='all';
const $=(q,c=document)=>c.querySelector(q),$$=(q,c=document)=>[...c.querySelectorAll(q)];
const safe=value=>String(value).replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
const key=date=>`${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}`;
const format=(date,options)=>new Intl.DateTimeFormat('es-GT',options).format(typeof date==='string'?new Date(`${date}T12:00:00`):date);
const statusName=status=>({approved:'Aprobada',pending:'Pendiente',rejected:'Rechazada',cancelled:'Cancelada'})[status];
const roomById=id=>rooms.find(room=>room.id===Number(id));

function spaceName(meeting){return meeting.roomIds.length===2?'Salón 1 + Salón 2':roomById(meeting.roomIds[0]).name}
function capacityFor(roomIds,configuration){
  const field=configuration==='Tipo Escuela'?'school':'u';
  return roomIds.reduce((sum,id)=>sum+roomById(id)[field],0);
}
function overlaps(a,b){return a.start<b.end&&a.end>b.start}
function conflicts(candidate){return meetings.some(item=>item.id!==candidate.id&&item.date===candidate.date&&item.status==='approved'&&item.roomIds.some(id=>candidate.roomIds.includes(id))&&overlaps(candidate,item))}
function initials(name){return name.split(' ').map(p=>p[0]).slice(0,2).join('')}

function toast(message,error=false){
  const item=document.createElement('div');item.className='toast';item.style.borderLeftColor=error?'var(--red)':'var(--green)';item.textContent=`${error?'!':'✓'}  ${message}`;$('#toasts').append(item);setTimeout(()=>item.remove(),3200);
}
function openModal(html){$('#modalBody').innerHTML=html;$('#modal').classList.remove('hidden')}
function closeModal(){$('#modal').classList.add('hidden')}

function login(user){
  currentUser=user;$('#login').classList.add('hidden');$('#app').classList.remove('hidden');
  $$('.user-name').forEach(el=>el.textContent=user.name);$$('.user-role').forEach(el=>el.textContent=user.label);$$('.user-initials').forEach(el=>el.textContent=user.initials);$('#firstName').textContent=user.name.split(' ')[0];
  $$('.admin-only').forEach(el=>el.classList.toggle('hidden',user.role!=='admin'));
  populateRooms();renderAll();showView('agenda');
}
function logout(){currentUser=null;$('#app').classList.add('hidden');$('#login').classList.remove('hidden');$('#password').value=''}
function showView(name){
  $$('.view').forEach(v=>v.classList.remove('active'));$(`#${name}View`).classList.add('active');
  $$('aside nav button[data-view]').forEach(b=>b.classList.toggle('active',b.dataset.view===name));$('#sidebar').classList.remove('open');
  if(name==='agenda')renderAgenda();if(name==='mine')renderMine();if(name==='approvals')renderApprovals();if(name==='rooms')renderRooms();
}

function populateRooms(){
  const options=rooms.map(room=>`<option value="${room.id}">${room.name}</option>`).join('');
  $('#meetingRoom').innerHTML=options;$('#roomFilter').innerHTML='<option value="all">Todos los salones</option>'+options;updateRequestOptions();
}
function updateRequestOptions(){
  const roomId=Number($('#meetingRoom').value),configuration=$('#configuration').value,combined=$('#combineRooms').checked&&(roomId===1||roomId===2);
  $('#combineOption').classList.toggle('hidden',roomId!==1&&roomId!==2);
  if(roomId!==1&&roomId!==2)$('#combineRooms').checked=false;
  const ids=combined?[1,2]:[roomId],capacity=capacityFor(ids,configuration);
  $('#roomHelp').textContent=combined?`Se bloquearán ambos salones · Capacidad conjunta: ${capacity} personas`:`${roomById(roomId).floor} · Capacidad: ${capacity} personas`;
  $('#configHelp').textContent=configuration==='Tipo U'?'Mesas formando una U para facilitar conversación.':'Filas de mesas orientadas al frente.';
}

function renderAgenda(){
  const date=key(currentDate),filter=$('#roomFilter').value,visible=filter==='all'?rooms:rooms.filter(r=>r.id===Number(filter)),grid=$('#agendaGrid');
  $('#currentDateText').textContent=format(currentDate,{weekday:'long',day:'numeric',month:'long'}).replace(/^./,c=>c.toUpperCase());$('#currentYear').textContent=currentDate.getFullYear();grid.style.setProperty('--columns',visible.length);
  let html='<div class="corner">HORA</div>'+visible.map(room=>`<div class="room-head"><b>${room.name}</b><small>${room.floor} · U ${room.u} / Escuela ${room.school}</small></div>`).join('');
  for(let hour=7;hour<=18;hour++){
    html+=`<div class="time">${String(hour).padStart(2,'0')}:00</div>`;
    visible.forEach(room=>{
      const items=meetings.filter(m=>m.date===date&&m.roomIds.includes(room.id)&&Number(m.start.slice(0,2))===hour&&!['rejected','cancelled'].includes(m.status)&&(m.status==='approved'||currentUser.role==='admin'||m.owner===currentUser.name));
      html+='<div class="slot">'+items.map(m=>`<button class="meeting ${m.status}" data-detail="${m.id}"><b>${safe(m.title)}</b><small>${m.start}–${m.end}${m.roomIds.length===2?' · Combinado':''}</small></button>`).join('')+'</div>';
    });
  }
  grid.innerHTML=html;$$('[data-detail]',grid).forEach(b=>b.onclick=()=>meetingDetail(Number(b.dataset.detail)));
  const approved=meetings.filter(m=>m.date===date&&m.status==='approved'),occupiedNow=new Set(approved.filter(m=>m.start<='12:00'&&m.end>'12:00').flatMap(m=>m.roomIds));
  $('#availableStat').innerHTML=`${rooms.length-occupiedNow.size} <em>de ${rooms.length}</em>`;$('#meetingsStat').textContent=approved.length;
  const reservedHours=approved.reduce((sum,m)=>sum+(Number(m.end.slice(0,2))+Number(m.end.slice(3))/60-Number(m.start.slice(0,2))-Number(m.start.slice(3))/60)*m.roomIds.length,0);$('#occupancyStat').textContent=`${Math.round(reservedHours/(12*rooms.length)*100)}%`;
}

function meetingDetail(id){
  const m=meetings.find(x=>x.id===id);openModal(`<span class="badge ${m.status}">${statusName(m.status)}</span><h2 style="margin-top:14px">${safe(m.title)}</h2><p>${safe(m.description)}</p><div class="details"><div><span>FECHA</span><b>${format(m.date,{day:'numeric',month:'short'})}</b></div><div><span>HORARIO</span><b>${m.start}–${m.end}</b></div><div><span>ESPACIO</span><b>${spaceName(m)}</b></div><div><span>CONFIGURACIÓN</span><b>${m.configuration}</b></div><div><span>PERSONAS</span><b>${m.attendees}</b></div><div><span>ORGANIZA</span><b>${safe(m.owner)}</b></div></div>`)
}

function ownMeetings(){return meetings.filter(m=>m.owner===currentUser.name)}
function renderMine(){
  let items=ownMeetings();if(mineFilter!=='all')items=items.filter(m=>m.status===mineFilter);items.sort((a,b)=>(a.date+a.start).localeCompare(b.date+b.start));$('#mineCount').textContent=ownMeetings().filter(m=>m.status!=='cancelled').length;
  $('#mineRows').innerHTML=items.length?items.map(m=>`<tr><td><b>${safe(m.title)}</b><small>${safe(m.description)}</small></td><td><b>${format(m.date,{day:'numeric',month:'short',year:'numeric'})}</b><small>${m.start}–${m.end}</small></td><td>${spaceName(m)}</td><td>${m.configuration}</td><td><span class="badge ${m.status}">${statusName(m.status)}</span></td><td><button class="mini" data-detail="${m.id}">Ver</button> ${['pending','approved'].includes(m.status)?`<button class="mini danger" data-cancel="${m.id}">Cancelar</button>`:''}</td></tr>`).join(''):'<tr><td colspan="6" class="empty">No hay reuniones en esta categoría.</td></tr>';
  $$('[data-detail]',$('#mineRows')).forEach(b=>b.onclick=()=>meetingDetail(Number(b.dataset.detail)));$$('[data-cancel]').forEach(b=>b.onclick=()=>cancelMeeting(Number(b.dataset.cancel)));
}
function cancelMeeting(id){const m=meetings.find(x=>x.id===id);if(!m||m.owner!==currentUser.name)return;m.status='cancelled';renderAll();toast('La reunión fue cancelada.')}

function renderApprovals(){
  const pending=meetings.filter(m=>m.status==='pending');$('#pendingCount').textContent=pending.length;$('#pendingStat').textContent=pending.length;
  $('#approvalCards').innerHTML=pending.length?pending.map(m=>`<article class="panel approval-card"><div class="approval-top"><div><h3>${safe(m.title)}</h3><p>${safe(m.description)}</p></div><span class="badge pending">Pendiente</span></div><div class="person"><span class="avatar">${initials(m.owner)}</span><div><b>${safe(m.owner)}</b><small>${safe(m.area)}</small></div></div><div class="details"><div><span>FECHA</span><b>${format(m.date,{day:'numeric',month:'short'})}</b></div><div><span>HORARIO</span><b>${m.start}–${m.end}</b></div><div><span>ESPACIO</span><b>${spaceName(m)}</b></div><div><span>MONTAJE</span><b>${m.configuration}</b></div><div><span>PERSONAS</span><b>${m.attendees}</b></div></div><div class="card-actions"><button class="button secondary" data-reject="${m.id}">Rechazar</button><button class="button secondary" data-move="${m.id}">Mover</button><button class="button primary" data-approve="${m.id}">Aprobar</button></div></article>`).join(''):'<article class="panel approval-card empty">No hay solicitudes pendientes.</article>';
  $$('[data-approve]').forEach(b=>b.onclick=()=>approve(Number(b.dataset.approve)));$$('[data-reject]').forEach(b=>b.onclick=()=>reject(Number(b.dataset.reject)));$$('[data-move]').forEach(b=>b.onclick=()=>move(Number(b.dataset.move)));
}
function approve(id){const m=meetings.find(x=>x.id===id);if(conflicts(m))return toast('No se puede aprobar: uno de los salones ya está ocupado.',true);m.status='approved';renderAll();toast('Solicitud aprobada correctamente.')}
function reject(id){
  const m=meetings.find(x=>x.id===id);openModal(`<h2>Rechazar solicitud</h2><p>Indica el motivo del rechazo.</p><form id="rejectForm"><div class="form-grid"><label class="full">Motivo<textarea id="reason" rows="4" required></textarea></label></div><div class="form-actions"><button type="button" class="button secondary" id="back">Volver</button><button class="button primary">Confirmar rechazo</button></div></form>`);$('#back').onclick=closeModal;$('#rejectForm').onsubmit=e=>{e.preventDefault();m.status='rejected';m.reason=$('#reason').value;closeModal();renderAll();toast('Solicitud rechazada.')}
}
function move(id){
  const m=meetings.find(x=>x.id===id),current=m.roomIds.length===2?'combined':String(m.roomIds[0]);
  openModal(`<h2>Cambiar espacio</h2><p>El combinado bloquea simultáneamente Salón 1 y Salón 2.</p><form id="moveForm"><div class="form-grid"><label class="full">Nuevo espacio<select id="newRoom"><option value="combined" ${current==='combined'?'selected':''}>Salón 1 + Salón 2</option>${rooms.map(r=>`<option value="${r.id}" ${current===String(r.id)?'selected':''}>${r.name}</option>`).join('')}</select></label></div><div class="form-actions"><button type="button" class="button secondary" id="back">Cancelar</button><button class="button primary">Guardar cambio</button></div></form>`);$('#back').onclick=closeModal;$('#moveForm').onsubmit=e=>{e.preventDefault();const old=[...m.roomIds],value=$('#newRoom').value;m.roomIds=value==='combined'?[1,2]:[Number(value)];if(m.attendees>capacityFor(m.roomIds,m.configuration)){m.roomIds=old;return toast('El nuevo espacio no tiene capacidad suficiente.',true)}if(conflicts(m)){m.roomIds=old;return toast('El nuevo espacio está ocupado en ese horario.',true)}closeModal();renderAll();toast('Espacio actualizado.')}
}

function renderRooms(){
  $('#roomCards').innerHTML=rooms.map(r=>`<article class="panel room-card"><div class="room-visual"><b>${r.name}</b></div><div class="room-info"><div><span>⌖ ${r.floor}</span><span>● Activo</span></div><div class="capacity"><span>TIPO U<b>${r.u} personas</b></span><span>TIPO ESCUELA<b>${r.school} personas</b></span></div></div></article>`).join('')+`<article class="panel room-card"><div class="room-visual"><b>Salón 1 + Salón 2</b></div><div class="room-info"><div><span>⌖ Nivel 1</span><span>● Combinable</span></div><div class="capacity"><span>TIPO U<b>${capacityFor([1,2],'Tipo U')} personas</b></span><span>TIPO ESCUELA<b>${capacityFor([1,2],'Tipo Escuela')} personas</b></span></div></div></article>`;
}
function renderAll(){renderAgenda();renderMine();if(currentUser.role==='admin')renderApprovals();renderRooms()}

$('#loginForm').onsubmit=e=>{e.preventDefault();const user=Object.values(users).find(u=>u.email===$('#email').value.trim().toLowerCase()&&u.password===$('#password').value);if(!user)return $('#loginError').textContent='Correo o contraseña incorrectos.';$('#loginError').textContent='';login(user)};
$$('[data-user]').forEach(b=>b.onclick=()=>{const u=users[b.dataset.user];$('#email').value=u.email;$('#password').value=u.password;$('#loginError').textContent=''});
$('#logout').onclick=logout;$('#menu').onclick=()=>$('#sidebar').classList.toggle('open');$$('aside nav button[data-view]').forEach(b=>b.onclick=()=>showView(b.dataset.view));$$('[data-go]').forEach(b=>b.onclick=()=>showView(b.dataset.go));
$('#closeModal').onclick=closeModal;$('#modal').onclick=e=>{if(e.target===$('#modal'))closeModal()};document.addEventListener('keydown',e=>{if(e.key==='Escape')closeModal()});
$('#prevDate').onclick=()=>{currentDate.setDate(currentDate.getDate()-1);renderAgenda()};$('#nextDate').onclick=()=>{currentDate.setDate(currentDate.getDate()+1);renderAgenda()};$('#today').onclick=()=>{currentDate=new Date('2026-08-14T12:00:00');renderAgenda()};$('#roomFilter').onchange=renderAgenda;
$('#meetingRoom').onchange=updateRequestOptions;$('#configuration').onchange=updateRequestOptions;$('#combineRooms').onchange=updateRequestOptions;
$('.tabs').onclick=e=>{const b=e.target.closest('button');if(!b)return;mineFilter=b.dataset.status;$$('.tabs button').forEach(x=>x.classList.toggle('active',x===b));renderMine()};
$('#requestForm').onreset=()=>setTimeout(()=>{$('#meetingDate').value=key(currentDate);updateRequestOptions()},0);
$('#requestForm').onsubmit=e=>{
  e.preventDefault();const start=$('#start').value,end=$('#end').value;if(start>=end)return toast('La hora final debe ser posterior a la hora de inicio.',true);
  const selected=Number($('#meetingRoom').value),combined=$('#combineRooms').checked&&(selected===1||selected===2),roomIds=combined?[1,2]:[selected],configuration=$('#configuration').value,attendees=Number($('#attendees').value),capacity=capacityFor(roomIds,configuration);
  if(attendees>capacity)return toast(`La capacidad máxima para ${configuration} es de ${capacity} personas.`,true);
  const meeting={id:Math.max(...meetings.map(m=>m.id))+1,title:$('#title').value.trim(),description:$('#description').value.trim()||'Sin descripción',date:$('#meetingDate').value,start,end,roomIds,configuration,attendees,status:'pending',owner:currentUser.name,area:'Mi departamento'};
  if(conflicts(meeting))return toast(combined?'Salón 1 o Salón 2 ya está ocupado en ese horario.':'El salón ya está ocupado en ese horario.',true);
  meetings.push(meeting);e.target.reset();$('#meetingDate').value=key(currentDate);updateRequestOptions();renderAll();showView('mine');toast(combined?'Solicitud creada; se reservarán ambos salones.':'Solicitud enviada para aprobación.');
};
$('#meetingDate').value=key(currentDate);
