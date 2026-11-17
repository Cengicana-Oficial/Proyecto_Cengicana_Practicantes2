const portalSettings=window.SALAFLOW_SUPABASE;
const portalDb=window.supabase?.createClient(portalSettings?.url,portalSettings?.publishableKey,{auth:{persistSession:true,autoRefreshToken:true,detectSessionInUrl:true}});
const portal$=selector=>document.querySelector(selector);

function portalInitials(name){return String(name||'Usuario').split(/\s+/).filter(Boolean).slice(0,2).map(part=>part[0]).join('').toUpperCase()}
function portalMessage(error){const message=error?.message||String(error||'Error desconocido');return /failed to fetch|networkerror/i.test(message)?'No fue posible conectar con el sistema. Verifica tu conexión e inténtalo nuevamente.':message}

async function loadPortal(){
  if(!portalDb)throw new Error('No se pudo iniciar la conexión con CengiSalas.');
  const{data:{session},error:sessionError}=await portalDb.auth.getSession();
  if(sessionError)throw sessionError;
  if(!session){location.replace('login.html');return}
  const{data:profile,error:profileError}=await portalDb.from('profiles').select('full_name,role,is_active,account_status,organizations(name)').eq('id',session.user.id).single();
  if(profileError)throw profileError;
  if(!profile.is_active||profile.account_status!=='active'){await portalDb.auth.signOut();location.replace('login.html');return}
  portal$('#portalUserName').textContent=profile.full_name;
  portal$('#portalInitials').textContent=portalInitials(profile.full_name);
  portal$('#portalUserMeta').textContent=`${profile.role==='admin'?'Administrador':'Usuario'} · ${profile.organizations?.name||'Sin organización'}`;
  const{data:authorizedModules,error:modulesError}=await portalDb.rpc('get_my_modules');
  if(!modulesError&&Array.isArray(authorizedModules)){
    const moduleCodes=new Set(authorizedModules.map(item=>item.code));
    portal$('#openRooms').classList.toggle('hidden',!moduleCodes.has('salas'));
    portal$('#openVisits').classList.toggle('hidden',!moduleCodes.has('visitas'));
    if(!moduleCodes.size)portal$('#portalError').textContent='Tu cuenta está activa, pero todavía no tiene módulos asignados.';
  }
  if(profile.role!=='admin')portal$('#visitsDescription').textContent='Consulta las solicitudes de visitas aprobadas utilizando tu misma cuenta de CengiSalas.';
  portal$('#portalLoading').classList.add('hidden');
  portal$('#portalApp').classList.remove('hidden');

  portal$('#openVisits').onclick=()=>{location.href='../login/sso_visitas.php'};
}

portal$('#portalLogout').onclick=async()=>{portal$('#portalLogout').disabled=true;await portalDb?.auth.signOut();location.replace('login.html')};
void loadPortal().catch(error=>{portal$('#portalLoading').classList.add('hidden');portal$('#portalApp').classList.remove('hidden');portal$('#portalError').textContent=portalMessage(error)});
