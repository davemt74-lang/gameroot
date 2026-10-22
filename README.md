# Lucky Game Hub GameRoot — Portal v0.3.0C3

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

HostGator provisioning (a read-only cPanel connection test is available), automatic subdomains / SSL, live paid checkout, email verification/recovery, shipping signed downloadable installers, and HomeServer/cloud license activation are intentionally not enabled. This release makes those future workflows possible without selling or provisioning anything prematurely.

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

### v0.3.0B — Customer package browsing and selections
Customers can browse active HomeServer, optional subdomain hosting, and fully hosted Cloud packages at /plans.php. Signed-in users can record a package selection in their dashboard. Repeated selections are idempotent; inactive packages cannot be newly selected; user-specific selections are only returned for the authenticated account. Package selections do not create purchases, subscriptions, entitlements, provisioning or download access. Existing v0.3.0A installations can apply the idempotent package schema upgrade from the owner package page.

### v0.3.0C1 — Stripe foundation
Owner Admin → Integrations accepts a Stripe secret API key (sk_test_ / sk_live_) and a distinct webhook signing secret (whsec_), both encrypted at rest and never displayed after saving. Existing installations must click **Enable Stripe event storage**; fresh installations get the table automatically.

Register the HTTPS snapshot webhook endpoint `https://YOUR-DOMAIN/stripe_webhook.php` in Stripe Workbench for the event types documented in `stripe_allowed_event_types()`. The endpoint verifies the raw-body HMAC, a five-minute timestamp tolerance, account test/live mode, event shape, and event type. Repeated event IDs increment a delivery counter but do not duplicate records. Events are stored as **pending**, not processed. No Stripe Checkout call, financial transaction, subscription activation, license, or download occurs in C1. Complete C2/C3 and live Stripe sandbox acceptance before enabling money movement.

### v0.3.0C2 — Stripe sandbox one-time HomeServer checkout
Owner Admin → Integrations requires a valid `sk_test_` API key, webhook signing secret, and an exact **portal HTTPS origin** (for example, `https://example.com`). Existing installations must enable **test Checkout storage**. Active, paid one-time HomeServer package selections then show **Stripe test checkout** in the customer's account.

Checkout requests are CSRF-checked, serialized per buyer, idempotent per order, bounded to an hour-long Stripe-hosted session, and restricted to `checkout.stripe.com`. A customer cannot purchase a product they already own. An entitlement is only granted after Stripe delivers a signed, test-mode, `paid` Checkout event matching the persisted session, product ownership model, amount, currency, order ID, and user ID. Repeated paid events do not duplicate entitlements. A paid event received before local checkout reconciliation is retried via a non-2xx response. **Live Stripe keys, recurring subscriptions, and actual HomeServer download fulfillment remain disabled.** Complete Stripe sandbox acceptance before permitting live charges.


### v0.3.0C3 — Stripe sandbox monthly / annual subdomain hosting
- Owner creates separate active monthly and/or annual **Optional subdomain hosting** packages with exact USD prices. Cloud-only plans and permanent HomeServer purchases do not participate in hosting subscription Checkout.
- Customer submits a subdomain installation request; owner must review it before any subscription checkout. Customer selects an optional hosting plan and chooses that reviewed installation at **Account → Packages awaiting checkout**. Only the account owner may start Checkout; requests, packages and Stripe URLs are validated server-side.
- Stripe test-mode only: Stripe-hosted subscription Checkout uses monthly or annual recurring prices, immutable price snapshot, trusted HTTPS return URLs and an idempotency key. One installation can have only one hosting subscription at a time; repeat clicks reuse the same pending session. No live charges.
- Only signed, test-mode paid Checkout and verified renewal invoices activate hosting authorization. Webhook handlers track paid invoices, failed renewals, subscription updates and deletions, and session expiry. Customer/subscription IDs are bound to the database ledger. Duplicate invoices are not duplicated; older events cannot restore service after a later failure, and canceled subscriptions cannot be revived by late invoices.
- **Account → Subdomain & hosting subscriptions** shows active, past-due, canceled or pending billing status. Active/past-due subscribers may open a customer-bound **Stripe Billing Portal** test session to update payment details or cancel. Configure your Stripe Customer Portal before using that button.
- Existing installations must click **Enable test hosting subscriptions** in Owner Admin → Integrations to create two idempotent tables. New installations include them.
- A verified payment records **billing authorization only**, not DNS, SSL, customer hosting files or deployment; those remain a separately approved/provisioned section. Subscription cancellation does not revoke permanent HomeServer ownership. Sandbox acceptance with real test credentials and an actual site is required before any live mode release.
