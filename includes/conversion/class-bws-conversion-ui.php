<?php
/**
 * Conversion UI Class
 *
 * Handles the conversion interface, form rendering, and user interactions.
 * Provides the UI for data conversion tab in BWS Meta Manager settings.
 *
 * @package BWS_Meta_Manager
 * @subpackage Conversion
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BWS_Conversion_UI {

    /**
     * Component instances
     *
     * @var array
     */
    private $components;

    /**
     * Current tab
     *
     * @var string
     */
    private $current_tab = 'copy_data';

    /**
     * Available tabs
     *
     * @var array
     */
    private $tabs = [
        'copy_data' => 'Copy Data',
        'map_data' => 'Map Data'
    ];

    /**
     * Constructor
     *
     * @param BWS_Field_Mapper $field_mapper Field mapper instance
     * @param BWS_Data_Processor $data_processor Data processor instance
     * @param BWS_Preview_System $preview_system Preview system instance
     */
    public function __construct(
        BWS_Field_Mapper $field_mapper,
        BWS_Data_Processor $data_processor,
        BWS_Preview_System $preview_system
    ) {
        $this->components = [
            'field_mapper' => $field_mapper,
            'data_processor' => $data_processor,
            'preview_system' => $preview_system
        ];

        $this->current_tab = sanitize_text_field( $_GET['tab'] ?? 'copy_data' );
        
        if ( ! array_key_exists( $this->current_tab, $this->tabs ) ) {
            $this->current_tab = 'copy_data';
        }
    }

    /**
     * Render tab content for BWS Meta Manager settings page
     *
     * Legacy entry point — called by BWS_Settings::render_conversion_tab()
     * when conversion is a tab inside the old settings page.
     */
    public function render_tab_content(): void {
        ?>
        <div class="bws-conversion-tab-content">
            <?php $this->display_admin_notices(); ?>

            <div class="bws-conversion-container">
                <?php $this->display_tabs(); ?>

                <div class="bws-conversion-content">
                    <?php $this->display_tab_content(); ?>
                </div>

                <?php $this->display_progress_modal(); ?>
                <?php $this->display_report_modal(); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render as a standalone admin page (subpage under Meta Conductor).
     *
     * Adds the wp-admin .wrap chrome that render_tab_content() omitted
     * because it expected to live inside a tab container.
     */
    public function render_page(): void {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Data Conversion', 'bws-meta-manager'); ?></h1>
            <p class="description">
                <?php esc_html_e('Convert ACF field data into taxonomy terms or between field types.', 'bws-meta-manager'); ?>
            </p>
            <?php $this->render_tab_content(); ?>
        </div>
        <?php
    }

    /**
     * Display admin notices
     */
    private function display_admin_notices(): void {
        // Check for ACF Pro
        if ( ! $this->is_acf_pro_available() ) {
            ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e( 'ACF Data Conversion Tool', 'bws-meta-manager' ); ?></strong><br>
                    <?php esc_html_e( 'Advanced Custom Fields Pro is required for this tool to function properly.', 'bws-meta-manager' ); ?>
                </p>
            </div>
            <?php
            return;
        }

        // Display any stored notices
        if ( isset( $_GET['conversion_result'] ) ) {
            $result = sanitize_text_field( $_GET['conversion_result'] );
            $message = sanitize_text_field( $_GET['message'] ?? '' );
            
            $notice_class = 'success' === $result ? 'notice-success' : 'notice-error';
            ?>
            <div class="notice <?php echo esc_attr( $notice_class ); ?> bws-persistent-notice">
                <p>
                    <?php echo esc_html( $message ); ?>
                    <?php if ( 'success' === $result ) : ?>
                        <a href="#" class="button button-secondary view-conversion-report" style="margin-left: 10px;">
                            <?php esc_html_e( 'View Conversion Report', 'bws-meta-manager' ); ?>
                        </a>
                    <?php endif; ?>
                </p>
            </div>
            <?php
        }
    }

    /**
     * Display navigation tabs (sub-tabs within conversion tab)
     */
    private function display_tabs(): void {
        ?>
        <nav class="bws-conversion-sub-tabs wp-clearfix" role="tablist">
            <?php foreach ( $this->tabs as $tab_key => $tab_label ) : ?>
                <a href="#<?php echo esc_attr( $tab_key ); ?>"
                   class="bws-conversion-sub-tab <?php echo $this->current_tab === $tab_key ? 'active' : ''; ?>"
                   data-tab="<?php echo esc_attr( $tab_key ); ?>"
                   role="tab"
                   aria-selected="<?php echo $this->current_tab === $tab_key ? 'true' : 'false'; ?>">
                    <?php echo esc_html( $tab_label ); ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php
    }

    /**
     * Display all tab content panels (toggle visibility with JavaScript)
     */
    private function display_tab_content(): void {
        ?>
        <div id="copy_data" class="bws-conversion-sub-tab-content <?php echo $this->current_tab === 'copy_data' ? 'active' : ''; ?>">
            <?php $this->display_copy_data_tab(); ?>
        </div>

        <div id="map_data" class="bws-conversion-sub-tab-content <?php echo $this->current_tab === 'map_data' ? 'active' : ''; ?>">
            <?php $this->display_map_data_tab(); ?>
        </div>
        <?php
    }

    /**
     * Display copy data tab (formerly move data)
     */
    private function display_copy_data_tab(): void {
        ?>
        <div class="bws-conversion-tab-content">
            <div class="bws-conversion-section">
                <h2><?php esc_html_e( 'Copy Data', 'bws-meta-manager' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'Copy data as-is between ACF fields, or between taxonomy terms. Original data is preserved and copied to the target location.', 'bws-meta-manager' ); ?>
                </p>

                <form id="copy-data-form" class="bws-conversion-form">
                    <?php wp_nonce_field( 'bws_taxonomy_manager_nonce', 'nonce' ); ?>
                    <input type="hidden" name="conversion_type" value="copy_data">

                    <table class="form-table" role="presentation">
                        <tbody>
                            <!-- Content Filter Section -->
                            <tr>
                                <th scope="row">
                                    <label for="content_type"><?php esc_html_e( 'Content Type', 'bws-meta-manager' ); ?></label>
                                </th>
                                <td>
                                    <select name="content_type" id="content_type" class="regular-text" required>
                                        <option value=""><?php esc_html_e( 'Select content type...', 'bws-meta-manager' ); ?></option>
                                        <option value="posts"><?php esc_html_e( 'Posts', 'bws-meta-manager' ); ?></option>
                                        <option value="taxonomy_terms"><?php esc_html_e( 'Taxonomy Terms', 'bws-meta-manager' ); ?></option>
                                    </select>
                                    <p class="description"><?php esc_html_e( 'Whether to work with posts or taxonomy terms.', 'bws-meta-manager' ); ?></p>
                                </td>
                            </tr>

                            <!-- Post Type Selection (shown when content_type = posts) -->
                            <tr id="post_types_row" style="display: none;">
                                <th scope="row">
                                    <label for="post_types"><?php esc_html_e( 'Post Types', 'bws-meta-manager' ); ?></label>
                                </th>
                                <td>
                                    <?php $this->render_post_types_select(); ?>
                                    <p class="description"><?php esc_html_e( 'Select which post types to include.', 'bws-meta-manager' ); ?></p>
                                </td>
                            </tr>

                            <!-- Post Status Selection (shown when content_type = posts) -->
                            <tr id="post_status_row" style="display: none;">
                                <th scope="row">
                                    <label for="post_status"><?php esc_html_e( 'Post Status', 'bws-meta-manager' ); ?></label>
                                </th>
                                <td>
                                    <?php $this->render_post_status_select(); ?>
                                    <p class="description"><?php esc_html_e( 'Select which post statuses to include.', 'bws-meta-manager' ); ?></p>
                                </td>
                            </tr>

                            <!-- Taxonomy Selection (shown when content_type = taxonomy_terms) -->
                            <tr id="taxonomies_row" style="display: none;">
                                <th scope="row">
                                    <label for="taxonomies"><?php esc_html_e( 'Taxonomies', 'bws-meta-manager' ); ?></label>
                                </th>
                                <td>
                                    <?php $this->render_taxonomies_select(); ?>
                                    <p class="description"><?php esc_html_e( 'Select which taxonomies to include.', 'bws-meta-manager' ); ?></p>
                                </td>
                            </tr>

                            <!-- Copy Type Selection -->
                            <?php 
							echo '<tr id="copy_type_row" style="display: none;">
								<th scope="row">
									' . esc_html__( 'Copy Type', 'bws-meta-manager' ) . ' <span class="required">*</span>
								</th>
								<td>
									<fieldset>
										<label>
											<input type="radio" name="copy_type" value="field_to_field" id="copy_field_to_field" required>
											' . esc_html__( 'Between ACF Fields', 'bws-meta-manager' ) . '
										</label><br>
										<label>
											<input type="radio" name="copy_type" value="field_to_taxonomy" id="copy_field_to_taxonomy">
											' . esc_html__( 'ACF Field to Taxonomy Terms', 'bws-meta-manager' ) . '
										</label><br>
										<label>
											<input type="radio" name="copy_type" value="taxonomy_to_field" id="copy_taxonomy_to_field">
											' . esc_html__( 'Taxonomy Terms to ACF Field', 'bws-meta-manager' ) . '
										</label><br>
										<label>
											<input type="radio" name="copy_type" value="taxonomy_to_taxonomy" id="copy_taxonomy_to_taxonomy">
											' . esc_html__( 'Between Taxonomies', 'bws-meta-manager' ) . '
										</label>
									</fieldset>
									<p class="description">' . esc_html__( 'Choose the type of data copying operation.', 'bws-meta-manager' ) . '</p>
								</td>
							</tr>';
							
							// Source Field Selection
							echo '<tr id="source_field_row" style="display: none;">
								<th scope="row">
									<label for="source_field">' . esc_html__( 'Source Field', 'bws-meta-manager' ) . ' <span class="required">*</span></label>
								</th>
								<td>
									<select name="source_field" id="source_field" class="regular-text" data-conditional-required="field_to_field,field_to_taxonomy">
										<option value="">' . esc_html__( 'Select source field...', 'bws-meta-manager' ) . '</option>
									</select>
									<p class="description">' . esc_html__( 'Field containing the data to copy.', 'bws-meta-manager' ) . '</p>
								</td>
							</tr>';
							
							// Source Taxonomy Selection
							echo '<tr id="source_taxonomy_row" style="display: none;">
								<th scope="row">
									<label for="source_taxonomy">' . esc_html__( 'Source Taxonomy', 'bws-meta-manager' ) . ' <span class="required">*</span></label>
								</th>
								<td>
									<select name="source_taxonomy" id="source_taxonomy" class="regular-text" data-conditional-required="taxonomy_to_field,taxonomy_to_taxonomy">
										<option value="">' . esc_html__( 'Select source taxonomy...', 'bws-meta-manager' ) . '</option>
									</select>
									<p class="description">' . esc_html__( 'Taxonomy containing the terms to copy.', 'bws-meta-manager' ) . '</p>
								</td>
							</tr>';
							
							// Target Field Selection
							echo '<tr id="target_field_row" style="display: none;">
								<th scope="row">
									<label for="target_field">' . esc_html__( 'Target Field', 'bws-meta-manager' ) . ' <span class="required">*</span></label>
								</th>
								<td>
									<select name="target_field" id="target_field" class="regular-text" data-conditional-required="field_to_field,taxonomy_to_field">
										<option value="">' . esc_html__( 'Select target field...', 'bws-meta-manager' ) . '</option>
									</select>
									<p class="description">' . esc_html__( 'Field where the data will be copied.', 'bws-meta-manager' ) . '</p>
								</td>
							</tr>';
							
							// Target Taxonomy Selection
							echo '<tr id="target_taxonomy_row" style="display: none;">
								<th scope="row">
									<label for="target_taxonomy">' . esc_html__( 'Target Taxonomy', 'bws-meta-manager' ) . ' <span class="required">*</span></label>
								</th>
								<td>
									<select name="target_taxonomy" id="target_taxonomy" class="regular-text" data-conditional-required="field_to_taxonomy,taxonomy_to_taxonomy">
										<option value="">' . esc_html__( 'Select target taxonomy...', 'bws-meta-manager' ) . '</option>
									</select>
									<p class="description">' . esc_html__( 'Taxonomy where terms will be created/assigned.', 'bws-meta-manager' ) . '</p>
								</td>
							</tr>';
							?>
							
                            <!-- Term Assignment Options -->
                            <tr id="term_assignment_row" style="display: none;">
                                <th scope="row">
                                    <?php esc_html_e( 'Term Assignment', 'bws-meta-manager' ); ?>
                                </th>
                                <td>
                                    <fieldset>
                                        <label>
                                            <input type="radio" name="append_terms" value="0" checked>
                                            <?php esc_html_e( 'Replace existing terms', 'bws-meta-manager' ); ?>
                                        </label><br>
                                        <label>
                                            <input type="radio" name="append_terms" value="1">
                                            <?php esc_html_e( 'Add to existing terms', 'bws-meta-manager' ); ?>
                                        </label>
                                    </fieldset>
                                    <p class="description"><?php esc_html_e( 'How to handle existing terms.', 'bws-meta-manager' ); ?></p>
                                </td>
                            </tr>

                            <!-- Batch Size -->
                            <tr id="batch_size_row" style="display: none;">
                                <th scope="row">
                                    <label for="batch_size"><?php esc_html_e( 'Batch Size', 'bws-meta-manager' ); ?></label>
                                </th>
                                <td>
                                    <input type="number" name="batch_size" id="batch_size" value="25" min="5" max="100" class="small-text">
                                    <p class="description"><?php esc_html_e( 'Number of items to process per batch.', 'bws-meta-manager' ); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="bws-conversion-validation" id="copy-data-validation" style="display: none;">
                        <h3><?php esc_html_e( 'Validation Results', 'bws-meta-manager' ); ?></h3>
                        <div class="validation-content"></div>
                    </div>

                    <?php $this->render_action_buttons(); ?>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Display map data tab
     */
    private function display_map_data_tab(): void {
        ?>
        <div class="bws-conversion-tab-content">
            <div class="bws-conversion-section">
                <h2><?php esc_html_e( 'Map Data', 'bws-meta-manager' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'Transform option field values by mapping them to new values in another field or to taxonomy terms.', 'bws-meta-manager' ); ?>
                </p>

                <form id="map-data-form" class="bws-conversion-form">
                    <?php wp_nonce_field( 'bws_taxonomy_manager_nonce', 'nonce' ); ?>
                    <input type="hidden" name="conversion_type" value="map_data">

                    <table class="form-table" role="presentation">
                        <tbody>
                            <!-- Content Filter Section -->
                            <tr>
                                <th scope="row">
                                    <label for="map_content_type"><?php esc_html_e( 'Content Type', 'bws-meta-manager' ); ?></label>
                                </th>
                                <td>
                                    <select name="content_type" id="map_content_type" class="regular-text" required>
                                        <option value=""><?php esc_html_e( 'Select content type...', 'bws-meta-manager' ); ?></option>
                                        <option value="posts"><?php esc_html_e( 'Posts', 'bws-meta-manager' ); ?></option>
                                        <option value="taxonomy_terms"><?php esc_html_e( 'Taxonomy Terms', 'bws-meta-manager' ); ?></option>
                                    </select>
                                    <p class="description"><?php esc_html_e( 'Whether to work with posts or taxonomy terms.', 'bws-meta-manager' ); ?></p>
                                </td>
                            </tr>

                            <!-- Post Type Selection (shown when content_type = posts) -->
                            <tr id="map_post_types_row" style="display: none;">
                                <th scope="row">
                                    <label for="map_post_types"><?php esc_html_e( 'Post Types', 'bws-meta-manager' ); ?></label>
                                </th>
                                <td>
                                    <?php $this->render_post_types_select( 'map_post_types' ); ?>
                                    <p class="description"><?php esc_html_e( 'Select which post types to include.', 'bws-meta-manager' ); ?></p>
                                </td>
                            </tr>

                            <!-- Post Status Selection (shown when content_type = posts) -->
                            <tr id="map_post_status_row" style="display: none;">
                                <th scope="row">
                                    <label for="map_post_status"><?php esc_html_e( 'Post Status', 'bws-meta-manager' ); ?></label>
                                </th>
                                <td>
                                    <?php $this->render_post_status_select( 'map_post_status' ); ?>
                                    <p class="description"><?php esc_html_e( 'Select which post statuses to include.', 'bws-meta-manager' ); ?></p>
                                </td>
                            </tr>

                            <!-- Taxonomy Selection (shown when content_type = taxonomy_terms) -->
                            <tr id="map_taxonomies_row" style="display: none;">
                                <th scope="row">
                                    <label for="map_taxonomies"><?php esc_html_e( 'Taxonomies', 'bws-meta-manager' ); ?></label>
                                </th>
                                <td>
                                    <?php $this->render_taxonomies_select( 'map_taxonomies' ); ?>
                                    <p class="description"><?php esc_html_e( 'Select which taxonomies to include.', 'bws-meta-manager' ); ?></p>
                                </td>
                            </tr>

                            <!-- Source Field Selection -->
                            <?php
                            echo '<tr id="map_source_field_row" style="display: none;">
								<th scope="row">
									<label for="map_source_field">' . esc_html__( 'Source Field', 'bws-meta-manager' ) . ' <span class="required">*</span></label>
								</th>
								<td>
									<select name="source_field" id="map_source_field" class="regular-text" required>
										<option value="">' . esc_html__( 'Select source field...', 'bws-meta-manager' ) . '</option>
									</select>
									<p class="description">' . esc_html__( 'Option field containing values to map.', 'bws-meta-manager' ) . '</p>
								</td>
							</tr>';
							?>

                            <!-- Target Type Selection -->
                            <?php
                            echo '<tr id="map_target_type_row" style="display: none;">
								<th scope="row">
									' . esc_html__( 'Map To', 'bws-meta-manager' ) . ' <span class="required">*</span>
								</th>
								<td>
									<fieldset>
										<label>
											<input type="radio" name="target_type" value="field" id="map_target_type_field" required>
											' . esc_html__( 'Another ACF Field', 'bws-meta-manager' ) . '
										</label><br>
										<label>
											<input type="radio" name="target_type" value="taxonomy" id="map_target_type_taxonomy">
											' . esc_html__( 'Taxonomy Terms', 'bws-meta-manager' ) . '
										</label>
									</fieldset>
								</td>
							</tr>';
							?>

                            <!-- Target Field Selection -->
                            <tr id="map_target_field_row" style="display: none;">
                                <th scope="row">
                                    <label for="map_target_field"><?php esc_html_e( 'Target Field', 'bws-meta-manager' ); ?> <span class="required">*</span></label>
                                </th>
                                <td>
                                    <select name="target_field" id="map_target_field" class="regular-text" data-conditional-required="field">
                                        <option value=""><?php esc_html_e( 'Select target field...', 'bws-meta-manager' ); ?></option>
                                    </select>
                                    <p class="description"><?php esc_html_e( 'Field where mapped values will be stored.', 'bws-meta-manager' ); ?></p>
                                    
                                    <!-- Option Mappings for Field Target -->
                                    <div class="bws-conversion-mappings" id="field-option-mappings" style="display: none; margin-top: 15px;">
                                        <h4><?php esc_html_e( 'Value Mappings', 'bws-meta-manager' ); ?></h4>
                                        <p class="description"><?php esc_html_e( 'Map each source option to a target field option.', 'bws-meta-manager' ); ?></p>
                                        <div class="unmapped-behavior-notice" style="background: #fff3cd; border: 1px solid #ffecb5; padding: 10px; margin: 10px 0; border-radius: 4px;">
                                            <strong><?php esc_html_e( 'Unmapped Values:', 'bws-meta-manager' ); ?></strong>
                                            <?php esc_html_e( 'Values without mappings will be skipped and not copied to the target field.', 'bws-meta-manager' ); ?>
                                        </div>
                                        <div class="mappings-content"></div>
                                    </div>
                                </td>
                            </tr>

                            <!-- Target Taxonomy Selection -->
                            <tr id="map_target_taxonomy_row" style="display: none;">
                                <th scope="row">
                                    <label for="map_target_taxonomy"><?php esc_html_e( 'Target Taxonomy', 'bws-meta-manager' ); ?> <span class="required">*</span></label>
                                </th>
                                <td>
                                    <select name="target_taxonomy" id="map_target_taxonomy" class="regular-text" data-conditional-required="taxonomy">
                                        <option value=""><?php esc_html_e( 'Select target taxonomy...', 'bws-meta-manager' ); ?></option>
                                    </select>
                                    <p class="description"><?php esc_html_e( 'Taxonomy where terms will be created.', 'bws-meta-manager' ); ?></p>
                                    
                                    <!-- Term Assignment Options for Taxonomy Target -->
                                    <div id="taxonomy-term-assignment" style="display: none; margin-top: 15px;">
                                        <fieldset>
                                            <legend><?php esc_html_e( 'Term Assignment', 'bws-meta-manager' ); ?></legend>
                                            <label>
                                                <input type="radio" name="append_terms" value="0" checked>
                                                <?php esc_html_e( 'Replace existing terms', 'bws-meta-manager' ); ?>
                                            </label><br>
                                            <label>
                                                <input type="radio" name="append_terms" value="1">
                                                <?php esc_html_e( 'Add to existing terms', 'bws-meta-manager' ); ?>
                                            </label>
                                        </fieldset>
                                    </div>

                                    <!-- Option Mappings for Taxonomy Target -->
                                    <div class="bws-conversion-mappings" id="taxonomy-option-mappings" style="display: none; margin-top: 15px;">
                                        <h4><?php esc_html_e( 'Value Mappings', 'bws-meta-manager' ); ?></h4>
                                        <p class="description"><?php esc_html_e( 'Map each source option to a taxonomy term.', 'bws-meta-manager' ); ?></p>
                                        <div class="unmapped-behavior-notice" style="background: #fff3cd; border: 1px solid #ffecb5; padding: 10px; margin: 10px 0; border-radius: 4px;">
                                            <strong><?php esc_html_e( 'Unmapped Values:', 'bws-meta-manager' ); ?></strong>
                                            <?php esc_html_e( 'Values without mappings will be skipped and no taxonomy terms will be assigned for those values.', 'bws-meta-manager' ); ?>
                                        </div>
                                        <div class="mappings-content"></div>
                                    </div>
                                </td>
                            </tr>

                            <!-- Batch Size -->
                            <tr id="map_batch_size_row" style="display: none;">
                                <th scope="row">
                                    <label for="map_batch_size"><?php esc_html_e( 'Batch Size', 'bws-meta-manager' ); ?></label>
                                </th>
                                <td>
                                    <input type="number" name="batch_size" id="map_batch_size" value="25" min="5" max="100" class="small-text">
                                    <p class="description"><?php esc_html_e( 'Number of items to process per batch.', 'bws-meta-manager' ); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="bws-conversion-validation" id="map-data-validation" style="display: none;">
                        <h3><?php esc_html_e( 'Validation Results', 'bws-meta-manager' ); ?></h3>
                        <div class="validation-content"></div>
                    </div>

                    <?php $this->render_action_buttons(); ?>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Render action buttons
     */
    private function render_action_buttons(): void {
        ?>
        <div class="bws-conversion-actions">
            <button type="button" class="button button-secondary bws-preview-btn">
                <span class="dashicons dashicons-visibility"></span>
                <?php esc_html_e( 'Generate Preview', 'bws-meta-manager' ); ?>
            </button>
            
            <button type="submit" class="button button-primary bws-convert-btn" disabled>
                <span class="dashicons dashicons-update"></span>
                <?php esc_html_e( 'Start Copying', 'bws-meta-manager' ); ?>
            </button>

            <div class="bws-conversion-status" style="display: none;">
                <span class="status-text"></span>
                <div class="progress-bar">
                    <div class="progress-fill"></div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Display progress modal
     */
    private function display_progress_modal(): void {
        ?>
        <div id="bws-conversion-modal" class="bws-acf-modal" style="display: none;">
            <div class="bws-acf-modal-content">
                <div class="bws-acf-modal-header">
                    <h2 id="modal-title"><?php esc_html_e( 'Processing', 'bws-meta-manager' ); ?></h2>
                    <button type="button" class="bws-acf-modal-close">&times;</button>
                </div>
                
                <div class="bws-acf-modal-body">
                    <div class="progress-container">
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: 0%;"></div>
                        </div>
                        <div class="progress-text">0%</div>
                    </div>
                    
                    <div class="status-messages">
                        <div class="current-status"></div>
                        <div class="detailed-log" style="display: none;">
                            <h4><?php esc_html_e( 'Detailed Log', 'bws-meta-manager' ); ?></h4>
                            <div class="log-content"></div>
                        </div>
                    </div>
                </div>
                
                <div class="bws-acf-modal-footer">
                    <button type="button" class="button button-secondary toggle-log">
                        <?php esc_html_e( 'Show Details', 'bws-meta-manager' ); ?>
                    </button>
                    <button type="button" class="button button-primary modal-close" style="display: none;">
                        <?php esc_html_e( 'Close', 'bws-meta-manager' ); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Display report modal
     */
    private function display_report_modal(): void {
        ?>
        <div id="bws-conversion-report-modal" class="bws-acf-modal" style="display: none;">
            <div class="bws-acf-modal-content">
                <div class="bws-acf-modal-header">
                    <h2><?php esc_html_e( 'Conversion Report', 'bws-meta-manager' ); ?></h2>
                    <button type="button" class="bws-acf-modal-close">&times;</button>
                </div>
                
                <div class="bws-acf-modal-body">
                    <div class="report-content">
                        <!-- Report content will be populated by JavaScript -->
                    </div>
                </div>
                
                <div class="bws-acf-modal-footer">
                    <button type="button" class="button button-primary modal-close">
                        <?php esc_html_e( 'Close', 'bws-meta-manager' ); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Handle conversion AJAX request
     */
    public function handle_conversion_ajax(): void {
        $conversion_type = sanitize_text_field( $_POST['conversion_type'] ?? '' );
        
        // Map old type names to new ones
        if ( $conversion_type === 'move_data' ) {
            $conversion_type = 'copy_data';
        }
        
        $config = $this->sanitize_conversion_config( $_POST );

        switch ( $conversion_type ) {
            case 'copy_data':
                $result = $this->components['data_processor']->process_copy_data_conversion( $config );
                break;
                
            case 'map_data':
                $result = $this->components['data_processor']->process_map_data_conversion( $config );
                break;
                
            default:
                $result = [
                    'success' => false,
                    'errors' => [ __( 'Invalid conversion type.', 'bws-meta-manager' ) ]
                ];
        }

        wp_send_json( $result );
    }

    /**
     * Handle preview AJAX request
     */
    public function handle_preview_ajax(): void {
        $conversion_type = sanitize_text_field( $_POST['conversion_type'] ?? '' );
        
        // Map old type names to new ones
        if ( $conversion_type === 'move_data' ) {
            $conversion_type = 'copy_data';
        }
        
        $config = $this->sanitize_conversion_config( $_POST );
        $sample_count = intval( $_POST['sample_count'] ?? 10 );

        switch ( $conversion_type ) {
            case 'copy_data':
                $result = $this->components['preview_system']->generate_copy_data_preview( $config, $sample_count );
                break;
                
            case 'map_data':
                $result = $this->components['preview_system']->generate_map_data_preview( $config, $sample_count );
                break;
                
            default:
                $result = [
                    'success' => false,
                    'error' => __( 'Invalid conversion type.', 'bws-meta-manager' )
                ];
        }

        wp_send_json( $result );
    }

    /**
     * Handle get taxonomy terms AJAX request
     */
    public function handle_get_taxonomy_terms_ajax(): void {
        $this->verify_ajax_request();
        
        $taxonomy = sanitize_text_field( $_POST['taxonomy'] ?? '' );
        
        if ( ! $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
            wp_send_json_error( __( 'Invalid taxonomy.', 'bws-meta-manager' ) );
        }

        $terms = $this->components['field_mapper']->get_taxonomy_terms( $taxonomy, [
            'number' => 200, // Limit for performance
            'orderby' => 'name',
            'order' => 'ASC'
        ] );

        wp_send_json_success( $terms );
    }

    /**
     * Verify AJAX request security
     */
    private function verify_ajax_request(): void {
        // Verify nonce
        if ( ! check_ajax_referer( 'bws_taxonomy_manager_nonce', 'nonce', false ) ) {
            wp_die( 
                esc_html__( 'Security check failed.', 'bws-meta-manager' ),
                esc_html__( 'Forbidden', 'bws-meta-manager' ),
                [ 'response' => 403 ]
            );
        }

        // Verify capabilities
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die(
                esc_html__( 'You do not have permission to perform this action.', 'bws-meta-manager' ),
                esc_html__( 'Forbidden', 'bws-meta-manager' ),
                [ 'response' => 403 ]
            );
        }
    }

    // ... [Rest of the methods remain largely the same, with "move" renamed to "copy" throughout]

    /**
     * Handle get fields AJAX request
     */
    public function handle_get_fields_ajax(): void {
        $context = sanitize_text_field( $_POST['context'] ?? '' );
        $content_type = sanitize_text_field( $_POST['content_type'] ?? '' );
        $post_types = array_map( 'sanitize_text_field', $_POST['post_types'] ?? [] );
        $taxonomies = array_map( 'sanitize_text_field', $_POST['taxonomies'] ?? [] );
        $field_type_filter = sanitize_text_field( $_POST['field_type_filter'] ?? '' );

        $fields = $this->components['field_mapper']->get_fields_by_context( 
            $content_type, 
            $post_types, 
            $taxonomies, 
            $field_type_filter 
        );

        wp_send_json_success( $fields );
    }

    /**
     * Handle get options AJAX request
     */
    public function handle_get_options_ajax(): void {
        $field_key = sanitize_text_field( $_POST['field_key'] ?? '' );

        if ( $field_key ) {
            $field_data = $this->components['field_mapper']->get_field_by_key( $field_key );
            $options = $field_data['options'] ?? [];
            wp_send_json_success( $options );
        } else {
            wp_send_json_error( __( 'Field key is required.', 'bws-meta-manager' ) );
        }
    }

    /**
     * Handle get taxonomies AJAX request
     */
    public function handle_get_taxonomies_ajax(): void {
        $taxonomies = $this->components['field_mapper']->get_taxonomies( true );
        wp_send_json_success( $taxonomies );
    }


    /**
     * Render post types select
     */
    private function render_post_types_select( string $field_id = 'post_types' ): void {
        $post_types = get_post_types( [ 'public' => true ], 'objects' );
        ?>
        <select name="post_types[]" id="<?php echo esc_attr( $field_id ); ?>" class="regular-text" multiple>
            <option value="any" selected><?php esc_html_e( 'All Post Types', 'bws-meta-manager' ); ?></option>
            <?php foreach ( $post_types as $post_type ) : ?>
                <option value="<?php echo esc_attr( $post_type->name ); ?>">
                    <?php echo esc_html( $post_type->label ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /**
     * Render post status select
     */
    private function render_post_status_select( string $field_id = 'post_status' ): void {
        ?>
        <select name="post_status[]" id="<?php echo esc_attr( $field_id ); ?>" class="regular-text" multiple>
            <option value="publish" selected><?php esc_html_e( 'Published', 'bws-meta-manager' ); ?></option>
            <option value="draft"><?php esc_html_e( 'Draft', 'bws-meta-manager' ); ?></option>
            <option value="private"><?php esc_html_e( 'Private', 'bws-meta-manager' ); ?></option>
            <option value="pending"><?php esc_html_e( 'Pending', 'bws-meta-manager' ); ?></option>
        </select>
        <?php
    }

    /**
     * Render taxonomies select
     */
    private function render_taxonomies_select( string $field_id = 'taxonomies' ): void {
        $taxonomies = $this->components['field_mapper']->get_taxonomies();
        ?>
        <select name="taxonomies[]" id="<?php echo esc_attr( $field_id ); ?>" class="regular-text" multiple>
            <option value="any" selected><?php esc_html_e( 'All Taxonomies', 'bws-meta-manager' ); ?></option>
            <?php foreach ( $taxonomies as $taxonomy ) : ?>
                <option value="<?php echo esc_attr( $taxonomy['name'] ); ?>">
                    <?php echo esc_html( $taxonomy['label'] ); ?>
                    <?php if ( $taxonomy['hierarchical'] ) : ?>
                        (<?php esc_html_e( 'Hierarchical', 'bws-meta-manager' ); ?>)
                    <?php endif; ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /**
     * Get tab URL
     */
    private function get_tab_url( string $tab ): string {
        return add_query_arg( [
            'page' => 'bws-meta-manager',
            'tab' => $tab
        ], admin_url( 'tools.php' ) );
    }
    
	/**
	 * Handle chunked conversion AJAX request
	 */
	public function handle_chunked_conversion_ajax(): void {
		$this->verify_ajax_request();
		
		$config = $this->sanitize_conversion_config( $_POST );
		$chunk_start = intval( $_POST['chunk_start'] ?? 0 );
		$chunk_size = intval( $_POST['chunk_size'] ?? 5 );
		$session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
		$dry_run = ! empty( $_POST['dry_run'] );
		
		// Map old type names to new ones
		$conversion_type = $config['conversion_type'] ?? '';
		if ( $conversion_type === 'move_data' ) {
			$conversion_type = 'copy_data';
			$config['conversion_type'] = 'copy_data';
		}
		
		// Add session_id to config if provided
		if ( $session_id ) {
			$config['session_id'] = $session_id;
		}
		
		$result = $this->components['data_processor']->process_conversion_chunk( 
			$config, 
			$dry_run, 
			$chunk_start, 
			$chunk_size 
		);
		
		wp_send_json( $result );
	}

/**
 * Handle conversion size estimation AJAX request - FIXED with debugging
 */
public function handle_estimate_conversion_size_ajax(): void {
    $this->verify_ajax_request();
    
    // Add debugging
    error_log('=== ESTIMATION AJAX DEBUG ===');
    error_log('Raw POST data: ' . print_r($_POST, true));
    
    try {
        $config = $this->sanitize_conversion_config( $_POST );
        error_log('Sanitized config: ' . print_r($config, true));
        
        $conversion_type = $config['conversion_type'] ?? '';
        error_log('Conversion type from config: ' . $conversion_type);
        
        // More robust conversion type handling
        if ( empty( $conversion_type ) ) {
            // Try to get it directly from POST if config sanitization failed
            $conversion_type = sanitize_text_field( $_POST['conversion_type'] ?? '' );
            error_log('Conversion type from direct POST: ' . $conversion_type);
        }
        
        // Map old type names to new ones
        if ( $conversion_type === 'move_data' ) {
            $conversion_type = 'copy_data';
            $config['conversion_type'] = 'copy_data';
            error_log('Mapped move_data to copy_data');
        }
        
        // Validate conversion type
        if ( ! in_array( $conversion_type, [ 'copy_data', 'map_data' ], true ) ) {
            error_log('Invalid conversion type detected: ' . $conversion_type);
            wp_send_json_error( [ 
                'message' => sprintf( 
                    __( 'Invalid conversion type: %s. Expected copy_data or map_data.', 'bws-meta-manager' ), 
                    $conversion_type 
                )
            ] );
            return;
        }
        
        error_log('Processing estimation for type: ' . $conversion_type);
        
        // Get estimated item count using a simpler approach
        $items_count = 0;
        
        if ( $conversion_type === 'copy_data' ) {
            $items_count = $this->estimate_copy_data_items( $config );
        } else if ( $conversion_type === 'map_data' ) {
            $items_count = $this->estimate_map_data_items( $config );
        }
        
        error_log('Estimated items count: ' . $items_count);
        
        // Calculate batch size - use a simple default if data processor method fails
        $batch_size = 25; // Default
        try {
            $batch_size = $this->components['data_processor']->calculate_batch_size( $config );
        } catch ( Exception $e ) {
            error_log('Failed to calculate batch size, using default: ' . $e->getMessage());
            $batch_size = intval( $config['batch_size'] ?? 25 );
        }
        
        $total_batches = $items_count > 0 ? ceil( $items_count / $batch_size ) : 0;
        
        // Determine if chunked processing is recommended
        $use_chunked = $total_batches > 10; // More than 10 batches
        $recommended_chunk_size = $use_chunked ? min( 5, max( 2, intval( $total_batches / 20 ) ) ) : 0;
        
        $result = [
            'total_items' => $items_count,
            'batch_size' => $batch_size,
            'total_batches' => $total_batches,
            'use_chunked_processing' => $use_chunked,
            'recommended_chunk_size' => $recommended_chunk_size,
            'estimated_time' => $this->estimate_processing_time( $items_count, $conversion_type ),
            'debug_info' => [
                'conversion_type' => $conversion_type,
                'config_keys' => array_keys( $config )
            ]
        ];
        
        error_log('Estimation successful: ' . print_r($result, true));
        wp_send_json_success( $result );
        
    } catch ( Exception $e ) {
        error_log('Estimation failed with exception: ' . $e->getMessage());
        error_log('Stack trace: ' . $e->getTraceAsString());
        wp_send_json_error( [ 'message' => $e->getMessage() ] );
    }
}

/**
 * Simple estimation for copy data items
 */
private function estimate_copy_data_items( array $config ): int {
    try {
        if ( $config['content_type'] === 'posts' ) {
            $args = [
                'post_type' => $config['post_types'] ?? 'any',
                'post_status' => $config['post_status'] ?? [ 'publish', 'draft', 'private' ],
                'posts_per_page' => -1,
                'fields' => 'ids'
            ];
            
            $query = new WP_Query( $args );
            return $query->found_posts;
            
        } else if ( $config['content_type'] === 'taxonomy_terms' ) {
            $taxonomies = $config['taxonomies'] ?? [ 'any' ];
            
            if ( in_array( 'any', $taxonomies, true ) ) {
                $taxonomies = array_keys( $this->components['field_mapper']->get_taxonomies() );
            }
            
            $total_terms = 0;
            foreach ( $taxonomies as $taxonomy ) {
                if ( taxonomy_exists( $taxonomy ) ) {
                    $term_count = wp_count_terms( [
                        'taxonomy' => $taxonomy,
                        'hide_empty' => false
                    ] );
                    
                    if ( ! is_wp_error( $term_count ) ) {
                        $total_terms += $term_count;
                    }
                }
            }
            
            return $total_terms;
        }
        
        return 0;
        
    } catch ( Exception $e ) {
        error_log('Error estimating copy data items: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Simple estimation for map data items
 */
private function estimate_map_data_items( array $config ): int {
    try {
        // For map data, estimate about 50% of total items will have values
        $total_items = $this->estimate_copy_data_items( $config );
        return max( 1, intval( $total_items * 0.5 ) );
        
    } catch ( Exception $e ) {
        error_log('Error estimating map data items: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Updated sanitize_conversion_config method to handle edge cases better
 */
private function sanitize_conversion_config( array $raw_config ): array {
    $config = [];

    // Common fields
    $config['content_type'] = sanitize_text_field( $raw_config['content_type'] ?? '' );
    $config['batch_size'] = intval( $raw_config['batch_size'] ?? 25 );
    
    // Content filtering
    if ( 'posts' === $config['content_type'] ) {
        $config['post_types'] = array_map( 'sanitize_text_field', $raw_config['post_types'] ?? [ 'any' ] );
        $config['post_status'] = array_map( 'sanitize_text_field', $raw_config['post_status'] ?? [ 'publish' ] );
    } elseif ( 'taxonomy_terms' === $config['content_type'] ) {
        $config['taxonomies'] = array_map( 'sanitize_text_field', $raw_config['taxonomies'] ?? [ 'any' ] );
    }

    // Get conversion type - try multiple sources
    $conversion_type = '';
    
    // First try the direct field
    if ( ! empty( $raw_config['conversion_type'] ) ) {
        $conversion_type = sanitize_text_field( $raw_config['conversion_type'] );
    }
    
    // If not found, try to detect from the form context
    if ( empty( $conversion_type ) ) {
        // Check which fields are present to guess the type
        if ( ! empty( $raw_config['mappings'] ) ) {
            $conversion_type = 'map_data';
        } elseif ( ! empty( $raw_config['copy_type'] ) || ! empty( $raw_config['move_type'] ) ) {
            $conversion_type = 'copy_data';
        }
    }
    
    // Map old type names to new ones
    if ( $conversion_type === 'move_data' ) {
        $conversion_type = 'copy_data';
    }
    
    $config['conversion_type'] = $conversion_type;
    
    // Conversion-specific fields
    switch ( $conversion_type ) {
        case 'copy_data':
            $config['copy_type'] = sanitize_text_field( $raw_config['copy_type'] ?? $raw_config['move_type'] ?? '' );
            $config['source_field'] = sanitize_text_field( $raw_config['source_field'] ?? '' );
            $config['source_taxonomy'] = sanitize_text_field( $raw_config['source_taxonomy'] ?? '' );
            $config['target_field'] = sanitize_text_field( $raw_config['target_field'] ?? '' );
            $config['target_taxonomy'] = sanitize_text_field( $raw_config['target_taxonomy'] ?? '' );
            $config['append_terms'] = (bool) intval( $raw_config['append_terms'] ?? 0 );
            break;
            
        case 'map_data':
            $config['source_field'] = sanitize_text_field( $raw_config['source_field'] ?? '' );
            $config['target_type'] = sanitize_text_field( $raw_config['target_type'] ?? 'field' );
            
            if ( 'field' === $config['target_type'] ) {
                $config['target_field'] = sanitize_text_field( $raw_config['target_field'] ?? '' );
            } else {
                $config['target_taxonomy'] = sanitize_text_field( $raw_config['target_taxonomy'] ?? '' );
                $config['append_terms'] = (bool) intval( $raw_config['append_terms'] ?? 0 );
            }
            
            // Sanitize mappings
            $raw_mappings = $raw_config['mappings'] ?? [];
            $config['mappings'] = [];
            
            if ( is_array( $raw_mappings ) ) {
                foreach ( $raw_mappings as $from => $to ) {
                    // Skip empty mappings (these will be handled as unmapped values)
                    if ( ! empty( trim( $to ) ) ) {
                        $config['mappings'][ sanitize_text_field( $from ) ] = sanitize_text_field( $to );
                    }
                }
            }
            break;
    }

    return $config;
}
	
	
	/**
	 * Get items for copy conversion estimation (faster than full processing)
	 */
	private function get_items_for_copy_conversion_estimation( array $config ): array {
		if ( $config['content_type'] === 'posts' ) {
			$args = [
				'post_type' => $config['post_types'] ?? 'any',
				'post_status' => $config['post_status'] ?? [ 'publish', 'draft', 'private' ],
				'posts_per_page' => -1,
				'fields' => 'ids'
			];
			
			// For estimation, we'll get all posts and assume they have data
			// This is faster than checking each field individually
			$query = new WP_Query( $args );
			
			// Convert to the format expected by the system
			return array_map( function( $post_id ) {
				return [ 'id' => $post_id, 'type' => 'post' ];
			}, $query->posts );
			
		} else {
			$taxonomies = $config['taxonomies'] ?? [ 'any' ];
			
			if ( in_array( 'any', $taxonomies, true ) ) {
				$taxonomies = array_keys( $this->components['field_mapper']->get_taxonomies() );
			}
			
			$all_terms = [];
			foreach ( $taxonomies as $taxonomy ) {
				if ( taxonomy_exists( $taxonomy ) ) {
					$terms = get_terms( [
						'taxonomy' => $taxonomy,
						'hide_empty' => false,
						'fields' => 'ids'
					] );
					
					if ( ! is_wp_error( $terms ) ) {
						foreach ( $terms as $term_id ) {
							$all_terms[] = [ 
								'id' => $term_id, 
								'type' => 'term', 
								'taxonomy' => $taxonomy 
							];
						}
					}
				}
			}
			
			return $all_terms;
		}
	}
	
	/**
	 * Get items for map conversion estimation (simplified)
	 */
	private function get_items_for_map_conversion_estimation( array $config ): array {
		// For map conversions, we'll get a reasonable estimate
		// The actual processing will filter these further
		$base_items = $this->get_items_for_copy_conversion_estimation( $config );
		
		// For estimation purposes, assume about 50% of items will have values
		// This gives us a reasonable ballpark for UI decisions
		$estimated_count = count( $base_items );
		$filtered_count = max( 1, intval( $estimated_count * 0.5 ) );
		
		// Return a subset for estimation
		return array_slice( $base_items, 0, $filtered_count );
	}
	
	/**
	 * Calculate batch size for estimation (simpler version)
	 */
	public function calculate_batch_size_for_estimation( array $config ): int {
		$base_size = $config['batch_size'] ?? 25;
		
		// Simple memory-based adjustment
		$memory_limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		$available_memory = $memory_limit - memory_get_usage();
		
		// Adjust batch size based on available memory
		if ( $available_memory < ( $memory_limit * 0.3 ) ) {
			$base_size = max( 5, intval( $base_size * 0.5 ) );
		}
		
		return $base_size;
	}
	
	/**
	 * Estimate processing time based on item count and conversion type
	 */
	private function estimate_processing_time( int $items, string $conversion_type ): array {
		// Base processing times per item (in seconds)
		$time_per_item = [
			'copy_data' => 0.05,  // 50ms per item
			'map_data' => 0.08    // 80ms per item (more complex)
		];
		
		$base_time = $time_per_item[ $conversion_type ] ?? 0.06;
		$total_seconds = $items * $base_time;
		
		// Add overhead for AJAX requests and database operations
		$overhead_factor = 1.3;
		$estimated_seconds = intval( $total_seconds * $overhead_factor );
		
		return [
			'seconds' => $estimated_seconds,
			'formatted' => $this->format_duration( $estimated_seconds ),
			'items_per_second' => $items > 0 ? round( 1 / $base_time, 1 ) : 0
		];
	}
	
	/**
	 * Format duration in human-readable format
	 */
	private function format_duration( int $seconds ): string {
		if ( $seconds < 60 ) {
			return sprintf( _n( '%d second', '%d seconds', $seconds, 'bws-meta-manager' ), $seconds );
		} elseif ( $seconds < 3600 ) {
			$minutes = intval( $seconds / 60 );
			$remaining_seconds = $seconds % 60;
			
			if ( $remaining_seconds === 0 ) {
				return sprintf( _n( '%d minute', '%d minutes', $minutes, 'bws-meta-manager' ), $minutes );
			} else {
				return sprintf( 
					__( '%d minutes, %d seconds', 'bws-meta-manager' ), 
					$minutes, 
					$remaining_seconds 
				);
			}
		} else {
			$hours = intval( $seconds / 3600 );
			$remaining_minutes = intval( ( $seconds % 3600 ) / 60 );
			
			if ( $remaining_minutes === 0 ) {
				return sprintf( _n( '%d hour', '%d hours', $hours, 'bws-meta-manager' ), $hours );
			} else {
				return sprintf( 
					__( '%d hours, %d minutes', 'bws-meta-manager' ), 
					$hours, 
					$remaining_minutes 
				);
			}
		}
	}
	
	/**
	 * Get actual field name (helper method - may already exist in your class)
	 */
	private function get_actual_field_name( array $source_field_data ): string {
		// Check if this is a sub-field
		if ( ! empty( $source_field_data['parent'] ) ) {
			$parent_field_data = $this->components['field_mapper']->get_field_by_key( $source_field_data['parent'] );
			
			if ( $parent_field_data ) {
				switch ( $parent_field_data['type'] ) {
					case 'group':
						return $parent_field_data['name'] . '_' . $source_field_data['name'];
						
					case 'repeater':
						return $parent_field_data['name'] . '_0_' . $source_field_data['name'];
						
					case 'flexible_content':
						return $parent_field_data['name'] . '_0_' . $source_field_data['name'];
						
					default:
						return $parent_field_data['name'] . '_' . $source_field_data['name'];
				}
			}
		}
		
		return $source_field_data['name'];
	}

	
	/**
	 * Get estimated count for copy conversion items
	 */
	private function get_copy_conversion_items_count( array $config ): int {
		if ( $config['content_type'] === 'posts' ) {
			$args = [
				'post_type' => $config['post_types'] ?? 'any',
				'post_status' => $config['post_status'] ?? [ 'publish', 'draft', 'private' ],
				'posts_per_page' => -1,
				'fields' => 'ids'
			];
			
			// Add meta query if we have a source field
			if ( isset( $config['source_field'] ) ) {
				$field_mapper = $this->components['field_mapper'];
				$source_field = $field_mapper->get_field_by_key( $config['source_field'] );
				if ( $source_field ) {
					$actual_field_name = $this->get_actual_field_name( $source_field );
					$args['meta_query'] = [
						[
							'key' => $actual_field_name,
							'compare' => 'EXISTS'
						]
					];
				}
			}
			
			$query = new WP_Query( $args );
			return $query->found_posts;
			
		} else {
			$taxonomies = $config['taxonomies'] ?? [ 'any' ];
			
			if ( in_array( 'any', $taxonomies, true ) ) {
				$taxonomies = array_keys( $this->components['field_mapper']->get_taxonomies() );
			}
			
			$total_terms = 0;
			foreach ( $taxonomies as $taxonomy ) {
				if ( taxonomy_exists( $taxonomy ) ) {
					$term_count = wp_count_terms( [
						'taxonomy' => $taxonomy,
						'hide_empty' => false
					] );
					
					if ( ! is_wp_error( $term_count ) ) {
						$total_terms += $term_count;
					}
				}
			}
			
			return $total_terms;
		}
	}
	
	/**
	 * Get estimated count for map conversion items
	 */
	private function get_map_conversion_items_count( array $config ): int {
		// For map conversions, we need to actually check which items have values
		// This is a simplified estimate - for exact count, we'd need to run the full query
		$base_count = $this->get_copy_conversion_items_count( $config );
		
		// Estimate that about 30-70% of items will have values in the source field
		// This is just an estimate to help with UI decisions
		return intval( $base_count * 0.5 );
	}
	

    /**
     * Check if ACF Pro is available
     */
    private function is_acf_pro_available(): bool {
        return function_exists( 'acf' ) && ( class_exists( 'ACF_PRO' ) || defined( 'ACF_PRO' ) );
    }
}