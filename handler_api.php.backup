<?php
//error_reporting(E_ALL);
//ini_set('display_errors', 1);
error_reporting(0);
ini_set('display_errors', 0);
require 'vendor/autoload.php';
use MongoDB\Client;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Model\BSONArray;

function getMongoDBConnection($database = 'smsgateway') {
    try {
        $mongoClient = new Client("mongodb://smsgateway:Lauda%409798@localhost:27017/$database?authSource=admin");
        return $mongoClient->$database;
    } catch (Exception $e) {
        die("Error connecting to MongoDB: " . $e->getMessage());
    }
}

function buynumber($request) {
    try {
        $db = getMongoDBConnection();
        
        $params = $_GET;
        if (!isset($params['api_key']) || !$params['api_key']) {
            return "BAD_KEY";
        }
        if (!isset($params['service']) || !$params['service']) {
            return "BAD_SERVICE";
        }
        if (!isset($params['country']) || $params['country'] === '') {
            return "BAD_COUNTRY";
        }

        $service = $params['service'];
        $country = (string) $params['country'];
        $api_key = $params['api_key'];

        $userdata = $db->users->findOne(['apikey' => $api_key]);
        if (!$userdata) return "BAD_KEY";
        if (isset($userdata['ban']) && $userdata['ban'] === true) {
            return "ACCOUNT_BAN";
        }

        $servicesdata = $db->services->findOne(['code' => $service, 'active' => true]);
        if (!$servicesdata) return "BAD_SERVICE";

        $countrydata = $db->countires->findOne(['code' => $country, 'active' => true]);
        if (!$countrydata) return "BAD_COUNTRY";

        $maxTries = 6;
        $validNumber = null;

        // Cooldown time: between 5–20 minutes (randomized)
        $cooldownMin = 5 * 60;   // 5 minutes in seconds
        $cooldownMax = 20 * 60;  // 20 minutes in seconds

        for ($i = 0; $i < $maxTries; $i++) {
            $availableNumbers = $db->numbers->aggregate([
                ['$match' => ['active' => true, 'countryid' => $countrydata->_id, 'suspended' => false]],
                ['$sample' => ['size' => 1]]
            ])->toArray();

            if (empty($availableNumbers)) {
                return "NO_NUMBER"; 
            }

            $numberDoc = $availableNumbers[0];

            // Check lock
            $islocked = $db->locks->findOne([
                'number' => $numberDoc['number'],
                'countryid' => $countrydata->_id,
                'serviceid' => $servicesdata->_id,
                'locked' => true,
            ]);
            if ($islocked) {
                continue;
            }

            // Check active orders using this number
            $isUsedInOrders = $db->orders->findOne([
                'number' => $numberDoc['number'],
                'countryid' => $countrydata->_id,
                'serviceid' => $servicesdata->_id,
                'active' => true,
                'isused' => false
            ]);
            if ($isUsedInOrders) {
                continue;
            }

            // Check recent completed usage in last 4 hours
            $fourHoursAgo = new MongoDB\BSON\UTCDateTime((time() - 4 * 3600) * 1000);
            $recentOrder = $db->orders->findOne([
                'number' => $numberDoc['number'],
                'countryid' => $countrydata->_id,
                'serviceid' => $servicesdata->_id,
                'isused' => true,
                'createdAt' => ['$gte' => $fourHoursAgo]
            ]);
            if ($recentOrder) {
                continue; 
            }

            // 🔥 Cooldown check: prevent instant reuse
            $cooldownOrder = $db->orders->findOne([
                'number' => $numberDoc['number'],
                'countryid' => $countrydata->_id,
                'serviceid' => $servicesdata->_id,
                'isused' => false,
                'active' => false, // canceled or expired
            ], [
                'sort' => ['updatedAt' => -1] // get last canceled order
            ]);

            if ($cooldownOrder) {
                $cooldownSeconds = rand($cooldownMin, $cooldownMax);
                $cooldownTime = $cooldownOrder['updatedAt']->toDateTime()->getTimestamp() + $cooldownSeconds;

                if (time() < $cooldownTime) {
                    // still under cooldown
                    continue;
                }
            }

            // Passed all checks ✅
            $validNumber = $numberDoc;
            break;
        }

        if (!$validNumber) {
            return "NO_NUMBER";
        }

        $collection = $db->orders;

        $result = $collection->insertOne([
            "number" => $validNumber['number'],
            "countryid" => $countrydata->_id,
            "serviceid" => $servicesdata->_id,
            "dialcode" => $countrydata->dial,
            "isused" => false,
            "ismultiuse" => true,
            "nextsms" => false,
            "message" => [],
            "keywords" => $servicesdata->keywords,
            "formate" => $servicesdata->formate,
            "maxmessage" => $servicesdata->maxmessage,
            "active" => true,
            'createdAt' => new MongoDB\BSON\UTCDateTime(time() * 1000),
            "updatedAt" => new MongoDB\BSON\UTCDateTime(time() * 1000),
            "__v" => 0
        ]);

        if ($result) {
          //  return "ACCESS_NUMBER:" . $result->getInsertedId() . ":91" . $validNumber['number'] . "";
          $number = $validNumber['number'];

// If number has 12 digits, remove first 2
if (strlen($number) === 12) {
    $number = substr($number, 2);
}

return "ACCESS_NUMBER:" . $result->getInsertedId() . ":91" . $number;
        } else {
            return "NO_NUMBER";
        }
    } catch (Exception $error) {
        return $error->getMessage();
    }
}




function getsms($request){
    try{
        $db = getMongoDBConnection();
        
        $params = $_GET;
        if (!isset($params['api_key']) || !$params['api_key']) {
            return "BAD_KEY";
        }
        if (!isset($params['id']) || !$params['id']) {
            return "NO_ACTIVATION";
        }
        $id = $params['id'];
        $api_key = $params['api_key'];
        $userdata = $db->users->findOne(['apikey' => $api_key]);
        if (!$userdata) {
            return "BAD_KEY";
        }
        $userid = $userdata["_id"];
        $id = new ObjectId($id);
        $order = $db->orders->findOne([
            "_id" => $id,
            "active" => true
        ]);
        if($order){
            $givenTime = $order["createdAt"];

            if ($givenTime instanceof UTCDateTime) {
                $givenTime = $givenTime->toDateTime();
            } else {
                $givenTime = new DateTime($givenTime);
            }
            
            $currentTime = new DateTime();
            $diffInSeconds = $currentTime->getTimestamp() - $givenTime->getTimestamp();
            $twentyMinutesInSeconds = 20 * 60; 
            
            if ($diffInSeconds < $twentyMinutesInSeconds) {
                $secondsLeft = $twentyMinutesInSeconds - $diffInSeconds;
                $bsonArray = new BSONArray($order['message']);
            
                $array = iterator_to_array($bsonArray);
                $slice = array_slice($array, -1, 1, true);
                $otp = end($slice);
                $otp = str_replace(":", "", $otp);
                if($otp == ""){
                    return "STATUS_WAIT_CODE";
                }else{
                return "STATUS_OK:$otp";
                }
            } else {
                return "STATUS_CANCEL";
            }
        }else{
         return "NO_ACTIVATION";
        }
    } catch (Exception $error){
        return "NO_ACTIVATION";
    }
}


function setcancel($request){
    try{
        $db = getMongoDBConnection();
        
        $params = $_GET;
        if (!isset($params['api_key']) || !$params['api_key']) {
            return "BAD_KEY";
        }
        if (!isset($params['id']) || !$params['id']) {
            return "NO_ACTIVATION";
        }
        if (!isset($params['status']) || !$params['status']) {
            return "BAD_STATUS";
        }
        if($params['status'] == 8){
        $id = $params['id'];
        $api_key = $params['api_key'];
        $userdata = $db->users->findOne(['apikey' => $api_key]);
        if (!$userdata) {
            return "BAD_KEY";
        }
        $id = new ObjectId($id);
        $order = $db->orders->findOne([
            "_id" => $id,
            "active" => true
        ]);
        if($order){
            if (!$order['isused']) {
    // Check if createdAt < 2 minutes ago
                    $givenTime = $order["createdAt"];
                    $now = new \MongoDB\BSON\UTCDateTime();
                    $diffMs = $now->toDateTime()->getTimestamp() - $givenTime->toDateTime()->getTimestamp();

                    if ($diffMs < 120) { 
                        return "EARLY_CANCEL_DENIED"; // Less than 2 minutes
                    }
            $updatedOrder = $db->orders->findOneAndUpdate(
                ['_id' => $order['_id'], 'active' => true, 'isused' => false],
                ['$set' => ['active' => false, 'failureReason' => 'user_cancelled', 'qualityImpact' => 0]],
                ['new' => true]
            );
            return "ACCESS_CANCEL";
        }else{
            $msg = $db->orders->findOneAndUpdate(
                ['_id' => $order['_id'], 'active' => true],
                ['$set' => ['active' => false, 'isused' => true]],
                ['new' => true]
            );
            return "ACCESS_ACTIVATION";
        } 
        }else{
         return "NO_ACTIVATION";
        }
    }elseif($params['status'] == 3){
        $id = $params['id'];
        $api_key = $params['api_key'];
        $userdata = $db->users->findOne(['apikey' => $api_key]);
        if (!$userdata) {
            return "BAD_KEY";
        }
          $id = new ObjectId($id);
        $order = $db->orders->findOne([
            "_id" => $id,
            "active" => true
        ]);
        if($order){
            if($order['isused']){
$msg = $db->orders->findOneAndUpdate(
    ['_id' => $order['_id']],
    [
        '$set' => [
            'nextsms'   => true,
            'updatedAt' => new \MongoDB\BSON\UTCDateTime()
        ]
    ],
    ['returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
);

            return "ACCESS_RETRY_GET";
        }else{
        return "ACCESS_READY";
        }
        }else{
         return "NO_ACTIVATION";
        }
    }else{
        return "BAD_STATUS"; 
    }
    } catch (Exception $error){
        return "ERROR_DATABASE";
    } 
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['action'])) {
        $action = $_GET['action'];
        
        if ($action == "getNumber") {
            echo buynumber($_GET);
        } elseif ($action == "getStatus") {
            echo getsms($_GET);
        } elseif ($action == "setStatus") {
            echo setcancel($_GET);
        }else{
        echo "WRONG_ACTION";
        }
    } else {
        echo "NO_ACTION";
    }
}

?>
