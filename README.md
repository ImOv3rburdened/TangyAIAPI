# Tangy AI PHP API

Public PHP API edge for the private TangyAIHub stack.

## Architecture

Internet -> Cloudflare/Coolify/Traefik -> **this PHP API** -> private TangyAIHub gateway -> Hub -> Redis/PostgreSQL/workers.

The PHP API never connects to PostgreSQL directly and does not contain database credentials. Authentication is delegated to the private Hub gateway so there is one source of truth for sessions and the 1-hour heartbeat TTL.

## Public routes

- `GET /health` — PHP edge liveness only.
- `GET /v1/status/public` — safe public status proxied from the Hub.
- `POST /v1/auth/login` — login.
- `POST /v1/auth/logout` — authenticated logout.
- `POST /v1/heartbeat` — authenticated heartbeat.
- `ANY /v1/hub/{path}` — authenticated passthrough to the internal Hub gateway.

All `/v1/hub/*` requests require an `Authorization: Bearer ...` header at the edge and are verified again by the private Hub gateway.

## Environment

Copy `.env.example` values into Coolify. Production secrets remain in the Hub/Coolify, not this repository.

Important:

```env
HUB_BASE_URL=http://gateway:8000
HUB_REQUEST_TIMEOUT_SECONDS=30
MAX_REQUEST_BYTES=1048576
APP_ENV=production
LOG_LEVEL=INFO
```

`HUB_BASE_URL` must resolve over the shared internal Coolify Docker network. The PHP API resource must have **Connect to Predefined Networks** enabled.

## Coolify

Deploy this repository as Docker Compose.

Do **not** add a host `ports:` mapping. The Compose file exposes only container port 80 and uses Traefik labels for:

`https://api.tangycatteai.com`

The server still only needs normal public HTTP/HTTPS ports.

## First checks

From inside the PHP API container:

```sh
curl http://127.0.0.1/health
curl http://gateway:8000/health
```

From the Internet:

```sh
curl https://api.tangycatteai.com/health
curl https://api.tangycatteai.com/v1/status/public
```

## Notes

- If TangyAIHub is down, this API stays alive and returns a controlled `503 hub_unavailable`.
- The Hub remains private and has no public Traefik route.
- No Redis/PostgreSQL credentials are needed here.
- Keep the Cloudflare/Coolify edge protections in front of this API.
