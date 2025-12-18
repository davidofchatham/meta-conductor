# BWS Meta Manager - Development Roadmap

**Current Version**: 2.0.0
**Branch**: `claude/plan-plugin-integration-01J8Rqv5YTEfF1uXyx84SihV`
**Last Updated**: 2025-12-18

---

## Overview

This roadmap outlines the planned development for BWS Meta Manager, including the future merge with BWS User Based Terms plugin and migration to CPT-based rule storage.

---

## ✅ Phase 1: Unified Framework Foundation (COMPLETED)

**Status**: Merged to branch
**Completed**: December 2025

### Achievements
- ✅ Created unified entity abstraction (`BWS_Entity`)
- ✅ Built rule engine with condition/action system
- ✅ Refactored hierarchical handler with v2.0 features
- ✅ Added bidirectional hierarchy support
- ✅ Implemented smart child expansion (3 modes)
- ✅ Created 6 database tables for logging/tracking
- ✅ Improved code quality (input validation, error handling, PHPDoc)
- ✅ **NEW: Implemented storage abstraction layer**

### New Files Added
- `includes/abstracts/interface-bws-rule-storage.php` - Storage interface
- `includes/storage/class-bws-option-rule-storage.php` - Options implementation
- `includes/storage/class-bws-storage-factory.php` - Factory pattern

### Architecture Improvements
```
Before:
Handler → get_option() → wp_options table

After:
Handler → BWS_Storage_Factory → BWS_Rule_Storage Interface
                                   ↓
                         BWS_Option_Rule_Storage → wp_options table
                         (Future: BWS_CPT_Rule_Storage → CPT)
```

**Benefits**:
- Prepares for CPT migration without disrupting current code
- Enables A/B testing of storage backends
- Improves testability and maintainability
- No functional changes - backward compatible

---

## 🔄 Phase 2: Testing & Stabilization (IN PROGRESS)

**Status**: Active
**Timeline**: December 2025 - January 2026

### High Priority ✅ (COMPLETED)
- ✅ Add input validation in BWS_Entity constructor
- ✅ Add error handling to database table creation
- ✅ Security audit (nonce verification)
- ✅ Add comprehensive PHPDoc documentation
- ✅ Implement storage abstraction layer

### Current Tasks
- ⏸️ Verify database tables on test site
- ⏸️ Test smart child expansion with real data
- ⏸️ Performance test with 100+ posts
- ⏸️ Test deep hierarchies (10+ levels)

### Testing Checklist
See `TESTING_PLAN_V2.md` for complete testing procedures.

**Key Tests**:
1. Database table verification
2. Smart expansion behavior validation
3. Bulk processing performance
4. Multi-taxonomy rule conflicts
5. ACF integration testing

---

## 📦 Phase 3: Plugin Integration Planning (Q1 2026)

**Status**: Planning
**Timeline**: February - March 2026

### Goals
Prepare for merging with `bws-user-based-terms` plugin.

### Tasks
1. **Analyze Feature Overlap**
   - Map rule types between plugins
   - Identify unique features in each
   - Plan unified rule taxonomy

2. **UI/UX Unification**
   - Design combined settings interface
   - Plan rule organization strategy
   - Create migration wizard UI

3. **Data Migration Planning**
   - Design import tool for bws-user-based-terms rules
   - Plan rule conflict resolution
   - Create rollback mechanism

### Deliverables
- Feature comparison matrix
- Unified data model design
- Migration tool specification
- UI mockups for combined plugin

---

## 🚀 Phase 4: CPT Migration (Q2 2026)

**Status**: Planned
**Timeline**: April - June 2026
**Prerequisite**: Phase 3 planning complete

### Why Migrate to CPT?

**Benefits** (from `ARCHITECTURE_DECISION_CPT_VS_OPTIONS.md`):
- ✅ Native import/export via WordPress tools
- ✅ Search, filter, bulk operations in list table
- ✅ Post revisions for rule history
- ✅ Trash/restore functionality
- ✅ Post locking prevents concurrent edit conflicts
- ✅ REST API auto-generated
- ✅ WP-CLI support out of the box
- ✅ Better performance with 100+ rules
- ✅ Scales to 1000+ rules efficiently

**Current Preparation**:
- ✅ Storage abstraction layer implemented
- ✅ Interface defined (`BWS_Rule_Storage`)
- ✅ Factory pattern ready for backend switch

### Implementation Steps

#### Step 1: Create CPT Storage Implementation (2 weeks)
```php
// File: includes/storage/class-bws-cpt-rule-storage.php
class BWS_CPT_Rule_Storage implements BWS_Rule_Storage {
    // Implement all interface methods
    // Map rule types to CPT taxonomy terms
    // Convert between array format and post/meta format
}
```

**Tasks**:
- [ ] Register 'bws_meta_rule' custom post type
- [ ] Register 'bws_rule_type' taxonomy
- [ ] Implement meta box system for rule editing
- [ ] Create CPT storage class (15 interface methods)
- [ ] Add unit tests for CPT storage

**Estimated Effort**: 32-40 hours

#### Step 2: Build Migration Tool (1 week)
```php
// File: includes/admin/class-bws-storage-migrator.php
class BWS_Storage_Migrator {
    public function migrate_options_to_cpt() { }
    public function rollback_migration() { }
    public function verify_migration() { }
}
```

**Tasks**:
- [ ] Create migration admin page
- [ ] Build migration progress tracker
- [ ] Implement data verification
- [ ] Add rollback capability
- [ ] Create migration logs

**Estimated Effort**: 16-24 hours

#### Step 3: Update UI Components (1-2 weeks)
**Tasks**:
- [ ] Customize CPT list table columns
- [ ] Add custom filters (taxonomy, status, priority)
- [ ] Implement bulk actions (enable/disable, duplicate)
- [ ] Create meta boxes for each rule type
- [ ] Fix meta box ordering issues
- [ ] Add inline editing capabilities

**Estimated Effort**: 24-32 hours

#### Step 4: Testing & Validation (1 week)
**Tasks**:
- [ ] Test migration with sample data
- [ ] Verify rule execution with CPT storage
- [ ] Performance benchmarks (100, 500, 1000 rules)
- [ ] Test import/export workflows
- [ ] Validate backward compatibility
- [ ] User acceptance testing

**Estimated Effort**: 16-24 hours

**Total Phase 4 Effort**: 88-120 hours (11-15 days)

### Configuration Switch

Once CPT storage is ready, switching is trivial:

**Option A: Via Configuration**
```php
// In wp-config.php or plugin settings
define('BWS_RULE_STORAGE_TYPE', 'cpt');
```

**Option B: Via Filter**
```php
add_filter('bws_meta_manager_storage_type', function() {
    return 'cpt';
});
```

**Option C: Via Admin UI**
```
Settings → BWS Meta Manager → Advanced → Storage Backend
○ WordPress Options (current)
● Custom Post Type (recommended for 100+ rules)
```

### Migration Process

**Step-by-Step Migration for Users**:

1. **Pre-Migration Check**
   ```
   Dashboard notices:
   "Your site has 127 rules. We recommend migrating to CPT storage
   for better performance. [Run Migration Wizard]"
   ```

2. **Migration Wizard**
   ```
   Step 1: Backup
   ✓ Current rules backed up to: wp_options (bws_storage_backup_1234567890)

   Step 2: Migrate
   ⏳ Migrating 127 rules to Custom Post Type storage...
   ✓ Hierarchical rules: 42/42 migrated
   ✓ Propagation rules: 23/23 migrated
   ✓ Related rules: 18/18 migrated
   ...

   Step 3: Verify
   ✓ All rules migrated successfully
   ✓ Rule execution tested and working

   Step 4: Complete
   ✓ Storage backend switched to CPT
   ✓ Old data retained for rollback (can be deleted after 30 days)
   ```

3. **Post-Migration**
   ```
   New features available:
   • Import/Export: Tools → Export → Meta Rules
   • Search/Filter: Meta Rules → Search box
   • Revisions: Meta Rules → Edit → Revisions
   • REST API: /wp-json/wp/v2/bws_meta_rule
   ```

---

## 🔀 Phase 5: Plugin Merge (Q3 2026)

**Status**: Planned
**Timeline**: July - September 2026
**Prerequisite**: Phase 4 complete, both plugins on CPT storage

### Goals
Merge BWS User Based Terms into BWS Meta Manager.

### Pre-Merge Requirements
- ✅ BWS Meta Manager using CPT storage
- ✅ BWS User Based Terms already using CPT storage
- ✅ Feature parity analysis complete (Phase 3)
- ✅ Data model unified

### Merge Strategy

#### Option A: Soft Merge (Recommended)
```
BWS Meta Manager
├─ Hierarchical Rules
├─ Propagation Rules
├─ Related Rules
├─ Time-Based Rules
├─ Related Post Terms Rules
├─ Level Restriction Rules
└─ User-Based Term Rules (NEW - from bws-user-based-terms)
```

**Benefits**:
- Keep bws-user-based-terms as separate module
- Can be enabled/disabled independently
- Shared CPT infrastructure
- Gradual transition for users

#### Option B: Full Merge
```
BWS Meta Manager
├─ All existing rule types
└─ Enhanced with user-targeting from bws-user-based-terms
    (add "User Conditions" to all rule types)
```

**Benefits**:
- Simpler codebase
- Unified UX
- Less maintenance

### Migration Tool for Users

```php
// File: includes/admin/class-bws-plugin-merger.php
class BWS_Plugin_Merger {
    /**
     * Import rules from BWS User Based Terms
     * Maps bws_user_term_rule CPT to bws_meta_rule CPT
     */
    public function import_user_terms_rules() { }
}
```

**Wizard**:
```
BWS Plugin Merge Wizard
─────────────────────────

Step 1: Detect
✓ BWS User Based Terms v1.2.0 detected
✓ Found 23 user-based rules

Step 2: Preview
Rules to import:
• "Auto-assign categories for editors" → Hierarchical Rule (user-filtered)
• "Tag presets for contributors" → User-Based Term Rule
...

Step 3: Import
⏳ Importing 23 rules...
✓ 23 rules imported successfully

Step 4: Complete
✓ Rules migrated to BWS Meta Manager
□ Deactivate BWS User Based Terms (recommended)
□ Delete BWS User Based Terms data
```

### Estimated Effort
- Soft Merge: 40-60 hours
- Full Merge: 80-120 hours

---

## 📊 Phase 6: Advanced Features (Q4 2026)

**Status**: Planned
**Timeline**: October - December 2026

### Planned Features

#### 1. Rule Templates & Presets
```
Quick Start Templates:
• E-commerce Product Categories
• Event Management Hierarchies
• Location-Based Taxonomies
• Multi-Author Publishing Workflows
```

#### 2. Visual Rule Builder
```
[Drag & Drop Interface]
  Source: [Posts] where [Category] is [Electronics]
  ↓
  Action: [Apply Terms] to [Category]
  ↓
  Target: [Child Posts]
```

#### 3. Rule Analytics Dashboard
```
Rule Performance:
• "Category Hierarchy" - 1,234 executions, 98% success rate
• "Auto Tags" - 456 executions, 100% success rate

Most Active Rules:
• Category Hierarchy (342 executions this week)
• Tag Propagation (156 executions)
```

#### 4. Conditional Logic Builder
```
IF [Post Type] is [Product]
AND [Price] > [100]
THEN [Apply Term] "Premium Products"
```

#### 5. WP-CLI Commands
```bash
# Export rules
wp bws-meta rule export --type=hierarchical --format=json > rules.json

# Import rules
wp bws-meta rule import rules.json

# Process rules
wp bws-meta rule process --type=hierarchical --batch=50

# Statistics
wp bws-meta stats
```

---

## 🧪 Phase 7: Performance Optimization (Q1 2027)

**Status**: Planned
**Timeline**: January - March 2027

### Planned Optimizations

#### 1. Query Optimization
- Add database indexes for frequent queries
- Implement query result caching
- Optimize term hierarchy lookups

#### 2. Background Processing
```php
// Use Action Scheduler for large batches
class BWS_Background_Processor {
    public function process_rules_batch($batch_id) {
        // Process 100 posts at a time
        // Queue next batch
    }
}
```

#### 3. Selective Rule Loading
```php
// Only load rules needed for current context
add_filter('bws_load_rules_for_post_type', function($rules, $post_type) {
    // Filter rules by post type before loading
    return $rules;
});
```

#### 4. Performance Benchmarks
- Target: < 0.5s processing time for 100 posts
- Target: < 50MB memory for 1000 rules loaded
- Target: < 5 database queries per rule execution

---

## 🎯 Success Metrics

### Technical Metrics
- **Code Quality**: 90%+ test coverage
- **Performance**: < 0.5s average rule processing
- **Scalability**: Support 1000+ rules efficiently
- **Reliability**: 99.9% rule execution success rate

### User Metrics
- **Ease of Use**: < 10 minutes to create first rule
- **Migration Success**: 95%+ successful CPT migrations
- **Adoption**: 50%+ users on CPT storage by end of 2026

### Business Metrics
- **Plugin Consolidation**: Merge complete by Q3 2026
- **Maintenance**: 50% reduction in support tickets
- **Performance**: 80% of users report faster load times

---

## 🚧 Known Limitations & Technical Debt

### Current Limitations
1. **Storage**: wp_options doesn't scale past 200 rules efficiently
2. **No Import/Export**: Custom implementation required
3. **Limited Search**: Must iterate through PHP arrays
4. **No Revisions**: Lost rule history if overwritten

### Will Be Resolved By
- ✅ **Storage Abstraction** (Phase 1 - DONE)
- 🔄 **CPT Migration** (Phase 4 - Q2 2026)
- 🔄 **Plugin Merge** (Phase 5 - Q3 2026)

---

## 📚 Documentation Roadmap

### To Be Created
- [ ] User Guide: Rule Types & Use Cases
- [ ] Developer Guide: Creating Custom Handlers
- [ ] Migration Guide: wp_options → CPT
- [ ] API Reference: Storage Interface
- [ ] Video Tutorials: Getting Started

### To Be Updated
- [ ] TESTING_PLAN_V2.md (post-CPT migration)
- [ ] README.md (feature list)
- [ ] CHANGELOG.md (version history)

---

## 🔄 Version History

| Version | Release Date | Status | Key Features |
|---------|-------------|--------|--------------|
| 1.0.0 | 2024 | Deprecated | Legacy handlers, wp_options only |
| 2.0.0 | Dec 2025 | Current | Unified framework, storage abstraction |
| 2.1.0 | Q2 2026 | Planned | CPT storage, migration tool |
| 2.5.0 | Q3 2026 | Planned | Plugin merge complete |
| 3.0.0 | Q4 2026 | Planned | Advanced features, visual builder |

---

## 📞 Decision Points

### When to Migrate to CPT?

**Migrate Now If**:
- You have 100+ rules
- Import/export is critical
- You need REST API access
- Performance is a concern

**Wait for Phase 4 If**:
- You have < 50 rules
- Current system works fine
- Limited development time
- Uncertain about merge timing

### Configuration Options

**Development Mode** (test CPT without migrating):
```php
// wp-config.php
define('BWS_RULE_STORAGE_TYPE', 'cpt');
define('BWS_STORAGE_TEST_MODE', true); // Doesn't migrate, just tests
```

**Gradual Rollout** (migrate rule types one at a time):
```php
add_filter('bws_storage_type_for_rule', function($type, $rule_type) {
    // Use CPT only for hierarchical rules
    if ($rule_type === 'hierarchical_rules') {
        return 'cpt';
    }
    return 'options';
}, 10, 2);
```

---

## 🎓 Lessons Learned

### From Phase 1 (Unified Framework)
- ✅ Abstraction layers enable future flexibility
- ✅ Backward compatibility prevents user disruption
- ✅ Comprehensive testing catches edge cases early

### To Apply in Phase 4 (CPT Migration)
- Use feature flags for gradual rollout
- Provide rollback mechanism
- Test with real user data
- Monitor performance metrics

---

## 🤝 Contributing

This is a planning document. For actual development tasks, see:
- `TESTING_PLAN_V2.md` - Current testing priorities
- `ARCHITECTURE_DECISION_CPT_VS_OPTIONS.md` - Storage decision rationale
- GitHub Issues - Active development tasks

---

**Last Reviewed**: 2025-12-18
**Next Review**: 2026-03-01 (Post Phase 3 completion)
**Maintained By**: David (Bridge Web Solutions)
