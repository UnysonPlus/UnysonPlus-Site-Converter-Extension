<?php if ( ! defined( 'FW' ) ) { die( 'Forbidden' ); }

/**
 * BUILD PROGRESS — what the long request is actually doing, readable while it is still running.
 *
 * "Build the site from this mapping" takes seconds on a small page and close to a minute on a large one,
 * and for all of that time the only thing that changed was a disabled button reading "Building…". A wait
 * with no visible work reads as a hang, and the honest fix is not a nicer spinner: it is to say which of
 * the pipeline's steps is running.
 *
 * Deliberately NOT a percentage. The existing loading() bar eases asymptotically toward 99% over a 7s
 * estimate, so on a 60-second build it reaches ~99% in about fifteen seconds and then sits there for
 * forty-five more — which reads as frozen rather than slow, and is worse than showing nothing. Named steps
 * cannot lie in that way: each one either has finished or has not.
 *
 * Stored in a TRANSIENT rather than a property because the reader is a different HTTP request: the browser
 * polls while the build POST is still in flight, so the two processes share nothing but the database.
 * Keyed per user, so two people converting at once do not read each other's progress.
 */
class FW_Site_Converter_Progress {

	/** Long enough to outlive the slowest build, short enough that a crashed run cannot haunt the next one. */
	const TTL = 900;

	/** @var bool set once a run has begun, so a stray step() from another code path cannot start one */
	private static $running = false;

	private static function key() {
		return 'fw_sc_progress_' . get_current_user_id();
	}

	/**
	 * Begin a run.
	 *
	 * @param array $steps ordered key => label. The labels are what the user reads, so they name the WORK
	 *                     ("Importing images") rather than the function ("media import").
	 */
	public static function start( array $steps ) {
		self::$running = true;
		set_transient( self::key(), array(
			'steps'   => $steps,
			'order'   => array_keys( $steps ),
			'current' => '',
			'done'    => array(),
			'started' => microtime( true ),
			'at'      => microtime( true ),
			'failed'  => false,
		), self::TTL );
	}

	/**
	 * Mark a step as the one now running; everything before it in the declared order is finished.
	 *
	 * Deriving "done" from the ORDER rather than requiring a matching finish() call means a step that
	 * returns early, throws, or is skipped by an option can never leave the panel stuck on a spinner that
	 * belongs to work nothing is doing any more.
	 */
	public static function step( $key ) {
		if ( ! self::$running ) { return; }
		$s = get_transient( self::key() );
		if ( ! is_array( $s ) || empty( $s['order'] ) ) { return; }
		$i = array_search( $key, $s['order'], true );
		if ( false === $i ) { return; }
		$s['done']    = array_slice( $s['order'], 0, $i );
		$s['current'] = $key;
		$s['at']      = microtime( true );
		set_transient( self::key(), $s, self::TTL );
	}

	/** Everything finished. Kept (briefly) rather than deleted so the last poll can show a complete list. */
	public static function finish() {
		if ( ! self::$running ) { return; }
		$s = get_transient( self::key() );
		if ( is_array( $s ) ) {
			$s['done']    = $s['order'];
			$s['current'] = '';
			$s['at']      = microtime( true );
			set_transient( self::key(), $s, 60 );
		}
		self::$running = false;
	}

	/** The run failed — say so, so the panel stops implying work is still happening. */
	public static function fail( $message = '' ) {
		if ( ! self::$running ) { return; }
		$s = get_transient( self::key() );
		if ( is_array( $s ) ) {
			$s['failed']  = true;
			$s['message'] = (string) $message;
			$s['at']      = microtime( true );
			set_transient( self::key(), $s, 60 );
		}
		self::$running = false;
	}

	public static function clear() {
		self::$running = false;
		delete_transient( self::key() );
	}

	/**
	 * The current state for the poller: the step list, which are finished, which is running, and how long
	 * the run and the current step have been going.
	 */
	public static function read() {
		$s = get_transient( self::key() );
		if ( ! is_array( $s ) || empty( $s['order'] ) ) { return null; }

		$now  = microtime( true );
		$list = array();
		foreach ( $s['order'] as $k ) {
			$list[] = array(
				'key'   => $k,
				'label' => isset( $s['steps'][ $k ] ) ? (string) $s['steps'][ $k ] : $k,
				'state' => in_array( $k, (array) $s['done'], true ) ? 'done' : ( $k === $s['current'] ? 'running' : 'pending' ),
			);
		}

		return array(
			'steps'      => $list,
			'current'    => (string) $s['current'],
			'elapsed'    => (int) round( $now - (float) $s['started'] ),
			'step_secs'  => (int) round( $now - (float) $s['at'] ),
			'failed'     => ! empty( $s['failed'] ),
			'message'    => isset( $s['message'] ) ? (string) $s['message'] : '',
			'complete'   => '' === $s['current'] && count( (array) $s['done'] ) === count( (array) $s['order'] ),
		);
	}
}
