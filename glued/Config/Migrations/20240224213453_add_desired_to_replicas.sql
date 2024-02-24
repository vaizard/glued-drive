-- migrate:up

ALTER TABLE `t_stor_objects_replicas`
ADD `desired` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Object is desired on device (1 = desired, 0 = not desired). By default the desired state is set to 1, in case that the object is to be deleted, this is to be set to 0. Objects marked with 0 are to be expunged (and once this happens, the particular line in this table deleted)';

-- migrate:down

ALTER TABLE `t_stor_objects_replicas`
DROP `desired`;