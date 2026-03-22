# Chatko systemd services

Ovi unit fajlovi pokrecu backend queue + scheduler 24/7 bez rucnog pokretanja.

## 1) Prilagodi putanju i user

Po defaultu koristi:

- `WorkingDirectory=/var/www/chatko/backend`
- `User=www-data`
- `Group=www-data`

Ako je kod na drugoj putanji ili drugom Linux useru, izmijeni oba `.service` fajla prije instalacije.

## 2) Instalacija servisa

```bash
sudo cp deploy/systemd/chatko-queue.service /etc/systemd/system/
sudo cp deploy/systemd/chatko-scheduler.service /etc/systemd/system/

sudo systemctl daemon-reload
sudo systemctl enable --now chatko-queue
sudo systemctl enable --now chatko-scheduler
```

## 3) Provjera statusa

```bash
sudo systemctl status chatko-queue
sudo systemctl status chatko-scheduler
```

Live log:

```bash
journalctl -u chatko-queue -f
journalctl -u chatko-scheduler -f
```

## 4) Restart nakon deploya

```bash
cd /var/www/chatko/backend
php artisan optimize:clear
php artisan config:cache
php artisan migrate --force
php artisan queue:restart
sudo systemctl restart chatko-queue
sudo systemctl restart chatko-scheduler
```

## 5) Horizon fallback logika

`chatko-queue.service` pokrece `php artisan queue:run-managed`:

- ako postoji `horizon` komanda, starta Horizon
- ako ne postoji, koristi `queue:work`

Na taj nacin isti unit radi i sa i bez Horizon paketa.
