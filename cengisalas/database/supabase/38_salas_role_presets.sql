-- Tres perfiles fijos de CengiSalas. No modifica roles ni permisos de Visitas.
begin;

-- El catálogo de cada rol debe reflejar exactamente el menú autorizado.
delete from public.app_module_role_permissions rp
using public.app_module_roles r, public.app_modules m, public.app_module_permissions p
where rp.role_id=r.id and rp.permission_id=p.id
  and r.module_id=m.id and p.module_id=m.id and m.code='salas'
  and r.code in ('user','manager')
  and not (
    (r.code='user' and p.code in ('ver_dashboard','ver_solicitudes'))
    or (r.code='manager' and p.code in
      ('ver_dashboard','ver_solicitudes','ver_solicitudes_pago','ver_reportes'))
  );

insert into public.app_module_role_permissions(role_id,permission_id)
select r.id,p.id from public.app_module_roles r
join public.app_modules m on m.id=r.module_id and m.code='salas'
join public.app_module_permissions p on p.module_id=m.id
where r.code='admin'
   or (r.code='user' and p.code in ('ver_dashboard','ver_solicitudes'))
   or (r.code='manager' and p.code in
     ('ver_dashboard','ver_solicitudes','ver_solicitudes_pago','ver_reportes'))
on conflict do nothing;

-- Para perfiles del portal, el rol global puede pertenecer a Visitas.
-- Una operación administrativa de Salones exige su permiso propio.
create or replace function private.is_admin()
returns boolean language sql stable security definer set search_path=''
as $$
  select coalesce((
    select p.is_active and p.account_status::text='active' and (
      p.is_superadmin
      or (p.portal_user_id is null and p.role='admin'::public.app_role)
      or (p.portal_user_id is not null and exists (
        select 1 from public.profile_module_permissions pp
        join public.app_module_permissions ap on ap.id=pp.permission_id
        join public.app_modules am on am.id=ap.module_id
        where pp.profile_id=p.id and pp.allowed
          and am.code='salas' and ap.code='gestionar_solicitudes'
      ))
    )
    from public.profiles p where p.id=auth.uid()
  ),false);
$$;

commit;
