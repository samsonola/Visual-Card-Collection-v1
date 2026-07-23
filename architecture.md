# Visual ICT Backend Architecture
Version: 1.0
Author: Afolayan Oladimeji
Last Updated: July 2026

---

# Project Overview

This project is a modular PHP backend consisting of several independent services that share a common Core framework.

Current Services

/core
/admin
/agent
/organization
/supervisor
/import-export-service
/media_service
/sync-service
/workers

Every service MUST use the shared Core layer.

Never duplicate framework logic.

---

# Core Bootstrap

Every endpoint begins with

```php
require_once __DIR__ . '/../core/bootstrap.php';
```

bootstrap.php automatically loads

- Composer autoload
- Environment (.env)
- Database
- Response helpers
- Logger
- JWT
- Redis
- Cache

Never manually reload these files.

---

# Response Standard

Always return JSON using

```php
json_response($data, $status);
```

Example

```php
json_response([
    'status'=>'success',
    'message'=>'Completed'
]);
```

Never

```
echo json_encode(...)
die(...)
print_r(...)
```

---

# Authentication

JWT helper functions

```php
jwt_encode()

jwt_refresh_encode()

jwt_decode()

jwt_refresh_decode()

get_bearer_token()
```

Authentication flow

```
$token = get_bearer_token();

if (!$token) {

    json_response([
        'error'=>'Unauthorized'
    ],401);

}

$user = jwt_decode($token);

if(!$user){

    json_response([
        'error'=>'Invalid token'
    ],401);

}
```

Never decode JWT manually.

---

# Cache Layer

The ONLY cache API is

```php
cacheGet()

cachePut()

cacheRemember()

cacheForget()

cacheFlush()
```

Never use

```
cache_get()

cache_set()

redis_cache_get()

Cache::get()

Cache::put()
```

Example

```php
$key = "beneficiaries:{$orgId}";

$data = cacheRemember(

    $key,

    function () use ($conn,$orgId){

        ...

        return $rows;

    },

    300

);
```

Cache automatically falls back when Redis is unavailable.

Never connect to Redis for caching.

---

# Redis

Redis connection already exists globally

```
$redis
```

Loaded from

```
core/redis.php
```

Do not reconnect Redis unless implementing queue workers.

---

# Database

Database is loaded by

```
core/db.php
```

Never create new MySQL connections inside endpoints.

Always reuse the existing database connection.

---

# Logging

General logging

```
log_error(...)
```

Import Export logging

```
import_export_log(...)
```

Media logging

```
media_log(...)
```

Never use

```
error_log()
```

inside production endpoints.

---

# Folder Responsibilities

## Core

Contains shared framework

```
bootstrap.php
db.php
cache.php
redis.php
jwt.php
logger.php
response.php
functions.php
```

Nothing outside Core should duplicate these.

---

## import-export-service

Responsible for

- CSV imports
- XLSX exports
- Queue management
- Progress tracking
- Retry
- Cancellation

Helpers

```
helpers.php
queue.php
validators.php
```

Workers

```
import_worker.php

export_worker.php
```

Wrapper workers

```
workers/import_worker.php

workers/export_worker.php
```

---

## media_service

Responsible for

- Upload
- Temporary storage
- S3 upload
- Retry
- Queue

Helpers

```
helpers.php
worker.php
queue.php
s3.php
```

Wrapper worker

```
workers/media_worker.php
```

---

## sync-service

Responsible for

- Synchronisation
- Notifications
- Retry
- Queue

---

# Queue Convention

Queues use Redis.

Only queue workers should communicate directly with Redis.

Application endpoints should call queue helper functions.

Example

```
queue_export_job()

queue_import_job()
```

Never duplicate queue logic.

---

# API Endpoint Template

Every endpoint should roughly follow

```php
require_once __DIR__.'/../../core/bootstrap.php';

$token = get_bearer_token();

if(!$token){

    json_response([
        'error'=>'Unauthorized'
    ],401);

}

$user = jwt_decode($token);

if(!$user){

    json_response([
        'error'=>'Invalid token'
    ],401);

}

// validation

// database

// cache

// response
```

---

# Coding Standards

Use procedural PHP.

No classes unless the existing project already uses them.

Reuse existing helper functions.

Never introduce new helper APIs.

Never duplicate framework code.

Never reconnect MySQL.

Never reconnect Redis except queue workers.

Always return JSON through

```
json_response()
```

Prefer

```
cacheRemember()
```

over manual cacheGet/cachePut.

---

# Before Creating Any New Function

Check whether the project already provides it.

If unsure,

ASK.

Never invent helper names.

---

# Rules For ChatGPT

When generating code for this project:

✅ Follow existing architecture.

✅ Reuse helper functions.

✅ Reuse bootstrap.

✅ Reuse JWT.

✅ Reuse cache.

✅ Reuse DB.

✅ Reuse response helper.

❌ Do NOT invent cache functions.

❌ Do NOT invent DB wrappers.

❌ Do NOT invent response helpers.

❌ Do NOT redesign the architecture.

❌ Do NOT replace existing helpers.

If a required helper is unknown,

ask for the file instead of guessing.

---

End of Document