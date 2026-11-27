# Electrical Panel Builder API

Backend of the electrical panel builder. **Symfony 7.4 LTS** + **API Platform 5**, **Doctrine ORM 3**, **PostgreSQL 18**,
**PHP 8.4**. Runs in **MicroK8s** via Helm; the code is mounted into the pod from the host (`hostPath`), so edits are live.

* API root: <https://api.electrical.local/api/v1>
* Docs (Swagger / OpenAPI): <https://api.electrical.local/api/v1/docs>
* No authentication yet. CORS (nelmio) is enabled for `^/api/`, allowed origins come from `CORS_ALLOW_ORIGIN` (regex; set from `corsAllowOrigin` in `values.yaml`, currently only the frontend origin `https://app.electrical.local`).

## Local TLS

The ingress serves HTTPS with a mkcert wildcard certificate for `*.electrical.local`, stored as the Kubernetes
TLS Secret `electrical-local-tls` (name from `tlsSecretName` in `values.yaml`; the chart omits `tls:` when it is empty).
The mkcert root CA is installed once per machine (`mkcert -install`). Certificate files are created outside the
repository and must never be committed.
```shell
mkcert -cert-file ~/certs/electrical-local.pem -key-file ~/certs/electrical-local-key.pem "*.electrical.local" electrical.local
kubectl create secret tls electrical-local-tls --cert ~/certs/electrical-local.pem --key ~/certs/electrical-local-key.pem
```
To rotate the certificate, delete the secret (`kubectl delete secret electrical-local-tls`) and create it again.

## First-time setup

### 1. Build the image and push it to the MicroK8s registry
```shell
# with Xdebug (default, `xdebug: true` in values.yaml):
docker build -f kube/Dockerfile --target dev --build-arg INSTALL_XDEBUG=1 -t localhost:32000/electrical-api:latest .
# without Xdebug (set `xdebug: false` in values.yaml):
docker build -f kube/Dockerfile --target dev -t localhost:32000/electrical-api:latest .
docker push localhost:32000/electrical-api:latest
```
Build context is the `backend/` root. Push to `localhost:32000` (it is in Docker's `insecure-registries`;
`127.0.0.1:32000` is rejected as HTTPS-only on this machine).
The `prod` and `nginx` Dockerfile targets (code and vendor baked in) are not used locally.

### 2. Prepare values
```shell
cp values.yaml.dist values.yaml
# adjust hostPath / postgres.hostPath if the repo lives elsewhere
```
Dev-only DB credentials live in `values.yaml`; the chart passes them to the php container as `DATABASE_URL`
(`electrical-api-db:5432`, `serverVersion=18`) and the pod environment overrides `.env`.

### 3. Install the chart
```shell
helm install electrical-api ./kube/chart -f values.yaml
echo "127.0.0.1 api.electrical.local" | sudo tee -a /etc/hosts > /dev/null
```

### 4. Install dependencies and migrate (inside the pod)
```shell
kubectl exec -it deployment/electrical-api -c php -- php -d xdebug.mode=off /usr/bin/composer install
kubectl exec -it deployment/electrical-api -c php -- php -d xdebug.mode=off bin/console doctrine:migrations:migrate -n
```
While there are no migrations yet, `migrations:migrate` reports "no registered migrations" and exits 1 - that is expected.

## Daily commands (inside the pod)

| Task | Command |
|---|---|
| Shell | `kubectl exec -it deployment/electrical-api -c php -- bash` |
| Tests | `kubectl exec -it deployment/electrical-api -c php -- php -d xdebug.mode=off bin/phpunit` |
| PHPStan | `kubectl exec -it deployment/electrical-api -c php -- php -d xdebug.mode=off bin/phpstan analyse` |
| CS check | `kubectl exec -it deployment/electrical-api -c php -- php -d xdebug.mode=off bin/php-cs-fixer check --diff` |
| CS fix | `kubectl exec -it deployment/electrical-api -c php -- php -d xdebug.mode=off bin/php-cs-fixer fix` |
| Check (CS + PHPStan) | `kubectl exec -it deployment/electrical-api -c php -- env XDEBUG_MODE=off make check` |
| Migrations | `kubectl exec -it deployment/electrical-api -c php -- php -d xdebug.mode=off bin/console doctrine:migrations:migrate -n` |
| Make migration | `kubectl exec -it deployment/electrical-api -c php -- php -d xdebug.mode=off bin/console make:migration` |
| Clear cache | `kubectl exec -it deployment/electrical-api -c php -- php -d xdebug.mode=off bin/console c:c` |

Inside the pod `sf` is an alias for `bin/console`, and `make check`, `make fix`, `make test` are available.
`-d xdebug.mode=off` (or `XDEBUG_MODE=off`) silences Xdebug's "could not connect to debugging client" notice.

## Ports

| What | Where |
|---|---|
| API (via Traefik ingress, TLS) | `https://api.electrical.local` (port 443) |
| PostgreSQL (NodePort) | `127.0.0.1:32004` - `postgresql://electrical_user:electrical_password@127.0.0.1:32004/electrical_db` |
| Xdebug | client port `9013`, IDE key `PHPSTORM`, `PHP_IDE_CONFIG=serverName=electrical-api`, `xdebugClientHost` in values (default `172.17.0.1`) |

PostgreSQL data lives in `data/postgres/` (gitignored). The PG18 image keeps data under `/var/lib/postgresql/18/docker`,
so the volume is mounted at `/var/lib/postgresql`.

## Chart lifecycle
```shell
helm upgrade electrical-api ./kube/chart -f values.yaml   # apply chart changes
helm uninstall electrical-api                              # remove
```

## Code style and QA
`php-cs-fixer` with `@PER-CS` + `@PER-CS:risky` (`.php-cs-fixer.dist.php`), PHPStan level `max` over `src` and `tests`
(`phpstan.dist.neon`), PHPUnit 12 with `dama/doctrine-test-bundle` and `zenstruck/foundry`
(`phpunit.dist.xml`). Run `make check`, `make fix`, `make test` inside the pod.

## Known pinning
None. `doctrine/orm` 3.8 with `doctrine/dbal` 4.6 and `doctrine-bundle` 3.4 works on Symfony 7.4: `schema:update --dump-sql`
and `migrations:diff` were checked against a throw-away entity. (The CV project pins `doctrine/orm` 3.6.7 for a DBAL 4.4 /
doctrine-bridge 8.0 problem; it does not apply here.)
