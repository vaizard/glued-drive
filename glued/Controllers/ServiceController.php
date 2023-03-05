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
        $sys['os'] = PHP_OS;
        foreach ($this->settings['stor']['devices'] as $d) {
            if ( $d['name'] == 'default' ) { $d['path'] = $this->settings['glued']['datapath']; }
            if ( $d['name'] == 'tmp' ) { $d['path'] = sys_get_temp_dir(); }
            $d['health'] = 'unknown';
            $d['status']['message'] = 'device status retrieval unsupported';
            if ( $d['adapter'] == 'filesystem' and PHP_OS == 'Linux') {
                if (is_dir($d['path'])) {
                    $output = json_decode(shell_exec('findmnt -DJv --output fstype,source,target,fsroot,options,size,used,avail,use%,uuid,partuuid --target ' . $d['path']));
                    $d['status'] = (array) $output->filesystems[0];
                    if (is_writable($d['path'])) {
                        $d['health'] = 'online';
                        $d['status']['message'] = 'ok';
                    } else {
                        $d['health'] = 'degraded';
                        $d['status']['message'] = 'path is not writable';
                    }
                } else {
                    $d['health'] = 'offline';
                    $d['status']['message'] = $d['path'].' is not a directory or is missing.';
                }
            }
            $sys['devices'][] = $d;
        }
        $data[basename(__ROOT__)] = $sys;
        return $response->withJson($data);
    }

    /**
     * List available drives
     * @param  Request  $request
     * @param  Response $response
     * @param  array    $args
     * @return Response Json result set.
     */
    public function spaces_r1(Request $request, Response $response, array $args = []): Response {
        $params = $request->getQueryParams();
        $data = [
                'timestamp' => microtime(),
                'status' => 'Spaces r1 OK',
                'params' => $params,
                'service' => basename(__ROOT__),
            ];
        return $response->withJson($data);
    }

    public function spaces_w1(Request $request, Response $response, array $args = []): Response {
        $params = $request->getQueryParams();
        $data = [
            'timestamp' => microtime(),
            'status' => 'Spaces w1 OK',
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

    public function files_w1(Request $request, Response $response, array $args = []): Response {

        if (isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > $this->convert_units(ini_get('post_max_size')))
        {
            throw new \Exception(
                'Upload size in bytes exceeds allowed limit ('.$_SERVER['CONTENT_LENGTH'].' > '.$this->convert_units(ini_get('post_max_size')).').',
                400);
        }

        $data = [];
        $files = $request->getUploadedFiles();


        if (!empty($files)) {

            // flatten $files array to unify handling of file1=,file2=,file[]
            foreach ($files as $k => $f) {
                if (is_array($f)) {
                    foreach ($f as $kk=>$ff) {
                        $filess[$k.'+'.$kk] = $ff;
                    }
                } else {
                    $filess[$k] = $f;
                }
            }

            foreach ($filess as $file) {
                // Validate file information
                if ($file->getError() === UPLOAD_ERR_OK) {
                    // Set target directory for uploaded files
                    $target_dir = "/var/www/html/data/glued-stor/data/";
                    $target_file = $target_dir . $file->getClientFilename();

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
