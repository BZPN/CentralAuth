-- MultiCentralAuth external user IDs schema
CREATE TABLE IF NOT EXISTS /*_*/mca_external_userids (
    meu_user_id INT UNSIGNED NOT NULL,
    meu_farm_id VARBINARY(32) NOT NULL,
    meu_external_username VARBINARY(255) NOT NULL,
    PRIMARY KEY (meu_user_id, meu_farm_id)
) /*$wgDBTableOptions*/;
