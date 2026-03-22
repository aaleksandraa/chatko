# Horizon Setup (Optional)

Ako zelite da queue runtime ide preko Laravel Horizon umjesto klasicnog `queue:work`, uradite:

```bash
cd /var/www/chatko/backend
composer require laravel/horizon
php artisan horizon:install
php artisan migrate --force
php artisan config:cache
```

Napomena:

- Horizon zahtijeva PHP `ext-pcntl` i preporuceno Linux okruzenje (production server).
- Na Windows lokalnom developmentu ovo obicno nije dostupno; tada koristite fallback `queue:run-managed` koji ce automatski ici na `queue:work`.

Nakon toga `queue:run-managed` ce automatski detektovati `horizon` komandu i startati Horizon.

## Provjera

```bash
php artisan list | grep horizon
php artisan queue:run-managed
```

## systemd

Ne treba mijenjati unit fajl ako koristite:

- `deploy/systemd/chatko-queue.service`

Jer on vec koristi `php artisan queue:run-managed` (Horizon auto-detect).
