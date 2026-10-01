<?php
/**
 * Invalidate stored responses when content changes.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BlueFairy\ApiGateway\Logger;

/**
 * Listens to post, term and comment changes and purges the response cache.
 *
 * By default a change flushes everything: the free plugin has one rule and
 * no tags, so there is nothing finer to do. The `bfag_cache_purge_tags`
 * filter lets an add-on return the tags that a given change touches, and
 * then only those generations are bumped. An untagged rule is keyed on the
 * implicit tag `all` (CacheKeyGenerator), so an answer that includes `all`
 * still purges it on every change; only tagged rules are narrower.
 */
final class CachePurger {

	/**
	 * Constructor.
	 *
	 * @param CacheStore          $store Response storage.
	 * @param CacheRuleRepository $rules Rules, consulted for the tags they carry.
	 */
	public function __construct(
		private readonly CacheStore $store,
		private readonly CacheRuleRepository $rules
	) {}

	/**
	 * Bind to the content hooks.
	 */
	public function register_hooks(): void {
		add_action(
			'save_post',
			function ( mixed $post_id, mixed $post = null, mixed $update = false ): void {
				unset( $post, $update );
				$this->on_post_change( (int) $post_id );
			},
			10,
			3
		);
		add_action( 'delete_post', [ $this, 'on_post_change' ] );
		add_action( 'trashed_post', [ $this, 'on_post_change' ] );

		$term = function ( mixed $term_id, mixed $tt_id = 0, mixed $taxonomy = '' ): void {
			unset( $tt_id );
			$this->on_term_change( (int) $term_id, is_string( $taxonomy ) ? $taxonomy : '' );
		};
		add_action( 'edited_term', $term, 10, 3 );
		add_action( 'created_term', $term, 10, 3 );
		add_action( 'delete_term', $term, 10, 3 );

		add_action(
			'transition_comment_status',
			function ( mixed $new_status, mixed $old_status, mixed $comment ): void {
				unset( $new_status, $old_status );
				$this->on_comment_change( $this->comment_id( $comment ) );
			},
			10,
			3
		);
		add_action(
			'wp_insert_comment',
			function ( mixed $comment_id ): void {
				$this->on_comment_change( (int) $comment_id );
			}
		);
	}

	/**
	 * A post was saved, trashed or deleted. Revisions and autosaves are
	 * ignored: they never appear in a public response.
	 *
	 * @param int $post_id Post id.
	 */
	public function on_post_change( int $post_id ): void {
		if ( $this->is_revision_or_autosave( $post_id ) ) {
			return;
		}
		$this->purge_for( 'post', $post_id );
	}

	/**
	 * A term was created, edited or deleted.
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy name.
	 */
	public function on_term_change( int $term_id, string $taxonomy ): void {
		unset( $taxonomy );
		$this->purge_for( 'term', $term_id );
	}

	/**
	 * A comment was inserted or changed status.
	 *
	 * @param int $comment_id Comment id.
	 */
	public function on_comment_change( int $comment_id ): void {
		$this->purge_for( 'comment', $comment_id );
	}

	/**
	 * Orphan every stored response.
	 */
	public function purge_all(): void {
		$this->store->flush_all();
		/**
		 * The response cache was purged.
		 *
		 * @since 1.0.0
		 *
		 * @param string   $scope 'all' or 'tags'.
		 * @param string[] $tags  The tags bumped (empty for 'all').
		 */
		do_action( 'bfag_cache_purged', 'all', [] );
	}

	/**
	 * Orphan the responses stored under the given tags. Only tags an
	 * enabled rule carries (plus the implicit `all`) are bumped: no key
	 * embeds any other tag's generation, so bumping it would purge nothing
	 * and leave one more entry in `bfag_tag_generations` for every post
	 * ever saved. The known set is also what the store keeps, so entries
	 * for tags no rule carries any more are dropped on the same write.
	 *
	 * @param string[] $tags Tags to bump.
	 */
	public function purge_tags( array $tags ): void {
		$tags  = array_values( array_filter( $tags, static fn( mixed $tag ): bool => is_string( $tag ) && '' !== $tag ) );
		$known = $this->known_tags();

		$unknown = array_values( array_diff( $tags, $known ) );
		if ( [] !== $unknown ) {
			Logger::log( 'cache purge names tags no enabled rule carries', [ 'tags' => $unknown ] );
		}
		$tags = array_values( array_intersect( $tags, $known ) );

		$this->store->bump_tags( $tags, $known );
		/** This action is documented in includes/Cache/CachePurger.php */
		do_action( 'bfag_cache_purged', 'tags', $tags );
	}

	/**
	 * Ask the add-on which tags a change touches; flush everything when it
	 * does not say.
	 *
	 * @param string $type 'post', 'term' or 'comment'.
	 * @param int    $id   Object id.
	 */
	private function purge_for( string $type, int $id ): void {
		/**
		 * Narrow a purge to tags.
		 *
		 * @since 1.0.0
		 *
		 * @param string[]|null $tags Tags to bump, or null to flush everything.
		 * @param string        $type 'post', 'term' or 'comment'.
		 * @param int           $id   Object id.
		 */
		$tags = apply_filters( 'bfag_cache_purge_tags', null, $type, $id );
		if ( is_array( $tags ) ) {
			$this->purge_tags( $tags );
			return;
		}
		$this->purge_all();
	}

	/**
	 * Every tag carried by an enabled rule, plus the implicit `all` every
	 * untagged rule is keyed on.
	 *
	 * @return string[]
	 */
	private function known_tags(): array {
		$known = [ CacheKeyGenerator::IMPLICIT_TAG => true ];
		foreach ( $this->rules->all_enabled() as $rule ) {
			foreach ( $this->rules->tags_of( $rule ) as $tag ) {
				$known[ $tag ] = true;
			}
		}
		return array_keys( $known );
	}

	/**
	 * Is this post a revision or an autosave?
	 *
	 * @param int $post_id Post id.
	 */
	private function is_revision_or_autosave( int $post_id ): bool {
		if ( false !== wp_is_post_revision( $post_id ) || false !== wp_is_post_autosave( $post_id ) ) {
			return true;
		}
		$post = get_post( $post_id );
		return $post instanceof \WP_Post && 'revision' === $post->post_type;
	}

	/**
	 * The id of a comment given as an object, array or id.
	 *
	 * @param mixed $comment WP_Comment, array or id.
	 */
	private function comment_id( mixed $comment ): int {
		if ( is_object( $comment ) && isset( $comment->comment_ID ) ) {
			return (int) $comment->comment_ID;
		}
		if ( is_array( $comment ) && isset( $comment['comment_ID'] ) ) {
			return (int) $comment['comment_ID'];
		}
		return is_numeric( $comment ) ? (int) $comment : 0;
	}
}
