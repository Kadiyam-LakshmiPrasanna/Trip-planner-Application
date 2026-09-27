-- =========================================================
-- Migration: Trip status / cancellation + Reviews
-- Run this ONCE against the existing `trip_planner` database,
-- after migration_trip_communities.sql has already been applied.
--
-- What this does:
--   1. Adds `status`, `cancel_reason`, `cancel_reason_other` and
--      `cancelled_at` columns to `trips` so a trip can be marked
--      Cancelled (End Trip feature) without deleting its row —
--      it stays visible in "All Created Trips" but disappears
--      from "Ongoing Trips" everywhere.
--   2. Creates the `reviews` table used by the "What Travellers
--      Say" section (public name + rating + text; email is
--      collected but never displayed).
-- =========================================================

USE trip_planner;

-- 1. Trip status / cancellation
ALTER TABLE trips
    ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'Active' AFTER trip_type,
    ADD COLUMN cancel_reason VARCHAR(150) NULL AFTER status,
    ADD COLUMN cancel_reason_other TEXT NULL AFTER cancel_reason,
    ADD COLUMN cancelled_at TIMESTAMP NULL AFTER cancel_reason_other;

-- 2. Reviews table
CREATE TABLE IF NOT EXISTS reviews (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    email       VARCHAR(150) NOT NULL,
    rating      TINYINT UNSIGNED NOT NULL,
    review_text TEXT NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_reviews_rating CHECK (rating BETWEEN 1 AND 5),
    INDEX idx_reviews_rating_created (rating DESC, created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
