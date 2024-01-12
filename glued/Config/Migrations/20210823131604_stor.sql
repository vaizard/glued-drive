-- migrate:up

SET NAMES utf8;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

SET NAMES utf8mb4;

DROP TABLE IF EXISTS `t_stor_bucket_dgs`;
CREATE TABLE `t_stor_bucket_dgs` (
                                     `bucket` binary(16) NOT NULL COMMENT 'Bucket UUID (foreign key)',
                                     `dg` binary(16) NOT NULL COMMENT 'Bucket device group UUID (foreign key)',
                                     UNIQUE KEY `unique_bucket_dg` (`bucket`,`dg`),
                                     KEY `bucket_uuid` (`bucket`),
                                     KEY `dg` (`dg`),
                                     CONSTRAINT `t_stor_bucket_dgs_ibfk_1` FOREIGN KEY (`bucket`) REFERENCES `t_stor_buckets` (`uuid`) ON DELETE RESTRICT ON UPDATE CASCADE,
                                     CONSTRAINT `t_stor_bucket_dgs_ibfk_2` FOREIGN KEY (`dg`) REFERENCES `t_stor_dgs` (`uuid`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Stor Buckets are isolated containers for links with assignable storage device groups (dgs)';


DROP TABLE IF EXISTS `t_stor_buckets`;
CREATE TABLE `t_stor_buckets` (
                                  `uuid` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),true)) COMMENT 'Bucket UUID',
                                  `data` json NOT NULL COMMENT 'JSON data',
                                  `name` varchar(255) GENERATED ALWAYS AS (coalesce(json_unquote(json_extract(`data`,_utf8mb4'$.name')),_utf8mb4'bucket')) VIRTUAL COMMENT 'Generated name from JSON',
                                  PRIMARY KEY (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Stor Buckets are isolated containers for links with assignable storage device groups (dgs)';


DROP TABLE IF EXISTS `t_stor_configlog`;
CREATE TABLE `t_stor_configlog` (
                                    `uuid` binary(16) NOT NULL COMMENT 'Device / device group UUID.',
                                    `data` json NOT NULL COMMENT 'Config JSON.',
                                    `hash` binary(16) GENERATED ALWAYS AS (unhex(md5(`data`))) STORED COMMENT 'JSON hash.',
                                    `ts_logged` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Config update logged timestamp.',
                                    UNIQUE KEY `uuid_hash` (`uuid`,`hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Stor devices/dgs config log';


DROP TABLE IF EXISTS `t_stor_devices`;
CREATE TABLE `t_stor_devices` (
                                  `uuid` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),true)) COMMENT 'Device group UUID',
                                  `data` json NOT NULL COMMENT 'Device group JSON',
                                  PRIMARY KEY (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Stor devices.';


DROP TABLE IF EXISTS `t_stor_dgs`;
CREATE TABLE `t_stor_dgs` (
                              `uuid` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),true)) COMMENT 'Device group UUID',
                              `data` json NOT NULL COMMENT 'Device group JSON',
                              `ts_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Created timestamp.',
                              `ts_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Changed timestamp.',
                              PRIMARY KEY (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Stor Buckets are isolated containers for links with assignable storage device groups (dgs)';


DROP TABLE IF EXISTS `t_stor_links`;
CREATE TABLE `t_stor_links` (
                                `uuid` binary(16) NOT NULL COMMENT 'Link UUID.',
                                `bucket` binary(16) NOT NULL COMMENT 'Bucket UUID.',
                                `object` binary(16) NOT NULL COMMENT 'Data object UUID.',
                                `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT 'file' COMMENT 'Default filename.',
                                PRIMARY KEY (`uuid`),
                                KEY `bucket` (`bucket`),
                                KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC COMMENT='Content aware storage links table. Entries in t_stor_objects are unique, t_stor_links provides links to appropriate locations with a user-friendly name.';


DROP TABLE IF EXISTS `t_stor_links_meta`;
CREATE TABLE `t_stor_links_meta` (
                                     `uuid` binary(16) NOT NULL COMMENT 'Link UUID',
                                     `namespace` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Metadata namespace',
                                     `data` json NOT NULL COMMENT 'Metadata json',
                                     PRIMARY KEY (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='App specific metadata related to a particular link';


DROP TABLE IF EXISTS `t_stor_links_rels`;
CREATE TABLE `t_stor_links_rels` (
                                     `uuid1` binary(16) NOT NULL COMMENT 'Link UUID 1',
                                     `uuid2` binary(16) NOT NULL COMMENT 'Link UUID 2',
                                     `type` varchar(64) NOT NULL COMMENT 'Relationship type',
                                     `ts_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Create timestamp',
                                     KEY `uuid1` (`uuid1`),
                                     KEY `uuid2` (`uuid2`),
                                     KEY `type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Relationships between links';


DROP TABLE IF EXISTS `t_stor_objects`;
CREATE TABLE `t_stor_objects` (
                                  `c_uuid` binary(16) GENERATED ALWAYS AS (uuid_to_bin(json_unquote(json_extract(`c_data`,_utf8mb4'$.uuid')),true)) STORED NOT NULL,
                                  `c_data` json NOT NULL COMMENT 'Object metadata',
                                  `c_size` bigint GENERATED ALWAYS AS (cast(json_unquote(json_extract(`c_data`,_utf8mb4'$.size')) as unsigned)) STORED COMMENT 'Object size',
                                  `c_mime` varchar(80) GENERATED ALWAYS AS (json_unquote(json_extract(`c_data`,_utf8mb4'$.mime.type'))) STORED COMMENT 'Object MIME type',
                                  `c_ext` varchar(32) GENERATED ALWAYS AS (json_unquote(json_extract(`c_data`,_utf8mb4'$.mime.ext'))) STORED COMMENT 'Object extension',
                                  `c_seekhash` binary(16) GENERATED ALWAYS AS (unhex(json_unquote(json_extract(`c_data`,_utf8mb4'$.hash.md5')))) STORED COMMENT 'Indexed / seekable hash (md5)',
                                  `c_mainhash` binary(64) GENERATED ALWAYS AS (unhex(json_unquote(json_extract(`c_data`,_utf8mb4'$.hash.sha3-512')))) STORED COMMENT 'Main hash (sha3-512)',
                                  `c_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp object created',
                                  PRIMARY KEY (`c_uuid`),
                                  UNIQUE KEY `unique_mainhash` (`c_mainhash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC COMMENT='Content addressable storage data objects.';


DROP TABLE IF EXISTS `t_stor_objects_replicas`;
CREATE TABLE `t_stor_objects_replicas` (
                                           `object` binary(16) NOT NULL COMMENT 'Object UUID',
                                           `device` binary(16) NOT NULL COMMENT 'Device UUID',
                                           `ts_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp from when object is scheduled for balancing onto device.',
                                           `ts_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Timestamp from when object was last tested/modified on its balanced/consistent status',
                                           `ts_locked` timestamp NULL DEFAULT NULL COMMENT 'Timestamp until when object is locked for other operations on device.',
                                           `balanced` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Object is present on devices',
                                           `consistent` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Object file size and hash on device matches that which is stored in the database.'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


DROP TABLE IF EXISTS `t_stor_statuslog`;
CREATE TABLE `t_stor_statuslog` (
                                    `uuid` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),true)) COMMENT 'Status line UUID',
                                    `uuid_dg` binary(16) NOT NULL COMMENT 'Dg UUID',
                                    `uuid_dev` binary(16) NOT NULL COMMENT 'Device UUID',
                                    `prio` int NOT NULL DEFAULT '1000' COMMENT 'Device priority in dg',
                                    `role` varchar(16) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT 'undefined' COMMENT 'Device role dg',
                                    `data` json NOT NULL COMMENT 'Device status JSON',
                                    `health` varchar(16) GENERATED ALWAYS AS (coalesce(json_unquote(json_extract(`data`,_utf8mb4'$.health')),_utf8mb4'undefined')) VIRTUAL COMMENT 'Device health status',
                                    `ts_created` datetime(1) DEFAULT CURRENT_TIMESTAMP(1) COMMENT 'Timestamp Created with precision of 1 second',
                                    `ts_updated` datetime(1) DEFAULT CURRENT_TIMESTAMP(1) ON UPDATE CURRENT_TIMESTAMP(1) COMMENT 'Timestamp Updated with precision of 1 second',
                                    `boundary` bigint GENERATED ALWAYS AS ((floor((`ts_created` / 86400)) * 86400)) STORED COMMENT 'Boundary timestamp rounded to 24 hours (86400 seconds)',
                                    `data_hash` binary(16) GENERATED ALWAYS AS (unhex(md5(`data`))) STORED COMMENT 'Device status hash',
                                    `uniq_hash` binary(16) GENERATED ALWAYS AS (unhex(md5(concat(`uuid_dg`,`uuid_dev`,`data`,`boundary`)))) STORED COMMENT 'Unique row hash',
                                    PRIMARY KEY (`uuid`),
                                    UNIQUE KEY `uk_uniq_hash` (`uniq_hash`),
                                    KEY `idx_health_prefix` (`health`(2))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Stor devices/dgs status log. Uniqueness based on Device UUID, partial hash, and creation timestamp (24 hours intervals)';

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;

CREATE OR REPLACE VIEW v_stor_status AS
WITH RankedStatus AS (
    SELECT
        t_stor_statuslog.uuid_dg AS uuid_dg,
        t_stor_statuslog.uuid_dev AS uuid_dev,
        t_stor_statuslog.ts_updated AS ts_updated,
        t_stor_statuslog.health AS health,
        t_stor_statuslog.prio AS prio,
        t_stor_statuslog.role AS role,
        ROW_NUMBER() OVER (PARTITION BY t_stor_statuslog.uuid_dg,t_stor_statuslog.uuid_dev ORDER BY t_stor_statuslog.ts_created DESC) AS row_num
    FROM
        t_stor_statuslog
)
Select
    BIN_TO_UUID(status.uuid_dg, true) as dg_uuid,
    BIN_TO_UUID(status.uuid_dev, true) as dev_uuid,
    IFNULL(status.ts_updated, CAST(NOW() AS DATETIME)) AS status_ts,
    IFNULL(status.health, 'undefined') AS dev_health,
    status.prio AS dev_prio,
    status.role AS dev_role,
    ifnull(dev.data->>'$.adapter','unknown') AS dev_adapter,
    dgs.data AS dg_data,
    dev.data AS dev_data,
    (CASE WHEN (
        SUM((CASE WHEN (IFNULL(status.health, 'empty') <> 'online') THEN 1 ELSE 0 END)) OVER (PARTITION BY status.uuid_dg) > 0
        ) THEN 'degraded' ELSE 'online' END) AS dg_health
from RankedStatus status
         left join t_stor_dgs dgs ON (status.uuid_dg = dgs.uuid)
         left join t_stor_devices dev ON (status.uuid_dev = dev.uuid)
where status.row_num = 1
GROUP BY
    dg_uuid,
    dev_uuid,
    dg_data,
    status_ts,
    dev_health,
    dev_prio,
    dev_role,
    dev_adapter,
    dev_data,
    status.health,
    status.uuid_dg;


/*!40101 SET character_set_client = @saved_cs_client */;

-- migrate:down

DROP TABLE `t_stor_links`;
DROP TABLE `t_stor_objects`;
DROP VIEW `v_stor_status`;