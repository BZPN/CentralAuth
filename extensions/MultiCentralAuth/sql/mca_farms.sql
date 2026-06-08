-- MultiCentralAuth farms schema
CREATE TABLE IF NOT EXISTS /*_*/mca_farms (
    mf_id VARBINARY(32) NOT NULL PRIMARY KEY,
    mf_display_name VARBINARY(255) NOT NULL,
    mf_api_url VARBINARY(255) DEFAULT NULL,
    mf_is_centralauth TINYINT(1) NOT NULL DEFAULT 0,
    mf_header_msg VARBINARY(255) DEFAULT NULL
) /*$wgDBTableOptions*/;

CREATE TABLE IF NOT EXISTS /*_*/mca_farm_wikis (
    mfw_farm_id VARBINARY(32) NOT NULL,
    mfw_wiki_id VARBINARY(255) NOT NULL,
    PRIMARY KEY (mfw_farm_id, mfw_wiki_id)
) /*$wgDBTableOptions*/;
