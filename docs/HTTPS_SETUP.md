# HTTPS Setup (when a domain is ready)

HTTPS needs a **domain name** pointing at the droplet — Let's Encrypt will not
issue certificates for a bare IP. Everything else is prepared; once you have a
domain (e.g. `ams.itsea.in`), it's ~10 minutes:

## 1. DNS
Create an **A record**: `ams.yourdomain.tld → 142.93.88.143` (TTL 300).

## 2. Get the certificate (on the droplet)
```bash
ssh root@142.93.88.143
apt-get update && apt-get install -y certbot
# stop nginx briefly so certbot can bind :80 (standalone mode)
cd /var/www/attendance
docker compose -f docker-compose.yml -f docker-compose.prod.yml stop nginx
certbot certonly --standalone -d ams.yourdomain.tld --agree-tos -m you@example.com --no-eff-email
docker compose -f docker-compose.yml -f docker-compose.prod.yml start nginx
```
Certs land in `/etc/letsencrypt/live/ams.yourdomain.tld/`.

## 3. Nginx TLS server block
Mount certs into the container — add to `docker-compose.prod.yml` under `nginx.volumes`:
```yaml
      - /etc/letsencrypt:/etc/letsencrypt:ro
```
Then add to `nginx/conf.d/default.conf` (keep the :80 block, make it redirect):
```nginx
server {
    listen 443 ssl;
    http2 on;
    server_name ams.yourdomain.tld;
    ssl_certificate     /etc/letsencrypt/live/ams.yourdomain.tld/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/ams.yourdomain.tld/privkey.pem;
    # …copy the entire contents (locations) of the existing :80 server block here…
}
# and change the :80 block body to a redirect:
#   return 301 https://$host$request_uri;
```

## 4. App config
```bash
# in /var/www/attendance/.env
APP_URL=https://ams.yourdomain.tld
SANCTUM_STATEFUL_DOMAINS=ams.yourdomain.tld
```
Then: `docker compose ... up -d nginx && docker exec ams_backend php artisan config:cache`

`AppServiceProvider` auto-enables `URL::forceScheme('https')` when APP_URL is
https — no code change needed.

## 5. Renewals
```bash
echo "0 3 * * 1 certbot renew --pre-hook 'docker stop ams_nginx' --post-hook 'docker start ams_nginx'" | crontab -
```

## 6. Update client-facing URLs
Client guide, download page, HANDOFF and app default server URL
(`client/lib/core/config.dart` → rebuild APK) still reference the bare IP —
swap to the domain after cutover.
