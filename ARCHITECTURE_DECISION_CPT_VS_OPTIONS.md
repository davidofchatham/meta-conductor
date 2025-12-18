# Architecture Decision: CPT vs wp_options for Rule Storage

**Decision Context**: Planning merge of bws-user-based-terms and bws-meta-manager
**Date**: 2025-12-18
**Status**: Under Consideration

---

## Current State Analysis

### BWS Meta Manager (wp_options approach)
**Storage Method**: Single serialized array in `bws_taxonomy_manager_settings`
```php
[
    'hierarchical_rules' => [
        [0] => ['taxonomy' => 'category', 'enabled' => true, ...],
        [1] => ['taxonomy' => 'post_tag', 'enabled' => true, ...]
    ],
    'propagation_rules' => [...],
    'related_rules' => [...],
    // 6 rule types total
]
```

**Implementation**:
- Settings class: 1,338 lines
- Single custom settings page
- Manual import/export would require custom implementation
- All rules loaded into memory on every request

### BWS User Based Terms (CPT approach)
**Storage Method**: Each rule is a `bws_user_term_rule` CPT post
```php
Post ID: 123
Post Title: "Auto-assign categories for editors"
Meta:
    _target_post_types => ['post', 'page']
    _target_taxonomy => 'category'
    _user_roles => ['editor']
    _term_ids => [4, 5, 6]
    _rule_enabled => true
    _priority => 10
```

**Implementation**:
- Clean OOP model class (236 lines)
- Native WordPress edit screen
- Native import/export via WordPress tools
- Query-based rule loading (only when needed)

---

## Comparison Matrix

| Criterion | wp_options (Current) | CPT (User-Terms) | Winner |
|-----------|---------------------|------------------|---------|
| **Development Speed** | | | |
| Initial setup | ⚡ Fast - single option | 🐌 Slow - CPT registration + meta boxes | wp_options |
| Adding new fields | 🔧 Modify sanitization + UI | 🔧 Add meta box + meta key | Tie |
| Adding new rule types | 📝 Add array key + tab | 📝 Add taxonomy or meta flag | Tie |
| **User Experience** | | | |
| Import/Export | ❌ Custom implementation required | ✅ Native WP tools work | **CPT** |
| Search/Filter | ❌ Must build custom | ✅ Native list table filtering | **CPT** |
| Bulk operations | ❌ Custom UI needed | ✅ Native bulk actions | **CPT** |
| Editing experience | ⚠️ Custom tabs/accordions | ⚠️ Meta boxes (order issues) | Tie |
| Rule organization | ❌ Flat arrays by type | ✅ Categories, tags, folders | **CPT** |
| **Performance** | | | |
| Read all rules | 🐌 Load entire array (6 types) | ⚡ Query specific types | **CPT** |
| Read single rule | 🐌 Load all, filter in PHP | ⚡ Single post query | **CPT** |
| Update single rule | 🐌 Load all, modify, save all | ⚡ Update single post | **CPT** |
| Memory usage | ❌ All rules always in memory | ✅ Only loaded rules in memory | **CPT** |
| Database queries | ⚡ 1 query for all rules | 🐌 N queries for N rules | wp_options |
| **Data Integrity** | | | |
| Versioning | ❌ No history | ✅ Post revisions available | **CPT** |
| Trash/Restore | ❌ Permanent deletion | ✅ Trash bin with restore | **CPT** |
| Concurrent edits | ⚠️ Last write wins | ✅ Post locking prevents conflicts | **CPT** |
| Data validation | ✅ Sanitization on save | ✅ Sanitization on save | Tie |
| **Scalability** | | | |
| 10 rules | ✅ Excellent | ✅ Excellent | Tie |
| 100 rules | ⚠️ Large serialized array | ✅ Indexed DB queries | **CPT** |
| 1000+ rules | ❌ Memory/performance issues | ✅ Paginated queries | **CPT** |
| **Extensibility** | | | |
| Third-party plugins | ❌ Custom integration needed | ✅ Standard CPT hooks | **CPT** |
| REST API | ❌ Custom endpoints | ✅ Auto-generated endpoints | **CPT** |
| WP-CLI access | ❌ Custom commands | ✅ Standard post commands | **CPT** |
| GraphQL (WPGraphQL) | ❌ Custom resolvers | ✅ Auto-registered | **CPT** |
| **Developer Experience** | | | |
| Code complexity | ⚠️ 1,338-line settings class | ✅ Modular meta box classes | **CPT** |
| Testing | ❌ Mock entire settings array | ✅ Test individual posts | **CPT** |
| Debugging | ❌ Inspect serialized data | ✅ View posts in admin | **CPT** |
| Code reuse | ❌ Tightly coupled to settings | ✅ Standard WP patterns | **CPT** |

**Score: CPT wins 15 / wp_options wins 2 / Tie 6**

---

## Detailed Analysis

### 1. Performance Deep Dive

#### wp_options Approach
```php
// Current implementation
$all_settings = get_option('bws_taxonomy_manager_settings');
// Loads ALL 6 rule types (hierarchical, propagation, related, time-based, etc.)
// Even if you only need hierarchical rules

// To get enabled hierarchical rules:
foreach ($all_settings['hierarchical_rules'] as $rule) {
    if ($rule['enabled']) {
        // Process
    }
}
```

**Issues**:
- Every plugin initialization loads entire settings array
- No way to query "give me only enabled rules for taxonomy X"
- Must deserialize, iterate, filter in PHP

#### CPT Approach
```php
// Query only what you need
$enabled_rules = get_posts([
    'post_type' => 'bws_meta_rule',
    'meta_query' => [
        ['key' => '_rule_enabled', 'value' => '1'],
        ['key' => '_target_taxonomy', 'value' => 'category']
    ],
    'posts_per_page' => -1
]);
```

**Benefits**:
- Database does the filtering (indexed, fast)
- Only load rules you need
- WordPress object cache handles caching automatically

**Performance Recommendation**:
- **< 50 rules**: Both approaches perform similarly
- **50-200 rules**: CPT starts to show benefits
- **200+ rules**: CPT is significantly better

### 2. User Experience Deep Dive

#### Import/Export Comparison

**wp_options**: Custom implementation required
```php
// Must build:
// 1. Export JSON file generation
// 2. Import JSON parsing + validation
// 3. Conflict resolution (overwrite vs merge?)
// 4. UI for upload/download
// Estimated: 500-800 lines of code
```

**CPT**: Built-in WordPress functionality
- **Export**: Tools → Export → Select "Meta Rules" → Download XML
- **Import**: Tools → Import → WordPress Importer → Upload XML
- **Zero code required** ✅

#### Search/Filter Comparison

**Current bws-meta-manager UI**:
```
Settings → BWS Taxonomy Manager
  ├─ Hierarchical Rules (tab)
  │   ├─ Rule 1 (accordion)
  │   ├─ Rule 2 (accordion)
  │   └─ Rule 3 (accordion)
  ├─ Propagation Rules (tab)
  └─ Related Rules (tab)

❌ No search box
❌ No filtering by taxonomy
❌ No sorting by enabled/disabled
❌ Must manually scan through tabs
```

**CPT Approach**:
```
Meta Rules (list table)
  [Search box] [Filter by: Taxonomy ▼] [Filter by: Status ▼]

  ☑ Rule Name          | Taxonomy  | Post Types | Status   | Date
  ────────────────────────────────────────────────────────────────
  ☑ Auto Category      | category  | post, page | Enabled  | Dec 17
  ☑ Tag Hierarchy      | post_tag  | post       | Disabled | Dec 15
  ☑ Location Terms     | location  | event      | Enabled  | Dec 10

✅ Native WordPress search
✅ Filter dropdowns
✅ Bulk enable/disable
✅ Quick edit inline
```

### 3. The Meta Box Ordering Issue

You mentioned: *"small UI issues such as metaboxes not appearing in logical order"*

**Problem**: WordPress renders meta boxes in this order:
1. `high` priority (sidebar)
2. `high` priority (normal)
3. `default` priority (sidebar)
4. `default` priority (normal)
5. `low` priority

**Current bws-user-based-terms order**:
```php
Target Selection (side, high)      ← Shows first (sidebar)
User Conditions (normal, high)     ← Shows second (main area)
Terms to Preset (normal, high)     ← Shows third (main area)
Lock Settings (side, default)      ← Shows fourth (sidebar) ❌ Should be with Target
Rule Options (side, default)       ← Shows fifth (sidebar)
```

**Solutions**:

#### Option A: All same priority (cleanest)
```php
add_meta_box('target',    'Target',    'callback', $cpt, 'side',   'high');
add_meta_box('options',   'Options',   'callback', $cpt, 'side',   'high');
add_meta_box('lock',      'Lock',      'callback', $cpt, 'side',   'high');
add_meta_box('users',     'Users',     'callback', $cpt, 'normal', 'high');
add_meta_box('terms',     'Terms',     'callback', $cpt, 'normal', 'high');
```
Result: All sidebar boxes together, all main boxes together ✅

#### Option B: Use `default_hidden` meta boxes
```php
add_meta_box('advanced', 'Advanced', 'callback', $cpt, 'normal', 'default');
// Then hide by default:
add_filter('default_hidden_meta_boxes', function($hidden, $screen) {
    if ('bws_meta_rule' === $screen->post_type) {
        $hidden[] = 'advanced';
    }
    return $hidden;
}, 10, 2);
```

#### Option C: Custom meta box order filter
```php
add_filter('get_user_option_meta-box-order_bws_meta_rule', function($order) {
    return [
        'side' => 'target,options,lock',
        'normal' => 'users,terms',
        'advanced' => ''
    ];
});
```

**Recommendation**: Use Option A (all same priority) - simplest and most predictable.

---

## Migration Path Analysis

### Scenario A: Keep wp_options (Status Quo)

**Short-term** (Now):
```
✅ No migration needed
✅ Continue current development
❌ Miss out on CPT benefits
❌ Duplicate effort when plugins merge
```

**Long-term** (Plugin Merge):
```
Decision needed: Convert bws-user-based-terms to wp_options?
├─ Lose: Import/export, search, filtering, revisions
├─ Gain: Consistency (both use same storage)
└─ Effort: Medium (rewrite UI, lose native features)
```

### Scenario B: Migrate to CPT

**Short-term** (Now):
```
⏱️ Development time: 40-60 hours estimated
├─ Create CPT registration
├─ Create meta box UI for 6 rule types
├─ Build migration tool from wp_options → CPT
├─ Update all handlers to query CPT instead of options
└─ Test existing functionality

✅ Gain all CPT benefits immediately
✅ Ready for merge when time comes
❌ Significant upfront investment
```

**Long-term** (Plugin Merge):
```
✅ Both plugins use CPT
✅ Just need to merge rule types (taxonomy mapping)
✅ Shared meta box components
└─ Effort: Low (mostly configuration)
```

### Scenario C: Hybrid Approach

**Short-term** (Now):
```
Keep wp_options BUT add abstraction layer:

interface Rule_Storage {
    public function get_rules($type, $filters = []);
    public function save_rule($type, $rule_id, $data);
    public function delete_rule($type, $rule_id);
}

class Option_Rule_Storage implements Rule_Storage { ... }
class CPT_Rule_Storage implements Rule_Storage { ... }
```

**Long-term** (Plugin Merge):
```
✅ Swap storage backend via config
✅ Minimal code changes in handlers
✅ Can gradually migrate rule types
└─ Best of both worlds
```

---

## Recommendations

### 🎯 Primary Recommendation: **Hybrid with CPT Target**

**Phase 1: Add Abstraction Layer** (Now - 8-16 hours)
```php
// Create storage interface
interface BWS_Rule_Storage {
    public function get_rules(string $type, array $filters = []): array;
    public function get_rule(string $type, int $rule_id): ?array;
    public function save_rule(string $type, int $rule_id, array $data): bool;
    public function delete_rule(string $type, int $rule_id): bool;
    public function search_rules(string $query): array;
}

// Implement option storage (current behavior)
class BWS_Option_Rule_Storage implements BWS_Rule_Storage {
    // Wraps existing get_option() calls
}

// Update handlers to use interface instead of direct get_option()
```

**Benefits**:
- ✅ Doesn't break existing functionality
- ✅ Prepares for future migration
- ✅ Improves code architecture
- ✅ Testability improves
- ⏱️ Minimal time investment

**Phase 2: Implement CPT Storage** (Before Merge - 32-48 hours)
```php
class BWS_CPT_Rule_Storage implements BWS_Rule_Storage {
    // Maps rule types to CPT meta keys
    // Converts between array format and post/meta format
}

// Add migration tool
class BWS_Storage_Migrator {
    public function migrate_from_options_to_cpt() { ... }
}
```

**Phase 3: Switch Default + Migrate** (During Merge)
```php
// Config switch
define('BWS_RULE_STORAGE', 'cpt'); // vs 'options'

// Run migration for existing sites
if (get_option('bws_migrated_to_cpt') !== true) {
    $migrator = new BWS_Storage_Migrator();
    $migrator->migrate_from_options_to_cpt();
    update_option('bws_migrated_to_cpt', true);
}
```

---

## Decision Factors

### Choose wp_options if:
- ❌ You plan to merge plugins in < 3 months (too soon to justify migration)
- ❌ You expect < 50 total rules ever
- ❌ Import/export is not important
- ❌ You have limited development time

### Choose CPT if:
- ✅ Merging plugins is 6+ months away (time to benefit from CPT)
- ✅ You expect 100+ rules eventually
- ✅ Import/export is valuable
- ✅ You want better UX (search, filter, bulk actions)
- ✅ You value native WordPress patterns
- ✅ REST API / WP-CLI access matters

### Choose Hybrid if:
- ✅ You want flexibility
- ✅ You value good architecture
- ✅ Migration timing is uncertain
- ✅ You want to test CPT before full commitment

---

## Implementation Checklist (If choosing CPT)

### 1. CPT Registration
```php
☐ Register 'bws_meta_rule' post type
☐ Add custom capabilities (manage_meta_rules)
☐ Disable Gutenberg (use classic editor)
☐ Set menu icon and position
☐ Configure supports (title, revisions)
```

### 2. Rule Type Taxonomy
```php
☐ Register 'bws_rule_type' taxonomy
☐ Terms: hierarchical, propagation, related, time_based, etc.
☐ Set as hierarchical (for grouping)
☐ Add to CPT registration
```

### 3. Meta Boxes (per rule type)
```php
☐ Hierarchical: taxonomy, direction, depth, expansion, post_types
☐ Propagation: taxonomy, source_post_type, target_relationship
☐ Related: trigger_type, source_taxonomy, target_taxonomy
☐ Time Based: taxonomy, schedule_type, date_field, terms
☐ Related Post Terms: acf_field, source_tax, target_tax
☐ Level Restriction: taxonomy, mode, level, include_ancestors
☐ Common: enabled toggle, priority, description
```

### 4. List Table Customization
```php
☐ Custom columns (Rule Type, Taxonomy, Status, Priority)
☐ Sortable columns
☐ Filter dropdowns (by rule type, taxonomy, status)
☐ Row actions (Enable/Disable, Duplicate, Test)
☐ Bulk actions (Enable All, Disable All, Delete)
```

### 5. Migration Tool
```php
☐ Read current wp_options settings
☐ Create posts for each rule
☐ Set taxonomy term (rule type)
☐ Add post meta (all rule fields)
☐ Maintain enabled/disabled status
☐ Preserve rule order via priority
☐ Add rollback capability
☐ Logging and error handling
```

### 6. Update Rule Engine
```php
☐ Change BWS_Unified_Handler_Base::get_enabled_rules()
☐ Query CPT instead of get_option()
☐ Cache results (WP object cache)
☐ Maintain backward compatibility during transition
```

### 7. Testing
```php
☐ Unit tests for Rule model
☐ Integration tests for storage interface
☐ Migration test (options → CPT → verify)
☐ Performance test (100+ rules)
☐ UI testing (meta boxes, list table)
```

---

## Cost-Benefit Summary

### wp_options (Keep Current)
**Cost**: $0 (no change)
**Benefit**: Continue development, no disruption
**Risk**: Technical debt, harder merge later

### CPT (Full Migration)
**Cost**: 40-60 hours development time
**Benefit**: Native WP features, better UX, easier merge
**Risk**: Bugs during migration, temporary disruption

### Hybrid (Recommended)
**Cost**: 8-16 hours (Phase 1), 32-48 hours (Phase 2 when ready)
**Benefit**: Best architecture, flexible timing, testable
**Risk**: Minimal (abstraction adds small overhead)

---

## Questions to Consider

1. **Timeline**: When do you plan to merge the plugins?
   - < 3 months → Stay with wp_options
   - 6-12 months → Implement hybrid now, migrate later
   - 12+ months → Migrate to CPT now

2. **Rule Volume**: How many rules do you expect?
   - < 50 → wp_options is fine
   - 50-200 → CPT recommended
   - 200+ → CPT required for performance

3. **User Base**: Who will manage rules?
   - Developers only → wp_options acceptable
   - Clients/non-technical → CPT provides better UX

4. **Import/Export**: How important is this feature?
   - Critical → CPT (native support)
   - Nice-to-have → Either approach
   - Not needed → Either approach

5. **Development Resources**: How much time can you invest?
   - Limited → Stay with wp_options
   - Moderate → Implement hybrid
   - Ample → Migrate to CPT

---

## Final Recommendation

**Implement the Hybrid Approach with Phase 1 now:**

1. ✅ Add `BWS_Rule_Storage` interface (2-4 hours)
2. ✅ Implement `BWS_Option_Rule_Storage` wrapper (4-8 hours)
3. ✅ Update handlers to use interface (2-4 hours)
4. ⏸️ Defer CPT implementation until merge is closer

**Why this approach wins**:
- Immediate architecture improvement
- Minimal time investment now
- Prepares for future CPT migration
- No disruption to current development
- Easy to test and validate
- Provides clean abstraction for v2.0

**When to execute Phase 2** (CPT migration):
- When merge timeline becomes clear (6 months out)
- When you hit 100+ rules (performance matters)
- When users request import/export
- When you have 2-3 weeks for focused development

---

**Document Version**: 1.0
**Author**: Claude Code Analysis
**Review Date**: Recommend quarterly review of decision
