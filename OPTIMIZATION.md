# Asset Optimization Strategy

## The Challenge

WordPress typically loads ALL assets from active plugins and themes, regardless of whether they're actually used on a specific page. This includes:

- **Bloated CSS/JS** from every active plugin
- **Unused features** loaded "just in case"
- **Late-loading JavaScript** that executes after initial page load
- **Event-based functionality** that only triggers on user interaction

## Our Approach

### 1. **Multi-Phase Rendering**

**Phase 1: Initial Render**
- Standard HTTP request to get base HTML
- Captures all initially loaded assets
- Records WordPress script/style queues

**Phase 2: Late-Loading Analysis** *(Optional)*
- Waits 3 seconds for async content to load
- Re-fetches page to capture dynamically loaded assets
- Compares content to detect significant changes
- Only used if late-loading patterns are detected

**Phase 3: Asset Optimization**
- Analyzes extracted assets for criticality
- Preserves late-loading/event-based JavaScript
- Combines and minifies safe assets
- Removes obviously unused assets

### 2. **Smart Asset Detection**

**Critical Assets** (preserved and prioritized):
- jQuery and WordPress core scripts
- WooCommerce functionality
- Inline scripts with `document.ready` or `DOMContentLoaded`
- Non-async/defer scripts (typically critical)
- All CSS except print stylesheets

**Late-Loading Assets** (preserved as-is):
- Scripts with event listeners (`addEventListener`, `.on()`)
- Timer-based functionality (`setTimeout`, `setInterval`)
- AJAX/fetch operations
- Intersection Observer (lazy loading)
- Dynamic imports
- Scroll/click/hover triggered code

**Unused Assets** (candidates for removal):
- Emoji scripts when no emojis detected
- Embed scripts when no embeds present
- Plugin assets not referenced in HTML

### 3. **Plugin Conflict Management**

**Detected Conflicting Plugins:**
- Autoptimize
- WP Rocket
- W3 Total Cache
- WP Super Cache
- LiteSpeed Cache
- WP Optimize
- SG CachePress
- Hummingbird Performance
- Swift Performance
- Breeze

**Conflict Prevention:**
- Admin warnings when conflicts detected
- Recommendation to disable conflicting plugins
- Option to exclude static pages from other optimizers
- Clear documentation about incompatibilities

### 4. **Optimization Features**

**HTML Minification:**
- Removes unnecessary whitespace
- Preserves formatting in `<pre>`, `<code>`, `<textarea>`, `<script>`
- Removes HTML comments (except build markers)

**CSS Optimization:**
- Combines local CSS files
- Minifies combined CSS
- Adds preload hints for critical CSS
- Experimental unused CSS removal

**JavaScript Optimization:**
- Separates critical from non-critical JS
- Combines safe scripts while preserving late-loading ones
- Maintains async/defer attributes
- Preserves event-based functionality

**Performance Enhancements:**
- DNS prefetch for external domains
- Resource preload hints
- Critical resource prioritization

## Usage Recommendations

### ✅ **Safe to Enable When:**
- No other optimization plugins active
- Mostly static content (pages, products)
- Limited dynamic JavaScript functionality
- Testing has been performed on staging

### ⚠️ **Use Caution With:**
- Heavy JavaScript applications
- Complex e-commerce functionality
- Third-party widgets/integrations
- Sites with extensive dynamic content

### ❌ **Don't Use With:**
- Active optimization plugins (conflicts)
- Sites requiring real-time updates
- Complex AJAX-heavy applications
- Untested third-party integrations

## Testing Strategy

1. **Enable optimization on staging first**
2. **Test all interactive functionality:**
   - Forms submission
   - AJAX operations
   - Cart/checkout processes
   - Search functionality
   - User interactions (clicks, hovers, scrolls)
3. **Compare before/after performance:**
   - Page load times
   - JavaScript console errors
   - Functionality breakage
4. **Monitor build logs for optimization metrics**

## Configuration Options

- **Enable Optimization**: Master switch for all optimization features
- **HTML Minification**: Safe whitespace removal
- **CSS Optimization**: Combine and minify stylesheets
- **JavaScript Optimization**: Smart JS combination with late-loading preservation
- **Remove Unused Assets**: Experimental feature for unused asset removal
- **Performance Hints**: Add preload and DNS prefetch directives
- **Second-Pass Analysis**: Capture late-loading content changes

## Technical Implementation

The system uses WordPress's own rendering engine via HTTP requests, ensuring compatibility with all themes and plugins while adding intelligent asset analysis and optimization on top.