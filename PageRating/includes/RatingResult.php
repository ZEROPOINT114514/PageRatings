<?php
/**
 * Value object returned from Store::calculateRating().
 *
 * @license MIT
 */

declare( strict_types = 1 );

namespace MediaWiki\Extension\PageRating;

/**
 * Immutable rating result for a single page.
 */
final class RatingResult {

	/** Average score in range [-1.0, +1.0]. */
	public float $score;

	/** Number of votes per bucket, keyed by the Store::VOTE_* constants. */
	public array $buckets;

	/** Total voters (incl. ZERO votes). */
	public int $total;

	public function __construct( float $score, array $buckets, int $total ) {
		$this->score = $score;
		$this->buckets = $buckets;
		$this->total = $total;
	}

	/**
	 * Counts the number of people who voted +1.
	 */
	public function positive1(): int {
		return $this->buckets[Store::VOTE_POS1] ?? 0;
	}

	public function positive05(): int {
		return $this->buckets[Store::VOTE_POS05] ?? 0;
	}

	public function zero(): int {
		return $this->buckets[Store::VOTE_ZERO] ?? 0;
	}

	public function negative05(): int {
		return $this->buckets[Store::VOTE_NEG05] ?? 0;
	}

	public function negative1(): int {
		return $this->buckets[Store::VOTE_NEG1] ?? 0;
	}

	public function formattedScore(): string {
		// Use 2 decimals (e.g. 0.85)
		$val = round( $this->score, 2 );
		return ( $val >= 0 ? '+' : '' ) . number_format( $val, 2 );
	}
}
