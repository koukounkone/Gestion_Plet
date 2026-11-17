-- Record the account creator's confirmation that they are authorized to use
-- the service for the farm. Apply after 002 on an existing installation.
ALTER TABLE user_consents
    ADD COLUMN authorization_confirmed TINYINT(1) NOT NULL DEFAULT 1
        AFTER privacy_version,
    ADD CONSTRAINT chk_user_consents_authorization
        CHECK (authorization_confirmed = 1);
