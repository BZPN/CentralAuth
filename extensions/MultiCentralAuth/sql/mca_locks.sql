-- MultiCentralAuth locks schema
CREATE TABLE IF NOT EXISTS /*_*/mca_locks (
    mcl_id INT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
    mcl_user_id INT UNSIGNED NOT NULL,
    mcl_reason VARBINARY(255) NOT NULL,
    mcl_expiry VARBINARY(14) NOT NULL,
    mcl_by INT UNSIGNED NOT NULL,
    mcl_timestamp VARBINARY(14) NOT NULL
) /*$wgDBTableOptions*/;

CREATE INDEX /*i*/mcl_user_id ON /*_*/mca_locks (mcl_user_id);
CREATE INDEX /*i*/mcl_expiry ON /*_*/mca_locks (mcl_expiry);
