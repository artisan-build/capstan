# Cloudflare Email Invites

Capstan can send team invitation email through Laravel's first-party Cloudflare mail transport. The default fork remains safe: `MAIL_MAILER=log` means invite delivery is copy-link only until a deployer explicitly enables Cloudflare.

## 1. Verify A Sending Domain

In the Cloudflare dashboard, open your account and go to **Email** -> **Email Sending**. Add the domain you want Capstan to send from, then follow Cloudflare's verification flow.

Use a sender address on that verified domain, for example `invites@yourdomain.example`.

## 2. Add DNS Records

Cloudflare will show exact DNS values for your domain. Add the record shapes it provides:

| Type | Name | Content |
| --- | --- | --- |
| TXT | `<selector>._domainkey` | `<DKIM public key value from Cloudflare>` |
| TXT | `@` or your sending subdomain | `v=spf1 include:<cloudflare-provided host> ~all` |
| TXT | `_dmarc` | `v=DMARC1; p=<policy>; rua=mailto:<report-address>` |

Do not invent these values. Use the account-specific values Cloudflare displays for the sending domain.

## 3. Create An API Token

Create a scoped Cloudflare API token for the account that owns the sending domain. In the API token permissions picker, select the Email Sending permission Cloudflare exposes for sending mail, scoped to the target account.

Cloudflare occasionally adjusts permission labels. If the exact label differs, search the token permission picker for **Email Sending** and choose the send/write permission for the account.

## 4. Configure Capstan

Set these environment variables in the deployment environment:

```dotenv
MAIL_MAILER=cloudflare
MAIL_FROM_ADDRESS=invites@yourdomain.example
CLOUDFLARE_ACCOUNT_ID=<cloudflare-account-id>
CLOUDFLARE_EMAIL_TOKEN=<scoped-email-sending-token>
```

Keep `MAIL_FROM_ADDRESS` on a Cloudflare-verified sending domain. Do not add unrelated mail or storage provider settings for email invites.

## 5. Verify Delivery

After deployment, run a one-off test from the app environment:

```bash
php artisan tinker --execute="Mail::raw('Capstan email test', fn ($message) => $message->to('you@yourdomain.example')->subject('Capstan email test'));"
```

If delivery fails, check the Cloudflare Email Sending activity, the sending-domain DNS verification state, and the app logs for the Cloudflare API response.
