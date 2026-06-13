<?php

namespace LedgerPilot;

/**
 * Lifecycle statuses of a llx_ledgerpilot_proposal row, as PHP constants (the table stores status as a
 * varchar(16) — spec §5/§6). The commit/reversal cycle introduced the terminal states APPROVED/BOOKED/
 * REVERSED minimally; the queue/proposal cycle completes the lifecycle with the states the worker and the
 * Dashboard own. These constants are the single source every service and the Dashboard write through;
 * ProposalStatusTest pins the values so they cannot drift from the DDL.
 *
 * Lifecycle:
 *   PENDING ─approve→ APPROVED ─commit→ BOOKED ─reverse→ REVERSED
 *      │                  └──abort (settled / overpay)→ EXCEPTION
 *      └─reject→ REJECTED
 */
final class ProposalStatus
{
	/** Freshly suggested by the engine worker, awaiting the accountant's review (DDL default). */
	public const PENDING = 'pending';

	/** Approved by the accountant on the Dashboard — the only state commit() will act on. */
	public const APPROVED = 'approved';

	/** Declined by the accountant (a reject-without-alternative; it still feeds decision_log). */
	public const REJECTED = 'rejected';

	/** Terminal: commit() posted the payment (the idempotency anchor — approved→booked, first statement). */
	public const BOOKED = 'booked';

	/** Terminal: reverse() undid a booked commit (booked→reversed, the mirror guard). */
	public const REVERSED = 'reversed';

	/**
	 * A commit aborted (nothing left to pay, or it would overpay) → flagged for human review on the
	 * Dashboard, the engine is NOT re-queued (the money already arrived; the engine would re-propose the
	 * same thing). This is the abort→exception mapping PaymentCommitService deferred to this cycle (D-B).
	 */
	public const EXCEPTION = 'exception';
}
