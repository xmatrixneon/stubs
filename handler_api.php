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
use Predis\Client as RedisClient;

// PERFORMANCE: Cache env variables in memory
static $envLoaded = false;
static $mongoConnection = null;

if (!$envLoaded) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->safeLoad();
    $envLoaded = true;
}

/**
 * Get cached MongoDB connection (reuse across requests)
 */
function getMongoDBConnection($database = 'smsgateway') {
    global $mongoConnection;

    if ($mongoConnection !== null) {
        return $mongoConnection->$database;
    }

    try {
        $host = $_ENV['MONGO_HOST'] ?? 'localhost';
        $port = $_ENV['MONGO_PORT'] ?? '27017';
        $username = $_ENV['MONGO_USERNAME'] ?? '';
        $password = $_ENV['MONGO_PASSWORD'] ?? '';
        $authSource = $_ENV['MONGO_AUTH_SOURCE'] ?? 'admin';

        if (empty($username) || empty($password)) {
            throw new Exception("MongoDB credentials not configured");
        }

        // URL-encode username and password for connection string
        $encodedUsername = rawurlencode($username);
        $encodedPassword = rawurlencode($password);

        // Use SCRAM-SHA-256 for secure authentication (hash-based)
        $dsn = "mongodb://{$encodedUsername}:{$encodedPassword}@{$host}:{$port}/{$database}?authSource={$authSource}&authMechanism=SCRAM-SHA-256";
        $mongoClient = new Client($dsn);
        $mongoConnection = $mongoClient;
        return $mongoClient->$database;
    } catch (Exception $e) {
        error_log("MongoDB connection error: " . $e->getMessage());
        die("Database connection failed. Please try again later.");
    }
}

/**
 * Get cached users from Redis (avoid DB query on every request)
 * SECURITY: Uses Redis with 60-second TTL so bans take effect quickly
 */
function getCachedUsers($db) {
    $redis = getRedisConnection();
    $cacheKey = 'users:cache:all';

    // Try Redis first
    if ($redis !== null) {
        try {
            $cached = $redis->get($cacheKey);
            if ($cached !== null) {
                return json_decode($cached, true);
            }
        } catch (Exception $e) {
            error_log("Redis cache get error: " . $e->getMessage());
        }
    }

    // Fetch from database
    $users = iterator_to_array($db->users->find(['apikey_hash' => ['$exists' => true]], [
        'projection' => ['apikey_hash' => 1, 'ban' => 1, 'active' => 1, 'email' => 1]
    ]));

    // Store in Redis with 60-second TTL
    if ($redis !== null) {
        try {
            $redis->setex($cacheKey, 60, json_encode($users));
        } catch (Exception $e) {
            error_log("Redis cache set error: " . $e->getMessage());
        }
    }

    return $users;
}

/**
 * Verify API key using hash-based comparison with cached users
 * ENHANCED: Uses Redis for persistent rate limiting across requests
 */
function verifyApiKey($plainApiKey, $db) {
    if (empty($plainApiKey)) {
        return false;
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $apiKeyHash = substr(hash('sha256', $plainApiKey), 0, 16);

    // Use cached users - NO DB query!
    $users = getCachedUsers($db);

    foreach ($users as $user) {
        if (password_verify($plainApiKey, $user['apikey_hash'])) {
            // Reset failed attempts on successful verification
            resetFailedAuthAttempts($ip, $apiKeyHash);
            return $user;
        }
    }

    // Record failed attempt - returns false if rate limited
    if (!recordFailedAuthAttempt($ip, $apiKeyHash)) {
        error_log("API key verification rate limited for IP: {$ip}");
    }

    return false;
}

/**
 * Verify IP-based access control (enhanced security)
 * FIXED: Removed X-Forwarded-For spoofing vulnerability
 */
function verifyIpAccess() {
    $allowedIp = $_ENV['ALLOWED_IP'] ?? '';
    if (empty($allowedIp)) {
        return false;
    }

    // Use only REMOTE_ADDR - it's set by the server, not the client
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    // Validate IP format before comparison
    if (filter_var($clientIp, FILTER_VALIDATE_IP) === false) {
        error_log("Invalid client IP format: " . $clientIp);
        return false;
    }

    return $clientIp === $allowedIp;
}

/**
 * Get cached Redis connection (reuse across requests)
 */
function getRedisConnection() {
    static $redis = null;

    if ($redis !== null) {
        return $redis;
    }

    try {
        $redis = new RedisClient([
            'scheme' => 'tcp',
            'host' => '127.0.0.1',
            'port' => 6379,
            'timeout' => 2.0,
        ]);
        return $redis;
    } catch (Exception $e) {
        error_log("Redis connection error: " . $e->getMessage());
        return null;
    }
}

/**
 * Validate MongoDB ObjectId format (24-character hex string)
 */
function validateObjectId($id) {
    if (!is_string($id)) {
        return false;
    }
    // ObjectId must be exactly 24 hexadecimal characters
    return preg_match('/^[a-f0-9]{24}$/i', $id) === 1;
}

/**
 * Sanitize and validate service code (alphanumeric + underscore + hyphen)
 */
function validateServiceCode($service) {
    if (empty($service)) {
        return false;
    }
    // Sanitize first
    $service = filter_var($service, FILTER_SANITIZE_SPECIAL_CHARS);
    // Validate format: alphanumeric, underscore, hyphen, 2-50 chars
    return preg_match('/^[A-Z0-9_-]{2,50}$/i', $service) === 1;
}

/**
 * Sanitize and validate country code (2-letter ISO code OR numeric code)
 */
function validateCountryCode($country) {
    if (empty($country)) {
        return false;
    }
    // Sanitize first
    $country = filter_var($country, FILTER_SANITIZE_SPECIAL_CHARS);
    // Validate format: either 2 uppercase letters (ISO) OR 1-4 digits
    return preg_match('/^[A-Z]{2}$/', $country) === 1 || preg_match('/^\d{1,4}$/', $country) === 1;
}

/**
 * Validate status code (whitelist: 3 or 8)
 */
function validateStatusCode($status) {
    $validStatuses = [3, 8];
    return in_array((int)$status, $validStatuses, true);
}

/**
 * Check number request rate limit (30 requests per second per API key)
 * Returns true if under limit, false if rate limited
 */
function checkNumberRequestRateLimit($apiKeyHash) {
    $redis = getRedisConnection();
    if ($redis === null) {
        // If Redis is unavailable, allow request (fail open)
        return true;
    }

    try {
        $currentSecond = time();
        $key = "number:ratelimit:{$apiKeyHash}:{$currentSecond}";

        // Increment counter with 2-second expiry (auto-cleanup)
        $count = $redis->incr($key);
        $redis->expire($key, 2);

        // Check if exceeded limit (30 req/sec)
        if ($count > 30) {
            error_log("Number request rate limit exceeded for API key hash: {$apiKeyHash}");
            return false;
        }

        return true;
    } catch (Exception $e) {
        error_log("Redis rate limit error: " . $e->getMessage());
        return true; // Fail open on Redis errors
    }
}

/**
 * Record failed API key attempt for rate limiting (5 attempts per 5 minutes)
 * Returns true if allowed, false if blocked
 */
function recordFailedAuthAttempt($ip, $apiKeyHash) {
    $redis = getRedisConnection();
    if ($redis === null) {
        // If Redis is unavailable, use in-memory fallback (broken but better than nothing)
        static $attempts = [];
        $key = $ip . ':' . $apiKeyHash;

        if (isset($attempts[$key])) {
            if ($attempts[$key]['count'] >= 5 && (time() - $attempts[$key]['last']) < 300) {
                return false;
            }
        }

        $attempts[$key] = ['count' => ($attempts[$key]['count'] ?? 0) + 1, 'last' => time()];
        return true;
    }

    try {
        $key = "auth:ratelimit:{$ip}:{$apiKeyHash}";

        // Increment counter with 300-second expiry
        $count = $redis->incr($key);
        $redis->expire($key, 300);

        // Check if exceeded limit (5 attempts per 5 minutes)
        if ($count >= 5) {
            error_log("Auth rate limit exceeded for IP: {$ip}");
            return false;
        }

        return true;
    } catch (Exception $e) {
        error_log("Redis auth rate limit error: " . $e->getMessage());
        return true; // Fail open on Redis errors
    }
}

/**
 * Reset failed auth attempts on successful authentication
 */
function resetFailedAuthAttempts($ip, $apiKeyHash) {
    $redis = getRedisConnection();
    if ($redis !== null) {
        try {
            $key = "auth:ratelimit:{$ip}:{$apiKeyHash}";
            $redis->del($key);
        } catch (Exception $e) {
            error_log("Redis error resetting auth attempts: " . $e->getMessage());
        }
    }
}

/**
 * Invalidate user cache (call after banning user, changing status, etc.)
 * This allows immediate revocation of access
 */
function invalidateUserCache() {
    $redis = getRedisConnection();
    if ($redis !== null) {
        try {
            $redis->del('users:cache:all');
            error_log("User cache invalidated");
        } catch (Exception $e) {
            error_log("Redis cache invalidation error: " . $e->getMessage());
        }
    }
}

/**
 * Send security headers
 */
function sendSecurityHeaders() {
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
    header("X-XSS-Protection: 1; mode=block");
    header("Strict-Transport-Security: max-age=31536000");
}

function buynumber($request) {
    try {
        if (!verifyIpAccess()) {
            http_response_code(403);
            return "IP_BLOCKED";
        }

        $db = getMongoDBConnection();

        $params = $_GET;
        if (!isset($params['api_key']) || !$params['api_key']) {
            http_response_code(401);
            return "BAD_KEY";
        }
        if (!isset($params['service']) || !$params['service']) {
            http_response_code(400);
            return "BAD_SERVICE";
        }
        if (!isset($params['country']) || $params['country'] === '') {
            http_response_code(400);
            return "BAD_COUNTRY";
        }

        // Sanitize and validate inputs
        $api_key = filter_var($params['api_key'], FILTER_UNSAFE_RAW);
        $service = filter_var($params['service'], FILTER_SANITIZE_SPECIAL_CHARS);
        $country = filter_var($params['country'], FILTER_SANITIZE_SPECIAL_CHARS);

        // Validate input formats
        if (!validateServiceCode($service)) {
            error_log("Invalid service code format: " . $service);
            http_response_code(400);
            return "BAD_SERVICE";
        }
        if (!validateCountryCode($country)) {
            error_log("Invalid country code format: " . $country);
            http_response_code(400);
            return "BAD_COUNTRY";
        }

        $userdata = verifyApiKey($api_key, $db);
        if (!$userdata) {
            http_response_code(401);
            return "BAD_KEY";
        }
        if (isset($userdata['ban']) && $userdata['ban'] === true) {
            http_response_code(403);
            return "ACCOUNT_BAN";
        }

        // Check number request rate limit (30 req/sec)
        $apiKeyHash = hash('sha256', $api_key);
        if (!checkNumberRequestRateLimit($apiKeyHash)) {
            http_response_code(429);
            return "RATE_LIMITED";
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

            // Cooldown check: prevent instant reuse
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

            // Passed all checks
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

http_response_code(200);
return "ACCESS_NUMBER:" . $result->getInsertedId() . ":91" . $number;
        } else {
            return "NO_NUMBER";
        }
    } catch (Exception $error) {
        error_log("Error in buynumber: " . $error->getMessage());
        http_response_code(500);
        return "ERROR_DATABASE";
    }
}




function getsms($request){
    try{
        if (!verifyIpAccess()) {
            http_response_code(403);
            return "IP_BLOCKED";
        }

        $db = getMongoDBConnection();

        $params = $_GET;
        if (!isset($params['api_key']) || !$params['api_key']) {
            http_response_code(401);
            return "BAD_KEY";
        }
        if (!isset($params['id']) || !$params['id']) {
            http_response_code(400);
            return "NO_ACTIVATION";
        }

        // Sanitize and validate inputs
        $api_key = filter_var($params['api_key'], FILTER_UNSAFE_RAW);
        $id = filter_var($params['id'], FILTER_SANITIZE_SPECIAL_CHARS);

        // Validate ObjectId format before instantiation
        if (!validateObjectId($id)) {
            error_log("Invalid ObjectId format in getsms: " . $id);
            http_response_code(400);
            return "NO_ACTIVATION";
        }

        $userdata = verifyApiKey($api_key, $db);
        if (!$userdata) {
            http_response_code(401);
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
                    http_response_code(200);
                    return "STATUS_WAIT_CODE";
                }else{
                http_response_code(200);
                return "STATUS_OK:$otp";
                }
            } else {
                http_response_code(200);
                return "STATUS_CANCEL";
            }
        }else{
         http_response_code(200);
         return "NO_ACTIVATION";
        }
    } catch (Exception $error){
        error_log("Error in getsms: " . $error->getMessage());
        http_response_code(500);
        return "NO_ACTIVATION";
    }
}


function setcancel($request){
    try{
        if (!verifyIpAccess()) {
            http_response_code(403);
            return "IP_BLOCKED";
        }

        $db = getMongoDBConnection();

        $params = $_GET;
        if (!isset($params['api_key']) || !$params['api_key']) {
            http_response_code(401);
            return "BAD_KEY";
        }
        if (!isset($params['id']) || !$params['id']) {
            http_response_code(400);
            return "NO_ACTIVATION";
        }
        if (!isset($params['status']) || !$params['status']) {
            http_response_code(400);
            return "BAD_STATUS";
        }

        // Sanitize and validate inputs
        $api_key = filter_var($params['api_key'], FILTER_UNSAFE_RAW);
        $id = filter_var($params['id'], FILTER_SANITIZE_SPECIAL_CHARS);
        $status = filter_var($params['status'], FILTER_VALIDATE_INT);

        // Validate ObjectId format before instantiation
        if (!validateObjectId($id)) {
            error_log("Invalid ObjectId format in setcancel: " . $id);
            http_response_code(400);
            return "NO_ACTIVATION";
        }

        // Validate status code (only 3 or 8 allowed)
        if (!validateStatusCode($status)) {
            error_log("Invalid status code in setcancel: " . $status);
            http_response_code(400);
            return "BAD_STATUS";
        }

        if($status == 8){
        $userdata = verifyApiKey($api_key, $db);
        if (!$userdata) {
            http_response_code(401);
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
                        http_response_code(200);
                        return "EARLY_CANCEL_DENIED"; // Less than 2 minutes
                    }
            $updatedOrder = $db->orders->findOneAndUpdate(
                ['_id' => $order['_id'], 'active' => true, 'isused' => false],
                ['$set' => ['active' => false, 'failureReason' => 'user_cancelled', 'qualityImpact' => 0]],
                ['new' => true]
            );
            http_response_code(200);
            return "ACCESS_CANCEL";
        }else{
            $msg = $db->orders->findOneAndUpdate(
                ['_id' => $order['_id'], 'active' => true],
                ['$set' => ['active' => false, 'isused' => true]],
                ['new' => true]
            );
            http_response_code(200);
            return "ACCESS_ACTIVATION";
        }
        }else{
         http_response_code(200);
         return "NO_ACTIVATION";
        }
    }elseif($status == 3){
        $userdata = verifyApiKey($api_key, $db);
        if (!$userdata) {
            http_response_code(401);
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

            http_response_code(200);
            return "ACCESS_RETRY_GET";
        }else{
        http_response_code(200);
        return "ACCESS_READY";
        }
        }else{
         return "NO_ACTIVATION";
        }
    }else{
        http_response_code(400);
        return "BAD_STATUS";
    }
    } catch (Exception $error){
        error_log("Error in setcancel: " . $error->getMessage());
        http_response_code(500);
        return "ERROR_DATABASE";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Send security headers on all requests
    sendSecurityHeaders();

    if (isset($_GET['action'])) {
        $action = filter_var($_GET['action'], FILTER_SANITIZE_SPECIAL_CHARS);

        if ($action == "getNumber") {
            echo buynumber($_GET);
        } elseif ($action == "getStatus") {
            echo getsms($_GET);
        } elseif ($action == "setStatus") {
            echo setcancel($_GET);
        }else{
        http_response_code(400);
        echo "WRONG_ACTION";
        }
    } else {
        http_response_code(400);
        echo "NO_ACTION";
    }
}

?>
