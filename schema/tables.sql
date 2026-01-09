CREATE TABLE /*_*/rtbf_queue (
    rq_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rq_user_id  INT UNSIGNED NOT NULL,
    rq_user_name_original VARCHAR(255) BINARY NOT NULL,
    rq_user_name_target   VARCHAR(255) BINARY NOT NULL,
    rq_status   TINYINT UNSIGNED NOT NULL DEFAULT 1,
    rq_source   VARCHAR(32) NOT NULL DEFAULT 'web',
    rq_token    CHAR(36) NULL,
    rq_token_expires    BINARY(14) NULL,
    rq_created_at   BINARY(14) NOT NULL,
    rq_confirmed_at BINARY(14) NULL,
    rq_completed_at BINARY(14) NULL,
    INDEX   idx_user_status (rq_user_id, rq_status),
    INDEX   idx_token (rq_token),
    INDEX   idx_process_queue (rq_status)
) /*$wgDBTableOptions*/;

CREATE TABLE /*_*/rtbf_request_targets (
    id  INT AUTO_INCREMENT PRIMARY KEY,
    request_id  INT NOT NULL,
    wiki_id VARCHAR(64) NOT NULL,
    status  TINYINT NOT NULL DEFAULT 1,
    error_message   TEXT NULL,
    updated_at  BINARY(14) NOT NULL,
    INDEX (request_id)
) /*$wgDBTableOptions*/;