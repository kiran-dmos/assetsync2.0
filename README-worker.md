# AssetSync2.0 Worker — Docker Setup

This guide describes the worker service tested with AssetSync2.0 on GLPI 11.0.11.
The worker service runs the plugin's CLI command continuously in a separate container.

## What this configuration expects

- An existing Docker Compose project containing services named `db` and `glpi`.
- That Compose project defines a named volume called `glpi_data`, mounted by GLPI at `/var/glpi`.
- The GLPI service uses image `glpi/glpi:11.0.11`.
- The AssetSync2.0 plugin code is installed in the shared GLPI data volume and is enabled.
- The database credentials are supplied locally as environment variables. Do not commit passwords or tokens.

`docker/worker.compose.yml` is a Compose override containing only the worker service. It is intended to be used together with the existing Compose file, not by itself.

## 1. Get the plugin code

From the plugin repository:

```powershell
git switch master
git pull origin master
```

Ensure the plugin's committed code is deployed into the GLPI installation/volume before starting the worker. Pulling the Git repository alone does not automatically update a running Docker volume.

## 2. Set the database password in PowerShell

Open PowerShell in the same terminal where you will run Docker Compose:

```powershell
$env:GLPI_DB_PASSWORD = Read-Host "Enter the GLPI database password"
```

Enter the password used by the existing `db` service. This sets it only for the current PowerShell session; it is not written into this file.

## 3. Start the worker alongside the existing Compose project

Replace the paths below if your folders are different. Run the command from the directory containing the original `compose.yml` so Compose uses the existing project name and named volume:

```powershell
cd C:\glpi-docker
docker compose -f compose.yml -f C:\assetsync2.0-10sec\docker\worker.compose.yml config
docker compose -f compose.yml -f C:\assetsync2.0-10sec\docker\worker.compose.yml up -d assetsync-worker
```

The first command renders/validates the merged Compose configuration. Review it before starting the service; ensure the database password is not copied into Git or shared logs.

The paths above are examples based on the original author's local folders. Update them to your actual locations. Keep the base Compose project name and volume consistent so the worker uses the same `glpi_data` volume as GLPI.

## 4. Verify the worker

```powershell
docker ps --filter "name=assetsync-worker"
docker logs --since 2m assetsync-worker
```

The worker normally logs cycle start/finish messages. `Processed: 0` can be normal when there are no due jobs. It is not proof of a successful asset sync by itself.

Check the plugin sync queue in GLPI and confirm that test assets appear in the destination GLPI instance.

## 5. Stop/restart the worker

```powershell
docker compose -f compose.yml -f C:\assetsync2.0-10sec\docker\worker.compose.yml stop assetsync-worker
docker compose -f compose.yml -f C:\assetsync2.0-10sec\docker\worker.compose.yml up -d assetsync-worker
```

## Troubleshooting

- **`Could not open input file: /var/www/glpi/bin/console`**: the command was run directly in Windows PowerShell. It must run inside the GLPI container, or via the Compose service.
- **Worker container starts but cannot find the plugin command**: verify that the plugin code is deployed into the same GLPI data volume mounted at `/var/glpi`, that the plugin is installed/enabled, and that the CLI command is registered.
- **Database connection failures**: confirm `GLPI_DB_HOST` is the Compose service name (`db` in this setup), and that the supplied password matches the existing database service.
- **No jobs processed**: inspect the plugin sync queue, active routes, connection configuration, entity scope, and plugin log. An idle queue can legitimately result in zero processed jobs.
- **Connection timeout/retry**: check network reachability and the queue's `last_error`; retries may be transient.

## Important notes

- This worker configuration is separate from GLPI's standard cron runner (`cron-worker.sh`); do not substitute one for the other.
- Keep only one scheduler responsible for the same AssetSync sync workload. In the tested setup, the GLPI automatic action `assetsync20_sync` was disabled while the dedicated worker was running.
- Do not commit database backups, API tokens, user tokens, passwords, or environment-specific secrets.
- The local test confirmed four computer queue jobs reached `done` and the corresponding computers were visible in GLPI B. Each teammate should repeat the test in their own environment.
