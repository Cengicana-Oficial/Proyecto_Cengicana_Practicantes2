-- SalaFlow / Supabase
-- 05: publicación Realtime del canal de invalidación del tablero.
-- El cliente escucha INSERT en board_events y vuelve a invocar get_public_board(fecha).

begin;

do $$
begin
  if not exists (
    select 1
    from pg_publication_tables
    where pubname = 'supabase_realtime'
      and schemaname = 'public'
      and tablename = 'board_events'
  ) then
    alter publication supabase_realtime add table public.board_events;
  end if;
end $$;

-- Facilita limpieza programada sin afectar el tablero; conservar al menos unos minutos.
create or replace function public.purge_old_board_events(p_keep interval default interval '1 day')
returns bigint
language plpgsql
security definer
set search_path = ''
as $$
declare v_count bigint;
begin
  perform private.require_admin();
  delete from public.board_events where created_at < now() - greatest(p_keep, interval '5 minutes');
  get diagnostics v_count = row_count;
  return v_count;
end;
$$;
revoke all on function public.purge_old_board_events(interval) from public, anon, authenticated;
grant execute on function public.purge_old_board_events(interval) to authenticated;

commit;
