# AGENTS.md

## Scope
- Applies to the entire repository.
- Follow these instructions for any automated or assisted code changes.

## Architecture
- Keep a hexagonal/domain-driven structure.
- Put business logic in `src/Domain`.
- Put external integrations and IO in `src/Infrastructure`.
- Keep Symfony console wiring in `src/Command`.
- Depend on ports/interfaces from domain to infrastructure, not the other way around.

## Coding Conventions
- Use `declare(strict_types=1);` in all PHP files except interfaces
- Keep the base namespace `SportClimbing\EventDetails`.
- Add this phpdoc block immediately above each `namespace` declaration:

```php
/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
```

- Keep methods small and focused.
- Prefer explicit value objects/entities over loose arrays in domain code.

## Testing and Validation
- Add or update tests for behavioral changes.
- Run `./vendor/bin/phpunit` after modifications.
- Keep existing output formats stable unless intentionally changed.

## Security and Secrets
- Never commit real API keys or tokens.
- Use environment variables for credentials.
