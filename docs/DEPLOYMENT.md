# GameRoot v0.1.0 deployment

This is the **standalone cloud portal**, not the Lucky Game Hub game-engine ZIP.

## Hosting / cPanel

1. Create a new, empty MySQL/MariaDB database and a database user with permissions on it using cPanel. Record host, port, database name and user. Shared hosting often prepends an account prefix to both names.
2. Extract the **full GameRoot deployment ZIP** into a directory outside the domain document root, for example `~/gameroot/`.
3. Point the **root domain's document root** to `~/gameroot/public` (not `~/gameroot`). Do not deploy the `app/`, `var/`, or `tests/` directories inside an accessible document root. Do not use plain HTTP for first owner setup or login.
4. Use PHP 8.1+ with `pdo_mysql`, `sodium`, and `openssl` enabled. Grant the PHP process write access to `~/gameroot/var/`; restrict permissions to the hosting account. If the server does not provide secure key storage, do not set API keys yet.
5. Visit `https://YOUR_ROOT_DOMAIN/install.php` and complete the one-time guided setup. The portal creates database tables and the owner account, then writes encrypted-key metadata and database credentials to `var/config.php` (chmod 0600). The installer locks once a config exists.
6. Log in as owner and open **Admin → Integrations / API keys**. Configure game-base domain, and optionally save HostGator/cPanel, OpenAI, or Claude credentials. Values are masked and can be revoked. These settings **do not trigger provisioning or AI calls yet**.
7. Register a non-admin customer and submit a sample installation request. Verify Admin sees it as pending, and that no DNS, hosting, or payment change occurred.

## Security and scope

- **Do not upload API tokens to GitHub, tickets, chat, or the public document root.** Tokens are never returned to HTML once saved.
- Portal DB credentials and encryption key are stored server-side in `var/config.php`, outside `public/`. Back this file up securely, separately from database backups. Losing it makes encrypted provider credentials unrecoverable.
- The portal currently offers account sign-up, login, owner admin, encrypted provider credential storage, and **pending** subdomain requests.
- **Automatic subdomain provisioning, DNS/SSL verification, checkout, invoices, payment webhooks, password reset, email verification, signed download delivery, and HomeServer updates are not yet implemented**. The UI does not claim these operations are active.
- Registered user accounts are active immediately. Before public launch, add email verification and password recovery, and confirm mail deliverability and account abuse controls.
- Hosted game instances will remain separate databases and deployments, under a reviewed provisioning flow. HomeServer remains playable offline, with no cloud requirement.
- Choose a staging domain for first certification and test with a dedicated fresh database. Do not run the installer against the existing `lucky` game database.

## Release upgrades

Each release ZIP is independent of the existing Lucky Game Hub Cloud ZIP. Keep your `var/config.php` and all database/media state during upgrades. Back up config + DB before future schema changes. The first version has no upgrade migrations yet.
