# Project Ockham

High-performance calculation engine for oil & gas project modeling with dual-execution architecture supporting both synchronous and asynchronous computation strategies.

## Overview

Project Ockham is an enterprise-grade platform designed for economic, engineering, and geological modeling of oil & gas projects. The system implements a monolithic calculation engine with a two-tier execution strategy optimized for different computational requirements:

- **Synchronous Mode**: Real-time calculations for interactive UI operations with Redis caching
- **Asynchronous Mode**: Monte Carlo simulations (1000+ iterations) with progress tracking and persistent storage

## Features

### Core Capabilities

- **Dual Execution Strategy**: Seamless switching between synchronous and asynchronous calculation modes
- **Deterministic Hashing**: Stable hash generation for calculation deduplication using SHA-256
- **Smart Case Binding**: Automatic linking of calculations with grace period management (7-day retention)
- **Real-time Progress Tracking**: WebSocket-based progress updates for long-running calculations
- **Automatic Cleanup**: Scheduled removal of orphaned calculations with configurable retention policies
- **Result Caching**: Redis-based caching layer for synchronous calculations (1-hour TTL)
- **Queue Management**: Laravel Horizon integration for job monitoring and metrics
- **Retry Mechanism**: Automatic retry logic with exponential backoff for failed calculations

### Calculation Pipeline

The system implements a sequential calculation pipeline:

1. **Engineering Analysis**: Reserve estimation, well count optimization, productivity indexing
2. **Production Forecasting**: Decline curve analysis with exponential/hyperbolic models
3. **Revenue Calculation**: Price modeling and sales revenue projections
4. **CAPEX/OPEX Modeling**: Capital and operational expenditure analysis
5. **Tax Computation**: Mining tax, income tax, and export duty calculations
6. **Financial Metrics**: NPV, IRR, PI, and payback period computation

## Tech Stack

### Backend
- PHP 8.3+
- Laravel 11.x
- MySQL 8.0+ / MariaDB 10.5+
- Redis 7.0+

### Queue & Job Processing
- Laravel Queue (Redis driver)
- Laravel Horizon (queue monitoring)
- Supervisor (process control)

### Broadcasting & Real-time
- Laravel Broadcasting
- WebSocket support (Pusher/Soketi compatible)

### Development Tools
- Composer 2.x
- PHPUnit for testing
- Laravel Pint for code styling

## Installation

### Using Docker (Recommended)

```bash
# Clone the repository
git clone <repository-url> project-ockham
cd project-ockham

# Build and start containers
docker-compose up -d

# Install dependencies
docker-compose exec app composer install

# Generate application key
docker-compose exec app php artisan key:generate

# Run migrations
docker-compose exec app php artisan migrate

# Start queue workers
docker-compose exec app php artisan horizon
```

### Manual Installation

#### Prerequisites
- PHP 8.3+ with extensions: mbstring, pdo_mysql, redis, bcmath
- MySQL 8.0+ or MariaDB 10.5+
- Redis 7.0+
- Composer 2.x

#### Steps

```bash
# Install dependencies
composer install --optimize-autoloader

# Environment configuration
cp .env.example .env
php artisan key:generate

# Database setup
php artisan migrate --force

# Start queue workers
php artisan queue:work redis --queue=calculations --tries=3 --timeout=3600
```

### Production Deployment

For production environments, configure Supervisor for queue workers:

```ini
[program:ockham-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/project-ockham/artisan queue:work redis --queue=calculations --sleep=3 --tries=3 --timeout=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/var/www/project-ockham/storage/logs/worker.log
```

## API Endpoints

### Calculation Execution

#### POST `/api/v1/cases/{caseId}/calculate`

Execute a calculation in either synchronous or asynchronous mode.

**Request Body (Synchronous Mode):**
```json
{
  "is_interactive": true,
  "engineer_params": {
    "initial_reserves": 1000000,
    "well_count": 10,
    "productivity_index": 1.5,
    "decline_rate": 0.15
  },
  "production_params": {
    "project_lifetime": 20
  },
  "sales_params": {
    "oil_price": 70
  },
  "capex_params": {
    "cost_per_well": 5000000,
    "facilities_cost": 10000000
  },
  "opex_params": {
    "fixed_opex": 1000000,
    "variable_opex_rate": 10
  },
  "tax_params": {
    "tax_rate": 0.20,
    "mining_tax_rate": 0.10
  }
}
```

**Request Body (Asynchronous Mode):**
```json
{
  "is_interactive": false,
  "iterations": 1000,
  "engineer_params": { ... },
  "production_params": { ... },
  "sales_params": { ... },
  "capex_params": { ... },
  "opex_params": { ... },
  "tax_params": { ... }
}
```

**Response (Synchronous):**
```json
{
  "success": true,
  "message": "Calculation completed",
  "data": {
    "hash_id": "a3f7b2c1d4e5f6g7h8i9j0k1l2m3n4o5",
    "status": "completed",
    "results": {
      "final_metrics": {
        "npv": 45000000,
        "irr": 0.18,
        "pi": 1.45,
        "payback_period": 7
      }
    },
    "execution_time": 0.523,
    "from_cache": true
  }
}
```

**Response (Asynchronous):**
```json
{
  "success": true,
  "message": "Calculation queued for processing",
  "data": {
    "calculation_id": 42,
    "hash_id": "b4c8d3e6f9g2h5i8j1k4l7m0n3o6p9q2",
    "status": "pending"
  }
}
```

### Status Monitoring

#### GET `/api/v1/calculations/{calculationId}/status`

Retrieve the current status of an asynchronous calculation.

**Response:**
```json
{
  "success": true,
  "data": {
    "calculation_id": 42,
    "case_id": 1,
    "hash_id": "b4c8d3e6f9g2h5i8j1k4l7m0n3o6p9q2",
    "status": "processing",
    "progress_percentage": 65,
    "progress_message": "Completed iterations: 650/1000",
    "iterations_completed": 650,
    "iterations_total": 1000,
    "started_at": "2025-12-27T10:30:00Z",
    "completed_at": null,
    "execution_time_seconds": null
  }
}
```

### Result Retrieval

#### GET `/api/v1/calculations/{calculationId}/results`

Fetch completed calculation results.

**Response:**
```json
{
  "success": true,
  "data": {
    "hash_id": "b4c8d3e6f9g2h5i8j1k4l7m0n3o6p9q2",
    "final_metrics": {
      "npv": 42500000,
      "irr": 0.165,
      "pi": 1.38,
      "payback_period": 8
    },
    "distributions": {
      "npv": {
        "mean": 42500000,
        "median": 41800000,
        "std_dev": 5200000,
        "min": 28000000,
        "max": 58000000,
        "p10": 35000000,
        "p50": 41800000,
        "p90": 50000000
      },
      "irr": { ... },
      "pi": { ... },
      "payback_period": { ... }
    }
  }
}
```

### Cache Management

#### DELETE `/api/v1/cases/{caseId}/cache`

Invalidate cached results for a specific case.

**Response:**
```json
{
  "success": true,
  "message": "Cache invalidated for case"
}
```

### Calculation Cancellation

#### POST `/api/v1/calculations/{calculationId}/cancel`

Cancel a running asynchronous calculation.

**Response:**
```json
{
  "success": true,
  "message": "Calculation cancelled"
}
```

## Configuration

### Environment Variables

Key configuration options in `.env`:

```bash
# Cache Configuration
CALC_SYNC_CACHE_TTL=3600          # Synchronous mode cache TTL (seconds)
CALC_SYNC_TIMEOUT=30               # Synchronous execution timeout

# Queue Configuration
CALC_QUEUE_NAME=calculations       # Queue name for async jobs
CALC_ASYNC_TIMEOUT=3600            # Async job timeout (seconds)
CALC_RETRY_ATTEMPTS=3              # Retry attempts on failure
CALC_RETRY_BACKOFF=60              # Retry backoff delay (seconds)
CALC_MAX_ITERATIONS=10000          # Maximum Monte Carlo iterations
CALC_DEFAULT_ITERATIONS=1000       # Default iteration count

# Smart Binding
CALC_GRACE_PERIOD_DAYS=7           # Grace period before detachment
CALC_DELETE_AFTER_DAYS=30          # Deletion delay after detachment

# Broadcasting
CALC_BROADCASTING_ENABLED=true     # Enable WebSocket notifications
CALC_PROGRESS_INTERVAL=5           # Progress update frequency (%)
```

### Application Configuration

Edit `config/ockham.php` for advanced configuration:

```php
return [
    'calculations' => [
        'sync' => [
            'cache_ttl' => env('CALC_SYNC_CACHE_TTL', 3600),
            'timeout' => env('CALC_SYNC_TIMEOUT', 30),
        ],
        'async' => [
            'queue_name' => env('CALC_QUEUE_NAME', 'calculations'),
            'timeout' => env('CALC_ASYNC_TIMEOUT', 3600),
            'max_iterations' => env('CALC_MAX_ITERATIONS', 10000),
        ],
        'binding' => [
            'grace_period_days' => env('CALC_GRACE_PERIOD_DAYS', 7),
            'delete_after_days' => env('CALC_DELETE_AFTER_DAYS', 30),
        ],
    ],
];
```

## Maintenance

### Scheduled Tasks

The system includes automated cleanup tasks. Configure Laravel Scheduler in `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    $schedule->command('calculations:cleanup')
        ->dailyAt('03:00')
        ->withoutOverlapping()
        ->onOneServer();
}
```

Add to crontab:
```bash
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```

### Manual Cleanup

```bash
php artisan calculations:cleanup
```

### Queue Monitoring

Access Laravel Horizon dashboard:
```bash
php artisan horizon
```

Navigate to: `http://localhost/horizon`

## Testing

```bash
# Run test suite
php artisan test

# Run specific test class
php artisan test --filter=CalculationTest

# Run with coverage
php artisan test --coverage
```

## Performance Benchmarks

### Synchronous Mode
- Cache hit: < 50ms
- Cache miss: 200-500ms
- Throughput: 100+ requests/second

### Asynchronous Mode
- Iterations/second: 10-20
- 1000 iterations: 50-100 seconds
- Memory per job: 128-256 MB

## Architecture Highlights

### Design Patterns
- **Strategy Pattern**: Pluggable execution strategies (Sync/Async)
- **DTO Pattern**: Type-safe data transfer objects
- **Repository Pattern**: Eloquent models with scoped queries
- **Observer Pattern**: Event-driven progress tracking

### Data Integrity
- Deterministic hash generation via data canonicalization
- Float normalization (10-digit precision)
- Recursive JSON key sorting
- SHA-256 hashing algorithm

### Scalability
- Horizontal scaling via multiple queue workers
- Redis clustering support
- MySQL read replica compatibility
- Stateless API design
