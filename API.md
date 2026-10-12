# Watchdog-connector: HTTP-contract (v1)

Dit is de afspraak tussen de Watchdog-server en de Craft-plugin. De server roept deze eindpunten aan;
de macOS-app praat nooit rechtstreeks met een Craft-site.

## Basis

```
{basis}/actions/watchdog/api/<actie>
```

`{basis}` is standaard schema + host van de bewaakte URL (bv. `https://voorbeeld.be`).

## Authenticatie

De server stuurt de sleutel in twee headers, omdat sommige hosts `Authorization` weggooien:

```
Authorization: Bearer <sleutel>
X-Watchdog-Key: <sleutel>
```

De plugin aanvaardt een van beide en vergelijkt met `hash_equals`.

Een geldige sleutel is ofwel de sleutel uit `.env` / de plugin-instellingen, ofwel een sleutel die de
plugin zelf uitgaf bij het koppelen via het controlepaneel (zie `POST claim`).

| Situatie | Status | Antwoord |
| --- | --- | --- |
| Geen sleutel ingesteld (of korter dan 32 tekens) en ook geen koppeling via het controlepaneel | 503 | `{"ok": false, "error": "…"}` |
| Sleutel ontbreekt of klopt niet | 401 | `{"ok": false, "error": "…"}` |
| Verkeerde methode / onbekende actie / onverwachte fout | 405 / 404 / 500 | `{"ok": false, "error": "…"}` |

Twee gevallen bereiken de plugin niet en krijgen het eigen antwoord van Craft: ongeldige JSON (400) en
een site met een openstaande migratie (503, zonder `"ok"`).

## Algemeen

- Alle antwoorden zijn JSON met `Content-Type: application/json`.
- Gelukt: `{"ok": true, …}`. Mislukt: passende HTTP-status en `{"ok": false, "error": "leesbare melding in het Nederlands"}`.
- Tijdstippen zijn Unix-seconden (integer).
- Sleutels in camelCase.
- Geheimen komen nooit in een antwoord: geen SMTP-wachtwoord, geen licentiesleutels, geen omgevingsvariabelen.
- Geen CSRF-token nodig; de eindpunten zijn anoniem bereikbaar en enkel door de sleutel beveiligd.

## Koppelen via het controlepaneel: `POST claim`

Alternatief voor een sleutel in `.env`. De server bedenkt een geheime `verifier` (64 hex-tekens) en
stuurt de gebruiker naar het controlepaneel met alleen de SHA-256 ervan:

```
{controlepaneel}/watchdog/pair?challenge=<sha256(verifier)>&label=<naam>
```

Een beheerder ziet daar een controlecode (de eerste zes hex-tekens van
`sha256("watchdog-pair:" + challenge)`, als `XXX-XXX`), die de app ook toont, en keurt goed. De plugin
maakt dan een sleutel aan. De server haalt die op met het enige eindpunt dat geen sleutel vraagt:

```json
{ "verifier": "<64 hex-tekens>" }
```

```json
{ "ok": true, "key": "…48 tekens…", "pending": false }
```

Zolang er niets op te halen valt (nog niet goedgekeurd, verlopen na 10 minuten, of onbekend):
`{"ok": true, "key": null, "pending": true}`. De sleutel wordt één keer uitgegeven; daarna bewaart de
plugin alleen de hash. Een 404 betekent dat de plugin er niet staat of te oud is.

## `GET info`

Instellingen en toestand van de site.

```json
{
  "ok": true,
  "connector": { "version": "1.0.0" },
  "craft": {
    "version": "5.4.2",
    "edition": "pro",
    "environment": "production",
    "devMode": false,
    "allowAdminChanges": false,
    "isSystemLive": true,
    "phpVersion": "8.3.1",
    "dbDriver": "mysql",
    "dbVersion": "8.0.36",
    "timezone": "Europe/Brussels",
    "updates": { "total": 3, "critical": false }
  },
  "sites": [
    { "handle": "default", "name": "Voorbeeld", "baseUrl": "https://voorbeeld.be/", "language": "nl-BE", "primary": true }
  ],
  "mail": {
    "transport": "SMTP",
    "fromEmail": "info@voorbeeld.be",
    "fromName": "Voorbeeld",
    "replyToEmail": null,
    "host": "smtp.voorbeeld.be",
    "port": 587,
    "encryption": "tls",
    "username": "info@voorbeeld.be"
  },
  "plugins": [
    { "handle": "formie", "name": "Formie", "version": "3.0.1", "enabled": true }
  ],
  "forms": {
    "formie": { "installed": true, "version": "3.0.1", "failureLog": true, "note": null },
    "freeform": { "installed": false, "version": null, "failureLog": false, "note": null }
  },
  "queue": { "waiting": 0, "reserved": 0, "failed": 0 }
}
```

- `craft.updates` is `null` als er geen update-info in de cache zit. Dit eindpunt mag zelf geen trage
  externe aanvraag doen om updates op te halen.
- `mail.host`, `port`, `encryption`, `username` zijn `null` als het transport ze niet heeft.
  `encryption` is op Craft 5 altijd `null`: die instelling bestaat daar niet meer.
- `forms.<plugin>.installed` is ook `false` als de plugin wel geïnstalleerd maar uitgeschakeld is.
- Freeform houdt zelf geen log bij: `forms.freeform.failureLog` is altijd `false`, met uitleg in `note`.
- `forms.<plugin>.failureLog` zegt of de plugin mislukte meldingen bijhoudt (bv. Formie's
  "Sent Notifications"). Staat dat uit, dan legt `note` uit wat er moet aangezet worden.

## `POST mail-test`

Verstuurt een testmail met de mailinstellingen van Craft, zoals de knop "Test" onder Instellingen → E-mail.

Aanvraag:

```json
{ "to": "iemand@voorbeeld.be" }
```

Antwoord (ook bij een mislukte verzending is de HTTP-status 200):

```json
{ "ok": true, "sent": true, "error": null, "transport": "SMTP", "fromEmail": "info@voorbeeld.be" }
```

Bij `sent: false` bevat `error` de foutmelding van het transport. Ongeldig adres: 422.

## `GET forms`

Alle formulieren van Formie en Freeform.

```json
{
  "ok": true,
  "forms": [
    {
      "plugin": "formie",
      "id": 3,
      "handle": "contact",
      "name": "Contact",
      "fields": [
        { "handle": "email", "label": "E-mail", "type": "email", "required": true }
      ],
      "notifications": [
        { "name": "Melding naar beheerder", "enabled": true, "to": "info@voorbeeld.be" }
      ]
    }
  ]
}
```

Geen formulierplugin geïnstalleerd: `{"ok": true, "forms": []}`.

## `POST submit`

Maakt binnen Craft een testinzending aan (dus zonder captcha) en verstuurt de meldingen van het formulier.

Aanvraag:

```json
{
  "plugin": "formie",
  "form": "contact",
  "recipient": "tester@moqo.be",
  "delete": true,
  "values": { "email": "tester@moqo.be" }
}
```

- `recipient` (optioneel): als dit meegegeven is, gaan **alle** meldingen uitsluitend naar dit adres
  (To overschreven, CC en BCC leeg). Kan de plugin dat voor een formulier niet garanderen, dan weigert
  hij met 422 in plaats van toch naar de echte ontvangers te sturen.
- Zonder `recipient` gaan de meldingen naar de ingestelde ontvangers.
- `delete` (standaard `true`): verwijder de testinzending na het versturen.
- `values` (optioneel): waarden per veld-handle. Ontbrekende verplichte velden krijgen een herkenbare
  testwaarde ("Watchdog test", een geldig e-mailadres, …).
- Meldingen worden meteen verstuurd, niet via de wachtrij, zodat het resultaat in het antwoord staat.

Antwoord:

```json
{
  "ok": true,
  "submissionId": 412,
  "deleted": true,
  "recipientOverridden": true,
  "notifications": [
    { "name": "Melding naar beheerder", "success": true, "error": null, "to": "tester@moqo.be" }
  ]
}
```

Onbekend formulier: 404. Plugin niet geïnstalleerd: 422.

- `notifications[].to` is één tekst met alle adressen waar de mail heen ging (To, CC en BCC, door komma's
  gescheiden).
- Freeform verstuurt één mail per ontvanger; elke mail is een eigen regel in `notifications`.
- Met `recipient` worden taken die tijdens de test in de wachtrij komen niet uitgevoerd, zodat er later
  niets alsnog naar de echte ontvangers gaat.
- Testinzendingen slaan validatie, captcha, spamcontrole en integraties (CRM, mailinglijsten, webhooks) over.

## `GET failures`

Mails en meldingen die niet verstuurd konden worden.

Query: `since` (Unix-seconden, standaard 7 dagen terug), `limit` (standaard 100, maximaal 500).

```json
{
  "ok": true,
  "failures": [
    {
      "id": "formie:412",
      "source": "formie",
      "occurredAt": 1760000000,
      "form": "Contact",
      "subject": "Nieuwe inzending",
      "to": "info@voorbeeld.be",
      "error": "Connection could not be established with host smtp.voorbeeld.be",
      "submissionId": 398,
      "cpUrl": "https://voorbeeld.be/admin/formie/sent-notifications/edit/412"
    }
  ],
  "sources": {
    "formie": { "available": true, "note": null },
    "freeform": { "available": false, "note": "Niet geïnstalleerd" },
    "mailer": { "available": true, "note": null },
    "queue": { "available": true, "note": null }
  }
}
```

- `id` is stabiel en uniek binnen de site; de server ontdubbelt hierop. Dezelfde mislukking levert bij
  elke aanvraag hetzelfde `id` op.
- `source`:
  - `formie` / `freeform`: mislukte melding volgens het log van de plugin zelf.
  - `mailer`: elke mail die Craft's mailer niet kon versturen, ongeacht welke code hem verstuurde.
    De plugin houdt dit zelf bij in een eigen tabel.
  - `queue`: mislukte wachtrijtaken die mails versturen.
- Eén mislukte mail levert waar mogelijk één regel op: is dezelfde mislukking al via `formie` of
  `freeform` gemeld, dan komt ze niet nog eens als `mailer`.
- Gesorteerd van nieuw naar oud.
- Er komen nooit regels met `source: "freeform"`: een mislukte Freeform-mail staat onder `mailer`, met
  `form` en `submissionId` ingevuld.
- `form`, `subject`, `to`, `submissionId` en `cpUrl` kunnen `null` zijn.
- `queue` is een benadering: mislukte taken waarvan de klassenaam "mail" of "notification" bevat.
- Een mislukte `mail-test` verschijnt hier ook, als `mailer`-regel.
