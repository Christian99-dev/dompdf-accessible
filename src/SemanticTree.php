<?php
/**
 * @package dompdf
 * @link    https://github.com/dompdf/dompdf
 */
namespace Dompdf;

/**
 * SemanticNode - A complete semantic node in the tree
 * 
 * This class contains ALL semantic information AND tree structure.
 * It is a REPLACEMENT for SemanticElement with added tree capabilities.
 * 
 * Design Philosophy:
 * - Contains all HTML semantic info (tag, attributes, display)
 * - Contains tree navigation (parent, children)
 * - Contains all semantic logic (isDecorative, getPdfStructureTag, etc.)
 * 
 * @package dompdf
 */
class SemanticNode
{
    // ========================================================================
    // SEMANTIC DATA (from SemanticElement)
    // ========================================================================
    
    /**
     * Unique identifier for this element (Frame ID)
     */
    public readonly string $id;
    
    /**
     * HTML tag name (e.g., 'h1', 'p', 'img', 'div')
     */
    public readonly string $tag;
    
    /**
     * HTML attributes as key-value pairs
     * @var array<string, string>
     */
    public readonly array $attributes;
    
    /**
     * CSS display property value (e.g., 'block', 'inline', 'none')
     */
    public readonly string $display;
    
    /**
     * Parent element ID (stored for reference, but use getParent() for O(1) access!)
     * This is kept for compatibility but the tree structure provides direct parent access.
     */
    public readonly ?string $parentId;
    
    // ========================================================================
    // TREE STRUCTURE
    // ========================================================================
    
    /**
     * Direct reference to parent node (O(1) access!)
     * @var SemanticNode|null
     */
    private ?SemanticNode $parent = null;
    
    /**
     * Direct references to child nodes (O(1) access!)
     * @var SemanticNode[]
     */
    private array $children = [];
    
    // ========================================================================
    // CONSTRUCTOR
    // ========================================================================
    
    /**
     * Constructor
     * 
     * @param string $id Element identifier (Frame ID as string)
     * @param string $tag HTML tag name
     * @param array<string, string> $attributes HTML attributes
     * @param string $display CSS display value
     * @param string|null $parentId Parent element ID (for reference)
     */
    public function __construct(
        string $id,
        string $tag,
        array $attributes = [],
        string $display = 'block',
        ?string $parentId = null
    ) {
        $this->id = $id;
        $this->tag = strtolower($tag);
        $this->attributes = $attributes;
        $this->display = $display;
        $this->parentId = $parentId;
    }
    
    // ========================================================================
    // TREE NAVIGATION (O(1) operations!)
    // ========================================================================
    
    /**
     * Set parent node (internal, called by SemanticTree)
     * 
     * @param SemanticNode|null $parent
     * @internal
     */
    public function setParent(?SemanticNode $parent): void
    {
        $this->parent = $parent;
    }
    
    /**
     * Get parent node (O(1) access!)
     * 
     * @return SemanticNode|null
     */
    public function getParent(): ?SemanticNode
    {
        return $this->parent;
    }
    
    /**
     * Check if node has a parent
     * 
     * @return bool
     */
    public function hasParent(): bool
    {
        return $this->parent !== null;
    }
    
    /**
     * Add child node (internal, called by SemanticTree)
     * 
     * @param SemanticNode $child
     * @internal
     */
    public function addChild(SemanticNode $child): void
    {
        $this->children[] = $child;
    }
    
    /**
     * Get all children (O(1) access!)
     * 
     * Returns ALL children including #text nodes, decorative elements, etc.
     * 
     * @return SemanticNode[]
     */
    public function getChildren(): array
    {
        return $this->children;
    }
    
    /**
     * Check if node has children
     * 
     * @return bool
     */
    public function hasChildren(): bool
    {
        return count($this->children) > 0;
    }
    
    /**
     * Get depth in tree (pure calculation, no caching)
     * 
     * Root elements (no parent) have depth 0, their children have depth 1, etc.
     * 
     * Note: This is only used for debug output, so performance is not critical.
     * If this becomes a bottleneck, consider adding depth as a readonly property
     * calculated once during tree construction.
     * 
     * @return int Depth in tree (0 = root, 1 = child, 2 = grandchild, etc.)
     */
    public function getDepth(): int
    {
        // Calculate depth by walking up parent chain
        $depth = 0;
        $current = $this;
        
        while ($current->hasParent()) {
            $current = $current->getParent();
            $depth++;
            
            // Safety: prevent infinite loops
            if ($depth > 100) {
                SimpleLogger::log("semantic_tree_logs", __METHOD__, 
                    "WARNING: Depth exceeded 100 for node {$this->id}, breaking loop"
                );
                break;
            }
        }
        
        return $depth;
    }
    
    /**
     * Get all ancestors (parent, grandparent, etc.) up to root
     * 
     * @return SemanticNode[] Array of ancestor nodes (closest parent first)
     */
    public function getAncestors(): array
    {
        $ancestors = [];
        $current = $this->parent;
        
        while ($current !== null) {
            $ancestors[] = $current;
            $current = $current->getParent();
            
            // Safety: prevent infinite loops
            if (count($ancestors) > 100) {
                SimpleLogger::log("semantic_tree_logs", __METHOD__, 
                    "WARNING: Ancestor count exceeded 100 for node {$this->id}, breaking loop"
                );
                break;
            }
        }
        
        return $ancestors;
    }
    
    /**
     * Find parent that matches a condition (e.g., skip transparent tags)
     * 
     * @param callable $condition Function that takes SemanticNode and returns bool
     * @return SemanticNode|null First parent that matches condition
     */
    public function findParentWhere(callable $condition): ?SemanticNode
    {
        $current = $this->parent;
        $depth = 0;
        
        while ($current !== null) {
            if ($condition($current)) {
                return $current;
            }
            
            $current = $current->getParent();
            $depth++;
            
            // Safety: prevent infinite loops
            if ($depth > 100) {
                SimpleLogger::log("semantic_tree_logs", __METHOD__, 
                    "WARNING: Search depth exceeded 100 for node {$this->id}, breaking"
                );
                break;
            }
        }
        
        return null;
    }
    
    // ========================================================================
    // ATTRIBUTE ACCESS (copied from SemanticElement)
    // ========================================================================
    
    /**
     * Check if element has a specific attribute
     * 
     * @param string $name Attribute name
     * @return bool
     */
    public function hasAttribute(string $name): bool
    {
        return isset($this->attributes[$name]);
    }
    
    /**
     * Get an attribute value
     * 
     * @param string $name Attribute name
     * @param string|null $default Default value if attribute doesn't exist
     * @return string|null
     */
    public function getAttribute(string $name, ?string $default = null): ?string
    {
        return $this->attributes[$name] ?? $default;
    }
    
    // ========================================================================
    // SEMANTIC CLASSIFICATION (copied from SemanticElement)
    // ========================================================================
    

    public function isTextNode(): bool
    {
        $tags = ['#text', 'bullet'];
        return in_array($this->tag, $tags, true);
    }
    /**
     * Check if element should be treated as decorative (artifact)
     * 
     * @return bool
     */
    public function isDecorative(): bool
    {
        // aria-hidden="true" means decorative
        if ($this->getAttribute('aria-hidden') === 'true') {
            return true;
        }
        
        // role="presentation" or role="none" means decorative
        $role = $this->getAttribute('role');
        if ($role === 'presentation' || $role === 'none') {
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if any parent (ancestor) is decorative
     * 
     * This walks up the tree and checks if any ancestor has decorative role.
     * Used to determine if content should be wrapped as Artifact.
     * 
     * @return bool True if any parent is decorative
     */
    public function hasDecorativeParent(): bool
    {
        $current = $this->parent;
        $depth = 0;
        
        while ($current !== null) {
            if ($current->isDecorative()) {
                return true;
            }
            
            $current = $current->getParent();
            $depth++;
            
            // Safety: prevent infinite loops
            if ($depth > 100) {
                SimpleLogger::log("semantic_tree_logs", __METHOD__, 
                    "WARNING: Search depth exceeded 100 for node {$this->id}, breaking"
                );
                break;
            }
        }
        
        return false;
    }

    /**
     * Check if element is a transparent inline styling tag
     * 
     * Transparent inline tags provide visual styling but are not semantically relevant
     * for PDF/UA structure. The parent block-level element is used for tagging instead.
     * 
     * @return bool True if this is a transparent inline styling tag
     */
    public function isTransparentInlineTag(): bool
    {
        $transparentTags = [
            'strong',  'b',      'em',     'i',      'span',   'u',
            's',       'del',    'ins',    'mark',   'small',  'sub',
            'sup',     'code',   'kbd',    'samp',   'var',    'cite',
            'dfn',     'abbr',   'time',
        ];
        
        return in_array($this->tag, $transparentTags, true);
    }
    
    /**
     * Check if element is an image
     * 
     * @return bool
     */
    public function isImage(): bool
    {
        return $this->tag === 'img';
    }
    
    /**
     * Check if element is a link
     * 
     * @return bool
     */
    public function isLink(): bool
    {
        return $this->tag === 'a';
    }
    
    /**
     * Check if element is table-related
     * 
     * Table-related elements include table structure tags (table, tr, td, th)
     * and table section tags (thead, tbody, tfoot).
     * 
     * This is used by DrawingManager to determine if drawings should be
     * wrapped as Artifacts when inside table structures.
     * 
     * @return bool True if element is table-related
     */
    public function isTableRelated(): bool
    {
        $tableElements = ['table', 'tr', 'th', 'td', 'thead', 'tbody', 'tfoot'];
        return in_array($this->tag, $tableElements, true);
    }
    
    // ========================================================================
    // PDF STRUCTURE MAPPING (copied from SemanticElement)
    // ========================================================================
    
    /**
     * Get the PDF structure tag that should be used for this element
     * 
     * @return string PDF structure tag (e.g., 'H1', 'P', 'Figure', 'Link')
     */
    public function getPdfStructureTag(): string
    {
        // HTML to PDF tag mapping
        $mapping = [
            'h1' => 'H1',     'h2' => 'H2',       'h3' => 'H3',
            'h4' => 'H4',     'h5' => 'H5',       'h6' => 'H6',
            'p' => 'P',       'div' => 'Div',     'span' => 'Span',
            'section' => 'Sect',   'article' => 'Art',  'aside' => 'Aside',
            'nav' => 'Nav',   'table' => 'Table', 'tr' => 'TR',
            'th' => 'TH',     'td' => 'TD',       'thead' => 'THead',
            'tbody' => 'TBody',    'tfoot' => 'TFoot', 'ul' => 'L',
            'ol' => 'L',      'li' => 'LI',       'img' => 'Figure',
            'figure' => 'Figure',  'a' => 'Link',  'blockquote' => 'BlockQuote',
            'code' => 'Code', 'pre' => 'Code',    'strong' => 'Strong',
            'em' => 'Em',     'b' => 'Strong',    'i' => 'Em',
        ];
        
        return $mapping[$this->tag] ?? 'Div';
    }
    
    /**
     * Get alt text for images
     * 
     * @return string|null
     */
    public function getAltText(): ?string
    {
        if (!$this->isImage()) {
            return null;
        }
        
        return $this->getAttribute('alt');
    }
    
    /**
     * Check if image has alt text
     * 
     * @return bool
     */
    public function hasAltText(): bool
    {
        return $this->isImage() && $this->hasAttribute('alt');
    }
    
    // ========================================================================
    // DEBUG & LOGGING
    // ========================================================================
    
    /**
     * String representation for debugging
     * 
     * @return string
     */
    public function __toString(): string
    {
        $attrs = [];
        foreach ($this->attributes as $key => $value) {
            $attrs[] = "{$key}=\"{$value}\"";
        }
        
        $parentInfo = $this->hasParent() 
            ? " parent=" . $this->getParent()->id 
            : " (root)";
        
        $childInfo = count($this->children) > 0 
            ? " children=" . count($this->children) 
            : "";
        
        return sprintf(
            "<%s%s> [%s] (node_%s)%s%s depth=%d",
            $this->tag,
            $attrs ? ' ' . implode(' ', $attrs) : '',
            $this->display,
            $this->id,
            $parentInfo,
            $childInfo,
            $this->getDepth()
        );
    }
}

// ============================================================================
// ============================================================================

/**
 * SemanticTree - The complete semantic tree structure
 * 
 * This class manages the entire tree of SemanticNode objects.
 * 
 * Key Features:
 * - O(1) node lookup via HashMap: $nodeMap[$frameId] => SemanticNode
 * - Automatic parent-child linking during add()
 * - Current node tracking for rendering
 * - Root node management
 * 
 * @package dompdf
 */
class SemanticTree
{
    /**
     * Root node of the tree (first node without parent)
     * @var SemanticNode|null
     */
    private ?SemanticNode $root = null;
    
    /**
     * HashMap for O(1) node lookup by frame ID
     * @var array<string, SemanticNode>
     */
    private array $nodeMap = [];
    
    // NOTE: currentNode removed! TCPDF manages its own currentFrameId.
    // Tree is just a data structure, not a state machine.
    
    /**
     * Total number of nodes added
     * @var int
     */
    private int $nodeCount = 0;
    
    // ========================================================================
    // TREE BUILDING
    // ========================================================================
    
    /**
     * Add a new node to the tree
     * 
     * This method:
     * 1. Creates a SemanticNode with the given data
     * 2. Adds it to the HashMap for O(1) lookup
     * 3. Links it to its parent (if parent exists)
     * 4. Sets it as root if it has no parent
     * 
     * @param string $id Element identifier (Frame ID)
     * @param string $tag HTML tag name
     * @param array<string, string> $attributes HTML attributes
     * @param string $display CSS display value
     * @param string|null $parentId Parent element ID
     * @return SemanticNode The created node
     */
    public function add(
        string $id,
        string $tag,
        array $attributes = [],
        string $display = 'block',
        ?string $parentId = null
    ): SemanticNode {
        // Create the node
        $node = new SemanticNode($id, $tag, $attributes, $display, $parentId);
        
        // Add to HashMap for O(1) lookup
        $this->nodeMap[$id] = $node;
        $this->nodeCount++;
        
        // Link to parent if exists
        if ($parentId !== null) {
            $parent = $this->nodeMap[$parentId] ?? null;
            
            if ($parent !== null) {
                // Bidirectional link
                $parent->addChild($node);
                $node->setParent($parent);
                
                SimpleLogger::log("semantic_tree_logs", __METHOD__,
                    sprintf("Linked node %s <%s> to parent %s <%s>",
                        $node->id, $node->tag,
                        $parent->id, $parent->tag
                    )
                );
            } else {
                SimpleLogger::log("semantic_tree_logs", __METHOD__,
                    sprintf("WARNING: Parent %s not found for node %s <%s>",
                        $parentId, $node->id, $node->tag
                    )
                );
            }
        } else {
            // No parent = Root node
            if ($this->root === null) {
                $this->root = $node;
                
                SimpleLogger::log("semantic_tree_logs", __METHOD__,
                    sprintf("Set root node: %s <%s>", $node->id, $node->tag)
                );
            }
        }
        
        return $node;
    }
    
    // ========================================================================
    // NODE LOOKUP (O(1) operations!)
    // ========================================================================
    
    /**
     * Get node by frame ID (O(1) HashMap lookup!)
     * 
     * @param string $frameId Frame ID to look up
     * @return SemanticNode|null The node or null if not found
     */
    public function getNodeById(string $frameId): ?SemanticNode
    {
        return $this->nodeMap[$frameId] ?? null;
    }
    
    /**
     * Check if node exists in tree (O(1) HashMap lookup!)
     * 
     * @param string $frameId Frame ID to check
     * @return bool True if node exists
     */
    public function hasNode(string $frameId): bool
    {
        return isset($this->nodeMap[$frameId]);
    }
    
    /**
     * Get all nodes as associative array
     * 
     * @return array<string, SemanticNode> HashMap of frameId => SemanticNode
     */
    public function getAllNodes(): array
    {
        return $this->nodeMap;
    }
    
    // ========================================================================
    // ROOT & STATISTICS
    // ========================================================================
    
    /**
     * Get root node
     * 
     * @return SemanticNode|null Root node or null if tree is empty
     */
    public function getRoot(): ?SemanticNode
    {
        return $this->root;
    }
    
    /**
     * Get total number of nodes in tree
     * 
     * @return int Node count
     */
    public function getNodeCount(): int
    {
        return $this->nodeCount;
    }
    
    /**
     * Check if tree is empty
     * 
     * @return bool True if tree has no nodes
     */
    public function isEmpty(): bool
    {
        return $this->nodeCount === 0;
    }
    
    // ========================================================================
    // TREE TRAVERSAL & DEBUGGING
    // ========================================================================
    
    /**
     * Get tree structure as nested array (for debugging)
     * 
     * @param SemanticNode|null $node Starting node (null = root)
     * @param int $maxDepth Maximum depth to traverse (prevent infinite recursion)
     * @return array Tree structure
     */
    public function toArray(?SemanticNode $node = null, int $maxDepth = 10): array
    {
        if ($node === null) {
            $node = $this->root;
        }
        
        if ($node === null || $maxDepth <= 0) {
            return [];
        }
        
        $result = [
            'id' => $node->id,
            'tag' => $node->tag,
            'display' => $node->display,
            'depth' => $node->getDepth(),
            'children' => []
        ];
        
        foreach ($node->getChildren() as $child) {
            $result['children'][] = $this->toArray($child, $maxDepth - 1);
        }
        
        return $result;
    }
    
    /**
     * Get tree as string (shows the whole tree structure)
     * 
     * @return string Tree representation
     */
    public function __toString(): string
    {
        // Helper function for recursive tree printing
        $printNode = function (SemanticNode $node, int $level = 0) use (&$printNode) {
            $indent = str_repeat('  ', $level);
            $str = $indent . (string)$node . "\n";
            foreach ($node->getChildren() as $child) {
                $str .= $printNode($child, $level + 1);
            }
            return $str;
        };

        if ($this->root === null) {
            return "SemanticTree(nodes=0, root=none)\n";
        }

        $treeStr = "SemanticTree(nodes={$this->nodeCount}, root={$this->root->id})\n";
        $treeStr .= $printNode($this->root);

        return $treeStr;
    }
}
