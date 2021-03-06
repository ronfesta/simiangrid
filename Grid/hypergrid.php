<?php
/** Simian grid services
 *
 * PHP version 5
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 * 1. Redistributions of source code must retain the above copyright
 *    notice, this list of conditions and the following disclaimer.
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 * 3. The name of the author may not be used to endorse or promote products
 *    derived from this software without specific prior written permission.
 * 
 * THIS SOFTWARE IS PROVIDED BY THE AUTHOR ``AS IS'' AND ANY EXPRESS OR
 * IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES
 * OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
 * IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT
 * NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF
 * THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 *
 * @package    SimianGrid
 * @author     John Hurliman <http://software.intel.com/en-us/blogs/author/john-hurliman/>
 *             Jonathan Freedman <http://twitter.com/otakup0pe>
 * @copyright  Open Metaverse Foundation
 * @license    http://www.debian.org/misc/bsd.license  BSD License (3 Clause)
 * @link       http://openmetaverse.googlecode.com/
 */

define('BASEPATH', str_replace("\\", "/", realpath(dirname(__FILE__)) . '/'));

require_once(BASEPATH . 'common/Config.php');
require_once(BASEPATH . 'common/Errors.php');
require_once(BASEPATH . 'common/Log.php');
require_once(BASEPATH . 'common/Interfaces.php');
require_once(BASEPATH . 'common/UUID.php');
require_once(BASEPATH . 'common/Vector3.php');
require_once(BASEPATH . 'common/Curl.php');
require_once(BASEPATH . 'common/Scene.php');
require_once(BASEPATH . 'common/SceneLocation.php');
require_once(BASEPATH . 'common/Session.php');

if ( $_SERVER['REQUEST_METHOD'] != 'POST' ) {
    header("HTTP/1.1 400 Bad Request");
    exit();
}

if ( isset($_SERVER['PATH_INFO'] ) ) {
    log_message('debug', "PARSING PATH INFO " . $_SERVER['PATH_INFO']);
    $path_bits = explode('/', $_SERVER['PATH_INFO']);
    if ( count($path_bits) > 0 ) {
        $data = file_get_contents("php://input");
        if ( $path_bits[1] == "foreignagent" ) {
            foreignagent_handler(array_slice($path_bits, 2), $data);
        } else if ( $path_bits[1] == "homeagent" ) {
            homeagent_handler(array_slice($path_bits, 2), $data);
        }
    }
}

///////////////////////////////////////////////////////////////////////////////
// XML-RPC Server

$xmlrpc_server = xmlrpc_server_create();
xmlrpc_server_register_method($xmlrpc_server, "link_region", "link_region");
xmlrpc_server_register_method($xmlrpc_server, "get_region", "get_region");
xmlrpc_server_register_method($xmlrpc_server, "get_home_region", "get_home_region");
xmlrpc_server_register_method($xmlrpc_server, "verify_client", "verify_client");
xmlrpc_server_register_method($xmlrpc_server, "verify_agent", "verify_agent");
xmlrpc_server_register_method($xmlrpc_server, "logout_agent", "logout_agent");
xmlrpc_server_register_method($xmlrpc_server, "agent_is_coming_home", "agent_is_coming_home");

$request_xml = file_get_contents("php://input");

log_message('debug', "RECEIVING THIS -> $request_xml");

$response = xmlrpc_server_call_method($xmlrpc_server, $request_xml, '');

header('Content-Type: text/xml');

log_message('debug', "SENDING THIS -> $response");
echo $response;

xmlrpc_server_destroy($xmlrpc_server);
exit();

///////////////////////////////////////////////////////////////////////////////

function ends_with($str, $sub)
{
   return (substr($str, strlen($str) - strlen($sub)) == $sub);
}

function decode_recursive_json($json)
{   
    if ( is_string($json) ) {
        $response = json_decode($json, TRUE);
        if ( $response === null || ! is_array($response) ) {
            return $json;
        }
    } else if ( is_array($json) ) {
        $response = $json;
    } else {
        return $json;
    }
    if ( $response == null ) {
        return $json;
    }
    foreach ( $response as $key => $value ) {
        $response[$key] = decode_recursive_json($value);
    }
    return $response;
}

function webservice_post($url, $params, $jsonRequest = FALSE)
{
    // Parse the RequestMethod out of the request for debugging purposes
    if (isset($params['RequestMethod']))
        $requestMethod = $params['RequestMethod'];
    else
        $requestMethod = '';
    
    if (empty($url))
    {
        log_message('error', "Canceling $requestMethod POST to an empty URL");
        return array('Message' => 'Web service URL is not configured');
    }
    
    if ($jsonRequest)
        $params = json_encode($params);
    
    // POST our query and fetch the response
    $curl = new Curl();
    $response = $curl->simple_post($url, $params);
    
    //log_message('debug', sprintf('Response received from %s POST to %s: %s', $requestMethod, $url, $response));
    
    // JSON decode the response
    $response = decode_recursive_json($response);
    
    if (!isset($response))
        $response = array('Message' => 'Invalid or missing response');
    
    return $response;
}

function get_user($userID)
{
    $config =& get_config();
    $userService = $config['user_service'];
    
    $response = webservice_post($userService, array(
        'RequestMethod' => 'GetUser',
        'UserID' => $userID)
    );
    
    if (!empty($response['Success']) && !empty($response['User']))
        return $response['User'];
    
    return null;
}

function get_session($userID)
{
    $config =& get_config();
    $userService = $config['user_service'];
        
    $response = webservice_post($userService, array(
        'RequestMethod' => 'GetSession',
        'UserID' => $userID)
    );
    
    if (!empty($response['Success']))
        return $response;
    
    return null;
}

function add_session($userID, &$sessionID, &$secureSessionID)
{
    $config =& get_config();
    $userService = $config['user_service'];
    
    $response = webservice_post($userService, array(
        'RequestMethod' => 'AddSession',
        'UserID' => $userID)
    );
    
    if (!empty($response['Success']) &&
        UUID::TryParse($response['SessionID'], $sessionID) &&
        UUID::TryParse($response['SecureSessionID'], $secureSessionID))
    {
        return true;
    }
    
    return false;
}

function remove_session($sessionID)
{
    $config =& get_config();
    $userService = $config['user_service'];
    
    $response = webservice_post($userService, array(
        'RequestMethod' => 'RemoveSession',
        'SessionID' => $sessionID)
    );
    
    if (!empty($response['Success']))
        return true;
    
    return false;
}

function lookup_scene_by_id($sceneID)
{
    $config =& get_config();
    $gridService = $config['grid_service'];
    
    $response = webservice_post($gridService, array(
        'RequestMethod' => 'GetScene',
        'SceneID' => $sceneID,
        'Enabled' => '1')
    );
    
    if (!empty($response['Success']))
        return Scene::fromOSD($response);
    
    return null;
}

function lookup_scene_by_name($name)
{
    $config =& get_config();
    $gridService = $config['grid_service'];
    
    $response = webservice_post($gridService, array(
        'RequestMethod' => 'GetScenes',
        'NameQuery' => $name,
        'Enabled' => '1',
        'MaxNumber' => '1')
    );
    
    if (!empty($response['Success']) && is_array($response['Scenes']) && count($response['Scenes']) > 0)
        return Scene::fromOSD($response['Scenes'][0]);
    
    return null;
}

function lookup_scene_by_position($position, $findClosest = false)
{
    $config =& get_config();
    $gridService = $config['grid_service'];
    
    $response = webservice_post($gridService, array(
        'RequestMethod' => 'GetScene',
        'Position' => $position,
        'FindClosest' => ($findClosest ? '1' : '0'),
        'Enabled' => '1')
    );
    
    if (!empty($response['Success']))
        return Scene::fromOSD($response);
    
    return null;
}

function add_wearable(&$wearables, $appearance, $wearableName)
{
    $uuid = null;
    
    // ItemID
    if (isset($appearance[$wearableName . 'Item']) && UUID::TryParse($appearance[$wearableName . 'Item'], $uuid))
        $wearables[] = $uuid;
    else
        $wearables[] = UUID::Zero;
    
    // AssetID
    if (isset($appearance[$wearableName . 'Asset']) && UUID::TryParse($appearance[$wearableName . 'Asset'], $uuid))
        $wearables[] = $uuid;
    else
        $wearables[] = UUID::Zero;
}

function create_opensim_presence($scene, $userID, $circuitCode, $fullName, $wearables, $attachments, $sessionID, $secureSessionID, $startPosition, $capsPath)
{
    return create_opensim_presence_full($scene->Address, $scene->Name, $scene->ID, $scene->MinPosition->X, $scene->MinPosition->Y, $userID, $circuitCode, $fullName, $wearables, $attachments, $sessionID, $secureSessionID, $startPosition, $capsPath, null, null, null);
}

function create_opensim_presence_full($server_uri, $scene_name, $scene_uuid, $scene_x, $scene_y, $userID, $circuitCode, $fullName, $wearables, $attachments, $sessionID, $secureSessionID, $startPosition, $capsPath, $client_ip, $service_urls, $tp_flags, $service_session_id)
{
    if (!ends_with($server_uri, '/'))
        $server_uri .= '/';
    $regionUrl = $server_uri . 'agent/' . $userID . '/';
    
    list($firstName, $lastName) = explode(' ', $fullName);

    $request = array(
        'agent_id' => $userID,
        'caps_path' => $capsPath,
        'child' => false,
        'circuit_code' => $circuitCode,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'session_id' => $sessionID,
        'secure_session_id' => $secureSessionID,
        'start_pos' => (string)$startPosition,
        'appearance_serial' => 1,
        'destination_x' => $scene_x,
        'destination_y' => $scene_y,
        'destination_name' => $scene_name,
        'destination_uuid' => $scene_uuid,
        'wearables' => $wearables,
        'attachments' => $attachments,
        'child' => true
    );
    if ( $client_ip != null ) {
        $request['client_ip'] = $client_ip;
    }
    if ( $service_urls != null ) {
        $request['service_urls'] = $service_urls;
    }
    if ( $service_session_id != null ) {
        $request['service_session_id'] = $service_session_id;
    }
    if ( $tp_flags != null ) {
        $request['teleport_flags'] = $tp_flags;
    }
    $response = webservice_post($regionUrl, $request, true);
    
    log_dump("webservice post response", $response);
    
    if (!empty($response['success']))
    {
        return true;
    }
    
    $seedCapability = null;
    return false;
}

function create_session($user_id, $session_id, $secure_session_id)
{
    $config =& get_config();
    $gridService = $config['grid_service'];
    $response = webservice_post($gridService, array(
        'RequestMethod' => 'AddSession',
        'UserID' => $user_id,
        'SessionID' => $session_id,
        'SecureSessionID' => $secure_session_id
    ));
    if (!empty($response['success'])) {
        return true;
    } else {
        return false;
    }
}

function bump_user($user_id, $username, $email)
{
    $config =& get_config();
    $userService = $config['user_service'];
    $response = webservice_post($userService, array(
        'RequestMethod' => 'AddUser',
        'UserID' => $user_id,
        'Name' => $username,
        'Email' => $email,
        'AccessLevel' => 0
    ));
    if (!empty($response['success'])) {
        return true;
    } else {
        return false;
    }
    
}

function bin2int($str)
{
    $result = '0';

    $n = strlen($str);
    do
    {
        $result = bcadd(bcmul($result, '256'), ord($str{--$n}));
    } while ($n > 0);

    return $result;
}

function int2bin($num)
{
    $result = '';

    do
    {
        $result .= chr(bcmod($num, '256'));
        $num = bcdiv($num, '256');
    } while (bccomp($num, '0'));

    return $result;
}

function bitOr($num1, $num2, $start_pos)
{
    $start_byte = intval($start_pos / 8);
    $start_bit = $start_pos % 8;
    $tmp1 = int2bin($num1);

    $num2 = bcmul($num2, 1 << $start_bit);
    $tmp2 = int2bin($num2);

    if ($start_byte < strlen($tmp1))
    {
        $tmp2 |= substr($tmp1, $start_byte);
        $tmp1 = substr($tmp1, 0, $start_byte) . $tmp2;
    }
    else
    {
        $tmp1 = str_pad($tmp1, $start_byte, "\0") . $tmp2;
    }

    return bin2int($tmp1);
}

function bitShift($num1, $bits)
{
    return bcmul($num1, bcpow(2, $bits));
}

///////////////////////////////////////////////////////////////////////////////
// GateKeeper Service

function link_region($method_name, $params, $user_data)
{
    log_message('info', "link_region called");

    $config =& get_config();

    $req = $params[0];

    if ( isset($req['region_name']) && strlen($req['region_name']) > 0 ) {
        $region_name = $req['region_name'];
        log_message('debug', "Using specified region name $region_name");
    } else {
        $region_name = $config['hypergrid_default_region'];
        log_message('debug', "No region name specified - using $region_name");
    }
    
    $scene = lookup_scene_by_name($region_name);
    
    $response = array();

    if ( $scene == null ) {
        log_message('warn', "Unable to link to unknown region $region_name - no scene found");
        $response['result'] = 'false';
    } else {
        $response['result'] = 'true';
        $response['uuid'] = $scene->SceneID;
        
        // Yay for 64-bit integer bitmath in PHP
        $x = $scene->MinPosition->X;
        $y = $scene->MinPosition->Y;
        $handle = bitShift($x, 32);
        $handle = bitOr($handle, (string)$y, 0);
        $response['handle'] = (string)$handle;
        
        $response['region_image'] = "http://" . $scene->ExtraData['ExternalAddress'] . ":" .
        $response['server_uri'] = $scene->Address;
        $response['external_name'] = $scene->Name;
        log_message('debug', "Succesfully linked to $region_name@" . $scene->Address);
    }
    
    return $response;
}

function get_region($method_name, $params, $user_data)
{
    log_message('info', "$method_name called");
    
    $req = $params[0];
    $regionid = $req['region_uuid'];
    
    $scene = lookup_scene_by_id($regionid);
    
    $response = array();
    $config =& get_config();
    if ( $scene == null ) {
        $response['result'] = "false";
    } else {
        $response['result'] = "true";
        $response['uuid'] = $scene->SceneID;
        $response['x'] = (string) $scene->MinPosition->X;
        $response['y'] = (string) $scene->MinPosition->Y;
        $response['region_name'] = $scene->Name;
        $response['server_uri'] = $scene->Address;
        $response['hostname'] = $scene->ExtraData['ExternalAddress'];
        $response['internal_port'] = (string) $scene->ExtraData['ExternalPort'];
    }

    return $response;
}

function foreignagent_handler($path_tail, $data)
{
    log_message('debug', "server method is " . $_SERVER['REQUEST_METHOD']);
    $userid = $path_tail[0];
    log_message('info', "foreign_agent called for $userid with $data");
    
    $osd = decode_recursive_json($data);
    
    $dest_x = $osd['destination_x'];
    $dest_y = $osd['destination_y'];
    
    if ( $dest_x == null ) {
        $dest_x = 0;
    }
    if ( $dest_y == null ) {
        $dest_y = 0;
    }
    
    $caps_path = $osd['caps_path'];
    $username = $osd['first_name'] . ' ' . $osd['last_name'];
    $circuit_code = $osd['circuit_code'];
    $session_id = $osd['session_id'];
    $secure_session_id = $osd['secure_session_id'];
    $service_session_id = $osd['service_session_id'];
    $start_pos = $osd['start_pos'];
    $appearance = $osd['wearables'];
    if ( isset($osd['attachments']) ) {
        $attachments = $osd['attachments'];
    } else {
        $attachments = array();
    }
    $service_urls['HomeURI'] = $osd['service_urls'][1];
    $service_urls['GatekeeperURI'] = $osd['service_urls'][3];
    $service_urls['InventoryServerURI'] = $osd['service_urls'][5];
    $service_urls['AssetServerURI'] = $osd['service_urls'][7];
    $client_ip = $osd['client_ip'];
    
    $dest_uuid = $osd['destination_uuid'];
    $dest_name = $osd['destination_name'];
    
    if ( $dest_uuid == null || $dest_name == null ) {
        header("HTTP/1.1 400 Bad Request");
        echo "missing destination_name and/or destination_uuid";
        exit();
    }
    
    $scene = lookup_scene_by_id($dest_uuid);
    
    $username = $osd['first_name'] . ' ' . $osd['last_name'] . '@' . $service_urls['HomeURI'];
    
    bump_user($userid, $username, "$username@HG LOLOL");
    create_session($userid, $session_id, $secure_session_id);
    
    $result = create_opensim_presence_full($scene->Address, $dest_name, $dest_uuid, $dest_x, $dest_y, $userid, $circuit_code, $username, $appearance, $attachments, $session_id, $secure_session_id, $start_pos, $caps_path, $client_ip, $service_urls, 1073741824, $service_session_id);
    
    echo "{'success': $result, 'reason': 'no reason set lol', 'your_ip': '" . $_SERVER['REMOTE_ADDR'] . "'}";
    exit();
    
}

///////////////////////////////////////////////////////////////////////////////
// UserAgent Service

function get_home_region($method_name, $params, $user_data)
{
    $response = array();
    
    $req = $params[0];
    $userID = $req['userID'];
    
    $response = array();
    
    log_message('info', "get_home_region called with UserID $userID");
    
    // Fetch the user
    $user = get_user($userID);
    if (empty($user))
    {
        log_message('warn', "Unknown UserID $userID");
        $response['result'] = 'false';
        return $response;
    }
    
    $homeLocation = null;
    
    if (isset($user['HomeLocation']))
        $homeLocation = SceneLocation::fromOSD($user['HomeLocation']);
    
    log_message('debug', "User retrieval success for $userID, HomeLocation is $homeLocation");
    
    $scene = null;
    $position = null;
    $lookat = null;
    
    // If the user's home is set, try to grab info for that scene
    if (isset($homeLocation))
    {
        log_message('debug', sprintf("Looking up scene '%s'", $homeLocation->SceneID));
        $scene = lookup_scene_by_id($homeLocation->SceneID);
        
        if (isset($scene))
        {
            $position = $homeLocation->Position;
            $lookat = $homeLocation->LookAt;
        }
    }
    
    // No home set, last resort lookup for *any* scene in the grid
    if (!isset($scene))
    {
        $position = Vector3::Zero();
        log_message('debug', "Looking up scene closest to '$position'");
        $scene = lookup_scene_by_position($position, true);
        
        if (isset($scene))
        {
            $position = new Vector3(
                (($scene->MinPosition->X + $scene->MaxPosition->X) / 2) - $scene->MinPosition->X,
                (($scene->MinPosition->Y + $scene->MaxPosition->Y) / 2) - $scene->MinPosition->Y,
                25);
            $lookat = new Vector3(1, 0, 0);
        }
    }
    
    if (isset($scene))
    {
        $response['result'] = 'true';
        $response['uuid'] = $scene->SceneID;
        $response['x'] = $scene->MinPosition->X;
        $response['y'] = $scene->MinPosition->Y;
        $response['region_name'] = $scene->Name;
        $response['hostname'] = $scene->Address;
        $response['http_port'] = $scene->ExtraData['ExternalPort'];
        $response['internal_port'] = $scene->ExtraData['InternalPort'];
        $response['position'] = (string)$position;
        $response['lookAt'] = (string)$lookat;
        
        log_message('debug', "Returning successful home lookup for $userID");
    }
    else
    {
        $response['result'] = 'false';
        log_message('warn', "Failed to find a valid home scene for $userID, returning failure");
    }
    
    return $response;
}

function verify_client($method_name, $params, $user_data)
{
    $response = array();
    
    $req = $params[0];
    $sessionID = $req['sessionID'];
    $token = $req['token'];
    
    log_message('info', "verify_client called with SessionID $sessionID and Token $token");
    
    $response['result'] = 'true';
    return $response;
}

function verify_agent($method_name, $params, $user_data)
{
    $response = array();
    
    $req = $params[0];
    $sessionID = $req['sessionID'];
    $token = $req['token'];
    
    log_message('info', "verify_agent called with SessionID $sessionID and Token $token");
    
    $response['result'] = 'true';
    return $response;
}

function logout_agent($method_name, $params, $user_data)
{
    $response = array();
    log_message('info', "$method_name called");
    
    $response["blah"] = 'blah';
    return $response;
}

function agent_is_coming_home($method_name, $params, $user_data)
{
    $response = array();
    log_message('info', "$method_name called");
    
    $response["blah"] = 'blah';
    return $response;
}

function homeagent_handler($path_tail, $data)
{
    $userid = $path_tail[0];
    $osd = decode_recursive_json($data);
    
    $gatekeeper_host = $osd['gatekeeper_host'];
    $gatekeeper_port = $osd['gatekeeper_port'];
    
    $dest_x = $osd['destination_x'];
    $dest_y = $osd['destination_y'];
    
    if ( $dest_x == null ) {
        $dest_x = 0;
    }
    if ( $dest_y == null ) {
        $dest_y = 0;
    }
    
    $dest_uuid = $osd['destination_uuid'];
    #$dest_name = $osd['destination_name'];
    
    $scene = get_scene($dest_uuid);
    
    
    if ( $dest_uuid == null || $dest_name == null ) {
        header("HTTP/1.1 400 Bad Request");
        echo "missing destination_name and/or destination_uuid";
        exit();
    }
    
    $caps_path = $osd['caps_path'];
    $username = $osd['first_name'] . ' ' . $osd['last_name'];
    $circuit_code = $osd['circuit_code'];
    $session_id = $osd['session_id'];
    $secure_session_id = $osd['secure_session_id'];
    $start_pos = $osd['start_pos'];
    $appearance = $osd['wearables'];
    $attachments = $osd['attachments'];
    
    $result = create_opensim_presence($scene, $userid, $circuit_code, $username, $appearance, $attachments,
    $session_id, $secure_session_id, $start_pos, $caps_path);
    
    echo "{'success': $result, 'reason': 'no reason set lol'}";
    exit();

}
