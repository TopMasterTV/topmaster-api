# Top Gestor webhook probe

Temporary read-only receiver for validating `customer.renewed`. It does not load
the operational backend or access PostgreSQL.

## Isolation

- The dedicated Dockerfile copies only this probe's `src/` and `public/`.
- The image does not install PDO or PostgreSQL extensions.
- Do not configure `DATABASE_URL`.
- Only customer `143528` and event `customer.renewed` are accepted.
- Expiration dates are never written. Duplicate claims are ephemeral `/tmp` files.
- Bodies, tokens, credentials, secrets and complete signatures are never logged.

## Environment

Required:

- `TOPGESTOR_WEBHOOK_SECRET` (at least 32 bytes)
- `TOPGESTOR_API_TOKEN` (read-only token)
- `TOPGESTOR_ALLOWED_CUSTOMER_ID` (exactly `143528`)
- `TOPGESTOR_EXPECTED_GP_PRODUCT_ID`

Optional:

- `TOPGESTOR_EXPECTED_GP_PRODUCT_NAME` (default `Sistema GP`)
- `TOPGESTOR_BASELINE_EXPIRATION` (`Y-m-d`, comparison only)
- `TOPGESTOR_DELIVERY_DIR` (default under `/tmp`)

## Endpoints

- `GET /health`
- `POST /webhooks/top-gestor`

After publication, register:

```text
https://<isolated-render-service>.onrender.com/webhooks/top-gestor
```

## Render plan

1. Create a new Web Service. Do not reuse production or the automatic-update Cron Job.
2. Select Docker, repository-root build context, and Dockerfile
   `top_gestor_webhook_probe/Dockerfile`.
3. Disable Auto-Deploy and configure health check `/health`.
4. Use one instance and add only the environment above. Confirm `DATABASE_URL` is absent.
5. Deploy only after publication authorization.
6. Configure the Top Gestor webhook only after separate authorization.
7. Remove the webhook and temporary service after the controlled test.

The Docker build runs PHP lint for the receiver and the complete synthetic test
suite. A syntax error or failed test stops the image build. Test files are
removed before the service starts.

## Signature assumption

The probe strictly accepts:

```text
X-Hub-Signature-256: sha256=<64 lowercase hexadecimal characters>
```

It calculates HMAC-SHA-256 over the exact raw body and uses `hash_equals`.
Before deployment, compare this with the contract displayed by the Top Gestor
panel. Stop if the panel documents a different canonical format.

## Synthetic test

```text
php top_gestor_webhook_probe/tests/run.php
```

The test uses a fake API client and makes no external request.
