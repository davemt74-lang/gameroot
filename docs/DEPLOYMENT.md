# GameRoot v0.3.0C3 deployment

This is the **standalone cloud portal**, not the Lucky Game Hub game-engine ZIP.

## Hosting / cPanel

1. Create a new, empty MySQL/MariaDB database and a database user with permissions on it using cPanel. Record host, port, database name and user. Shared hosting often prepends an account prefix to both names.
2. Extract the **full GameRoot deployment ZIP** into a directory outside the domain document root, for example `~/gameroot/`.
3. Point the **root domain's document root** to `~/gameroot/public` (not `~/gameroot`). Do not deploy the `app/`, `var/`, or `tests/` directories inside an accessible document root. Do not use plain HTTP for first owner setup or login.
4. Use PHP 8.1+ with PDO MySQL, sodium, openssl and cURL enabled. Grant the PHP process write access to `~/gameroot/var/`; restrict permissions to the hosting account. If the server does not provide secure key storage, do not set API keys yet.
5. Visit `https://YOUR_ROOT_DOMAIN/install.php` and complete the one-time guided setup. The portal creates database tables and the owner account, then writes encrypted-key metadata and database credentials to `var/config.php` (chmod 0600). The installer locks once a config exists.
6. Log in as owner and open **Admin → Integrations / API keys**. Configure game-base domain, and optionally save HostGator/cPanel, OpenAI, or Claude credentials. Values are masked and can be revoked. These settings **do not trigger provisioning or AI calls yet**.
7. Register a non-admin customer and submit a sample installation request. Verify Admin sees it as pending, and that no DNS, hosting, or payment change occurred.

## Security and scope

- **Do not upload API tokens to GitHub, tickets, chat, or the public document root.** Tokens are never returned to HTML once saved.
- Portal DB credentials and encryption key are stored server-side in `var/config.php`, outside `public/`. Back this file up securely, separately from database backups. Losing it makes encrypted provider credentials unrecoverable.
- The portal offers account sign-up/login, owner admin, encrypted credentials, reviewed subdomain requests, package catalog, test Checkout, and test-mode subscription billing.
- **Automatic subdomain provisioning, DNS/SSL verification, live payment checkout, password reset, email verification, signed download delivery, and HomeServer updates are not yet implemented**. Test-only Checkout, signed webhooks and renewal invoice tracking are implemented. The UI does not claim these operations are active.
- Registered user accounts are active immediately. Before public launch, add email verification and password recovery, and confirm mail deliverability and account abuse controls.
- Hosted game instances will remain separate databases and deployments, under a reviewed provisioning flow. HomeServer remains playable offline, with no cloud requirement.
- Choose a staging domain for first certification and test with a dedicated fresh database. Do not run the installer against the existing `lucky` game database.

## Release upgrades

Each release ZIP is independent of the existing Lucky Game Hub Cloud ZIP. Keep your `var/config.php` and all database/media state during upgrades. Back up config + DB before future schema changes. Owner-run, idempotent schema upgrades are available for package catalog, Stripe event storage, one-time Checkout and hosting subscriptions.


## v0.3.0C3 Stripe sandbox acceptance (required before live billing)

1. Back up the private configuration and MySQL database. Upload the merged GameRoot deployment ZIP, keeping existing var/ data, with only public/ in the document root.
2. In Owner Admin → Integrations save the portal HTTPS origin (for example https://example.com, without path/slash), a Stripe test secret API key and the matching Stripe webhook signing secret. Do not paste secrets into tickets or chat.
3. If upgrading, choose the idempotent **Enable Stripe event storage**, **Enable test Checkout storage**, and **Enable test hosting subscriptions** actions as necessary. Fresh installations already include these tables.
4. Register https://YOUR-DOMAIN/stripe_webhook.php as a Stripe snapshot-event HTTPS endpoint. Include checkout.session.completed, checkout.session.async_payment_succeeded, checkout.session.expired, invoice.paid, invoice.payment_failed, customer.subscription.updated, and customer.subscription.deleted. Webhook event types must match the configured Stripe account and mode.
5. Configure the Stripe test-mode **Customer Portal** for subscription cancellation and payment-method changes. GameRoot creates customer-bound portal sessions; it does not store payment cards.
6. In **Admin → Package builder**, activate one or more USD **Optional subdomain hosting** packages, with subscription and either monthly or annual interval. Do not use a HomeServer or generic Cloud package for this flow.
7. Register a customer, select the hosting package, create an installation request and mark it **Reviewed** as owner. In the customer account select that reviewed request and click **Stripe test subscription**. Complete a Stripe test payment. Confirm that only a matching signed paid session sets hosting to active. Unpaid and pending orders cannot create active hosting.
8. Simulate a renewal invoice, failed renewal, out-of-order or duplicate event, and cancellation. Confirm the correct account sees state changes exactly once, and that HomeServer ownership is unchanged. Verify **Manage billing / cancel** opens Stripe-hosted test billing. Check the signed webhook inbox for failed retries and resolution.
9. Keep live checkout **disabled** and do not create DNS/SSL or hosting installations yet. Provisioning must separately validate domain ownership, cPanel permissions and deployment isolation. Sandbox acceptance is a release gate.

**Pricing:** one hosting-addon subscription is tied to one reviewed installation request. Its selected monthly/annual price is snapshotted at checkout; catalog price edits affect future new subscriptions. A canceled subscription can start a new test Checkout. A ready, active or past-due subscription blocks duplicate purchases. Billing authorization is not proof of completed provisioning.
