const settings=window.SALAFLOW_SUPABASE;
const db=window.supabase?.createClient(settings?.url,settings?.publishableKey,{auth:{persistSession:false,autoRefreshToken:false,detectSessionInUrl:false}});
const rooms=[
  {id:1,name:'Salón 1',floor:'Nivel 1'},{id:2,name:'Salón 2',floor:'Nivel 1'},
  {id:3,name:'Salón 3',floor:'Nivel 1'},{id:4,name:'Salón 4',floor:'Nivel 2'},
  {id:5,name:'Salón 5',floor:'Nivel 2'},{id:6,name:'Salón 6',floor:'Nivel 2'},
  {id:7,name:'Salón 7',floor:'Nivel 3'},{id:8,name:'Salón 8',floor:'Nivel 3'}
];
const $=selector=>document.querySelector(selector);
const safe=value=>String(value??'').replace(/[&<>'"]/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
const dateKey=date=>guatemalaDateTime(date).date;
let meetings=[],maintenance=[],lastSnapshot='',announcementTimer,realtimeChannel;

function guatemalaDateTime(value){
  const date=new Date(value),parts=Object.fromEntries(new Intl.DateTimeFormat('en-CA',{timeZone:'America/Guatemala',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).formatToParts(date).filter(part=>part.type!=='literal').map(part=>[part.type,part.value]));
  return{date:`${parts.year}-${parts.month}-${parts.day}`,time:`${parts.hour}:${parts.minute}`};
}
function isCurrent(item,now){return new Date(item.startsAt)<=now&&new Date(item.endsAt)>now}
function showAnnouncement(){$('#announcement').textContent='✓ Tablero actualizado';$('#announcement').classList.add('show');clearTimeout(announcementTimer);announcementTimer=setTimeout(()=>$('#announcement').classList.remove('show'),2200)}
function setConnection(connected,message=connected?'En tiempo real':'Sin conexión al servicio'){$('#liveIndicator').classList.toggle('offline',!connected);$('#liveIndicator').innerHTML=`<i></i> ${message}`}

async function loadBoard(announce=false){
  if(!db){setConnection(false,'Configuración incompleta');return}
  const today=dateKey(new Date()),{data,error}=await db.rpc('get_public_board',{p_date:today});
  if(error){setConnection(false);console.error('No se pudo cargar el tablero:',error);return}
  const rows=data||[],roomRows=rows.filter(row=>row.item_type==='room'),knownRooms=roomRows.length?new Map(roomRows.map(row=>[Number(row.room_id),{id:Number(row.room_id),name:row.room_name,floor:row.details||''}])):new Map(rooms.map(room=>[room.id,room]));
  rows.filter(row=>row.item_type!=='room').forEach(row=>{const room=knownRooms.get(Number(row.room_id));if(room)room.name=row.room_name||room.name;else knownRooms.set(Number(row.room_id),{id:Number(row.room_id),name:row.room_name,floor:''})});
  rooms.splice(0,rooms.length,...[...knownRooms.values()].sort((a,b)=>a.name.localeCompare(b.name,'es',{numeric:true})));
  meetings=rows.filter(row=>row.item_type==='meeting').map(row=>{const start=guatemalaDateTime(row.starts_at),end=guatemalaDateTime(row.ends_at);return{id:String(row.item_id),roomId:Number(row.room_id),title:row.title,startsAt:row.starts_at,endsAt:row.ends_at,start:start.time,end:end.time,configuration:row.configuration_name||'',combined:String(row.space_name).includes('+')}}).sort((a,b)=>a.startsAt.localeCompare(b.startsAt));
  maintenance=rows.filter(row=>row.item_type==='maintenance').map(row=>{const start=guatemalaDateTime(row.starts_at),end=guatemalaDateTime(row.ends_at);return{id:String(row.item_id),roomId:Number(row.room_id),startsAt:row.starts_at,endsAt:row.ends_at,start:start.time,end:end.time,allDay:start.time==='00:00'&&end.time>='23:59',reason:row.details||'Mantenimiento'}}).sort((a,b)=>a.startsAt.localeCompare(b.startsAt));
  setConnection(true);renderBoard(announce);
}
function renderBoard(announce=false){
  const now=new Date(),snapshot=JSON.stringify({meetings,maintenance});if(announce&&lastSnapshot&&snapshot!==lastSnapshot)showAnnouncement();lastSnapshot=snapshot;
  $('#roomBoard').innerHTML=rooms.map(room=>roomMarkup(room,meetings.filter(item=>item.roomId===room.id),maintenance.filter(item=>item.roomId===room.id),now)).join('');
}
function roomMarkup(room,roomMeetings,roomMaintenance,now){
  const currentMaintenance=roomMaintenance.find(item=>isCurrent(item,now)),current=roomMeetings.find(item=>isCurrent(item,now)),events=[...roomMeetings.map(item=>({...item,eventType:'meeting'})),...roomMaintenance.map(item=>({...item,eventType:'maintenance'}))].filter(item=>new Date(item.endsAt)>now).sort((a,b)=>a.startsAt.localeCompare(b.startsAt)),upcoming=events.filter(item=>new Date(item.startsAt)>now),next=upcoming[0];
  const status=currentMaintenance?`<div class="room-state maintenance"><b>Mantenimiento</b><small>${currentMaintenance.allDay?'todo el día':`hasta ${currentMaintenance.end}`}</small></div>`:current?`<div class="room-state busy"><b>Ocupado</b><small>hasta ${current.end}</small></div>`:`<div class="room-state free"><b>Disponible</b><small>${next?`hasta ${next.start}`:'todo el día'}</small></div>`;
  const currentBlock=currentMaintenance?`<div class="maintenance-notice"><small>⚒ MANTENIMIENTO</small><b>${safe(currentMaintenance.reason)}</b><span>${currentMaintenance.allDay?'Todo el día':`${currentMaintenance.start}–${currentMaintenance.end}`}</span></div>`:current?`<div class="now-meeting"><small>EN CURSO</small><b>${safe(current.title)}${current.combined?'<span class="combined">COMBINADO</span>':''}</b><span>${current.start}–${current.end} · ${safe(current.configuration)}</span></div>`:'';
  const list=events.length?`<div class="schedule-label"><span>AGENDA DEL DÍA</span><span>${events.length} ${events.length===1?'EVENTO':'EVENTOS'}</span></div><div class="schedule-list">${events.map(item=>item.eventType==='maintenance'?`<div class="schedule-item maintenance-item ${next?.id===item.id?'next':''}"><time>${item.allDay?'TODO<br>EL DÍA':`${item.start}<br>${item.end}`}</time><div><b>⚒ Mantenimiento</b><small>${safe(item.reason)}</small></div></div>`:`<div class="schedule-item ${next?.id===item.id?'next':''}"><time>${item.start}<br>${item.end}</time><div><b>${safe(item.title)}${item.combined?'<span class="combined">COMBINADO</span>':''}</b><small>${safe(item.configuration)}</small></div></div>`).join('')}</div>`:$('#emptySchedule').innerHTML;
  return`<article class="room-tile ${currentMaintenance?'under-maintenance':''}"><div class="room-top"><div class="room-title"><h2>${safe(room.name)}</h2><small>${safe(room.floor)}</small></div>${status}</div><div class="room-content">${currentBlock}${list}</div></article>`;
}
function updateClock(){const now=new Date();$('#boardDate').textContent=now.toLocaleDateString('es-GT',{weekday:'long',day:'numeric',month:'long',year:'numeric'});$('#boardClock').textContent=now.toLocaleTimeString('es-GT',{hour:'2-digit',minute:'2-digit',second:'2-digit'});renderBoard(false)}
function subscribeRealtime(){if(!db)return;realtimeChannel=db.channel('salaflow-public-board').on('postgres_changes',{event:'INSERT',schema:'public',table:'board_events'},()=>void loadBoard(true)).subscribe(status=>{if(status==='SUBSCRIBED')setConnection(true);if(['CHANNEL_ERROR','TIMED_OUT','CLOSED'].includes(status))setConnection(false)})}
$('#fullscreen').onclick=()=>document.fullscreenElement?document.exitFullscreen():document.documentElement.requestFullscreen();
updateClock();void loadBoard();subscribeRealtime();setInterval(updateClock,1000);setInterval(()=>void loadBoard(false),60000);
