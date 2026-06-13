<?php

namespace LedgerPilot;

/**
 * Lifecycle statuses of a llx_ledgerpilot_proposal row, as PHP constants (the table stores status as a
 * varchar — spec §5). Introduced minimally here because PaymentCommitService / PaymentReversalService
 * need the terminal commit states; the proposal/queue cycle that OWNS this table will adopt this holder
 * and add the rest of the lifecycle (pending / rejected, and the abort→exception mapping the Dashboard
 * applies — see PaymentCommitService).
 */
final class ProposalStatus
{
	/** Approved by the accountant on the Dashboard — the only state commit() will act on. */
	public const APPROVED = 'approved';

	/** Terminal: commit() posted the payment (the idempotency anchor — approved→booked, first statement). */
	public const BOOKED = 'booked';

	/** Terminal: reverse() undid a booked commit (booked→reversed, the mirror guard). */
	public const REVERSED = 'reversed';
}
