# Autodiscover & SMTP probe tool

This small CLI helper attempts to discover Exchange / SMTP settings based on an email address.

Usage:

```bash
php tools/autodiscover_lookup.php admin@example.com [--username=user] [--password=pass] [--verbose]
```

What it does:
- Performs DNS SRV lookup for `_autodiscover._tcp.<domain>`
- Performs MX lookup and lists MX hosts
- Tries common Autodiscover HTTP endpoints and follows redirects
- Parses returned XML for service URLs (EWS, AS, etc.)
- Probes common SMTP hosts (MX, smtp.domain, mail.domain) on ports 25, 587, 465
- If port 587 is open, attempts a simple EHLO to detect STARTTLS support

Notes and caveats:
- Autodiscover often requires credentials and may redirect to vendor-specific endpoints (e.g., Exchange Online)
- For Exchange Online modern auth (OAuth2/Graph) the script may not be able to extract SMTP settings
- Use the `--username` and `--password` flags if you have a test mailbox that can perform Autodiscover
- Running this from inside your network (or where your mail infrastructure is reachable) yields best results

Security:
- Do not commit real credentials. Use this on a trusted machine. If needed, delete credentials after testing.

Example:

```bash
php tools/autodiscover_lookup.php admin@empresa.com --username=admin@empresa.com --password='MiPass' --verbose
```
