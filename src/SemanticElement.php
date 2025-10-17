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
     * Dompdf Frame ID
     */
    public readonly int $frameId;
    
    /**
     * CSS display property value (e.g., 'block', 'inline', 'none')
     */
    public readonly string $display;
    
    /**
     * Constructor
     * 
     * @param string $id Element identifier
     * @param string $tag HTML tag name
     * @param array<string, string> $attributes HTML attributes
     * @param int $frameId Dompdf Frame ID
     * @param string $display CSS display value
     */
    public function __construct(
        string $id,
        string $tag,
        array $attributes = [],
        int $frameId = 0,
        string $display = 'block'
    ) {
        $this->id = $id;
        $this->tag = strtolower($tag);
        $this->attributes = $attributes;
        $this->frameId = $frameId;
        $this->display = $display;
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
            "<%s%s> [%s] (frame_%d)",
            $this->tag,
            $attrs ? ' ' . implode(' ', $attrs) : '',
            $this->display,
            $this->frameId
        );
    }
}