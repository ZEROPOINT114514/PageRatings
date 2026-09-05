<?php
/**
 * PageRating - Data access layer.
 *
 * All database operations for votes and the page registry go through here.
 * This is the only class allowed to touch the DB; the rest of the extension
 * delegates to it via the DI service container (see ServiceWiring.php).
 *
 * @license MIT
 */

declare( strict_types = 1 );

namespace MediaWiki\Extension\PageRating;

use InvalidArgumentException;
use MediaWiki\Extension\PageRating\RatingResult;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;

class Store {

	/** Allowed raw vote values. */
	public const VOTE_CANCEL = -100;
	public const VOTE_NEG1 = -2;     // -1.0
	public const VOTE_NEG05 = -1;    // -0.5
	public const VOTE_ZERO = 0;      //  0
	public const VOTE_POS05 = 1;     // +0.5
	public const VOTE_POS1 = 2;      // +1.0

	/** Default rating group: page uses the base {{投票}} template. */
	public const GROUP_DEFAULT = '';

	private const VALID_VALUES = [
		self::VOTE_NEG1, self::VOTE_NEG05, self::VOTE_ZERO,
		self::VOTE_POS05, self::VOTE_POS1,
	];

	/** @var IConnectionProvider */
	private $connectionProvider;

	public function __construct( IConnectionProvider $connectionProvider ) {
		$this->connectionProvider = $connectionProvider;
	}

	/**
	 * Get a database connection in the appropriate mode.
	 *
	 * MediaWiki 1.42+ recommends IConnectionProvider for all database
	 * access; the old ILoadBalancer/ILoadBalancerFactory services are
	 * deprecated and their getConnection() methods are going away.
	 *
	 * @param int $mode DB_REPLICA or DB_PRIMARY
	 * @return IDatabase
	 */
	private function getDB( int $mode = DB_REPLICA ): IDatabase {
		if ( $mode === DB_PRIMARY ) {
			return $this->connectionProvider->getPrimaryDatabase();
		}
		return $this->connectionProvider->getReplicaDatabase();
	}

	// ------------------------------------------------------------------
	// Page registry (driven by {{投票}} template presence)
	// ------------------------------------------------------------------

	/**
	 * Register (or refresh) a page in the rating registry.
	 * Called by the ArticleSaveComplete hook.
	 */
	/**
	 * Register (or refresh) a page in the rating registry.
	 * Called by the save hook and by the widget renderer.
	 *
	 * @param int    $pageId
	 * @param int    $namespace
	 * @param string $title     page DB key
	 * @param string $group     rating group ('' = base template, 'X' = 投票/X sub-template)
	 */
	public function registerPage( int $pageId, int $namespace, string $title, string $group = self::GROUP_DEFAULT ): void {
		$db = $this->getDB( DB_PRIMARY );
		$now = wfTimestampNow();
		$db->upsert(
			'page_rating_pages',
			[
				'page_id'        => $pageId,
				'page_namespace' => $namespace,
				'page_title'     => $title,
				'pr_group'       => $group,
				'registered_at'  => $now,
				'last_vote_at'   => '',
				'vote_count'     => 0,
			],
			[ [ 'page_id' ] ],
			[
				'page_namespace' => $namespace,
				'page_title'     => $title,
				'pr_group'       => $group,
				'registered_at'  => $now,
			]
		);
	}

	/**
	 * Unregister a page (the {{投票}} template was removed).
	 * NOTE: We only remove the registry row. Vote history rows remain
	 * for audit, but stop being included in ratings.
	 */
	public function unregisterPage( int $pageId ): void {
		$db = $this->getDB( DB_PRIMARY );
		$db->delete( 'page_rating_pages', [ 'page_id' => $pageId ], __METHOD__ );
	}

	public function isPageRegistered( int $pageId ): bool {
		$db = $this->getDB( DB_REPLICA );
		$count = $db->selectRowCount(
			'page_rating_pages',
			'*',
			[ 'page_id' => $pageId ],
			__METHOD__
		);
		return $count > 0;
	}

	/**
	 * Rating group currently stored for a page ('' when not registered).
	 */
	public function getGroup( int $pageId ): string {
		$db = $this->getDB( DB_REPLICA );
		$row = $db->selectRow(
			'page_rating_pages',
			[ 'pr_group' ],
			[ 'page_id' => $pageId ],
			__METHOD__
		);
		return $row ? (string)$row->pr_group : '';
	}

	/**
	 * Groups present in the registry (excluding the default '' group).
	 *
	 * @return string[]
	 */
	public function getUsedGroups(): array {
		$db = $this->getDB( DB_REPLICA );
		$groups = $db->selectFieldValues(
			'page_rating_pages',
			'DISTINCT pr_group',
			'pr_group <> ' . $db->addQuotes( self::GROUP_DEFAULT ),
			__METHOD__
		);
		return array_values( array_filter( array_map( 'strval', $groups ) ) );
	}

	/**
	 * Groups derived from existing 投票 sub-templates
	 * (Template:投票/Crossover → 'Crossover').
	 *
	 * @param string[] $baseTexts base template page texts, e.g. [ '投票', 'Vote' ]
	 * @return string[]
	 */
	public function getTemplateSubpageGroups( array $baseTexts ): array {
		$db = $this->getDB( DB_REPLICA );
		$titles = $db->selectFieldValues(
			'page',
			'page_title',
			[ 'page_namespace' => NS_TEMPLATE ],
			__METHOD__
		);
		$groups = [];
		foreach ( $titles as $title ) {
			foreach ( $baseTexts as $base ) {
				$prefix = $base . '/';
				if ( str_starts_with( strtolower( $title ), strtolower( $prefix ) ) ) {
					$groups[] = substr( $title, strlen( $prefix ) );
				}
			}
		}
		return array_values( array_unique( $groups ) );
	}

	// ------------------------------------------------------------------
	// Voting lock (锁票)
	// ------------------------------------------------------------------

	/**
	 * Lock or unlock voting for a single page.
	 */
	public function setPageLocked( int $pageId, bool $locked ): void {
		$db = $this->getDB( DB_PRIMARY );
		$db->update(
			'page_rating_pages',
			[ 'pr_locked' => $locked ? 1 : 0 ],
			[ 'page_id' => $pageId ],
			__METHOD__
		);
	}

	/**
	 * Whether voting is locked for a page.
	 */
	public function isPageLocked( int $pageId ): bool {
		$db = $this->getDB( DB_REPLICA );
		$row = $db->selectRow(
			'page_rating_pages',
			[ 'pr_locked' ],
			[ 'page_id' => $pageId ],
			__METHOD__
		);
		return $row ? ( (int)$row->pr_locked === 1 ) : false;
	}

	/**
	 * Lock (or unlock) every page in a rating group.
	 *
	 * @param string $group rating group key ('' = base group, 'X' = sub-template)
	 * @return int number of affected rows
	 */
	public function setGroupLocked( string $group, bool $locked ): int {
		$db = $this->getDB( DB_PRIMARY );
		$db->update(
			'page_rating_pages',
			[ 'pr_locked' => $locked ? 1 : 0 ],
			[ 'pr_group' => $group ],
			__METHOD__
		);
		return $db->affectedRows();
	}

	/**
	 * Count locked pages in a group (for informational display).
	 */
	public function countGroupPages( string $group ): int {
		$db = $this->getDB( DB_REPLICA );
		return (int)$db->selectRowCount(
			'page_rating_pages',
			'*',
			[ 'pr_group' => $group ],
			__METHOD__
		);
	}

	/**
	 * Count locked pages in a group.
	 */
	public function countLockedGroupPages( string $group ): int {
		$db = $this->getDB( DB_REPLICA );
		return (int)$db->selectRowCount(
			'page_rating_pages',
			'*',
			[ 'pr_group' => $group, 'pr_locked' => 1 ],
			__METHOD__
		);
	}

	/**
	 * Search registered pages by title substring.
	 *
	 * @param string $query  empty = list all (capped at $limit)
	 * @param int    $limit
	 * @return array<int, array{id:int,ns:int,title:string,group:string,locked:bool}>
	 */
	public function searchRegisteredPages( string $query, int $limit = 200 ): array {
		$db = $this->getDB( DB_REPLICA );
		$conds = [];
		if ( $query !== '' ) {
			// buildLike() returns a COMPLETE LIKE clause already — it includes
			// the LIKE keyword and an ESCAPE, e.g. `LIKE '%旧夏未落%' ESCAPE '`'`.
			// So we must only prefix the column name, NOT another `LIKE` keyword
			// (which would produce `page_title LIKE LIKE …`), and we must append
			// it as a raw condition ($conds[]) rather than $conds['page_title']
			// (which would emit `page_title = LIKE …` — an equality that never
			// matches anything).
			$conds[] = 'page_title ' . $db->buildLike( $db->anyString(), $query, $db->anyString() );
		}
		$rows = $db->select(
			'page_rating_pages',
			[ 'page_id', 'page_namespace', 'page_title', 'pr_group', 'pr_locked' ],
			$conds,
			__METHOD__,
			[
				'ORDER BY' => [ 'page_namespace', 'page_title' ],
				'LIMIT'    => $limit,
			]
		);
		$out = [];
		foreach ( $rows as $row ) {
			$out[] = [
				'id'     => (int)$row->page_id,
				'ns'     => (int)$row->page_namespace,
				'title'  => (string)$row->page_title,
				'group'  => (string)$row->pr_group,
				'locked' => ( (int)$row->pr_locked === 1 ),
			];
		}
		return $out;
	}

	// ------------------------------------------------------------------
	// Voting
	// ------------------------------------------------------------------

	/**
	 * Persist or update a vote. Throws if the value is invalid.
	 *
	 * @return bool true when the vote actually changed, false on no-op.
	 */
	public function castVote(
		int $pageId,
		UserIdentity $user,
		int $value
	): bool {
		if ( $value !== self::VOTE_CANCEL && !in_array( $value, self::VALID_VALUES, true ) ) {
			throw new InvalidArgumentException( "Invalid vote value: $value" );
		}

		$db = $this->getDB( DB_PRIMARY );
		$db->startAtomic( __METHOD__ );

		try {
			// acquireActorId looks up the actor row and creates it on the
			// given connection when missing — here a PRIMARY connection,
			// so the write is allowed.
			$actorId = MediaWikiServices::getInstance()
				->getActorStore()
				->acquireActorId( $user, $db );
			$now = wfTimestampNow();

			// Lookup existing vote
			$existing = $db->selectRow(
				'page_rating_votes',
				[ 'vote_id', 'vote_value' ],
				[
					'page_id'    => $pageId,
					'vote_actor' => $actorId,
				],
				__METHOD__
			);

			if ( $value === self::VOTE_CANCEL ) {
				// Cancellation: actually delete the row.
				if ( $existing ) {
					$db->delete(
						'page_rating_votes',
						[ 'vote_id' => $existing->vote_id ],
						__METHOD__
					);
					$this->decrementVoteCount( $db, $pageId );
					// CRITICAL: endAtomic() before returning — a dangling
					// explicit transaction makes MediaWiki throw
					// DBTransactionError at request shutdown (HTTP 500).
					$db->endAtomic( __METHOD__ );
					return true;
				}
				$db->endAtomic( __METHOD__ );
				return false;
			}

			// Insert or update
			$row = [
				'page_id'        => $pageId,
				'vote_actor'     => $actorId,
				'vote_user_id'   => $user->getId(),
				'vote_user_name' => $user->getName(),
				'vote_value'     => $value,
				'voted_at'       => $now,
			];

			if ( $existing ) {
				if ( (int)$existing->vote_value === $value ) {
					// No-op: same vote. Still update timestamp for activity tracking.
					$db->update(
						'page_rating_votes',
						[ 'voted_at' => $now ],
						[ 'vote_id' => $existing->vote_id ],
						__METHOD__
					);
					$db->endAtomic( __METHOD__ );
					return false;
				}
				$db->update(
					'page_rating_votes',
					$row,
					[ 'vote_id' => $existing->vote_id ],
					__METHOD__
				);
				$this->touchLastVoteAt( $db, $pageId, $now );
				$db->endAtomic( __METHOD__ );
				return true;
			}

			$db->insert( 'page_rating_votes', $row, __METHOD__ );
			$this->incrementVoteCount( $db, $pageId, $now );
			$db->endAtomic( __METHOD__ );
			return true;
		} catch ( \Throwable $e ) {
			$db->endAtomic( __METHOD__ );
			throw $e;
		}
	}

	/**
	 * Read the current vote of a user on a page.
	 * Returns null if the user never voted.
	 */
	public function getUserVote( int $pageId, UserIdentity $user ): ?int {
		$db = $this->getDB( DB_REPLICA );
		// findActorId is read-only — it never writes, so it is safe on a
		// REPLICA connection. (Writing on REPLICA throws
		// DBReadOnlyRoleError → HTTP 500.)
		$actorId = MediaWikiServices::getInstance()
			->getActorStore()
			->findActorId( $user, $db );
		if ( $actorId === null ) {
			return null;
		}
		$row = $db->selectRow(
			'page_rating_votes',
			[ 'vote_value' ],
			[
				'page_id'    => $pageId,
				'vote_actor' => $actorId,
			],
			__METHOD__
		);
		return $row ? (int)$row->vote_value : null;
	}

	// ------------------------------------------------------------------
	// Aggregation / rating calculation
	// ------------------------------------------------------------------

	/**
	 * Calculate the rating for a single page.
	 *
	 * Formula:
	 *   {[(投+1人数) - (投-1人数)] + [(投+0.5人数) - (投-0.5人数)] * 0.5}
	 *   -----------------------------------------------------------------
	 *                         (总投票人数, 含投0的人数)
	 *
	 * @return RatingResult
	 */
	public function calculateRating( int $pageId ): RatingResult {
		$db = $this->getDB( DB_REPLICA );

		$rows = $db->select(
			'page_rating_votes',
			[ 'vote_value', 'vote_count' => 'COUNT(*)' ],
			[ 'page_id' => $pageId ],
			__METHOD__,
			[ 'GROUP BY' => 'vote_value' ]
		);

		$buckets = [
			self::VOTE_NEG1 => 0,
			self::VOTE_NEG05 => 0,
			self::VOTE_ZERO => 0,
			self::VOTE_POS05 => 0,
			self::VOTE_POS1 => 0,
		];

		$total = 0;
		foreach ( $rows as $row ) {
			$v = (int)$row->vote_value;
			$c = (int)$row->vote_count;
			if ( isset( $buckets[$v] ) ) {
				$buckets[$v] = $c;
				$total += $c;
			}
		}

		if ( $total === 0 ) {
			return new RatingResult( 0.0, $buckets, 0 );
		}

		$numerator =
			( $buckets[self::VOTE_POS1] - $buckets[self::VOTE_NEG1] )
			+ ( $buckets[self::VOTE_POS05] - $buckets[self::VOTE_NEG05] ) * 0.5;

		$score = $numerator / $total;

		return new RatingResult( $score, $buckets, $total );
	}

	/**
	 * List all registered pages ordered by score (DESC, then by vote count).
	 *
	 * @return array<int, array{0:int,1:string,2:float,3:int}> rows of
	 *         (page_id, display_title, score, total_votes)
	 */
	public function listRankedPages( string $group = self::GROUP_DEFAULT ): array {
		$db = $this->getDB( DB_REPLICA );

		// Pull everything; for thousands of pages this is fine and lets
		// us calculate ranks in PHP without GROUP BY gymnastics.
		$pages = $db->select(
			'page_rating_pages',
			[ 'page_id', 'page_namespace', 'page_title', 'vote_count' ],
			[ 'pr_group' => $group ],
			__METHOD__,
			[ 'ORDER BY' => 'last_vote_at DESC' ]
		);

		$out = [];
		foreach ( $pages as $row ) {
			$result = $this->calculateRating( (int)$row->page_id );
			$title = Title::makeTitle( (int)$row->page_namespace, $row->page_title );
			$out[] = [
				(int)$row->page_id,
				$title->getPrefixedText(),
				$result->score,
				$result->total,
				$result->buckets,
			];
		}

		// Sort by score DESC. Stable tie-breaker: more votes first.
		usort( $out, static function ( $a, $b ) {
			if ( $a[2] !== $b[2] ) {
				return $b[2] <=> $a[2]; // DESC
			}
			return $b[3] <=> $a[3];
		} );

		return $out;
	}

	public function getVoteStats( int $pageId ): array {
		$result = $this->calculateRating( $pageId );
		return [
			'score'   => $result->score,
			'total'   => $result->total,
			'buckets' => $result->buckets,
		];
	}

	/**
	 * List the user names who voted a specific value on a page.
	 * Ordered oldest vote first.
	 *
	 * @param int $pageId
	 * @param int $value one of the VOTE_* weights (-2 … 2)
	 * @return string[] user names
	 */
	public function getVoters( int $pageId, int $value ): array {
		$db = $this->getDB( DB_REPLICA );
		$rows = $db->select(
			'page_rating_votes',
			[ 'vote_user_name' ],
			[
				'page_id'    => $pageId,
				'vote_value' => $value,
			],
			__METHOD__,
			[ 'ORDER BY' => 'voted_at ASC' ]
		);
		$names = [];
		foreach ( $rows as $row ) {
			$names[] = $row->vote_user_name;
		}
		return $names;
	}

	// ------------------------------------------------------------------
	// Internals
	// ------------------------------------------------------------------

	private function incrementVoteCount( IDatabase $db, int $pageId, string $now ): void {
		$db->update(
			'page_rating_pages',
			[
				'vote_count'   => $db->buildIntegerCast( 'vote_count + 1' ),
				'last_vote_at' => $now,
			],
			[ 'page_id' => $pageId ],
			__METHOD__
		);
	}

	private function decrementVoteCount( IDatabase $db, int $pageId ): void {
		// vote_count is INT UNSIGNED. UNSIGNED is a *column-level* type, so
		// any expression involving vote_count (including inside CASE THEN)
		// is still evaluated as UNSIGNED arithmetic — `0 - 1` in strict
		// mode throws ERROR 1690 and the API returns HTTP 500 on cancel.
		// Cast to SIGNED *first* so the subtraction doesn't wrap, and
		// clamp with GREATEST(0, …) so the result is never negative
		// (assigning a negative value back to an UNSIGNED column would
		// also error out).
		$expr = 'GREATEST(0, ' . $db->buildIntegerCast( 'vote_count' ) . ' - 1)';
		$db->update(
			'page_rating_pages',
			[
				'vote_count' => $expr,
			],
			[ 'page_id' => $pageId ],
			__METHOD__
		);
	}

	private function touchLastVoteAt( IDatabase $db, int $pageId, string $now ): void {
		$db->update(
			'page_rating_pages',
			[ 'last_vote_at' => $now ],
			[ 'page_id' => $pageId ],
			__METHOD__
		);
	}
}
