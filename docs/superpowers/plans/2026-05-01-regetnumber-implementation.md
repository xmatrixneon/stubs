# regetNumber Endpoint Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `regetNumber` API endpoint that resets an existing order's message state to receive a new OTP on the same phone number and service.

**Architecture:** A new PHP function `regetnumber()` following existing validation patterns (parameter → user → order → number validation), using MongoDB's `findOneAndUpdate` for atomic order reset. The dispatcher is extended with a new action case.

**Tech Stack:** PHP 8.1+, MongoDB driver (mongodb/mongodb), existing handler_api.php patterns

---

## File Structure

| File | Responsibility | Change Type |
|------|---------------|-------------|
| `handler_api.php` | Main API handler with all endpoint functions | Modify |

No new files needed. Single-file modification following existing patterns.

---

## Task 1: Add the regetnumber() Function

**Files:**
- Modify: `handler_api.php:330` (insert after `setcancel()` function, before dispatcher)

- [ ] **Step 1: Add the regetnumber() function**

Insert this complete function after line 330 (after `setcancel()` ends, before the GET dispatcher):

```php
function regetnumber($request){
    try{
        $db = getMongoDBConnection();

        $params = $_GET;
        
        // Parameter validation
        if (!isset($params['api_key']) || !$params['api_key']) {
            return "BAD_KEY";
        }
        if (!isset($params['id']) || !$params['id']) {
            return "NO_ACTIVATION";
        }
        
        $id = $params['id'];
        $api_key = $params['api_key'];
        
        // User validation
        $userdata = $db->users->findOne(['apikey' => $api_key]);
        if (!$userdata) {
            return "BAD_KEY";
        }
        if (isset($userdata['ban']) && $userdata['ban'] === true) {
            return "ACCOUNT_BAN";
        }
        
        // Order validation
        $id = new ObjectId($id);
        $order = $db->orders->findOne([
            "_id" => $id,
            "active" => true
        ]);
        
        if (!$order) {
            return "NO_ACTIVATION";
        }
        
        // Order must have been used (isused = true) to allow reget
        if (!$order['isused']) {
            return "NO_ACTIVATION";
        }
        
        // Number validation - check if still active and not suspended
        $numberData = $db->numbers->findOne([
            'number' => $order['number'],
            'active' => true,
            'suspended' => false
        ]);
        
        if (!$numberData) {
            return "NO_ACTIVE_NUMBER";
        }
        
        // Reset the order
        $updatedOrder = $db->orders->findOneAndUpdate(
            [
                '_id' => $id,
                'active' => true
            ],
            [
                '$set' => [
                    'message' => [],
                    'isused' => false,
                    'nextsms' => false,
                    'updatedAt' => new MongoDB\BSON\UTCDateTime(time() * 1000)
                ]
            ],
            [
                'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER
            ]
        );
        
        if ($updatedOrder) {
            return "REGET_NUMBER_OK";
        } else {
            return "ERROR_DATABASE";
        }
        
    } catch (Exception $error){
        return "ERROR_DATABASE";
    }
}
```

- [ ] **Step 2: Verify PHP syntax**

Run: `php -l /home/deploy/apps/stubs/handler_api.php`

Expected output: `No syntax errors detected in /home/deploy/apps/stubs/handler_api.php`

- [ ] **Step 3: Commit the function addition**

```bash
git add handler_api.php
git commit -m "feat: add regetnumber() function for order reset

- Validates API key, user, order, and phone number
- Only works on orders where isused=true (already received OTP)
- Clears message array and resets isused/nextsms flags
- Returns REGET_NUMBER_OK on success"
```

---

## Task 2: Add the Dispatcher Case

**Files:**
- Modify: `handler_api.php:340-344` (in the GET action dispatcher)

- [ ] **Step 1: Add the regetNumber action case**

Find the dispatcher section (lines 332-348). After the `setStatus` elseif block (around line 341), add:

```php
} elseif ($action == "regetNumber") {
    echo regetnumber($_GET);
```

The complete dispatcher section should look like this after the change:

```php
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['action'])) {
        $action = $_GET['action'];

        if ($action == "getNumber") {
            echo buynumber($_GET);
        } elseif ($action == "getStatus") {
            echo getsms($_GET);
        } elseif ($action == "setStatus") {
            echo setcancel($_GET);
        } elseif ($action == "regetNumber") {
            echo regetnumber($_GET);
        }else{
        echo "WRONG_ACTION";
        }
    } else {
        echo "NO_ACTION";
    }
}
```

- [ ] **Step 2: Verify PHP syntax**

Run: `php -l /home/deploy/apps/stubs/handler_api.php`

Expected output: `No syntax errors detected in /home/deploy/apps/stubs/handler_api.php`

- [ ] **Step 3: Commit the dispatcher addition**

```bash
git add handler_api.php
git commit -m "feat: add regetNumber action to dispatcher"
```

---

## Task 3: Manual Testing

**Files:**
- None (testing via curl)

- [ ] **Step 1: Test with invalid API key**

Run: `curl -s "http://localhost:8080/handler_api.php?action=regetNumber&api_key=invalid_key&id=507f1f77bcf86cd799439011"`

Expected output: `BAD_KEY`

- [ ] **Step 2: Test with missing id parameter**

Run: `curl -s "http://localhost:8080/handler_api.php?action=regetNumber&api_key=sk_test_key"`

Expected output: `NO_ACTIVATION`

- [ ] **Step 3: Create a test order to work with**

First, generate a test API key:
```bash
php /home/deploy/apps/stubs/generate_test_key.php
```

Then get a fresh order:
```bash
ORDER_RESPONSE=$(curl -s "http://localhost:8080/handler_api.php?action=getNumber&api_key=YOUR_TEST_KEY&service=telegram&country=22")
echo $ORDER_RESPONSE
```

Expected format: `ACCESS_NUMBER:order_id:dialcode+number`

Extract the order ID:
```bash
ORDER_ID=$(echo $ORDER_RESPONSE | cut -d: -f2)
echo "Order ID: $ORDER_ID"
```

- [ ] **Step 4: Test regetNumber on fresh order (isused=false) - should fail**

Run: `curl -s "http://localhost:8080/handler_api.php?action=regetNumber&api_key=YOUR_TEST_KEY&id=$ORDER_ID"`

Expected output: `NO_ACTIVATION` (because isused=false on fresh orders)

- [ ] **Step 5: Simulate an used order for testing**

For a proper test, you need an order where `isused=true`. You can either:
1. Wait for an actual SMS to arrive on the number
2. Manually update the order in MongoDB:

```bash
mongosh smsgateway --eval "db.orders.updateOne({_id: ObjectId('$ORDER_ID')}, {\$set: {isused: true, message: ['123456']}})"
```

- [ ] **Step 6: Test regetNumber on used order with active number**

Run: `curl -s "http://localhost:8080/handler_api.php?action=regetNumber&api_key=YOUR_TEST_KEY&id=$ORDER_ID"`

Expected output: `REGET_NUMBER_OK`

- [ ] **Step 7: Verify the order was reset**

Run: `mongosh smsgateway --eval "db.orders.findOne({_id: ObjectId('$ORDER_ID')}, {message: 1, isused: 1, nextsms: 1})"`

Expected: `message` should be empty array `[]`, `isused` should be `false`, `nextsms` should be `false`

- [ ] **Step 8: Test with suspended number (simulate and test)**

First, suspend a number in MongoDB:
```bash
# Find a number and suspend it
mongosh smsgateway --eval "db.numbers.updateOne({number: 'YOUR_NUMBER_HERE'}, {\$set: {suspended: true}})"
```

Then try regetNumber on an order using that number:
```bash
curl -s "http://localhost:8080/handler_api.php?action=regetNumber&api_key=YOUR_TEST_KEY&id=ORDER_WITH_SUSPENDED_NUMBER"
```

Expected output: `NO_ACTIVE_NUMBER`

Restore the number after testing:
```bash
mongosh smsgateway --eval "db.numbers.updateOne({number: 'YOUR_NUMBER_HERE'}, {\$set: {suspended: false}})"
```

- [ ] **Step 9: Test invalid action parameter**

Run: `curl -s "http://localhost:8080/handler_api.php?action=invalidAction&api_key=YOUR_TEST_KEY&id=$ORDER_ID"`

Expected output: `WRONG_ACTION`

---

## Task 4: Documentation Update

**Files:**
- Modify: `README.md` (add regetNumber endpoint documentation)

- [ ] **Step 1: Read existing README to understand format**

Run: `head -100 /home/deploy/apps/stubs/README.md`

- [ ] **Step 2: Add regetNumber endpoint documentation**

Add a new section to README.md following the existing format. Insert after the `setStatus` section:

```markdown
### regetNumber

Reset an existing order to receive a new OTP on the same number/service.

**Parameters:**
- `action` - Must be `regetNumber`
- `api_key` - Your API key
- `id` - Order ID (from getNumber response)

**Responses:**
- `REGET_NUMBER_OK` - Order successfully reset
- `BAD_KEY` - Invalid API key
- `NO_ACTIVATION` - Order not found, inactive, or not yet used
- `NO_ACTIVE_NUMBER` - Phone number inactive or suspended
- `ACCOUNT_BAN` - Account is banned
- `ERROR_DATABASE` - Database error

**Example:**
```bash
curl "http://localhost:8080/handler_api.php?action=regetNumber&api_key=sk_xxx&id=order_id"
```

**Note:** Only works on orders that have already received an OTP (`isused: true`). Fresh orders will return `NO_ACTIVATION`.
```

- [ ] **Step 3: Commit documentation update**

```bash
git add README.md
git commit -m "docs: add regetNumber endpoint documentation"
```

---

## Verification Checklist

After completing all tasks, verify:

- [ ] PHP syntax is valid: `php -l handler_api.php`
- [ ] Function exists: `grep -n "function regetnumber" handler_api.php`
- [ ] Dispatcher case added: `grep -n 'regetNumber' handler_api.php`
- [ ] All error codes defined: `BAD_KEY`, `NO_ACTIVATION`, `NO_ACTIVE_NUMBER`, `ACCOUNT_BAN`, `REGET_NUMBER_OK`, `ERROR_DATABASE`
- [ ] MongoDB update uses atomic `findOneAndUpdate`
- [ ] Order validation checks both `active=true` AND `isused=true`
- [ ] Number validation checks both `active=true` AND `suspended=false`

---

## Notes for Implementation

1. **Error Response Format:** All errors are plain string constants, not JSON. Follow this pattern consistently.

2. **ObjectId Conversion:** Always convert the order ID string to ObjectId before MongoDB query: `new ObjectId($id)`

3. **Atomic Updates:** Using `findOneAndUpdate` ensures thread-safe updates. The `returnDocument` option returns the updated document for verification.

4. **Validation Order:** The function validates in this specific order:
   - Parameters → User → Order → Number → Update
   Each validation failure returns immediately with the appropriate error code.

5. **isused Check:** The requirement that `isused=true` is intentional — it prevents users from calling regetNumber on fresh orders that haven't received an OTP yet.

6. **No Timeout Check:** Unlike `getStatus`, this function does not check the 20-minute timeout. The order can be reset anytime as long as it's `active=true`.
