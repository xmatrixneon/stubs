<?php
/**
 * Migration script: Hash existing plain text API keys
 * Run once to convert plain text API keys to Argon2ID hashes
 */

require 'vendor/autoload.php';

use MongoDB\Client;

echo "Starting API key migration...\n";

$mongoClient = new Client("mongodb://smsgateway:Lauda%409798@localhost:27017/smsgateway?authSource=admin");
$db = $mongoClient->smsgateway;

$users = $db->users->find(['apikey' => ['$exists' => true], 'apikey_hash' => ['$exists' => false]]);

$migratedCount = 0;
$failedUsers = [];

foreach ($users as $user) {
    try {
        $plainApiKey = $user['apikey'];
        $userId = $user['_id'];

        // Hash with Argon2ID
        $hashedKey = password_hash($plainApiKey, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 1
        ]);

        // Update user document
        $result = $db->users->updateOne(
            ['_id' => $userId],
            [
                '$set' => ['apikey_hash' => $hashedKey],
                '$unset' => ['apikey' => ''] // Remove plain text after hashing
            ]
        );

        if ($result->getModifiedCount() > 0) {
            $migratedCount++;
            echo "✓ Migrated: {$user['email']} (ID: {$userId})\n";
        }
    } catch (Exception $e) {
        $failedUsers[] = $user['email'] ?? $user['_id'];
        echo "✗ Failed: {$user['email']} - " . $e->getMessage() . "\n";
    }
}

echo "\n=== Migration Complete ===\n";
echo "Migrated: $migratedCount users\n";
if (!empty($failedUsers)) {
    echo "Failed: " . implode(', ', $failedUsers) . "\n";
}
echo "\nPlain text 'apikey' fields have been removed.\n";
echo "All API keys are now stored as 'apikey_hash' using Argon2ID.\n";
?>
