CREATE TABLE IF NOT EXISTS ticket_hold_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id INT NOT NULL,
    requested_by INT NOT NULL,
    reason TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    decided_by INT NULL,
    decision_note VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_hold_request_ticket_status (ticket_id, status),
    KEY idx_hold_request_requested_by (requested_by),
    KEY idx_hold_request_decided_by (decided_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
