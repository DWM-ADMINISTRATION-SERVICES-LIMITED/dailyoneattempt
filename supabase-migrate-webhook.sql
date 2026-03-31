-- Migration: Database trigger to fire GitHub Actions workflow
-- when a review session is completed (completed_at set).
--
-- Uses pg_net (enabled by default on Supabase) to POST a
-- repository_dispatch event to GitHub Actions, which runs
-- review-complete.php to send the confirmation email.
--
-- Prerequisites:
--   1. Run this SQL in the Supabase SQL Editor
--   2. Replace YOUR_GITHUB_PAT_HERE with your fine-grained PAT
--      (Actions:write scope on the dailyoneattempt repo)

CREATE EXTENSION IF NOT EXISTS pg_net WITH SCHEMA extensions;

CREATE OR REPLACE FUNCTION notify_review_completed()
RETURNS trigger AS $$
BEGIN
    IF NEW.completed_at IS NOT NULL AND OLD.completed_at IS NULL THEN
        PERFORM net.http_post(
            url   := 'https://api.github.com/repos/ryandwmas/dailyoneattempt/dispatches',
            body  := '{"event_type": "review-completed"}'::jsonb,
            headers := '{
                "Accept": "application/vnd.github+json",
                "Authorization": "Bearer YOUR_GITHUB_PAT_HERE",
                "Content-Type": "application/json"
            }'::jsonb
        );
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

CREATE TRIGGER on_review_completed
    AFTER UPDATE OF completed_at ON review_sessions
    FOR EACH ROW
    EXECUTE FUNCTION notify_review_completed();
