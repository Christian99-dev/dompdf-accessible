<?php
/**
 * @package dompdf
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace Dompdf;

/**
 * Represents semantic information about a rendered element
 * 
 * This class encapsulates all semantic data extracted from HTML/CSS
 * that is needed for PDF/UA accessibility tagging.
 * 
 * @package dompdf
 */
class SemanticElement
{
    /**
     * Unique identifier for this element
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
     * Parent element ID (null if root/no parent)
     * This is the Frame ID of the parent element
     */
    public readonly ?string $parentId;
    
    /**
     * CSS display property value (e.g., 'block', 'inline', 'none')
     */
    public readonly string $display;
    
    /**
     * Constructor
     * 
     * @param string $id Element identifier (Frame ID as string)
     * @param string $tag HTML tag name
     * @param array<string, string> $attributes HTML attributes
     * @param string $display CSS display value
     * @param string|null $parentId Parent element ID (Frame ID as string)
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
        
        // CSS display:none is not rendered, so not decorative but invisible
        // (We might handle this differently)
        
        return false;
    }

    /**
     * Check if element is a transparent inline styling tag
     * 
     * Transparent inline tags provide visual styling but are not semantically relevant
     * for PDF/UA structure. The parent block-level element is used for tagging instead.
     * 
     * This allows styles like bold, italic, color, etc. to be applied within tagged content
     * without creating nested structure elements that violate PDF/UA rules.
     * 
     * Examples:
     * - <strong>bold text</strong> → Style applied, but parent <p> is tagged
     * - <span style="color:red">red text</span> → Color applied, but parent <p> is tagged
     * - <em>italic text</em> → Style applied, but parent <p> is tagged
     * 
     * @return bool True if this is a transparent inline styling tag
     */
    public function isTransparentInlineTag(): bool
    {
        $transparentTags = [
            'strong',  // Bold text
            'b',       // Bold text (presentational)
            'em',      // Emphasized text (usually italic)
            'i',       // Italic text (presentational)
            'span',    // Generic inline container (for styling)
            'u',       // Underlined text
            's',       // Strikethrough text
            'del',     // Deleted text (strikethrough)
            'ins',     // Inserted text (underline)
            'mark',    // Highlighted text
            'small',   // Smaller text
            'sub',     // Subscript
            'sup',     // Superscript
            'code',    // Inline code (when inside p, not pre)
            'kbd',     // Keyboard input
            'samp',    // Sample output
            'var',     // Variable
            'cite',    // Citation
            'dfn',     // Definition term
            'abbr',    // Abbreviation
            'time',    // Time/date
        ];
        
        return in_array($this->tag, $transparentTags, true);
    }
    
    /**
     * Check if element is a heading
     * 
     * @return bool
     */
    public function isHeading(): bool
    {
        return in_array($this->tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true);
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
     * Get the PDF structure tag that should be used for this element
     * 
     * @return string PDF structure tag (e.g., 'H1', 'P', 'Figure', 'Link')
     */
    public function getPdfStructureTag(): string
    {
        // HTML to PDF tag mapping
        $mapping = [
            'h1' => 'H1',
            'h2' => 'H2',
            'h3' => 'H3',
            'h4' => 'H4',
            'h5' => 'H5',
            'h6' => 'H6',
            'p' => 'P',
            'div' => 'Div',
            'span' => 'Span',
            'section' => 'Sect',
            'article' => 'Art',
            'aside' => 'Aside',
            'nav' => 'Nav',
            'table' => 'Table',
            'tr' => 'TR',
            'th' => 'TH',
            'td' => 'TD',
            'thead' => 'THead',
            'tbody' => 'TBody',
            'tfoot' => 'TFoot',
            'ul' => 'L',
            'ol' => 'L',
            'li' => 'LI',
            'img' => 'Figure',
            'figure' => 'Figure',
            'a' => 'Link',
            'blockquote' => 'BlockQuote',
            'code' => 'Code',
            'pre' => 'Code',
            'strong' => 'Strong',
            'em' => 'Em',
            'b' => 'Strong',
            'i' => 'Em',
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
    // HIERARCHY NAVIGATION
    // Methods for traversing the semantic element tree
    // ========================================================================
    
    /**
     * Find the parent semantic element in the tree
     * 
     * Uses the parentFrameId stored during registration to find the actual parent.
     * Optionally skips transparent inline styling tags.
     * 
     * @param array $semanticRegistry The semantic elements registry (frameId => SemanticElement)
     * @param bool $skipTransparentTags If true, skip transparent inline styling tags (strong, em, span, etc.)
     * @return SemanticElement|null The parent element, or null if not found
     */
    public function findParent(array $semanticRegistry, bool $skipTransparentTags = false): ?SemanticElement
    {
        // No parent ID → root element
        if ($this->parentId === null) {
            return null;
        }
        
        // Look up parent by ID
        $parent = $semanticRegistry[$this->parentId] ?? null;
        
        if ($parent === null) {
            return null;
        }
        
        // If skipping transparent tags, recursively find non-transparent parent
        if ($skipTransparentTags && $parent->isTransparentInlineTag()) {
            return $parent->findParent($semanticRegistry, true);
        }
        
        return $parent;
    }
    
    /**
     * Calculate the depth of this element in the tree
     * 
     * Root elements (no parent) have depth 0, their children have depth 1, etc.
     * This is used for topological sorting to ensure parent StructElems
     * are created before their children in the PDF structure tree.
     * 
     * @param array $semanticRegistry The semantic elements registry (frameId => SemanticElement)
     * @return int Depth in tree (0 = root, 1 = child, 2 = grandchild, etc.)
     */
    public function getDepth(array $semanticRegistry): int
    {
        $depth = 0;
        $current = $this;
        
        // Walk up parent chain until root
        while (true) {
            $parent = $current->findParent($semanticRegistry, false);
            if ($parent === null) {
                break;
            }
            
            $current = $parent;
            $depth++;
            
            // Safety: prevent infinite loops
            if ($depth > 100) {
                SimpleLogger::log("semantic_element_logs", __METHOD__, 
                    "WARNING: Depth exceeded 100 for element {$this->id}, breaking loop"
                );
                break;
            }
        }
        
        return $depth;
    }
    
    /**
     * Collect all ancestors (parent, grandparent, etc.) of this element
     * 
     * This ensures we create StructElems for ALL container elements in the hierarchy,
     * even if they weren't directly rendered (e.g., table, tr, tbody elements).
     * 
     * @param array $semanticRegistry The semantic elements registry (frameId => SemanticElement)
     * @return array Associative array of [frameId => SemanticElement] for all ancestors including self
     */
    public function collectAncestors(array $semanticRegistry): array
    {
        $ancestors = [];
        $current = $this;
        
        // Add this element and all its parents
        while ($current !== null) {
            $ancestors[$current->id] = $current;
            $current = $current->findParent($semanticRegistry, false);
            
            // Safety: prevent infinite loops
            if (count($ancestors) > 100) {
                SimpleLogger::log("semantic_element_logs", __METHOD__, 
                    "WARNING: Ancestor count exceeded 100 for element {$this->id}, breaking loop"
                );
                break;
            }
        }
        
        return $ancestors;
    }
    
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
        
        return sprintf(
            "<%s%s> [%s] (frame_%s)",
            $this->tag,
            $attrs ? ' ' . implode(' ', $attrs) : '',
            $this->display,
            $this->id
        );
    }
}