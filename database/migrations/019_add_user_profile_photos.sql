SET @profile_photo_data_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'profile_photo_data'
);
SET @profile_photo_data_sql = IF(
    @profile_photo_data_exists = 0,
    'ALTER TABLE users ADD COLUMN profile_photo_data MEDIUMBLOB NULL AFTER status',
    'SELECT 1'
);
PREPARE profile_photo_data_statement FROM @profile_photo_data_sql;
EXECUTE profile_photo_data_statement;
DEALLOCATE PREPARE profile_photo_data_statement;

SET @profile_photo_mime_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'profile_photo_mime_type'
);
SET @profile_photo_mime_sql = IF(
    @profile_photo_mime_exists = 0,
    'ALTER TABLE users ADD COLUMN profile_photo_mime_type VARCHAR(32) NULL AFTER profile_photo_data',
    'SELECT 1'
);
PREPARE profile_photo_mime_statement FROM @profile_photo_mime_sql;
EXECUTE profile_photo_mime_statement;
DEALLOCATE PREPARE profile_photo_mime_statement;
