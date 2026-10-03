# Transferts bancaires et traitement manuel

Ce document décrit les transferts **Mobile Money** et **bancaires**, le routage
**AUTOMATIC / MANUAL**, et le traitement manuel par les **agents**. La doc OpenAPI publique
(Scramble, `/docs/api`) couvre l'API marchande `/v1/gateway/*` (virement bancaire inclus, voir plus bas) ; les routes
client mobile, agent et admin sont documentées ici.

Toutes les routes existent sous `/api/...` et `/api/v1/...`. Authentification : Sanctum (`Bearer`).

## Workflow

```
USER → CREATE TRANSFER → ROUTING (TransferRoutingService)
                          │
          ┌───────────────┴────────────────┐
      AUTOMATIC                          MANUAL
          │                                 │
   ProcessTransferJob               pending_manual_review ← file des agents
          │ (Digitwave)                     │ claim
   processing → success|failed          assigned
                                            │ start
                                        processing
                                  ┌─────────┼──────────┐
                              complete    reject      fail
                              (success)  (rejected)  (failed)
                                          └─ remboursement du wallet ─┘
   Le client peut annuler (cancelled, remboursé) tant que le statut est pending_manual_review.
```

Statuts : `pending`, `pending_manual_review`, `assigned`, `processing`, `success` (= COMPLETED),
`rejected`, `failed`, `cancelled` (+ `reversed` historique). Les valeurs historiques en minuscules
sont conservées pour ne pas casser le dashboard et l'app Flutter.

## Routage

Configuré par l'admin dans `country_services` (un enregistrement par pays et service) :

| Service du pays | Provider | Résultat |
|---|---|---|
| `INACTIVE` | — | transfert refusé (`SERVICE_UNAVAILABLE`) |
| `MANUAL` | — | `MANUAL` |
| `ACTIVE` | actif, supportant le service, implémenté (`config/transfers.php`) | `AUTOMATIC` |
| `ACTIVE` | aucun, inactif ou non implémenté | `MANUAL` |
| *non configuré*, Mobile Money | — | historique : `AUTOMATIC` via Digitwave, sauf si le provider `digitwave` est désactivé (alors `MANUAL`) |
| *non configuré*, virement bancaire | — | refusé |

Retrait et dépôt n'ont pas de traitement manuel : ils sont refusés (`SERVICE_UNAVAILABLE`) quand le pays est INACTIVE ou MANUAL, ou quand Digitwave est désactivé.

DigiWave n'est **jamais** appelé pour un transfert `MANUAL` (routage + garde dans `ProcessTransferJob`).
Les transferts sandbox ne sont jamais envoyés aux agents.

## Argent

Le wallet est débité à la création (`montant + frais`) : ce débit tient lieu de réservation.
Complétion = les fonds sont consommés. Rejet, échec ou annulation = remboursement unique
(`TransactionStatusUpdater::refundWallet`, sur ligne verrouillée). Pas de second système financier.

Frais : table `fee_rules` (pays, service, provider, devise, tranche). La règle la plus spécifique
l'emporte ; sans règle, le virement bancaire est refusé (`FEE_NOT_CONFIGURED`). Limites : `min_amount`,
`max_amount`, `daily_limit`, `monthly_limit` de `country_services`.

## Idempotence

En-tête `Idempotency-Key` (100 caractères max, vide = ignorée) sur `POST /transfer` et `POST /bank-transfer`. `/withdrawal` et `/deposit` gardent le verrou anti-doublon de 15 s (la clé n'y est pas utilisée). Stockée en base
(`transactions.idempotency_key`, unique par utilisateur) : rejouer la même clé renvoie le transfert
existant sans nouveau débit ; la même clé avec un autre montant/bénéficiaire est refusée.

## API client

| Méthode | Route | Description |
|---|---|---|
| POST | `/transfer` | Transfert Mobile Money (réponse : `processing_mode`, `transfer_status`) |
| GET | `/bank-transfer/countries` | Pays où le virement est activé (ACTIVE ou MANUAL), avec devise et champs obligatoires |
| GET | `/bank-transfer/requirements?country=XX` | Champs bancaires obligatoires d'un pays |
| POST | `/bank-transfer` | Virement bancaire `{country, amount, pin, beneficiary{...}}` |
| GET | `/transfers`, `/transfers/{id}` | Mes transferts |
| POST | `/transfers/{id}/cancel` | Annuler (manuel, pas encore pris en charge) |
| GET | `/notifications`, POST `/notifications/{id}/read` | Notifications |

Bénéficiaire bancaire : `full_name, phone, email, bank_name, bank_code, branch_code, account_number,
iban, swift_bic, address, city`. L'IBAN n'est pas obligatoire par défaut ; l'obligation de chaque champ
se configure par pays (`PUT /admin/countries/{id}/bank-fields`).

## API marchande (clé API) — documentée dans /docs/api (OpenAPI)

| Méthode | Route `/v1/gateway/...` | Scope | Description |
|---|---|---|---|
| GET | `/bank-countries` | `countries.read` | Pays ouverts au virement + champs obligatoires |
| POST | `/bank-transfers` | `bank_transfer.write` | Virement bancaire (en-tête `Idempotency-Key` obligatoire) |
| GET | `/transactions/{reference}` | `transactions.read` | Suivi (statuts `pending_manual_review` → `assigned` → `processing` → `success`, ou `rejected` / `failed` remboursés) |

Les clés API existantes n'ont pas le scope `bank_transfer.write` : il faut en créer une nouvelle (portail marchand).
Sandbox : le résultat se pilote par la fin de `beneficiary.account_number` (`0002` échec remboursé, `0003` reste en cours).
Un transfert Mobile Money marchand en production peut aussi être routé en manuel : `processing_mode` et les nouveaux statuts apparaissent alors dans les réponses.

## API publique

`GET /public/coverage` (sans authentification, 60 req/min, cache 5 min) : pays actifs et services ouverts (`MOBILE_MONEY`, `BANK_TRANSFER`) avec devise, drapeau et noms d'opérateurs. Alimente la section « Couverture pays » de la page d'accueil. N'expose ni provider, ni mode de traitement, ni frais, ni limites.

## API agent (`role = agent`)

Connexion de la console web : `POST /agent/auth/login {phone, password}` (jeton réservé aux routes `/agent/*` ; le login admin refuse les agents), `POST /agent/auth/logout`.

| Méthode | Route | Description |
|---|---|---|
| GET | `/agent/transfers` | File (`status`, `mine=1`, `service`) — défaut `pending_manual_review` |
| GET | `/agent/transfers/{id}` | Détail, preuves, historique |
| POST | `/agent/transfers/{id}/claim` | Prendre en charge → `assigned` |
| POST | `/agent/transfers/{id}/start` | Commencer → `processing` |
| POST | `/agent/transfers/{id}/release` | Rendre un transfert `assigned` pas commencé → `pending_manual_review` |
| POST | `/agent/transfers/{id}/proof` | `proof` : PDF/JPG/JPEG/PNG, 5 Mo max |
| POST | `/agent/transfers/{id}/complete` | `provider_reference`, `transaction_reference`, `comment` → `success` (au moins une preuve requise) |
| POST | `/agent/transfers/{id}/reject` | `reason` → `rejected` + remboursement |
| POST | `/agent/transfers/{id}/fail` | `reason` → `failed` + remboursement |

Suspendre un agent remet ses transferts `assigned` dans la file ; ceux en `processing` restent à son nom et sont signalés (`processing_transfers`) pour que l'admin décide via `release`.

Un agent ne traite que les transferts qui lui sont assignés ; il n'a accès à aucune route de
configuration. Transition impossible : `409` avec `error_code` (`INVALID_TRANSFER_STATE`,
`NOT_ASSIGNED_TO_AGENT`, `PROOF_REQUIRED`...). Les preuves sont stockées sur un disque privé
(`TRANSFER_PROOF_DISK`, `local` par défaut, `s3` possible) ; `TRANSFER_PROOF_REQUIRED=false` rend la
preuve facultative.

## API admin (`admin` / `superadmin`)

| Route | Description |
|---|---|
| `GET/POST/PUT /admin/providers` | Providers (`active` pour activer/désactiver) |
| `GET/POST/PUT /admin/country-services` | Services par pays : statut, provider, limites |
| `GET/POST/PUT /admin/fee-rules` | Frais |
| `GET/PUT /admin/countries/{id}/bank-fields` | Champs bancaires obligatoires |
| `GET/POST/PUT /admin/agents` | Comptes agents (création, suspension, mot de passe) |
| `GET /admin/transfers`, `/admin/transfers/{id}` | Supervision, avec journal d'audit |
| `GET /admin/manual-transfers` | File manuelle |
| `POST /admin/transfers/{id}/release` | `reason` obligatoire : remet un transfert `assigned` ou `processing` bloqué dans la file (fonds toujours réservés) |
| `/admin/countries`, `/admin/operators` | Existants (pays, opérateurs) |

## Audit et événements

`transfer_audit_logs` : append-only (le modèle interdit modification et suppression). Actions :
`TRANSFER_CREATED`, `TRANSFER_ASSIGNED`, `TRANSFER_STARTED`, `TRANSFER_COMPLETED`,
`TRANSFER_REJECTED`, `TRANSFER_FAILED`, `TRANSFER_CANCELLED`, `PROOF_UPLOADED`. Écrit dans la même
transaction que le changement de statut. Les événements `TransferCreated/Assigned/Processing/
Completed/Rejected/Failed` sont dispatchés après commit ; leurs listeners (en queue) notifient le
client et, pour un nouveau transfert manuel, les agents.

## Mise en route

```bash
php artisan migrate
php artisan db:seed --class=ProviderSeeder   # enregistre Digitwave (interrupteur admin)
```

Puis, côté admin : activer les services par pays (`country-services`), configurer les frais
(`fee-rules`) et créer les agents (`agents`). Un worker de queue doit tourner pour les notifications.

## Frontends

- **digit-dashboard (Next.js)** : pages admin `Transferts manuels`, `Agents`, `Services par pays` (statut, provider, plafonds, champs bancaires), `Providers`, `Frais de transfert` ; console agent sur `/agent/login` et `/agent/dashboard` (jeton `agent_auth_token`, distinct des sessions admin et marchand).
- **digit_app (Flutter)** : écran *Virement bancaire* (champs selon le pays), transferts manuels (reçu « en attente de prise en charge » au lieu de l'attente temps réel), statuts `assigned` / `rejected` / `cancelled`, annulation depuis le détail d'un transfert, écran *Notifications* (cloche sur l'accueil). Les envois portent un `Idempotency-Key`.
