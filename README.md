# SimpleR2 - Cloudflare R2 PHP Client

Based on SimpleS3 - https://github.com/mnapoli/simple-s3

This PHP client provides a simple interface to interact with Cloudflare's R2 storage service, allowing for basic operations such as GET, PUT, and DELETE requests.


## Initialization
You can initialize the SimpleR2 client either by passing credentials and endpoint directly or by using environment variables.
```php
$r2 = new SimpleR2('ACCESS_KEY_ID', 'SECRET_ACCESS_KEY', 'SESSION_TOKEN', 'R2_ENDPOINT');
```
OR
```php
$r2 = SimpleR2::fromEnvironmentVariables('R2_ENDPOINT');
```

## GET (Download)
Retrieve the content from a specified bucket and key.
```php
$response = $r2->get('BUCKET_NAME', 'OBJECT_KEY');
```

## PUT (Upload)
Upload a file or content to a specified bucket and key.
```php
$response = $r2->put('BUCKET_NAME', 'OBJECT_KEY', 'CONTENT');
```

## DELETE
Delete an object from a specified bucket and key.
```php
$response = $r2->delete('BUCKET_NAME', 'OBJECT_KEY');
```
