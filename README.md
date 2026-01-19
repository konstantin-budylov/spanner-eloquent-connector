# Eloquent Spanner Connector

A comprehensive Laravel Eloquent driver for Google Cloud Spanner that extends the base `colopl/laravel-spanner` package with advanced features, optimizations, and developer tools.

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

## Overview

This package provides a production-ready integration between Laravel's Eloquent ORM and Google Cloud Spanner, offering seamless database operations with enhanced features for binary UUIDs, numeric types, query optimization, and comprehensive debugging capabilities.

## Features

### Core Functionality
- **Full Eloquent ORM Integration** - Complete support for Laravel's Eloquent models with Spanner
- **Extended Query Builder** - Custom query builder with Spanner-specific optimizations
- **Schema Builder** - Enhanced schema builder with Spanner data types support
- **Migration Support** - Complete migration system for Spanner databases

### Data Type Support
- **Binary UUID** - Native BYTES(16) UUID support with automatic casting via `SpannerBinaryUuid`
- **Numeric/Decimal** - High-precision numeric type support via `SpannerNumeric`
- **JSON Objects** - JSON data type support with custom casting via `SpannerJsonObjectCast`
- **Timestamps** - Automatic timestamp handling with timezone support

### Advanced Features
- **Query Optimization**
  - Automatic GROUP BY scan optimization
  - Force join order hints
  - Hash join build side optimization
  - Batch mode operations
  - AS alias support for compatibility

- **Session & Lock Management**
  - PSR-6 cache integration for session pooling
  - Redis/File-based session pool support
  - Symfony Lock integration for emulator environments
  - Connection-level lock management

- **Queue & Batch Support**
  - Laravel Queue integration with binary UUID support
  - Batch job processing with `DatabaseBatchRepository`
  - Failed job provider with Spanner compatibility

### Developer Tools
- **Laravel Debugbar Integration** - Custom debugbar collector with BYTES() type support
- **Query Tracing** - Built-in query timing and tracing capabilities
- **Emulator Support** - Full Google Cloud Spanner Emulator compatibility

## Requirements

- **PHP**: >= 8.0
- **Laravel**: >= 10.32
- **Google Cloud Spanner**: ^1.0 || ^2.0 || ^3.0
- **Extensions**: grpc (recommended for production)

## Installation

Install via Composer:

```bash
composer require konstantin-budylov/eloquent-spanner-connector
```

## Configuration

### 1. Add Spanner Connection

Add the Spanner connection to your `config/database.php`:

```php
'connections' => [
    'spanner' => [
        'driver' => 'sxope-spanner',
        'instance' => env('SPANNER_INSTANCE', 'your-instance'),
        'database' => env('SPANNER_DATABASE', 'your-database'),
        'prefix' => '',
        
        // Optional: Session pool configuration
        'session_pool' => [
            'minSessions' => 10,
            'maxSessions' => 500,
        ],
        
        // Optional: Cache configuration for auth and sessions
        'sessionPoolDriver' => 'redis', // or 'file'
        
        // Optional: Google Cloud project configuration
        'client' => [
            'projectId' => env('GOOGLE_CLOUD_PROJECT'),
        ],
    ],
],
```

### 2. Environment Variables

Add to your `.env` file:

```env
SPANNER_INSTANCE=your-instance-id
SPANNER_DATABASE=your-database-name
GOOGLE_CLOUD_PROJECT=your-project-id

# For local development with emulator
SPANNER_EMULATOR_HOST=localhost:9010
```

### 3. Service Provider Registration

The package uses Laravel's auto-discovery. For manual registration, add to `config/app.php`:

```php
'providers' => [
    // ...
    KonstantinBudylov\EloquentSpanner\ServiceProvider::class,
    KonstantinBudylov\EloquentSpanner\QueueServiceProvider::class, // For queue support
    KonstantinBudylov\EloquentSpanner\BusServiceProvider::class,   // For batch jobs
],
```

### 4. Emulator Lock Configuration (Optional)

For emulator environments, configure lock behavior in `config/database.php`:

```php
'spannerEmulatorLock' => [
    'driver' => 'redis', // 'redis', 'file', or 'none'
    'connection' => 'default',
],
```

## Usage

### Basic Model Setup

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use KonstantinBudylov\EloquentSpanner\ModelTrait;

class User extends Model
{
    use ModelTrait;
    
    protected $connection = 'spanner';
    protected $keyType = 'spanner_binary_uuid'; // or 'string', 'int64'
    
    protected $casts = [
        'id' => 'spanner_binary_uuid',
        'balance' => 'spanner_numeric',
        'metadata' => \KonstantinBudylov\EloquentSpanner\Casts\SpannerJsonObjectCast::class,
    ];
}
```

### Automatic UUID Generation

Models with `ModelTrait` automatically generate UUIDs on creation:

```php
$user = new User();
$user->name = 'John Doe';
$user->email = 'john@example.com';
$user->save();
// $user->id is automatically generated as binary UUID
```

### Working with Binary UUIDs

```php
use KonstantinBudylov\EloquentSpanner\SpannerBinaryUuid;

// Create from string UUID
$uuid = new SpannerBinaryUuid('550e8400-e29b-41d4-a716-446655440000');

// Generate random UUID
$uuid = SpannerBinaryUuid::randomUuidBytes();

// Use in queries
$user = User::where('id', $uuid)->first();

// Compare UUIDs
if ($user->id->equals($uuid)) {
    // ...
}
```

### Working with Numeric/Decimal Types

```php
use KonstantinBudylov\EloquentSpanner\SpannerNumeric;

$product = new Product();
$product->price = new SpannerNumeric('19.99');
$product->save();

// Automatic JSON serialization
echo json_encode($product); // price is serialized as string
```

### Schema Migrations

```php
<?php

use Illuminate\Database\Migrations\Migration;
use KonstantinBudylov\EloquentSpanner\Schema\BaseSpannerSchemaBlueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::connection('spanner')->create('users', function (BaseSpannerSchemaBlueprint $table) {
            // Binary UUID primary key
            $table->binaryUuid('id')->primary();
            
            // Standard columns
            $table->string('name', 256);
            $table->string('email', 256)->unique();
            
            // Numeric column
            $table->numeric('balance', 15, 2);
            
            // JSON column
            $table->json('metadata');
            
            // Binary hash for checksums
            $table->binaryHash('checksum');
            
            // Technical columns (created_at, updated_at, etc.)
            $table->technical(
                precision: 0,
                withDataOwner: true,
                withUserNames: true
            );
            
            // Indexes
            $table->index('email');
        });
    }
    
    public function down()
    {
        Schema::connection('spanner')->dropIfExists('users');
    }
};
```

### Query Optimization Hints

The package includes several Spanner-specific query optimization hints:

```php
use KonstantinBudylov\EloquentSpanner\Query\SpannerQueryBuilder;

// Force join order
$results = DB::connection('spanner')
    ->table('orders')
    ->forceJoinOrder()
    ->join('customers', 'orders.customer_id', '=', 'customers.id')
    ->get();

// Hash join optimization
$results = DB::connection('spanner')
    ->table('orders')
    ->hashJoinBuildSide('customers')
    ->join('customers', 'orders.customer_id', '=', 'customers.id')
    ->get();

// Batch mode
$results = DB::connection('spanner')
    ->table('users')
    ->batchMode()
    ->get();

// Group by scan optimization
$results = DB::connection('spanner')
    ->table('orders')
    ->groupByScanOptimization()
    ->select('customer_id', DB::raw('COUNT(*)'))
    ->groupBy('customer_id')
    ->get();
```

### Eloquent Relationships

Extended relationship classes with Spanner optimizations:

```php
use KonstantinBudylov\EloquentSpanner\Eloquent\Relations\SpannerHasMany;
use KonstantinBudylov\EloquentSpanner\Eloquent\Relations\SpannerBelongsTo;

class User extends Model
{
    use ModelTrait;
    
    public function orders()
    {
        return $this->hasMany(Order::class);
    }
    
    public function profile()
    {
        return $this->hasOne(Profile::class);
    }
}

class Order extends Model
{
    use ModelTrait;
    
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
```

### Queue Integration

Configure queue to use Spanner:

```php
// config/queue.php
'connections' => [
    'spanner' => [
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
        'connection' => 'spanner',
    ],
],
```

### Batch Jobs

```php
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;

$batch = Bus::batch([
    new ProcessPodcast(Podcast::find(1)),
    new ProcessPodcast(Podcast::find(2)),
])->dispatch();

// Batch IDs are stored as binary UUIDs in Spanner
```

## Advanced Topics

### Custom Connection Class

The `Connection` class extends Colopl's base connection with:
- Emulator lock management
- Enhanced debugging via Debugbar
- Mutation limit tracking (5000 mutations per transaction)
- Session pool optimization

### Debugbar Integration

The package includes a custom Debugbar implementation that properly handles Spanner's BYTES() type:

```php
// Automatically enabled when Laravel Debugbar is installed
// View query details, timing, and bindings in the debug toolbar
```

### Helper Functions

```php
use KonstantinBudylov\EloquentSpanner\Helper;

// Check if connection is Spanner
if (Helper::isSpanner($model)) {
    // Spanner-specific logic
}

// Parameter limits for chunking
Helper::PARAMS_LIMIT; // 950 - Safe parameter limit for queries
```

### Model Trait Features

The `ModelTrait` provides:
- Automatic UUID generation based on key type
- Dynamic cast replacement for Spanner vs non-Spanner connections
- Automatic data owner ID population
- Integer/string type enforcement for Spanner compatibility

## Performance Considerations

### Session Pooling

Use Redis-based session pooling for production:

```php
'session_pool' => [
    'minSessions' => 10,
    'maxSessions' => 500,
],
'sessionPoolDriver' => 'redis',
```

### Query Batching

When loading relationships, the connector automatically chunks requests to stay within Spanner's parameter limits:

```php
// Automatically chunks into groups of 950 records
$users = User::with('orders')->get();
```

### Mutation Limits

Spanner has a limit of 20,000 mutations per transaction. This package tracks mutations and provides warnings:

```php
Connection::MUTATIONS_LIMIT; // 5000 - Recommended safe limit
```

## Testing with Spanner Emulator

1. Start the emulator:

```bash
gcloud emulators spanner start
```

2. Set environment variables:

```bash
export SPANNER_EMULATOR_HOST=localhost:9010
```

3. Configure your application:

```php
'client' => [
    'emulatorHost' => env('SPANNER_EMULATOR_HOST'),
    'hasEmulator' => true,
],
```

## Troubleshooting

### Common Issues

**Binary UUID serialization errors**
- Ensure models use `ModelTrait` and proper casts

**Query parameter limits exceeded**
- Use chunking or reduce WHERE IN clause sizes
- Utilize automatic chunking in eager loading

**Session pool exhaustion**
- Increase `maxSessions` in configuration
- Monitor session pool usage

**Emulator connection issues**
- Verify `SPANNER_EMULATOR_HOST` is set
- Check emulator is running on correct port

## Development

### Running Tests

```bash
composer test
```

### Code Style

```bash
composer cs-fix
```

## Contributing

Contributions are welcome! Please ensure:
- Code follows PSR-12 standards
- Tests are included for new features
- Documentation is updated

## License

This package is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Credits

Built on top of [colopl/laravel-spanner](https://github.com/colopl/laravel-spanner) with extensive enhancements for production use.

## Support

For issues, questions, or contributions, please use the GitHub issue tracker.

---

**Made with ❤️ for Laravel and Google Cloud Spanner**

