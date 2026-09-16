# BugSense

Intelligent Bug Tracking and Analysis System.

## Stack

- Nuxt 4
- Vue 3
- TypeScript
- Laravel 13
- PostgreSQL 18
- Docker Compose

## Local Development

Create the local environment files once (they are ignored by Git):

    Copy-Item .env.example .env
    Copy-Item backend/.env.example backend/.env
    Copy-Item frontend/.env.example frontend/.env

Start the project in the background:

    docker compose up --build -d

Generate the Laravel application key once after the first start:

    docker compose exec backend php artisan key:generate

View the service status and logs:

    docker compose ps
    docker compose logs -f

Stop the project while preserving the PostgreSQL development volume:

    docker compose down

Reinstall frontend dependencies after changing `frontend/package-lock.json`:

    docker compose exec frontend npm ci

## Local URLs

Frontend: http://localhost:3000

Backend: http://localhost:8000

## Backend Tests

Create the isolated Postgres test database once (separate from the `bugsense` development database so `RefreshDatabase` never touches dev data):

    docker compose exec postgres psql -U bugsense -d bugsense -c "CREATE DATABASE bugsense_testing OWNER bugsense;"

Run the Pest suite with the `test` Composer script, the single authoritative test command (it exports the required testing-environment overrides itself; see the comment in `backend/phpunit.xml` for why plain `php artisan test` is not sufficient under this Docker Compose setup):

    docker compose exec backend composer test
