/**
 * ACF Data Conversion Tool - Admin JavaScript (UPDATED VERSION)
 * 
 * Handles form interactions, AJAX requests, validation, and progress tracking.
 */

(function($) {
    'use strict';

    // Global variables
    let currentForm = null;
    let conversionInProgress = false;
    let progressInterval = null;
    let lastConversionReport = null;
    let currentConversionSession = null;
    let conversionAborted = false;

    // Initialize when document is ready
    $(document).ready(function() {
        initializeInterface();
        bindEvents();
        initializeFormValidation();
    });

    /**
     * Initialize the interface
     */
    function initializeInterface() {
        // Set up initial form state
        updateFormState();
        
        // Initialize content type handlers
        setupContentTypeHandlers();
        
        // Initialize copy type handlers (formerly move type)
        setupCopyTypeHandlers();
        
        // Initialize map data handlers
        setupMapDataHandlers();
    }

    /**
     * Setup content type change handlers
     */
    function setupContentTypeHandlers() {
        // Copy data tab - using event delegation for better reliability
        $(document).on('change', '#content_type', function() {
            const contentType = $(this).val();
            console.log('Copy content type changed to:', contentType);
            handleContentTypeChange(contentType, 'copy');
            updateFormState();
        });
        
        // Map data tab
        $(document).on('change', '#map_content_type', function() {
            const contentType = $(this).val();
            console.log('Map content type changed to:', contentType);
            handleContentTypeChange(contentType, 'map');
            updateFormState();
        });
    }

    /**
     * Setup copy type change handlers (formerly move type)
     */
    function setupCopyTypeHandlers() {
        $(document).on('change', 'input[name="copy_type"]', function() {
            const copyType = $(this).val();
            console.log('Copy type changed to:', copyType);
            handleCopyTypeChange(copyType);
            updateFormState();
        });
    }

    /**
     * Setup map data handlers
     */
    function setupMapDataHandlers() {
        // Target type change
        $(document).on('change', 'input[name="target_type"]', function() {
            const targetType = $(this).val();
            console.log('Target type changed to:', targetType);
            handleMapTargetTypeChange(targetType);
            updateFormState();
        });
        
        // Source field change for mapping
        $(document).on('change', '#map_source_field', function() {
            const sourceField = $(this).val();
            console.log('Map source field changed to:', sourceField);
            
            if (sourceField) {
                $('#map_target_type_row').show();
                
                const targetType = $('input[name="target_type"]:checked').val();
                if (targetType) {
                    loadFieldOptionsForMapping(sourceField);
                }
            } else {
                $('#map_target_type_row, #map_target_field_row, #map_target_taxonomy_row, #map_batch_size_row').hide();
                $('#field-option-mappings, #taxonomy-option-mappings, #taxonomy-term-assignment').hide();
            }
            
            updateFormState();
        });
        
        // Target field change for mapping
        $(document).on('change', '#map_target_field', function() {
            updateFieldMappings();
            updateFormState();
        });
        
        // Target taxonomy change for mapping
        $(document).on('change', '#map_target_taxonomy', function() {
            const taxonomy = $(this).val();
            if (taxonomy) {
                loadTaxonomyTermsForMapping(taxonomy);
                updateTaxonomyMappings();
            }
            updateFormState();
        });

        // Event handlers for all other relevant form fields
        $(document).on('change', '#source_field, #target_field, #source_taxonomy, #target_taxonomy', function() {
            console.log('Form field changed:', $(this).attr('id'), 'to:', $(this).val());
            updateFormState();
        });

        // Event handlers for post types and taxonomies selection
        $(document).on('change', '#post_types, #post_status, #taxonomies, #map_post_types, #map_post_status, #map_taxonomies', function() {
            updateFormState();
        });
    }

    /**
     * Handle content type change
     */
    function handleContentTypeChange(contentType, context) {
        const prefix = context === 'map' ? 'map_' : '';
        
        // Hide all content-specific rows
        $(`#${prefix}post_types_row, #${prefix}post_status_row, #${prefix}taxonomies_row`).hide();
        
        // Show appropriate rows based on content type
        if (contentType === 'posts') {
            $(`#${prefix}post_types_row, #${prefix}post_status_row`).show();
        } else if (contentType === 'taxonomy_terms') {
            $(`#${prefix}taxonomies_row`).show();
        }
        
        // Show next step
        if (contentType) {
            if (context === 'copy') {
                $('#copy_type_row').show();
            } else {
                $(`#${prefix}source_field_row`).show();
                loadFieldsForContext(contentType, context, 'source');
            }
        } else {
            // Hide subsequent rows
            if (context === 'copy') {
                $('#copy_type_row, #source_field_row, #source_taxonomy_row, #target_field_row, #target_taxonomy_row, #term_assignment_row, #batch_size_row').hide();
            } else {
                $(`#${prefix}source_field_row, #${prefix}target_type_row, #${prefix}target_field_row, #${prefix}target_taxonomy_row, #${prefix}batch_size_row`).hide();
                $('#field-option-mappings, #taxonomy-option-mappings, #taxonomy-term-assignment').hide();
            }
        }
        
        updateFormState();
    }

    /**
     * Handle copy type change (formerly move type)
     */
    function handleCopyTypeChange(copyType) {
        // Hide all copy-specific rows
        $('#source_field_row, #source_taxonomy_row, #target_field_row, #target_taxonomy_row, #term_assignment_row').hide();
        
        const contentType = $('#content_type').val();
        
        if (!contentType) {
            console.log('No content type selected');
            return;
        }
        
        console.log('Copy type changed to:', copyType, 'for content type:', contentType);
        
        switch (copyType) {
            case 'field_to_field':
                $('#source_field_row, #target_field_row').show();
                loadFieldsForContext(contentType, 'copy', 'source');
                loadFieldsForContext(contentType, 'copy', 'target');
                break;
                
            case 'field_to_taxonomy':
                $('#source_field_row, #target_taxonomy_row, #term_assignment_row').show();
                loadFieldsForContext(contentType, 'copy', 'source');
                loadTaxonomiesForContext();
                break;
                
            case 'taxonomy_to_field':
                $('#source_taxonomy_row, #target_field_row').show();
                loadTaxonomiesForContext();
                loadFieldsForContext(contentType, 'copy', 'target');
                break;
                
            case 'taxonomy_to_taxonomy':
                $('#source_taxonomy_row, #target_taxonomy_row, #term_assignment_row').show();
                loadTaxonomiesForContext();
                break;
        }
        
        // Show batch size row if copy type is selected
        if (copyType) {
            $('#batch_size_row').show();
        }
        
        updateFormState();
    }

    /**
     * Handle map target type change
     */
    function handleMapTargetTypeChange(targetType) {
        console.log('Map target type changed to:', targetType);
        
        // Hide all target-specific elements
        $('#map_target_field_row, #map_target_taxonomy_row').hide();
        $('#field-option-mappings, #taxonomy-option-mappings, #taxonomy-term-assignment').hide();
        
        const contentType = $('#map_content_type').val();
        
        if (targetType === 'field') {
            $('#map_target_field_row').show();
            if (contentType) {
                loadFieldsForContext(contentType, 'map', 'target');
            }
        } else if (targetType === 'taxonomy') {
            $('#map_target_taxonomy_row').show();
            loadTaxonomiesForContext('map_target_taxonomy');
            $('#taxonomy-term-assignment').show();
        }
        
        // Show batch size if target type is selected
        if (targetType) {
            $('#map_batch_size_row').show();
        }
        
        updateFormState();
    }

    /**
     * Load fields for context
     */
    function loadFieldsForContext(contentType, context, fieldType = 'source') {
        if (!contentType) {
            console.log('loadFieldsForContext: No content type provided');
            return;
        }
        
        console.log('loadFieldsForContext called with:', {
            contentType: contentType,
            context: context,
            fieldType: fieldType
        });
        
        const postTypes = context === 'map' ? $('#map_post_types').val() : $('#post_types').val();
        const taxonomies = context === 'map' ? $('#map_taxonomies').val() : $('#taxonomies').val();
        
        let fieldTypeFilter = '';
        if (context === 'map' && fieldType === 'source') {
            fieldTypeFilter = 'option_fields'; // Only show option-supporting fields
        }
        
        const requestData = {
            action: 'bws_acf_conversion_get_fields',
            nonce: bwsAcfConversion.nonce,
            context: context,
            content_type: contentType,
            post_types: postTypes || ['any'],
            taxonomies: taxonomies || ['any'],
            field_type_filter: fieldTypeFilter
        };
        
        console.log('AJAX request data:', requestData);
        
        $.post(bwsAcfConversion.ajaxUrl, requestData, function(response) {
            console.log('AJAX response:', response);
            if (response.success) {
                console.log('Fields loaded successfully:', response.data);
                populateFieldSelects(response.data, context, fieldType);
            } else {
                console.error('Failed to load fields:', response);
            }
        }).fail(function(xhr, status, error) {
            console.error('AJAX request failed:', { xhr, status, error });
            console.error('Response text:', xhr.responseText);
        });
    }

    /**
     * Load taxonomies for context
     */
    function loadTaxonomiesForContext(selectId = null) {
        $.post(bwsAcfConversion.ajaxUrl, {
            action: 'bws_acf_conversion_get_taxonomies',
            nonce: bwsAcfConversion.nonce
        }, function(response) {
            if (response.success) {
                populateTaxonomySelects(response.data, selectId);
            }
        });
    }

    /**
     * Load taxonomy terms for mapping
     */
    function loadTaxonomyTermsForMapping(taxonomy) {
        if (!taxonomy) {
            return;
        }
        
        console.log('Loading terms for taxonomy:', taxonomy);
        
        $.post(bwsAcfConversion.ajaxUrl, {
            action: 'bws_acf_conversion_get_taxonomy_terms',
            nonce: bwsAcfConversion.nonce,
            taxonomy: taxonomy
        }, function(response) {
            if (response.success) {
                console.log('Taxonomy terms loaded:', response.data.length, 'terms');
                // Store terms for use in mapping
                window.currentTaxonomyTerms = response.data;
                
                // Update mappings if source field is already selected
                const sourceField = $('#map_source_field').val();
                if (sourceField) {
                    loadFieldOptionsForMapping(sourceField);
                }
            } else {
                console.error('Failed to load taxonomy terms:', response);
                window.currentTaxonomyTerms = [];
            }
        }).fail(function(xhr, status, error) {
            console.error('Failed to load taxonomy terms:', error);
            window.currentTaxonomyTerms = [];
        });
    }

    /**
     * Populate field select elements
     */
    function populateFieldSelects(fields, context, fieldType) {
        console.log('populateFieldSelects called with:', {
            fieldsCount: fields.length,
            context: context,
            fieldType: fieldType
        });
        
        let selectors = [];
        
        if (context === 'copy') {
            if (fieldType === 'source') {
                selectors = ['#source_field'];
            } else {
                selectors = ['#target_field'];
            }
        } else if (context === 'map') {
            if (fieldType === 'source') {
                selectors = ['#map_source_field'];
            } else if (fieldType === 'target') {
                selectors = ['#map_target_field'];
            }
        }
        
        console.log('Target selectors:', selectors);
        
        selectors.forEach(selector => {
            const $select = $(selector);
            console.log('Populating selector:', selector, 'element found:', $select.length > 0);
            
            if ($select.length === 0) {
                console.warn('Selector not found:', selector);
                return;
            }
            
            const currentValue = $select.val();
            
            $select.empty().append('<option value="">Select field...</option>');
            
            let totalFieldsAdded = 0;
            
            fields.forEach(group => {
                console.log('Processing group:', group.title, 'fields:', group.fields ? group.fields.length : 0);
                
                if (group.fields && group.fields.length > 0) {
                    const $optgroup = $('<optgroup>').attr('label', group.title);
                    let groupHasFields = false;
                    
                    group.fields.forEach(field => {
                        console.log('Processing field:', field.label, 'supported:', field.supported, 'type:', field.type);
                        
                        if (field.supported) {
                            const $option = $('<option>')
                                .val(field.key)
                                .text(`${field.label} (${field.name}) - ${field.type}`)
                                .data('field', field);
                            
                            $optgroup.append($option);
                            groupHasFields = true;
                            totalFieldsAdded++;
                            console.log('Added field:', field.label);
                            
                            // Add sub-fields
                            if (field.sub_fields && Array.isArray(field.sub_fields)) {
                                console.log('Processing', field.sub_fields.length, 'sub-fields for', field.label);
                                field.sub_fields.forEach(subField => {
                                    if (subField.supported) {
                                        const $subOption = $('<option>')
                                            .val(subField.key)
                                            .text(`  └ ${subField.label} (${subField.name}) - ${subField.type}`)
                                            .data('field', subField);
                                        
                                        $optgroup.append($subOption);
                                        totalFieldsAdded++;
                                        console.log('Added sub-field:', subField.label);
                                    }
                                });
                            }
                        }
                    });
                    
                    if (groupHasFields) {
                        $select.append($optgroup);
                    }
                }
            });
            
            console.log('Total fields added to', selector, ':', totalFieldsAdded);
            
            // Restore previous value if it exists
            if (currentValue) {
                $select.val(currentValue);
            }

            // Trigger change event to update form state
            $select.trigger('change');
        });
    }

    /**
     * Populate taxonomy select elements
     */
    function populateTaxonomySelects(taxonomies, selectId = null) {
        const selectors = selectId ? [`#${selectId}`] : ['#source_taxonomy', '#target_taxonomy', '#map_target_taxonomy'];
        
        selectors.forEach(selector => {
            const $select = $(selector);
            if ($select.length === 0) return;
            
            const currentValue = $select.val();
            
            $select.empty().append('<option value="">Select taxonomy...</option>');
            
            Object.values(taxonomies).forEach(taxonomy => {
                const label = taxonomy.hierarchical ? 
                    `${taxonomy.label} (Hierarchical)` : 
                    taxonomy.label;
                
                $select.append(
                    $('<option>')
                        .val(taxonomy.name)
                        .text(label)
                );
            });
            
            // Restore previous value if it exists
            if (currentValue) {
                $select.val(currentValue);
            }

            // Trigger change event to update form state
            $select.trigger('change');
        });
    }

    /**
     * Load field options for mapping
     */
    function loadFieldOptionsForMapping(fieldKey) {
        if (!fieldKey) {
            $('#field-option-mappings, #taxonomy-option-mappings').hide();
            return;
        }
        
        $.post(bwsAcfConversion.ajaxUrl, {
            action: 'bws_acf_conversion_get_options',
            nonce: bwsAcfConversion.nonce,
            field_key: fieldKey
        }, function(response) {
            if (response.success && response.data.length > 0) {
                // Show the appropriate mappings section based on target type
                const targetType = $('input[name="target_type"]:checked').val();
                if (targetType === 'field') {
                    displayFieldMappings(response.data);
                } else if (targetType === 'taxonomy') {
                    displayTaxonomyMappings(response.data);
                }
            }
        });
    }

    /**
     * Display field option mappings
     */
    function displayFieldMappings(sourceOptions) {
        const targetFieldKey = $('#map_target_field').val();
        if (!targetFieldKey) return;
        
        // Get target field options
        $.post(bwsAcfConversion.ajaxUrl, {
            action: 'bws_acf_conversion_get_options',
            nonce: bwsAcfConversion.nonce,
            field_key: targetFieldKey
        }, function(response) {
            if (response.success) {
                const targetOptions = response.data;
                let html = '';
                
                sourceOptions.forEach(sourceOption => {
                    html += `
                        <div class="mapping-row">
                            <div class="source-option">
                                <strong>${escapeHtml(sourceOption.label || sourceOption.value)}</strong>
                                <small>(${escapeHtml(sourceOption.value)})</small>
                            </div>
                            <div class="mapping-arrow">→</div>
                            <div class="target-input">
                                <select name="mappings[${escapeHtml(sourceOption.value)}]" class="regular-text mapping-select">
                                    <option value="">${bwsAcfConversion.strings.skip_unmapped || 'Skip this value'}</option>
                                    ${targetOptions.map(targetOption => 
                                        `<option value="${escapeHtml(targetOption.value)}">${escapeHtml(targetOption.label || targetOption.value)}</option>`
                                    ).join('')}
                                </select>
                            </div>
                        </div>
                    `;
                });
                
                $('#field-option-mappings .mappings-content').html(html);
                $('#field-option-mappings').show();
                $('#taxonomy-option-mappings').hide();

                // Bind change events to mapping selects
                $('.mapping-select').on('change', function() {
                    updateFormState();
                });
            }
        });
    }

    /**
     * Display taxonomy option mappings
     */
    function displayTaxonomyMappings(sourceOptions) {
        let html = '';
        
        // Use existing taxonomy terms if available
        const taxonomyTerms = window.currentTaxonomyTerms || [];
        
        sourceOptions.forEach(sourceOption => {
            html += `
                <div class="mapping-row">
                    <div class="source-option">
                        <strong>${escapeHtml(sourceOption.label || sourceOption.value)}</strong>
                        <small>(${escapeHtml(sourceOption.value)})</small>
                    </div>
                    <div class="mapping-arrow">→</div>
                    <div class="target-input">
            `;
            
            if (taxonomyTerms.length > 0) {
                // Show dropdown with existing taxonomy terms
                html += `
                    <select name="mappings[${escapeHtml(sourceOption.value)}]" class="regular-text mapping-select">
                        <option value="">${bwsAcfConversion.strings.skip_unmapped || 'Skip this value'}</option>
                        <option value="__CREATE_NEW__">Create new term...</option>
                        ${taxonomyTerms.map(term => 
                            `<option value="${escapeHtml(term.name)}">${escapeHtml(term.name)}</option>`
                        ).join('')}
                    </select>
                    <input type="text" 
                           name="new_term_${escapeHtml(sourceOption.value)}" 
                           placeholder="Enter new term name" 
                           class="regular-text new-term-input" 
                           style="display: none; margin-top: 5px;">
                `;
            } else {
                // Fallback to text input if no terms loaded
                html += `
                    <input type="text" 
                           name="mappings[${escapeHtml(sourceOption.value)}]" 
                           placeholder="Enter term name (leave empty to skip)" 
                           class="regular-text mapping-input">
                `;
            }
            
            html += `
                    </div>
                </div>
            `;
        });
        
        $('#taxonomy-option-mappings .mappings-content').html(html);
        $('#taxonomy-option-mappings').show();
        $('#field-option-mappings').hide();

        // Bind change events
        $('.mapping-select').on('change', function() {
            const $this = $(this);
            const $newTermInput = $this.siblings('.new-term-input');
            
            if ($this.val() === '__CREATE_NEW__') {
                $newTermInput.show().focus();
                // Set the actual mapping value from the text input
                $newTermInput.on('input', function() {
                    $this.data('custom-value', $(this).val());
                });
            } else {
                $newTermInput.hide();
                $this.removeData('custom-value');
            }
            
            updateFormState();
        });
        
        $('.mapping-input').on('input change', function() {
            updateFormState();
        });
    }

    /**
     * Update field mappings when target field changes
     */
    function updateFieldMappings() {
        const sourceFieldKey = $('#map_source_field').val();
        if (sourceFieldKey) {
            loadFieldOptionsForMapping(sourceFieldKey);
        }
    }

    /**
     * Update taxonomy mappings when target taxonomy changes
     */
    function updateTaxonomyMappings() {
        const sourceFieldKey = $('#map_source_field').val();
        if (sourceFieldKey) {
            loadFieldOptionsForMapping(sourceFieldKey);
        }
    }

    /**
     * Bind event handlers
     */
    function bindEvents() {
        // Form submissions
        $('.bws-acf-conversion-form').on('submit', handleFormSubmissionEnhanced);
        
        // Preview buttons
        $('.bws-preview-btn').on('click', handlePreviewRequest);
        
        // Modal events
        $('.bws-acf-modal-close, .modal-close').on('click', closeModal);
        $('.toggle-log').on('click', toggleDetailedLog);
        
        // Report modal events
        $('.view-conversion-report').on('click', function(e) {
            e.preventDefault();
            showConversionReport();
        });
        
        // Sample expansion in preview
        $(document).on('click', '.sample-header', toggleSampleDetails);
        
        // Window events
        $(window).on('beforeunload', function() {
            if (conversionInProgress) {
                return bwsAcfConversion.strings.confirm_conversion;
            }
        });
    }

    /**
     * Initialize form validation
     */
    function initializeFormValidation() {
        // Bind to all form fields that affect validation
        $(document).on('change input', '.bws-acf-conversion-form select, .bws-acf-conversion-form input[type="radio"], .bws-acf-conversion-form input[type="text"], .bws-acf-conversion-form input[type="checkbox"]', function() {
            console.log('Form field changed for validation:', $(this).attr('name') || $(this).attr('id'));
            updateFormState();
        });

        // Initial state update
        updateFormState();
    }

    /**
     * Handle form submission
     */
    function handleFormSubmission(e) {
        e.preventDefault();
        
        if (conversionInProgress) {
            return false;
        }

        const form = $(this);
        currentForm = form;

        // Validate form
        if (!validateForm(form)) {
            showNotice('Please fix the form errors before proceeding.', 'error');
            return false;
        }

        // Confirm with user
        if (!confirm(bwsAcfConversion.strings.confirm_conversion)) {
            return false;
        }

        // Clear any existing success notices when starting new conversion
        $('.bws-persistent-notice').remove();

        // Start conversion
        startConversion(form);
    }


	/**
	 * Enhanced form submission handler with size estimation
	 */
	function handleFormSubmissionEnhanced(e) {
		e.preventDefault();
		
		if (conversionInProgress) {
			return false;
		}
	
		const form = $(this);
		currentForm = form;
	
		// Validate form
		if (!validateForm(form)) {
			showNotice('Please fix the form errors before proceeding.', 'error');
			return false;
		}
	
		// First, estimate the conversion size
		estimateConversionSize(form);
	}
	
	/**
	 * Estimate conversion size and determine processing approach
	 */
	function estimateConversionSize(form) {
		const formData = prepareFormData(form);
		formData.append('action', 'bws_acf_conversion_estimate_size');
		formData.append('nonce', bwsAcfConversion.nonce);
	
		showModal('Analyzing Conversion', 'Estimating conversion size...');
		
		$.ajax({
			url: bwsAcfConversion.ajaxUrl,
			type: 'POST',
			data: formData,
			processData: false,
			contentType: false,
			dataType: 'json',
			success: function(response) {
				if (response.success) {
					showConversionSizeDialog(response.data, form);
				} else {
					closeModal();
					showNotice('Failed to estimate conversion size: ' + (response.data?.message || 'Unknown error'), 'error');
				}
			},
			error: function(xhr, status, error) {
				closeModal();
				showNotice('Failed to estimate conversion size: ' + error, 'error');
			}
		});
	}
	
	/**
	 * Show conversion size dialog with processing options
	 */
	function showConversionSizeDialog(sizeData, form) {
		const isLargeConversion = sizeData.use_chunked_processing;
		const processingTime = sizeData.estimated_time?.formatted || 'Unknown';
		
		let dialogHtml = `
			<div class="conversion-size-dialog">
				<h3>Conversion Analysis</h3>
				<div class="size-stats">
					<div class="stat">
						<strong>${sizeData.total_items.toLocaleString()}</strong>
						<span>Total Items</span>
					</div>
					<div class="stat">
						<strong>${sizeData.total_batches}</strong>
						<span>Batches</span>
					</div>
					<div class="stat">
						<strong>${processingTime}</strong>
						<span>Est. Time</span>
					</div>
				</div>
		`;
		
		if (isLargeConversion) {
			dialogHtml += `
				<div class="large-conversion-notice">
					<p><strong>Large Conversion Detected</strong></p>
					<p>This conversion will use chunked processing to handle the large dataset safely. 
					   The process will run in multiple AJAX requests to avoid timeouts.</p>
					<div class="processing-options">
						<label>
							<input type="radio" name="processing_mode" value="chunked" checked>
							<strong>Chunked Processing (Recommended)</strong>
							<span>Processes ${sizeData.recommended_chunk_size} batches at a time with progress tracking</span>
						</label>
						<label>
							<input type="radio" name="processing_mode" value="standard">
							<strong>Standard Processing</strong>
							<span>Attempts to process all items at once (may timeout for large datasets)</span>
						</label>
					</div>
				</div>
			`;
		} else {
			dialogHtml += `
				<div class="standard-conversion-notice">
					<p>This conversion will process all items in a single operation.</p>
				</div>
			`;
		}
		
		dialogHtml += `
				<div class="dialog-actions">
					<button type="button" class="button button-secondary" onclick="closeModal()">Cancel</button>
					<button type="button" class="button button-primary" onclick="startConversionWithMode()">Start Conversion</button>
				</div>
			</div>
		`;
		
		$('.status-messages .current-status').html(dialogHtml);
		updateModalProgress(100, 'Ready to proceed');
	}
	
	/**
	 * Start conversion with selected processing mode
	 */
	function startConversionWithMode() {
		const processingMode = $('input[name="processing_mode"]:checked').val() || 'standard';
		
		if (processingMode === 'chunked') {
			startChunkedConversion(currentForm);
		} else {
			startStandardConversion(currentForm);
		}
	}
	
	/**
	 * Start chunked conversion for large datasets
	 */
	function startChunkedConversion(form) {
		if (!form) return;
		
		conversionInProgress = true;
		conversionAborted = false;
		currentConversionSession = null;
		
		updateConversionUI(true);
		showModal('Processing Large Conversion', 'Initializing chunked processing...');
		
		// Show abort button
		$('.bws-acf-modal-footer').prepend(
			'<button type="button" class="button button-secondary abort-conversion">Abort Conversion</button>'
		);
		
		// Bind abort handler
		$('.abort-conversion').on('click', abortConversion);
		
		processNextChunk(form, 0);
	}
	
	/**
	 * Process next chunk in chunked conversion
	 */
	function processNextChunk(form, chunkStart, chunkSize = 5) {
		if (conversionAborted) {
			finishConversion(false, 'Conversion aborted by user.');
			return;
		}
		
		const formData = prepareFormData(form);
		formData.append('action', 'bws_acf_conversion_process_chunk');
		formData.append('nonce', bwsAcfConversion.nonce);
		formData.append('chunk_start', chunkStart);
		formData.append('chunk_size', chunkSize);
		
		if (currentConversionSession) {
			formData.append('session_id', currentConversionSession);
		}
		
		$.ajax({
			url: bwsAcfConversion.ajaxUrl,
			type: 'POST',
			data: formData,
			processData: false,
			contentType: false,
			dataType: 'json',
			timeout: 60000, // 60 second timeout for chunks
			success: function(response) {
				handleChunkResponse(response, form, chunkSize);
			},
			error: function(xhr, status, error) {
				if (status === 'timeout') {
					// Retry with smaller chunk size
					const newChunkSize = Math.max(1, Math.floor(chunkSize / 2));
					logMessage(`Chunk timed out, retrying with smaller size: ${newChunkSize}`, 'warning');
					processNextChunk(form, chunkStart, newChunkSize);
				} else {
					handleConversionError(xhr, status, error);
				}
			}
		});
	}
	
	/**
	 * Handle chunk processing response
	 */
	function handleChunkResponse(response, form, chunkSize) {
		if (!response.success) {
			finishConversion(false, response.errors?.join('; ') || 'Chunk processing failed');
			return;
		}
		
		// Store session ID for subsequent chunks
		if (response.session_id) {
			currentConversionSession = response.session_id;
		}
		
		// Update progress
		const progress = response.progress_percentage || 0;
		const statusMessage = `Processing... ${response.completed_batches || 0}/${response.total_batches || 0} batches (${progress.toFixed(1)}%)`;
		updateModalProgress(progress, statusMessage);
		
		// Log chunk results
		if (response.batch_results) {
			response.batch_results.forEach(batch => {
				const message = `Batch ${batch.batch_number}: ${batch.successful} successful, ${batch.failed} failed`;
				logMessage(message, batch.failed > 0 ? 'warning' : 'success');
			});
		}
		
		if (response.is_complete) {
			// Conversion complete
			lastConversionReport = {
				total_items: response.total_items,
				processed_items: response.overall_processed,
				successful_conversions: response.overall_successful,
				failed_conversions: response.overall_failed,
				total_batches: response.total_batches,
				batch_results: [], // Would need to aggregate from session if needed
				success: response.overall_failed === 0
			};
			
			finishConversion(true, `Conversion completed! Processed ${response.overall_processed} items.`);
		} else {
			// Process next chunk
			setTimeout(() => {
				processNextChunk(form, response.next_chunk_start, chunkSize);
			}, 100); // Small delay to prevent overwhelming the server
		}
	}
	
	/**
	 * Start standard conversion (original method)
	 */
	function startStandardConversion(form) {
		conversionInProgress = true;
		
		updateConversionUI(true);
		showModal('Processing Conversion', 'Starting conversion process...');
		
		const formData = prepareFormData(form);
		formData.append('action', 'bws_acf_conversion_process');
		formData.append('nonce', bwsAcfConversion.nonce);
	
		$.ajax({
			url: bwsAcfConversion.ajaxUrl,
			type: 'POST',
			data: formData,
			processData: false,
			contentType: false,
			dataType: 'json',
			success: function(response) {
				handleConversionResponse(response);
			},
			error: function(xhr, status, error) {
				handleConversionError(xhr, status, error);
			}
		});
	}
	
	/**
	 * Abort chunked conversion
	 */
	function abortConversion() {
		if (confirm('Are you sure you want to abort the conversion? Progress will be lost.')) {
			conversionAborted = true;
			logMessage('Conversion aborted by user', 'warning');
		}
	}
	
	/**
	 * Finish conversion (success or failure)
	 */
	function finishConversion(success, message) {
		conversionInProgress = false;
		updateConversionUI(false);
		
		// Remove abort button
		$('.abort-conversion').remove();
		
		if (success) {
			updateModalProgress(100, message);
			
			// Show report if available
			if (lastConversionReport) {
				displayConversionResults(lastConversionReport);
			}
			
			setTimeout(() => {
				closeModal();
				showPersistentSuccessNotice(message);
			}, 2000);
		} else {
			updateModalProgress(0, 'Error: ' + message);
			logMessage(message, 'error');
			
			setTimeout(() => {
				closeModal();
				showNotice('Conversion failed: ' + message, 'error');
			}, 3000);
		}
	}


    /**
     * Handle preview request
     */
    function handlePreviewRequest(e) {
        e.preventDefault();
        
        const form = $(this).closest('.bws-acf-conversion-form');
        currentForm = form;

        // Validate form
        if (!validateForm(form)) {
            showNotice('Please fix the form errors before generating preview.', 'error');
            return false;
        }

        // Confirm with user
        if (!confirm(bwsAcfConversion.strings.confirm_preview)) {
            return false;
        }

        // Generate preview
        generatePreview(form);
    }

    /**
     * Start conversion process
     */
    function startConversion(form) {
        conversionInProgress = true;
        
        // Update UI
        updateConversionUI(true);
        showModal('Processing Conversion', 'Starting conversion process...');
        
        // Prepare data with proper field name mapping
        const formData = prepareFormData(form);
        formData.append('action', 'bws_acf_conversion_process');
        formData.append('nonce', bwsAcfConversion.nonce);

        // Send AJAX request
        $.ajax({
            url: bwsAcfConversion.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                handleConversionResponse(response);
            },
            error: function(xhr, status, error) {
                handleConversionError(xhr, status, error);
            }
        });
    }

    /**
     * Generate preview
     */
    function generatePreview(form) {
        // Update UI
        showModal('Generating Preview', 'Creating preview samples...');
        updateModalProgress(0, 'Analyzing data...');
        
        // Prepare data with proper field name mapping
        const formData = prepareFormData(form);
        formData.append('action', 'bws_acf_conversion_preview');
        formData.append('nonce', bwsAcfConversion.nonce);
        formData.append('sample_count', '10');

        // Send AJAX request
        $.ajax({
            url: bwsAcfConversion.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                handlePreviewResponse(response);
            },
            error: function(xhr, status, error) {
                handlePreviewError(xhr, status, error);
            }
        });
    }

    /**
     * Prepare form data with proper field name mapping
     */
    function prepareFormData(form) {
        const formData = new FormData(form[0]);
        
        // Handle taxonomy mapping with "create new" options
        $('.mapping-select').each(function() {
            const $select = $(this);
            const customValue = $select.data('custom-value');
            
            if ($select.val() === '__CREATE_NEW__' && customValue) {
                // Replace the select value with the custom text input value
                const fieldName = $select.attr('name');
                formData.delete(fieldName);
                formData.append(fieldName, customValue);
            }
        });
        
        return formData;
    }

    /**
     * Handle conversion response
     */
    function handleConversionResponse(response) {
        conversionInProgress = false;
        updateConversionUI(false);

        if (response.success) {
            // Store report for later display
            lastConversionReport = response;
            
            updateModalProgress(100, 'Conversion completed successfully!');
            displayConversionResults(response);
            
            // Show persistent success message with report link
            setTimeout(() => {
                closeModal();
                showPersistentSuccessNotice(
                    `Conversion completed successfully! Processed ${response.processed_items || 0} items with ${response.successful_conversions || 0} successful and ${response.failed_conversions || 0} failed conversions.`
                );
            }, 2000);
        } else {
            displayConversionErrors(response);
        }
    }

    /**
     * Handle conversion error
     */
    function handleConversionError(xhr, status, error) {
        conversionInProgress = false;
        updateConversionUI(false);
        
        const errorMessage = xhr.responseJSON?.data || error || 'Unknown error occurred';
        updateModalProgress(0, 'Error: ' + errorMessage);
        
        logMessage('Conversion failed: ' + errorMessage, 'error');
        
        setTimeout(() => {
            closeModal();
            showNotice('Conversion failed: ' + errorMessage, 'error');
        }, 3000);
    }

    /**
     * Handle preview response
     */
    function handlePreviewResponse(response) {
        if (response.success) {
            updateModalProgress(100, 'Preview generated successfully!');
            displayPreviewResults(response);
        } else {
            updateModalProgress(0, 'Error: ' + (response.error || 'Unknown error'));
            logMessage('Preview generation failed: ' + response.error, 'error');
        }
    }

    /**
     * Handle preview error
     */
    function handlePreviewError(xhr, status, error) {
        const errorMessage = xhr.responseJSON?.data || error || 'Unknown error occurred';
        updateModalProgress(0, 'Error: ' + errorMessage);
        logMessage('Preview generation failed: ' + errorMessage, 'error');
    }

    /**
     * Show persistent success notice with report link
     */
    function showPersistentSuccessNotice(message) {
        // Remove any existing notices
        $('.bws-persistent-notice').remove();
        
        const notice = $(`
            <div class="notice notice-success bws-persistent-notice">
                <p>
                    ${escapeHtml(message)}
                    <a href="#" class="button button-secondary view-conversion-report" style="margin-left: 10px;">
                        View Conversion Report
                    </a>
                </p>
            </div>
        `);
        
        $('.bws-acf-conversion-wrap h1').after(notice);
        
        // Bind the report viewing event
        notice.find('.view-conversion-report').on('click', function(e) {
            e.preventDefault();
            showConversionReport();
        });
    }

    /**
     * Show conversion report in modal
     */
    function showConversionReport() {
        if (!lastConversionReport) {
            showNotice('No conversion report available.', 'error');
            return;
        }
        
        const reportHtml = generateReportHTML(lastConversionReport);
        $('#bws-acf-conversion-report-modal .report-content').html(reportHtml);
        $('#bws-acf-conversion-report-modal').show();
    }

    /**
     * Generate report HTML
     */
    function generateReportHTML(report) {
        let html = `
            <div class="conversion-report">
                <div class="report-summary">
                    <h4>Conversion Summary</h4>
                    <div class="summary-stats">
                        <div class="summary-stat">
                            <span class="stat-number">${report.total_items || 0}</span>
                            <span class="stat-label">Total Items</span>
                        </div>
                        <div class="summary-stat">
                            <span class="stat-number">${report.processed_items || 0}</span>
                            <span class="stat-label">Processed</span>
                        </div>
                        <div class="summary-stat">
                            <span class="stat-number">${report.successful_conversions || 0}</span>
                            <span class="stat-label">Successful</span>
                        </div>
                        <div class="summary-stat">
                            <span class="stat-number">${report.failed_conversions || 0}</span>
                            <span class="stat-label">Failed</span>
                        </div>
                        <div class="summary-stat">
                            <span class="stat-number">${report.total_batches || 0}</span>
                            <span class="stat-label">Batches</span>
                        </div>
                    </div>
                </div>
        `;
        
        if (report.batch_results && report.batch_results.length > 0) {
            html += '<div class="batch-results"><h4>Batch Details</h4>';
            
            report.batch_results.forEach(batch => {
                const statusClass = batch.failed > 0 ? 'warning' : 'success';
                html += `
                    <div class="batch-item ${statusClass}" style="padding: 10px; margin: 5px 0; border-left: 3px solid ${batch.failed > 0 ? '#ffc107' : '#28a745'}; background: #f8f9fa;">
                        <strong>Batch ${batch.batch_number}:</strong>
                        ${batch.successful} successful, ${batch.failed} failed
                        ${batch.execution_time ? `(${batch.execution_time.toFixed(2)}s)` : ''}
                        
                        ${batch.errors && batch.errors.length > 0 ? 
                            `<div style="margin-top: 5px; font-size: 0.9em; color: #721c24;">
                                <strong>Errors:</strong>
                                <ul style="margin: 5px 0; padding-left: 20px;">
                                    ${batch.errors.map(error => `<li>${escapeHtml(error)}</li>`).join('')}
                                </ul>
                            </div>` : ''
                        }
                    </div>
                `;
            });
            
            html += '</div>';
        }
        
        if (report.errors && report.errors.length > 0) {
            html += `
                <div class="conversion-errors" style="margin-top: 20px;">
                    <h4>Overall Errors</h4>
                    <ul style="padding-left: 20px;">
                        ${report.errors.map(error => `<li>${escapeHtml(error)}</li>`).join('')}
                    </ul>
                </div>
            `;
        }
        
        if (report.warnings && report.warnings.length > 0) {
            html += `
                <div class="conversion-warnings" style="margin-top: 20px;">
                    <h4>Warnings</h4>
                    <ul style="padding-left: 20px; color: #664d03;">
                        ${report.warnings.map(warning => `<li>${escapeHtml(warning)}</li>`).join('')}
                    </ul>
                </div>
            `;
        }
        
        html += '</div>';
        return html;
    }

    /**
     * Display conversion results (for modal)
     */
    function displayConversionResults(response) {
        const resultsHtml = generateReportHTML(response);
        $('.status-messages .current-status').html(resultsHtml);
        
        if (response.batch_results) {
            logBatchResults(response.batch_results);
        }
    }

    /**
     * Display conversion errors
     */
    function displayConversionErrors(response) {
        let errorHtml = '<div class="conversion-errors"><h4>Conversion Errors</h4>';
        
        if (response.errors && response.errors.length > 0) {
            errorHtml += '<ul>';
            response.errors.forEach(error => {
                errorHtml += `<li>${escapeHtml(error)}</li>`;
                logMessage('Error: ' + error, 'error');
            });
            errorHtml += '</ul>';
        }
        
        errorHtml += '</div>';
        $('.status-messages .current-status').html(errorHtml);
    }

    /**
     * Display preview results
     */
    function displayPreviewResults(response) {
        const previewHtml = `
            <div class="preview-results">
                <div class="preview-summary">
                    <h4>Preview Summary</h4>
                    <div class="summary-stats">
                        <div class="summary-stat">
                            <span class="stat-number">${response.summary?.total_samples || 0}</span>
                            <span class="stat-label">Samples</span>
                        </div>
                        <div class="summary-stat">
                            <span class="stat-number">${response.summary?.successful_samples || 0}</span>
                            <span class="stat-label">Successful</span>
                        </div>
                        <div class="summary-stat">
                            <span class="stat-number">${response.summary?.failed_samples || 0}</span>
                            <span class="stat-label">Failed</span>
                        </div>
                    </div>
                    ${displayPreviewSummaryDetails(response.summary, response.conversion_type)}
                </div>
                ${response.samples ? displayPreviewSamples(response.samples) : ''}
            </div>
        `;
        
        $('.status-messages .current-status').html(previewHtml);
        $('.modal-close').show();
    }

    /**
     * Display preview summary details
     */
    function displayPreviewSummaryDetails(summary, conversionType) {
        let html = '';
        
        if (summary.unique_terms && summary.unique_terms.length > 0) {
            html += `
                <div class="summary-detail">
                    <strong>Unique Terms (${summary.unique_terms.length}):</strong>
                    ${summary.unique_terms.slice(0, 10).join(', ')}
                    ${summary.unique_terms.length > 10 ? '...' : ''}
                </div>
            `;
        }
        
        if (summary.mapped_values && summary.mapped_values.length > 0) {
            html += `
                <div class="summary-detail">
                    <strong>Mapped Values:</strong>
                    ${summary.mapped_values.slice(0, 5).join(', ')}
                    ${summary.mapped_values.length > 5 ? '...' : ''}
                </div>
            `;
        }
        
        if (summary.unmapped_values && summary.unmapped_values.length > 0) {
            html += `
                <div class="summary-detail warning">
                    <strong>Unmapped Values (will be skipped):</strong>
                    ${summary.unmapped_values.slice(0, 5).join(', ')}
                    ${summary.unmapped_values.length > 5 ? '...' : ''}
                </div>
            `;
        }
        
        return html;
    }

    /**
     * Display preview samples
     */
    function displayPreviewSamples(samples) {
        let html = '<div class="preview-samples"><h4>Sample Results</h4>';
        
        samples.forEach((sample, index) => {
            const statusClass = sample.success ? 'success' : 'error';
            const title = sample.post_title || sample.term_name || `ID: ${sample.post_id || sample.term_id}`;
            const id = sample.post_id || sample.term_id;
            
            html += `
                <div class="preview-sample">
                    <div class="sample-header" data-index="${index}">
                        <div class="sample-title">
                            ${escapeHtml(title)} (ID: ${id})
                        </div>
                        <div class="sample-status ${statusClass}">
                            ${sample.success ? 'Success' : 'Error'}
                        </div>
                    </div>
                    <div class="sample-details" id="sample-details-${index}">
                        ${displaySampleDetails(sample)}
                    </div>
                </div>
            `;
        });
        
        html += '</div>';
        return html;
    }

    /**
     * Display sample details
     */
    function displaySampleDetails(sample) {
        let html = '';
        
        // Source field/taxonomy
        if (sample.source_field) {
            html += `
                <div class="sample-field">
                    <div class="sample-field-label">Source: ${escapeHtml(sample.source_field.label)}</div>
                    <div class="sample-field-value">${formatValue(sample.source_field.current_value)}</div>
                </div>
            `;
        } else if (sample.source_taxonomy) {
            html += `
                <div class="sample-field">
                    <div class="sample-field-label">Source Taxonomy: ${escapeHtml(sample.source_taxonomy.name)}</div>
                    <div class="sample-field-value">${formatValue(sample.source_taxonomy.current_terms)}</div>
                </div>
            `;
        }
        
        // Target field/taxonomy
        if (sample.target_field) {
            html += `
                <div class="sample-field">
                    <div class="sample-field-label">Target: ${escapeHtml(sample.target_field.label)}</div>
                    <div class="sample-field-value">${formatValue(sample.target_field.current_value)}</div>
                </div>
            `;
        } else if (sample.target_taxonomy) {
            html += `
                <div class="sample-field">
                    <div class="sample-field-label">Target Taxonomy: ${escapeHtml(sample.target_taxonomy.name)}</div>
                    <div class="sample-field-value">${formatValue(sample.target_taxonomy.current_terms)}</div>
                </div>
            `;
        }
        
        // Preview result
        if (sample.preview_data) {
            html += `
                <div class="sample-field">
                    <div class="sample-field-label">Preview Result</div>
                    <div class="sample-field-value">${formatPreviewData(sample.preview_data)}</div>
                </div>
            `;
        }
        
        return html;
    }

    /**
     * Format preview data for display
     */
    function formatPreviewData(previewData) {
        if (previewData.error) {
            return `<span style="color: #721c24;">Error: ${escapeHtml(previewData.error)}</span>`;
        }
        
        let html = '';
        
        if (previewData.converted_value !== undefined) {
            html += `<strong>Converted Value:</strong> ${formatValue(previewData.converted_value)}<br>`;
        }
        
        if (previewData.term_names) {
            html += `<strong>Terms:</strong> ${previewData.term_names.join(', ')}<br>`;
            if (previewData.new_terms && previewData.new_terms.length > 0) {
                html += `<strong>New Terms:</strong> ${previewData.new_terms.join(', ')}<br>`;
            }
        }
        
        if (previewData.mapping_details) {
            html += '<strong>Mappings:</strong><br>';
            previewData.mapping_details.forEach(mapping => {
                html += `&nbsp;&nbsp;${escapeHtml(mapping.from)} → ${escapeHtml(mapping.to)}<br>`;
            });
        }
        
        if (previewData.unmapped_values && previewData.unmapped_values.length > 0) {
            html += `<strong style="color: #664d03;">Skipped Values:</strong> ${previewData.unmapped_values.join(', ')}<br>`;
        }
        
        if (previewData.conversion_notes && previewData.conversion_notes.length > 0) {
            html += `<em>Notes: ${previewData.conversion_notes.join('; ')}</em>`;
        }
        
        return html || 'No preview data available';
    }

    /**
     * Format value for display
     */
    function formatValue(value) {
        if (value === null || value === undefined) {
            return '<em>Empty</em>';
        }
        
        if (Array.isArray(value)) {
            return value.length > 0 ? value.join(', ') : '<em>Empty Array</em>';
        }
        
        if (typeof value === 'object') {
            return JSON.stringify(value, null, 2);
        }
        
        return escapeHtml(String(value));
    }

    /**
     * Toggle sample details
     */
    function toggleSampleDetails(e) {
        const header = $(e.target).closest('.sample-header');
        const index = header.data('index');
        const details = $(`#sample-details-${index}`);
        
        details.toggleClass('expanded');
    }

    /**
     * Update form state - IMPROVED VERSION
     */
    function updateFormState() {
        $('.bws-acf-conversion-form').each(function() {
            const form = $(this);
            const convertBtn = form.find('.bws-convert-btn');
            const previewBtn = form.find('.bws-preview-btn');
            
            let formValid = false;
            
            // Check form validity based on conversion type
            const conversionType = form.find('[name="conversion_type"]').val();
            
            if (conversionType === 'copy_data') {
                formValid = validateCopyDataForm(form);
            } else if (conversionType === 'map_data') {
                formValid = validateMapDataForm(form);
            }
            
            console.log('Form validation result:', formValid, 'for type:', conversionType);
            
            convertBtn.prop('disabled', !formValid || conversionInProgress);
            previewBtn.prop('disabled', !formValid);
            
            // Visual feedback
            if (formValid) {
                convertBtn.removeClass('button-disabled');
                previewBtn.removeClass('button-disabled');
            } else {
                convertBtn.addClass('button-disabled');
                previewBtn.addClass('button-disabled');
            }
        });
    }

    /**
     * Validate copy data form (formerly move data)
     */
    function validateCopyDataForm(form) {
        const contentType = form.find('[name="content_type"]').val();
        const copyType = form.find('[name="copy_type"]:checked').val();
        
        console.log('Validating copy form - contentType:', contentType, 'copyType:', copyType);
        
        if (!contentType || !copyType) {
            console.log('Copy form validation failed: missing contentType or copyType');
            return false;
        }
        
        // Check required fields based on copy type
        switch (copyType) {
            case 'field_to_field':
                const sourceField = form.find('[name="source_field"]').val();
                const targetField = form.find('[name="target_field"]').val();
                console.log('field_to_field validation - source:', sourceField, 'target:', targetField);
                return !!(sourceField && targetField);
                
            case 'field_to_taxonomy':
                const sourceFieldTax = form.find('[name="source_field"]').val();
                const targetTaxonomy = form.find('[name="target_taxonomy"]').val();
                console.log('field_to_taxonomy validation - source:', sourceFieldTax, 'target:', targetTaxonomy);
                return !!(sourceFieldTax && targetTaxonomy);
                
            case 'taxonomy_to_field':
                const sourceTaxonomy = form.find('[name="source_taxonomy"]').val();
                const targetFieldFromTax = form.find('[name="target_field"]').val();
                console.log('taxonomy_to_field validation - source:', sourceTaxonomy, 'target:', targetFieldFromTax);
                return !!(sourceTaxonomy && targetFieldFromTax);
                
            case 'taxonomy_to_taxonomy':
                const sourceTaxonomyToTax = form.find('[name="source_taxonomy"]').val();
                const targetTaxonomyToTax = form.find('[name="target_taxonomy"]').val();
                console.log('taxonomy_to_taxonomy validation - source:', sourceTaxonomyToTax, 'target:', targetTaxonomyToTax);
                return !!(sourceTaxonomyToTax && targetTaxonomyToTax);
        }
        
        console.log('Copy form validation failed: unknown copyType');
        return false;
    }

    /**
     * Validate map data form - IMPROVED VERSION
     */
    function validateMapDataForm(form) {
        const contentType = form.find('[name="content_type"]').val();
        const sourceField = form.find('[name="source_field"]').val();
        const targetType = form.find('[name="target_type"]:checked').val();
        
        console.log('Validating map form - contentType:', contentType, 'sourceField:', sourceField, 'targetType:', targetType);
        
        if (!contentType || !sourceField || !targetType) {
            console.log('Map form validation failed: missing basic fields');
            return false;
        }
        
        if (targetType === 'field') {
            const targetField = form.find('[name="target_field"]').val();
            console.log('Map to field validation - targetField:', targetField);
            
            // Check if mappings are configured (at least one mapping should have a value)
            const mappingSelects = form.find('select[name^="mappings["]');
            const hasValidMappings = mappingSelects.length === 0 || mappingSelects.filter(function() {
                return $(this).val() !== '';
            }).length > 0;
            
            console.log('Mapping selects found:', mappingSelects.length, 'hasValidMappings:', hasValidMappings);
            return !!(targetField && hasValidMappings);
            
        } else if (targetType === 'taxonomy') {
            const targetTaxonomy = form.find('[name="target_taxonomy"]').val();
            console.log('Map to taxonomy validation - targetTaxonomy:', targetTaxonomy);
            
            // Check if mappings are configured
            const mappingSelects = form.find('select[name^="mappings["]');
            const mappingInputs = form.find('input[name^="mappings["]');
            
            let hasValidMappings = false;
            
            if (mappingSelects.length > 0) {
                hasValidMappings = mappingSelects.filter(function() {
                    const $this = $(this);
                    if ($this.val() === '__CREATE_NEW__') {
                        return $this.data('custom-value') && $this.data('custom-value').trim() !== '';
                    }
                    return $this.val() !== '';
                }).length > 0;
            } else if (mappingInputs.length > 0) {
                hasValidMappings = mappingInputs.filter(function() {
                    return $(this).val().trim() !== '';
                }).length > 0;
            }
            
            console.log('Mapping elements found - selects:', mappingSelects.length, 'inputs:', mappingInputs.length, 'hasValidMappings:', hasValidMappings);
            return !!(targetTaxonomy && hasValidMappings);
        }
        
        console.log('Map form validation failed: unknown targetType');
        return false;
    }

    /**
     * Validate form before submission
     */
    function validateForm(form) {
        const conversionType = form.find('[name="conversion_type"]').val();
        
        if (conversionType === 'copy_data') {
            return validateCopyDataForm(form);
        } else if (conversionType === 'map_data') {
            return validateMapDataForm(form);
        }
        
        return false;
    }

    /**
     * Update conversion UI state
     */
    function updateConversionUI(inProgress) {
        $('.bws-convert-btn').prop('disabled', inProgress);
        $('.bws-preview-btn').prop('disabled', inProgress);
        
        if (inProgress) {
            $('.bws-convert-btn').html('<span class="dashicons dashicons-update dashicons-spin"></span> Processing...');
        } else {
            $('.bws-convert-btn').html('<span class="dashicons dashicons-update"></span> Start Copying');
        }
    }

    /**
     * Show modal
     */
    function showModal(title, message) {
        $('#modal-title').text(title);
        $('.current-status').text(message);
        $('.progress-fill').css('width', '0%');
        $('.progress-text').text('0%');
        $('.detailed-log').hide();
        $('.log-content').empty();
        $('.modal-close').hide();
        $('#bws-acf-conversion-modal').show();
    }

    /**
     * Close modal
     */
    function closeModal() {
        $('#bws-acf-conversion-modal, #bws-acf-conversion-report-modal').hide();
        conversionInProgress = false;
        updateConversionUI(false);
        
        if (progressInterval) {
            clearInterval(progressInterval);
            progressInterval = null;
        }
    }

    /**
     * Update modal progress
     */
    function updateModalProgress(percentage, message) {
        $('.progress-fill').css('width', percentage + '%');
        $('.progress-text').text(Math.round(percentage) + '%');
        
        if (message) {
            $('.current-status').text(message);
        }
    }

    /**
     * Toggle detailed log
     */
    function toggleDetailedLog() {
        const log = $('.detailed-log');
        const button = $('.toggle-log');
        
        if (log.is(':visible')) {
            log.hide();
            button.text('Show Details');
        } else {
            log.show();
            button.text('Hide Details');
        }
    }

    /**
     * Log message to detailed log
     */
    function logMessage(message, type = 'info') {
        const timestamp = new Date().toLocaleTimeString();
        const logEntry = `<div class="log-entry ${type}"><span class="timestamp">[${timestamp}]</span>${escapeHtml(message)}</div>`;
        
        $('.log-content').append(logEntry);
        $('.log-content').scrollTop($('.log-content')[0].scrollHeight);
    }

    /**
     * Log batch results
     */
    function logBatchResults(batchResults) {
        batchResults.forEach(batch => {
            logMessage(`Batch ${batch.batch_number}: ${batch.successful} successful, ${batch.failed} failed (${batch.execution_time?.toFixed(2) || 0}s)`, batch.failed > 0 ? 'warning' : 'success');
            
            if (batch.errors && batch.errors.length > 0) {
                batch.errors.forEach(error => {
                    logMessage(error, 'error');
                });
            }
        });
    }

    /**
     * Show admin notice
     */
    function showNotice(message, type = 'info') {
        const noticeClass = type === 'error' ? 'notice-error' : 'notice-success';
        const notice = $(`
            <div class="notice ${noticeClass} is-dismissible">
                <p>${escapeHtml(message)}</p>
                <button type="button" class="notice-dismiss">
                    <span class="screen-reader-text">Dismiss this notice.</span>
                </button>
            </div>
        `);
        
        $('.bws-acf-conversion-wrap h1').after(notice);
        
        // Auto-dismiss after 5 seconds for non-success messages
        if (type !== 'success') {
            setTimeout(() => {
                notice.fadeOut();
            }, 5000);
        }
        
        // Handle dismiss button
        notice.find('.notice-dismiss').on('click', function() {
            notice.fadeOut();
        });
    }

    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        
        return String(text).replace(/[&<>"']/g, function(m) {
            return map[m];
        });
    }

    // Expose public methods for debugging
    window.bwsAcfConversionTool = {
        showModal: showModal,
        closeModal: closeModal,
        updateProgress: updateModalProgress,
        logMessage: logMessage,
        loadFields: loadFieldsForContext,
        loadTaxonomies: loadTaxonomiesForContext,
        loadTaxonomyTerms: loadTaxonomyTermsForMapping,
        updateFormState: updateFormState,
        validateCopyForm: validateCopyDataForm,
        validateMapForm: validateMapDataForm,
        showReport: showConversionReport
    };

})(jQuery);