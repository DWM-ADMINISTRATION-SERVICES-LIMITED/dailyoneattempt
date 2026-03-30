-- ============================================================
-- Daily One Attempt — Supabase Schema Setup
-- Run this in the Supabase SQL Editor to create the tables
-- ============================================================

-- Enable UUID generation
create extension if not exists "pgcrypto";

-- ── Review Sessions ──
-- One row per day's report. The token is used in the review URL.
create table review_sessions (
    id             uuid primary key default gen_random_uuid(),
    token          text unique not null,
    report_date    date not null,
    created_at     timestamptz not null default now(),
    completed_at   timestamptz,
    total_rows     integer not null default 0,
    reviewed_count integer not null default 0
);

create index idx_sessions_token on review_sessions (token);
create index idx_sessions_date  on review_sessions (report_date);

-- ── Attempts ──
-- Each row from the processed CSV, linked to a review session.
create table attempts (
    id               uuid primary key default gen_random_uuid(),
    session_id       uuid not null references review_sessions(id) on delete cascade,
    startdatetime    text not null,
    fullname         text not null,
    resultcode       text not null,
    phonenumber      text not null,
    disconnector     text not null,
    is_genuine       boolean,          -- null = unreviewed
    rejection_reason text,             -- null unless is_genuine = false
    reviewed_at      timestamptz
);

create index idx_attempts_session on attempts (session_id);
create index idx_attempts_review  on attempts (is_genuine) where is_genuine is not null;

-- ── Row Level Security ──

alter table review_sessions enable row level security;
alter table attempts enable row level security;

-- Anonymous users can SELECT sessions by token
create policy "Select session by token"
    on review_sessions for select
    to anon
    using (true);

-- Anonymous users can UPDATE reviewed_count and completed_at on sessions they can see
create policy "Update session progress"
    on review_sessions for update
    to anon
    using (true)
    with check (true);

-- Anonymous users can SELECT attempts belonging to any session (filtered client-side by token)
create policy "Select attempts by session"
    on attempts for select
    to anon
    using (true);

-- Anonymous users can UPDATE review fields on attempts
create policy "Update attempt review"
    on attempts for update
    to anon
    using (true)
    with check (true);

-- Only service_role (used by GitHub Actions) can INSERT
create policy "Service insert sessions"
    on review_sessions for insert
    to service_role
    with check (true);

create policy "Service insert attempts"
    on attempts for insert
    to service_role
    with check (true);
