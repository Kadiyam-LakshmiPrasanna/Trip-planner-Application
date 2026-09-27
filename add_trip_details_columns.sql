-- =========================================================
-- Adds the columns needed to save a COMPLETE generated trip
-- plan (image, weather, AI itinerary text, budget breakdown,
-- and a de-duplication hash) to the existing MyTrips table.
--
-- Safe to run once against the same database used by
-- create_mytrips_table.sql. Uses IF NOT EXISTS-style guards
-- via a stored procedure so re-running this file won't error
-- out on databases where it was already applied.
-- =========================================================

DELIMITER $$

CREATE PROCEDURE add_mytrips_columns_if_missing()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'MyTrips' AND COLUMN_NAME = 'destination_image'
    ) THEN
        ALTER TABLE MyTrips ADD COLUMN destination_image TEXT NULL AFTER destination;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'MyTrips' AND COLUMN_NAME = 'weather_data'
    ) THEN
        ALTER TABLE MyTrips ADD COLUMN weather_data LONGTEXT NULL; -- JSON: array of {date, tempMax, tempMin, precipitationChance, condition, emoji}
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'MyTrips' AND COLUMN_NAME = 'budget_breakdown'
    ) THEN
        ALTER TABLE MyTrips ADD COLUMN budget_breakdown LONGTEXT NULL; -- JSON: {Hotels: n, Transportation: n, Activities: n, Buffer: n}
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'MyTrips' AND COLUMN_NAME = 'ai_itinerary_html'
    ) THEN
        ALTER TABLE MyTrips ADD COLUMN ai_itinerary_html LONGTEXT NULL; -- rendered AI itinerary (destination desc, places, food, tips)
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'MyTrips' AND COLUMN_NAME = 'trip_hash'
    ) THEN
        ALTER TABLE MyTrips ADD COLUMN trip_hash CHAR(64) NULL;
    END IF;

    -- Prevent the exact same generated trip being saved twice by the same user.
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'MyTrips' AND INDEX_NAME = 'uniq_user_trip_hash'
    ) THEN
        ALTER TABLE MyTrips ADD UNIQUE KEY uniq_user_trip_hash (user_id, trip_hash);
    END IF;
END$$

DELIMITER ;

CALL add_mytrips_columns_if_missing();
DROP PROCEDURE add_mytrips_columns_if_missing;
