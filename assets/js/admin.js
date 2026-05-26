/**
 * BWS Taxonomy Manager Admin JavaScript
 * Handles admin interface interactions
 */

(function($) {
    'use strict';
    
    var BWSTaxManager = {
        hasUnsavedChanges: false,

        init: function() {
            this.bindEvents();
            this.initTabs();
            this.initRuleManagement();
            this.initKeyboardShortcuts();
            this.initUnsavedChangesWarning();
        },
        
        bindEvents: function() {
            $(document).on('click', '.nav-tab', this.switchTab);
            $(document).on('click', '.add-rule-btn', this.addRule);
            $(document).on('click', '.delete-rule', this.deleteRule);
            $(document).on('click', '.enable-rule-btn, .disable-rule-btn', this.toggleRuleEnabled);
            $(document).on('click', '.process-existing-btn', this.processExisting);
            $(document).on('change', '.rule-field', this.validateRule);
            $(document).on('input change', 'select.bws-invalid, input.bws-invalid', this.clearFieldError);
            $(document).on('change', '.taxonomy-select', this.updateTaxonomyFields);
            $(document).on('change', '.post-type-select', this.updatePostTypeFields);
            $(document).on('change', '.trigger-type-radio', this.toggleTriggerFields);
            $(document).on('click', '.move-rule-up',  function() { BWSTaxManager.moveRule('up',   $(this)); });
            $(document).on('click', '.move-rule-down', function() { BWSTaxManager.moveRule('down', $(this)); });

            // Empty state "Add First Rule" buttons - trigger the corresponding add button
            $(document).on('click', '#add-hierarchical-rule-empty', function() {
                $('#add-hierarchical-rule').trigger('click');
            });
            $(document).on('click', '#add-propagation-rule-empty', function() {
                $('#add-propagation-rule').trigger('click');
            });
            $(document).on('click', '#add-related-rule-empty', function() {
                $('#add-related-rule').trigger('click');
            });
            $(document).on('click', '#add-time-based-rule-empty', function() {
                $('#add-time-based-rule').trigger('click');
            });
            $(document).on('click', '#add-related-post-terms-rule-empty', function() {
                $('#add-related-post-terms-rule').trigger('click');
            });
            $(document).on('click', '#add-hierarchical-level-restriction-rule-empty', function() {
                $('#add-hierarchical-level-restriction-rule').trigger('click');
            });
            $(document).on('click', '#add-title-slug-rule, #add-title-slug-rule-empty', function() {
                BWSTaxManager.addRule('title-slug');
            });

            // Save active tab before form submission
            $('form[action*="bws-taxonomy-manager"]').on('submit', function() {
                var activeTab = $('.tab-content.active').attr('id');
                if (activeTab) {
                    sessionStorage.setItem('bws_active_tab', activeTab);
                }
            });

            // Title & Slug rule: preview
            $(document).on('click', '.preview-title-slug-rule', function() {
                var $btn  = $(this);
                var index = $btn.data('rule-index');
                var nonce = $('#bws_taxonomy_manager_nonce').val();

                $btn.prop('disabled', true).text(bwsTaxManager.strings.processing || 'Previewing...');
                $.post(ajaxurl, { action: 'bws_title_slug_preview', nonce: nonce, rule_index: index })
                 .done(function(res) {
                     if (res.success) {
                         BWSTaxManager.showPreviewModal(res.data);
                     } else {
                         alert((res.data && res.data.message) || 'Preview failed');
                     }
                 })
                 .always(function() { $btn.prop('disabled', false).text('Preview'); });
            });

            // Title & Slug rule: apply to existing posts
            $(document).on('click', '.apply-title-slug-rule', function() {
                var $btn  = $(this);
                var nonce = $('#bws_taxonomy_manager_nonce').val();
                var offset = 0;

                function runBatch() {
                    $.post(ajaxurl, {
                        action: 'bws_title_slug_process_existing',
                        nonce: nonce,
                        batch_size: 50,
                        offset: offset
                    }).done(function(res) {
                        if (res.success) {
                            offset += 50;
                            var shown = Math.min(offset, res.data.total);
                            $btn.text('Processing... ' + shown + '/' + res.data.total);
                            if (!res.data.done) {
                                runBatch();
                            } else {
                                $btn.text('Done (' + res.data.total + ' posts)').prop('disabled', false);
                            }
                        } else {
                            $btn.text('Apply to Existing Posts').prop('disabled', false);
                            alert('Processing failed');
                        }
                    }).fail(function() {
                        $btn.text('Apply to Existing Posts').prop('disabled', false);
                    });
                }

                $btn.prop('disabled', true).text('Processing...');
                runBatch();
            });
        },
        
        initTabs: function() {
            var tabName = null;

            // Check sessionStorage first (for post-save restoration)
            var savedTab = sessionStorage.getItem('bws_active_tab');
            if (savedTab && $('.nav-tab[data-tab="' + savedTab + '"]').length) {
                tabName = savedTab;
                // Clear sessionStorage after using it
                sessionStorage.removeItem('bws_active_tab');
            }

            // If no saved tab, read hash from URL
            if (!tabName) {
                var hash = window.location.hash.substring(1);
                tabName = hash || 'hierarchical'; // Default to first tab
            }

            // Validate tab exists
            if (!$('.nav-tab[data-tab="' + tabName + '"]').length) {
                tabName = 'hierarchical';
            }

            // Activate the tab
            this.activateTab(tabName);

            // Handle hash changes (browser back/forward)
            var self = this;
            $(window).on('hashchange', function() {
                var newHash = window.location.hash.substring(1);
                if (newHash && $('.nav-tab[data-tab="' + newHash + '"]').length) {
                    self.activateTab(newHash);
                }
            });
        },
        
        switchTab: function(e) {
            e.preventDefault();

            var $tab = $(this);
            var tabName = $tab.data('tab');

            // Activate tab (this will also update the URL hash)
            BWSTaxManager.activateTab(tabName);
        },

        activateTab: function(tabName) {
            // Update URL hash
            if (history.pushState) {
                history.pushState(null, null, '#' + tabName);
            } else {
                window.location.hash = '#' + tabName;
            }

            // Update navigation
            $('.nav-tab').removeClass('nav-tab-active')
                        .attr('aria-selected', 'false')
                        .attr('tabindex', '-1');

            var $activeTab = $('.nav-tab[data-tab="' + tabName + '"]');
            $activeTab.addClass('nav-tab-active')
                      .attr('aria-selected', 'true')
                      .attr('tabindex', '0');

            // Update content
            $('.tab-content').removeClass('active').hide();
            $('#' + tabName).addClass('active').show();

            // Update hidden fields (for form submission)
            if ($('#current_tab').length) {
                $('#current_tab').val(tabName);
            }

            // Update save_tab value in the active tab's save section
            $('.bws-tab-actions-container input[name="save_tab"]').val(tabName);

            // Announce tab change to screen readers
            var tabLabel = $activeTab.text().trim();
            this.announceToScreenReader('Switched to ' + tabLabel + ' tab', 'polite');

            // Fire custom event
            $(document).trigger('bws-tab-changed', [tabName]);
        },
        
        initRuleManagement: function() {
            this.updateRuleIndexes();
            this.bindRuleEvents();
            this.initializeExistingRules();
        },
        
        initializeExistingRules: function() {
            // Initialize disabled state for all rules
            $('.bws-rule-item').each(function() {
                var $rule = $(this);
                var $btn = $rule.find('.enable-rule-btn, .disable-rule-btn');
                var isEnabled = $btn.data('enabled') === 1 || $btn.data('enabled') === '1';
                BWSTaxManager.updateRuleDisabledState($rule, isEnabled);
            });

            // Initialize trigger type visibility for existing related rules
            $('.bws-rule-item[data-rule-type="related"]').each(function() {
                var $rule = $(this);
                var $checkedRadio = $rule.find('.trigger-type-radio:checked');
                if ($checkedRadio.length > 0) {
                    BWSTaxManager.toggleTriggerFieldsForRule($rule, $checkedRadio.val());
                }
            });

            // Initialize hierarchy direction for existing hierarchical rules
            $('.bws-rule-item[data-rule-type="hierarchical"]').each(function() {
                var $rule = $(this);
                var $directionSelect = $rule.find('.bws-hierarchy-direction');
                if ($directionSelect.length > 0) {
                    var direction = $directionSelect.val();
                    BWSTaxManager.toggleExpansionBehavior($rule, direction);

                    // Add change handler
                    $directionSelect.on('change', function() {
                        BWSTaxManager.toggleExpansionBehavior($rule, $(this).val());
                    });
                }
            });

            // Initialize existing title-slug rules
            $('#title-slug-rules-container .bws-rule-item').each(function() {
                BWSTaxManager.initTitleSlugRule($(this));
            });
            BWSTaxManager.updateMoveButtons($('#title-slug-rules-container'));
        },
        
        toggleTriggerFieldsForRule: function($rule, triggerType) {
            if (triggerType === 'term') {
                $rule.find('.trigger-term-row').show();
                $rule.find('.trigger-taxonomy-row').hide();
            } else if (triggerType === 'taxonomy') {
                $rule.find('.trigger-term-row').hide();
                $rule.find('.trigger-taxonomy-row').show();
            }
        },

        toggleExpansionBehavior: function($rule, direction) {
            var $expansionRow = $rule.find('.bws-expansion-behavior-row');

            // Show expansion behavior only for parent_to_child or both
            if (direction === 'parent_to_child' || direction === 'both') {
                $expansionRow.show();
            } else {
                $expansionRow.hide();
            }
        },
        
        bindRuleEvents: function() {
            // Add rule buttons
            $('#add-hierarchical-rule').on('click', function() {
                BWSTaxManager.addRule('hierarchical');
            });
            
            $('#add-propagation-rule').on('click', function() {
                BWSTaxManager.addRule('propagation');
            });
            
            $('#add-related-rule').on('click', function() {
                BWSTaxManager.addRule('related');
            });
            
            $('#add-time-based-rule').on('click', function() {
                BWSTaxManager.addRule('time_based');
            });

			$('#add-related-post-terms-rule').on('click', function() {
				BWSTaxManager.addRule('related_post_terms');
			});
			
			$('#add-hierarchical-level-restriction-rule').on('click', function() {
				BWSTaxManager.addRule('hierarchical_level_restriction');
			});

        },
        
		addRule: function(ruleType) {
			var template = $('#' + ruleType.replace(/_/g, '-') + '-rule-template').html();
			var container = $('#' + ruleType.replace(/_/g, '-') + '-rules-container');
			var index = container.find('.bws-rule-item').length;

			// Replace placeholder index
			template = template.replace(/\{\{INDEX\}\}/g, index);

			var $newRule = $(template);
			container.append($newRule);

			// Hide empty state and show container if this is the first rule
			if (index === 0) {
				container.closest('.bws-rules-section').find('.bws-empty-state').hide();
				container.show();
			}

			// Initialize new rule
			this.initializeRule($newRule, ruleType);

			// Update indexes
			this.updateRuleIndexes();
		},
        
        deleteRule: function(e) {
            e.preventDefault();

            var $btn = $(this);
            var $rule = $btn.closest('.bws-rule-item');
            var ruleType = $rule.data('rule-type');
            var $container = $('#' + ruleType.replace(/_/g, '-') + '-rules-container');

            // Get rule index from the first field name in the rule
            var $firstField = $rule.find('input, select').first();
            var fieldName = $firstField.attr('name');
            var ruleIndex = -1;
            if (fieldName) {
                var match = fieldName.match(/\[(\d+)\]/);
                if (match) {
                    ruleIndex = parseInt(match[1]);
                }
            }

            // Show accessible confirmation modal
            BWSTaxManager.showConfirmation(
                bwsTaxManager.strings.confirm_delete || 'Are you sure you want to delete this rule? This action cannot be undone.',
                {
                    title: 'Delete Rule',
                    confirmText: 'Delete',
                    cancelText: 'Cancel',
                    confirmClass: 'button-primary button-danger'
                }
            ).then(function() {
                // Show loading state
                $btn.prop('disabled', true).addClass('is-loading');
                $rule.addClass('is-loading');

                $.ajax({
                    url: bwsTaxManager.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'bws_delete_rule',
                        nonce: bwsTaxManager.nonce,
                        rule_type: ruleType,
                        rule_index: ruleIndex
                    },
                    success: function(response) {
                        if (response.success) {
                            // Remove from DOM
                            $rule.remove();

                            // Show empty state if no rules remain
                            if ($container.find('.bws-rule-item').length === 0) {
                                $container.hide();
                                $container.closest('.bws-rules-section').find('.bws-empty-state').show();
                            }

                            // Update indexes for this rule type
                            BWSTaxManager.updateRuleIndexes(ruleType);

                            // Show success message
                            BWSTaxManager.showNotice(response.data.message, 'success');

                            // Announce deletion to screen readers
                            BWSTaxManager.announceToScreenReader('Rule deleted', 'polite');
                        } else {
                            // Remove loading state
                            $btn.prop('disabled', false).removeClass('is-loading');
                            $rule.removeClass('is-loading');

                            BWSTaxManager.showNotice(response.data.message || 'Failed to delete rule', 'error', $rule);
                            BWSTaxManager.announceToScreenReader(response.data.message || 'Failed to delete rule', 'assertive');
                        }
                    },
                    error: function() {
                        // Remove loading state
                        $btn.prop('disabled', false).removeClass('is-loading');
                        $rule.removeClass('is-loading');

                        BWSTaxManager.showNotice('An error occurred. Please try again.', 'error', $rule);
                    }
                });
            }).catch(function() {
                // User cancelled - do nothing
            });
        },

        toggleRuleEnabled: function(e) {
            e.preventDefault();

            var $btn = $(this);
            var $rule = $btn.closest('.bws-rule-item');
            var ruleType = $btn.data('rule-type');
            var ruleIndex = $btn.data('rule-index');
            var currentEnabled = $btn.data('enabled') === 1 || $btn.data('enabled') === '1';
            var newEnabled = !currentEnabled;

            // Show loading state
            $btn.prop('disabled', true).addClass('is-loading');
            var originalText = $btn.text();

            $.ajax({
                url: bwsTaxManager.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bws_toggle_rule_enabled',
                    nonce: bwsTaxManager.nonce,
                    rule_type: ruleType,
                    rule_index: ruleIndex,
                    enabled: newEnabled
                },
                success: function(response) {
                    if (response.success) {
                        // Update button state
                        $btn.data('enabled', newEnabled ? 1 : 0);
                        $btn.text(newEnabled ? 'Disable' : 'Enable');
                        $btn.removeClass(newEnabled ? 'enable-rule-btn button-primary' : 'disable-rule-btn button-secondary');
                        $btn.addClass(newEnabled ? 'disable-rule-btn button-secondary' : 'enable-rule-btn button-primary');

                        // Update hidden field
                        $rule.find('.rule-enabled-field').val(newEnabled ? '1' : '0');

                        // Update rule disabled state UI
                        BWSTaxManager.updateRuleDisabledState($rule, newEnabled);

                        // Show success message
                        BWSTaxManager.showNotice(response.data.message, 'success', $rule);

                        // Announce to screen readers
                        BWSTaxManager.announceToScreenReader(response.data.message, 'polite');
                    } else {
                        BWSTaxManager.showNotice(response.data.message || 'Failed to update rule', 'error', $rule);
                        $btn.text(originalText);

                        // Announce error to screen readers
                        BWSTaxManager.announceToScreenReader(response.data.message || 'Failed to update rule', 'assertive');
                    }
                },
                error: function() {
                    BWSTaxManager.showNotice('An error occurred. Please try again.', 'error', $rule);
                    $btn.text(originalText);
                },
                complete: function() {
                    $btn.prop('disabled', false).removeClass('is-loading');
                }
            });
        },

        updateRuleDisabledState: function($rule, isEnabled) {
            if (isEnabled) {
                $rule.removeClass('rule-disabled');
                $rule.find('.bws-rule-content input, .bws-rule-content select, .bws-rule-content textarea')
                    .prop('disabled', false)
                    .attr('aria-disabled', 'false');
            } else {
                $rule.addClass('rule-disabled');
                $rule.find('.bws-rule-content input, .bws-rule-content select, .bws-rule-content textarea')
                    .prop('disabled', true)
                    .attr('aria-disabled', 'true');
            }
        },

        showNotice: function(message, type, $context) {
            var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');

            if ($context && $context.length) {
                $context.prepend($notice);
            } else {
                $('.wrap h1').after($notice);
            }

            // Auto-dismiss after 3 seconds
            setTimeout(function() {
                $notice.fadeOut(function() {
                    $(this).remove();
                });
            }, 3000);
        },
        
        initializeRule: function($rule, ruleType) {
            // Initialize select2 for all selects with standardized styling
            if ($.fn.select2) {
                $rule.find('select:not(.select2-hidden-accessible)').each(function() {
                    var $select = $(this);
                    var isMultiple = $select.prop('multiple');

                    $select.select2({
                        width: '100%',
                        placeholder: isMultiple ? 'Select options...' : 'Select an option...',
                        allowClear: true,
                        language: {
                            noResults: function() {
                                return 'No results found';
                            },
                            searching: function() {
                                return 'Searching...';
                            }
                        }
                    });
                });
            }

            // Pre-load terms for term-select dropdowns
            $rule.find('.term-select').each(function() {
                var $select = $(this);
                if (!$select.hasClass('terms-loaded') && $select.find('option').length <= 1) {
                    BWSTaxManager.loadAllTermsForSelect($select);
                }
            });

            // Initialize date pickers for time-based rules
            if (ruleType === 'time_based' && $.fn.datepicker) {
                $rule.find('input[type="date"]').datepicker({
                    dateFormat: 'yy-mm-dd'
                });
            }

            // Initialize hierarchy direction handling for hierarchical rules
            if (ruleType === 'hierarchical') {
                var $directionSelect = $rule.find('.bws-hierarchy-direction');

                // Set up change handler
                $directionSelect.on('change', function() {
                    BWSTaxManager.toggleExpansionBehavior($rule, $(this).val());
                });

                // Initialize on load
                var currentDirection = $directionSelect.val();
                if (currentDirection) {
                    BWSTaxManager.toggleExpansionBehavior($rule, currentDirection);
                }
            }

            // Initialize trigger fields for related rules
            if (ruleType === 'related') {
                var $checkedRadio = $rule.find('.trigger-type-radio:checked');
                if ($checkedRadio.length > 0) {
                    BWSTaxManager.toggleTriggerFieldsForRule($rule, $checkedRadio.val());
                } else {
                    // Default to 'term' if nothing is selected
                    $rule.find('.trigger-type-radio[value="term"]').prop('checked', true);
                    BWSTaxManager.toggleTriggerFieldsForRule($rule, 'term');
                }
            }

			// Initialize ACF field name validation for related post terms
			if (ruleType === 'related_post_terms') {
				$rule.find('input[name*="[acf_field_name]"]').on('blur', function() {
					BWSTaxManager.validateAcfFieldName($(this));
				});
			}
			
			// Initialize restriction mode handling for level restrictions
			if (ruleType === 'hierarchical_level_restriction') {
				$rule.find('input[name*="[restriction_mode]"]').on('change', function() {
					BWSTaxManager.toggleAncestorOptions($rule, $(this).val());
				});
				
				// Initialize on load
				var $checkedMode = $rule.find('input[name*="[restriction_mode]"]:checked');
				if ($checkedMode.length > 0) {
					BWSTaxManager.toggleAncestorOptions($rule, $checkedMode.val());
				}
			}

			// Initialize title-slug rules
			if (ruleType === 'title-slug') {
				BWSTaxManager.initTitleSlugRule($rule);
				BWSTaxManager.updateMoveButtons($('#title-slug-rules-container'));
			}
				
            // Show the rule content
            $rule.find('.bws-rule-content').show();
        },
        
        updateRuleIndexes: function(ruleType) {
            var selector = ruleType ? 
                '.bws-rule-item[data-rule-type="' + ruleType + '"]' : 
                '.bws-rule-item';
            
            $(selector).each(function(index) {
                var $rule = $(this);
                var currentRuleType = $rule.data('rule-type');
                
                // Update all input names and IDs
                $rule.find('input, select, textarea').each(function() {
                    var $field = $(this);
                    var name = $field.attr('name');
                    var id = $field.attr('id');
                    
                    if (name) {
                        // Replace the index in the name
                        name = name.replace(/\[\d+\]/, '[' + index + ']');
                        $field.attr('name', name);
                    }
                    
                    if (id) {
                        // Update ID if it contains an index
                        id = id.replace(/_\d+_/, '_' + index + '_');
                        $field.attr('id', id);
                    }
                });
                
                // Update labels
                $rule.find('label').each(function() {
                    var $label = $(this);
                    var forAttr = $label.attr('for');
                    
                    if (forAttr) {
                        forAttr = forAttr.replace(/_\d+_/, '_' + index + '_');
                        $label.attr('for', forAttr);
                    }
                });
            });
        },
        
		processExisting: function(e) {
			e.preventDefault();

			var $btn = $(this);
			var ruleType = $btn.data('rule-type');

			var confirmMessage = bwsTaxManager.strings.confirm_process ||
				'This will apply these rules to all existing posts. Continue?';

			// Custom confirmation messages for new rule types
			if (ruleType === 'related_post_terms') {
				confirmMessage = 'This will sync terms from related posts to main posts. This operation may take a few moments. Continue?';
			} else if (ruleType === 'hierarchical_level_restriction') {
				confirmMessage = 'This will apply level restrictions to existing taxonomy terms. This may remove some terms from posts. Continue?';
			}

			// Show accessible confirmation modal
			BWSTaxManager.showConfirmation(confirmMessage, {
				title: 'Process Existing Posts',
				confirmText: 'Process',
				cancelText: 'Cancel'
			}).then(function() {
				// Show loading state
				$btn.prop('disabled', true).addClass('is-loading');
				var $rule = $btn.closest('.bws-rule-item');
				$rule.addClass('is-loading');

				// Add loading overlay
				var $overlay = $('<div class="bws-loading-overlay"><div class="bws-loading-spinner"></div><span class="bws-loading-text">Processing...</span></div>');
				$rule.append($overlay);

				BWSTaxManager.runBatchProcess(ruleType, 0, $btn, $overlay);
			}).catch(function() {
				// User cancelled - do nothing
			});
		},
        
        runBatchProcess: function(ruleType, offset, $btn, $overlay) {
            var $rule = $btn.closest('.bws-rule-item');

            // Update overlay text with progress
            if ($overlay && $overlay.find('.bws-loading-text').length) {
                $overlay.find('.bws-loading-text').text('Processing batch ' + (Math.floor(offset / 50) + 1) + '...');
            }

            $.ajax({
                url: bwsTaxManager.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bws_process_existing_posts',
                    rule_type: ruleType,
                    offset: offset,
                    batch_size: 50,
                    nonce: bwsTaxManager.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data;

                        if (!data.complete) {
                            // Continue processing
                            setTimeout(function() {
                                BWSTaxManager.runBatchProcess(ruleType, data.offset, $btn, $overlay);
                            }, 1000);
                        } else {
                            // Processing complete - remove loading state
                            $overlay.remove();
                            $rule.removeClass('is-loading');
                            $btn.removeClass('is-loading').prop('disabled', false);

                            // Show success message
                            BWSTaxManager.showNotice(data.message || 'Processing complete', 'success', $rule);
                        }
                    } else {
                        // Error - remove loading state
                        $overlay.remove();
                        $rule.removeClass('is-loading');
                        $btn.removeClass('is-loading').prop('disabled', false);

                        BWSTaxManager.showError($btn, response.data);
                    }
                },
                error: function() {
                    // Error - remove loading state
                    $overlay.remove();
                    $rule.removeClass('is-loading');
                    $btn.removeClass('is-loading').prop('disabled', false);

                    BWSTaxManager.showError($btn, bwsTaxManager.strings.error);
                }
            });
        },
        
        showError: function($btn, message) {
            $btn.text(message).addClass('error');
            setTimeout(function() {
                $btn.prop('disabled', false)
                    .removeClass('error')
                    .text('Process Existing Posts');
            }, 3000);
        },

		// Validate ACF field names
		validateAcfFieldName: function($input) {
			var fieldName = $input.val().trim();
			var $rule = $input.closest('.bws-rule-item');
			
			// Clear previous validation messages
			$rule.find('.acf-field-validation').remove();
			
			if (fieldName === '') {
				return;
			}
			
			// Basic validation - check if it looks like a field name
			if (!/^[a-zA-Z_][a-zA-Z0-9_]*$/.test(fieldName)) {
				BWSTaxManager.showFieldValidation($input, 'Field name should contain only letters, numbers, and underscores, starting with a letter or underscore.', 'warning');
				return;
			}
			
			// AJAX validation could be added here to check if field exists
			BWSTaxManager.validateAcfFieldExists($input, fieldName);
		},
		
		// Validate if ACF field exists
		validateAcfFieldExists: function($input, fieldName) {
			$.ajax({
				url: bwsTaxManager.ajaxurl,
				type: 'POST',
				data: {
					action: 'bws_validate_acf_field',
					field_name: fieldName,
					nonce: bwsTaxManager.nonce
				},
				success: function(response) {
					if (response.success) {
						if (response.data.exists) {
							BWSTaxManager.showFieldValidation($input, 'Field found: ' + response.data.field_type, 'success');
						} else {
							BWSTaxManager.showFieldValidation($input, 'Field not found. Make sure the field name is correct.', 'warning');
						}
					}
				},
				error: function() {
					// Silently fail - field validation is not critical
				}
			});
		},
		
		// Show field validation message
		showFieldValidation: function($input, message, type) {
			var $validation = $('<div class="acf-field-validation validation-message ' + type + '">' + message + '</div>');
			$input.after($validation);
			
			// Auto-remove after 5 seconds
			setTimeout(function() {
				$validation.fadeOut(function() {
					$(this).remove();
				});
			}, 5000);
		},
		
		// Toggle ancestor options based on restriction mode
		toggleAncestorOptions: function($rule, mode) {
			var $ancestorOption = $rule.find('input[name*="[include_ancestors]"]').closest('tr');
			
			if (mode === 'deepest_only') {
				$ancestorOption.show();
			} else {
				$ancestorOption.hide();
				// Uncheck the option when hidden
				$rule.find('input[name*="[include_ancestors]"]').prop('checked', false);
			}
		},
        
		clearFieldError: function(e) {
			var $field = $(this);
			var errorId = $field.attr('aria-describedby');

			// Remove error styling and ARIA attributes
			$field.removeClass('bws-invalid')
			      .removeAttr('aria-invalid aria-describedby');

			// Remove associated error message
			if (errorId) {
				$('#' + errorId).remove();
			}

			// Check if there are any remaining errors in the rule
			var $rule = $field.closest('.bws-rule-item');
			if ($rule.find('.bws-invalid').length === 0) {
				// All errors cleared - remove summary
				$rule.find('.bws-rule-validation-message').fadeOut(function() {
					$(this).remove();
				});
			}
		},

		validateRule: function(e) {
			var $field = $(this);
			var $rule = $field.closest('.bws-rule-item');
			var ruleType = $rule.data('rule-type');
			
			// Clear previous validation messages
			$rule.find('.validation-message').not('.acf-field-validation').remove();
			
			// Collect rule data
			var ruleData = BWSTaxManager.collectRuleData($rule);
			
			// Client-side validation for new rule types
			var clientValidation = BWSTaxManager.clientSideValidation(ruleType, ruleData);
			if (!clientValidation.valid) {
				BWSTaxManager.showValidationErrors($rule, clientValidation.errors);
				return;
			}
			
			// Server-side validation via AJAX
			$.ajax({
				url: bwsTaxManager.ajaxurl,
				type: 'POST',
				data: {
					action: 'bws_validate_rule',
					rule_type: ruleType,
					rule_data: ruleData,
					nonce: bwsTaxManager.nonce
				},
				success: function(response) {
					if (!response.success && response.data.errors) {
						BWSTaxManager.showValidationErrors($rule, response.data.errors);
					}
				}
			});
		},
		
		// Client-side validation for new rule types
		clientSideValidation: function(ruleType, ruleData) {
			var errors = [];

			// Common validation for all rule types
			if (ruleType === 'hierarchical') {
				if (!ruleData.taxonomy) {
					errors.push({ field: 'taxonomy', message: 'Taxonomy is required.' });
				}
			}

			if (ruleType === 'propagation') {
				if (!ruleData.parent_post_type) {
					errors.push({ field: 'parent_post_type', message: 'Parent post type is required.' });
				}
				if (!ruleData.taxonomy) {
					errors.push({ field: 'taxonomy', message: 'Taxonomy is required.' });
				}
			}

			if (ruleType === 'related') {
				if (!ruleData.trigger_taxonomy && !ruleData.trigger_terms) {
					errors.push({ field: 'trigger_taxonomy', message: 'Trigger taxonomy or terms must be specified.' });
				}
				if (!ruleData.target_taxonomy) {
					errors.push({ field: 'target_taxonomy', message: 'Target taxonomy is required.' });
				}
			}

			if (ruleType === 'time_based') {
				if (!ruleData.taxonomy) {
					errors.push({ field: 'taxonomy', message: 'Taxonomy is required.' });
				}
				if (!ruleData.terms || ruleData.terms.length === 0) {
					errors.push({ field: 'terms', message: 'At least one term is required.' });
				}
			}

			if (ruleType === 'related_post_terms') {
				if (!ruleData.post_type) {
					errors.push({ field: 'post_type', message: 'Post type is required.' });
				}
				if (!ruleData.acf_field_name) {
					errors.push({ field: 'acf_field_name', message: 'ACF field name is required.' });
				}
				if (!ruleData.source_taxonomy) {
					errors.push({ field: 'source_taxonomy', message: 'Source taxonomy is required.' });
				}
				if (!ruleData.target_taxonomy) {
					errors.push({ field: 'target_taxonomy', message: 'Target taxonomy is required.' });
				}
				if (ruleData.source_taxonomy && ruleData.target_taxonomy &&
				    ruleData.source_taxonomy === ruleData.target_taxonomy) {
					errors.push({ field: 'target_taxonomy', message: 'Source and target taxonomies must be different.' });
				}
			}

			if (ruleType === 'hierarchical_level_restriction') {
				if (!ruleData.taxonomy) {
					errors.push({ field: 'taxonomy', message: 'Taxonomy is required.' });
				}
				if (!ruleData.restriction_mode) {
					errors.push({ field: 'restriction_mode', message: 'Restriction mode is required.' });
				}
			}

			return {
				valid: errors.length === 0,
				errors: errors
			};
		},
        
        collectRuleData: function($rule) {
            var data = {};
            
            $rule.find('input, select, textarea').each(function() {
                var $field = $(this);
                var name = $field.attr('name');
                
                if (name) {
                    // Extract field name from the full name
                    var fieldName = name.match(/\[([^\]]+)\]$/);
                    if (fieldName) {
                        if ($field.attr('type') === 'checkbox') {
                            if ($field.is(':checked')) {
                                if (!data[fieldName[1]]) {
                                    data[fieldName[1]] = [];
                                }
                                data[fieldName[1]].push($field.val());
                            }
                        } else {
                            data[fieldName[1]] = $field.val();
                        }
                    }
                }
            });
            
            return data;
        },
        
        showValidationErrors: function($rule, errors) {
            // Clear existing error messages and ARIA attributes
            $rule.find('.bws-field-error').remove();
            $rule.find('.bws-rule-validation-message').remove();
            $rule.find('select, input').removeClass('bws-invalid').removeAttr('aria-invalid aria-describedby');

            if (errors.length === 0) {
                return;
            }

            // Create rule-level error summary
            var $summary = $('<div class="bws-rule-validation-message" role="alert"></div>');
            $summary.append('<p><strong>Please fix the following errors:</strong></p>');
            var $errorList = $('<ul></ul>');

            $.each(errors, function(index, error) {
                // Add to summary
                $errorList.append('<li>' + error.message + '</li>');

                // Find and mark the specific field as invalid
                if (error.field) {
                    var $field = $rule.find('[name*="[' + error.field + ']"]').first();
                    if ($field.length) {
                        var errorId = 'error-' + error.field + '-' + Math.random().toString(36).substr(2, 9);

                        // Add error message below field
                        var $errorMsg = $('<span class="bws-field-error" id="' + errorId + '">' + error.message + '</span>');
                        $field.after($errorMsg);

                        // Mark field as invalid with ARIA
                        $field.addClass('bws-invalid')
                              .attr('aria-invalid', 'true')
                              .attr('aria-describedby', errorId);
                    }
                }
            });

            $summary.append($errorList);
            $rule.find('.bws-rule-content').prepend($summary);

            // Announce errors to screen readers
            var errorCount = errors.length;
            var announcement = errorCount + ' validation ' + (errorCount === 1 ? 'error' : 'errors') + ' found. Please review the form.';
            BWSTaxManager.announceToScreenReader(announcement, 'assertive');

            // Focus first invalid field
            var $firstInvalid = $rule.find('.bws-invalid').first();
            if ($firstInvalid.length) {
                $firstInvalid.focus();
            }
        },


		// Enhanced term selection for ACF fields
		initializeTermSelects: function($rule) {
			var $termSelects = $rule.find('select.term-select');
			
			$termSelects.each(function() {
				var $select = $(this);
				var isMultiple = $select.prop('multiple');
				
				if ($.fn.select2) {
					$select.select2({
						width: '100%',
						placeholder: isMultiple ? 'Select terms...' : 'Select a term...',
						allowClear: true,
						ajax: {
							url: bwsTaxManager.ajaxurl,
							type: 'POST',
							dataType: 'json',
							delay: 250,
							data: function(params) {
								return {
									action: 'bws_search_terms',
									search: params.term || '',
									taxonomy: $select.data('taxonomy') || '',
									nonce: bwsTaxManager.nonce
								};
							},
							processResults: function(data) {
								if (data.success) {
									return {
										results: data.data.terms.map(function(term) {
											// Standardized format: "Name (Taxonomy Label)"
											return {
												id: term.term_id,
												text: term.name + ' (' + term.taxonomy_label + ')'
											};
										})
									};
								}
								return { results: [] };
							},
							cache: true
						},
						minimumInputLength: 0,
						language: {
							noResults: function() {
								return 'No terms found';
							},
							searching: function() {
								return 'Searching...';
							},
							inputTooShort: function() {
								return 'Start typing to search for terms';
							}
						}
					});
				}
			});
		},
		
		// Initialize all enhanced features for new rules
		initializeEnhancedFeatures: function($rule, ruleType) {
			// Initialize enhanced term selects
			this.initializeTermSelects($rule);
			
			// Initialize taxonomy change handlers
			$rule.find('.taxonomy-select').on('change', function() {
				BWSTaxManager.updateDependentFields($(this), $rule);
			});
			
			// Initialize post type change handlers
			$rule.find('.post-type-select').on('change', function() {
				BWSTaxManager.updatePostTypeDependentFields($(this), $rule);
			});
		},
		
		// Update dependent fields when taxonomy changes
		updateDependentFields: function($taxonomySelect, $rule) {
			var taxonomy = $taxonomySelect.val();
			var $dependentSelects = $rule.find('select.term-select[data-depends-on="' + $taxonomySelect.attr('name') + '"]');
			
			$dependentSelects.each(function() {
				var $select = $(this);
				$select.data('taxonomy', taxonomy);
				
				// Clear current selection and reload options
				if ($.fn.select2) {
					$select.val(null).trigger('change');
				} else {
					$select.empty().append('<option value="">Select term...</option>');
				}
			});
		},
		
		// Update fields when post type changes
		updatePostTypeDependentFields: function($postTypeSelect, $rule) {
			var postType = $postTypeSelect.val();
			
			// Load taxonomies for this post type
			if (postType) {
				$.ajax({
					url: bwsTaxManager.ajaxurl,
					type: 'POST',
					data: {
						action: 'bws_get_post_type_taxonomies',
						post_type: postType,
						nonce: bwsTaxManager.nonce
					},
					success: function(response) {
						if (response.success) {
							BWSTaxManager.updateTaxonomySelects($rule, response.data.taxonomies);
						}
					}
				});
			}
		},
        
        updateTaxonomyFields: function(e) {
            var $select = $(this);
            var taxonomy = $select.val();
            var $rule = $select.closest('.bws-rule-item');
            
            if (!taxonomy) {
                return;
            }
            
            // Load terms for this taxonomy via AJAX
            BWSTaxManager.loadTaxonomyTerms(taxonomy, $rule);
        },
        
        loadTaxonomyTerms: function(taxonomy, $rule) {
            $.ajax({
                url: bwsTaxManager.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bws_get_taxonomy_terms',
                    taxonomy: taxonomy,
                    nonce: bwsTaxManager.nonce
                },
                success: function(response) {
                    if (response.success) {
                        BWSTaxManager.updateTermSelects($rule, response.data.terms);
                    }
                }
            });
        },
        
        updateTermSelects: function($rule, terms) {
            var $termSelects = $rule.find('select.term-select');
            
            $termSelects.each(function() {
                var $select = $(this);
                var currentValue = $select.val();
                
                // Clear current options except the first
                $select.find('option:not(:first)').remove();
                
                // Add new options
                $.each(terms, function(id, term) {
                    var $option = $('<option></option>')
                        .attr('value', term.term_id)
                        .text(term.name);
                    
                    if (currentValue == term.term_id) {
                        $option.prop('selected', true);
                    }
                    
                    $select.append($option);
                });
            });
        },
        
        updatePostTypeFields: function(e) {
            var $select = $(this);
            var postType = $select.val();
            var $rule = $select.closest('.bws-rule-item');
            
            if (!postType) {
                return;
            }
            
            // Update available taxonomies for this post type
            BWSTaxManager.loadPostTypeTaxonomies(postType, $rule);
        },
        
        loadPostTypeTaxonomies: function(postType, $rule) {
            $.ajax({
                url: bwsTaxManager.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bws_get_post_type_taxonomies',
                    post_type: postType,
                    nonce: bwsTaxManager.nonce
                },
                success: function(response) {
                    if (response.success) {
                        BWSTaxManager.updateTaxonomySelects($rule, response.data.taxonomies);
                    }
                }
            });
        },
        
        updateTaxonomySelects: function($rule, taxonomies) {
            var $taxonomySelects = $rule.find('select.taxonomy-select:not(.post-type-select)');
            
            $taxonomySelects.each(function() {
                var $select = $(this);
                var currentValue = $select.val();
                
                // Clear current options except the first
                $select.find('option:not(:first)').remove();
                
                // Add new options
                $.each(taxonomies, function(name, taxonomy) {
                    var $option = $('<option></option>')
                        .attr('value', taxonomy.name)
                        .text(taxonomy.label);
                    
                    if (currentValue === taxonomy.name) {
                        $option.prop('selected', true);
                    }
                    
                    $select.append($option);
                });
            });
        },
        
        /**
         * Toggle trigger type fields for related rules
         */
        toggleTriggerFields: function() {
            var $radio = $(this);
            var $rule = $radio.closest('.bws-rule-item');
            var triggerType = $radio.val();
            
            BWSTaxManager.toggleTriggerFieldsForRule($rule, triggerType);
        },
        
        /**
         * Load all terms for a specific term select dropdown
         */
        loadAllTermsForSelect: function($select) {
            // Skip if already loaded
            if ($select.hasClass('terms-loaded')) {
                return;
            }

            // Debug: Check if bwsTaxManager is defined
            if (typeof bwsTaxManager === 'undefined') {
                console.error('BWS Meta Manager: bwsTaxManager is not defined. Scripts may not be loaded correctly.');
                return;
            }

            // Show loading
            $select.prop('disabled', true);

            $.ajax({
                url: bwsTaxManager.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bws_search_terms',
                    search: '',
                    nonce: bwsTaxManager.nonce
                },
                success: function(response) {
                    if (response.success) {
                        BWSTaxManager.populateTermSelect($select, response.data.terms);
                        $select.addClass('terms-loaded');

                        // Trigger Select2 to update with new options
                        if ($.fn.select2 && $select.hasClass('select2-hidden-accessible')) {
                            $select.trigger('change.select2');
                        }
                    }
                },
                error: function(xhr, status, error) {
                    console.error('BWS Meta Manager: AJAX error:', {
                        status: xhr.status,
                        statusText: xhr.statusText,
                        responseText: xhr.responseText,
                        error: error
                    });
                },
                complete: function() {
                    $select.prop('disabled', false);
                }
            });
        },
        
        /**
         * Populate term select with options
         */
        populateTermSelect: function($select, terms) {
            var currentValue = $select.val();
            var isMultiple = $select.prop('multiple');
            var currentValues = isMultiple ? $select.val() || [] : [currentValue];
            
            // Clear existing options except selected ones
            $select.find('option').each(function() {
                var $option = $(this);
                if ($option.val() && !currentValues.includes($option.val())) {
                    $option.remove();
                }
            });
            
            // Add new options
            $.each(terms, function(index, term) {
                // Skip if option already exists
                if ($select.find('option[value="' + term.term_id + '"]').length > 0) {
                    return;
                }
                
                var optionText = term.name + ' (' + term.taxonomy_label + ')';
                var $option = $('<option></option>')
                    .attr('value', term.term_id)
                    .text(optionText);
                
                $select.append($option);
            });
            
            // Restore selection
            if (isMultiple) {
                $select.val(currentValues);
            } else {
                $select.val(currentValue);
            }
        },

        /**
         * Initialize keyboard shortcuts for accessibility
         */
        initKeyboardShortcuts: function() {
            var self = this;

            // Global keyboard shortcuts
            $(document).on('keydown', function(e) {
                // Ctrl/Cmd + S to save current tab
                if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                    e.preventDefault();
                    var $activeTab = $('.tab-content.active');
                    var $saveBtn = $activeTab.find('input[type="submit"]');
                    if ($saveBtn.length) {
                        $saveBtn.trigger('click');
                    }
                }

                // Escape to dismiss notices
                if (e.key === 'Escape') {
                    $('.notice.is-dismissible').fadeOut();
                }
            });

            // Arrow key navigation for tabs
            $('.nav-tab').on('keydown', function(e) {
                var $tabs = $('.nav-tab');
                var index = $tabs.index(this);
                var $targetTab = null;

                if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
                    e.preventDefault();
                    var nextIndex = (index + 1) % $tabs.length;
                    $targetTab = $tabs.eq(nextIndex);
                } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    var prevIndex = (index - 1 + $tabs.length) % $tabs.length;
                    $targetTab = $tabs.eq(prevIndex);
                } else if (e.key === 'Home') {
                    e.preventDefault();
                    $targetTab = $tabs.first();
                } else if (e.key === 'End') {
                    e.preventDefault();
                    $targetTab = $tabs.last();
                }

                if ($targetTab) {
                    $targetTab.trigger('click').focus();
                }
            });
        },

        /**
         * Announce message to screen readers
         * @param {string} message - Message to announce
         * @param {string} priority - 'polite' or 'assertive'
         */
        announceToScreenReader: function(message, priority) {
            priority = priority || 'polite';
            var $region = $('.bws-sr-only[role="' + (priority === 'assertive' ? 'alert' : 'status') + '"]');

            if (!$region.length) {
                return;
            }

            // Clear and re-populate to trigger announcement
            $region.text('');
            setTimeout(function() {
                $region.text(message);
            }, 100);

            // Clear after announcement
            setTimeout(function() {
                $region.text('');
            }, 3000);
        },

        /**
         * Initialize unsaved changes warning
         */
        initUnsavedChangesWarning: function() {
            var self = this;

            // Track changes to form fields
            $('form[action*="bws-taxonomy-manager"]').on('change input', 'input, select, textarea', function() {
                // Ignore specific fields that don't indicate actual data changes
                if ($(this).attr('name') === 'save_tab' || $(this).attr('name') === 'current_tab') {
                    return;
                }
                self.hasUnsavedChanges = true;
            });

            // Warn before leaving page if there are unsaved changes (external navigation)
            $(window).on('beforeunload', function(e) {
                if (self.hasUnsavedChanges) {
                    var message = 'You have unsaved changes. Are you sure you want to leave this page?';
                    e.returnValue = message;
                    return message;
                }
            });

            // Intercept WordPress admin menu clicks (internal navigation)
            $(document).on('click', '#adminmenu a, .subsubsub a, .wrap a[href*="admin.php"], .wrap a[href*=".php?page="]', function(e) {
                // Skip if no unsaved changes
                if (!self.hasUnsavedChanges) {
                    return true;
                }

                // Skip if this is a link within the same page (e.g., tabs)
                var href = $(this).attr('href');
                if (!href || href.charAt(0) === '#') {
                    return true;
                }

                // Skip if this is the current page
                if (href.indexOf(window.location.pathname) !== -1 && href.indexOf('bws-taxonomy-manager') !== -1) {
                    return true;
                }

                // Prevent navigation and show confirmation
                e.preventDefault();
                e.stopPropagation();

                var targetUrl = href;

                self.showConfirmation(
                    'You have unsaved changes. If you leave this page, your changes will be lost.',
                    {
                        title: 'Unsaved Changes',
                        confirmText: 'Leave Page',
                        cancelText: 'Stay',
                        confirmClass: 'button-primary button-danger'
                    }
                ).then(function() {
                    // User confirmed - clear flag and navigate
                    self.hasUnsavedChanges = false;
                    window.location.href = targetUrl;
                }).catch(function() {
                    // User cancelled - do nothing (stay on page)
                });

                return false;
            });

            // Clear flag when form is successfully submitted
            $('form[action*="bws-taxonomy-manager"]').on('submit', function() {
                self.hasUnsavedChanges = false;
            });

            // Clear flag when AJAX save is successful (for toggle operations)
            $(document).on('bws-rule-saved', function() {
                self.hasUnsavedChanges = false;
            });
        },

        /**
         * Show accessible confirmation modal
         * @param {string} message - Confirmation message
         * @param {Object} options - Modal options (title, confirmText, cancelText, confirmClass)
         * @returns {Promise} - Resolves on confirm, rejects on cancel
         */
        showConfirmation: function(message, options) {
            var self = this;
            options = options || {};

            return new Promise(function(resolve, reject) {
                // Create modal HTML
                var modalId = 'bws-modal-' + Math.random().toString(36).substr(2, 9);
                var $modal = $('<div class="bws-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="' + modalId + '-title">' +
                    '<div class="bws-modal">' +
                        '<div class="bws-modal-header">' +
                            '<h2 class="bws-modal-title" id="' + modalId + '-title">' + (options.title || 'Confirm Action') + '</h2>' +
                        '</div>' +
                        '<div class="bws-modal-body">' + message + '</div>' +
                        '<div class="bws-modal-footer">' +
                            '<button type="button" class="button button-secondary bws-modal-cancel">' + (options.cancelText || 'Cancel') + '</button>' +
                            '<button type="button" class="button ' + (options.confirmClass || 'button-primary') + ' bws-modal-confirm">' + (options.confirmText || 'Confirm') + '</button>' +
                        '</div>' +
                    '</div>' +
                '</div>');

                // Store previously focused element
                var $previouslyFocused = $(document.activeElement);

                // Add to body
                $('body').append($modal);

                // Show modal
                setTimeout(function() {
                    $modal.addClass('active');
                    // Focus confirm button
                    $modal.find('.bws-modal-confirm').focus();
                }, 10);

                // Handle confirm
                $modal.find('.bws-modal-confirm').on('click', function() {
                    self.closeModal($modal, $previouslyFocused);
                    resolve(true);
                });

                // Handle cancel
                $modal.find('.bws-modal-cancel').on('click', function() {
                    self.closeModal($modal, $previouslyFocused);
                    reject(false);
                });

                // Handle Escape key
                $modal.on('keydown', function(e) {
                    if (e.key === 'Escape') {
                        self.closeModal($modal, $previouslyFocused);
                        reject(false);
                    }

                    // Trap focus within modal
                    if (e.key === 'Tab') {
                        var $focusable = $modal.find('button');
                        var $first = $focusable.first();
                        var $last = $focusable.last();

                        if (e.shiftKey && document.activeElement === $first[0]) {
                            e.preventDefault();
                            $last.focus();
                        } else if (!e.shiftKey && document.activeElement === $last[0]) {
                            e.preventDefault();
                            $first.focus();
                        }
                    }
                });

                // Handle click outside modal
                $modal.on('click', function(e) {
                    if ($(e.target).hasClass('bws-modal-overlay')) {
                        self.closeModal($modal, $previouslyFocused);
                        reject(false);
                    }
                });
            });
        },

        /**
         * Close modal and restore focus
         */
        closeModal: function($modal, $previouslyFocused) {
            $modal.removeClass('active');
            setTimeout(function() {
                $modal.remove();
                if ($previouslyFocused && $previouslyFocused.length) {
                    $previouslyFocused.focus();
                }
            }, 300);
        },

        initTitleSlugRule: function($rule) {
            var $slugPattern  = $rule.find('.slug-pattern-field');
            var $slugModeRow  = $rule.find('.slug-mode-row');
            var $escalRow     = $rule.find('.escalation-row');
            var $escalCheck   = $rule.find('.date-escalation-checkbox');
            var $dateFieldRow = $rule.find('.date-field-row');
            var $modeSelect   = $rule.find('.slug-mode-select');
            var modeTouched   = $modeSelect.data('initial') ? true : false;

            function updateSlugControls() {
                var slugPat   = $slugPattern.val().trim();
                var titlePat  = $rule.find('.title-pattern-field').val().trim();
                var hasSlug   = slugPat !== '';
                var hasDT     = slugPat.indexOf('{default_slug}') !== -1;

                $slugModeRow.toggle(hasSlug);
                $escalRow.toggle(hasSlug || titlePat !== '');

                if (hasSlug && hasDT) {
                    $modeSelect.val('replace').prop('disabled', true);
                    $modeSelect.siblings('.slug-mode-hint').show();
                } else {
                    $modeSelect.prop('disabled', false);
                    $modeSelect.siblings('.slug-mode-hint').hide();
                    if (hasSlug && !modeTouched) {
                        $modeSelect.val('prefix');
                    }
                }
            }

            function updateDateField() {
                $dateFieldRow.toggle($escalCheck.is(':checked'));
            }

            $modeSelect.on('change', function() { modeTouched = true; });
            $slugPattern.on('input', updateSlugControls);
            $rule.find('.title-pattern-field').on('input', updateSlugControls);
            $escalCheck.on('change', updateDateField);

            updateSlugControls();
            updateDateField();
        },

        moveRule: function(direction, $btn) {
            var $item      = $btn.closest('.bws-rule-item');
            var $container = $item.closest('[id$="-rules-container"]');

            if (direction === 'up' && $item.prev('.bws-rule-item').length) {
                $item.insertBefore($item.prev('.bws-rule-item'));
            } else if (direction === 'down' && $item.next('.bws-rule-item').length) {
                $item.insertAfter($item.next('.bws-rule-item'));
            }

            BWSTaxManager.updateRuleIndexes();
            BWSTaxManager.updateMoveButtons($container);
        },

        updateMoveButtons: function($container) {
            var $items = $container.find('.bws-rule-item');
            $items.find('.move-rule-up').prop('disabled', false);
            $items.find('.move-rule-down').prop('disabled', false);
            $items.first().find('.move-rule-up').prop('disabled', true);
            $items.last().find('.move-rule-down').prop('disabled', true);
        },

        showPreviewModal: function(data) {
            var html = '<table class="widefat" style="margin-bottom:10px;"><tbody>'
                + '<tr><th style="width:140px;">Title (current)</th><td>' + $('<span>').text(data.current_title).html() + '</td></tr>'
                + '<tr><th>Title (preview)</th><td><strong>' + $('<span>').text(data.preview_title).html() + '</strong></td></tr>'
                + '<tr><th>Slug (current)</th><td><code>' + $('<span>').text(data.current_slug).html() + '</code></td></tr>'
                + '<tr><th>Slug (preview)</th><td><code><strong>' + $('<span>').text(data.preview_slug).html() + '</strong></code></td></tr>'
                + '</tbody></table>';
            if (data.warnings && data.warnings.length) {
                html += '<p class="description">' + $('<span>').text(data.warnings.join(' | ')).html() + '</p>';
            }
            var $dlg = $('<div>').attr('title', 'Rule Preview (post #' + data.post_id + ')').html(html);
            $('body').append($dlg);
            $dlg.dialog({
                modal: true, width: 600,
                buttons: { Close: function() { $(this).dialog('destroy').remove(); } },
                close: function() { $(this).dialog('destroy').remove(); }
            });
        }
    };
    
    // Initialize when document is ready
    $(document).ready(function() {
        BWSTaxManager.init();
    });
    
    // Expose globally for debugging
    window.BWSTaxManager = BWSTaxManager;
    
})(jQuery);
