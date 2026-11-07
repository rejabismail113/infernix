# Infernix Workspace

Internal Integrated Business & Operations Management System for **Infernix Evolv Hub** — finance & accounting,
projects & training, commercial documents, HR/part-time staff, assets, activities, CSR, certifications,
procurement, reporting, administration and credential inventory (13 modules, SRS `docs/SRS.md`).

## Quick start

```bash
cp .env.example .env
docker compose up -d
make setup
```

App: <http://localhost:8010> (host port from `APP_PORT` in `.env`; change it if 8010 is taken) ·
Mailpit: <http://localhost:8025> · Object store console (RustFS): <http://localhost:9001> · Horizon: <http://localhost:8010/horizon>

Seeded Owner login: `owner@infernix.local` / `ChangeMe-Infernix-2026!` (from `SEED_OWNER_*` in `.env`;
change before any shared deployment).

**After every `git pull`** run `make update` — it installs new PHP / JS packages (the `vite` container otherwise
keeps its old `node_modules`, e.g. "Failed to resolve import chart.js"), runs new migrations, adds new permissions
and restarts the workers.

**Opening the app from another device (LAN IP)**: set `APP_URL=http://<your-ip>:8010` in `.env`, then
`docker compose restart vite app`. The dev asset server follows `APP_URL`; with `localhost` there, other devices get a
blank page because the browser looks for scripts on its own machine. Port 5173 must be reachable too.

## Quality gates

`make lint` (Pint, Larastan L6, ESLint, vue-tsc) and `make test` (Pest on PostgreSQL, Vitest) must be green
before every commit. See `CLAUDE.md` for the engineering rules and `docs/` for architecture, data model,
permission matrix, traceability and decisions.
