-- =========================================================
-- MyTrips table
-- Run this once against the same MySQL database that
-- already holds your `users` table (used by login.php /
-- register.php).
-- =========================================================

CREATE TABLE IF NOT EXISTS MyTrips (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    user_id           INT NOT NULL,
    user_name         VARCHAR(150)   NULL,
    destination       VARCHAR(150)   NOT NULL,
    current_location  VARCHAR(150)   NULL,
    start_date        DATE           NULL,
    end_date          DATE           NULL,
    number_of_days    INT            NOT NULL DEFAULT 1,
    number_of_people  INT            NOT NULL DEFAULT 1,
    budget            DECIMAL(12,2)  NOT NULL DEFAULT 0,
    currency          VARCHAR(10)    NOT NULL DEFAULT 'USD',
    itinerary         LONGTEXT       NULL,   -- JSON string
    hotels            LONGTEXT       NULL,   -- JSON string
    transportation    LONGTEXT       NULL,   -- JSON string
    activities        LONGTEXT       NULL,   -- JSON string
    estimated_cost    DECIMAL(12,2)  NOT NULL DEFAULT 0,
    created_at        TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_mytrips_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE,

    INDEX idx_mytrips_user_created (user_id, created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
