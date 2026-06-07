# Transaction categorization & posting module — consolidated spec + execution plan

> **Status:** pre-implementation. Design frozen after 5 review rounds + live-code verification
> against Dolibarr 23.0.3 (MariaDB). Remaining unknowns are **empirical** — to be settled by
> running spikes, not by more document review.
> **Last updated:** 2026-06-07.
> **Note on location:** moved to `ledgerpilot/docs/` on 2026-06-07 (after §13 step 4 — the module is
> now generated and has its own repo). The keystone work it describes lives in `bankimport`; the
> remaining engine work (steps 5-6) lives here in `ledgerpilot`.

---

## 0. Objective & module boundary

**Objective:** automatically link bank transactions to open invoices, and for the rest propose
an accounting account that the accountant approves. Every approval/correction feeds learning.
A human always approves.

**Boundary:** a **separate module** (sibling to the existing `bankimport`), consuming `llx_bank`
lines + native invoices. Integration via data, not via code coupling.
**Suggestion ≠ commit:** custom Dashboard for review, but posting goes through **native Dolibarr
objects** (never a raw INSERT). **Zero changes to core table schema.**

---

## 1. Decisions (resolved)

1. **v0.1 scope = keystone first** (side-table in `bankimport`, then the module).
2. **Revolut = separate account per currency** → line currency is recoverable from the account
   (`llx_bank_account`); no per-line `currency_code` needed. **This is a configuration boundary
   condition (1 account = 1 currency), NOT a property of the bank-agnostic engine (§10):** a bank that
   keeps several currencies on one account breaks the recovery → such a source must carry per-line
   currency in the keystone side-table (or stays v0.2). True multicurrency matching is rare → v0.2.
   *Edge:* one payment split across two accounts with auto-conversion (e.g. 200 EUR from the EUR
   account + remainder pulled from CHF) = N:1 cross-currency → **manual** in v0.1.
3. **Offline-eval = yes in v0.1** → 4 tables (incl. `decision_log`).
4. **Processor payouts = configurable processor→clearing-account mapping** (TWINT→TWINT clearing,
   card→card clearing…), keyed on recognizing the processor (IBAN/counterparty name) on the line.
   Mapping in config, not hardcoded.
5. **Own-transfer filter = yes in v0.1.**
6. **Cost centers (MPK/analytic accounting) = not used** → out of v0.1 scope (account only). The
   MPK axis stays forward-looking in the design; revisit when per-channel P&L is wanted (shop vs fairs).
7. **Shop = two channels:** WooCommerce (online, **not yet in Dolibarr** — separate integration
   track) + TakePOS (fairs, **native in Dolibarr**). Both → v0.2 clearing reconciliation.

---

## 2. Verified native facts (Dolibarr 23, from code — not memory)

**Tables / indexes**
- `llx_bank`: `amount` (in the bank account's currency) + `amount_main_currency` (company main
  currency, **not** the original). **No per-line currency column.**
- `llx_facture` indexes: `uk_facture_ref(ref,entity)`, `idx fk_soc`, `idx fk_statut`, `idx datef`.
  **No index on amount, none on `paye`.**
- `llx_facture_fourn`: `uk(ref,entity)`, `uk(ref_supplier,fk_soc,entity)`, `idx date_lim_reglement`,
  `idx fk_soc`. **No index on `fk_statut`, none on amount.**
- Open invoice = `fk_statut = 1 AND paye = 0`.

**Balance**
- `getRemainToPay($multicurrency=0)` in `core/class/commoninvoice.class.php:303`; `getSommePaiement:331`
  branches on `$this->element` (for `facture_fourn` → `paiementfourn_facturefourn`/`fk_facturefourn`).
  At `$multicurrency>0` uses `multicurrency_total_ttc`. Sales/purchase symmetry is real (shared ancestor).

**Commit (payment → bank)**
- `Paiement::addPaymentToBank()` (`compta/paiement/class/paiement.class.php:790`) creates a **NEW**
  `llx_bank` line (`new Account` + `addline`, l.812-854) → naive use would **duplicate** the imported
  line. Adds `bank_url` links: `'payment'/'payment_supplier'` (l.889) **and** `'company'` (l.~907-926).
  Sets `paiement.fk_bank` via `update_fk_bank` (l.873).
- `Paiement::create()` does **not** touch the bank (verified).
- **Correct commit path:** `Paiement::create()` + manual `Account::add_url_line()` on the **existing**
  line, recreating **both** links (payment + company).

**Supplier path (MIRROR, corrected)**
- `class PaiementFourn extends Paiement` (`fourn/class/paiementfourn.class.php:40`) → **inherits**
  `addPaymentToBank` (which handles `'payment_supplier'`). (An earlier "PaiementFourn has no
  addPaymentToBank" claim was a grep-absence on the child file, not a fact — the method lives in the
  parent.) Supplier commit = same shape as sales.
- `PaiementFourn` **overrides** `create()` (l.170, adds **currency validation** payment↔account,
  `new Account` l.232) and `delete()` (l.462, verified safe at `fk_bank=NULL`).
- Purchase `paye` revert = `FactureFournisseur::setUnpaid` (`fourn/class/fournisseur.facture.class.php:1705`).

**Reversal**
- `Paiement::delete()` takes the line from `$obj->fk_bank` (l.282) and deletes it (`$accline->delete()`,
  l.729) **only when `fk_bank>0`**. In our flow `fk_bank=NULL` (we skip `addPaymentToBank`) → block
  skipped → **line is safe**. But then `delete()` does not touch our manually-added links (they sit in
  the skipped `delete_urls`) and **does not revert `paye`** (no `set_unpaid` in l.760-786). Native guard:
  errors on a reconciled/rapprochée line (l.711). `Facture::setUnpaid` at `compta/facture/class/facture.class.php:3388`.

**Transactions**
- `DoliDB` has a depth counter `transaction_opened` (`core/db/DoliDB.class.php`): `begin()` increments,
  `commit()` only commits at `<=1`, **nested `rollback()` only decrements** (footgun → check return
  codes and roll back at the outermost level).

**`bankimport` import state (matching ceiling)**
- Original per-line currency: lost in `llx_bank` (`amount_main_currency=null`) but **recoverable from
  the account** (separate accounts per currency).
- **Structured QR/SCOR reference: not extracted at all** — no `RmtInf/Strd/CdtrRefInf` parsing.
- Counterparty IBAN: survives, but in the **free-text note** (not a joinable field).
- AcctSvcrRef: kept as `num_chq`. (`bankimport` already has its own dedup via `import_key`/`ImportKey`.)

---

## 3. KEYSTONE — side-table in `bankimport` (prerequisite for v0.1)

One problem ("how much of the CAMT survives import"), one fix: a **side-table keyed on `fk_bank`**,
populated by `bankimport`:

```
fk_bank → { structured_ref (QRR/SCOR), counterparty_iban_hmac, ... }
```

- Currency — not needed in the table (recoverable from the account).
- Unblocks: deterministic Step 0 by QR reference + own-transfer filter (clean IBAN) + later, tying
  split-payment legs together.
- Fits the principles (no core schema change, integration via data).
- **This is ingestion work (`bankimport`), not the new module** → it is the first code step.
- **Data protection:** store the IBAN as `HMAC-SHA256(IBAN, pepper)` (pepper outside the DB), not raw
  and not plain SHA256 (IBANs are enumerable/low-entropy). See §11.

*Invoice side, to settle in a spike:* Dolibarr builds the QR-bill reference in the PDF layer
(`pdf_crabe/sponge/octopus`, `modules_facture.php`) — likely computed at render time, not stored.
Goal is **invertibility**: can the invoice rowid/ref be decoded from the incoming reference (→ direct
lookup), rather than computing the reference for all open invoices?

---

## 4. Pipeline v0.1 (no LLM — that is v0.2)

**Pre-flight (once per batch):** one query → lightweight index of open invoices
(`rowid, ref, fk_soc, total_ttc`, filter `fk_statut=1 AND paye=0`), **without computing balance**.

**Side-table read contract (HARD requirement):** the engine MUST read `llx_bankimport_line_ref` by
joining **FROM `llx_bank`** (the work item is a bank line), **never FROM `line_ref`**. This guarantees a
stale side-table row (a deleted line not yet reaped — see §13.3) is never selected, so orphan rows are
correctness-inert and the reap is a §9-retention nicety, not a matching requirement.

**Per transaction (from the queue):**
- **Pre-filters:** own transfer (IBAN ∈ own accounts from `llx_bank_account`) → skip. Processor
  (IBAN/name ∈ map) → clearing account per config.
- **Step 0 (invoice):** direction CRDT→sales / DBIT→purchase (**heuristic, not deterministic** — a
  **supplier refund is CRDT** and a **customer refund is DBIT** → wrong lane; v0.1-acceptable because a
  misroute falls through to **manual**, but a known edge alongside N:1) → **structured QRR/SCOR
  reference** (most reliable key for CH; reverse-decode → direct lookup) → fallback ref-in-title→`facture.ref`
  (cross-check `fk_soc`) → fallback `fk_soc`+amount. Balance `getRemainToPay()` **only on 1-3
  candidates**. Commit per §6.
- **Step 1 (L1):** exact IBAN match in the history of approved invoice-less postings.
- **Step 2 (L2):** retriever candidate-gen→rerank (MariaDB FULLTEXT for generation, PHP rerank
  trigram/Jaccard on the normalized title); accept only when **top-K agree on the account AND
  similarity > threshold**.
- No resolution → **manual** (feeds the corpus).
- **Step 3 (L3, v0.2):** LLM — enum from the L2 shortlist + few-shot + an **"unknown"/abstain**
  option; pluggable provider (OpenAI-compatible → local Ollama), **off by default** (data protection).

---

## 5. Table schema (4 — because eval is in v0.1)

- **`queue`** — work queue: `fk_bank`, `status`, `lease_token`/`worker_id`, `claimed_at`,
  `attempts`→dead-letter; **UNIQUE(`fk_bank`)**; reaping of stuck rows; **ORDER BY in `UPDATE...LIMIT`**
  (MariaDB ordering is otherwise nondeterministic).
- **`proposals`** — ephemeral review workflow (pending/approved/rejected), deletable.
- **`knowledge`** — materialized **projection** of the corpus for L1/L2 (IBAN→account,
  normalized_label→account, source). Fast to query.
- **`decision_log`** — append-only event store (the source of truth): suggestion + candidate set +
  score + layer + accountant action (accept / correct-to-X / **reject-without-alternative**) → offline
  precision/recall. `knowledge` is re-derivable from this (re-run with a new normalizer/scorer without
  data loss).
  - *v0.1 pragmatic impl:* always write the log + update `knowledge` on accept. Full event-sourcing
    projection is an enhancement, not a blocker.

Thresholds (similarity, batch size, lease timeout) + processor→clearing map → in `llx_const`/config,
**not hardcoded**.

---

## 6. Commit & reversal — two spikes, each ×2 (customer + supplier)

**SPIKE #1 — commit without duplicate (`Paiement` AND `PaiementFourn`):**
- `create()` + `Account::add_url_line()` on the existing line, **both links** (payment + company; for
  purchase use `'payment_supplier'`), **NOT** `addPaymentToBank`.
- Whole operation in **one outer transaction**: guard-insert into our table **first** (fail-fast on
  retry), then `create()` + links, then `queue→DONE`, `commit()`. Check the return code of every native
  call; roll back at the outermost level.
- **Resolve in the spike:**
  - ✅ `fk_bank` strategy = **keep NULL** (resolved §12.1: ventilation reads `bank_url`, same journal
    for sales + purchase). Bookkeeping works and native `delete()` stays line-safe. Cosmetic only:
    payment card may show a blank bank account.
  - ✅ `PaiementFourn::create()` does not force `fk_account` at same-currency (resolved §12.5).
  - ✅ **Proven live on dev (2026-06-06, executable red→green spike `docs/spikes/spike1_commit.php`):**
    `create()` + `add_url_line` links to the existing line with **no duplicate** (Δ`llx_bank`=1). The
    naive `addPaymentToBank` control yields Δ=2 — the duplicate — with both links landing on it; that
    is the RED the test catches. Both flows pass 7/7 (sales `Paiement` + supplier `PaiementFourn`),
    `getRemainToPay()→0`. Supplier `create()` writes `fk_bank=0` (same effect as NULL). §12.1 (bank_url,
    not fk_bank) and §12.5 (currency validation passes same-currency) are now code-read **and**
    live-verified. **Scope = the no-duplicate mechanics only** — the one-outer-transaction +
    guard-insert/idempotency contract (next bullet) and the §7 atomicity test are deferred to the
    pipeline build, NOT covered by this spike.

**SPIKE #2 — reversal without deleting the line (`Paiement` AND `PaiementFourn`):**
✅ **Proven live on dev (2026-06-06, `docs/spikes/spike1_commit.php --phase=reverse`, both flows 7/7).**
Empirically-settled procedure — **ORDER MATTERS, this corrects the earlier "delete then setUnpaid" guess:**
1. **`setUnpaid()` FIRST.** Native `delete()` **refuses on a closed/paid invoice** — sales guard
   `f.fk_statut>1` (`ErrorDeletePaymentLinkedToAClosedInvoiceNotPossible`), supplier guard `paye=1`
   (`ErrorCantDeletePaymentSharedWithPayedInvoice`). Our commit had set the invoice paid (`setPaid` ⇒
   `fk_statut=CLOSED, paye=1`), so we must reopen before deleting. `setUnpaid()` has no precondition
   (§12.3): unconditionally `paye=0, fk_statut=VALIDATED`, clears `close_*`.
2. **`delete()`** on **our** payment (fetched fresh; `bank_line` = `fk_bank` = NULL/0) → the bank block
   is skipped → **the imported line survives** (R1 confirmed live). Removes `paiement(_fourn)` + the
   `…_facture` link.
3. **Manually remove our `bank_url` links** (`payment`/`payment_supplier` + `company`): `delete()` does
   NOT touch them — sales only runs `delete_urls()` when `bank_line>0`; `PaiementFourn::delete()` never
   calls `delete_urls()` at all. Skipping this orphans the links on the line (the RED the test catches).
4. **Multi-payment case:** after delete, if remaining payments still fully cover the invoice, re-close
   with `setPaid()`; for the v0.1 single-payment reversal there are none → leave it open. Purchase uses
   `FactureFournisseur::setUnpaid`.

---

## 7. Concurrency / guards / atomicity

- **Two distinct races, two guards:** UNIQUE(`fk_bank`) = same line twice (idempotency); FOR UPDATE on
  the invoice + balance recheck = two different lines overpaying one invoice (Dolibarr **allows**
  overpayment).
- **MVCC pitfall:** under default MariaDB REPEATABLE READ a balance recheck reads the snapshot from
  before the lock (read-view fixed at the first plain SELECT / `fetch()`). Two valid fixes:
  **locking-read-first works** (`SELECT ... FOR UPDATE` as the *first* statement, with no plain SELECT
  before it, so the read-view forms after the competitor's commit), but it is order-sensitive; **READ
  COMMITTED** for the guarded block is the most foolproof (every statement sees the last commit; note
  `SET TRANSACTION ISOLATION` is top-level only — fits the single outer transaction). `GET_LOCK` is
  advisory → does not protect against the native UI creating a payment, so prefer row-lock / READ COMMITTED.
- **Residual:** FOR UPDATE on `llx_facture` serializes against the native UI only if that path
  locks/UPDATEs the invoice row; a **partial** payment may not touch the row → its INSERT is not
  serialized. Low probability + reversible (visible overpayment) → document as a known risk.
  **Resolved (§12.2, code-read): `Paiement::create()` does NOT lock/UPDATE the invoice row** (only a
  `getBillsArray` plain SELECT) → the residual race is real; mitigate inside the guarded block (READ
  COMMITTED + balance recheck), do **not** rely on `create()` to serialize.
- **Atomicity test:** force a mid-transaction error and **assert DB state** (nested rollback is a footgun).

---

## 8. Cross-cutting principles (always-on)

- **TDD** — the core (normalizer, retriever, matcher, planners) as **pure classes in `core/class/`**,
  testable without a live Dolibarr (like FeeSplitter/EntryPlan). **Two test regimes, do not conflate:**
  the **spikes** are throwaway *integration* tests on the live dev DB (they must hit native commit/
  reversal, so they mutate real records and self-teardown — `docs/spikes/`); the **production engine**
  is **pure-class PHPUnit** (no live Dolibarr). "Test-first / red→green" applies to both, but only the
  PHPUnit suite is durable — the spikes are deleted once their findings land in this spec.
- **Native-first / reuse** — `getRemainToPay()`, native commit/reversal, existing `bankimport`
  reconciliation.
- **Capture corrections** — an accountant's correction is a full training sample (more valuable than an
  approval).
- **Bootstrap the corpus from history** of posted entries (kills cold-start) — with recency weighting
  AND validation that the account is still active in `llx_accounting_account`. (Bootstrap inherits the
  free-text-IBAN problem → regex CH+19, lower confidence.)
- `entity` filter on all queries; CSRF + rights + escape; `reason` as an enum; `ref` from the numbering
  mask (conditionally — `FACTURE_ADDON` selects the model, the mask is a separate const, sequential
  models have no mask).

---

## 9. Data protection (corpus, not only the LLM)

The persistent, growing PII surface is `knowledge` + `decision_log` (counterparty IBANs, titles that
may contain names), not just L3. We are in CH+EU → **revDSG (Sept 2023)** alongside GDPR.

- **Pseudonymize IBANs:** `HMAC-SHA256(IBAN, pepper)` with the pepper outside the DB (plain hashes are
  brute-forceable — IBANs are enumerable). HMAC supports exact-match L1 and the transfer filter.
- **Erasure is not solved by hashing:** a key holder can re-identify an enumerable identifier, so real
  deletion needs a purge or key rotation, not just a deletable mapping. **This is a DPO/lawyer call**,
  not "hash and done."
- Retention policy + `rights`-based access control on these tables.

---

## 10. Generalization (multi-bank, TWINT, shop)

- **Engine is bank-agnostic** (consumes `llx_bank`) → PostFinance and others work without engine
  changes (verify only the per-bank import).
- **Aggregated TWINT/PayPal/Stripe** = class 1:N → v0.1 to a **clearing account** (processor→clearing
  map). Approach is **source-agnostic**.
- **WooCommerce (online):** not in Dolibarr → separate integration track (API > manual CSV; evaluate
  existing bridges first). Clearing reconciliation via Woo data = **v0.2**.
- **TakePOS (fairs, TWINT):** native in Dolibarr → payouts reconcile against native records (verify
  which account TakePOS books TWINT to) = **v0.2**.
- **Multi-account** → the own-transfer filter is needed from the start (it is in v0.1).

---

## 11. Deferred to v0.2+

LLM/L3 · full multicurrency (FX, split-payment netting) · 1:N / N:1 payments · transfer pairing
(netting the two legs) · clearing-account reconciliation (Woo + TakePOS) · WooCommerce↔Dolibarr
integration (separate track) · cost centers/MPK (when per-channel P&L is wanted).

---

## 12. Open empirical items — status

**Resolved by code-reading (2026-06-06, no dev mutation):**
1. ✅ Accounting ventilation reads the bank via `bank_url`, **not** `paiement.fk_bank`
   (`accountancy/journal/bankjournal.php:168-176` + `get_url()`; same file for sales `payment` and
   purchase `payment_supplier`). → **Decision: keep `paiement.fk_bank = NULL`** — bookkeeping still
   works (reads our bank_url links) AND native `delete()` stays line-safe. (Cosmetic only: the payment
   card may show a blank bank account; confirm the rapprochement view.)
2. ✅ Native `Paiement::create()` does **not** lock/UPDATE the invoice row (only a `getBillsArray`
   plain SELECT). → the partial-payment overpayment race is not serialized by our `FOR UPDATE`; real
   but low-probability + reversible → documented residual risk (§7).
3. ✅ `setUnpaid()` (sales `facture:3388`, purchase `fournisseur.facture:1705`) has **no precondition**
   — it unconditionally sets `paye=0, fk_statut=VALIDATED` + trigger. → **reversal order (corrected and
   live-proven by SPIKE #2, see §6): `setUnpaid()` FIRST.** Native `delete()` refuses on a closed/paid
   invoice, so we must reopen *before* deleting; the multi-payment "re-close if still fully covered" step
   is a `setPaid()` **after** delete, not a conditional `setUnpaid()` before it. *(Supersedes the earlier
   "delete → conditional setUnpaid" wording.)*
5. ✅ `PaiementFourn::create()` currency validation (and its `fk_account` fetch) is gated by
   `if (!empty($currencyofpayment))` (multicurrency only). → same-currency v0.1 does **not** force
   `fk_account` → no tension with the NULL-`fk_bank` strategy (re-examine at multicurrency / v0.2).

**Resolved by code-reading (2026-06-06, `commoninvoice.class.php::buildSwitzerlandQRString` l.2176):**
4. ✅ QR-bill reference composition. **Native Dolibarr emits reference type `NON`** (hardcoded l.2267,
   reference field always empty l.2268) — it does **not** generate a structured QRR/SCOR on its own
   sales QR-bills. The invoice identity travels only in the **Swico S1 billing-info free text**
   (`//S1/10/<ref>/11/<date>…`, l.2201 — `/10/` = `str_replace('/','',$this->ref)`). It is **computed
   at PDF render time, not stored** (no `ref_ext`/column; own code, no vendored lib). → **Invertibility:**
   - **Sales (CRDT):** no QRR to decode — recover `facture.ref` from the Swico `/10/` token (arrives in
     CAMT `RmtInf/Strd/AddtlRmtInf` or `Ustrd`) → direct lookup via `uk_facture_ref`. Trivially
     invertible, no need to compute references for all open invoices.
   - **Purchase / third-party (DBIT):** a *foreign* issuer may use a real QRR/SCOR → arrives as
     `RmtInf/Strd/CdtrRefInf/Ref`; that is the structured key to parse.
   → **Keystone must extract BOTH** from `RmtInf`: structured `CdtrRefInf` (QRR/SCOR) **and**
   `AddtlRmtInf`/`Ustrd` (Swico `/10/`). *(Side note: a rarely-used stored `payment_reference` field
   exists but the default QR path ignores it.)*

---

## 13. Execution plan (ordered)

> Rationale: keystone is first by dependency, but SPIKE #1 is cheaper and unblocks the biggest
> uncertainty (the whole commit/reversal path). Do the spike first to confirm the foundation before
> investing in CAMT extraction.

1. ✅ **SPIKE #1 (commit) — mechanics DONE (2026-06-06):** proved `create()` + `add_url_line` links to an
   existing line **without a duplicate** (live red→green, both flows 7/7); #1/#5 live-verified,
   #2 code-read. Artifact: `docs/spikes/spike1_commit.php`. **Scope = the no-duplicate mechanics only.**
   The §6-step-2 transaction contract (one outer tx, guard-insert-first / idempotency, queue→DONE,
   per-call return-code checks, outermost rollback) and the whole of §7 (UNIQUE / FOR UPDATE, MVCC /
   READ COMMITTED, the atomicity test) are **not** exercised by the spike — they belong to the pipeline
   build (step 6). Throwaway script, no new module needed.
2. ✅ **SPIKE #2 (reversal) — DONE (2026-06-06):** native `delete()` at `fk_bank=NULL/0` is line-safe
   (R1 live); procedure proven = **`setUnpaid()` → `delete()` → manual `bank_url` cleanup** (order
   corrected: delete refuses while closed); both flows 7/7. Artifact: same script `--phase=reverse`.
3. 🟢 **Keystone in `bankimport` — INGESTION + cleanup-trigger DONE (2026-06-07):** side-table `llx_bankimport_line_ref` (fk_bank PK)
   + pure `RemittanceRef` (QRR/SCOR + Swico `/10/` token) + pure `IbanPseudonymizer` (HMAC, pepper from
   conf.php) + wired into the import via `EntryPlan` (`line_ref`) and `BankImport::writeLineRef`
   (best-effort). #4 resolved (§12.4). Unit suite 75/75; wiring integration-verified
   (`docs/spikes/keystone_wiring_check.php`). **Operational:** set
   `$dolibarr_main_bankimport_iban_pepper` in `conf.php` (outside the DB, §9) to enable IBAN matching —
   until then the structured keys are still stored but `counterparty_iban_hmac` stays NULL (a warning is
   logged; an admin banner is a small follow-up). Pepper is **write-once** in v0.1 — rotation rebuilds
   the corpus (DPO matter).
   **Cleanup status:** *(a)* **orphan cleanup — trigger DONE (2026-06-07):**
   `interface_99_modBankImport_LineRef` deletes the `line_ref` row on `BANKACCOUNTLINE_DELETE` (descriptor
   declares `module_parts['triggers']`; integration-verified, `docs/spikes/keystone_trigger_check.php`).
   Covers native UI deletion; the SPIKE #2 reversal keeps the line (`fk_bank=NULL`), so it never orphans.
   *Optional backstop (deferred — §9 retention only, not correctness):* a periodic reap
   (`DELETE … WHERE fk_bank NOT IN (SELECT rowid FROM llx_bank)`) for deletion paths that bypass the
   trigger (`$notrigger=1` / raw SQL) — matters for IBAN-HMAC retention, since orphans are otherwise
   inert (§4 engine contract). **Deploy:** existing installs must re-activate the module to write
   `MAIN_MODULE_BANKIMPORT_TRIGGERS` (fresh activation is automatic). *(b)* **raw IBAN still in
   `note_private`** — the import keeps writing `CounterpartyIBAN=<raw>` to the note (pre-existing), so
   the HMAC protects only the side-table; full IBAN protection needs the note addressed too (§9, v0.2).
4. 🟢 **New module generated + leaned — DONE (2026-06-07):** `ledgerpilot` scaffolded via Module
   Builder (id 500000, `modLedgerPilot`), then reduced to a hand-maintained lean module: descriptor
   stripped of every MODULEBUILDER example block (family `financial`, internal-only top menu, lean
   `init()`/`remove()`, **no hard dependency on `bankimport`** — data-only integration, so it loads
   even when bankimport is off), CRUD boilerplate removed, `.tx/` + `modulebuilder.txt` dropped,
   GPL-3.0. Own git repo (github.com/pofr2026/ledgerpilot, public). Activated clean on dev `:8080`
   (no errors, module-scan intact). Folder wired into `bankimport.code-workspace` (PHPUnit config
   moved to per-folder `.vscode/settings.json` so the Docker working dir is scoped per module).
5. **Core engine** (TDD, pure classes in the new module's `core/class/`): normalizer, retriever
   (candidate-gen→rerank), matcher, planners; the 4 tables; processor→clearing config; own-transfer
   filter.
6. **Pipeline + queue (cron)** + commit/reversal using the spikes' confirmed patterns + the review
   Dashboard + corpus bootstrap.

**Environment notes:** module is bind-mounted in `custom/` (no ZIP installer). PHPUnit runs in Docker;
settings live in the `.code-workspace` (multi-root: `bankimport` + the new module, same profile).
Keep the new module's descriptor lean — lazy-load any vendor deps (avoid the "all modules disappeared"
failure mode).
