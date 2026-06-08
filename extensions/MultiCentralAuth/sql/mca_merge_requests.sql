-- MultiCentralAuth merge requests schema
CREATE TABLE IF NOT EXISTS /*_*/mca_merge_requests (
    mmr_id INT UNSIGNED NOT NULL PRIMARY KEY,
    mmr_user_id INT UNSIGNED NOT NULL,
    mmr_farm VARBINARY(32) NOT NULL,
    mmr_external_data BLOB DEFAULT NULL,
    mmr_comment BLOB DEFAULT NULL,
    mmr_status VARBINARY(16) NOT NULL DEFAULT 'open',
    mmr_timestamp BINARY(14) NOT NULL,
    mmr_closed_by INT UNSIGNED DEFAULT NULL,
    mmr_closed_comment BLOB DEFAULT NULL,
    mmr_closed_timestamp BINARY(14) DEFAULT NULL
) /*$wgDBTableOptions*/;
