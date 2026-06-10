<?php

namespace LedgerPilot;

/**
 * L1 of the engine (spec §4 Step 1): an exact counterparty-IBAN match in the corpus of approved
 * postings. The line's counterparty_iban_hmac (written by the keystone, read by JOIN from llx_bank —
 * never recomputed here) keys into llx_ledgerpilot_knowledge; the matching rows give the account(s)
 * that IBAN was historically posted to, each with a weight (observation count) and last_seen (recency).
 *
 * Split in two: resolve() is PURE and unit-tested (the decision); lookup() is Dolibarr-coupled (the
 * entity-scoped fetch) and verified by an integration spike.
 *
 * RANKING — the SHARED L1/L2 contract this class establishes: when an IBAN maps to several accounts,
 * pick the dominant one by weight desc, then last_seen desc, then account_number asc. The third key
 * makes the choice independent of DB row order; when AccountMatcher (L2) unparks its own tie-break it
 * must adopt all three levels so L1 and L2 break ties identically. L1 is a strong signal (exact IBAN)
 * and a human approves it in the Dashboard, so it suggests whenever there is any history rather than
 * abstaining on ties.
 */
final class IbanAccountLookup
{
	/**
	 * Choose the account for an IBAN from its knowledge rows, or null when there is no history.
	 *
	 * @param  array<int, array{account_number: string, weight: int, last_seen: string}> $rows
	 *         Knowledge rows for one counterparty_iban_hmac (last_seen as 'Y-m-d H:i:s', which sorts
	 *         lexically = chronologically).
	 * @return string|null The chosen account_number, or null if $rows is empty.
	 */
	public static function resolve(array $rows): ?string
	{
		if ($rows === []) {
			return null;
		}

		// weight desc, then last_seen desc (lexical = chronological for 'Y-m-d H:i:s'), then
		// account_number asc as the final deterministic key (so the result never depends on DB order).
		usort($rows, static fn (array $a, array $b): int =>
			($b['weight'] <=> $a['weight'])
			?: ($b['last_seen'] <=> $a['last_seen'])
			?: ($a['account_number'] <=> $b['account_number'])
		);

		return $rows[0]['account_number'];
	}

	/**
	 * Look up the L1 account for a line's counterparty IBAN hash, or null when there is no usable hash
	 * or no history.
	 *
	 * Dolibarr-coupled (verified by the spike, not unit tests). Scopes STRICTLY to $conf->entity — the
	 * knowledge corpus is PII and stays company-isolated, not getEntity() which a sharing config could
	 * silently widen (same posture as CandidateGenerator). Querying on counterparty_iban_hmac already
	 * selects only L1 rows (label rows have it NULL). $db->escape() closes SQL injection; note the hash
	 * is a keystone HMAC-SHA256 hex (char(64), [0-9a-f]) read from llx_bankimport_line_ref via the JOIN,
	 * NOT arbitrary user input — it matches the char(64) column byte-for-byte (no collation mismatch);
	 * never pass a raw label here. A query error is logged and yields null (fail-soft — the line falls
	 * through to L2 / manual).
	 *
	 * @param  \DoliDB     $db               Open Dolibarr database handle.
	 * @param  string|null $counterpartyHmac The line's counterparty_iban_hmac (keystone, via JOIN from
	 *                                       llx_bank); null/'' when the line carried no IBAN.
	 * @return string|null                   The L1 account_number, or null.
	 */
	public static function lookup(\DoliDB $db, ?string $counterpartyHmac): ?string
	{
		if ($counterpartyHmac === null || $counterpartyHmac === '') {
			return null;
		}

		global $conf;

		$sql = 'SELECT account_number, weight, last_seen'
			.' FROM '.MAIN_DB_PREFIX.'ledgerpilot_knowledge'
			.' WHERE entity = '.((int) $conf->entity)
			." AND counterparty_iban_hmac = '".$db->escape($counterpartyHmac)."'";

		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog('LedgerPilot IbanAccountLookup::lookup query failed: '.$db->lasterror(), LOG_ERR);

			return null;
		}

		$rows = [];
		while ($obj = $db->fetch_object($resql)) {
			$rows[] = [
				'account_number' => $obj->account_number,
				'weight'         => (int) $obj->weight,
				'last_seen'      => $obj->last_seen,
			];
		}
		$db->free($resql);

		return self::resolve($rows);
	}
}
