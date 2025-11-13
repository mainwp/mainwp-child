<?php
/**
 * Public Suffix List Trie using anonymous node objects (keeps only one class per file)
 *
 * @package MainWP/Child
 */

/**
 * Public Suffix List Trie
 */
class PslTrie {

    /**
     * Root node of the trie.
     *
     * @var object
     */
    private $root;

    /**
     * Initialize the trie root node.
     */
    public function __construct() {
        $this->root = new class() {
            /**
             * Child nodes indexed by label.
             *
             * @var array
             */
            public $children = array();

            /**
             * Whether this node marks the end of a suffix.
             *
             * @var bool
             */
            public $isEnd = false;
        };
    }

    /**
     * Insert a suffix into the trie.
     *
     * @param  mixed $suffix
     * @return void
     */
    public function insert( $suffix ) {
        $parts = array_reverse( explode( '.', $suffix ) );
        $node  = $this->root;
        foreach ( $parts as $part ) {
            if ( ! isset( $node->children[ $part ] ) ) {
                $node->children[ $part ] = new class() {
                    /**
                     * Child nodes indexed by label.
                     *
                     * @var array
                     */
                    public $children = array();

                    /**
                     * Whether this node marks the end of a suffix.
                     *
                     * @var bool
                     */
                    public $isEnd = false;
                };
            }
            $node = $node->children[ $part ];
        }
        $node->isEnd = true;
    }

    /**
     * Find the longest matching suffix for a host.
     *
     * @param string $host Hostname to match against the public suffix list.
     * @return string The longest matching public suffix for the host (fallback to last label).
     */
    public function find_longest_match( $host ) {
        $parts = array_reverse( explode( '.', $host ) );
        $node  = $this->root;
        $match = array();
        foreach ( $parts as $part ) {
            if ( ! isset( $node->children[ $part ] ) ) {
                break;
            }
            $match[] = $part;
            $node    = $node->children[ $part ];
        }
        if ( empty( $match ) ) {
            return $parts[0]; // fallback: last label.
        }
        return implode( '.', array_reverse( $match ) );
    }
}
