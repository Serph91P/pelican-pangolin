# Raw Port Pools

Raw TCP and UDP Pangolin resources are public and unauthenticated. Restrict access at the application or network layer when needed.

Pools accept comma-separated ports and inclusive ranges, for example `25565,30000-30100`. Ranges cannot overlap and every port must be between 1 and 65535.

The allocator prefers the Pelican allocation port when it belongs to the configured pool and is free. Otherwise it selects the lowest free port. TCP and UDP reservations are independent, so both protocols can use the same number.

Run `php artisan pangolin:infrastructure` to generate:

- firewall rules
- Compose port mappings
- Traefik `tcp-{port}` and `udp-{port}` entry points

Review the output against your Pangolin deployment. The command is read-only and does not apply configuration. Restarting or reloading host services remains an administrator operation.
