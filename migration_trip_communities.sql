-- =========================================================
-- Migration: Trip Communities feature upgrade
-- Run this ONCE against the existing `trip_planner` database
-- (the same database used by db.php / users / trips tables).
--
-- What this does:
--   1. Adds the new Create Trip fields to the existing `trips`
--      table (description, start_date, end_date, number_of_days,
--      registration_deadline, estimated_cost, age_limit).
--      NOTE: the existing `privacy` column is reused as the
--      "Who Can Join" field (Everyone / Women / Men) instead of
--      creating a duplicate column.
--   2. Creates the `trip_members` table (the table the existing
--      view-community.php / join-community.php / community-chat.php
--      code already queries) if it does not exist yet. The older
--      create_community_members_table.sql created a differently
--      named table that the rest of the app never actually used.
--   3. Creates the `community_messages` table used by
--      community-chat.php if it does not exist yet.
--   4. Adds trusted `age` and `gender` columns to `users`, needed
--      so Join Community can validate Age Limit / Who Can Join
--      restrictions against real profile data instead of
--      trusting values submitted from the browser.
-- =========================================================

USE trip_planner;

-- 1. New Create Trip fields on the trips table
ALTER TABLE trips
    ADD COLUMN description TEXT NULL AFTER destination,
    ADD COLUMN start_date DATE NULL AFTER description,
    ADD COLUMN end_date DATE NULL AFTER start_date,
    ADD COLUMN number_of_days INT NULL AFTER end_date,
    ADD COLUMN registration_deadline DATE NULL AFTER max_members,
    ADD COLUMN estimated_cost DECIMAL(12,2) NULL AFTER registration_deadline,
    ADD COLUMN age_limit INT NULL AFTER estimated_cost,
    MODIFY COLUMN travel_date DATE NULL;

-- 2. trip_members table (matches the table name already used by
--    view-community.php, join-community.php, community-chat.php)
CREATE TABLE IF NOT EXISTS trip_members (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    trip_id      INT NOT NULL,
    user_id      INT NOT NULL,
    status       VARCHAR(20) NOT NULL DEFAULT 'joined',
    joined_date  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_trip_member (trip_id, user_id),
    CONSTRAINT fk_trip_members_trip
        FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
    CONSTRAINT fk_trip_members_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. community_messages table used by community-chat.php
CREATE TABLE IF NOT EXISTS community_messages (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    community_id  INT NOT NULL,
    sender_id     INT NOT NULL,
    message       TEXT NOT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_community_messages_trip
        FOREIGN KEY (community_id) REFERENCES trips(id) ON DELETE CASCADE,
    CONSTRAINT fk_community_messages_user
        FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_community_messages_room (community_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Trusted age/gender profile fields on users (collected at
--    registration, never trusted from the join-community request)
ALTER TABLE users
    ADD COLUMN age INT NULL AFTER name,
    ADD COLUMN gender VARCHAR(10) NULL AFTER age;
