-- Migration: Add confirmation_sent_at to review_sessions
-- Tracks whether a "review complete" confirmation email has been sent
-- after the reviewer finishes all attempts for a given day.

ALTER TABLE review_sessions
ADD COLUMN confirmation_sent_at timestamptz;
