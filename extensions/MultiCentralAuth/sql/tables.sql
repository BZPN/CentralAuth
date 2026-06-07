-- MultiCentralAuth schema

CREATE TABLE IF NOT EXISTS /*_*/mca_external_ids (
    mei_user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    mei_wm_username VARBINARY(255) DEFAULT NULL,
    mei_mh_username VARBINARY(255) DEFAULT NULL
) /*$wgDBTableOptions*/;

CREATE TABLE IF NOT EXISTS /*_*/mca_local_attachments (
    mla_user_id INT UNSIGNED NOT NULL,
    mla_wiki_id VARBINARY(255) NOT NULL,
    PRIMARY KEY (mla_user_id, mla_wiki_id)
) /*$wgDBTableOptions*/;
