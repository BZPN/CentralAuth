-- Add mca_suppressed_wikis table
CREATE TABLE IF NOT EXISTS /*_*/mca_suppressed_wikis (
    msw_user_id INT UNSIGNED NOT NULL,
    msw_wiki_id VARBINARY(255) NOT NULL,
    PRIMARY KEY (msw_user_id, msw_wiki_id)
) /*$wgDBTableOptions*/;
