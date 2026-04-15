<?php
/**
 * Generate new secure random API keys for all users
 * Deletes old hashes and creates new secure keys
 */

require 'vendor/autoload.php';

use MongoDB\Client;

echo "=== REGENERATING ALL API KEYS ===\n";

$mongoClient = new Client("mongodb://smsgateway:Lauda%409798@localhost:27017/smsgateway?authSource=admin");
$db = $mongoClient->smsgateway;

// Get all users
$users = $db->users->find();

echo "Generating new secure API keys...\n\n";

foreach ($users as $user) {
    try {
        // Generate secure random API key (32 bytes = 64 hex chars)
        $newApiKey = bin2hex(random_bytes(32));

        // Hash with Argon2ID
        $hashedKey = password_hash($newApiKey, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 1
        ]);

        // Update user
        $result = $db->users->updateOne(
            ['_id' => $user['_id']],
            [
                '$set' => ['apikey_hash' => $hashedKey],
                '$unset' => ['apikey' => '']
            ]
        );

        if ($result->getModifiedCount() > 0 || $result->getUpsertedCount() > 0) {
            echo "✓ Email: {$user['email']}\n";
            echo "  NEW API KEY: $newApiKey\n\n";
        }
    } catch (Exception $e) {
        echo "✗ Failed: {$user['email']} - " . $e->getMessage() . "\n\n";
    }
}

echo "=== COMPLETE ===\n";
echo "ALL old API keys have been invalidated.\n";
echo "Use the new keys above for API access.\n";
?>
