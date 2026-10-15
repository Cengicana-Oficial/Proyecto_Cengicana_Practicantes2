-- CengiSalas / Supabase
-- 23: disponibilidad de ofertas comerciales antes de mostrar la selección.

create or replace function public.get_paid_available_offerings(p_occurrences jsonb)
returns jsonb language plpgsql stable security definer set search_path='' as $$
declare v_code text;v_item jsonb;v_space_id bigint;v_ok boolean;v_result jsonb:='[]'::jsonb;
begin
  if p_occurrences is null or jsonb_typeof(p_occurrences)<>'array' or jsonb_array_length(p_occurrences)=0 then return v_result; end if;
  for v_code in select code from public.paid_space_offerings where is_active order by case code when 'TRAINING_HALL' then 1 when 'EXECUTIVE_HALL' then 2 else 3 end loop
    v_ok:=true;
    for v_item in select value from jsonb_array_elements(p_occurrences) loop
      if v_code='MAIN_HALL' then select id into v_space_id from public.spaces where code='SPACE_1_2' and is_active;
      elsif v_code='EXECUTIVE_HALL' then select id into v_space_id from public.spaces where code='SPACE_3' and is_active;
      else select s.id into v_space_id from public.spaces s where s.code in ('SPACE_1','SPACE_2') and s.is_active and private.space_is_available(s.id,(v_item->>'starts_at')::timestamptz,(v_item->>'ends_at')::timestamptz,null) order by case when s.code='SPACE_1' then 1 else 2 end limit 1; end if;
      if v_space_id is null or not private.space_is_available(v_space_id,(v_item->>'starts_at')::timestamptz,(v_item->>'ends_at')::timestamptz,null) then v_ok:=false; exit; end if;
    end loop;
    if v_ok then v_result:=v_result||to_jsonb(v_code); end if;
  end loop;
  return v_result;
end;
$$;

revoke all on function public.get_paid_available_offerings(jsonb) from public,anon,authenticated;
grant execute on function public.get_paid_available_offerings(jsonb) to anon,authenticated;
