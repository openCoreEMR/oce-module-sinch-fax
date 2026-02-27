# Docker Development Environment

## Architecture

The `compose.yml` in this repository extends OpenEMR's development-easy Docker setup:

```
compose.yml (this repo)
    └── extends: vendor/openemr/openemr/docker/development-easy/docker-compose.yml
```

**What compose.yml does:**
- Extends all services from OpenEMR's docker-compose.yml using `extends:`
- Overrides ports with `!override` to use random ports (avoids conflicts)
- Mounts this module into OpenEMR's custom_modules directory
- Adds a healthcheck for the openemr service (waits for HTTP 200)
- Includes PHPUnit services with `[test]` profile

**Why this approach:**
- OpenEMR is installed as a Composer dependency in `vendor/openemr/openemr/`
- We reuse OpenEMR's official Docker setup without duplicating configuration
- Changes to OpenEMR's Docker setup are automatically inherited
- Project name is derived from directory (`oce-module-sinch-fax`)

**First startup after purge:**
The first `docker compose up -d --wait` after a fresh install takes 2-3 minutes while OpenEMR initializes. The healthcheck has a 3-minute start period to accommodate this.

## Quick Start Commands

```bash
# Start the environment
docker compose up -d --wait

# View logs in real-time
docker compose logs -f openemr

# Check container status
docker compose ps

# Get the assigned port for OpenEMR
docker compose port openemr 80

# Stop environment (keeps data)
docker compose down

# Stop and remove all data (fresh start)
docker compose down -v
```

## Running Commands Inside Containers

**Use `docker compose exec` for running commands in already-running containers:**
- Fast execution (no container startup)
- No entrypoint conflicts
- Commands run in existing container environment

**Execute commands in OpenEMR container:**
```bash
# Access bash shell
docker compose exec openemr bash

# Run PHP commands
docker compose exec openemr php -v
docker compose exec openemr php -l /path/to/file.php

# Run command directly without shell
docker compose exec openemr sh -c "cd /var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oce-module-sinch-fax && ls -la"
```

**Access MariaDB database:**
```bash
# MariaDB CLI
docker compose exec mysql mariadb -uroot -proot openemr

# Execute SQL queries
docker compose exec mysql mariadb -uroot -proot -e "SHOW TABLES LIKE 'oce_sinch%'" openemr

# Dump database
docker compose exec mysql mariadb-dump -uroot -proot openemr > backup.sql

# Import database (use -T to disable pseudo-TTY)
docker compose exec -T mysql mariadb -uroot -proot openemr < backup.sql
```

## Development Workflow

**Key Information:**
- Module is mounted at: `/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oce-module-sinch-fax`
- OpenEMR root: `/var/www/localhost/htdocs/openemr`
- All local file changes are immediately reflected (bind mount)
- No rebuild needed for code changes
- OPCACHE is disabled for instant PHP updates

**Testing Changes:**
1. Edit files locally in your editor
2. Refresh browser - changes appear immediately
3. No need to restart containers

**Viewing Logs:**
```bash
# All OpenEMR logs
docker compose logs -f openemr

# Filter for errors only
docker compose logs -f openemr | grep -i error

# View Apache error log
docker compose exec openemr tail -f /var/log/apache2/error.log

# View MySQL logs
docker compose logs -f mysql
```

## Troubleshooting Docker Issues

1. **Check container status:**
   ```bash
   docker compose ps
   ```

2. **View recent logs:**
   ```bash
   docker compose logs --tail=100 openemr
   ```

3. **Check if OpenEMR installed successfully:**
   ```bash
   docker compose exec openemr ls -la /var/www/localhost/htdocs/openemr/sites/default/sqlconf.php
   ```
   - If this file exists, installation is complete
   - If missing, installer may still be running

4. **Verify database connection:**
   ```bash
   docker compose exec mysql mariadb -uroot -proot -e "SHOW DATABASES"
   ```

5. **Check module files are mounted:**
   ```bash
   docker compose exec openemr ls -la /var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oce-module-sinch-fax
   ```

**Common Issues:**
- **Container won't start:** Check logs with `docker compose logs openemr`
- **Port conflicts:** Use `docker compose port openemr 80` to find assigned port
- **Database errors:** Verify MySQL is healthy with `docker compose ps mysql`
- **Changes not showing:** Restart Apache with `docker compose restart openemr`
- **Fresh start needed:** `docker compose down -v && docker compose up -d --wait`

## Running Tests in Docker

```bash
# Access container shell
docker compose exec openemr bash

# Navigate to module directory
cd /var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oce-module-sinch-fax

# Run all pre-commit checks (includes syntax, PHPCS, PHPStan, Rector, etc.)
pre-commit run -a

# Run individual composer scripts
composer phpcs    # Code style check
composer phpstan  # Static analysis
composer check    # Run all checks
```

## Database Operations

**View module tables:**
```bash
docker compose exec mysql mariadb -uroot -proot -e "SHOW TABLES LIKE 'oce_sinch%'" openemr
```

**Query data:**
```bash
docker compose exec mysql mariadb -uroot -proot -e "SELECT * FROM oce_sinch_faxes LIMIT 10" openemr
```

**Run SQL from file:**
```bash
# From local file (use -T to disable pseudo-TTY)
docker compose exec -T mysql mariadb -uroot -proot openemr < table.sql
```

**Export/Import:**
```bash
# Export module tables only
docker compose exec mysql mariadb-dump -uroot -proot openemr oce_sinch_faxes oce_sinch_cover_pages oce_sinch_services > module_backup.sql

# Import
docker compose exec -T mysql mariadb -uroot -proot openemr < module_backup.sql
```

## Environment Details

**Services (from OpenEMR's docker-compose):**
- `openemr` - OpenEMR application server (Alpine Linux, PHP 8.2, Apache)
- `mysql` - MariaDB 11.4 database
- `phpmyadmin` - Web-based MySQL admin interface
- `couchdb` - CouchDB for document storage
- `openldap` - LDAP server for authentication testing

**Services (from this repo's compose.yml):**
- `phpunit` - Test runner (profile: test)
- `phpunit-coverage` - Test runner with coverage (profile: test)

**Volumes:**
- `databasevolume` - Persistent MySQL data
- `logvolume` - Apache/PHP logs
- Bind mounts for code (live updates)

**Credentials:**
- OpenEMR: admin / pass
- MySQL: root / root
- MySQL app user: openemr / openemr

**Ports:**
- OpenEMR: Random port (use `docker compose port openemr 80`)
- MySQL: Random port (use `docker compose port mysql 3306`)
- phpMyAdmin: Random port (use `docker compose port phpmyadmin 80`)

## Local Configuration Overrides

For local testing with custom configuration (e.g., testing environment-based config mode), use these gitignored files:

**`.env.testing`** - Environment variables loaded into the OpenEMR container:
```bash
# Enable environment-based configuration
OCE_SINCH_FAX_ENV_CONFIG=1
OCE_SINCH_FAX_ENABLED=1
OCE_SINCH_FAX_PROJECT_ID=your-project-id
OCE_SINCH_FAX_API_KEY=your-api-key
OCE_SINCH_FAX_API_SECRET=your-secret
# ... other OCE_SINCH_FAX_* variables
```

**`compose.override.yml`** - Docker Compose overrides (automatically loaded):
```yaml
services:
  openemr:
    env_file:
      - .env.testing
```

**Usage:**
```bash
# Create your local config files (gitignored)
cp .env.testing.example .env.testing  # if example exists, or create from scratch
# Edit .env.testing with your values

# Start containers - compose.override.yml is auto-loaded
docker compose up -d --wait

# The module now uses environment variables instead of database config
```

This pattern is useful for:
- Testing environment-based configuration mode (`OCE_SINCH_FAX_ENV_CONFIG=1`)
- Using real Sinch API credentials without committing them
- Simulating production deployment configurations locally

## YAML File-Based Configuration

For Kubernetes-style deployments, the module supports YAML config files mounted via ConfigMap and Secret volumes. This is the preferred approach for K8s because it maps directly to volume mounts.

**Config files:**
- `/etc/oce/sinch-fax/config.yaml` — non-sensitive settings (from ConfigMap)
- `/etc/oce/sinch-fax/secrets.yaml` — sensitive settings (from Secret)

**Override paths via env vars:**
- `OCE_SINCH_FAX_CONFIG_FILE` — custom path to config file
- `OCE_SINCH_FAX_SECRETS_FILE` — custom path to secrets file

**Example config.yaml:**
```yaml
enabled: true
project_id: "abc123"
region: global
default_retry_count: 3
auth_method: basic
api_key: "your-api-key"
```

**Example secrets.yaml:**
```yaml
api_secret: "your-api-secret"
webhook_password: "$2y$10$..."
```

**Precedence:** env vars > YAML files > database globals

The module auto-detects file presence — no activation flag needed. When config files are present, the admin UI shows "Configuration Managed Externally" instead of editable fields.

**Imports:** Config files support Symfony-style imports for splitting across files:
```yaml
imports:
  - { resource: secrets.yaml }
enabled: true
```

**Testing locally with Docker:**
```bash
# Create config files
mkdir -p tmp/oce-config
cat > tmp/oce-config/config.yaml <<'EOF'
enabled: true
project_id: "test-project"
auth_method: basic
api_key: "test-key"
EOF

cat > tmp/oce-config/secrets.yaml <<'EOF'
api_secret: "test-secret"
EOF

# Mount into container via compose.override.yml:
# services:
#   openemr:
#     volumes:
#       - ./tmp/oce-config:/etc/oce/sinch-fax:ro
```

## Best Practices

1. Use `docker compose` (not `docker-compose`) - newer syntax
2. Use `docker compose exec` for running commands in containers
3. Use `mariadb` command (not `mysql`) for database shell access
4. Use `-T` flag with exec for piped input (e.g., database imports)
5. Use pre-commit or composer scripts for code quality checks (never manual syntax checks)
6. Use git commands (`git ls-files`, `git grep`) instead of `find` for file operations
7. Check logs first when debugging issues
8. Verify container health with `docker compose ps`
9. Remember that local file changes are instant
10. Use `.env.testing` and `compose.override.yml` for local config overrides (both gitignored)
