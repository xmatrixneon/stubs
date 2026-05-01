# regetNumber Endpoint Design

**Date:** 2026-05-01
**Status:** Approved
**File:** `handler_api.php`

## Overview

Add a new `regetNumber` API endpoint that allows users to reset an existing order's message state to receive a new OTP on the same phone number and service. This is useful when the previous OTP expired before verification or the user needs to retry.

## Requirements

1. Reset the order's message array and `isused` flag
2. Only work on orders that have already received an OTP (`isused: true`)
3. Validate the phone number is still active and not suspended
4. Follow existing code patterns and error response format

## API Specification

### Endpoint

**Method:** `GET`
**Action:** `regetNumber`

**Parameters:**
| Parameter | Type   | Required | Description              |
|-----------|--------|----------|--------------------------|
| action    | string | Yes      | Must be `regetNumber`    |
| api_key   | string | Yes      | User's API key           |
| id        | string | Yes      | Order ID (MongoDB ObjectId) |

### Responses

| Response            | Meaning                                          |
|---------------------|--------------------------------------------------|
| `REGET_NUMBER_OK`   | Order successfully reset                         |
| `BAD_KEY`           | Invalid or missing API key                       |
| `NO_ACTIVATION`     | Order not found, inactive, OR `isused=false`     |
| `NO_ACTIVE_NUMBER`  | Phone number inactive or suspended               |
| `ACCOUNT_BAN`       | User account is banned                           |
| `ERROR_DATABASE`    | Database operation failed                        |

## Implementation

### Location

File: `/home/deploy/apps/stubs/handler_api.php`

### Changes

1. **Add `regetnumber()` function** (after `setcancel()`, ~line 330)
2. **Add dispatcher case** (in the action switch, ~line 341)

### Function Logic

```php
function regetnumber($request) {
    // 1. Validate parameters: api_key, id
    // 2. Validate user: lookup by api_key, check ban status
    // 3. Validate order: lookup by id, must have active=true AND isused=true
    // 4. Validate number: lookup in numbers collection, active=true AND suspended=false
    // 5. Reset order:
    //    - message = []
    //    - isused = false
    //    - nextsms = false
    //    - updatedAt = current timestamp
    // 6. Return "REGET_NUMBER_OK"
}
```

### Dispatcher Addition

```php
} elseif ($action == "regetNumber") {
    echo regetnumber($_GET);
}
```

## Validation Rules

| Check | Condition | Error Code |
|-------|-----------|------------|
| API key exists | `isset($params['api_key']) && $params['api_key']` | `BAD_KEY` |
| ID exists | `isset($params['id']) && $params['id']` | `NO_ACTIVATION` |
| User valid | `users.findOne(['apikey' => $key])` | `BAD_KEY` |
| User not banned | `!$userdata['ban']` | `ACCOUNT_BAN` |
| Order active | `order['active'] === true` | `NO_ACTIVATION` |
| Order was used | `order['isused'] === true` | `NO_ACTIVATION` |
| Number active | `number['active'] === true` | `NO_ACTIVE_NUMBER` |
| Number not suspended | `number['suspended'] === false` | `NO_ACTIVE_NUMBER` |

## Testing Plan

### Test Cases

1. **Fresh order (isused=false)** → Expect: `NO_ACTIVATION`
2. **Used order with active number** → Expect: `REGET_NUMBER_OK`
3. **Order with suspended number** → Expect: `NO_ACTIVE_NUMBER`
4. **Invalid API key** → Expect: `BAD_KEY`
5. **Non-existent order ID** → Expect: `NO_ACTIVATION`

### Manual Testing Commands

```bash
# Get a fresh order
ORDER_ID=$(curl -s "http://localhost:8080/handler_api.php?action=getNumber&api_key=sk_xxx&service=telegram&country=22" | cut -d: -f2)

# Try regetNumber on fresh order (should fail with NO_ACTIVATION)
curl "http://localhost:8080/handler_api.php?action=regetNumber&api_key=sk_xxx&id=$ORDER_ID"

# After receiving OTP (isused=true), try again (should succeed with REGET_NUMBER_OK)
curl "http://localhost:8080/handler_api.php?action=regetNumber&api_key=sk_xxx&id=$ORDER_ID"
```

## Design Notes

1. **Order timeout:** The 20-minute timeout in `getStatus` is NOT checked here. `regetNumber` works on any order with `active=true`, regardless of elapsed time.
2. **No timeout extension:** The original 20-minute window from order creation is not extended.
3. **Number preservation:** The same phone number and service are kept; only the message state is reset.
4. **Pattern consistency:** Follows the same validation and error response patterns as `getsms()` and `setcancel()`.

## References

- Existing endpoints: `buynumber()`, `getsms()`, `setcancel()`
- MongoDB collections: `users`, `orders`, `numbers`
- Error response format: Plain string constants (not JSON)
