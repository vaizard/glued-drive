-- migrate:up

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;

DROP TABLE IF EXISTS `t_stor_objects`;
CREATE TABLE `t_stor_objects` (
    `c_uuid` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),true)) COMMENT 'File uuid (used to anonymize files)',
    `c_json` json NOT NULL COMMENT 'Files metadata.',
    `c_sha512` char(128) GENERATED ALWAYS AS (json_unquote(json_extract(`c_json`,'$.data.sha512'))) VIRTUAL NOT NULL,
    PRIMARY KEY (`c_uuid`),
    UNIQUE KEY `c_sha512` (`c_sha512`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC COMMENT='Content aware storage objects table. An object is a file identified by its sha512 hash (see the c_sha512 generated from c_json).';

DROP TABLE IF EXISTS `t_stor_links`;
CREATE TABLE `t_stor_links` (
    `c_uuid` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),true)) COMMENT 'Link UUID.',
    `c_core_user` binary(16) NOT NULL COMMENT 'Link owner (creator).',
    `c_filename` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'User friendly filename.',
    `c_inherit_table` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Authorization inherited from table:object pair.',
    `c_inherit_object` int DEFAULT NULL COMMENT 'Authorization inherited from table:object pair.',
    `c_ts_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp link created.',
    `c_stor_sha512` char(128) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL COMMENT 'Assigns a link to a specific object (row in t_stor_objects).',
    `c_stor_uuid` binary(16) NOT NULL COMMENT 'Assigns a link to a specific object (row in t_stor_objects).',
    PRIMARY KEY (`c_uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC COMMENT='Content aware storage links table. Entries in t_stor_objects are unique, t_stor_links provides links to appropriate locations with a user-friendly name.';

/*!40101 SET character_set_client = @saved_cs_client */;

-- migrate:down

DROP TABLE `t_stor_links`;
DROP TABLE `t_stor_objects`;