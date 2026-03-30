-- Migration: Add total call stats tracking
-- Run this in the Supabase SQL Editor

-- Add total_calls to review_sessions
alter table review_sessions add column if not exists total_calls integer;

-- Per-agent daily call counts (from raw MaxContact data)
create table if not exists daily_agent_stats (
    id           uuid primary key default gen_random_uuid(),
    session_id   uuid not null references review_sessions(id) on delete cascade,
    report_date  date not null,
    fullname     text not null,
    total_calls  integer not null default 0
);

create index if not exists idx_agent_stats_session on daily_agent_stats (session_id);
create index if not exists idx_agent_stats_date on daily_agent_stats (report_date);

-- RLS
alter table daily_agent_stats enable row level security;

create policy "Select agent stats"
    on daily_agent_stats for select
    to anon
    using (true);

create policy "Service insert agent stats"
    on daily_agent_stats for insert
    to service_role
    with check (true);
