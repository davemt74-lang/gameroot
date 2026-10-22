# Lucky Game Hub GameRoot — Portal v0.1.0

A PHP 8.1+ root-domain portal, separate from the [Lucky Game Hub game engine](https://github.com/davemt74-lang/lucky).

### Built in v0.1.0

- Public responsive landing page, feature overview, and forthcoming hosted / HomeServer options.
- Guided first-owner installer using a **new empty** MySQL/MariaDB database.
- Customer self-registration, login/logout, password hashing, CSRF sessions, and login/registration rate limiting.
- Private customer dashboard for pending hosted game subdomain requests.
- Owner-only Admin sections: system overview, customers, request review, purchases/downloads roadmap, and integrations.
- Encrypted-at-rest, masked, replace/revoke credentials for HostGator/cPanel, OpenAI and Anthropic (Claude), using PHP sodium. Credentials are **not** committed or displayed back in Admin.
- Database scaffolding for products, orders and entitlements, with checkout and download fulfillment **disabled** until implemented and certified.
- Automated PHP lint, security contract tests and MySQL lifecycle acceptance in GitHub Actions.

### Not yet implemented

HostGator provisioning (a read-only cPanel connection test is available), automatic subdomains / SSL, paid checkout, email verification/recovery, shipping signed downloadable installers, and HomeServer/cloud license activation are intentionally not enabled. This release makes those future workflows possible without selling or provisioning anything prematurely.

### Deployment

Read [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md). **Set the root domain document root to `public/`.** Secrets and SQL code must not be published as static web files. Run `/install.php` to create the first owner account.

### Repositories

- `davemt74-lang/gameroot` — root-domain SaaS, accounts, purchases, integration configuration.
- `davemt74-lang/lucky` — actual private game app, phone controller, video editor and HomeServer runtime.

### v0.2.0A — cPanel verification
After saving the cPanel hostname, account username and API token in Owner Admin → Integrations, select **Test cPanel connection**. GameRoot makes a read-only authenticated UAPI call over validated HTTPS port 2083. DNS results must be public IP addresses and are pinned for the request; redirects and invalid TLS certificates are rejected. Tokens and response bodies are never shown in the UI. Ensure PHP cURL is installed and your host permits outbound connections to port 2083. A successful check confirms API authentication only; it does not imply rights to provision subdomains, DNS, databases or SSL.

### v0.3.0A — Package catalog
The owner can visit **Admin → Package builder** to create, edit, activate, and disable draft packages. Package choices enforce one-time HomeServer ownership, optional recurring subdomain hosting, and recurring fully hosted Cloud plans. Prices are stored as integer USD cents and product SKUs are unique. Existing installations see an explicit owner-only, CSRF-protected and idempotent database upgrade before using the builder.

An active package is catalog metadata only. No checkout, recurring charges, payment credentials, license issuance, downloads, or hosting provisioning occur in v0.3.0A. Subsequent sections must independently certify each fulfillment step. Existing entitlements remain untouched; products with orders or entitlements cannot change their delivery/ownership model.
