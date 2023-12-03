<?php

declare(strict_types=1);

namespace Glued\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Glued\Classes\Exceptions\AuthTokenException;
use Glued\Classes\Exceptions\AuthJwtException;
use Glued\Classes\Exceptions\AuthOidcException;
use Glued\Classes\Exceptions\DbException;
use Glued\Classes\Exceptions\TransformException;

class ServiceController extends AbstractController
{

    /**
     * @param $value accepts strings and numbers, such as (100, 100mb, 100g, etc.)
     * @param $from_unit forces the from unit (unit in value is ignored). Accepts: b, kb, KB, kB ... etc.
     * @param $to_unit defines target unit. If nothing provided, bytes are implied.
     * @return float|int
     * @throws \Exception
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
        if (!array_key_exists($from_unit, $units)) throw new \Exception("Unexpected from unit provided.", 500);
        if (!array_key_exists($to_unit, $units)) throw new \Exception("Unexpected to unit provided.", 500);
        $from_power = $units[$from_unit];
        $to_power = $units[$to_unit];
        $factor = pow(1024,  $from_power - $to_power );
        return $value * $factor;
    }






    /**
     * Returns an exception.
     * @param  Request  $request  
     * @param  Response $response 
     * @param  array    $args     
     * @return Response Json result set.
     */
    public function stub(Request $request, Response $response, array $args = []): Response {
        throw new \Exception('Stub method served where it shouldnt. Proxy misconfigured?');
    }

    function is_uuidv4($uuid) {
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        return preg_match($pattern, $uuid) === 1;
    }

    function dg_handle($uuid = 'default') {
        if ($uuid == 'default') {
            $filtered = array_filter($this->settings['stor']['dgs'], function ($dg) {
                return isset($dg['default']) && $dg['default'] === true;
            });
            if (count($filtered)==0) { throw new \Exception("Configuration error: dg not found.", 500); }
            if (count($filtered)>1) { throw new \Exception("Configuration error: multiple primary stor dgs defined.", 500); }
            $uuid = array_keys($filtered)[0];
        }
        if (array_key_exists($uuid, $this->settings['stor']['dgs'])) { return $uuid; }
        throw new \Exception("Configuration error: undefined stor dgs element requested.", 500);
    }

    /**
     * @param array $filter
     * @param string $dg_uuid
     * @return array returns array of devices fitting the $filter constraint within a device group
     * @throws \Exception
     */
    function dev_handle(array $filter = [ 'default' => true ], string $dg_uuid = 'default') {
        $dg_uuid = $this->dg_handle($dg_uuid);
        $filtered = $this->settings['stor']['devices'];

        $filtered = array_filter($this->settings['stor']['devices'], function ($val) use ($dg_uuid, $filter) {
            // Check if 'dg' is set and equal to $dg_uuid and $val contains all elements and values from $filter
            if (isset($val['dg']) && $val['dg'] === $dg_uuid) {
                return count(array_intersect_assoc($val, $filter)) === count($filter);
            }
            return false; // If 'dg' is not set or not equal to $dg_uuid, exclude the element.
        });

        // predefined devices not having a datapath overridden must get path generated
        $key = '09e0ef57-86e6-4376-b466-a7d2af31474e';
        if (array_key_exists($key, $filtered)) {
            if (!isset($filtered[$key]['path']) || is_null($filtered[$key]['path'])) {
                $filtered[$key]['path'] = $this->settings['glued']['datapath'] . '/' . basename(__ROOT__) . '/data';
            }
        }
        $key = '86159ff5-f513-4852-bb3a-7f4657a35301';
        if (array_key_exists($key, $filtered)) {
            if (!isset($filtered[$key]['path']) || is_null($filtered[$key]['path'])) {
                $filtered[$key]['path'] = sys_get_temp_dir();
            }
        }

        // add device uuid as a value too
        foreach ($filtered as $k=>&$v) {
            $v['device'] = $k;
        }
        return $filtered;
    }


    public function dgsStatus($dg = null) {
        $whereClause = '';
        if ($dg !== null) { $whereClause = 'WHERE dgUuid = ?'; }
        $query = "SELECT * FROM v_stor_status {$whereClause}";
        if ($dg !== null) { $params = [$dg]; }
        else { $params = []; }
        $res = $this->mysqli->execute_query($query, $params);
        return $res->fetch_all(MYSQLI_ASSOC);
    }

    function status() {
        $dgs = [];
        $res = [];

        foreach ($this->settings['stor']['devices'] as $k => $d) {
            if ( array_key_exists('dg', $d) ) {
                if (!is_null($d['dg'])) {
                    $dgs[$d['dg']]['devices'] = isset($dgs[$d['dg']]['devices']) ? ($dgs[$d['dg']]['devices'] + 1) : 1;
                }
            }
            // the glued-lib default config comes with predefined uuids for the $datapath and for tmp
            if ( $k == '09e0ef57-86e6-4376-b466-a7d2af31474e' ) { $d['path'] = $this->settings['glued']['datapath'] . '/' . basename(__ROOT__)  . '/data'; }
            if ( $k == '86159ff5-f513-4852-bb3a-7f4657a35301' ) { $d['path'] = sys_get_temp_dir(); }
            $d['status']['health'] = 'unknown';
            $d['status']['message'] = 'device status retrieval unsupported';
            if ( $d['adapter'] == 'filesystem' and PHP_OS == 'Linux') {
                if (is_dir($d['path'] ?? '')) {
                    $output = json_decode(shell_exec('findmnt -DJv --output fstype,source,target,fsroot,options,size,used,avail,use%,uuid,partuuid --target ' . $d['path']));
                    $d['status'] = (array) $output->filesystems[0];
                    if (is_writable($d['path'])) {
                        $d['status']['health'] = 'online';
                        $d['status']['message'] = 'ok';
                    } else {
                        $d['status']['health'] = 'degraded';
                        $d['status']['message'] = 'path `'.$d['path'].'` is not writable';
                    }
                } else {
                    $d['status']['health'] = 'offline';
                    $d['status']['message'] = '`'.$d['path'].'` is not a directory or is missing.';
                }
            }
            if (!$this->is_uuidv4($k)) {
                $d['status']['health'] = 'degraded';
                $d['status']['message'] = 'device key `'.$k.'` is not a UUIDv4';
            }
            if (!$this->is_uuidv4($d['dg'] ?? 'a4590c69-f4ac-4fa5-8a2f-03b3f65923ce')) {
                $d['status']['health'] = 'degraded';
                $d['status']['message'] = 'dg key `' . ($d['dg'] ?? 'a4590c69-f4ac-4fa5-8a2f-03b3f65923ce') .'` is not a UUIDv4';
            }
            $res['devices'][$k] = $d;
            if ($d['status']['health'] != "online") { $this->notify->send('Storage device ' . $k . ' '.$d['status']['health']. ': '. $d['status']['message'], notify_admins: true);}
        }
        $res['dgs'] = array_merge_recursive($this->settings['stor']['dgs'],$dgs);

        $q1 = "INSERT INTO `t_stor_dgs` (`uuid`, `data`) VALUES (uuid_to_bin(?, true), ?) ON DUPLICATE KEY UPDATE `data` = VALUES(`data`)";
        $q2 = "INSERT IGNORE INTO `t_stor_configlog` (`uuid`, `data`) VALUES (uuid_to_bin(?, true), ?)";
        $q3 = "INSERT INTO `t_stor_devices` (`uuid`, `data`) VALUES (uuid_to_bin(?, true), ?) ON DUPLICATE KEY UPDATE `data` = VALUES(`data`)";
        $q4 = "INSERT IGNORE INTO `t_stor_configlog` (`uuid`, `data`) VALUES (uuid_to_bin(?, true), ?)";
        $q5 = "INSERT INTO `t_stor_statuslog` (`uuidDev`, `data`) VALUES (uuid_to_bin(?, true), ?) ON DUPLICATE KEY UPDATE `tsUpdated` = CURRENT_TIMESTAMP(1);";
        $q5 = "
            INSERT INTO `t_stor_statuslog` (`uuidDev`, `data`) VALUES (uuid_to_bin(?, true), ?) ON DUPLICATE KEY UPDATE `tsUpdated` = CURRENT_TIMESTAMP(1);
            ";

         foreach ($res['dgs'] as $k => $v) {
             $v = json_encode($v ?? [], JSON_FORCE_OBJECT);
             $data = [ $k, $v ];
             $this->mysqli->begin_transaction();
             $this->mysqli->execute_query($q1, $data);
             $this->mysqli->execute_query($q2, $data);
             $this->mysqli->commit();
        }
        foreach ($res['devices'] as $k => $v) {
            $v['status']['dg'] = $v['dg'];
            $s = json_encode($v['status'] ?? [], JSON_FORCE_OBJECT);
            unset($v['status']);
            $v = json_encode($v ?? [], JSON_FORCE_OBJECT);
            $data = [ $k, $v ];
            $this->mysqli->begin_transaction();
            $this->mysqli->execute_query($q3, $data);
            $this->mysqli->execute_query($q4, $data);
            $this->mysqli->execute_query($q5, [ $k, $s ]);
            $this->mysqli->commit();
        }
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
        $status['dgh-def'] = $this->dg_handle();
        $status['dgh-usr'] = $this->dg_handle('1c8964ab-b60e-4407-bc06-309faabd4db8');
        $status['dev-def'] = $this->dev_handle();
        $status['dev-def2'] = $this->dev_handle([ 'enabled' => true ],'default');
        $data[basename(__ROOT__)] = $status;
        return $response->withJson($data);
    }

    public function healthDgs(Request $request, Response $response, array $args = []): Response {
        $r = $this->dgsStatus($args['uuid'] ?? null);
        $data = [
            'timestamp' => microtime(),
            'status' => 'OK',
            'service' => basename(__ROOT__),
            'data' => $r ?? []
        ];
        $status = $this->status();
        return $response->withJson($data);
    }

    /**
     * List available drives
     * @param  Request  $request
     * @param  Response $response
     * @param  array    $args
     * @return Response Json result set.
     */
    public function buckets_r1(Request $request, Response $response, array $args = []): Response {
        $params = $request->getQueryParams();
        $q = "
        SELECT 
          bin_to_uuid(b.`uuid`, 1) AS `b.uuid`,
          b.name AS `b.name`,
          b.data AS `b.data`,
          bin_to_uuid(bd.`dg`, 1) AS `dg`,
          s.dgStatus
        FROM `t_stor_buckets` b
        LEFT JOIN t_stor_bucket_dgs bd ON bd.bucket = b.uuid
        LEFT JOIN (
          SELECT DISTINCT dgUuid, dgStatus
          FROM v_stor_status
        ) s ON s.dgUuid = bin_to_uuid(bd.dg, 1)
        ";

        $res = $this->mysqli->execute_query($q, []);
        $data = $res->fetch_all(MYSQLI_ASSOC);

        $data = [
                'timestamp' => microtime(),
                'status' => 'Buckets r1 OK',
                'data' => $data,
                'service' => basename(__ROOT__),
            ];
        return $response->withJson($data);
    }

    public function buckets_c1(Request $request, Response $response, array $args = []): Response {
        $params = $request->getQueryParams();
        $this->status();
        // TODO replace with propper validation
        $contentTypeHeader = $request->getHeaderLine('Content-Type') ?? '';
        if ($contentTypeHeader !== 'application/json') { throw new \Exception('Invalid Content-Type. Please set `Content-Type: application/json', 400); }
        $body = $request->getParsedBody();
        $uuid = \Ramsey\Uuid\Uuid::uuid4()->toString();
        if (!isset($body['name'])) { throw new \Exception('Mandatory $.name not provided.'); }
        if (!isset($body['dgs'])) { throw new \Exception('Mandatory $.dgs not provided.'); }
        if (!is_array($body['dgs'])) { throw new \Exception('Mandatory $.dgs is not a list (array).'); }

        $q1 = "INSERT INTO `t_stor_buckets` (`uuid`, `data`) VALUES (uuid_to_bin(?,true), ?)";
        $q2 = "INSERT INTO `t_stor_bucket_dgs` (`bucket`,`dg`) VALUES (uuid_to_bin(?,true), uuid_to_bin(?,true))";

        try {
            $this->mysqli->begin_transaction();
            $this->mysqli->execute_query($q1, [ $uuid, json_encode($body) ]);
            foreach ($body['dgs'] as $dg) {
                $this->mysqli->execute_query($q2, [$uuid, $dg]);
            }
            $this->mysqli->commit();
        } catch (\mysqli_sql_exception $e) {
            if ($e->getCode() === 1452) { throw new \Exception("Unknown devicegroup UUID.", 400); }
            elseif ($e->getCode() === 1062) { throw new \Exception("The bucket:devicegroup association already exists.", 400);
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

    /**
     * Gets all files in a space
     * @param  Request  $request
     * @param  Response $response
     * @param  array    $args
     * @return Response Json result set.
     */
    public function files_r1(Request $request, Response $response, array $args = []): Response {
        $params = $request->getQueryParams();
        $data = [
                'timestamp' => microtime(),
                'status' => 'Files r1 OK',
                'params' => $params,
                'service' => basename(__ROOT__),
            ];
        return $response->withJson($data);
    }

    function getObjectMeta($file):array {
        $path = $file->getStream()->getMetadata('uri');
        $mime = mime_content_type($path) ?? $file->getClientMediaType() ?? null;
        $data = [
            'uuid' => \Ramsey\Uuid\Uuid::uuid4()->toString(),
            'name' => $file->getClientFilename(),
            'size' => $file->getSize(),
            'hash' => [
                'sha3-512' => hash_file('sha3-512', $path),
                'md5' => hash_file('md5', $path)
            ],
            'mime' => [
                'type' => $mime,
                'ext' => $mime && array_key_exists($mime, $this->utils->mime2ext) ? $this->utils->mime2ext[$mime][0] : ($file->getClientFilename() ? pathinfo($file->getClientFilename(), PATHINFO_EXTENSION) : null),
            ]
        ];
        return $data;
    }

    // TODO t_core_tokens now has only a c_inherit column. we'll need a c_owner column too, because user tokens will use c_inherit, but svc tokens will have c_owner (the one whom is the token management associated to)
    public function files_w1(Request $request, Response $response, array $args = []): Response {

        if (isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > $this->convert_units(ini_get('post_max_size'))) {
            throw new \Exception(
                'Upload size in bytes exceeds allowed limit ('.$_SERVER['CONTENT_LENGTH'].' > '.$this->convert_units(ini_get('post_max_size')).').',
                400);
        }

        $data         = [];
        $files        = $request->getUploadedFiles();
        $parsedBody   = $request->getParsedBody();
        $hdrUser      = $request->getHeader('X-glued-auth-uuid')[0] ?? null;
        $hdrAuth      = $request->getHeader('Authorization')[0] ?? null;

        $serverParams = $request->getServerParams();
        $headers      = $request->getHeaders();
        $headerValueArray = $request->getHeader('Accept');
        $headerValueString = $request->getHeaderLine('Accept');

        //print_r($headers);
        //print_r($serverParams);
        //print_r($request->getHeader('X-glued-auth-uuid'));
        $hdrUser = $request->getHeader('X-glued-auth-uuid')[0] ?? null;
        $hdrAuth = $request->getHeader('Authorization')[0] ?? null;

//die();

        if (!empty($files)) {
            $target_dir = array_values($this->dev_handle())[0]['path'];

            // flatten $files array to unify handling of file1=,file2=,file[]
            foreach ($files as $k => $f) {
                if (is_array($f)) {
                    foreach ($f as $kk=>$ff) { $flattened[$k.'+'.$kk] = $ff; }
                } else {
                    $flattened[$k] = $f;
                }
            }

            $linksMeta = json_decode(($parsedBody['links'] ?? []), true);
            foreach ($flattened as $file) {
                if ($file->getError() === UPLOAD_ERR_OK) {
                    $target_file = $target_dir . $file->getClientFilename();
                    $object = $this->getObjectMeta($file);
                    $link = $linksMeta[$file->getClientFilename()] ?? [];
                    if (!isset($link['name'])) { $link['name'] = $file->getClientFilename() ?? 'file'; }
                    $data[] = [
                        'object' => $object,
                        'link' => $link
                    ];

                    /*
                    //print_r($data); echo "DIEING"; die();
                    //$f['meta'] = json_decode($parsedBody['stor'] ?? [],true)[$file->getClientFilename()] ?? [];


                    // Check if file already exists
                    if (!file_exists($target_file)) {
                        // Move the uploaded file to the target directory
                        $file->moveTo($target_file);

                        // Add file information to response array
                        $data[] = array(
                            'name' => $file->getClientFilename(),
                            'size' => $file->getSize(),
                            'type' => $file->getClientMediaType(),
                            'hash' => hash_file('sha3-512', $target_file),
                            'url' => 'http://' . $request->getUri()->getHost() . '/' . $target_file,
                            'status' => 'success'
                        );

                    } else {

                        // Add error message to response array
                        $data[] = array(
                            'name' => $file->getClientFilename(),
                            'error' => 'File already exists.',
                            'status' => 'error'
                        );
                    }
*/
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

    public function annotations_r1(Request $request, Response $response, array $args = []): Response {
        $headers = '';
        foreach ($request->getHeaders() as $name => $values) {
            $headers .= $name . ": " . implode(", ", $values);
        }
        $r = [
            'qp' => $request->getQueryParams(),
            'pb' => $request->getParsedBody(),
            'fi' => $request->getUploadedFiles(),
            'hd' => $headers
        ];
        return $response->withJson($r);
    }

    public function annotations_w1(Request $request, Response $response, array $args = []): Response {
        $headers = '';
        foreach ($request->getHeaders() as $name => $values) {
            $headers .= $name . ": " . implode(", ", $values);
        }
        $r = [
            'qp' => $request->getQueryParams(),
            'pb' => $request->getParsedBody(),
            'fi' => $request->getUploadedFiles(),
            'hd' => $headers
        ];
        return $response->withJson($r);
    }

    public function annotations_d1(Request $request, Response $response, array $args = []): Response {
        $headers = '';
        foreach ($request->getHeaders() as $name => $values) {
            $headers .= $name . ": " . implode(", ", $values);
        }
        $r = [
            'qp' => $request->getQueryParams(),
            'pb' => $request->getParsedBody(),
            'fi' => $request->getUploadedFiles(),
            'hd' => $headers
        ];
        return $response->withJson($r);
    }
}
