/**
 * BWS Taxonomy Manager Admin JavaScript
 * Handles admin interface interactions
 */

(function($) {
    'use strict';
    
    var BWSTaxManager = {
        
        init: function() {
            this.bindEvents();
            this.initTabs();
            this.initRuleManagement();
        },
        
        bindEvents: function() {
            $(document).on('click', '.nav-tab', this.switchTab);
            $(document).on('click', '.add-rule-btn', this.addRule);
            $(document).on('click', '.delete-rule', this.deleteRule);
            $(document).on('click', '.process-existing-btn', this.processExisting);
            $(document).on('change', '.rule-field', this.validateRule);
            $(document).on('change', '.taxonomy-select', this.updateTaxonomyFields);
            $(document).on('change', '.post-type-select', this.updatePostTypeFields);
            $(document).on('change', '.trigger-type-radio', this.toggleTriggerFields);
        },
        
        initTabs: function() {
            // Show the active tab (determined by PHP)
            var $activeTab = $('.nav-tab-active');
            var $activeContent = $('.tab-content.active');
            
            // Hide all tab content except active
            $('.tab-content').not('.active').hide();
            
            // Show active content
            $activeContent.show();
            
            // If no active tab found, default to first
            if ($activeTab.length === 0) {
                $('.nav-tab').first().addClass('nav-tab-active');
                $('.tab-content').first().addClass('active').show();
            }
        },
        
        switchTab: function(e) {
            e.preventDefault();
            
            var $tab = $(this);
            var target = $tab.attr('href');
            var tabName = $tab.data('tab');
            
            // Update tab navigation
            $('.nav-tab').removeClass('nav-tab-active');
            $tab.addClass('nav-tab-active');
            
            // Show target tab content
            $('.tab-content').removeClass('active').hide();
            $(target).addClass('active').show();
            
            // Update the current tab input for form persistence
            $('#current_tab').val(tabName);
        },
        
        initRuleManagement: function() {
            this.updateRuleIndexes();
            this.bindRuleEvents();
            this.initializeExistingRules();
        },
        
        initializeExistingRules: function() {
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
			
			// Initialize new rule
			this.initializeRule($newRule, ruleType);
			
			// Update indexes
			this.updateRuleIndexes();
		},
        
        deleteRule: function(e) {
            e.preventDefault();
            
            if (confirm(bwsTaxManager.strings.confirm_delete || 'Are you sure you want to delete this rule?')) {
                var $rule = $(this).closest('.bws-rule-item');
                var ruleType = $rule.data('rule-type');
                
                $rule.remove();
                
                // Update indexes for this rule type
                BWSTaxManager.updateRuleIndexes(ruleType);
            }
        },
        
        initializeRule: function($rule, ruleType) {
            // Initialize select2 for all selects with basic styling
            if ($.fn.select2) {
                $rule.find('select').select2({
                    width: '100%',
                    placeholder: 'Select...',
                    allowClear: true
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
			
			var confirmMessage = bwsTaxManager.strings.confirm_process;
			
			// Custom confirmation messages for new rule types
			if (ruleType === 'related_post_terms') {
				confirmMessage = 'This will sync terms from related posts to main posts. Continue?';
			} else if (ruleType === 'hierarchical_level_restriction') {
				confirmMessage = 'This will apply level restrictions to existing taxonomy terms. This may remove some terms. Continue?';
			}
			
			if (!confirm(confirmMessage)) {
				return;
			}
			
			$btn.prop('disabled', true).text(bwsTaxManager.strings.processing);
			
			BWSTaxManager.runBatchProcess(ruleType, 0, $btn);
		},
        
        runBatchProcess: function(ruleType, offset, $btn) {
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
                        
                        // Update progress
                        $btn.text(data.message);
                        
                        if (!data.complete) {
                            // Continue processing
                            setTimeout(function() {
                                BWSTaxManager.runBatchProcess(ruleType, data.offset, $btn);
                            }, 1000);
                        } else {
                            // Processing complete
                            $btn.text(bwsTaxManager.strings.complete);
                            setTimeout(function() {
                                $btn.prop('disabled', false).text('Process Existing Posts');
                            }, 3000);
                        }
                    } else {
                        BWSTaxManager.showError($btn, response.data);
                    }
                },
                error: function() {
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
			
			if (ruleType === 'related_post_terms') {
				if (!ruleData.post_type) {
					errors.push('Post type is required.');
				}
				if (!ruleData.acf_field_name) {
					errors.push('ACF field name is required.');
				}
				if (!ruleData.source_taxonomy) {
					errors.push('Source taxonomy is required.');
				}
				if (!ruleData.target_taxonomy) {
					errors.push('Target taxonomy is required.');
				}
				if (ruleData.source_taxonomy === ruleData.target_taxonomy) {
					errors.push('Source and target taxonomies must be different.');
				}
			}
			
			if (ruleType === 'hierarchical_level_restriction') {
				if (!ruleData.taxonomy) {
					errors.push('Taxonomy is required.');
				}
				if (!ruleData.restriction_mode) {
					errors.push('Restriction mode is required.');
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
            var $container = $('<div class="validation-message error"></div>');
            
            $.each(errors, function(index, error) {
                $container.append('<p>' + error + '</p>');
            });
            
            $rule.find('.bws-rule-content').prepend($container);
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
						minimumInputLength: 0
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
        }
    };
    
    // Initialize when document is ready
    $(document).ready(function() {
        BWSTaxManager.init();
    });
    
    // Expose globally for debugging
    window.BWSTaxManager = BWSTaxManager;
    
})(jQuery);
