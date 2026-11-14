# Brazil Locations Service API

A small, fast, open API for Brazilian locations: **27 states** and **5,570 municipalities**
with official IBGE codes. Read-only, no authentication, no API key.

Built with **Slim 4** and **MariaDB**. Runs with a single `docker compose up`.

[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.3-blue.svg)](https://www.php.net/)
[![Docker](https://img.shields.io/badge/docker-compose-blue.svg)](docker-compose.yml)

---

## Why

Every Brazilian project needs the same lookup: state → city, or city by IBGE code.
People end up copying a CSV into every database, or scraping a government site.

This service keeps the dataset in one place, exposes a stable contract, and stays free.

- **Canonical key:** `ibge_code` (7 digits) — never the internal `id`
- **Accent and case insensitive search**
- **Cache friendly:** the dataset changes once a year
- **Agent friendly:** `/llms.txt` and `/openapi.json` for LLM tooling

---

## Quickstart

```bash
git clone https://github.com/lucasrpasquetto/brazil-locations-service-api.git
cd brazil-locations-service-api
docker compose up -d --build
```

First boot loads the schema and seeds 5,570 municipalities. Takes a few seconds.

```bash
curl http://127.0.0.1:8130/health
curl http://127.0.0.1:8130/v1/locations/states
curl "http://127.0.0.1:8130/v1/locations/states/MT/cities?limit=5"
curl "http://127.0.0.1:8130/v1/locations/cities?search=floresta&uf=RO"
curl http://127.0.0.1:8130/v1/locations/cities/1100015
```

Interactive docs: <http://127.0.0.1:8130/docs>

---

## Endpoints

| Method | Path | Description |
|---|---|---|
| GET | `/health` | Service and database status |
| GET | `/v1/locations/countries` | Countries (currently Brazil) |
| GET | `/v1/locations/regions` | 5 Brazilian regions |
| GET | `/v1/locations/states` | 27 states with region |
| GET | `/v1/locations/states/{uf}/cities` | Municipalities of a state |
| GET | `/v1/locations/cities?search=` | Search by name (accents ignored) |
| GET | `/v1/locations/cities/{code}` | Municipality by IBGE code |
| GET | `/docs` | Human documentation |
| GET | `/openapi.json` | OpenAPI 3.0 spec |
| GET | `/llms.txt` | Compact description for AI agents |

### Examples

```json
// GET /v1/locations/states/MT/cities?limit=2
{
  "uf": "MT",
  "count": 141,
  "data": [
    { "id": 5183, "ibge_code": "5100102", "name": "Acorizal", "uf": "MT" },
    { "id": 5184, "ibge_code": "5100201", "name": "Água Boa", "uf": "MT" }
  ]
}
```

```json
// GET /v1/locations/cities/1100015
{
  "id": 1,
  "ibge_code": "1100015",
  "name": "Alta Floresta D'Oeste",
  "uf": "RO",
  "state_name": "Rondônia",
  "region": "Região Norte"
}
```

### Errors

Always JSON, always with an `error` field:

```json
{ "error": "uf_not_found", "message": "UF inexistente ou sem municipios", "uf": "ZZ" }
```

| Status | When |
|---|---|
| 404 | Unknown UF or IBGE code |
| 422 | `search` shorter than 2 characters |
| 503 | Database unavailable (`/health`) |

---

## Running without Docker

Requires PHP 8.3+, Composer and a MariaDB/MySQL database.

```bash
composer install
cp .env.example .env          # adjust DB_* credentials
mysql -u root -p < db/schema.sql
mysql -u root -p locations < db/seed.sql
export DB_HOST=127.0.0.1 DB_PORT=3306 DB_NAME=locations DB_USER=locations DB_PASS=...
composer serve                # http://127.0.0.1:8080
```

---

## Deploying

The app is a plain PHP front controller. Any of these work:

- **Apache/cPanel**: point the document root to `public/`, enable `mod_rewrite`
- **Nginx**: `fastcgi_pass` with `try_files $uri /index.php`
- **Docker**: `docker compose up -d` behind a reverse proxy with TLS

The dataset is read-only, so caching aggressively is safe:
`Cache-Control: public, max-age=86400, stale-while-revalidate=604800`.

---

## Data

Source: IBGE municipality codes. The dataset is static and refreshes once a year.

```
data/cities.csv   5,570 rows  id, state_id, code, name, slug
data/states.csv      27 rows  id, name, slug, country_id, code, region_id
db/seed.sql        generated from the CSVs
```

To refresh: replace the CSVs and regenerate `db/seed.sql`. The `name_search` column stores a
lowercase, unaccented version of the name — that is what makes search work without `unaccent()`.

---

## Project layout

```
public/index.php              Slim front controller and routes
src/Database.php              PDO factory from env
src/Controllers/              Endpoint handlers
db/schema.sql                 Tables and indexes
db/seed.sql                   Generated seed (5,570 cities)
docs/                         Human docs, OpenAPI spec, llms.txt
data/                         Source CSVs
```

---

## Contributing

Issues and pull requests are welcome.

1. Fork and create a branch
2. Keep the contract stable — `ibge_code` is the public key
3. Run the smoke test before opening a PR:
   ```bash
   ./scripts/smoke.sh http://127.0.0.1:8130
   ```

---

## Português

API pública e gratuita de localidades brasileiras: 27 UFs e 5.570 municípios com código IBGE.
Leitura apenas, sem autenticação. Feita em Slim 4 com MariaDB.

```bash
docker compose up -d --build
curl http://127.0.0.1:8130/v1/locations/states
```

Documentação em `/docs`, especificação em `/openapi.json` e versão para agentes de IA em `/llms.txt`.

---

## License

MIT — see [LICENSE](LICENSE).
