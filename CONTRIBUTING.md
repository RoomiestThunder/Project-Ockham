# Project Ockham - Contributing Guide

## Code Standards

This project follows PSR-12 coding standards and Laravel best practices.

### Code Style

We use Laravel Pint for automatic code formatting:

```bash
# Check code style
./vendor/bin/pint --test

# Fix code style issues
./vendor/bin/pint
```

### Testing

All new features must include tests:

```bash
# Run all tests
php artisan test

# Run specific test suite
php artisan test --testsuite=Feature

# Run with coverage
php artisan test --coverage
```

### Git Workflow

1. Create a feature branch from `develop`
2. Make your changes
3. Run tests and code style checks
4. Submit a pull request

### Commit Messages

Follow conventional commits format:

```
feat: add new calculation strategy
fix: resolve hash generation issue
refactor: optimize calculator service
test: add tests for smart binding
docs: update API documentation
```

## Architecture Guidelines

### Service Layer

- Keep services focused and single-responsibility
- Use dependency injection for all dependencies
- Add type hints to all method parameters and return types
- Document complex business logic with comments

### DTOs

- Use readonly properties
- Validate data in constructors
- Provide conversion methods (toArray, fromArray)

### Jobs

- Implement proper error handling
- Add retry logic with exponential backoff
- Log important state changes
- Support graceful cancellation

### Events

- Keep events lightweight
- Use events for cross-cutting concerns
- Implement ShouldBroadcast for real-time updates

## Database

### Migrations

- Always include rollback logic
- Add proper indexes
- Use foreign key constraints
- Document complex schema changes

### Models

- Use Eloquent scopes for common queries
- Define relationships explicitly
- Cast attributes appropriately
- Use accessors/mutators when needed

## API Design

### Endpoints

- Follow RESTful conventions
- Use proper HTTP methods and status codes
- Version your API endpoints
- Document all endpoints

### Validation

- Use Form Requests for validation
- Return consistent error responses
- Validate all user input
- Sanitize data before processing

## Performance

### Optimization Checklist

- [ ] Use eager loading to prevent N+1 queries
- [ ] Cache expensive operations
- [ ] Index database columns used in WHERE clauses
- [ ] Optimize Redis key patterns
- [ ] Profile slow endpoints
- [ ] Use queue workers for heavy tasks

## Security

### Security Checklist

- [ ] Validate and sanitize all input
- [ ] Use prepared statements (Eloquent)
- [ ] Implement rate limiting
- [ ] Add authentication/authorization
- [ ] Encrypt sensitive data
- [ ] Keep dependencies updated

## Documentation

- Update README for new features
- Document configuration options
- Add inline code comments for complex logic
- Keep API documentation in sync
