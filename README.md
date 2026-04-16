# SMS Gateway API - Stubs

## Overview

SMS Gateway API for renting phone numbers and receiving SMS verification codes.

## API Endpoints

- `POST /handler_api.php?action=getNumber` - Rent a phone number
- `GET /handler_api.php?action=getStatus&id={orderId}` - Check SMS status
- `GET /handler_api.php?action=setStatus&id={orderId}&status={status}` - Cancel/request next SMS

## Security

### IP-Based Access Control

**⚠️ IMPORTANT**: Access to `handler_api.php` is restricted at the nginx level.

**Allowed IP:** `23.111.15.80`

**Configuration:** `/etc/nginx/sites-available/stubs-test.conf`

```nginx
location = /handler_api.php {
    allow 23.111.15.80;
    deny all;
    # ...
}
```

### Security Features

- ✅ IP whitelist (nginx + application level)
- ✅ API key authentication (Argon2ID hashed)
- ✅ Redis-based rate limiting (30 req/sec for numbers, 5 auth attempts/5min)
- ✅ Input validation and sanitization
- ✅ MongoDB ObjectId validation
- ✅ Security headers (HSTS, X-Frame-Options, etc.)
- ✅ Server-side error logging

### API Authentication

```php
api_key=YOUR_API_KEY_HERE
```

Get your API key from the MongoDB `users` collection or use `generate_test_key.php`.

## Configuration

### Environment Variables (.env)

```bash
MONGO_HOST=localhost
MONGO_PORT=27017
MONGO_USERNAME=smsgateway
MONGO_PASSWORD=your_password
MONGO_AUTH_SOURCE=admin
ALLOWED_IP=23.111.15.80
```

### Dependencies

```bash
composer install
```

Required packages:
- `mongodb/mongodb` - MongoDB driver
- `vlucas/phpdotenv` - Environment variables
- `predis/predis` - Redis client for rate limiting

## Nginx Configuration

The API runs on port 8080 with nginx IP restrictions.

**Config file:** `/etc/nginx/sites-available/stubs-test.conf`

**To add more IPs:**

```nginx
location = /handler_api.php {
    allow 23.111.15.80;
    allow 23.111.15.81;  # Add new IP
    allow 10.0.0.0/8;     # Or entire subnet
    deny all;
    # ...
}
```

After changes:
```bash
sudo nginx -t
sudo systemctl reload nginx
```

## Error Codes

| Code | Description |
|------|-------------|
| `IP_BLOCKED` | IP not in whitelist |
| `BAD_KEY` | Invalid API key |
| `BAD_SERVICE` | Invalid service code |
| `BAD_COUNTRY` | Invalid country code |
| `NO_NUMBER` | No available numbers |
| `ACCOUNT_BAN` | User account banned |
| `RATE_LIMITED` | Rate limit exceeded (30 req/sec) |
| `NO_ACTIVATION` | Invalid order ID |
| `STATUS_WAIT_CODE` | Waiting for SMS |
| `STATUS_CANCEL` | Order expired |
| `ACCESS_CANCEL` | Successfully cancelled |
| `EARLY_CANCEL_DENIED` | Too early to cancel |
| `ACCESS_ACTIVATION` | Already used/complete |
| `ACCESS_RETRY_GET` | Ready for next SMS |
| `ACCESS_READY` | First SMS not received |
| `BAD_STATUS` | Invalid status code |
| `ERROR_DATABASE` | Database error |

## Development

### Generate New API Key

```bash
php generate_test_key.php
```

### Test API

```bash
# Test getNumber
curl "http://your-ip:8080/handler_api.php?action=getNumber&api_key=YOUR_KEY&service=snapmint&country=22"

# Test getStatus
curl "http://your-ip:8080/handler_api.php?action=getStatus&api_key=YOUR_KEY&id=ORDER_ID"
```

## Monitoring

### Redis Keys

```bash
# Check rate limit keys
redis-cli keys "auth:ratelimit:*"
redis-cli keys "number:ratelimit:*"

# Check user cache
redis-cli get "users:cache:all"
```

### MongoDB Collections

- `users` - API keys and user data
- `services` - Available services
- `countires` - Available countries
- `numbers` - Phone numbers
- `orders` - SMS orders
- `locks` - Number locks
- `ratelimit_auth` - Auth rate limit (if using MongoDB fallback)

## Troubleshooting

### IP Blocked but you're allowed

Check if you're behind a proxy/load balancer. The nginx check uses `REMOTE_ADDR`.

### Redis Connection Failed

```bash
sudo systemctl restart redis-server
```

### PHP-FPM Issues

```bash
sudo systemctl restart php8.1-fpm
```

## License

Proprietary - All rights reserved.
