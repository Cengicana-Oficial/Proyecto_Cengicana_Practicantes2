-- CengiSalas / Supabase
-- 24: consulta pública de salones disponibles para una o varias fechas y horarios.

create or replace function public.get_public_available_rooms(p_occurrences jsonb)
returns jsonb language plpgsql stable security definer set search_path='' as $$
declare v_room record;v_item jsonb;v_ok boolean;v_result jsonb:='[]'::jsonb;
begin
  if p_occurrences is null or jsonb_typeof(p_occurrences)<>'array' or jsonb_array_length(p_occurrences)=0 or jsonb_array_length(p_occurrences)>100 then return v_result; end if;
  for v_room in
    select r.id,r.code,r.name,s.id space_id from public.rooms r join public.spaces s on s.space_type='single' and s.is_active join public.space_rooms sr on sr.space_id=s.id and sr.room_id=r.id where r.is_active order by r.name
  loop
    v_ok:=true;
    for v_item in select value from jsonb_array_elements(p_occurrences) loop
      if (v_item->>'starts_at')::timestamptz >= (v_item->>'ends_at')::timestamptz or not private.space_is_available(v_room.space_id,(v_item->>'starts_at')::timestamptz,(v_item->>'ends_at')::timestamptz,null) then v_ok:=false;exit;end if;
    end loop;
    if v_ok then v_result:=v_result||jsonb_build_object('id',v_room.id,'code',v_room.code,'name',v_room.name);end if;
  end loop;
  return v_result;
end;
$$;

revoke all on function public.get_public_available_rooms(jsonb) from public,anon,authenticated;
grant execute on function public.get_public_available_rooms(jsonb) to anon,authenticated;
