-- Migration: Track sent monthly reports
-- Run this in the Supabase SQL Editor

create table if not exists reports_sent (
    id         uuid primary key default gen_random_uuid(),
    month      text unique not null,   -- e.g. '2026-03'
    sent_at    timestamptz not null default now()
);

alter table reports_sent enable row level security;

create policy "Select reports_sent"
    on reports_sent for select
    to service_role
    using (true);

create policy "Insert reports_sent"
    on reports_sent for insert
    to service_role
    with check (true);
