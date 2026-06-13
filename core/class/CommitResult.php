<?php

namespace LedgerPilot;

/**
 * Outcome of a PaymentCommitService::commit() / PaymentReversalService::reverse() call, as PHP constants
 * (idiom: single-valued string verdicts like CommitDecision / InvoiceMatchDecision). The Dashboard reads
 * this to decide what to show and how to move the proposal's status; the service itself does not write the
 * abort→exception transition (that mapping belongs to the proposal/queue cycle).
 */
final class CommitResult
{
	/** The payment was posted (commit) — proposal is now booked. */
	public const COMMITTED = 'committed';

	/** Reversal undid a booked commit — proposal is now reversed. */
	public const REVERSED = 'reversed';

	/** Idempotent re-entry: the proposal was already in its terminal commit/reverse state. */
	public const ALREADY_DONE = 'already_done';

	/** Aborted: nothing left to pay (a concurrent payment settled it, or the line is already posted). */
	public const ABORTED_SETTLED = 'aborted_settled';

	/** Aborted: the amount would overpay the invoice — flag for human review. */
	public const ABORTED_OVERPAY = 'aborted_overpay';

	/** The proposal was not in the expected pre-state (e.g. commit on a non-approved row) — UI/logic error. */
	public const INVALID_STATE = 'invalid_state';

	/** A native call failed (or a guard like the sign check tripped); the tx was rolled back. */
	public const FAILED = 'failed';
}
