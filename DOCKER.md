# Docker deployment

Self-contained Docker Compose stack for the licensing server: nginx + php-fpm
(Laravel) + queue worker + PostgreSQL + Redis.

## Quick start

```bash
cp .env.docker.example .env
# set APP_KEY and DB_PASSWORD in .env (see the comments in that file)

docker compose build
docker compose up -d

# first boot: run migrations + seed roles/permissions
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --class=RolesAndPermissionsSeeder --force
docker compose exec app php artisan config:cache
```

The app is published on **`127.0.0.1:8088`** (localhost only). Health check:

```bash
curl http://127.0.0.1:8088/api/v1/health
```

## How it fits together

| Service    | Image                  | Notes                                         |
|------------|------------------------|-----------------------------------------------|
| `web`      | nginx (built)          | serves `public/`, proxies PHP to `app:9000`; only published port (`127.0.0.1:8088`) |
| `app`      | php8.4-fpm (built)     | Laravel API + admin portal                    |
| `worker`   | same image as `app`    | `php artisan queue:work`                       |
| `postgres` | postgres:16-alpine     | DB `thirdline_license`, volume `pgdata`        |
| `redis`    | redis:7-alpine         | cache, sessions, queue                         |

- The **RSA-4096 license-signing keypair** is generated on first boot by
  `docker/entrypoint.sh` into the `license-storage` volume at
  `storage/keys/license_{private,public}.pem`. The **private key never leaves
  the server**; distribute only `license_public.pem` to client apps so they can
  verify license JWTs (RS256, issuer `thirdline-grc-licensing`).
- `.env` is gitignored. `POSTGRES_PASSWORD` is interpolated from `DB_PASSWORD`
  in `.env` — no secrets live in `docker-compose.yml`.

## Exposing publicly

The stack binds to localhost. To serve a `license.<domain>` subdomain, put an
nginx reverse proxy + TLS (certbot) in front of `127.0.0.1:8088`.

## Notes

- Images build with `--no-dev`, so Faker (a dev dependency) is absent and the
  full `DatabaseSeeder` (which uses `User::factory()`) will fail. Seed
  `RolesAndPermissionsSeeder` as shown above and create org/admin/api-client
  records via the admin API or `tinker`.
