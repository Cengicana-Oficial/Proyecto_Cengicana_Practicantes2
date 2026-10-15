let rooms=[],meetings=[],maintenances=[];
let currentUser=null,currentDate=new Date(),agendaMode='agenda',mineFilter='all',adminRequestStatus='pending',adminRequestDate='',paidRequestStatus='pending',paidRequestDate='',customDates=[],reportMonth='',reportOrganization='all',reportSource='all',inactivityTimer=null,requestAssignments=new Map(),requestAvailabilityByDate=new Map(),requestAvailabilityRequest=0,requestAvailabilityState='idle',incidents=[];
const supabaseSettings=window.SALAFLOW_SUPABASE;
const db=window.supabase?.createClient(supabaseSettings?.url,supabaseSettings?.publishableKey,{auth:{persistSession:false,autoRefreshToken:true,detectSessionInUrl:false}});
let spaces=[],meetingTypes=[],configurationTypes=[],refreshmentCatalog=[],organizations=[],registrationOrganizations=[],departments=[],adminUsers=[],paidOfferings=[],refreshmentsByMeeting=new Map(),applicationChannel=null,refreshTimer=null,maintenanceClockTimer=null,userAdminFilter='pending',permissionMatrixRows=[],portalUsers=[],portalUsersLoadedAt=0,portalUsersLoading=null;
const $=(q,c=document)=>c.querySelector(q),$$=(q,c=document)=>[...c.querySelectorAll(q)];
const safe=value=>String(value).replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
const key=date=>`${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}`;
const format=(date,options)=>new Intl.DateTimeFormat('es-GT',options).format(typeof date==='string'?new Date(`${date}T12:00:00`):date);
const money=value=>new Intl.NumberFormat('es-GT',{style:'currency',currency:'GTQ',minimumFractionDigits:2}).format(Number(value||0));
const approvalName=status=>({approved:'Aprobada',pending:'Pendiente',rejected:'Rechazada',cancelled:'Cancelada'})[status]||status;
const statusName=approvalName;
const roomById=id=>rooms.find(room=>room.id===Number(id));
const configurationFields={'Tipo U':'u','Tipo Escuela':'school','Tipo Juntas':'boardroom'};
const allowedConfigurationNames=room=>Object.entries(configurationFields).filter(([,field])=>Number(room?.[field])>0).map(([name])=>name);
const roomCapacityText=(room,compact=false)=>allowedConfigurationNames(room).map(name=>`${compact?({'Tipo U':'U','Tipo Escuela':'E','Tipo Juntas':'J'})[name]:name} ${({'Tipo U':room.u,'Tipo Escuela':room.school,'Tipo Juntas':room.boardroom})[name]||0}`).join(compact?' · ':' / ');
const selectedConfiguration=()=>$('input[name="configuration"]:checked')?.value||'Tipo U';
const selectedRefreshments=()=>$$('input[name="refreshment"]:checked').map(input=>Number(input.value)).filter(Boolean);
const refreshmentText=meeting=>meeting.refreshments?.length?meeting.refreshments.join(', '):'No solicitada';
const timestampNow=()=>new Date().toISOString();

function auditTimestamp(meeting){return({pending:meeting.createdAt,approved:meeting.approvedAt,rejected:meeting.rejectedAt,cancelled:meeting.cancelledAt})[meeting.status]||null}
function formatAuditTimestamp(timestamp){
  if(!timestamp)return'<b>Registro anterior</b><small>Sin fecha disponible</small>';const date=new Date(timestamp);if(Number.isNaN(date.getTime()))return'<b>Registro anterior</b><small>Fecha no disponible</small>';
  return `<b>${new Intl.DateTimeFormat('es-GT',{day:'numeric',month:'short',year:'numeric'}).format(date)}</b><small>${new Intl.DateTimeFormat('es-GT',{hour:'2-digit',minute:'2-digit'}).format(date)}</small>`;
}
function cancellationActor(meeting){
  const role=meeting.cancelledByRole||'user',name=meeting.cancelledBy||meeting.owner;return{role,label:role==='admin'?'Administrador':'Usuario',name};
}

function spaceName(meeting){return spaceById(meeting.spaceId)?.name||roomById(meeting.roomIds[0])?.name||'Espacio'}
function capacityFor(roomIds,configuration){
  const field=configuration==='Tipo Escuela'?'school':configuration==='Tipo Juntas'?'boardroom':'u';
  return roomIds.reduce((sum,id)=>sum+roomById(id)[field],0);
}
function overlaps(a,b){return a.start<b.end&&a.end>b.start}
function conflicts(candidate){
  const meetingConflict=meetings.some(item=>item.id!==candidate.id&&item.date===candidate.date&&item.status==='approved'&&item.roomIds.some(id=>candidate.roomIds.includes(id))&&overlaps(candidate,item));
  const maintenanceConflict=maintenances.some(item=>item.status==='scheduled'&&item.date===candidate.date&&candidate.roomIds.includes(item.roomId)&&overlaps(candidate,item));
  return meetingConflict||maintenanceConflict;
}
function initials(name){return name.split(' ').map(p=>p[0]).slice(0,2).join('')}
function seriesMembers(meeting,status=null){return meeting.seriesId?meetings.filter(item=>item.seriesId===meeting.seriesId&&(!status||item.status===status)):[meeting]}
function seriesSummary(meeting){const members=seriesMembers(meeting);return meeting.seriesId?`${meeting.recurrenceLabel||'Recurrente'} · ${members.length} fechas`:''}
function addDays(dateString,days){const date=new Date(`${dateString}T12:00:00`);date.setDate(date.getDate()+days);return key(date)}
function addMonth(dateString,months){
  const original=new Date(`${dateString}T12:00:00`),day=original.getDate(),target=new Date(original.getFullYear(),original.getMonth()+months,1,12);target.setDate(Math.min(day,new Date(target.getFullYear(),target.getMonth()+1,0).getDate()));return key(target);
}
function recurrenceDates(){
  const startDate=$('#meetingDate').value,type=$('#recurrence').value;if(!startDate)return[];if(type==='none')return[startDate];
  if(type==='custom')return[...new Set([startDate,...customDates])].sort();
  const until=$('#repeatUntil').value;if(!until||until<startDate)return[startDate];const dates=[];
  for(let index=0;index<52;index++){const date=type==='monthly'?addMonth(startDate,index):addDays(startDate,index*(type==='biweekly'?14:7));if(date>until)break;dates.push(date)}
  return dates;
}
function requestDefaultAssignment(){const roomId=Number($('#meetingRoom').value),combined=Boolean($('#combineRooms').checked),space=combined?combinedSpaceForRoom(roomId):singleSpaceForRoom(roomId),configuration=configurationByName(selectedConfiguration());return{spaceId:Number(space?.id||0),configurationId:Number(configuration?.id||0)}}
function requestConfigurationOptions(space){const attendees=Number($('#attendees').value);return configurationTypes.filter(item=>item.is_active&&Number(space?.capacities?.get(Number(item.id))||0)>=attendees&&attendees>0)}
function requestEligibleSpaces(date){const available=requestAvailabilityByDate.get(date)||[];return available.filter(space=>space.isActive&&space.roomIds.every(id=>roomById(id)?.isActive)&&requestConfigurationOptions(space).length>0)}
function requestSpaceEligible(space,date){return Boolean(space&&requestEligibleSpaces(date).some(item=>item.id===space.id))}
function renderRequestRoomOptions(){
  const select=$('#meetingRoom'),previous=Number(select.value),date=recurrenceDates()[0];
  const eligible=rooms.filter(room=>requestSpaceEligible(singleSpaceForRoom(room.id),date)||requestSpaceEligible(combinedSpaceForRoom(room.id),date));
  select.innerHTML=eligible.length?eligible.map(room=>`<option value="${room.id}">${safe(room.name)}</option>`).join(''):'<option value="">No hay salones disponibles para estos datos</option>';
  if(eligible.some(room=>room.id===previous))select.value=String(previous);
  select.disabled=!eligible.length;
  updateRequestOptions();
}
function updateRequestMainAvailability(){
  const date=recurrenceDates()[0],selection=requestDefaultAssignment(),available=requestSpaceEligible(spaceById(selection.spaceId),date);
  $('#meetingMainRoomAvailability').classList.toggle('hidden',!requestAvailabilityByDate.has(date)||available);
}
function renderRequestAssignments(){
  const dates=recurrenceDates(),additionalDates=dates.slice(1),section=$('#meetingDateAssignments'),list=$('#meetingAssignmentList');
  section.classList.toggle('hidden',!additionalDates.length);list.innerHTML='';if(!additionalDates.length){updateRequestMainAvailability();return}
  const fallback=requestDefaultAssignment(),next=new Map();
  additionalDates.forEach(date=>{
    const eligible=requestEligibleSpaces(date),previous=requestAssignments.get(date)||fallback;
    const space=eligible.find(item=>item.id===Number(previous.spaceId))||eligible[0]||null;
    const configurations=requestConfigurationOptions(space),configuration=configurations.find(item=>Number(item.id)===Number(previous.configurationId))||configurations[0]||null;
    next.set(date,{spaceId:Number(space?.id||0),configurationId:Number(configuration?.id||0)});
    const spaceOptions=eligible.map(item=>`<option value="${item.id}" ${item.id===Number(space?.id)?'selected':''}>${safe(item.name)}</option>`).join('');
    const configurationOptions=configurations.map(item=>`<option value="${item.id}" ${Number(item.id)===Number(configuration?.id)?'selected':''}>${safe(item.code==='SCHOOL'?'Tipo Escuela':item.name)}</option>`).join('');
    const checking=requestAvailabilityState==='loading',status=space?'':checking?'':'<strong class="room-unavailable-note">No Disponible</strong>';
    const warning=space?'':checking?'<small class="date-assignment-warning">Consultando salones disponibles…</small>':'<small class="date-assignment-warning">No hay salones disponibles para este horario y cantidad de participantes.</small>';
    list.insertAdjacentHTML('beforeend',`<article class="date-assignment-card ${space||checking?'':'unavailable'}" data-request-assignment="${date}"><div class="date-assignment-date"><b>${format(date,{weekday:'short',day:'numeric',month:'short',year:'numeric'})}</b><small>${$('#start').value}–${$('#end').value}</small></div><label><span>Salón ${status}</span><select data-request-space ${space?'':'disabled'}>${spaceOptions}</select></label><label>Distribución<select data-request-configuration ${space?'':'disabled'}>${configurationOptions}</select></label>${warning}</article>`);
  });
  requestAssignments=next;
  $$('[data-request-space]',list).forEach(select=>select.onchange=()=>{const date=select.closest('[data-request-assignment]').dataset.requestAssignment,assignment=requestAssignments.get(date),space=spaceById(Number(select.value)),configs=requestConfigurationOptions(space);assignment.spaceId=Number(select.value);assignment.configurationId=Number(configs[0]?.id||0);renderRequestAssignments()});
  $$('[data-request-configuration]',list).forEach(select=>select.onchange=()=>{const date=select.closest('[data-request-assignment]').dataset.requestAssignment;requestAssignments.get(date).configurationId=Number(select.value)});
  $('#meetingAssignmentCount').textContent=`${additionalDates.length} ${additionalDates.length===1?'fecha adicional':'fechas adicionales'}`;updateRequestMainAvailability();
}
function requestOccurrenceSelections(){const dates=recurrenceDates(),fallback=requestDefaultAssignment();return dates.map((date,index)=>{const assignment=index?(requestAssignments.get(date)||fallback):fallback;return{date,...assignment}})}
async function refreshRequestAvailability(){
  const request=++requestAvailabilityRequest,dates=recurrenceDates(),start=$('#start').value,end=$('#end').value;
  requestAvailabilityByDate.clear();requestAvailabilityState=!db||!dates.length||!start||!end||start>=end?'idle':'loading';
  renderRequestRoomOptions();renderRequestAssignments();
  if(requestAvailabilityState==='idle')return;
  try{
    const{data,error}=await db.rpc('get_public_space_availability_by_occurrence',{p_occurrences:dates.map(date=>({starts_at:toDatabaseTimestamp(date,start),ends_at:toDatabaseTimestamp(date,end)}))});
    if(error)throw error;if(request!==requestAvailabilityRequest)return;
    requestAvailabilityByDate=new Map((data||[]).map(item=>[String(item.date||item.startsAt).slice(0,10),(item.spaces||[]).map(candidate=>spaceById(candidate.id)).filter(Boolean)]));requestAvailabilityState='ready';
  }catch(error){if(request!==requestAvailabilityRequest)return;requestAvailabilityState='error'}
  renderRequestRoomOptions();renderRequestAssignments();
}
function updateRecurrence(){
  const type=$('#recurrence').value,isRecurring=type!=='none',isCustom=type==='custom',start=$('#meetingDate').value;
  $('#recurrencePanel').classList.toggle('hidden',!isRecurring);$('#repeatUntilLabel').classList.toggle('hidden',!isRecurring||isCustom);$('#customDatesPanel').classList.toggle('hidden',!isCustom);
  $('#repeatUntil').min=start;$('#customDate').min=start;
  if(isRecurring&&!isCustom&&!$('#repeatUntil').value&&start)$('#repeatUntil').value=addDays(start,type==='monthly'?90:type==='biweekly'?42:28);
  renderCustomDates();const dates=recurrenceDates();
  $('#recurrencePreview').textContent=!isRecurring?'':dates.length>1?`${dates.length} reuniones: ${format(dates[0],{day:'numeric',month:'short'})} a ${format(dates.at(-1),{day:'numeric',month:'short',year:'numeric'})}`:'Agrega al menos una fecha adicional para crear una serie.';renderRequestAssignments();void refreshRequestAvailability();
}
function renderCustomDates(){
  $('#customDateList').innerHTML=customDates.map(date=>`<span class="date-chip">${format(date,{day:'numeric',month:'short',year:'numeric'})}<button type="button" data-remove-date="${date}" aria-label="Quitar fecha">×</button></span>`).join('');
  $$('[data-remove-date]').forEach(button=>button.onclick=()=>{customDates=customDates.filter(date=>date!==button.dataset.removeDate);updateRecurrence()});
}
function requireSupabase(){
  if(!db)throw new Error('No fue posible conectar con el servicio. Verifica tu conexión a Internet.');
}
function databaseMessage(error){
  const message=error?.message||String(error||'Error desconocido');
  return message.replace(/^.*message[=:]\s*/i,'').replace(/\s*context:.*$/i,'');
}
function guatemalaDateTime(value){
  const date=new Date(value),parts=Object.fromEntries(new Intl.DateTimeFormat('en-CA',{timeZone:'America/Guatemala',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).formatToParts(date).filter(part=>part.type!=='literal').map(part=>[part.type,part.value]));
  return{date:`${parts.year}-${parts.month}-${parts.day}`,time:`${parts.hour}:${parts.minute}`};
}
const toDatabaseTimestamp=(date,time)=>`${date}T${time}:00-06:00`;
const recurrenceNames={none:'No se repite',weekly:'Semanalmente',biweekly:'Quincenalmente',monthly:'Mensualmente',custom:'Fechas específicas'};
function guatemalaNow(){return guatemalaDateTime(new Date().toISOString())}
function eventHasEnded(meeting){if(!meeting?.date||!meeting?.end)return false;const now=guatemalaNow();return`${meeting.date}T${meeting.end}`<=`${now.date}T${now.time}`}
function eventIsInProgress(meeting){if(!meeting?.date||!meeting?.start||!meeting?.end)return false;const now=guatemalaNow(),value=`${meeting.date}T${now.time}`;return value>=`${meeting.date}T${meeting.start}`&&value<`${meeting.date}T${meeting.end}`}
function operationalStatus(meeting){if(meeting.status==='cancelled')return{key:'cancelled',label:'Cancelado'};if(eventHasEnded(meeting))return{key:'finished',label:'Finalizada'};if(eventIsInProgress(meeting))return{key:'in-progress',label:'En Curso'};if(meeting.rescheduledAt)return{key:'rescheduled',label:'Reprogramado'};return{key:'scheduled',label:'Programada'}}
function canModifyMeeting(meeting){return Boolean(meeting&&!eventHasEnded(meeting))}
function operationalBadge(meeting){const state=operationalStatus(meeting);return`<span class="badge operational-${state.key}">${state.label}</span>`}
function spaceById(id){return spaces.find(space=>space.id===Number(id))}
function singleSpaceForRoom(roomId){return spaces.find(space=>space.type==='single'&&space.roomIds.length===1&&space.roomIds[0]===Number(roomId))}
function configurationByName(name){return configurationTypes.find(item=>item.name===name)}
function combinedSpaceForRoom(roomId){return spaces.find(space=>space.type==='combined'&&space.roomIds.includes(Number(roomId)))}

async function loadRegistrationOrganizations(){
  const{data,error}=await db.rpc('get_signup_organizations');if(error)throw error;registrationOrganizations=data||[];
  const select=$('#registerOrganization');if(select)select.innerHTML='<option value="">Selecciona tu organización</option>'+registrationOrganizations.map(item=>`<option value="${safe(item.code)}">${safe(item.name)}</option>`).join('');
}

async function loadCatalogs(){
  const [roomResult,spaceResult,typeResult,configurationResult,refreshmentResult,organizationResult,departmentResult,paidOfferingResult]=await Promise.all([
    db.from('rooms').select('id,code,name,location,is_active,hourly_consumption_kwh,allows_usage_cost,cost_one_hour,cost_four_to_eight_hours,cost_extra_hour_after_eight').order('name'),
    db.from('spaces').select('id,code,name,space_type,is_active,space_rooms(room_id),space_capacities(configuration_type_id,capacity)').order('name'),
    db.from('meeting_types').select('id,code,name,is_active').order('id'),
    db.from('configuration_types').select('id,code,name,is_active').order('id'),
    db.from('refreshments').select('id,code,name,is_active').order('id'),
    db.from('organizations').select('id,code,name,can_request_refreshments,is_active').order('name'),
    db.from('departments').select('id,organization_id,name,is_active').order('name'),
    db.from('paid_space_offerings').select('id,code,name,description,allocation_mode,capacities,price_one_hour,price_four_to_eight_hours,price_extra_hour_after_eight,is_active').order('id')
  ]);
  const error=[roomResult,spaceResult,typeResult,configurationResult,refreshmentResult,organizationResult,departmentResult,paidOfferingResult].find(result=>result.error)?.error;
  if(error)throw error;
  meetingTypes=typeResult.data||[];configurationTypes=configurationResult.data||[];refreshmentCatalog=refreshmentResult.data||[];organizations=organizationResult.data||[];departments=departmentResult.data||[];paidOfferings=paidOfferingResult.data||[];
  spaces=(spaceResult.data||[]).map(item=>({id:Number(item.id),code:item.code,name:item.name,type:item.space_type,isActive:Boolean(item.is_active),roomIds:(item.space_rooms||[]).map(link=>Number(link.room_id)),capacities:new Map((item.space_capacities||[]).map(capacity=>[Number(capacity.configuration_type_id),Number(capacity.capacity)]))}));
  rooms=(roomResult.data||[]).map(item=>{
    const space=spaces.find(candidate=>candidate.type==='single'&&candidate.roomIds.length===1&&candidate.roomIds[0]===Number(item.id)),u=configurationTypes.find(type=>type.code==='U'),school=configurationTypes.find(type=>type.code==='SCHOOL'),boardroom=configurationTypes.find(type=>type.code==='BOARDROOM');
    return{id:Number(item.id),code:item.code,name:item.name,floor:item.location||'Sin ubicación',isActive:Boolean(item.is_active),hourlyConsumptionKwh:Number(item.hourly_consumption_kwh||0),allowsUsageCost:Boolean(item.allows_usage_cost),costOneHour:item.cost_one_hour===null?null:Number(item.cost_one_hour),costFourToEight:item.cost_four_to_eight_hours===null?null:Number(item.cost_four_to_eight_hours),costExtraHour:item.cost_extra_hour_after_eight===null?null:Number(item.cost_extra_hour_after_eight),u:space?.capacities.get(Number(u?.id))||0,school:space?.capacities.get(Number(school?.id))||0,boardroom:space?.capacities.get(Number(boardroom?.id))||0};
  }).sort((a,b)=>a.name.localeCompare(b.name,'es',{numeric:true}));
  if(!rooms.length)throw new Error('No hay salones activos configurados.');
}

function populateCatalogOptions(){
  $('#meetingType').innerHTML='<option value="">Selecciona el tipo de reunión</option>'+meetingTypes.filter(item=>item.is_active).map(item=>`<option value="${Number(item.id)}">${safe(item.name)}</option>`).join('');
  const icons={COFFEE:'☕',SODA:'🥤',COOKIES:'🍪',WATER:'💧'};
  $('.refreshment-options').innerHTML=refreshmentCatalog.filter(item=>item.is_active).map(item=>`<label class="refreshment-option"><input type="checkbox" name="refreshment" value="${Number(item.id)}"><span class="refreshment-icon">${icons[item.code]||'◇'}</span><b>${safe(item.name)}</b></label>`).join('');
}

function mapOccurrence(row,agendaOnly=false){
  const start=guatemalaDateTime(row.starts_at),end=guatemalaDateTime(row.ends_at),space=spaceById(row.space_id),recurrence=row.recurrence_type||'none';
  return{
    id:String(row.occurrence_id),meetingId:String(row.meeting_id),seriesId:recurrence!=='none'?String(row.meeting_id):null,
    recurrenceLabel:recurrenceNames[recurrence]||'Recurrente',requestedBy:row.requested_by||null,
    title:row.title,description:row.description||'Sin descripción',observations:row.observations||'',date:start.date,start:start.time,end:end.time,
    roomIds:space?.roomIds||[],spaceId:Number(row.space_id),configuration:row.configuration_name||'Sin configuración',attendees:Number(row.attendees||0),
    status:row.status,owner:row.requester_name||'Usuario',organization:row.organization_name||'Sin organización',area:row.department_name||'',meetingType:row.meeting_type_name||'Reunión',requestSource:row.request_source||'registered',paidOfferingCode:row.paid_offering_code||null,quotedAmount:row.quoted_amount===null||row.quoted_amount===undefined?null:Number(row.quoted_amount),paymentStatus:row.payment_status||null,contactName:row.contact_name||row.requester_name||'',contactEmail:row.contact_email||row.requester_email||'',contactPhone:row.contact_phone||'',
    refreshments:refreshmentsByMeeting.get(String(row.meeting_id))||[],createdAt:row.requested_at||null,approvedAt:row.approved_at||null,
    rejectedAt:row.rejected_at||null,reason:row.rejection_reason||null,rejectedBy:row.rejected_by_name||null,cancelledAt:row.cancelled_at||null,
    cancelledBy:row.cancelled_by_name||null,cancelledByRole:row.cancelled_by_role||null,rescheduledAt:row.last_rescheduled_at||null,agendaOnly
  };
}
function mapMaintenance(row){
  const start=guatemalaDateTime(row.starts_at),end=guatemalaDateTime(row.ends_at);
  return{id:String(row.id),roomId:Number(row.room_id),date:start.date,endDate:end.date,start:start.time,end:end.time,allDay:Boolean(row.all_day),reason:row.reason,status:row.status,createdBy:row.created_by};
}
const incidentLabels={screen_on:'Pantalla de salón encendida',ac_unattended:'Aire acondicionado encendido sin personal en el salón',missing_equipment:'Equipo faltante'};
function mapIncidentOccurrence(row){
  const start=guatemalaDateTime(row.starts_at),end=guatemalaDateTime(row.ends_at);
  let items=[];try{items=Array.isArray(row.incidents)?row.incidents:JSON.parse(row.incidents||'[]')}catch{items=[]}
  return{occurrenceId:String(row.occurrence_id),meetingId:String(row.meeting_id),requestCode:row.request_code,title:row.title||'Reunión',owner:row.requester_name||'Usuario',requestedBy:row.requested_by||null,organization:row.organization_name||'Sin organización',spaceId:Number(row.space_id),spaceName:row.space_name||'Salón',date:start.date,start:start.time,endDate:end.date,end:end.time,status:row.status,configuration:row.configuration_name||'Sin configuración',attendees:Number(row.attendees||0),items};
}
function incidentStatus(item){return eventHasEnded({date:item.endDate||item.date,end:item.end})?{key:'finished',label:'Finalizada'}:{key:'scheduled',label:'Programada'}}
function incidentText(item){return incidentLabels[item.type]||item.type}
async function loadAgendaDate(render=true){
  const date=key(currentDate),{data,error}=await db.rpc('get_agenda',{p_date:date});if(error)throw error;
  meetings=meetings.filter(item=>!item.agendaOnly);
  const known=new Set(meetings.map(item=>item.id));
  (data||[]).forEach(row=>{const id=String(row.occurrence_id);if(!known.has(id))meetings.push(mapOccurrence({...row,requested_at:null},true))});
  if(render&&currentUser)renderAll(false);
}
async function loadApplicationData(){
  const [requestResult,maintenanceResult,refreshmentResult]=await Promise.all([
    db.from('vw_request_occurrences').select('*').order('starts_at'),
    db.from('maintenance_events').select('*').order('starts_at'),
    db.from('meeting_refreshments').select('meeting_id,refreshments(name)')
  ]);
  const error=[requestResult,maintenanceResult,refreshmentResult].find(result=>result.error)?.error;if(error)throw error;
  refreshmentsByMeeting=new Map();(refreshmentResult.data||[]).forEach(row=>{const id=String(row.meeting_id),names=refreshmentsByMeeting.get(id)||[];if(row.refreshments?.name)names.push(row.refreshments.name);refreshmentsByMeeting.set(id,names)});
  meetings=(requestResult.data||[]).map(row=>mapOccurrence(row));
  maintenances=(maintenanceResult.data||[]).map(mapMaintenance);
  adminUsers=[];
  try{
    const incidentResult=await db.rpc('get_incident_occurrences');
    if(incidentResult.error){
      const message=String(incidentResult.error.message||'');
      if(!/get_incident_occurrences|schema cache|does not exist|not found/i.test(message))throw incidentResult.error;
      incidents=[];
    }else incidents=(incidentResult.data||[]).map(mapIncidentOccurrence);
  }catch(error){
    if(!/get_incident_occurrences|schema cache|does not exist|not found/i.test(String(error?.message||'')))throw error;
    incidents=[];
  }
  await loadAgendaDate(false);
}
async function loadCurrentProfile(authUser){
  let{data,error}=await db.from('profiles').select('id,portal_user_id,full_name,role,is_superadmin,is_active,account_status,status_reason,organizations(name,code,can_request_refreshments),department:departments!profiles_department_id_fkey(name)').eq('id',authUser.id).single();
  if(error&&/is_superadmin|column .* does not exist|42703/i.test(String(error.message||''))){
    const legacy=await db.from('profiles').select('id,portal_user_id,full_name,role,is_active,account_status,status_reason,organizations(name,code,can_request_refreshments),department:departments!profiles_department_id_fkey(name)').eq('id',authUser.id).single();
    data=legacy.data;error=legacy.error;
    if(data)data.is_superadmin=false;
  }
  if(error)throw error;
  if(data.account_status==='pending')throw new Error('Tu solicitud de acceso está pendiente de aprobación por un administrador.');
  if(data.account_status==='rejected')throw new Error(`Tu solicitud fue rechazada${data.status_reason?`: ${data.status_reason}`:'.'}`);
  if(data.account_status==='deleted')throw new Error('Tu cuenta fue eliminada. Contacta al administrador.');
  if(!data.is_active||data.account_status!=='active')throw new Error('Tu cuenta está inactiva. Contacta al administrador.');
  const [permissionsResult,modulesResult]=await Promise.all([db.rpc('get_my_module_permissions',{p_module_code:'salas'}),db.rpc('get_my_modules')]);
  if(permissionsResult.error)throw permissionsResult.error;
  if(modulesResult.error)throw modulesResult.error;
  const permissions=new Set((permissionsResult.data||[]).map(item=>item.permission_code));
  const manager=data.role!=='admin'&&permissions.has('ver_solicitudes_pago')&&permissions.has('ver_reportes');
  return{id:authUser.id,portalUserId:Number(data.portal_user_id)||null,email:authUser.email,name:data.full_name,role:data.role,isSuperadmin:Boolean(data.is_superadmin),isManager:manager,permissions,label:data.role==='admin'?'Administrador':manager?'Gestor':'Usuario',initials:initials(data.full_name),organization:data.organizations?.name||'Sin organización',organizationCode:data.organizations?.code||'',canRequestRefreshments:Boolean(data.organizations?.can_request_refreshments),area:data.department?.name||''};
}
function subscribeApplicationChanges(){
  if(applicationChannel)db.removeChannel(applicationChannel);
  applicationChannel=db.channel(`salaflow-app-${currentUser.id}`).on('postgres_changes',{event:'INSERT',schema:'public',table:'board_events'},payload=>{
    clearTimeout(refreshTimer);refreshTimer=setTimeout(async()=>{try{
      if(payload.new?.entity_type==='user'&&payload.new?.entity_id===currentUser.id){
        const{data}=await db.auth.getUser();
        try{const access={role:currentUser.salasRole,superadmin:currentUser.isSuperadmin};currentUser=applyPortalSalasRole(await loadCurrentProfile(data.user),access);syncCurrentUserInterface()}catch(error){await logout();console.warn(databaseMessage(error));return}
      }
      await loadApplicationData();renderAll(false);
    }catch(error){console.warn('No fue posible actualizar los datos en tiempo real.',error)}},250);
  }).subscribe();
}
async function refreshApplication(){await loadApplicationData();renderAll(false)}

function toast(message,error=false){
  const item=document.createElement('div');item.className='toast';item.style.borderLeftColor=error?'var(--red)':'var(--green)';item.textContent=`${error?'!':'✓'}  ${message}`;$('#toasts').append(item);setTimeout(()=>item.remove(),3200);
}
function openModal(html,wide=false){$('#modalBody').innerHTML=html;$('#modal .modal').classList.toggle('modal-wide',wide);$('#modal').classList.remove('hidden')}
function closeModal(){$('#modal').classList.add('hidden');$('#modal .modal').classList.remove('modal-wide')}
function updateRefreshmentAccess(){
  const eligible=currentUser?.canRequestRefreshments,field=$('#refreshmentField'),inputs=$$('input[name="refreshment"]');
  field.classList.toggle('locked',!eligible);inputs.forEach(input=>{input.disabled=!eligible;if(!eligible)input.checked=false});
  $('#refreshmentStatus').textContent=eligible?'Disponible':'Solo Cengicaña';
  $('#refreshmentHelp').textContent=eligible?'Selecciona una o varias opciones; puedes dejar todas sin marcar.':'La refacción solo puede solicitarse desde una cuenta asociada a Cengicaña.';
}
function syncCurrentUserInterface(){
  if(!currentUser)return;const isAdmin=currentUser.role==='admin'||currentUser.isSuperadmin,canPaid=isAdmin||currentUser.permissions?.has('ver_solicitudes_pago'),canReports=isAdmin||currentUser.permissions?.has('ver_reportes');$$('.user-name').forEach(el=>el.textContent=currentUser.name);$$('.user-role').forEach(el=>el.textContent=`${currentUser.label} · ${currentUser.organization}`);$$('.user-initials').forEach(el=>el.textContent=currentUser.initials);$('#firstName').textContent=currentUser.name.split(' ')[0];$$('.admin-only').forEach(el=>el.classList.toggle('hidden',!isAdmin));$$('.user-only').forEach(el=>el.classList.toggle('hidden',isAdmin));$$('.manager-allowed').forEach(el=>{const view=el.dataset.view||el.id.replace('View','');el.classList.toggle('hidden',!(isAdmin||(view==='paidRequests'?canPaid:view==='reports'?canReports:canPaid||canReports)))});$$('.superadmin-only').forEach(el=>el.classList.toggle('hidden',!currentUser.isSuperadmin));
}

function resetInactivityTimer(){if(!currentUser)return;clearTimeout(inactivityTimer);inactivityTimer=setTimeout(async()=>{if(!currentUser)return;await logout('La sesión se cerró por 5 minutos de inactividad.');},300000)}
function startInactivityMonitor(){['mousemove','mousedown','keydown','touchstart','scroll'].forEach(event=>document.addEventListener(event,resetInactivityTimer,{passive:true}));resetInactivityTimer()}
function matchesPortalAccess(access){
  if(!access?.allowed||!currentUser)return false;
  // El portal confirma la identidad y la entrada al módulo; CengiSalas decide
  // el rol y las funciones. Una diferencia de rol no debe reiniciar el SSO.
  return currentUser.portalUserId===Number(access.portal_user_id);
}
function applyPortalSalasRole(profile,portalAccess){
  const role=String(portalAccess.role||'');
  if(!['admin','manager','user'].includes(role))throw new Error('Tu cuenta no tiene un rol válido en CengiSalas.');
  profile.salasRole=role;
  profile.role=role==='admin'?'admin':'user';
  profile.isManager=role==='manager';
  profile.label=role==='admin'?'Administrador':role==='manager'?'Gestor':'Usuario estándar';
  profile.permissions=new Set(role==='admin'
    ?['ver_dashboard','ver_solicitudes','ver_solicitudes_pago','ver_reportes','gestionar_solicitudes','gestionar_usuarios','gestionar_mantenimiento','gestionar_salones','gestionar_incidencias','administrar_instituciones']
    :role==='manager'?['ver_dashboard','ver_solicitudes','ver_solicitudes_pago','ver_reportes']:['ver_dashboard','ver_solicitudes']);
  profile.isSuperadmin=profile.isSuperadmin&&Boolean(portalAccess.superadmin);
  return profile;
}
async function login(authUser,portalAccess){
  currentUser=await loadCurrentProfile(authUser);currentDate=new Date();agendaMode='agenda';
  if(!matchesPortalAccess(portalAccess)){
    throw new Error('La cuenta de CengiSalas no corresponde al usuario del portal. Vuelve al menú e inicia sesión nuevamente.');
  }
  currentUser=applyPortalSalasRole(currentUser,portalAccess);
  await loadCatalogs();populateCatalogOptions();await loadApplicationData();
  $('#publicLanding')?.classList.add('hidden');$('#app').classList.remove('hidden');
  syncCurrentUserInterface();resetInactivityTimer();
  $('#contactName').value=currentUser.name;$('#contactEmail').value=currentUser.email||'';
  populateRooms();updateRefreshmentAccess();renderAll(false);showView('agenda');subscribeApplicationChanges();startMaintenanceStatusClock();
}
async function logout(reason=''){
  if(applicationChannel){await db.removeChannel(applicationChannel);applicationChannel=null}
  clearTimeout(inactivityTimer);clearInterval(maintenanceClockTimer);maintenanceClockTimer=null;try{await Promise.race([db.auth.signOut({scope:'local'}),new Promise(resolve=>setTimeout(resolve,1500))])}catch(error){console.warn('No se pudo limpiar la sesión de datos.',error)}currentUser=null;meetings=[];maintenances=[];$('#app').classList.add('hidden');$('#publicLanding')?.classList.remove('hidden');if(reason)sessionStorage.setItem('cengisalas_logout_message',reason);if(document.documentElement.classList.contains('private-entry'))location.replace('../login/cengisalas_logout.php');
}
function showView(name){
  const adminOnly=['incidents','approvals','users','maintenance','rooms'];
  if(adminOnly.includes(name)&&currentUser?.role!=='admin'&&!currentUser?.isSuperadmin)return;
  if(name==='paidRequests'&&!currentUser?.permissions?.has('ver_solicitudes_pago')&&currentUser?.role!=='admin')return;
  if(name==='reports'&&!currentUser?.permissions?.has('ver_reportes')&&currentUser?.role!=='admin')return;
  $$('.view').forEach(v=>v.classList.remove('active'));$(`#${name}View`).classList.add('active');
  $$('aside nav button[data-view]').forEach(b=>b.classList.toggle('active',b.dataset.view===name));$('#sidebar').classList.remove('open');
  if(name==='agenda')renderAgenda();if(name==='mine')renderMine();if(name==='incidents')renderIncidents();if(name==='approvals')renderApprovals();if(name==='paidRequests')renderPaidRequests();if(name==='users')renderUsers();if(name==='maintenance')renderMaintenance();if(name==='rooms'){renderRooms();renderPaidOfferings()}if(name==='reports')renderReports()
}

function unlockRequestDate(){
  const input=$('#meetingDate');input.disabled=false;delete input.dataset.calendarLocked;input.min=key(new Date());$('#meetingDateHelp').textContent='';
}
function openRequestView(){
  const input=$('#meetingDate'),wasLocked=input.dataset.calendarLocked==='true',today=key(new Date());unlockRequestDate();if(wasLocked||input.value<today)input.value=today;showView('request');updateRecurrence();
}
function openRequestForCalendarDate(date){
  const selected=new Date(`${date}T12:00:00`);currentDate=selected;$('#requestForm').reset();showView('request');setTimeout(()=>{const input=$('#meetingDate');input.value=date;input.disabled=true;input.dataset.calendarLocked='true';$('#meetingDateHelp').textContent=`Fecha seleccionada desde el calendario: ${format(date,{weekday:'long',day:'numeric',month:'long',year:'numeric'})}.`;$('#recurrence').value='none';updateRecurrence()},0);
}

function populateRooms(){
  const options=rooms.map(room=>`<option value="${room.id}">${room.name}</option>`).join('');
  $('#maintenanceRoom').innerHTML=options;$('#roomFilter').innerHTML='<option value="all">Todos los salones</option>'+options;renderRequestRoomOptions();
}
function updateRequestOptions(){
  const roomId=Number($('#meetingRoom').value),room=roomById(roomId),date=recurrenceDates()[0],singleSpace=singleSpaceForRoom(roomId),combinedSpace=combinedSpaceForRoom(roomId),singleEligible=requestSpaceEligible(singleSpace,date),canCombine=requestSpaceEligible(combinedSpace,date),combine=$('#combineRooms');
  if(!singleEligible&&canCombine)combine.checked=true;
  if(!canCombine)combine.checked=false;
  combine.disabled=!singleEligible&&canCombine;
  const selectedSpace=combine.checked?combinedSpace:singleSpace,allowed=requestConfigurationOptions(selectedSpace).map(item=>item.name),inputs=$$('input[name="configuration"]');
  inputs.forEach(input=>{const visible=allowed.includes(input.value);input.disabled=!visible;input.closest('.configuration-card').classList.toggle('hidden',!visible)});
  if(!allowed.includes(selectedConfiguration())){const first=inputs.find(input=>input.value===allowed[0]);if(first)first.checked=true}
  const configuration=selectedConfiguration(),combined=combine.checked&&canCombine;
  $$('.configuration-card').forEach(card=>card.classList.toggle('selected',!card.classList.contains('hidden')&&card.querySelector('input').checked));
  $('.configuration-options').style.gridTemplateColumns=`repeat(${Math.max(allowed.length,1)},minmax(0,1fr))`;
  $('#combineOption').classList.toggle('hidden',!canCombine);
  $('#combineRoomsLabel').textContent=combinedSpace?`Usar ${combinedSpace.name}`:'Usar salones combinados';
  const configurationRecord=configurationByName(configuration),capacity=selectedSpace?.capacities.get(Number(configurationRecord?.id))||0;
  const fixed=allowed.length===1?` · Distribución fija: ${allowed[0]}`:'';
  $('#roomHelp').textContent=!room?requestAvailabilityState==='loading'?'Consultando salones disponibles…':requestAvailabilityState==='error'?'No se pudo consultar la disponibilidad. Cambia el horario para reintentar.':requestAvailabilityState==='idle'?'Selecciona una fecha y un horario válido.':'No hay salones disponibles para este horario y cantidad de participantes.':combined?`Se bloquearán ambos salones · Capacidad conjunta: ${capacity} personas`:`${room.floor||''} · Capacidad: ${capacity} personas${fixed}`;
  $('#configHelp').textContent=!allowed.length?'No hay distribuciones con capacidad suficiente.':allowed.length===1?`Este salón solo admite ${allowed[0]} para la cantidad indicada.`:configuration==='Tipo U'?'Mesas formando una U para facilitar conversación.':configuration==='Tipo Escuela'?'Mesas diagonales en espina de pescado orientadas hacia el instructor.':'Mesa central con sillas alrededor para reuniones de junta.';updateRequestMainAvailability();
}

function visibleAgendaMeeting(meeting){return meeting.status==='approved'}
function renderRoomMeetingList(){
  const date=key(currentDate),filter=$('#roomFilter').value,visibleRooms=filter==='all'?rooms:rooms.filter(room=>room.id===Number(filter));
  $('#roomMeetingList').innerHTML=visibleRooms.map(room=>{
    const roomMeetings=meetings.filter(meeting=>meeting.date===date&&meeting.roomIds.includes(room.id)&&visibleAgendaMeeting(meeting)).sort((a,b)=>a.start.localeCompare(b.start)),roomMaintenance=maintenances.filter(item=>item.status==='scheduled'&&item.date===date&&item.roomId===room.id).sort((a,b)=>a.start.localeCompare(b.start)),entries=[...roomMeetings.map(meeting=>{const adminActions=currentUser.role==='admin'&&['pending','approved'].includes(meeting.status)?`<button class="mini" data-list-move="${meeting.id}">Mover</button><button class="mini danger" data-list-cancel="${meeting.id}">Cancelar</button>`:'';return{time:meeting.start,html:`<div class="room-list-meeting"><span class="room-list-time"><b>${meeting.start}</b><small>${meeting.end}</small></span><span class="room-list-copy"><b>${safe(meeting.title)}</b><small>${safe(meeting.meetingType||'Reunión')} · ${safe(meeting.configuration)} · ${meeting.attendees} personas${meeting.roomIds.length===2?' · Salones combinados':''}</small></span><span class="room-list-side"><span class="badge ${meeting.status}">${statusName(meeting.status)}</span><span class="room-list-actions"><button class="mini" data-detail="${meeting.id}">Ver</button>${adminActions}</span></span></div>`}}),...roomMaintenance.map(item=>({time:item.start,html:`<div class="room-list-meeting maintenance-list-item"><span class="room-list-time"><b>${item.allDay?'Todo el día':item.start}</b><small>${item.allDay?'':item.end}</small></span><span class="room-list-copy"><b>⚒ Mantenimiento</b><small>${safe(item.reason)}</small></span><span class="badge maintenance-active">Programado</span></div>`}))].sort((a,b)=>a.time.localeCompare(b.time));
    return `<article class="room-schedule-card"><header><div><h3>${room.name}</h3><p>${room.floor} · ${roomCapacityText(room)}</p></div><span>${entries.length} ${entries.length===1?'actividad':'actividades'}</span></header><div class="room-schedule-entries">${entries.length?entries.map(entry=>entry.html).join(''):'<div class="room-list-empty"><span>✓</span><div><b>Salón disponible</b><small>No hay reuniones programadas para este día.</small></div></div>'}</div></article>`;
  }).join('');const list=$('#roomMeetingList');$$('[data-detail]',list).forEach(button=>button.onclick=()=>meetingDetail(button.dataset.detail));$$('[data-list-move]',list).forEach(button=>button.onclick=()=>move(button.dataset.listMove));$$('[data-list-cancel]',list).forEach(button=>button.onclick=()=>cancelAdminMeeting(button.dataset.listCancel));
}
function renderAgendaGrid(){
  const date=key(currentDate),filter=$('#roomFilter').value,visible=filter==='all'?rooms:rooms.filter(r=>r.id===Number(filter)),grid=$('#agendaGrid');grid.style.setProperty('--columns',visible.length);
  let html='<div class="corner">HORA</div>'+visible.map(room=>`<div class="room-head"><b>${room.name}</b><small>${room.floor} · ${roomCapacityText(room,true)}</small></div>`).join('');
  for(let hour=7;hour<=18;hour++){
    html+=`<div class="time">${String(hour).padStart(2,'0')}:00</div>`;
    visible.forEach(room=>{
      const items=meetings.filter(m=>m.date===date&&m.roomIds.includes(room.id)&&Number(m.start.slice(0,2))===hour&&visibleAgendaMeeting(m));
      const maintenanceItems=maintenances.filter(item=>item.status==='scheduled'&&item.date===date&&item.roomId===room.id&&(item.allDay||Number(item.start.slice(0,2))===hour));
      html+='<div class="slot">'+maintenanceItems.map(item=>`<div class="meeting maintenance-agenda"><b>⚒ Mantenimiento</b><small>${item.allDay?'Todo el día':`${item.start}–${item.end}`} · ${safe(item.reason)}</small></div>`).join('')+items.map(m=>`<button class="meeting ${m.status}" data-detail="${m.id}"><b>${safe(m.title)}</b><small>${m.start}–${m.end}${m.roomIds.length===2?' · Combinado':''}</small></button>`).join('')+'</div>';
    });
  }
  grid.innerHTML=html;$$('[data-detail]',grid).forEach(b=>b.onclick=()=>meetingDetail(b.dataset.detail));
}
function renderMonthlyCalendar(){
  const year=currentDate.getFullYear(),month=currentDate.getMonth(),first=new Date(year,month,1,12),mondayOffset=(first.getDay()+6)%7,start=new Date(year,month,1-mondayOffset,12),today=key(new Date()),roomFilter=$('#roomFilter').value;
  const days=[];
  for(let index=0;index<42;index++){
    const date=new Date(start);date.setDate(start.getDate()+index);const dateValue=key(date),outside=date.getMonth()!==month,past=dateValue<today,dayMeetings=meetings.filter(item=>item.date===dateValue&&visibleAgendaMeeting(item)&&(roomFilter==='all'||item.roomIds.includes(Number(roomFilter)))),approved=dayMeetings.length,maintenance=maintenances.filter(item=>item.status==='scheduled'&&item.date===dateValue&&(roomFilter==='all'||item.roomId===Number(roomFilter))).length;
    const activity=[approved?`<span class="calendar-count approved">${approved} aprobada${approved===1?'':'s'}</span>`:'',maintenance?`<span class="calendar-count maintenance-active">${maintenance} mantenimiento${maintenance===1?'':'s'}</span>`:''].join('');
    days.push(`<button type="button" class="calendar-day${outside?' outside':''}${dateValue===today?' today':''}${past?' past':''}" data-calendar-date="${dateValue}" ${past?'disabled':''}><b>${date.getDate()}</b><div>${activity||'<span class="calendar-available">Disponible</span>'}</div></button>`);
  }
  $('#monthlyCalendar').innerHTML=days.join('');$$('[data-calendar-date]',$('#monthlyCalendar')).forEach(button=>button.onclick=()=>openRequestForCalendarDate(button.dataset.calendarDate));
}
function updateAgendaStats(){
  const date=key(currentDate);
  const now=new Date(),time=date===key(now)?`${String(now.getHours()).padStart(2,'0')}:${String(now.getMinutes()).padStart(2,'0')}`:'12:00',approved=meetings.filter(m=>m.date===date&&m.status==='approved'),occupiedNow=new Set(approved.filter(m=>m.start<=time&&m.end>time).flatMap(m=>m.roomIds));
  maintenances.filter(item=>item.status==='scheduled'&&item.date===date&&item.start<=time&&item.end>time).forEach(item=>occupiedNow.add(item.roomId));
  $('#availableStat').innerHTML=`${rooms.length-occupiedNow.size} <em>de ${rooms.length}</em>`;$('#meetingsStat').textContent=approved.length;
  const reservedHours=approved.reduce((sum,m)=>sum+(Number(m.end.slice(0,2))+Number(m.end.slice(3))/60-Number(m.start.slice(0,2))-Number(m.start.slice(3))/60)*m.roomIds.length,0);$('#occupancyStat').textContent=`${Math.round(reservedHours/(12*rooms.length)*100)}%`;
}
function renderAgenda(){
  const agenda=agendaMode==='agenda',list=agendaMode==='list',calendar=agendaMode==='calendar';$('#agendaContainer').classList.toggle('hidden',!agenda);$('#roomListContainer').classList.toggle('hidden',!list);$('#calendarContainer').classList.toggle('hidden',!calendar);$('#agendaViewButton').classList.toggle('active',agenda);$('#listViewButton').classList.toggle('active',list);$('#calendarViewButton').classList.toggle('active',calendar);
  $('#currentDateText').textContent=calendar?format(currentDate,{month:'long'}).replace(/^./,character=>character.toUpperCase()):format(currentDate,{weekday:'long',day:'numeric',month:'long'}).replace(/^./,character=>character.toUpperCase());$('#currentYear').textContent=currentDate.getFullYear();
  if(agenda)renderAgendaGrid();else if(list)renderRoomMeetingList();else renderMonthlyCalendar();updateAgendaStats();
}

function meetingDetail(id){
  const m=meetings.find(x=>x.id===id),series=seriesSummary(m);
  const auditLabels={pending:'SOLICITADA EL',approved:'APROBADA EL',rejected:'RECHAZADA EL',cancelled:'CANCELADA EL'};
  const audit=`<div><span>${auditLabels[m.status]}</span>${formatAuditTimestamp(auditTimestamp(m))}</div>`;
  const reason=m.status==='rejected'?`<div class="detail-wide rejection-detail"><span>MOTIVO DEL RECHAZO</span><b>${safe(m.reason||'No se registró un motivo para este rechazo.')}</b></div>`:'';
  const actor=m.status==='cancelled'?(()=>{const value=cancellationActor(m);return`<div><span>CANCELADA POR</span><b>${value.label}</b><small>${safe(value.name)}</small></div>`})():'';
  const contact=`<div class="detail-wide contact-detail"><span>DATOS DE CONTACTO DEL RESPONSABLE</span><div class="contact-detail-grid"><div><small>Responsable del salón</small><b>${safe(m.contactName||'No indicado')}</b></div><div><small>Correo electrónico</small><b>${safe(m.contactEmail||'No indicado')}</b></div><div><small>Teléfono</small><b>${safe(m.contactPhone||'No indicado')}</b></div></div></div>`;
  const observations=`<div class="detail-wide observation-detail"><span>OBSERVACIONES</span><b style="white-space:pre-wrap">${safe(m.observations||'Sin observaciones adicionales.')}</b></div>`;
  const paidOffering=m.requestSource==='paid'?paidOfferings.find(item=>item.code===m.paidOfferingCode):null;
  const paidDetails=paidOffering?`<div><span>OFERTA COMERCIAL</span><b>${safe(paidOffering.name)}</b></div><div><span>COTIZACIÓN</span><b>Q${Number(m.quotedAmount||0).toFixed(2)}</b></div>`:'';
  openModal(`<span class="badge ${m.status}">${approvalName(m.status)}</span>${operationalBadge(m)}${m.requestSource==='public'?'<span class="series-mark">Solicitud pública</span>':''}${m.requestSource==='paid'?'<span class="series-mark">Solicitud de pago</span>':''}${series?`<span class="series-mark">↻ ${safe(series)}</span>`:''}<h2 style="margin-top:14px">${safe(m.title)}</h2><p>${safe(m.description)}</p><div class="details"><div><span>APROBACIÓN</span><b>${approvalName(m.status)}</b></div><div><span>ESTADO</span><b>${operationalStatus(m).label}</b></div>${paidDetails}<div><span>TIPO DE REUNIÓN</span><b>${safe(m.meetingType||'No especificado')}</b></div><div><span>FECHA</span><b>${format(m.date,{day:'numeric',month:'short'})}</b></div><div><span>HORARIO</span><b>${m.start}–${m.end}</b></div><div><span>ESPACIO</span><b>${spaceName(m)}</b></div><div><span>CONFIGURACIÓN</span><b>${m.configuration}</b></div><div><span>PERSONAS</span><b>${m.attendees}</b></div><div><span>REFACCIÓN</span><b>${safe(refreshmentText(m))}</b></div><div><span>ORGANIZA</span><b>${safe(m.owner)}</b></div>${audit}${actor}${contact}${observations}${reason}</div>`)
}

function ownMeetings(){return meetings.filter(m=>m.requestedBy===currentUser.id)}
function renderMine(){
  let items=ownMeetings();if(mineFilter!=='all')items=items.filter(m=>m.status===mineFilter);items.sort((a,b)=>(a.date+a.start).localeCompare(b.date+b.start));$('#mineCount').textContent=ownMeetings().filter(m=>m.status!=='cancelled').length;
  $('#mineRows').innerHTML=items.length?items.map(m=>`<tr><td><b>${safe(m.title)}${m.seriesId?'<span class="series-mark">↻ Serie</span>':''}</b><small>${safe(m.meetingType||'Tipo no especificado')} · ${safe(m.description)}</small></td><td><b>${format(m.date,{day:'numeric',month:'short',year:'numeric'})}</b><small>${m.start}–${m.end}</small></td><td>${spaceName(m)}</td><td>${m.configuration}</td><td><span class="badge ${m.status}">${approvalName(m.status)}</span></td><td>${operationalBadge(m)}</td><td><button class="mini" data-detail="${m.id}">Ver</button> ${canModifyMeeting(m)&&['pending','approved'].includes(m.status)?`<button class="mini danger" data-cancel="${m.id}">Cancelar</button>`:''}</td></tr>`).join(''):'<tr><td colspan="7" class="empty">No hay reuniones en esta categoría.</td></tr>';
  $$('[data-detail]',$('#mineRows')).forEach(b=>b.onclick=()=>meetingDetail(b.dataset.detail));$$('[data-cancel]').forEach(b=>b.onclick=()=>cancelMeeting(b.dataset.cancel));
}
async function cancelOccurrenceInDatabase(id,applyToSeries,message){
  try{const{error}=await db.rpc('cancel_occurrence',{p_occurrence_id:id,p_reason:'Cancelada desde el panel',p_apply_to_series:applyToSeries});if(error)throw error;await refreshApplication();closeModal();toast(message)}catch(error){toast(databaseMessage(error),true)}
}
function cancelMeeting(id){
  const m=meetings.find(x=>x.id===id);if(!m||m.requestedBy!==currentUser.id)return;if(!canModifyMeeting(m))return toast('El evento ya finalizó; solo puedes consultar sus detalles.',true);
  if(!m.seriesId){void cancelOccurrenceInDatabase(id,false,'La reunión fue cancelada.');return}
  openModal(`<h2>Cancelar reunión recurrente</h2><p>Esta fecha pertenece a una serie de ${seriesMembers(m).length} reuniones. Elige el alcance de la cancelación.</p><div class="form-actions"><button id="cancelOne" class="button secondary">Solo ${format(m.date,{day:'numeric',month:'short'})}</button><button id="cancelSeries" class="button primary">Toda la serie</button></div>`);
  $('#cancelOne').onclick=()=>void cancelOccurrenceInDatabase(id,false,'Se canceló únicamente esta fecha.');
  $('#cancelSeries').onclick=()=>void cancelOccurrenceInDatabase(id,true,'Se canceló toda la serie recurrente.');
}

function adminRequestGroups(status,paidOnly=false){
  const selected=meetings.filter(meeting=>Boolean(meeting.requestSource==='paid')===paidOnly&&(status==='all'?['pending','approved','rejected','cancelled'].includes(meeting.status):meeting.status===status)),groups=new Map();
  selected.forEach(meeting=>{const cancellationKey=meeting.status==='cancelled'?`:${meeting.cancelledAt||'legacy'}:${meeting.cancelledByRole||meeting.cancelledBy||meeting.owner}`:'',groupKey=meeting.seriesId?`${meeting.seriesId}:${meeting.status}${cancellationKey}`:`meeting:${meeting.id}`;if(!groups.has(groupKey))groups.set(groupKey,[]);groups.get(groupKey).push(meeting)});
  return [...groups.values()].map(members=>members.sort((a,b)=>(a.date+a.start).localeCompare(b.date+b.start))).sort((a,b)=>(a[0].date+a[0].start).localeCompare(b[0].date+b[0].start));
}
function requestActionButtons(m){
  if(!canModifyMeeting(m)||(currentUser.role!=='admin'&&!currentUser.isSuperadmin))return'';const schedule=`<button type="button" data-reschedule="${m.id}">Cambiar horario</button>`,moveAction=`<button type="button" data-move="${m.id}">Mover salón</button>`;
  return({pending:`${schedule}${moveAction}<button type="button" class="danger" data-reject="${m.id}">Rechazar</button><button type="button" class="approve-action" data-approve="${m.id}">Aprobar</button>`,approved:`${schedule}${moveAction}<button type="button" class="danger" data-admin-cancel="${m.id}">Cancelar</button>`,rejected:`${schedule}${moveAction}<button type="button" class="approve-action" data-approve="${m.id}">Volver a aprobar</button>`,cancelled:''})[m.status]||'';
}
function requestActionMenu(m){return`<div class="request-actions-menu"><button type="button" class="request-actions-toggle" data-request-actions aria-label="Mostrar acciones" aria-expanded="false">•••</button><div class="request-actions-dropdown"><button type="button" data-detail="${m.id}">${canModifyMeeting(m)?'Ver solicitud':'Ver detalles'}</button>${requestActionButtons(m)}</div></div>`}
function closeRequestActionMenus(except=null){$$('.request-actions-dropdown.open').forEach(menu=>{if(menu!==except){menu.classList.remove('open');menu.previousElementSibling?.setAttribute('aria-expanded','false')}})}
function toggleRequestActionMenu(button){const menu=button.nextElementSibling,open=!menu.classList.contains('open');closeRequestActionMenus(menu);if(!open){menu.classList.remove('open');button.setAttribute('aria-expanded','false');return}menu.classList.add('open');button.setAttribute('aria-expanded','true');const rect=button.getBoundingClientRect(),width=menu.offsetWidth,height=menu.offsetHeight,left=Math.max(8,Math.min(window.innerWidth-width-8,rect.right-width));let top=rect.bottom+6;if(top+height>window.innerHeight-8)top=Math.max(8,rect.top-height-6);menu.style.left=`${left}px`;menu.style.top=`${top}px`}
function bindRequestRowActions(rows){
  $$('[data-request-actions]',rows).forEach(button=>button.onclick=event=>{event.stopPropagation();toggleRequestActionMenu(button)});$$('.request-actions-dropdown',rows).forEach(menu=>menu.onclick=event=>event.stopPropagation());
  $$('[data-detail]',rows).forEach(b=>b.onclick=()=>meetingDetail(b.dataset.detail));$$('[data-approve]',rows).forEach(b=>b.onclick=()=>approve(b.dataset.approve));$$('[data-reject]',rows).forEach(b=>b.onclick=()=>reject(b.dataset.reject));$$('[data-reschedule]',rows).forEach(b=>b.onclick=()=>reschedule(b.dataset.reschedule));$$('[data-move]',rows).forEach(b=>b.onclick=()=>move(b.dataset.move));$$('[data-admin-cancel]',rows).forEach(b=>b.onclick=()=>cancelAdminMeeting(b.dataset.adminCancel));
}
function requestGroupDate(members){const m=members[0],last=members.at(-1);return{dateText:m.seriesId?`${members.length} fechas`:format(m.date,{day:'numeric',month:'short',year:'numeric'}),dateDetail:m.seriesId?`${format(m.date,{day:'numeric',month:'short'})}–${format(last.date,{day:'numeric',month:'short',year:'numeric'})} · ${m.start}–${m.end}`:`${m.start}–${m.end}`}}
function renderApprovals(){
  const pendingGroups=adminRequestGroups('pending'),approvedGroups=adminRequestGroups('approved'),rejectedGroups=adminRequestGroups('rejected'),cancelledGroups=adminRequestGroups('cancelled'),allGroups=adminRequestGroups('all'),paidPendingGroups=adminRequestGroups('pending',true);
  $('#pendingCount').textContent=pendingGroups.length;$('#paidRequestNavCount').textContent=paidPendingGroups.length;$('#pendingStat').textContent=pendingGroups.length+paidPendingGroups.length;
  $('#requestPendingCount').textContent=pendingGroups.length;$('#requestApprovedCount').textContent=approvedGroups.length;$('#requestRejectedCount').textContent=rejectedGroups.length;$('#requestCancelledCount').textContent=cancelledGroups.length;$('#requestAllCount').textContent=allGroups.length;
  const showCancellationActor=adminRequestStatus==='cancelled',auditHeaders={pending:'FECHA DE SOLICITUD',approved:'APROBADA EL',rejected:'RECHAZADA EL',cancelled:'CANCELADA EL',all:'ÚLTIMA GESTIÓN'};
  $('#requestAuditHeader').textContent=auditHeaders[adminRequestStatus];$('#requestCancelActorHeader').classList.toggle('hidden-col',!showCancellationActor);$('#adminRequestTable').classList.toggle('with-cancel-actor',showCancellationActor);
  let groups=adminRequestGroups(adminRequestStatus);if(adminRequestDate)groups=groups.filter(members=>members.some(meeting=>meeting.date===adminRequestDate));
  $('#adminRequestRows').innerHTML=groups.length?groups.map(members=>{const m=members[0],{dateText,dateDetail}=requestGroupDate(members),actor=cancellationActor(m),actorCell=showCancellationActor?`<td class="cancel-actor-cell"><b>${actor.label}</b><small>${safe(actor.name)}</small></td>`:'';return`<tr><td><b>${safe(m.title)}${m.seriesId?'<span class="series-mark">↻ Serie</span>':''}</b><small>${safe(m.meetingType||'Tipo no especificado')} · ${safe(m.description)}</small></td><td><div class="requester-inline"><span class="avatar">${initials(m.owner)}</span><div><b>${safe(m.owner)}</b><small>${safe(m.organization)}</small></div></div></td><td class="responsible-cell"><b>${safe(m.contactName||'No indicado')}</b><small>Responsable del salón</small></td><td><b>${dateText}</b><small>${dateDetail}</small></td><td><b>${safe(spaceName(m))}</b><small>${safe(m.configuration)} · ${m.attendees} personas</small></td><td>${safe(refreshmentText(m))}</td><td><span class="badge ${m.status}">${approvalName(m.status)}</span></td><td>${operationalBadge(m)}</td><td class="audit-cell">${formatAuditTimestamp(auditTimestamp(m))}</td>${actorCell}<td>${requestActionMenu(m)}</td></tr>`}).join(''):`<tr><td colspan="${showCancellationActor?11:10}" class="empty">No hay solicitudes que coincidan con este estado y fecha.</td></tr>`;
  bindRequestRowActions($('#adminRequestRows'));
}
function renderPaidRequests(){
  const pendingGroups=adminRequestGroups('pending',true),approvedGroups=adminRequestGroups('approved',true),rejectedGroups=adminRequestGroups('rejected',true),cancelledGroups=adminRequestGroups('cancelled',true),allGroups=adminRequestGroups('all',true);
  $('#paidRequestNavCount').textContent=pendingGroups.length;$('#paidPendingCount').textContent=pendingGroups.length;$('#paidApprovedCount').textContent=approvedGroups.length;$('#paidRejectedCount').textContent=rejectedGroups.length;$('#paidCancelledCount').textContent=cancelledGroups.length;$('#paidAllCount').textContent=allGroups.length;
  const showCancellationActor=paidRequestStatus==='cancelled',auditHeaders={pending:'FECHA DE SOLICITUD',approved:'APROBADA EL',rejected:'RECHAZADA EL',cancelled:'CANCELADA EL',all:'ÚLTIMA GESTIÓN'};$('#paidRequestAuditHeader').textContent=auditHeaders[paidRequestStatus];$('#paidRequestCancelActorHeader').classList.toggle('hidden-col',!showCancellationActor);$('#paidRequestTable').classList.toggle('with-cancel-actor',showCancellationActor);
  let groups=adminRequestGroups(paidRequestStatus,true);if(paidRequestDate)groups=groups.filter(members=>members.some(meeting=>meeting.date===paidRequestDate));
  $('#paidRequestRows').innerHTML=groups.length?groups.map(members=>{const m=members[0],{dateText,dateDetail}=requestGroupDate(members),actor=cancellationActor(m),actorCell=showCancellationActor?`<td class="cancel-actor-cell"><b>${actor.label}</b><small>${safe(actor.name)}</small></td>`:'';return`<tr><td><b>${safe(m.title)}${m.seriesId?'<span class="series-mark">↻ Serie</span>':''}</b><small>${safe(m.meetingType||'Tipo no especificado')} · ${safe(m.description)}</small></td><td><div class="requester-inline"><span class="avatar">${initials(m.owner)}</span><div><b>${safe(m.owner)}</b><small>${safe(m.organization)}</small></div></div></td><td class="responsible-cell"><b>${safe(m.contactName||'No indicado')}</b><small>Responsable del salón</small></td><td><b>${dateText}</b><small>${dateDetail}</small></td><td><b>${safe(spaceName(m))}</b><small>${safe(m.configuration)} · ${m.attendees} personas</small></td><td>${safe(refreshmentText(m))}</td><td class="paid-total-cell"><b>${money(m.quotedAmount)}</b><small>Total cotizado</small></td><td><span class="badge ${m.status}">${approvalName(m.status)}</span></td><td>${operationalBadge(m)}</td><td class="audit-cell">${formatAuditTimestamp(auditTimestamp(m))}</td>${actorCell}<td>${requestActionMenu(m)}</td></tr>`}).join(''):`<tr><td colspan="${showCancellationActor?12:11}" class="empty">No hay solicitudes de pago que coincidan con este estado y fecha.</td></tr>`;
  bindRequestRowActions($('#paidRequestRows'));
}
function seriesReviewMembers(meetingId){return meetings.filter(item=>item.meetingId===meetingId).sort((a,b)=>(a.date+a.start).localeCompare(b.date+b.start))}
function openSeriesApproval(id){
  const selected=meetings.find(item=>item.id===id||item.meetingId===id);if(!selected)return;const meetingId=selected.meetingId,members=seriesReviewMembers(meetingId),actionable=members.filter(item=>canModifyMeeting(item)&&['pending','rejected'].includes(item.status)),refreshments=safe(refreshmentText(selected));
  const rows=members.map((item,index)=>{const canApprove=canModifyMeeting(item)&&['pending','rejected'].includes(item.status),canReject=canModifyMeeting(item)&&item.status==='pending',actions=canApprove||canReject?`<div class="series-session-actions">${canApprove?`<button class="mini approve-action" data-series-approve="${item.id}">${item.status==='rejected'?'Volver a aprobar':'Aprobar'}</button>`:''}${canReject?`<button class="mini danger" data-series-reject="${item.id}">Rechazar</button>`:''}</div>`:'<small>Solo consulta</small>';return`<tr><td><b>Sesión ${index+1}</b><small>${format(item.date,{weekday:'short',day:'numeric',month:'short',year:'numeric'})}</small></td><td><b>${item.start}–${item.end}</b></td><td><b>${safe(spaceName(item))}</b></td><td>${safe(item.configuration)}</td><td>${refreshments}</td><td><span class="badge ${item.status}">${approvalName(item.status)}</span></td><td>${actions}</td></tr>`}).join('');
  openModal(`<div class="series-review"><div class="series-review-heading"><div><span class="series-mark">↻ Serie · ${members.length} sesiones</span><h2>${safe(selected.title)}</h2><p>${safe(selected.owner)} · ${safe(selected.organization)} · ${selected.attendees} personas</p></div><div class="series-refreshment-summary"><small>REFACCIÓN SOLICITADA</small><b>${refreshments}</b></div></div><div class="series-review-table"><table><thead><tr><th>SESIÓN</th><th>HORARIO</th><th>SALÓN SOLICITADO</th><th>DISTRIBUCIÓN</th><th>REFACCIÓN</th><th>APROBACIÓN</th><th>DECISIÓN</th></tr></thead><tbody>${rows}</tbody></table></div><div class="form-actions series-review-actions"><button type="button" id="closeSeriesReview" class="button secondary">Cerrar</button>${actionable.length?`<button type="button" id="approveAllSeries" class="button primary">Aprobar toda la serie</button>`:''}</div></div>`,true);
  $('#closeSeriesReview').onclick=closeModal;$$('[data-series-approve]').forEach(button=>button.onclick=()=>void approveSeriesOccurrence(button.dataset.seriesApprove,false,meetingId,button));$$('[data-series-reject]').forEach(button=>button.onclick=()=>openSeriesOccurrenceReject(button.dataset.seriesReject,meetingId));if($('#approveAllSeries'))$('#approveAllSeries').onclick=event=>void approveSeriesOccurrence(actionable[0].id,true,meetingId,event.currentTarget);
}
async function approveSeriesOccurrence(id,applyToSeries,meetingId,button){button.disabled=true;try{const{error}=await db.rpc('approve_occurrence',{p_occurrence_id:id,p_apply_to_series:applyToSeries});if(error)throw error;await refreshApplication();if(applyToSeries){closeModal();toast('Las sesiones pendientes de la serie fueron aprobadas.')}else{openSeriesApproval(meetingId);toast('La sesión fue aprobada.')}}catch(error){button.disabled=false;toast(databaseMessage(error),true)}}
function openSeriesOccurrenceReject(id,meetingId){const meeting=meetings.find(item=>item.id===id);if(!meeting||meeting.status!=='pending')return;openModal(`<h2>Rechazar una sesión</h2><p>Se rechazará únicamente la sesión del ${format(meeting.date,{weekday:'long',day:'numeric',month:'long',year:'numeric'})}, de ${meeting.start} a ${meeting.end}. Las demás fechas conservarán su estado.</p><form id="seriesRejectForm"><label class="standalone-label">Motivo del rechazo<textarea id="seriesRejectReason" rows="4" required></textarea></label><div class="form-actions"><button type="button" class="button secondary" id="backToSeries">Volver</button><button class="button primary">Confirmar rechazo</button></div></form>`);$('#backToSeries').onclick=()=>openSeriesApproval(meetingId);$('#seriesRejectForm').onsubmit=async event=>{event.preventDefault();const button=event.submitter;button.disabled=true;try{const{error}=await db.rpc('reject_occurrence',{p_occurrence_id:id,p_reason:$('#seriesRejectReason').value.trim(),p_apply_to_series:false});if(error)throw error;await refreshApplication();openSeriesApproval(meetingId);toast('La sesión fue rechazada.')}catch(error){button.disabled=false;toast(databaseMessage(error),true)}}}
async function approve(id){
  const m=meetings.find(x=>x.id===id);if(!m||!['pending','rejected'].includes(m.status))return;if(m.seriesId){openSeriesApproval(id);return}
  try{const{error}=await db.rpc('approve_occurrence',{p_occurrence_id:id,p_apply_to_series:false});if(error)throw error;await refreshApplication();toast(m.status==='rejected'?'Solicitud aprobada nuevamente.':'Solicitud aprobada correctamente.')}catch(error){toast(databaseMessage(error),true)}
}
function reject(id){
  const m=meetings.find(x=>x.id===id);openModal(`<h2>Rechazar ${m.seriesId?'serie':'solicitud'}</h2><p>Indica el motivo del rechazo${m.seriesId?' para todas las fechas de la serie':''}.</p><form id="rejectForm"><div class="form-grid"><label class="full">Motivo<textarea id="reason" rows="4" required></textarea></label></div><div class="form-actions"><button type="button" class="button secondary" id="back">Volver</button><button class="button primary">Confirmar rechazo</button></div></form>`);$('#back').onclick=closeModal;$('#rejectForm').onsubmit=async e=>{e.preventDefault();const button=e.submitter;button.disabled=true;try{const{error}=await db.rpc('reject_occurrence',{p_occurrence_id:id,p_reason:$('#reason').value,p_apply_to_series:Boolean(m.seriesId)});if(error)throw error;await refreshApplication();closeModal();toast(m.seriesId?'Serie rechazada.':'Solicitud rechazada.')}catch(error){button.disabled=false;toast(databaseMessage(error),true)}}
}
function reschedule(id){
  const meeting=meetings.find(item=>item.id===id);if(!meeting||!['pending','approved','rejected'].includes(meeting.status))return;if(!canModifyMeeting(meeting))return toast('El evento ya finalizó; no se puede cambiar su horario.',true);
  const members=seriesMembers(meeting).filter(item=>item.status!=='cancelled'),targetOptions=meeting.seriesId?`<label class="full">Aplicar el cambio<select id="rescheduleTarget"><option value="series">Todas las fechas activas de la serie</option>${members.map(item=>`<option value="${item.id}">Solo ${format(item.date,{weekday:'short',day:'numeric',month:'short',year:'numeric'})}</option>`).join('')}</select></label>`:'';
  openModal(`<h2>Cambiar horario${meeting.seriesId?' de reunión recurrente':''}</h2><p>Se conservarán la fecha, el salón y la configuración. El sistema comprobará nuevamente que el espacio esté disponible.</p><form id="rescheduleForm"><div class="form-grid">${targetOptions}<label>Hora de inicio<input id="rescheduleStart" type="time" value="${meeting.start}" required></label><label>Hora de finalización<input id="rescheduleEnd" type="time" value="${meeting.end}" required></label><label class="full">Motivo del cambio<input id="rescheduleReason" value="Cambio de horario por administración" required></label></div><div class="form-actions"><button type="button" class="button secondary" id="back">Cancelar</button><button class="button primary">Guardar horario</button></div></form>`);
  $('#back').onclick=closeModal;
  if(meeting.seriesId)$('#rescheduleTarget').onchange=event=>{const selected=event.target.value==='series'?meeting:meetings.find(item=>item.id===event.target.value);if(selected){$('#rescheduleStart').value=selected.start;$('#rescheduleEnd').value=selected.end}};
  $('#rescheduleForm').onsubmit=async event=>{event.preventDefault();const start=$('#rescheduleStart').value,end=$('#rescheduleEnd').value;if(start>=end)return toast('La hora final debe ser posterior a la hora de inicio.',true);const target=meeting.seriesId?$('#rescheduleTarget').value:meeting.id,applyToSeries=target==='series',targetId=applyToSeries?meeting.id:target,button=event.submitter;button.disabled=true;try{const{error}=await db.rpc('admin_reschedule_occurrence',{p_occurrence_id:targetId,p_start_time:`${start}:00`,p_end_time:`${end}:00`,p_reason:$('#rescheduleReason').value.trim(),p_apply_to_series:applyToSeries});if(error)throw error;await refreshApplication();closeModal();toast(applyToSeries?'Horario actualizado para toda la serie.':'Horario de la reunión actualizado.')}catch(error){button.disabled=false;toast(databaseMessage(error),true)}};
}
function move(id){
  const m=meetings.find(x=>x.id===id);if(!m||!['pending','approved','rejected'].includes(m.status))return;if(!canModifyMeeting(m))return toast('El evento ya finalizó; no se puede mover de salón.',true);
  const configurationId=configurationByName(m.configuration)?.id,options=spaces.filter(space=>space.capacities.has(Number(configurationId))).map(space=>`<option value="${space.id}" ${space.id===m.spaceId?'selected':''}>${safe(space.name)}</option>`).join('');
  openModal(`<h2>Cambiar espacio${m.seriesId?' de la serie':''}</h2><p>El cambio se aplicará ${m.seriesId?`a todas las fechas de la serie. `:''}El espacio combinado bloquea simultáneamente Salón 1 y Salón 2.</p><form id="moveForm"><div class="form-grid"><label class="full">Nuevo espacio<select id="newRoom">${options}</select></label><label class="full">Motivo del cambio<input id="moveReason" value="Cambio solicitado por administración"></label></div><div class="form-actions"><button type="button" class="button secondary" id="back">Cancelar</button><button class="button primary">Guardar cambio</button></div></form>`);$('#back').onclick=closeModal;$('#moveForm').onsubmit=async e=>{e.preventDefault();const button=e.submitter;button.disabled=true;try{const{error}=await db.rpc('move_occurrence',{p_occurrence_id:id,p_new_space_id:Number($('#newRoom').value),p_reason:$('#moveReason').value,p_apply_to_series:Boolean(m.seriesId)});if(error)throw error;await refreshApplication();closeModal();toast(m.seriesId?'Espacio actualizado para toda la serie.':'Espacio actualizado.')}catch(error){button.disabled=false;toast(databaseMessage(error),true)}}
}
function cancelAdminMeeting(id){
  const guardMeeting=meetings.find(x=>x.id===id);if(guardMeeting&&!canModifyMeeting(guardMeeting))return toast('El evento ya finalizó; no se puede cancelar.',true);
  const m=meetings.find(x=>x.id===id);if(!m||!['pending','approved'].includes(m.status)||currentUser.role!=='admin')return;const pending=m.status==='pending',subject=m.seriesId?(pending?'serie pendiente':'serie aprobada'):(pending?'solicitud pendiente':'reunión aprobada');openModal(`<h2>Cancelar ${subject}</h2><p>${m.seriesId?'Se cancelarán las fechas pendientes o aprobadas de esta serie.':pending?'La solicitud dejará de estar pendiente de aprobación.':'La reunión dejará de ocupar el salón y desaparecerá inmediatamente del tablero.'}</p><label>Motivo<input id="adminCancelReason" value="Cancelada por administración"></label><div class="form-actions"><button id="back" class="button secondary">Volver</button><button id="confirmAdminCancel" class="button primary">Confirmar cancelación</button></div>`);$('#back').onclick=closeModal;$('#confirmAdminCancel').onclick=async()=>{const button=$('#confirmAdminCancel');button.disabled=true;try{const{error}=await db.rpc('cancel_occurrence',{p_occurrence_id:id,p_reason:$('#adminCancelReason').value,p_apply_to_series:Boolean(m.seriesId)});if(error)throw error;await refreshApplication();closeModal();toast(m.seriesId?'Serie cancelada por el administrador.':'Reunión cancelada por el administrador.')}catch(error){button.disabled=false;toast(databaseMessage(error),true)}};
}

function updateMaintenanceTimeFields(){
  const allDay=$('#maintenanceAllDay').checked;$('#maintenanceStart').disabled=allDay;$('#maintenanceEnd').disabled=allDay;
}
function maintenanceOperationalStatus(item){if(item.status==='cancelled')return{key:'cancelled',label:'Cancelado'};const now=guatemalaNow(),endDate=item.endDate||item.date,finished=`${endDate}T${item.end}`<=`${now.date}T${now.time}`;return finished?{key:'finished',label:'Finalizado'}:{key:'scheduled',label:'Programado'}}
function startMaintenanceStatusClock(){clearInterval(maintenanceClockTimer);maintenanceClockTimer=null;if(currentUser?.role!=='admin')return;maintenanceClockTimer=setInterval(renderMaintenance,30000)}
function renderMaintenance(){
  const active=maintenances.filter(item=>maintenanceOperationalStatus(item).key==='scheduled'),items=[...maintenances].sort((a,b)=>(a.date+a.start).localeCompare(b.date+b.start));
  $('#maintenanceCount').textContent=active.length;$('#maintenanceActiveBadge').textContent=`${active.length} ${active.length===1?'activo':'activos'}`;
  $('#maintenanceRows').innerHTML=items.length?items.map(item=>{const state=maintenanceOperationalStatus(item),action=state.key==='scheduled'?`<button class="mini danger" data-cancel-maintenance="${item.id}">Cancelar</button>`:'';return`<tr><td><b>${roomById(item.roomId)?.name||'Salón'}</b><small>${roomById(item.roomId)?.floor||''}</small></td><td>${format(item.date,{day:'numeric',month:'short',year:'numeric'})}</td><td>${item.allDay?'Todo el día':`${item.start}–${item.end}`}</td><td>${safe(item.reason)}</td><td><div class="maintenance-status-cell"><span class="badge maintenance-${state.key}">${state.label}</span>${action}</div></td></tr>`}).join(''):'<tr><td colspan="5" class="empty">No hay mantenimientos registrados.</td></tr>';
  $$('[data-cancel-maintenance]').forEach(button=>button.onclick=()=>cancelMaintenance(button.dataset.cancelMaintenance));
}
function incidentSummary(items){return items.length?items.map(item=>`<span class="incident-chip incident-${item.type}">${safe(incidentText(item))}${item.type==='missing_equipment'&&item.itemName?`: ${safe(item.itemName)}`:''}</span>`).join(''):'<span class="incident-empty">Sin incidencias registradas</span>'}
function renderIncidents(){
  const admin=$('#incidentsAdminView'),user=$('#incidentsUserView');if(!admin||!user)return;
  const isAdmin=currentUser?.role==='admin';admin.classList.toggle('hidden',!isAdmin);user.classList.toggle('hidden',isAdmin);$$('[data-incident-count]').forEach(element=>element.textContent=incidents.length);
  if(isAdmin){
    $('#incidentAdminCount').textContent=`${incidents.length} ${incidents.length===1?'reunión':'reuniones'}`;
    $('#incidentAdminRows').innerHTML=incidents.length?incidents.map(item=>{const state=incidentStatus(item);return`<tr><td><b>${safe(item.title)}</b><small>${safe(item.organization)}</small></td><td>${safe(item.owner)}</td><td><b>${safe(item.spaceName)}</b><small>${safe(item.configuration)} · ${item.attendees} personas</small></td><td><b>${format(item.date,{day:'numeric',month:'short',year:'numeric'})}</b><small>${item.start}–${item.end}</small></td><td><span class="badge incident-meeting-${state.key}">${state.label}</span></td><td><div class="incident-chip-list">${incidentSummary(item.items)}</div></td><td><button class="mini" data-incident-edit="${item.occurrenceId}">${item.items.length?'Editar':'Registrar'}</button></td></tr>`}).join(''):'<tr><td colspan="7" class="empty">No hay reuniones aprobadas para revisar.</td></tr>';
    $$('[data-incident-edit]').forEach(button=>button.onclick=()=>openIncidentEditor(button.dataset.incidentEdit));
  }else{
    $('#incidentCards').innerHTML=incidents.length?incidents.map(item=>{const state=incidentStatus(item);return`<button type="button" class="panel incident-card" data-incident-detail="${item.occurrenceId}"><span class="incident-card-head"><span><b>${safe(item.title)}</b><small>${safe(item.organization)}</small></span><span class="badge incident-meeting-${state.key}">${state.label}</span></span><span class="incident-card-meta"><span>${safe(item.spaceName)}</span><span>${format(item.date,{day:'numeric',month:'short',year:'numeric'})} · ${item.start}–${item.end}</span></span><span class="incident-chip-list">${incidentSummary(item.items)}</span><small class="incident-card-hint">Selecciona para ver el detalle</small></button>`}).join(''):'<article class="panel incident-empty-state"><b>No tienes reuniones aprobadas para revisar.</b><span>Las incidencias registradas aparecerán aquí.</span></article>';
    $$('[data-incident-detail]').forEach(button=>button.onclick=()=>openIncidentDetails(button.dataset.incidentDetail));
  }
}
function openIncidentEditor(occurrenceId){
  const item=incidents.find(candidate=>candidate.occurrenceId===String(occurrenceId));if(!item)return;
  const hasScreen=item.items.some(incident=>incident.type==='screen_on'),hasAir=item.items.some(incident=>incident.type==='ac_unattended'),missing=item.items.filter(incident=>incident.type==='missing_equipment').map(incident=>incident.itemName).filter(Boolean),existingNotes=item.items.find(incident=>incident.notes)?.notes||'';
  openModal(`<h2>Incidencias · ${safe(item.title)}</h2><p>${safe(item.spaceName)} · ${format(item.date,{day:'numeric',month:'long',year:'numeric'})} · ${item.start}–${item.end}</p><form id="incidentForm"><fieldset class="incident-options"><legend>Selecciona las incidencias encontradas</legend><label><input id="incidentScreen" type="checkbox" ${hasScreen?'checked':''}> Pantalla de salón encendida</label><label><input id="incidentAir" type="checkbox" ${hasAir?'checked':''}> Aire acondicionado encendido sin personal en el salón</label><label><input id="incidentMissing" type="checkbox" ${missing.length?'checked':''}> Equipo faltante</label></fieldset><label class="standalone-label">Equipos faltantes<textarea id="incidentMissingItems" rows="4" placeholder="Un equipo por línea, por ejemplo: Cable HDMI&#10;Control remoto" ${missing.length?'':'disabled'}>${safe(missing.join('\n'))}</textarea><small class="help">Escribe un equipo por línea o separado por comas.</small></label><label class="standalone-label">Observación adicional<textarea id="incidentNotes" rows="3" placeholder="Detalle opcional de la incidencia">${safe(existingNotes)}</textarea></label><p id="incidentFormError" class="form-error"></p><div class="form-actions"><button type="button" id="incidentBack" class="button secondary">Cancelar</button><button class="button primary" type="submit">Guardar incidencias</button></div></form>`,true);
  $('#incidentBack').onclick=closeModal;$('#incidentMissing').onchange=()=>{$('#incidentMissingItems').disabled=!$('#incidentMissing').checked;if(!$('#incidentMissing').checked)$('#incidentMissingItems').value=''};$('#incidentForm').onsubmit=async event=>{event.preventDefault();const errorBox=$('#incidentFormError'),notes=$('#incidentNotes').value.trim(),payload=[];if($('#incidentScreen').checked)payload.push({type:'screen_on',notes});if($('#incidentAir').checked)payload.push({type:'ac_unattended',notes});if($('#incidentMissing').checked){const names=[...new Set($('#incidentMissingItems').value.split(/[\n,]+/).map(value=>value.trim()).filter(Boolean))];if(!names.length){errorBox.textContent='Especifica al menos un equipo faltante.';return}names.forEach(itemName=>payload.push({type:'missing_equipment',itemName,notes}))}const button=event.submitter;button.disabled=true;errorBox.textContent='';try{const{error}=await db.rpc('admin_save_meeting_incidents',{p_occurrence_id:String(occurrenceId),p_incidents:payload});if(error)throw error;closeModal();await refreshApplication();toast(payload.length?`Incidencias guardadas para ${item.spaceName}.`:'Incidencias eliminadas para esta reunión.')}catch(error){button.disabled=false;errorBox.textContent=databaseMessage(error)}};
}
function openIncidentDetails(occurrenceId){
  const item=incidents.find(candidate=>candidate.occurrenceId===String(occurrenceId));if(!item)return;const state=incidentStatus(item),list=item.items.length?item.items.map(incident=>`<div><span>INCIDENCIA</span><b>${safe(incidentText(incident))}${incident.type==='missing_equipment'&&incident.itemName?` · ${safe(incident.itemName)}`:''}</b>${incident.notes?`<small>${safe(incident.notes)}</small>`:''}</div>`).join(''):'<p class="muted">No se han registrado incidencias para esta reunión.</p>';
  openModal(`<span class="badge incident-meeting-${state.key}">${state.label}</span><h2 style="margin-top:14px">${safe(item.title)}</h2><p>${safe(item.spaceName)} · ${format(item.date,{day:'numeric',month:'long',year:'numeric'})} · ${item.start}–${item.end}</p><div class="details"><div><span>SALÓN</span><b>${safe(item.spaceName)}</b></div><div><span>ORGANIZACIÓN</span><b>${safe(item.organization)}</b></div><div><span>INCIDENCIAS</span><b>${item.items.length}</b></div></div><div class="incident-detail-list">${list}</div>`);
}
async function cancelMaintenance(id){
  const maintenance=maintenances.find(item=>item.id===id);if(!maintenance||maintenanceOperationalStatus(maintenance).key!=='scheduled')return toast('El mantenimiento ya finalizó o fue cancelado; solo puede consultarse.',true);try{const{error}=await db.rpc('cancel_maintenance',{p_maintenance_id:id,p_reason:'Cancelado desde el panel'});if(error)throw error;await refreshApplication();toast('El aviso fue cancelado y el salón volvió a estar disponible.')}catch(error){toast(databaseMessage(error),true)}
}
async function scheduleMaintenance(event){
  event.preventDefault();const allDay=$('#maintenanceAllDay').checked,start=allDay?'00:00':$('#maintenanceStart').value,end=allDay?'23:59':$('#maintenanceEnd').value;
  if(!allDay&&start>=end)return toast('La hora final debe ser posterior a la hora de inicio.',true);
  const button=event.submitter;button.disabled=true;const date=$('#maintenanceDate').value;
  try{const{error}=await db.rpc('schedule_maintenance',{p_room_id:Number($('#maintenanceRoom').value),p_starts_at:toDatabaseTimestamp(date,start),p_ends_at:toDatabaseTimestamp(date,end),p_reason:$('#maintenanceReason').value.trim(),p_all_day:allDay,p_title:'Mantenimiento'});if(error)throw error;event.target.reset();setTimeout(()=>{$('#maintenanceDate').value=key(new Date());updateMaintenanceTimeFields()},0);await refreshApplication();toast('Aviso publicado; el tablero fue actualizado.')}catch(error){toast(databaseMessage(error),true)}finally{button.disabled=false}
}

const userStatusNames={pending:'Pendiente',active:'Activo',rejected:'Rechazado',deleted:'Eliminado'};
function userDate(value){return value?new Intl.DateTimeFormat('es-GT',{day:'numeric',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}).format(new Date(value)):'—'}
function userActions(user){
  if(user.account_status==='pending')return`<button class="mini danger" data-user-reject="${user.user_id}">Rechazar</button><button class="mini approve-action" data-user-approve="${user.user_id}">Aprobar</button>`;
  if(user.account_status==='rejected')return`<button class="mini approve-action" data-user-approve="${user.user_id}">Aprobar</button>`;
  if(user.account_status==='deleted')return`<button class="mini approve-action" data-user-restore="${user.user_id}">Restaurar</button>`;
  const passwordAction=`<button class="mini" data-user-password="${user.user_id}">Cambiar contraseña</button>`;
  if(user.user_id===currentUser.id)return`${passwordAction}<small>Sesión actual</small>`;
  return`${passwordAction}<button class="mini" data-user-role="${user.user_id}">Cambiar rol</button><button class="mini danger" data-user-delete="${user.user_id}">Eliminar</button>`;
}
function userGroupMarkup(title,items){
  const rows=items.length?items.map(user=>`<tr><td><div class="user-identity"><span class="avatar">${safe(initials(user.full_name))}</span><div><b>${safe(user.full_name)}</b><small>${safe(user.email||'Sin correo')}</small></div></div></td><td><b>${safe(user.organization_name||'Sin organización')}</b><small>${safe(user.department_name||'Sin departamento')}</small></td><td><span class="role-pill ${user.role}">${user.role==='admin'?'Administrador':'Usuario estándar'}</span></td><td><span class="account-status ${user.account_status}">${userStatusNames[user.account_status]}</span><small>${safe(user.status_reason||'')}</small></td><td><b>${userDate(user.created_at)}</b><small>${user.reviewed_at?`Gestionado: ${userDate(user.reviewed_at)}`:'Sin gestionar'}</small></td><td><div class="user-actions">${userActions(user)}</div></td></tr>`).join(''):'<tr><td colspan="6" class="empty">No hay usuarios en esta categoría.</td></tr>';
  return`<section class="user-group"><header><h3>${title}</h3><span>${items.length} ${items.length===1?'cuenta':'cuentas'}</span></header><div class="table-wrap"><table class="user-table"><thead><tr><th>USUARIO</th><th>ORGANIZACIÓN / ÁREA</th><th>ROL</th><th>ESTADO</th><th>FECHAS</th><th></th></tr></thead><tbody>${rows}</tbody></table></div></section>`;
}
function bindUserActions(){
  const root=$('#userGroups');$$('[data-user-approve]',root).forEach(button=>button.onclick=()=>openUserApproval(button.dataset.userApprove));$$('[data-user-reject]',root).forEach(button=>button.onclick=()=>openUserRejection(button.dataset.userReject));$$('[data-user-password]',root).forEach(button=>button.onclick=()=>openUserPassword(button.dataset.userPassword));$$('[data-user-role]',root).forEach(button=>button.onclick=()=>openUserRole(button.dataset.userRole));$$('[data-user-delete]',root).forEach(button=>button.onclick=()=>openUserDeletion(button.dataset.userDelete));$$('[data-user-restore]',root).forEach(button=>button.onclick=()=>void restoreUser(button.dataset.userRestore));
}
function renderUsers(){
  if(currentUser?.role!=='admin')return;
  if(!portalUsersLoadedAt||Date.now()-portalUsersLoadedAt>30000){
    $('#userGroups').innerHTML='<p class="empty">Cargando usuarios de Módulos…</p>';
    void loadPortalUsers();return;
  }
  const query=$('#portalUserSearch').value.trim().toLocaleLowerCase('es');
  const filtered=portalUsers.filter(user=>`${user.name} ${user.email}`.toLocaleLowerCase('es').includes(query));
  const labels={admin:'Administrador',manager:'Gestor',user:'Usuario estándar'};
  const rows=filtered.map(user=>`<tr><td><div class="user-identity"><span class="avatar">${safe(initials(user.name))}</span><div><b>${safe(user.name)}</b><small>${safe(user.email)}</small></div></div></td><td><span class="role-pill ${safe(user.role||'user')}">${safe(labels[user.role]||'Sin acceso')}</span></td><td>${user.source==='superadmin'?'Superadministrador de Módulos':user.source==='inherited'?'Heredado del portal':user.source==='assigned'?'Asignado en CengiSalas':'Sin asignar'}</td><td>${user.locked?'<small>Rol protegido</small>':`<button class="mini" data-portal-role="${user.id}">Cambiar rol</button>`}</td></tr>`).join('');
  $('#userGroups').innerHTML=`<div class="table-wrap"><table class="user-table"><thead><tr><th>USUARIO DEL PORTAL</th><th>ROL EN CENGISALAS</th><th>ORIGEN</th><th>ACCIÓN</th></tr></thead><tbody>${rows||'<tr><td colspan="4" class="empty">No hay usuarios que coincidan.</td></tr>'}</tbody></table></div>`;
  $$('[data-portal-role]',$('#userGroups')).forEach(button=>button.onclick=()=>void openPermissionsManager(Number(button.dataset.portalRole)));
}
async function loadPortalUsers(force=false){
  if(portalUsersLoading)return portalUsersLoading;
  if(!force&&portalUsersLoadedAt&&Date.now()-portalUsersLoadedAt<30000)return renderUsers();
  portalUsersLoading=(async()=>{
    try{
      const response=await fetch('../login/cengisalas_roles_api.php',{credentials:'same-origin',cache:'no-store'});
      const result=await response.json();if(!response.ok)throw new Error(result.error||'No se pudo consultar el portal.');
      portalUsers=result.users||[];portalUsersLoadedAt=Date.now();renderUsers();
    }catch(error){$('#userGroups').innerHTML=`<p class="empty">${safe(databaseMessage(error))}</p>`}
    finally{portalUsersLoading=null}
  })();
  return portalUsersLoading;
}
function organizationOptions(selectedId){return organizations.map(item=>`<option value="${item.id}" ${Number(selectedId)===Number(item.id)?'selected':''}>${safe(item.name)}</option>`).join('')}
function departmentOptions(organizationId,selectedId=null){return'<option value="">Sin departamento</option>'+departments.filter(item=>Number(item.organization_id)===Number(organizationId)).map(item=>`<option value="${item.id}" ${Number(selectedId)===Number(item.id)?'selected':''}>${safe(item.name)}</option>`).join('')}
function openUserApproval(id){
  const user=adminUsers.find(item=>item.user_id===id);if(!user)return;const defaultOrganization=user.organization_id||organizations.find(item=>item.code==='CENGICANA')?.id||organizations[0]?.id;
  openModal(`<span class="account-status ${user.account_status}">${userStatusNames[user.account_status]}</span><h2 style="margin-top:12px">Aprobar usuario</h2><p>${safe(user.full_name)} · ${safe(user.email)}</p><form id="approveUserForm"><label class="standalone-label">Rol<select id="approveUserRole"><option value="user" ${user.role==='user'?'selected':''}>Usuario estándar</option><option value="admin" ${user.role==='admin'?'selected':''}>Administrador</option></select></label><label class="standalone-label">Organización<select id="approveUserOrganization">${organizationOptions(defaultOrganization)}</select></label><label class="standalone-label">Departamento<select id="approveUserDepartment">${departmentOptions(defaultOrganization,user.department_id)}</select></label><div class="form-actions"><button type="button" id="back" class="button secondary">Cancelar</button><button class="button primary">Aprobar cuenta</button></div></form>`);
  $('#back').onclick=closeModal;$('#approveUserOrganization').onchange=event=>$('#approveUserDepartment').innerHTML=departmentOptions(event.target.value);
  $('#approveUserForm').onsubmit=async event=>{event.preventDefault();const button=event.submitter;button.disabled=true;try{const department=$('#approveUserDepartment').value,{error}=await db.rpc('admin_approve_user',{p_user_id:id,p_role:$('#approveUserRole').value,p_organization_id:Number($('#approveUserOrganization').value),p_department_id:department?Number(department):null});if(error)throw error;await refreshApplication();closeModal();toast('Usuario aprobado correctamente.')}catch(error){button.disabled=false;toast(databaseMessage(error),true)}};
}
function openUserRejection(id){
  const user=adminUsers.find(item=>item.user_id===id);if(!user)return;openModal(`<h2>Rechazar solicitud</h2><p>${safe(user.full_name)} · ${safe(user.email)}</p><form id="rejectUserForm"><label class="standalone-label">Motivo<textarea id="rejectUserReason" rows="4" required></textarea></label><div class="form-actions"><button type="button" id="back" class="button secondary">Cancelar</button><button class="button primary">Confirmar rechazo</button></div></form>`);$('#back').onclick=closeModal;$('#rejectUserForm').onsubmit=async event=>{event.preventDefault();const button=event.submitter;button.disabled=true;try{const{error}=await db.rpc('admin_reject_user',{p_user_id:id,p_reason:$('#rejectUserReason').value});if(error)throw error;await refreshApplication();closeModal();toast('Solicitud rechazada.')}catch(error){button.disabled=false;toast(databaseMessage(error),true)}};
}
function openUserPassword(id){
  const user=adminUsers.find(item=>item.user_id===id);if(!user||user.account_status!=='active')return;
  openModal(`<h2>Cambiar contraseña</h2><p>Asignarás una nueva contraseña a <b>${safe(user.full_name)}</b> · ${safe(user.email)}.</p><form id="userPasswordForm"><label class="standalone-label">Nueva contraseña<input id="userNewPassword" type="password" minlength="8" maxlength="72" autocomplete="new-password" required><small>Debe contener entre 8 y 72 caracteres.</small></label><label class="standalone-label">Confirmar contraseña<input id="userConfirmPassword" type="password" minlength="8" maxlength="72" autocomplete="new-password" required></label><label class="password-visibility"><input id="showUserPassword" type="checkbox"> Mostrar contraseñas</label><div class="form-actions"><button type="button" id="back" class="button secondary">Cancelar</button><button class="button primary">Guardar contraseña</button></div></form>`);
  $('#back').onclick=closeModal;$('#showUserPassword').onchange=event=>{$('#userNewPassword').type=event.target.checked?'text':'password';$('#userConfirmPassword').type=event.target.checked?'text':'password'};
  $('#userPasswordForm').onsubmit=async event=>{event.preventDefault();const password=$('#userNewPassword').value,confirmation=$('#userConfirmPassword').value;if(password.length<8||password.length>72)return toast('La contraseña debe contener entre 8 y 72 caracteres.',true);if(password!==confirmation)return toast('Las contraseñas no coinciden.',true);const button=event.submitter;button.disabled=true;try{const{data,error}=await db.functions.invoke('admin-set-password',{body:{userId:id,password}});if(error){let message=error.message;try{const details=await error.context?.json();message=details?.error||details?.message||message}catch{}throw new Error(message)}if(!data?.success)throw new Error(data?.error||'No fue posible cambiar la contraseña.');closeModal();toast(`Contraseña actualizada para ${user.full_name}.`)}catch(error){button.disabled=false;toast(databaseMessage(error),true)}};
}
function openUserRole(id){
  const user=adminUsers.find(item=>item.user_id===id);if(!user||user.account_status!=='active'||user.user_id===currentUser.id)return;const nextRole=user.role==='admin'?'user':'admin',nextLabel=nextRole==='admin'?'Administrador':'Usuario estándar';
  openModal(`<span class="role-pill ${user.role}">${user.role==='admin'?'Administrador':'Usuario estándar'}</span><h2 style="margin-top:12px">Cambiar rol</h2><p>Cambiarás la cuenta de <b>${safe(user.full_name)}</b> · ${safe(user.email)} a <b>${nextLabel}</b>.</p><form id="userRoleForm"><label class="standalone-label">Nuevo rol<select id="userNewRole"><option value="user" ${nextRole==='user'?'selected':''}>Usuario estándar</option><option value="admin" ${nextRole==='admin'?'selected':''}>Administrador</option></select></label><p class="muted">El cambio se aplicará inmediatamente y modificará los permisos disponibles para esta cuenta.</p><div class="form-actions"><button type="button" id="back" class="button secondary">Cancelar</button><button class="button primary">Guardar rol</button></div></form>`);
  $('#back').onclick=closeModal;$('#userRoleForm').onsubmit=async event=>{event.preventDefault();const button=event.submitter;button.disabled=true;try{const{error}=await db.rpc('admin_change_user_role',{p_user_id:id,p_role:$('#userNewRole').value});if(error)throw error;await refreshApplication();closeModal();toast(`Rol actualizado para ${user.full_name}.`)}catch(error){button.disabled=false;toast(databaseMessage(error),true)}};
}
function openUserDeletion(id){
  const user=adminUsers.find(item=>item.user_id===id);if(!user)return;openModal(`<h2>Eliminar usuario</h2><p>La cuenta de <b>${safe(user.full_name)}</b> se desactivará, pero su historial permanecerá en el sistema.</p><form id="deleteUserForm"><label class="standalone-label">Motivo<input id="deleteUserReason" value="Cuenta desactivada por administración" required></label><div class="form-actions"><button type="button" id="back" class="button secondary">Cancelar</button><button class="button primary">Eliminar lógicamente</button></div></form>`);$('#back').onclick=closeModal;$('#deleteUserForm').onsubmit=async event=>{event.preventDefault();const button=event.submitter;button.disabled=true;try{const{error}=await db.rpc('admin_delete_user_logically',{p_user_id:id,p_reason:$('#deleteUserReason').value});if(error)throw error;await refreshApplication();closeModal();toast('Usuario eliminado lógicamente.')}catch(error){button.disabled=false;toast(databaseMessage(error),true)}};
}
async function restoreUser(id){try{const{error}=await db.rpc('admin_restore_user',{p_user_id:id});if(error)throw error;await refreshApplication();toast('Usuario restaurado correctamente.')}catch(error){toast(databaseMessage(error),true)}}
function organizationCode(name){return name.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toUpperCase().replace(/[^A-Z0-9]+/g,'_').replace(/^_+|_+$/g,'').slice(0,30)}
function openOrganizationManager(){
  const rows=organizations.map(item=>`<li><span><b>${safe(item.name)}</b><small>${safe(item.code)}</small></span><i class="account-status ${item.is_active?'active':'deleted'}">${item.is_active?'Activa':'Inactiva'}</i></li>`).join('');
  openModal(`<h2>Instituciones</h2><p>Estas opciones estarán disponibles en el registro de nuevas cuentas.</p><ul class="organization-list">${rows||'<li class="empty">No hay instituciones registradas.</li>'}</ul><form id="organizationForm"><h3>Agregar institución</h3><label class="standalone-label">Nombre<input id="organizationName" minlength="2" required placeholder="Ej. Nueva institución"></label><label class="standalone-label">Código interno<input id="organizationCode" maxlength="30" required placeholder="NUEVA_INSTITUCION"><small>Se genera automáticamente y debe ser único.</small></label><div class="form-actions"><button type="button" id="back" class="button secondary">Cerrar</button><button class="button primary">Agregar institución</button></div></form>`);
  $('#back').onclick=closeModal;let codeEdited=false;$('#organizationCode').oninput=()=>codeEdited=true;$('#organizationName').oninput=event=>{if(!codeEdited)$('#organizationCode').value=organizationCode(event.target.value)};
  $('#organizationForm').onsubmit=async event=>{event.preventDefault();const button=event.submitter;button.disabled=true;try{const{error}=await db.rpc('admin_create_organization',{p_name:$('#organizationName').value,p_code:$('#organizationCode').value});if(error)throw error;await loadCatalogs();await loadRegistrationOrganizations();openOrganizationManager();toast('Institución agregada correctamente.')}catch(error){button.disabled=false;toast(databaseMessage(error),true)}};
}
async function openPermissionsManager(initialUserId=null){
  if(currentUser?.role!=='admin')return toast('Solo un administrador de CengiSalas puede asignar roles.',true);
  let users,csrf;
  try{
    const response=await fetch('../login/cengisalas_roles_api.php',{credentials:'same-origin',cache:'no-store'});
    const result=await response.json();
    if(!response.ok)throw new Error(result.error||'No fue posible cargar los usuarios del portal.');
    users=result.users||[];csrf=result.csrf;
  }catch(error){return toast(databaseMessage(error),true)}
  if(!users.length)return toast('No hay usuarios del portal para configurar.',true);
  const roleOptions=[
    {code:'user',name:'Usuario estándar',icon:'person',detail:'Principal: agenda de hoy, solicitar salón, mis reuniones y tablero de salones.'},
    {code:'manager',name:'Gestor',icon:'work',detail:'Todas las opciones del usuario estándar, más Solicitudes pago y Reportería.'},
    {code:'admin',name:'Administrador',icon:'admin_panel_settings',detail:'Todas las opciones de Principal y Administración de CengiSalas.'}
  ];
  const userOptions=users.map(user=>`<option value="${user.id}">${safe(user.name)} · ${safe(user.email)}</option>`).join('');
  openModal(`<div class="permission-manager"><div class="permission-manager-heading"><div><p class="overline blue">CENGISALAS</p><h2>Roles y permisos</h2><p>Elige un usuario registrado en Módulos y asigna uno de los tres perfiles. Esta configuración no cambia sus permisos de Visitas ni de otros módulos.</p></div></div><label class="standalone-label">Usuario del portal<select id="permissionUserSelect">${userOptions}</select></label><p id="permissionCurrentRole" class="muted"></p><div id="permissionRoleOptions" class="salas-role-options"></div><p id="permissionFormError" class="form-error"></p><div class="form-actions"><button type="button" id="permissionBack" class="button secondary">Cerrar</button><button type="button" id="permissionSave" class="button primary">Guardar rol</button></div></div>`,true);
  if(initialUserId&&users.some(user=>user.id===Number(initialUserId)))$('#permissionUserSelect').value=String(initialUserId);
  const renderSelection=()=>{
    const user=users.find(item=>item.id===Number($('#permissionUserSelect').value));
    if(!user)return;
    $('#permissionCurrentRole').textContent=user.source==='superadmin'?'Superadministrador de Módulos: mantiene acceso completo.':user.role?`Rol actual: ${roleOptions.find(item=>item.code===user.role)?.name||user.role}${user.source==='inherited'?' (heredado del portal)':''}.`:'Sin acceso asignado a CengiSalas.';
    $('#permissionRoleOptions').innerHTML=roleOptions.map(option=>`<label class="salas-role-option"><input type="radio" name="salasRole" value="${option.code}" ${user.role===option.code?'checked':''} ${user.locked?'disabled':''}><span class="material-symbols-outlined" aria-hidden="true">${option.icon}</span><span><b>${option.name}</b><small>${option.detail}</small></span></label>`).join('');
    $('#permissionSave').disabled=Boolean(user.locked);
    $('#permissionFormError').textContent=user.locked?'Esta cuenta administra Módulos o es tu sesión actual; su rol no se modifica aquí.':'';
  };
  $('#permissionUserSelect').onchange=renderSelection;
  $('#permissionBack').onclick=closeModal;
  renderSelection();
  $('#permissionSave').onclick=async()=>{
    const user=users.find(item=>item.id===Number($('#permissionUserSelect').value));
    const role=$('input[name="salasRole"]:checked')?.value;
    if(!user||user.locked||!role)return $('#permissionFormError').textContent='Selecciona un usuario y un rol.';
    const button=$('#permissionSave');button.disabled=true;$('#permissionFormError').textContent='';
    try{
      const response=await fetch('../login/cengisalas_roles_api.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({user_id:user.id,role})});
      const result=await response.json();if(!response.ok||!result.ok)throw new Error(result.error||'No se pudo guardar el rol.');
      user.role=role;user.source='assigned';portalUsers=users;portalUsersLoadedAt=Date.now();renderUsers();renderSelection();toast(`Rol ${roleOptions.find(item=>item.code===role)?.name} asignado a ${user.name}.`);
    }catch(error){button.disabled=false;$('#permissionFormError').textContent=databaseMessage(error)}
  };
}
async function openPortalCreateUser(){
  if(currentUser?.role!=='admin')return toast('Solo un administrador de CengiSalas puede crear usuarios.',true);
  let csrf;
  try{
    const response=await fetch('../login/cengisalas_roles_api.php',{credentials:'same-origin',cache:'no-store'});
    const result=await response.json();if(!response.ok)throw new Error(result.error||'No fue posible preparar el formulario.');
    csrf=result.csrf;
  }catch(error){return toast(databaseMessage(error),true)}
  const activeOrganizations=organizations.filter(item=>item.is_active);
  if(!activeOrganizations.length)return toast('No hay instituciones activas para asignar.',true);
  const defaultOrganization=activeOrganizations.find(item=>item.code==='CENGICANA')||activeOrganizations[0];
  const organizationChoices=activeOrganizations.map(item=>`<option value="${item.id}" ${item.id===defaultOrganization.id?'selected':''}>${safe(item.name)}</option>`).join('');
  const departmentChoices=organizationId=>'<option value="">Sin departamento</option>'+departments.filter(item=>Number(item.organization_id)===Number(organizationId)&&item.is_active).map(item=>`<option value="${item.id}">${safe(item.name)}</option>`).join('');
  openModal(`<div class="permission-manager"><div class="permission-manager-heading"><div><p class="overline blue">CENGISALAS</p><h2>Crear usuario</h2><p>La cuenta se registrará en Módulos con acceso solo a CengiSalas. Un superadministrador podrá habilitar otros módulos posteriormente.</p></div></div><form id="portalCreateForm"><div class="form-grid"><label>Nombre completo<input id="portalNewName" maxlength="120" minlength="3" autocomplete="name" required></label><label>Correo electrónico<input id="portalNewEmail" type="email" maxlength="120" autocomplete="email" required></label><label>Contraseña inicial<input id="portalNewPassword" type="password" minlength="8" maxlength="72" autocomplete="new-password" required></label><label>Confirmar contraseña<input id="portalNewConfirm" type="password" minlength="8" maxlength="72" autocomplete="new-password" required></label><label>Rol en CengiSalas<select id="portalNewRole" required><option value="user">Usuario estándar</option><option value="manager">Gestor</option><option value="admin">Administrador</option></select></label><label>Institución<select id="portalNewOrganization" required>${organizationChoices}</select></label><label>Departamento<select id="portalNewDepartment">${departmentChoices(defaultOrganization.id)}</select></label></div><p class="muted">La contraseña permitirá ingresar al portal de Módulos. No se enviará por correo automáticamente.</p><p id="portalCreateError" class="form-error" role="alert"></p><div class="form-actions"><button type="button" id="portalCreateBack" class="button secondary">Cancelar</button><button class="button primary" type="submit">Crear usuario</button></div></form></div>`,true);
  $('#portalCreateBack').onclick=closeModal;
  $('#portalNewOrganization').onchange=event=>$('#portalNewDepartment').innerHTML=departmentChoices(event.target.value);
  $('#portalCreateForm').onsubmit=async event=>{
    event.preventDefault();
    if($('#portalNewPassword').value!==$('#portalNewConfirm').value){$('#portalCreateError').textContent='Las contraseñas no coinciden.';return}
    const button=event.submitter;button.disabled=true;$('#portalCreateError').textContent='';
    const name=$('#portalNewName').value.trim();
    try{
      const response=await fetch('../login/cengisalas_roles_api.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({action:'create',name,email:$('#portalNewEmail').value.trim(),password:$('#portalNewPassword').value,role:$('#portalNewRole').value,organization_id:Number($('#portalNewOrganization').value),department_id:$('#portalNewDepartment').value||null})});
      const result=await response.json();if(!response.ok||!result.ok)throw new Error(result.error||'No fue posible crear el usuario.');
      $('#portalUserSearch').value='';await loadPortalUsers(true);closeModal();toast(`${name} ya puede ingresar a CengiSalas desde Módulos.`);
    }catch(error){button.disabled=false;$('#portalCreateError').textContent=databaseMessage(error)}
  };
}
function renderRooms(){
  const combined=spaces.find(space=>space.type==='combined'),u=configurationTypes.find(type=>type.code==='U'),school=configurationTypes.find(type=>type.code==='SCHOOL'),combinedCard=combined?`<article class="panel room-card combined-room-card"><div class="room-visual"><b>${safe(combined.name)}</b></div><div class="room-info"><div><span>⌖ Espacio combinado</span><span>● Capacidad calculada</span></div><div class="capacity"><span>TIPO U<b>${combined.capacities.get(Number(u?.id))||0} personas</b></span><span>TIPO ESCUELA<b>${combined.capacities.get(Number(school?.id))||0} personas</b></span></div><small class="room-edit-hint">Se actualiza al modificar los salones 1 o 2.</small></div></article>`:'';
  const labels={'Tipo U':'TIPO U','Tipo Escuela':'TIPO ESCUELA','Tipo Juntas':'TIPO JUNTAS'},fields={'Tipo U':'u','Tipo Escuela':'school','Tipo Juntas':'boardroom'};
  $('#roomCards').innerHTML=rooms.map(r=>{const allowed=allowedConfigurationNames(r),capacities=allowed.map(name=>`<span${allowed.length===1?' class="capacity-only"':''}>${labels[name]}<b>${r[fields[name]]} personas</b></span>`).join(''),tariffs=r.allowsUsageCost?`<div class="room-tariffs"><span>1 hora<b>${money(r.costOneHour)}</b></span><span>4 a 8 horas<b>${money(r.costFourToEight)}</b></span><span>Hora extra<b>${money(r.costExtraHour)}</b></span></div>`:`<div class="room-no-cost">Tarifa de uso no aplicable</div>`;return`<button type="button" class="panel room-card room-card-button" data-room-edit="${r.id}"><span class="room-visual"><b>${safe(r.name)}</b></span><span class="room-info"><span class="room-meta"><span>⌖ ${safe(r.floor)}</span><span>● Activo</span></span><span class="capacity${allowed.length===3?' capacity-three':''}">${capacities}</span><span class="room-energy">⚡ Consumo por hora <b>${r.hourlyConsumptionKwh.toFixed(2)} kWh</b></span>${tariffs}<small class="room-edit-hint">Seleccionar para editar información</small></span></button>`}).join('')+combinedCard;
  $$('[data-room-edit]',$('#roomCards')).forEach(button=>button.onclick=()=>openRoomEditor(button.dataset.roomEdit));
}

function openRoomEditor(id){
  if(currentUser?.role!=='admin')return;
  const room=roomById(id);if(!room)return;
  const labels={'Tipo U':'Tipo U','Tipo Escuela':'Tipo Escuela','Tipo Juntas':'Tipo Juntas'},fields={'Tipo U':'u','Tipo Escuela':'school','Tipo Juntas':'boardroom'},capacityInputs=allowedConfigurationNames(room).map(name=>{const configuration=configurationByName(name);return`<label>${labels[name]}<input type="number" min="1" step="1" required value="${room[fields[name]]}" data-room-capacity="${safe(configuration.code)}"><small>Capacidad máxima de personas</small></label>`}).join('');
  const pricing=room.allowsUsageCost?`<fieldset class="room-cost-editor"><legend>Tarifas de uso en quetzales</legend><div class="form-grid"><label>Costo por 1 hora<input id="roomCostOneHour" type="number" min="0" step="0.01" required value="${Number(room.costOneHour||0).toFixed(2)}"></label><label>Costo de 4 a 8 horas<input id="roomCostFourToEight" type="number" min="0" step="0.01" required value="${Number(room.costFourToEight||0).toFixed(2)}"></label><label class="full">Costo por hora extra después de 8 horas<input id="roomCostExtraHour" type="number" min="0" step="0.01" required value="${Number(room.costExtraHour||0).toFixed(2)}"></label></div></fieldset>`:`<div class="room-cost-notice"><b>Tarifas no aplicables</b><span>De acuerdo con la configuración actual, los costos de uso solo se administran para los salones 1, 2 y 3.</span></div>`;
  openModal(`<h2>Editar ${safe(room.name)}</h2><p>Actualiza las capacidades, el consumo eléctrico por hora${room.allowsUsageCost?' y las tarifas de uso del salón':''}. Los cambios se guardarán en el catálogo.</p><form id="roomEditorForm"><fieldset class="room-capacity-editor"><legend>Capacidad por distribución</legend><div class="form-grid">${capacityInputs}</div></fieldset><fieldset class="room-energy-editor"><legend>Consumo eléctrico</legend><label class="standalone-label">Consumo estimado por hora (kWh)<input id="roomHourlyConsumption" type="number" min="0" step="0.01" required value="${room.hourlyConsumptionKwh.toFixed(2)}"><small>Ejemplo: 5.50 kWh por cada hora de uso.</small></label></fieldset>${pricing}<div class="form-actions"><button type="button" id="back" class="button secondary">Cancelar</button><button class="button primary">Guardar cambios</button></div></form>`);
  $('#back').onclick=closeModal;
  $('#roomEditorForm').onsubmit=async event=>{
    event.preventDefault();const button=event.submitter,capacities=Object.fromEntries($$('[data-room-capacity]',$('#roomEditorForm')).map(input=>[input.dataset.roomCapacity,Number(input.value)]));button.disabled=true;
    try{
      const{error}=await db.rpc('admin_update_room_capacities_and_costs',{p_room_id:room.id,p_capacities:capacities,p_hourly_consumption_kwh:Number($('#roomHourlyConsumption').value),p_cost_one_hour:room.allowsUsageCost?Number($('#roomCostOneHour').value):null,p_cost_four_to_eight_hours:room.allowsUsageCost?Number($('#roomCostFourToEight').value):null,p_cost_extra_hour_after_eight:room.allowsUsageCost?Number($('#roomCostExtraHour').value):null});
      if(error)throw error;await loadCatalogs();populateRooms();renderRooms();closeModal();toast('La información del salón se actualizó correctamente.');
    }catch(error){button.disabled=false;toast(databaseMessage(error),true)}
  };
}
function renderPaidOfferings(){
  const target=$('#paidOfferingCards');if(!target)return;
  target.innerHTML=paidOfferings.filter(item=>item.is_active).map(item=>{const capacities=Object.entries(item.capacities||{}).map(([code,value])=>`<span>${code}<b>${value} personas</b></span>`).join('');return`<button type="button" class="panel room-card room-card-button" data-paid-offering-edit="${item.id}"><span class="room-visual"><b>${safe(item.name)}</b></span><span class="room-info"><span class="room-meta"><span>⌖ ${item.allocation_mode==='combined'?'Salones 1 + 2':'Salón 1 o 2'}</span><span>● Activo</span></span><span class="capacity">${capacities}</span><span class="room-energy">Precio desde <b>Q${Number(item.price_one_hour||0).toFixed(2)}</b></span><small class="room-edit-hint">Seleccionar para editar información comercial</small></span></button>`}).join('');$$('[data-paid-offering-edit]',target).forEach(button=>button.onclick=()=>openPaidOfferingEditor(button.dataset.paidOfferingEdit));
}
function openPaidOfferingEditor(id){
  const offering=paidOfferings.find(item=>Number(item.id)===Number(id));if(!offering)return;
  const capacityInputs=configurationTypes.filter(item=>item.code!=='BOARDROOM').map(item=>`<label>${safe(item.code==='SCHOOL'?'Escuela':item.name)}<input type="number" min="1" step="1" required data-paid-capacity="${safe(item.code)}" value="${Number(offering.capacities?.[item.code]||0)}"></label>`).join('');
  openModal(`<h2>Editar ${safe(offering.name)}</h2><p>Configura el nombre comercial, capacidades y precios que aparecerán en el enlace para empresas externas.</p><form id="paidOfferingEditor"><label class="standalone-label">Nombre comercial<input id="paidName" value="${safe(offering.name)}" required></label><label class="standalone-label">Descripción<textarea id="paidDescription" rows="3">${safe(offering.description||'')}</textarea></label><fieldset class="room-capacity-editor"><legend>Capacidad por distribución</legend><div class="form-grid">${capacityInputs}</div></fieldset><fieldset class="room-cost-editor"><legend>Precios en quetzales</legend><div class="form-grid"><label>1 hora<input id="paidPriceOne" type="number" min="0" step="0.01" value="${Number(offering.price_one_hour||0).toFixed(2)}" required></label><label>4 a 8 horas<input id="paidPricePack" type="number" min="0" step="0.01" value="${Number(offering.price_four_to_eight_hours||0).toFixed(2)}" required></label><label class="full">Hora extra después de 8 horas<input id="paidPriceExtra" type="number" min="0" step="0.01" value="${Number(offering.price_extra_hour_after_eight||0).toFixed(2)}" required></label></div></fieldset><div class="form-actions"><button type="button" id="back" class="button secondary">Cancelar</button><button class="button primary">Guardar cambios</button></div></form>`);$('#back').onclick=closeModal;$('#paidOfferingEditor').onsubmit=async event=>{event.preventDefault();const button=event.submitter;button.disabled=true;try{const capacities=Object.fromEntries($$('[data-paid-capacity]',$('#paidOfferingEditor')).map(input=>[input.dataset.paidCapacity,Number(input.value)])),{error}=await db.rpc('admin_update_paid_offering',{p_offering_id:Number(id),p_name:$('#paidName').value,p_description:$('#paidDescription').value,p_capacities:capacities,p_price_one_hour:Number($('#paidPriceOne').value),p_price_four_to_eight_hours:Number($('#paidPricePack').value),p_price_extra_hour_after_eight:Number($('#paidPriceExtra').value)});if(error)throw error;await loadCatalogs();renderRooms();renderPaidOfferings();closeModal();toast('Oferta comercial actualizada.')}catch(error){button.disabled=false;toast(databaseMessage(error),true)}};
}
function reportRowsForMonth(){return meetings.filter(m=>m.status==='approved'&&m.date?.slice(0,7)===reportMonth&&(reportSource==='paid'?m.requestSource==='paid':m.requestSource!=='paid')&&(reportOrganization==='all'||m.organization===reportOrganization)).map(m=>{const hours=Math.max(0,(new Date(`2000-01-01T${m.end}`)-new Date(`2000-01-01T${m.start}`))/3600000),kwh=m.roomIds.reduce((sum,id)=>sum+Number(roomById(id)?.hourlyConsumptionKwh||0),0);return{meetingId:m.meetingId||m.id,name:m.title||'Reunión',room:spaceName(m),responsible:m.contactName||m.owner||'No indicado',organization:m.organization||'Sin institución',date:m.date,start:m.start,end:m.end,hours,kwh,consumption:kwh*hours,quotedAmount:Number(m.quotedAmount||0)}})}
function reportIncomeForRows(rows){if(reportSource!=='paid')return 0;return[...new Map(rows.map(row=>[row.meetingId,row.quotedAmount])).values()].reduce((sum,value)=>sum+Number(value||0),0)}
function renderReports(){const monthInput=$('#reportMonth'),organizationSelect=$('#reportOrganization');if(!monthInput)return;if(!reportMonth)reportMonth=monthInput.value||key(new Date()).slice(0,7);monthInput.value=reportMonth;if(organizationSelect){const current=reportOrganization;organizationSelect.innerHTML='<option value="all">Todas las instituciones</option>'+organizations.filter(item=>item.is_active).map(item=>`<option value="${safe(item.name)}">${safe(item.name)}</option>`).join('');organizationSelect.value=current}const isPaid=reportSource==='paid';$$('#reportTabs button').forEach(button=>button.classList.toggle('active',button.dataset.reportSource===reportSource));$('#reportIncomeSummary').classList.toggle('hidden-col',!isPaid);$('#reportPaidCostHeader').classList.toggle('hidden-col',!isPaid);$('#reportTable').classList.toggle('paid-report',isPaid);const rows=reportRowsForMonth(),totalHours=rows.reduce((sum,row)=>sum+row.hours,0),totalEnergy=rows.reduce((sum,row)=>sum+row.consumption,0),totalIncome=reportIncomeForRows(rows);$('#reportMeetingCount').textContent=rows.length;$('#reportTotalHours').textContent=totalHours.toFixed(2);$('#reportTotalEnergy').textContent=totalEnergy.toFixed(2);$('#reportTotalIncome').textContent=money(totalIncome);$('#reportRows').innerHTML=rows.length?rows.map(row=>`<tr><td><b>${safe(row.name)}</b></td><td>${safe(row.room)}</td><td>${safe(row.responsible)}</td><td>${safe(row.organization)}</td><td>${format(row.date,{day:'2-digit',month:'2-digit',year:'numeric'})}</td><td>${row.start}</td><td>${row.end}</td><td>${row.hours.toFixed(2)}</td><td>${row.kwh.toFixed(2)}</td><td>${row.consumption.toFixed(2)}</td>${isPaid?`<td class="paid-total-cell"><b>${money(row.quotedAmount)}</b></td>`:''}</tr>`).join(''):`<tr><td colspan="${isPaid?11:10}" class="report-empty">No hay reuniones aprobadas para el mes seleccionado.</td></tr>`}
function exportReportXlsx(){const rows=reportRowsForMonth();if(!rows.length)return toast('No hay datos para exportar.',true);if(!window.XLSX)return toast('No se pudo cargar el exportador de Excel.',true);const data=rows.map(row=>{const result={'Nombre':row.name,'Salón':row.room,'Responsable':row.responsible,'Institución':row.organization,'Fecha':row.date,'Hora de inicio':row.start,'Hora de finalización':row.end,'Cantidad de horas':Number(row.hours.toFixed(2)),'KWH':Number(row.kwh.toFixed(2)),'Consumo energético':Number(row.consumption.toFixed(2))};if(reportSource==='paid')result['Costo total']=Number(row.quotedAmount.toFixed(2));return result});const sheet=XLSX.utils.json_to_sheet(data),book=XLSX.utils.book_new();XLSX.utils.book_append_sheet(book,sheet,reportSource==='paid'?'Solicitudes de pago':'Reuniones aprobadas');XLSX.writeFile(book,`reporte-${reportSource==='paid'?'solicitudes-pago':'reuniones'}-${reportMonth}.xlsx`)}
function exportReportPdf(){const rows=reportRowsForMonth();if(!rows.length)return toast('No hay datos para exportar.',true);const Pdf=window.jspdf?.jsPDF;if(!Pdf)return toast('No se pudo cargar el exportador PDF.',true);const isPaid=reportSource==='paid',doc=new Pdf({orientation:'landscape',unit:'mm',format:'a4'}),head=['Nombre','Salón','Responsable','Institución','Fecha','Inicio','Fin','Horas','KWH','Consumo energético'];if(isPaid)head.push('Costo total');doc.setFontSize(16);doc.text(`${isPaid?'Solicitudes de pago':'Reuniones aprobadas'} · ${reportMonth}`,14,15);doc.setFontSize(9);doc.text(isPaid?`Ingresos estimados: Q${reportIncomeForRows(rows).toFixed(2)}`:'Consumo energético estimado por salón',14,21);doc.autoTable({startY:27,head:[head],body:rows.map(row=>{const values=[row.name,row.room,row.responsible,row.organization,row.date,row.start,row.end,row.hours.toFixed(2),row.kwh.toFixed(2),row.consumption.toFixed(2)];if(isPaid)values.push(`Q${row.quotedAmount.toFixed(2)}`);return values}),styles:{fontSize:isPaid?6:6.5},headStyles:{fillColor:[46,125,50]}});doc.save(`reporte-${isPaid?'solicitudes-pago':'reuniones'}-${reportMonth}.pdf`)}
function renderAll(){renderAgenda();renderMine();renderIncidents();if(currentUser.role==='admin'||currentUser.isSuperadmin){renderApprovals();renderPaidRequests();renderUsers();renderMaintenance();renderReports()}else{if(currentUser.permissions?.has('ver_solicitudes_pago'))renderPaidRequests();if(currentUser.permissions?.has('ver_reportes'))renderReports()}renderRooms();renderPaidOfferings()}

$('#logout').onclick=()=>void logout();$('#menu').onclick=()=>$('#sidebar').classList.toggle('open');$$('aside nav button[data-view]').forEach(b=>b.onclick=()=>b.dataset.view==='request'?openRequestView():showView(b.dataset.view));$$('[data-go]').forEach(b=>b.onclick=()=>b.dataset.go==='request'?openRequestView():showView(b.dataset.go));
$('#closeModal').onclick=closeModal;$('#modal').onclick=e=>{if(e.target===$('#modal'))closeModal()};document.addEventListener('click',()=>closeRequestActionMenus());window.addEventListener('scroll',()=>closeRequestActionMenus(),true);document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeRequestActionMenus();closeModal()}});
async function navigateAgenda(direction){if(agendaMode==='calendar'){currentDate=new Date(currentDate.getFullYear(),currentDate.getMonth()+direction,1,12)}else currentDate.setDate(currentDate.getDate()+direction);try{await loadAgendaDate()}catch(error){toast(databaseMessage(error),true)}}
$('#prevDate').onclick=()=>void navigateAgenda(-1);$('#nextDate').onclick=()=>void navigateAgenda(1);$('#today').onclick=async()=>{currentDate=new Date();try{await loadAgendaDate()}catch(error){toast(databaseMessage(error),true)}};$('#roomFilter').onchange=renderAgenda;
$('#agendaViewButton').onclick=()=>{agendaMode='agenda';renderAgenda()};$('#listViewButton').onclick=()=>{agendaMode='list';renderAgenda()};$('#calendarViewButton').onclick=()=>{agendaMode='calendar';currentDate=new Date(currentDate.getFullYear(),currentDate.getMonth(),1,12);renderAgenda()};
$('#meetingRoom').onchange=()=>{requestAssignments.clear();updateRequestOptions();renderRequestAssignments()};$$('input[name="configuration"]').forEach(input=>input.onchange=()=>{requestAssignments.clear();updateRequestOptions();renderRequestAssignments()});$('#combineRooms').onchange=()=>{requestAssignments.clear();updateRequestOptions();renderRequestAssignments()};$('#attendees').oninput=()=>{renderRequestRoomOptions();renderRequestAssignments()};$('#start').onchange=()=>void refreshRequestAvailability();$('#end').onchange=()=>void refreshRequestAvailability();
$('#recurrence').onchange=updateRecurrence;$('#repeatUntil').onchange=updateRecurrence;$('#meetingDate').onchange=updateRecurrence;
$('#addCustomDate').onclick=()=>{const date=$('#customDate').value;if(!date)return toast('Selecciona una fecha para agregar.',true);if(date===$('#meetingDate').value)return toast('La primera fecha ya está incluida.',true);if(!customDates.includes(date))customDates.push(date);customDates.sort();$('#customDate').value='';updateRecurrence()};
$('#meetingTabs').onclick=e=>{const b=e.target.closest('button[data-status]');if(!b)return;mineFilter=b.dataset.status;$$('#meetingTabs button').forEach(x=>x.classList.toggle('active',x===b));renderMine()};
$('#adminRequestTabs').onclick=e=>{const b=e.target.closest('button[data-request-status]');if(!b)return;adminRequestStatus=b.dataset.requestStatus;$$('#adminRequestTabs button').forEach(x=>x.classList.toggle('active',x===b));renderApprovals()};
$('#paidRequestTabs').onclick=e=>{const b=e.target.closest('button[data-paid-request-status]');if(!b)return;paidRequestStatus=b.dataset.paidRequestStatus;$$('#paidRequestTabs button').forEach(x=>x.classList.toggle('active',x===b));renderPaidRequests()};
$('#portalUserSearch').oninput=renderUsers;
$('#portalUserRefresh').onclick=()=>void loadPortalUsers(true);
$('#createPortalUser').onclick=()=>void openPortalCreateUser();
$('#manageOrganizations').onclick=openOrganizationManager;
$('#managePermissions').onclick=openPermissionsManager;
$('#navManagePermissions').onclick=openPermissionsManager;
$('#adminRequestDate').onchange=e=>{adminRequestDate=e.target.value;renderApprovals()};
$('#clearRequestDate').onclick=()=>{adminRequestDate='';$('#adminRequestDate').value='';renderApprovals()};
$('#paidRequestDate').onchange=e=>{paidRequestDate=e.target.value;renderPaidRequests()};
$('#clearPaidRequestDate').onclick=()=>{paidRequestDate='';$('#paidRequestDate').value='';renderPaidRequests()};
$('#reportMonth').onchange=e=>{reportMonth=e.target.value;renderReports()};$('#reportOrganization').onchange=e=>{reportOrganization=e.target.value;renderReports()};$('#exportReportXlsx').onclick=exportReportXlsx;$('#exportReportPdf').onclick=exportReportPdf;
$('#reportTabs').onclick=e=>{const button=e.target.closest('button[data-report-source]');if(!button)return;reportSource=button.dataset.reportSource;renderReports()};
$('#requestForm').onreset=()=>{const locked=$('#meetingDate').dataset.calendarLocked==='true';setTimeout(()=>{customDates=[];requestAssignments.clear();requestAvailabilityByDate.clear();const date=locked?key(currentDate):key(new Date());$('#meetingDate').value=date;$('#meetingDate').disabled=locked;$('#meetingDateHelp').textContent=locked?`Fecha seleccionada desde el calendario: ${format(date,{weekday:'long',day:'numeric',month:'long',year:'numeric'})}.`:'';$('#contactName').value=currentUser.name;$('#contactEmail').value=currentUser.email||'';updateRequestOptions();updateRecurrence();updateRefreshmentAccess()},0)};
$('#requestForm').onsubmit=async e=>{
  e.preventDefault();const start=$('#start').value,end=$('#end').value;if(start>=end)return toast('La hora final debe ser posterior a la hora de inicio.',true);
  const attendees=Number($('#attendees').value),dates=recurrenceDates(),recurrenceType=$('#recurrence').value,selections=requestOccurrenceSelections();if(recurrenceType!=='none'&&dates.length<2)return toast('Agrega al menos una segunda fecha para la reunión recurrente.',true);
  for(const item of selections){const space=spaceById(item.spaceId),configuration=configurationTypes.find(candidate=>Number(candidate.id)===Number(item.configurationId)),capacity=space?.capacities.get(Number(configuration?.id))||0,known=requestAvailabilityByDate.has(item.date),available=!known||requestAvailabilityByDate.get(item.date).some(candidate=>candidate.id===Number(item.spaceId));if(!space||!configuration)return toast(`Selecciona salón y distribución para ${format(item.date,{day:'numeric',month:'short'})}.`,true);if(!available)return toast(`${space.name} no está disponible el ${format(item.date,{day:'numeric',month:'short'})}.`,true);if(attendees>capacity)return toast(`La capacidad máxima para ${format(item.date,{day:'numeric',month:'short'})} es de ${capacity} personas.`,true)}
  const meetingType=meetingTypes.find(item=>Number(item.id)===Number($('#meetingType').value));if(!meetingType)return toast('No se encontró el tipo de reunión solicitado.',true);
  const refreshmentIds=currentUser.canRequestRefreshments?selectedRefreshments():[];
  const button=e.submitter;button.disabled=true;
  try{
    const{error}=await db.rpc('create_meeting_request',{p_title:$('#title').value.trim(),p_description:$('#description').value.trim(),p_meeting_type_id:Number(meetingType.id),p_configuration_type_id:Number(selections[0].configurationId),p_attendees:attendees,p_contact_name:$('#contactName').value.trim(),p_contact_email:$('#contactEmail').value.trim().toLowerCase(),p_contact_phone:$('#contactPhone').value.trim(),p_occurrences:selections.map(item=>({space_id:Number(item.spaceId),configuration_type_id:Number(item.configurationId),starts_at:toDatabaseTimestamp(item.date,start),ends_at:toDatabaseTimestamp(item.date,end)})),p_recurrence_type:recurrenceType,p_recurrence_end_date:recurrenceType==='none'?null:dates.at(-1),p_refreshment_ids:refreshmentIds.map(Number),p_observations:$('#requestObservations').value.trim()});
    if(error)throw error;e.target.reset();customDates=[];requestAssignments.clear();requestAvailabilityByDate.clear();$('#meetingDate').value=key(currentDate);$('#contactName').value=currentUser.name;$('#contactEmail').value=currentUser.email||'';updateRequestOptions();updateRecurrence();await refreshApplication();showView('mine');toast(dates.length>1?`Serie creada con ${dates.length} reuniones pendientes.`:'Solicitud enviada para aprobación.');
  }catch(error){toast(databaseMessage(error),true)}finally{button.disabled=false}
};
$('#maintenanceAllDay').onchange=updateMaintenanceTimeFields;
$('#maintenanceForm').onsubmit=scheduleMaintenance;
$('#maintenanceForm').onreset=()=>setTimeout(()=>{$('#maintenanceDate').value=key(new Date());updateMaintenanceTimeFields()},0);
$('#meetingDate').min=key(new Date());$('#meetingDate').value=key(currentDate);updateRecurrence();
$('#maintenanceDate').value=key(new Date());updateMaintenanceTimeFields();
async function initializeApplication(){
  const releaseLoading=()=>{document.body.classList.remove('auth-checking');$('#appLoading')?.classList.add('hidden')};
  const failsafe=setTimeout(releaseLoading,10000);
  let privateError=false;
  try{
    if(!document.documentElement.classList.contains('private-entry'))return;
    requireSupabase();
    const response=await fetch('../login/cengisalas_session.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}});
    const portalAccess=await response.json();
    if(!response.ok||!portalAccess.allowed)throw new Error(portalAccess.error||'No se pudo iniciar CengiSalas desde el portal.');
    const restored=await db.auth.setSession({access_token:portalAccess.access_token,refresh_token:portalAccess.refresh_token});
    if(restored.error)throw restored.error;
    const authUser=restored.data.user||restored.data.session?.user;
    if(!authUser||authUser.id!==portalAccess.user_id){
      await db.auth.signOut({scope:'local'});
      throw new Error('La sesión de datos no corresponde a la cuenta del portal.');
    }
    await Promise.race([login(authUser,portalAccess),new Promise((_,reject)=>setTimeout(()=>reject(new Error('La carga de la sesión excedió el tiempo permitido.')),9000))]);
  }catch(error){
    try{void db?.auth?.signOut({scope:'local'})}catch{}
    currentUser=null;
    const message=databaseMessage(error);
    console.warn(message);
    $('#app').classList.add('hidden');
    if(document.documentElement.classList.contains('private-entry')){
      privateError=true;
      const loading=$('#appLoading');
      loading.style.animation='none';
      loading.classList.remove('hidden');
      loading.querySelector('span').textContent=message;
      if(!loading.querySelector('a')){
        const back=document.createElement('a');
        back.href='../login/Menu.php';
        back.className='button primary';
        back.textContent='Volver al menú';
        loading.appendChild(back);
      }
    }else $('#publicLanding')?.classList.remove('hidden');
  }finally{
    clearTimeout(failsafe);
    if(privateError)document.body.classList.remove('auth-checking');
    else releaseLoading();
  }
}
startInactivityMonitor();
setInterval(async()=>{
  if(!currentUser)return;
  try{
    const response=await fetch('../login/cengisalas_gate.php?module=salas',{credentials:'same-origin',cache:'no-store'});
    if(response.status===403){void db.auth.signOut({scope:'local'});location.replace('../login/Menu.php')}
    else if(response.ok){
      const access=await response.json();
      if(!matchesPortalAccess(access)){void db.auth.signOut({scope:'local'});location.replace('../login/Menu.php')}
      else if(access.role!==currentUser.salasRole||Boolean(access.superadmin)!==currentUser.isSuperadmin){
        currentUser=applyPortalSalasRole(await loadCurrentProfile({id:currentUser.id,email:currentUser.email}),access);
        syncCurrentUserInterface();await loadApplicationData();renderAll(false);showView('agenda');
      }
    }
  }catch(error){console.warn('No se pudo verificar la sesión del menú.',error)}
},60000);
void initializeApplication();
