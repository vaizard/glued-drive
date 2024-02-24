<?php

declare(strict_types=1);

namespace Glued\Controllers;

use Exception;
use mysqli_sql_exception;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ramsey\Uuid\Uuid;


class ServiceController extends AbstractController
{

    /// ///////////////////////////////////////////////////////////////////////////////
    /// HELPER STUFF
    /// ///////////////////////////////////////////////////////////////////////////////
    private $pkey = 'jwV84GGVLq1SBewdbqtsY6haWHsKfmOy9MM6aW1RrnU7NmFelo';
    /**
     * Returns an exception.
     * @param  Request  $request
     * @param  Response $response
     * @param  array    $args
     * @return Response Json result set.
     */
    public function stub(Request $request, Response $response, array $args = []): Response {
        throw new Exception('Stub method served where it shouldnt. Proxy misconfigured?');
    }

    function is_uuidv4($uuid) {
        if (!is_string($uuid)) { return 0; }
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        return preg_match($pattern, $uuid) === 1;
    }

    function is_valid_uuid($uuid)
    {
        $uuidv4_pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        $uuidv1_pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-1[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        return preg_match($uuidv4_pattern, $uuid) || preg_match($uuidv1_pattern, $uuid);
    }

    /**
     * @param $value accepts strings and numbers, such as (100, 100mb, 100g, etc.)
     * @param $from_unit forces the from unit (unit in value is ignored). Accepts: b, kb, KB, kB ... etc.
     * @param $to_unit defines target unit. If nothing provided, bytes are implied.
     * @return float|int
     * @throws Exception
     */
    public function convert_units($value, $from_unit = '', $to_unit = 'B') {
        $units = array('B' => 0, 'K' => 1, 'M' => 2, 'G' => 3, 'T' => 4);
        $from_unit = strtoupper(substr($from_unit,0,1));
        $to_unit = strtoupper(substr($to_unit,0,1));
        if ($from_unit == '') {
            $from_unit = substr(strtoupper(preg_replace('/\d/', '', $value)),0,1);
            if ($from_unit == '') { $from_unit = 'B'; }
        }
        $value = preg_replace("/[^0-9\.]/", "", $value);
        if (!array_key_exists($from_unit, $units)) throw new Exception("Unexpected from unit provided.", 500);
        if (!array_key_exists($to_unit, $units)) throw new Exception("Unexpected to unit provided.", 500);
        $from_power = $units[$from_unit];
        $to_power = $units[$to_unit];
        $factor = pow(1024,  $from_power - $to_power );
        return $value * $factor;
    }


    /// ///////////////////////////////////////////////////////////////////////////////
    /// DEVICES
    /// ///////////////////////////////////////////////////////////////////////////////

    /**
     * @param $d array device config
     * @return array
     */
    public function device_status(array $d): array {
        $d['uri'] = $d['path'];

        $missingKeys = array_diff_key(array_flip(["uuid", "adapter", "uri"]), $d);
        if (!empty($missingKeys)) {
            $s = implode(', ', array_keys($missingKeys));
            throw new Exception("Device status requested without {$s} defined.");
        }

        $msg = [];
        $s = [
            "health" => "unknown",
            "message" => []
        ];


        if (!$this->is_uuidv4($d["uuid"])) {
            $s["health"] = "degraded";
            $msg[] = "Device key `{$d["uuid"]}` is not a UUIDv4.";
        }

        if ( $d["adapter"] == "filesystem" and PHP_OS == "Linux") {
            if (is_dir($d["uri"] ?? "")) {
                $output = json_decode(shell_exec("findmnt -DJv --output fstype,source,target,fsroot,options,size,used,avail,use%,uuid,partuuid --target " . $d["path"]));
                $s["adapter"] = (array) $output->filesystems[0];
                if (is_writable($d["uri"])) {
                    $s["health"] = "online";
                    $msg[] = "ok";
                } else {
                    $s["health"] = "degraded";
                    $msg[] = "path `{$d["path"]}` is not writable";
                }
            } else {
                $d["health"] = "offline";
                $msg[] = "`{$d["path"]}` is not a directory or is missing";
            }
        }

        $s['message'] = implode('; ', $msg);
        if ($s["health"] != "online") { $this->notify->send("Storage device `{$d["uuid"]}` `{$s["health"]}`: {$s["message"]}", notify_admins: true); }
        return $s;
    }

    function findDeviceDgs($data, $uuid)
    {
        $res = [];
        foreach ($data as $item) {

            foreach (($item['devices'] ?? []) as $k => $device) {
                if ($device['uuid'] === $uuid) {
                    $subres = $item['devices'][$k];
                    $subres['uuid'] = $item['uuid'];
                    $res[] = $subres;

                    break; // Stop searching for this device once found
                }
            }
        }
        return $res;
    }


    public function devices($uuid = null, $log = true) {
        $res = $this->settings["stor"]["devices"];
        $dgs = $this->dgs();
        $key = "09e0ef57-86e6-4376-b466-a7d2af31474e";
        if (array_key_exists($key, $res)) { $res[$key]["path"] = $this->settings["glued"]["datapath"] . "/" . basename(__ROOT__)  . "/data"; }
        $key = "86159ff5-f513-4852-bb3a-7f4657a35301";
        if (array_key_exists($key, $res)) { $res[$key]["path"] = sys_get_temp_dir(); }
        if ($uuid) {
            if (array_key_exists($uuid,$res)) {
                $res = array_intersect_key($res, [$uuid => null]); // return $res containing the only key, $uuid
            }
            else return [];
        }
        foreach ($res as $k=>&$v) {
            $v["uuid"] = $k;
            $v["status"] = $this->device_status($v);
            $v["status"]["dgs"] = $this->findDeviceDgs($dgs, $k);
        }

        if ($log == 1) {
            $q1 = "INSERT INTO `t_stor_devices` (`uuid`, `data`) VALUES (uuid_to_bin(?, true), ?) ON DUPLICATE KEY UPDATE `data` = VALUES(`data`)";
            $q2 = "INSERT IGNORE INTO `t_stor_configlog` (`uuid`, `data`) VALUES (uuid_to_bin(?, true), ?) ON DUPLICATE KEY UPDATE `ts_logged` = CURRENT_TIMESTAMP(1)";
            $q3 = "INSERT INTO `t_stor_statuslog` (`uuid_dev`, `data`, `uuid_dg`, `role`, `prio`) VALUES (uuid_to_bin(?, true), ?, uuid_to_bin(?, true), ?, ?) ON DUPLICATE KEY UPDATE `ts_updated` = CURRENT_TIMESTAMP(1);";
            $s1 = $this->mysqli->prepare($q1);
            $s2 = $this->mysqli->prepare($q2);
            $s3 = $this->mysqli->prepare($q3);

            foreach ($res as $k => $vv) {
                $s = $vv['status'] ?? [];
                unset($vv['status']);
                $vv = json_encode($vv ?? []);
                $this->mysqli->begin_transaction();
                $s1->bind_param("ss", $k, $vv);
                $s1->execute();
                $s2->bind_param("ss", $k, $vv);
                $s2->execute();
                foreach ($s["dgs"] as $dg) {
                    $ss = json_encode($s);
                    $s3->bind_param("ssssd", $k, $ss, $dg['uuid'],$dg['role'],$dg['prio']);
                    $s3->execute();
                }
                $this->mysqli->commit();
            }
        }
        $res = array_values($res);
        if (!is_null($uuid)) { return $res[0]; }
        return $res;
    }


    public function devices_r1(Request $request, Response $response, array $args = []): Response {
        $res = $this->devices($args['id'] ?? null);
        if ($res == []) { return $response->withJson(['message' => "Not found."])->withStatus(404); }
        return $response->withJson(['data' => $res]);

    }

    /// ///////////////////////////////////////////////////////////////////////////////
    /// DGS
    /// ///////////////////////////////////////////////////////////////////////////////


    function get_dg_uuid($uuid) {
        //if ($uuid == 'default') { $uuid = $this->get_default_dg(); }
        if (array_key_exists($uuid, $this->settings['stor']['dgs'])) { return $uuid; }
        throw new Exception("Dg `{$uuid}` used as device parent but not configured.", 500);
        // TODO replace with notification
    }

    public function dgs($uuid = null, $log = true)
    {
        $dgs = $this->settings['stor']['dgs'] ?? [];
        foreach ($dgs as $key => &$item) {
            $item['uuid'] = $key;
            if (!array_key_exists('devices', $item)) { $item['devices'] = []; }

            // Process each "device" and validate "uuid"
            foreach ($item['devices'] as $k => &$device) {
                    if (isset($device['uuid'])) {
                        $device['prio'] = isset($device['prio']) ? $device['prio'] : 1000;
                        if (!$this->is_uuidv4($device['uuid'])) {
                            // Handle case where "uuid" is not set or is not a valid UUIDv4 (remove entry)
                            $item['status']['message'][] = "Device UUID {$device['uuid']} is not a valid UUID string.";
                            unset($item['devices'][$k]);
                            if (!array_key_exists($device['uuid'], $this->settings['stor']['devices'])) {
                                $item['status']['message'][] = "Device {$device['uuid']} undefined.";
                                unset($item['devices'][$k]);
                            }
                        }
                    } else {
                        $item['status']['message'][] = "Dg contains a device item without the mandatory uuid.";
                    }
            }

            // Remove any null values caused by invalid devices
            $item['devices'] = array_values(array_filter($item['devices']));
            $item['status']['members'] = count($item['devices']);

            // Sort devices by "prio"
            if ($item['status']['members'] > 0) {
                usort($item['devices'], function ($a, $b) {
                    return $a['prio'] - $b['prio'];
                });
            } else {
                $item['status']['message'][] = "Dg doesn't have any devices configured.";
            }
        }

        if ($log == true) {
            $q1 = "INSERT INTO `t_stor_dgs` (`uuid`, `data`) VALUES (uuid_to_bin(?, true), ?) ON DUPLICATE KEY UPDATE `data` = VALUES(`data`)";
            $q2 = "INSERT IGNORE INTO `t_stor_configlog` (`uuid`, `data`) VALUES (uuid_to_bin(?, true), ?)";
            foreach ($dgs as $k => &$v) {
                $vv = $v;
                unset($vv['status']);
                $data = [$k, json_encode($vv ?? [])];
                $this->mysqli->begin_transaction();
                $this->mysqli->execute_query($q1, $data);
                if ($this->mysqli->affected_rows > 0) { $v['log'] = "New dg `{$k}` configured."; }
                $this->mysqli->execute_query($q2, $data);
                if ($this->mysqli->affected_rows > 0) { $v['log'] = "Dg `{$k}` configuration updated."; }
                $this->mysqli->commit();
            }
        }

        $res = array_values($dgs);
        if (!is_null($uuid)) { return $res[0]; }
        return $res;
    }

    public function dgs_r1(Request $request, Response $response, array $args = []): Response {
        $res = $this->dgs($args['id'] ?? null);
        if ($res == []) { return $response->withJson(['message' => "Not found."])->withStatus(404); }
        return $response->withJson(['data' => $res]);
    }

    /// ///////////////////////////////////////////////////////////////////////////////
    /// BUCKETS
    /// ///////////////////////////////////////////////////////////////////////////////


    public function get_buckets($bucket = null): array
    {
        $where = '';
        if (!is_null($bucket)) {
            $where = 'WHERE bucket = uuid_to_bin(?, true)';
            $arg = [ $bucket ];
        }

        $q = "
        SELECT 
          bin_to_uuid(b.`uuid`, 1) AS `bucket`,
          b.name AS `name`,
          bin_to_uuid(bd.`dg`, 1) AS `dg`,
          s.dg_health,
          s.dev_uuid,
          s.dev_health,
          s.dev_prio,
          s.dev_role,
          s.dev_adapter,
          s.status_ts as ts
        FROM `t_stor_buckets` b
        LEFT JOIN t_stor_bucket_dgs bd ON bd.bucket = b.uuid
        LEFT JOIN (
          SELECT dg_uuid, dg_health, dev_uuid, dev_health, status_ts, dev_prio, dev_role, dev_adapter
          FROM v_stor_status
        ) s ON s.dg_uuid = bin_to_uuid(bd.dg, 1)
        {$where}
        ORDER BY 
          bucket,
          CASE WHEN s.dev_health = 'online' THEN 0 ELSE 1 END,
          dev_prio
        ";

        $res = $this->mysqli->execute_query($q, $arg ?? []);
        $data = $res->fetch_all(MYSQLI_ASSOC);
        if ($data == []) { return []; }
        $unflat = [];


        foreach ($data as $row) {
            // Check if the bucket already exists in the unflattened result
            if (!isset($unflat[$row['bucket']])) {
                // If not, create a new entry for the bucket
                $unflat[$row['bucket']] = [
                    'uuid' => $row['bucket'],
                    'name' => $row['name'],
                    'ts' => $row['ts'],
                    'dgs' => [],
                    'devices' => [],
                ];
            }

            // Check if the dg already exists in the dgs array
            $dgExists = false;
            foreach ($unflat[$row['bucket']]['dgs'] as &$dgArray) {
                if ($dgArray['uuid'] === $row['dg']) {
                    $dgExists = true;
                    break;
                }
            }
            // If the dg does not exist, create a new entry for dg
            if (!$dgExists) {
                $unflat[$row['bucket']]['dgs'][] = [
                    'uuid' => $row['dg'],
                    'health' => $row['dg_health'],
                ];
            }

            // Check if the dg already exists in the dgs array
            $devExists = false;
            foreach ($unflat[$row['bucket']]['devices'] as &$devArray) {
                if ($devArray['uuid'] === $row['dev_uuid']) {
                    $devExists = true;
                    break;
                }
            }

            // If the dg does not exist, create a new entry for dg
            if (!$devExists) {
                $unflat[$row['bucket']]['devices'][] = [
                    'uuid' => $row['dev_uuid'],
                    'health' => $row['dev_health'],
                    'role' => $row['dev_role'],
                    'prio' => $row['dev_prio'],
                    'adapter' => $row['dev_adapter'],
                ];
            }
        }
        $res = array_values($unflat);
        if (!is_null($bucket)) {
            $res = $res[0];
        }
        return $res;
    }
    public function buckets_r1(Request $request, Response $response, array $args = []): Response {
        $params = $request->getQueryParams();
        $b = $this->get_buckets($args['id'] ?? null);
        # TODO empty bukcets https://glued.industra.space/api/stor/v1/buckets
        if ($b == []) { throw new \Exception("Bucket `{$args['id']}` not found.", 404); }
        $data = [
            'timestamp' => microtime(),
            'status' => 'Buckets r1 OK',
            'data' => $b,
            'service' => basename(__ROOT__),
        ];
        return $response->withJson($data);
    }

    public function buckets_c1(Request $request, Response $response, array $args = []): Response {
        $params = $request->getQueryParams();
        $this->status();
        // TODO replace with propper validation
        $contentTypeHeader = $request->getHeaderLine('Content-Type') ?? '';
        if ($contentTypeHeader !== 'application/json') { throw new Exception('Invalid Content-Type. Please set `Content-Type: application/json', 400); }
        $body = $request->getParsedBody();
        $uuid = Uuid::uuid4()->toString();
        if (!isset($body['name'])) { throw new Exception('Mandatory $.name not provided.'); }
        if (!isset($body['dgs'])) { throw new Exception('Mandatory $.dgs not provided.'); }
        if (!is_array($body['dgs'])) { throw new Exception('Mandatory $.dgs is not a list (array).'); }

        $q1 = "INSERT INTO `t_stor_buckets` (`uuid`, `data`) VALUES (uuid_to_bin(?,true), ?)";
        $q2 = "INSERT INTO `t_stor_bucket_dgs` (`bucket`,`dg`) VALUES (uuid_to_bin(?,true), uuid_to_bin(?,true))";

        try {
            $this->mysqli->begin_transaction();
            $this->mysqli->execute_query($q1, [ $uuid, json_encode($body) ]);
            foreach ($body['dgs'] as $dg) { $this->mysqli->execute_query($q2, [$uuid, $dg]); }
            $this->mysqli->commit();
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() === 1452) { throw new Exception("Unknown devicegroup UUID.", 400); }
            elseif ($e->getCode() === 1062) { throw new Exception("The bucket:devicegroup association already exists.", 400);
            } else { throw $e; }
        }

        $data = [
            'timestamp' => microtime(),
            'status' => "Bucket {$uuid} ({$body['name']}) created.",
            'params' => $params,
            'service' => basename(__ROOT__),
        ];
        return $response->withJson($data);
    }

    /// ///////////////////////////////////////////////////////////////////////////////
    /// OBJECTS (data objects)
    /// ///////////////////////////////////////////////////////////////////////////////

    /**
     * Gets all files in a space
     * @param  Request  $request
     * @param  Response $response
     * @param  array    $args
     * @return Response Json result set.
     */

    private function get_hardlink($file, $bucket = ''): array
    {
        $stat = stat($file);
        if ($stat !== false) {
            $linkCount = $stat[11];
            if ($linkCount > 1) {
                $command = "find /var/www/html/data/glued-stor/data/buckets/{$bucket} -inum {$stat['ino']} 2>/dev/null";
                $hardlinks = [];
                exec($command, $hardlinks);
            }
        }
        return ($hardlinks ?? []);
    }


    function name_to_path($name, $length = 4): string
    {
        if (strlen($name) >= $length) {
            return implode('/', str_split(substr($name, 0, $length)));
        } else {
            throw new \Exception("Filename is too short.");
        }
    }

    function object_meta($file): array {
        $path = $file->getStream()->getMetadata('uri');
        $mime = mime_content_type($path) ?? $file->getClientMediaType() ?? null;
        $data = [
            'name' => $file->getClientFilename(),
            'size' => $file->getSize(),
            'hash' => [
                'sha3-512' => hash_file('sha3-512', $path),
                'md5' => hash_file('md5', $path)
            ],
            'mime' => [
                'type' => $mime,
                'ext' => $mime && array_key_exists($mime, $this->utils->mime2ext) ? $this->utils->mime2ext[$mime][0] : ($file->getClientFilename() ? pathinfo($file->getClientFilename(), PATHINFO_EXTENSION) : null),
            ],
        ];
        return $data;
    }

    private function patch_object_meta($objUUID, $meta = [])
    {
        $q = " 
        INSERT INTO `t_stor_objects_meta` 
            (`uuid`, `data`) VALUES (uuid_to_bin(?, 1), ?) ON DUPLICATE KEY UPDATE data = JSON_MERGE_PATCH(data, ?);
        ";
        return $this->mysqli->execute_query($q, [ $objUUID, json_encode($meta), json_encode($meta) ]);
    }

    private function add_object_refs($objUUID, $refNs, $refKind, $refUUID)
    {
        if (!$this->is_uuidv4($objUUID) && !$this->is_uuidv4($refUUID) && !is_string($refNs) && !is_string($refKind)) return false;
        if ($refNs === '' || $refKind === '') return false;
        $q = " 
        INSERT INTO `t_stor_objects_refs` (`obj`, `ref_ns`, `ref_kind`, `ref_val`) 
        VALUES (uuid_to_bin(?, 1), ?, ?, uuid_to_bin(?, 1)) 
        ON DUPLICATE KEY UPDATE `ref_val` = values(`ref_val`)
        ";
        $this->mysqli->execute_query($q, [ $objUUID, $refNs, $refKind, $refUUID ]);
        return true;
    }

    private function del_object_refs($objUUID, $refNs, $refKind, $refUUID)
    {
        if (!$this->is_uuidv4($objUUID) && !$this->is_uuidv4($refUUID) && !is_string($refNs) && !is_string($refKind)) return false;
        if ($refNs === '' || $refKind === '') return false;
        $q = "
        DELETE FROM t_stor_objects_refs
        WHERE obj = uuid_to_bin(?, 1)
          AND ref_ns = ?
          AND ref_kind = ?
          AND ref_val = uuid_to_bin(?, 1)
        ";
        $this->mysqli->execute_query($q, [ $objUUID, $refNs, $refKind, $refUUID ]);
        return true;
    }

    private function object_refs($objUUID, $objRefs, $action = null): void {
        foreach ($objRefs as $rk => $refUUIDs) {
            $parts = explode(':', $rk, 2);
            if (count($parts) == 1) { array_unshift($parts, "_"); }
            list($refNs, $refKind) = $parts;
            if (is_string($refUUIDs)) { $refUUIDs = [ $refUUIDs ]; }
            if (!is_array($refUUIDs)) { throw new \Exception('Ref value must be a UUID or list of UUIDs.'); }
            foreach ($refUUIDs as $refUUID) {
                if ( $action === 'add' ) { $this->add_object_refs($objUUID, $refNs, $refKind, $refUUID); }
                elseif ( $action === 'del' ) { $this->del_object_refs($objUUID, $refNs, $refKind, $refUUID); }
                else { throw new \Exception('Bad action.'); }
            }
        }
    }

private function write_object($file, $bucket, $meta = null, $refs = null): array {

        // Filter writable devices (for now, only filesystem devices are allowed)
        foreach ($bucket['devices'] as &$dev) { $dev['path'] = $this->devices($dev['uuid'])['path'] ?? null; }
        $localDevices = array_filter($bucket['devices'], function ($device) {
            return isset($device["path"]) && is_string($device["path"]) &&
                $device["health"] == "online" && $device["role"] == "storage" && $device["adapter"] == "filesystem";
        });
        if (count($localDevices) == 0) { throw new \Exception('No local writable devices configured/online', 500); }
        $device = $localDevices[0];
        // Get uploaded file metadata
        $object = $this->object_meta($file);

        // File storing logic
        if ($device['adapter'] === 'filesystem') {
            // Ensure $file identified with hash($file) exists
            $hashDir  = "{$device['path']}/hashes/{$this->name_to_path($object['hash']['sha3-512'])}";
            $hashFile = "{$hashDir}/{$object['hash']['sha3-512']}";
            if (!file_exists($hashFile)) {
                if (!is_dir($hashDir)) { mkdir($hashDir, 0777, true); }
                $file->moveTo($hashFile);
            }
            // Ensure hashFile is hardlinked as objectFile. If objectFile already exists, do not use the generated objectUUID
            $objFile = $this->get_hardlink($hashFile, $bucket['uuid'])[0] ?? null;
            if (!is_null($objFile)) { $objUUID = basename($objFile); }
            else {
                $objUUID = Uuid::uuid4()->toString();
                $objDir  = "{$device['path']}/buckets/{$bucket['uuid']}/{$this->name_to_path($objUUID)}";
                $objFile = "{$objDir}/{$objUUID}";
                if (!is_dir($objDir)) { mkdir($objDir, 0777, true); }
                link($hashFile, $objFile);
            }
        } else { throw new \Exception('No suitable local device found'); }

        $q0 = "
        INSERT INTO `t_stor_files` (`c_data`) VALUES (?) ON DUPLICATE KEY UPDATE `c_data` = ?;
        ";
        $q1 = "
        INSERT INTO `t_stor_objects_replicas` 
            (`object`, `device`, `balanced`, `consistent`) VALUES (uuid_to_bin(?, 1), uuid_to_bin(?, 1), ?, ?)
        ON DUPLICATE KEY UPDATE `balanced` = ?, `consistent` = ?;
        ";
        $q2 = "
        INSERT INTO `t_stor_objects_replicas` 
            (`object`, `device`, `balanced`, `consistent`) VALUES (uuid_to_bin(?, 1), uuid_to_bin(?, 1), ?, ?)
        ON DUPLICATE KEY UPDATE `ts_updated` = NOW();
        ";

        $q3 = "
        INSERT INTO `t_stor_objects` (`bucket`,`object`,`hash`,`name`) VALUES (uuid_to_bin(?, 1), uuid_to_bin(?, 1), unhex(?), ?) ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);
        ";

        $this->mysqli->begin_transaction();
        $s0 = $this->mysqli->prepare($q0);
        $s1 = $this->mysqli->prepare($q1);
        $s2 = $this->mysqli->prepare($q2);
        $s3 = $this->mysqli->prepare($q3);

        $v0 = 0;
        $v1 = 1;
        $fn = $file->getClientFilename();
        $d = $device['uuid'];
        $jo = json_encode($object);
        $b = $bucket['uuid'];
        $h = $object['hash']['sha3-512'];

        $s0->bind_param("ss", $jo, $jo);
        $s0->execute();

        $s1->bind_param("ssiiii", $objUUID, $d, $v1, $v1, $v1, $v1);
        $s1->execute();

        foreach ($bucket['devices'] as $dev) {
            if ($dev['uuid'] == $d) continue;
            $dd = $dev['uuid'];
            $s2->bind_param("ssii", $objUUID, $dd, $v0, $v0);
            $s2->execute();
        }
        $s3->bind_param("ssss",$b,$objUUID,$h,$fn);
        $s3->execute();

        $this->mysqli->commit();

        $object['uuid'] = $objUUID;
        $object['link'] = $this->generateRetrievalUri($objUUID, $bucket['uuid']);
        if (isset($refs)) { $object['refs'] = $refs; }
        if (isset($meta)) { $object['meta'] = $meta; }
        return $object;
    }

    // TODO t_core_tokens now has only a c_inherit column. we'll need a c_owner column too, because user tokens will use c_inherit, but svc tokens will have c_owner (the one whom is the token management associated to)
    public function objects_c1(Request $request, Response $response, array $args = []): Response {
        // initial sanity checks
        if (!array_key_exists('bucket', $args)) { throw new Exception('Bucket not found.', 400); }
        if (isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > $this->convert_units(ini_get('post_max_size'))) {
            throw new Exception(
                'Upload size in bytes exceeds allowed limit ('.$_SERVER['CONTENT_LENGTH'].' > '.$this->convert_units(ini_get('post_max_size')).').',
                400);
        }

        $bucket = $this->get_buckets($args['bucket']);
        if ($bucket == []) { throw new \Exception("Bucket `{$args['bucket']}` not found.", 404); }

        $files        = $request->getUploadedFiles();
        $parsedBody   = $request->getParsedBody();
        $hdrUser      = $request->getHeader('X-glued-auth-uuid')[0] ?? null;
        $hdrAuth      = $request->getHeader('Authorization')[0] ?? null;

        $serverParams = $request->getServerParams();
        $headers      = $request->getHeaders();
        $headerValueArray = $request->getHeader('Accept');
        $headerValueString = $request->getHeaderLine('Accept');

        $meta = json_decode($parsedBody['meta'] ?? '{}', true);
        $refs = json_decode($parsedBody['refs'] ?? '{}', true);

        if (!empty($files)) {

            // flatten $files array to unify handling of file1=,file2=,file[]
            foreach ($files as $k => $f) {
                if (is_array($f)) { foreach ($f as $kk=>$ff) { $flattened[$k.'+'.$kk] = $ff; } }
                else { $flattened[$k] = $f; }
            }
            foreach ($flattened as $file) {
                if ($file->getError() === UPLOAD_ERR_OK) {
                    $objMeta = $meta[$file->getClientFilename()] ?? [];
                    $objRefs = $refs[$file->getClientFilename()] ?? [];
                    $res = $this->write_object($file, $bucket, $objMeta, $objRefs);
                    $this->mysqli->begin_transaction();
                    $this->patch_object_meta($res['uuid'], $objMeta);
                    $this->object_refs($res['uuid'], $objRefs, 'add');
                    $this->mysqli->commit();
                    $data[] = $res;
                } else {
                    // Add error message to response array
                    $data[] = array(
                        'name' => $file->getClientFilename(),
                        'error' => 'Failed to upload the file.',
                        'status' => 'error'
                    );
                }
            }
            return $response->withJson($data)->withStatus(200);
        } else {
            return $response->withJson([ 'error' => 'No files were attached to the request.' ])->withStatus(400);
        }
    }

    public function objects_r1(Request $request, Response $response, array $args = []): Response {
        $data = [
            'timestamp' => microtime(),
            'status' => 'Objects R1 OK',
            'service' => basename(__ROOT__),
            'data' => []
        ];

        if (!array_key_exists('bucket', $args)) { throw new Exception('Bucket not found.', 400); }
        $wm = '';
        $link = false;
        $pa = [ $args['bucket'] ];

        if (array_key_exists('object', $args)) {
            $wm .= " AND o.object = uuid_to_bin(? ,1)";
            $pa[] = $args['object'];
            $link = $this->generateRetrievalUri($args['object'], $args['bucket']);
            if (array_key_exists('element', $args)) {
                if ($args['element'] == 'get') { return $response->withHeader('Location', $link)->withStatus(302); }
            }
        }

        $qp = $request->getQueryParams();
        foreach ($qp as $k=>$v) {
            $kp = explode('.', str_replace('_', '.', $k), 2);
            if ($kp[0] != 'meta') { continue; }
            $patharr = explode('.', $kp[1] ?? '');
            $path = '"'.implode('"."',$patharr).'"';
            $wm .= 'AND CAST(JSON_UNQUOTE(JSON_EXTRACT(om.data, ?)) as CHAR) = ?';
            $pa[] = "$.{$path}";
            $pa[] = $v;
        }

        $q = "
        SELECT 
          -- bin_to_uuid(o.`bucket`,1) AS `bucket`,
          -- HEX(o.`hash`) AS `hash`,
          bin_to_uuid(o.`object`,1) AS `object`, 
          f.c_size as size,
          f.c_mime as mime,
          o.name as name,
          f.c_ext as ext,
          o.ts_created as created,
          CASE 
            WHEN om.data IS NULL OR JSON_LENGTH(om.data) = 0 THEN NULL
            ELSE om.data
          END AS meta,
          fwd.refs,
          back.backrefs
        FROM `t_stor_objects` o
        LEFT JOIN t_stor_files f ON f.c_hash = o.hash
        LEFT JOIN v_stor_refs_fwd fwd ON fwd.object = o.object
        LEFT JOIN v_stor_refs_back back ON back.object = o.object
        LEFT JOIN t_stor_objects_meta om ON  om.uuid = o.object
        WHERE bucket = uuid_to_bin(? ,1) {$wm}
        ";

        $handle = $this->mysqli->execute_query($q, $pa);
        $r = $handle->fetch_all(MYSQLI_ASSOC);
        if ($handle->num_rows == 0) {
            if ($this->get_buckets($args['bucket']) == []) { throw new \Exception('Bucket not found.', 404); }
            return $response->withJson($data);
        }
        foreach ($r as &$rr) {
            if (isset($rr['refs'])) { $rr['refs'] = json_decode($rr['refs']); }
            if (isset($rr['backrefs'])) { $rr['backrefs'] = json_decode($rr['backrefs']); }
            if (isset($rr['meta'])) { $rr['meta'] = json_decode($rr['meta']); }
        }
        if ($wm !== '') { $r = $r[0]; }
        if ($link) { $r['link'] = $link; }
        $data['data'] = $r;
        return $response->withJson($data);
    }


    public function objects_d1(Request $request, Response $response, array $args = []): Response
    {
        $candidate = false;
        if (!array_key_exists('bucket', $args)) { throw new Exception('Bucket UUID missing in request uri `/api/stor/v1/buckets/{bucket}/objects/{object}/{element}`.', 400); }
        if (!array_key_exists('object', $args)) { throw new Exception('Object UUID missing in request uri `/api/stor/v1/buckets/{bucket}/objects/{object}/{element}`.', 400); }
        //if (!array_key_exists('element', $args)) { throw new Exception('Element UUID missing in request uri `/api/stor/v1/buckets/{bucket}/objects/{object}/{element}`.', 400); }

        if (!isset($args['element'])) {
            $pa[] = $args['bucket'];
            $pa[] = $args['object'];
            $q = "
          SELECT 
              bin_to_uuid(o.`bucket`,1) AS `bucket`,
              HEX(o.`hash`) AS `hash`,
              bin_to_uuid(o.`object`,1) AS `object`, 
              f.c_size as size,
              f.c_mime as mime,
              o.name as name,
              f.c_ext as ext,
              o.ts_created as created,
              bin_to_uuid(bdgs.dg,1) as bucket_dg,
              status.dev_uuid as bucket_dev
            FROM `t_stor_objects` o
            LEFT JOIN t_stor_files f ON f.c_hash = o.hash
            LEFT JOIN t_stor_bucket_dgs bdgs ON bdgs.bucket = o.bucket
            LEFT JOIN v_stor_status status ON bdgs.dg = uuid_to_bin(status.dg_uuid,1)
            WHERE o.bucket = uuid_to_bin(? ,1) AND o.object = uuid_to_bin(? ,1) 
            ";

            $handle = $this->mysqli->execute_query($q, $pa);
            $candidate = $handle->fetch_all(MYSQLI_ASSOC) ?? [];
            if (!$candidate) { throw new \Exception("Trying to delete {$args['object']} object that doesn't exist"); };

            $this->mysqli->begin_transaction();
            $q1 = "delete from t_stor_objects where object = uuid_to_bin(?,1)";
            $this->mysqli->execute_query($q1, [$args['object']]);
            $q2 = "delete from t_stor_objects_meta where uuid = uuid_to_bin(?,1)";
            $this->mysqli->execute_query($q2, [$args['object']]);
            $q3 = "delete from t_stor_objects_refs where obj = uuid_to_bin(?,1) or ref_val = uuid_to_bin(?, 1)";
            $this->mysqli->execute_query($q3, [$args['object'],$args['object']]);
            $q4 = "INSERT INTO t_stor_objects_replicas (object, device, desired) VALUES (uuid_to_bin(?, 1), uuid_to_bin(?, 1), 0) ON DUPLICATE KEY UPDATE desired = VALUES(desired)";
            foreach ($candidate as $replica) {
                $this->mysqli->execute_query($q4, [$replica['object'],$replica['bucket_dev']]);
            }
            $this->mysqli->commit();
            $data['status'] = 200;
            $data['message'] = "Deleted object {$args['object']}, queued up for expunge.";
            return $response->withJson($data);


        } elseif ($args['element'] == 'refs') {
            $body = $request->getParsedBody();
            if (is_null($body)) {
                throw new \Exception('Request body must be a valid json', 400);
            }
            $this->object_refs($args['object'], $body, 'del');
            $data = [
                'timestamp' => microtime(),
                'status' => '200 Deleted',
                'service' => basename(__ROOT__),
                'data' => $body
            ];
            return $response->withJson($data)->withStatus(200);
        } else {
            throw new Exception('DELETE request supported elements are: `refs` and NULL.', 400);
        }

    }


    public function objects_put1(Request $request, Response $response, array $args = []): Response
    {
        if (!array_key_exists('bucket', $args)) { throw new Exception('Bucket UUID missing in request uri `/api/stor/v1/buckets/{bucket}/objects/{object}/{element}`.', 400); }
        if (!array_key_exists('object', $args)) { throw new Exception('Object UUID missing in request uri `/api/stor/v1/buckets/{bucket}/objects/{object}/{element}`.', 400); }
        if (!array_key_exists('element', $args)) { throw new Exception('Element UUID missing in request uri `/api/stor/v1/buckets/{bucket}/objects/{object}/{element}`.', 400); }
        if ($args['element'] == 'refs') {
            $body = $request->getParsedBody();
            if (is_null($body)) { throw new \Exception('Request body must be a valid json', 400); }
            $this->object_refs($args['object'], $body, 'add');
            $data = [
                'timestamp' => microtime(),
                'status' => '200 Added',
                'service' => basename(__ROOT__),
                'data' => $body
            ];
            return $response->withJson($data)->withStatus(200);
        } else {
            throw new Exception('PUT request supported elements are: `refs`.', 400);
        }
    }

    public function objects_p1(Request $request, Response $response, array $args = []): Response
    {
        if (!array_key_exists('bucket', $args)) { throw new Exception('Bucket UUID missing in request uri `/api/stor/v1/buckets/{bucket}/objects/{object}/{element}`.', 400); }
        if (!array_key_exists('object', $args)) { throw new Exception('Object UUID missing in request uri `/api/stor/v1/buckets/{bucket}/objects/{object}/{element}`.', 400); }
        if (!array_key_exists('element', $args)) { throw new Exception('Element UUID missing in request uri `/api/stor/v1/buckets/{bucket}/objects/{object}/{element}`.', 400); }
        if ($args['element'] == 'meta') {
            $body = $request->getParsedBody();
            if (is_null($body)) { throw new \Exception('Request body must be a valid json', 400); }
            $this->patch_object_meta($args['object'], $body);
            $data = [
                'timestamp' => microtime(),
                'status' => '200 Patched',
                'service' => basename(__ROOT__),
                'data' => $body
            ];
            return $response->withJson($data)->withStatus(200);
        } else {
            throw new Exception('PATCH request supported elements are: `meta`.', 400);
        }
    }

    /// ///////////////////////////////////////////////////////////////////////////////
    /// OBJECTS (data objects)
    /// ///////////////////////////////////////////////////////////////////////////////

    public function generateRetrievalKey($bkt, $obj, $ttl = 3600): string
    {
        $iat = time();
        $exp = (string) ($iat + $ttl);
        $nonce   = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES - strlen($exp) - 1);
        $key     = sodium_crypto_generichash($this->pkey . $exp, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $payload = sodium_crypto_secretbox("$bkt/$obj/$ttl/$exp", $nonce  . '/' . $exp, $key);
        $token   = sodium_bin2base64($payload, SODIUM_BASE64_VARIANT_URLSAFE)  . '/' . sodium_bin2base64($nonce, SODIUM_BASE64_VARIANT_URLSAFE)  . '/' . $exp;
        return $token;
    }

    function generateRetrievalUri($object, $bucket, $ttl = 3600): string {
        $base = $this->settings['glued']['protocol'].$this->settings['glued']['hostname'].$this->settings['routes']['be_stor_links_v1']['path'] . '/';
        $data = $base . $this->generateRetrievalKey($bucket, $object, 3600);
        return $data;
    }
    public function decodeRetrievalKey(string $token): ?array
    {
        // Split the token into payload, nonce, and expiration parts
        // Handle invalid tokens (all necessary token parts must be present)
        $tokenParts = explode('/', $token);
        if (count($tokenParts) !== 3) { return null; }
        list($payload, $nonce, $exp) = $tokenParts;

        // Decode the payload and nonce from base64
        // Handle tampered nonce/expiry, calculate the private key
        // Handle the case where decryption fails
        $payload = sodium_base642bin($payload, SODIUM_BASE64_VARIANT_URLSAFE);
        $nonce = sodium_base642bin($nonce, SODIUM_BASE64_VARIANT_URLSAFE);
        if (strlen("{$nonce}/{$exp}") !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) { return null; }
        $key = sodium_crypto_generichash($this->pkey . $exp, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

        // Decrypt the payload using the nonce, key, and additional data
        // Handle the case where decryption fails
        $decrypted = sodium_crypto_secretbox_open($payload, $nonce . '/' . $exp, $key);
        if ($decrypted === false) { return null; }
        list($bkt, $obj, $ttl, $exp) = explode('/', $decrypted);
        return [
            'bkt' => $bkt,
            'obj' => $obj,
            'iat' => (int) ($exp - $ttl),
            'exp' => (int) $exp,
        ];
    }

    public function links_r1(Request $request, Response $response, array $args = []): Response {
        $data = [
            'timestamp' => microtime(),
            'status' => 'ok',
            'service' => basename(__ROOT__)
        ];
        $params = $request->getQueryParams();
        if (!array_key_exists('token', $args)) { throw new Exception('Token not defined.', 400); }
        if (!array_key_exists('nonce', $args)) { throw new Exception('Nonce not defined.', 400); }
        if (!array_key_exists('exp', $args)) { throw new Exception('Expiry not defined.', 400); }
        $res = $this->decodeRetrievalKey("{$args['token']}/{$args['nonce']}/{$args['exp']}");
        if (is_null($res)) { $data['status'] = 'Not found'; return $response->withJson($data)->withStatus(404); }
        if ($args['exp'] < time()) { $data['status'] = 'Gone'; return $response->withJson($data)->withStatus(410); }

        $tree = $this->name_to_path($res['obj']);
        $file = "/var/www/html/data/glued-stor/data/buckets/{$res['bkt']}/$tree/{$res['obj']}";

        if (file_exists($file)) {
            $mime = mime_content_type($file);
            $response = $response
                ->withHeader('Content-Type', $mime)
                ->withHeader('Content-Security-Policy', 'upgrade-insecure-requests')
                ->withHeader('Content-Disposition', "inline;filename=\"{$res['obj']}.{$mime}\"")
                ->withHeader('Content-Length', filesize($file));
            $fileStream = fopen($file, 'rb');
            $stream = (new Psr17Factory())->createStreamFromResource($fileStream);
            return $response->withBody($stream);
        } else { $data['status'] = 'Not found'; return $response->withJson($data)->withStatus(404); }
    }

    /// ///////////////////////////////////////////////////////////////////////////////
    /// FETCH OBJECTS (data objects)
    /// ///////////////////////////////////////////////////////////////////////////////


    public function download(Request $request, Response $response, array $args = []): Response {
        $params = $request->getQueryParams();
        $data = [];
        $curl_handle = curl_init();
        $extra_opts[CURLOPT_URL] = $uri;
        $curl_options = array_replace( $this->settings['php']['curl'], $extra_opts );
        curl_setopt_array($curl_handle, $curl_options);
        $data = curl_exec($curl_handle);
        curl_close($curl_handle);
        return $data;
        return $response->withJson($data);
    }



    /// ///////////////////////////////////////////////////////////////////////////////
    /// HEALTH
    /// ///////////////////////////////////////////////////////////////////////////////

    function status() {
        $res = [];
        $res['devices'] = $this->devices(log: 1);
        $res['dgs'] = $this->dgs();
        return $res;
    }

    /**
     * Returns a health status response.
     * @param  Request  $request  
     * @param  Response $response 
     * @param  array    $args     
     * @return Response Json result set.
     */
    public function health(Request $request, Response $response, array $args = []): Response {
        $params = $request->getQueryParams();
        $data = [
                'timestamp' => microtime(),
                'status' => 'OK',
                'params' => $params,
                'service' => basename(__ROOT__),
            ];
        $status = $this->status();
        $status['os'] = PHP_OS;
        $data['data'] = $status;
        return $response->withJson($data);
    }

}
