-- SalaFlow / Supabase
-- 01: extensiones, esquema privado y tipos PostgreSQL.
-- Ejecutar con una cuenta propietaria del proyecto desde SQL Editor.

begin;

create schema if not exists extensions;
create extension if not exists btree_gist with schema extensions;
create schema if not exists private;

do $$ begin
  create type public.app_role as enum ('admin', 'user');
exception when duplicate_object then null;
end $$;

do $$ begin
  create type public.meeting_status as enum ('pending', 'approved', 'rejected', 'cancelled');
exception when duplicate_object then null;
end $$;

do $$ begin
  create type public.recurrence_type as enum ('none', 'weekly', 'biweekly', 'monthly', 'custom');
exception when duplicate_object then null;
end $$;

do $$ begin
  create type public.space_type as enum ('single', 'combined');
exception when duplicate_object then null;
end $$;

do $$ begin
  create type public.maintenance_status as enum ('scheduled', 'cancelled', 'completed');
exception when duplicate_object then null;
end $$;

do $$ begin
  create type public.room_block_type as enum ('meeting', 'maintenance', 'payment_hold');
exception when duplicate_object then null;
end $$;

commit;
