# Chatko Admin Operativno Uputstvo

Ovaj dokument je prakticni prirucnik za administraciju sistema:

- tenant onboarding
- korisnici i role
- login i pracenje poruka
- widgeti i widget instalacija
- products i ingestion (integracije + CSV)
- knowledge
- audit log
- widget lab
- OpenAI povezivanje

Dokument opisuje trenutno implementirano stanje sistema.

---

## 1. Koncept sistema (kratko)

Sistem je multi-tenant:

- jedan tenant = jedna firma/klijent
- svaki tenant ima svoje:
  - korisnike i role
  - widget(e)
  - proizvode
  - knowledge dokumente
  - conversation istoriju
  - AI config
  - audit log zapise

Admin API je tenant-scoped. Za API pozive treba:

- `Authorization: Bearer <token>`
- `X-Tenant-Slug: <tenant-slug>` (ili `X-Tenant-Id`)

---

## 2. OpenAI: ko podesava API key

## Trenutni model (implementirano)

- OpenAI API key je globalan na nivou servera (`.env`).
- Svaki tenant ima svoj AI config (model, temperature, prompt, itd.) u bazi.
- BYOK (svaki klijent svoj OpenAI key) trenutno NIJE implementiran.

To znaci:

- platform admin (vi) postavlja globalni `OPENAI_API_KEY`
- klijenti (tenant admin/owner) kroz UI podesavaju AI ponasanje za svoj tenant

## Server setup (obavezno)

U `backend/.env`:

```env
OPENAI_API_KEY=sk-...
OPENAI_EMBEDDING_MODEL=text-embedding-3-small
OPENAI_EMBEDDING_DIMENSIONS=1536
```

Poslije izmjene:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan queue:restart
```

Ako worker nije pokrenut:

```bash
php artisan queue:work --tries=3
```

Napomena:

- ako nema validnog `OPENAI_API_KEY`, sistem radi fallback odgovore i fallback embeddinge
- za produkcijski kvalitet obavezno koristiti validan OpenAI key

---

## 3. Role i prava pristupa

Role hijerarhija:

- `support < editor < admin < owner`

Operativna pravila:

1. `support`
   - moze citati conversations i poruke
   - moze citati analytics overview
   - moze citati products/knowledge
   - ne moze mijenjati kriticne admin entitete
2. `editor`
   - sve iz support
   - moze edit products/knowledge
   - moze edit conversation status/lead flag
3. `admin`
   - sve iz editor
   - full integrations, widgets, ai config update
   - import job update/delete
   - conversation delete
   - audit log pregled
4. `owner`
   - najvisi nivo, prolazi sve admin provjere

---

## 4. Kreiranje tenant-a i prvog korisnika (owner)

Najbrzi nacin: **Tenant Onboarding Wizard** u Admin UI.

Koraci:

1. Otvori `GET /`
2. Tab `Tenant Onboarding`
3. Step 1: tenant + owner podaci
4. Step 2: widget osnovna konfiguracija
5. Step 3: (opciono) inicijalna integracija
6. Step 4: AI config
7. Klik `Create Tenant`

Rezultat onboarding-a:

- kreira se tenant
- kreira se owner korisnik
- owner se automatski uloguje
- kreira se widget
- upisuje se ai_config
- (opciono) kreira se inicijalna integracija

---

## 5. Kreiranje dodatnih korisnika za postojeci tenant

## Standardni nacin (UI)

Koristi tab `Users` u Admin panelu.

Koraci:

1. Login kao `admin` ili `owner`
2. Otvori tab `Users`
3. U formi `Add / Invite User` unesi:
   - name
   - email
   - role
   - password + confirm (obavezno za novi nalog)
4. Klik `Save User`
5. U tabeli `Tenant Users` mozes:
   - `Edit` (name/email/role + opcioni reset lozinke)
   - `Reset email` (salje token link korisniku da sam postavi novu lozinku)
   - `Remove` (uklanja korisnika iz tenant-a)

Napomene:

- ako email vec postoji u sistemu, korisnik se dodaje u tenant sa izabranom rolom
- owner rola je posebno zasticena:
  - samo owner moze mijenjati owner rolu
  - tenant mora uvijek imati barem jednog owner korisnika
- pri promjeni role automatski se sync-a role na postojecim tenant tokenima tog korisnika
- nakon uspjesnog password reset-a, svi aktivni API tokeni korisnika se revokuju

## Fallback nacin (tinker)

Ako bas treba skriptovani unos, moze i preko `php artisan tinker`.

Primjer: kreiranje support korisnika

```bash
php artisan tinker
```

```php
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Support\Facades\Hash;

$tenant = Tenant::where('slug', 'demo-shop')->firstOrFail();

$user = User::updateOrCreate(
    ['email' => 'agent@demo.local'],
    [
        'name' => 'Support Agent',
        'password' => Hash::make('StrongPass123!'),
    ]
);

$tenant->users()->syncWithoutDetaching([
    $user->id => ['role' => 'support'],
]);
```

## Promjena role postojeceg korisnika

```php
$tenant->users()->updateExistingPivot($user->id, ['role' => 'editor']);
```

## Uklanjanje korisnika iz tenant-a

```php
$tenant->users()->detach($user->id);
```

Napomena:

- isti korisnik (isti email) moze biti clan vise tenant-a, sa razlicitim rolama

---

## 6. Kako korisnici rade login

U Admin UI login forma trazi:

- `Tenant slug`
- `Email`
- `Password`

Backend login endpoint:

- `POST /api/admin/auth/login`

Primjer:

```bash
curl -X POST http://127.0.0.1:8001/api/admin/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "tenant_slug":"demo-shop",
    "email":"agent@demo.local",
    "password":"StrongPass123!"
  }'
```

Response vraca bearer token + role + tenant info.

---

## 7. Pracenje poruka (Conversations)

Da, moguce je pratiti poruke.

UI:

1. Login kao `support` ili veca rola
2. Tab `Conversations`
3. `Reload` za listu
4. Dugme `Messages` na konkretnom conversation-u
5. Desni panel prikazuje poruke (user + assistant)

Dodatne akcije:

- `Edit` (status, lead_captured, converted, ended_at) - editor+
- `Delete` - admin+

API:

- `GET /api/admin/conversations`
- `GET /api/admin/conversations/{id}/messages`

Napomena:

- trenutno nije websocket/live streaming panel
- operativno: koristi se `Reload` za osvjezavanje liste

---

## 8. Widgets: kreiranje, uredjivanje, instalacija

## A) Kreiranje i upravljanje u UI

Tab `Widgets`:

- kreiranje widgeta (`name`, `locale`, `allowed_domains_json`, `theme_json`, `is_active`)
- edit/delete kroz modal
- `Copy key` za `public_key`

## B) Ugradnja widgeta na web stranicu

Dodati skriptu na klijentski sajt:

```html
<script
  src="https://YOUR_DOMAIN/widget.js"
  data-key="wpk_XXXXXXXXXXXXXXXXXXXXXXXX"
  data-api-base="https://YOUR_DOMAIN">
</script>
```

`data-key` je widget public key iz admin panela.

## C) Vazno ogranicenje trenutne verzije

- `allowed_domains_json` se trenutno cuva kao konfiguracija/metadata
- stroga server-side validacija domain whitelist-a jos nije ukljucena

---

## 9. Widget Lab (interni test chat)

Tab `Widget Lab` sluzi za interni test bez ugradnje na eksterni sajt.

Koraci:

1. Unesi `widget public key`
2. (opciono) unesi `source URL`
3. `Start Session`
4. Salji poruke kroz `Send Message`
5. U logu vidis:
   - user poruku
   - AI odgovor
   - preporucene proizvode (ako ih ima)

Koristi se za brzu provjeru:

- da li radi session start
- da li radi retrieval (products + knowledge)
- da li AI config daje dobre odgovore

---

## 10. Products: kako se puni katalog

Postoje 3 operativna nacina:

1. Rucni unos kroz tab `Products`
2. Integracije (WooCommerce, WordPress REST, Shopify, Custom API)
3. CSV import (trenutno kroz API)

## A) Rucni unos (UI)

Tab `Products`:

- forma za create proizvoda
- pretraga/filter
- edit/delete kroz modal

## B) Integracije (UI)

Tab `Integrations`:

1. Kreiraj integraciju (type + base_url + auth + credentials + config + mapping)
2. `Test` provjeri konekciju
3. `Initial` pokrece pun sync
4. `Delta` pokrece inkrementalni sync
5. Prati rezultat u `Import Jobs`

Podrzani adapteri:

- WooCommerce
- WordPress REST
- Shopify
- Custom API (sa transform/mapping pravilima)
- CSV/manual tipovi

## C) Mapping presets

U istom tabu mozes:

- kreirati mapping preset
- `Apply` preset na integraciju
- edit/delete preset

## D) CSV import (API)

Endpoint:

- `POST /api/admin/products/import/csv` (multipart form-data, polje `file`)

Primjer:

```bash
curl -X POST http://127.0.0.1:8001/api/admin/products/import/csv \
  -H "Authorization: Bearer <TOKEN>" \
  -H "X-Tenant-Slug: demo-shop" \
  -F "file=@products.csv"
```

Nakon toga prati status u `Import Jobs`.

---

## 11. Import Jobs: monitoring ingest procesa

Tab `Import Jobs`:

- prikaz queue poslova (sync/import)
- status (`pending`, `processing`, `completed`, `failed`, ...)
- log summary
- edit/delete kroz modal (admin)

Koristi se za:

- operativni monitoring ingest-a
- brzu dijagnostiku gresaka sinhronizacije

---

## 12. Knowledge: FAQ/politike/pravila firme

## A) Text knowledge (UI)

Tab `Knowledge`:

- forma `Create Text Document`
- polja: `title`, `type`, `visibility`, `ai_allowed`, `content`
- edit/delete kroz modal
- `Reindex` dugme za ponovno indeksiranje

## B) File knowledge (API)

Endpoint:

- `POST /api/admin/knowledge-documents/upload`

Podrzani fajl ekstenzije:

- `txt`, `pdf`, `docx`

## C) Vazno ogranicenje parsera

Trenutno parser punim tokom radi za:

- `txt`

Za `pdf` i `docx`:

- parser hook postoji, ali parser paket nije konfigurisan
- dokument ce pasti u failed ako parser nije dodan

Preporuka:

- za sada koristiti text unos ili TXT upload dok se ne doda PDF/DOCX parser

---

## 13. AI Config (po tenantu)

Tab `AI Config` kontrolise ponasanje asistenta po tenantu:

- `provider`
- `model_name`
- `embedding_model`
- `temperature`
- `max_output_tokens`
- `top_p`
- `system_prompt_template`

Preporuceni start:

- provider: `openai`
- model_name: `gpt-5-mini`
- embedding_model: `text-embedding-3-small`
- temperature: `0.2 - 0.4`
- max_output_tokens: `600 - 900`
- top_p: `1.0`

Vazno:

- ovaj tab ne cuva OpenAI API key
- key je globalni env secret na serveru

---

## 14. Audit Logs: sta je i kako se koristi

Audit log je trag admin akcija nad kriticnim entitetima.

Tab `Audit Logs`:

- pregled akcija
- filter po:
  - `action` (`updated`, `deleted`)
  - `entity_type`
  - `actor user id`

Svaki zapis ima:

- action
- entity type / entity id
- actor (korisnik)
- role
- request path
- timestamp

Namjena:

- sigurnost i odgovornost ("ko je sta promijenio")
- troubleshooting
- kontrola admin operacija

Pristup:

- `admin` i `owner` (support/editor nema pristup)

---

## 15. Health checks i operativna provjera

Koristiti:

- `GET /up`
- `GET /api/health/live`
- `GET /api/health/ready`

Ako `ready` vraca `degraded`:

- provjeriti DB konekciju
- provjeriti write dozvole nad `storage/`
- provjeriti worker proces

---

## 16. Dnevna admin rutina (preporuka)

1. Provjeri `Dashboard` (overview metrika).
2. Provjeri `Import Jobs` (ima li failed/completed_with_errors).
3. Provjeri `Conversations` i poruke koje traze follow-up.
4. Provjeri `Audit Logs` za kriticne izmjene.
5. Po potrebi osvjezi AI Config i knowledge dokumente.

---

## 17. Najcesca pitanja

## P: Da li svaki klijent ima svoj OpenAI key?

O: Trenutno ne. Key je globalni, a tenant ima svoj AI config.

## P: Moze li support da cita poruke korisnika?

O: Da, kroz `Conversations` tab i `Messages` akciju.

## P: Mozemo li uploadovati PDF knowledge?

O: API endpoint postoji, ali parser za PDF/DOCX trenutno nije konfigurisan.

## P: Mozemo li kreirati korisnike iz UI?

O: Da. Koristi se `Users` tab (create/edit/remove + role management).

---

## 18. Kratki onboarding checklist za novog klijenta

1. Kreirati tenant kroz onboarding wizard.
2. Verifikovati globalni OpenAI key na serveru.
3. Podesiti tenant AI Config.
4. Kreirati i testirati widget (`Widget Lab`).
5. Povezati product source (integracija ili CSV).
6. Unijeti knowledge dokumente.
7. Pokrenuti initial sync / reindex.
8. Testirati realne upite kroz Widget Lab.
9. Dodati support/editor korisnike.
10. Potvrditi audit log i health check.
