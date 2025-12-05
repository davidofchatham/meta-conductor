<?php 

/*
$processed = bulk_update_athletics_events_date_time_fields(0, 3000);  // Process posts starting at x, batch size of y

function bulk_update_athletics_events_date_time_fields($start_offset = 0, $batch_size = 100) {
	// Set a high memory limit and execution time for bulk processing
	@ini_set('memory_limit', '512M');
	@set_time_limit(0);

	// Query parameters
	$args = [
		'post_type' => 'athletics_events',
		'posts_per_page' => $batch_size,
		'offset' => $start_offset,
		'no_found_rows' => true, // Enable to improve performance
		'update_post_meta_cache' => false, // Disable to improve performance
		'update_post_term_cache' => false // Disable to improve performance
	];

	// Run the query
	$query = new WP_Query($args);

	// Track processed posts
	$processed_count = 0;

	// Process posts in this batch
	if ($query->have_posts()) {
		while ($query->have_posts()) {
			$query->the_post();
			$post_id = get_the_ID();
			
			format_athletics_event_time($post_id);
			set_athletics_event_date_time_field($post_id);
			$processed_count++;
		}
	}

	// Reset the query
	wp_reset_postdata();

	// Return the number of processed posts
	return $processed_count;
}
*/


// Standardize athletics events time field

// ACF pre-save hook
// NOTE: This ACF hook does not recognize the group name as part of the field name
add_filter('acf/update_value/name=time', 'format_athletics_event_time_presave', 10, 3);

// ACF is processing updates made via Admin Columns Pro, so the helper function isn't required in this case
//add_filter('acp/editing/save_value', 'acp_format_athletics_event_time_presave', 10, 2);


// Set date-time field from separate date and time fields

// Default ACF filter
//add_action('acf/save_post', 'set_athletics_event_date_time_field', 20);
// ACF Extended hook:
add_action('acfe/save_post/post_type=athletics_events', 'set_athletics_event_date_time_field', 20);

// Admin Columns Pro helper:
add_filter('acp/editing/saved', 'acp_set_athletics_event_date_time_field', 20, 3);


function standardize_time_format($time_value, $time_format = 'g:i A') {
	if (empty($time_value)) {
		return [false, $time_value];
	}

	// Set new variable to avoid adding "PM" to original string
	$time_to_parse = $time_value;
	
	// If time doesn't start with 13-24 and doesn't contain AM/PM indicator, assume PM
	if (!preg_match('/^(1[3-9]|2[0-4])/', $time_value) && !preg_match('/\b(a\.?m\.?|p\.?m\.?)\b/i', $time_value)) {
		$time_to_parse .= ' PM';
	}
	
	$parsed_time = strtotime($time_to_parse);
	
	if ($parsed_time !== false) {
		$time_obj = new DateTime();
		$time_obj->setTimestamp($parsed_time);
		return [true, $time_obj->format($time_format)];
	}
	
	return [false, $time_value];
}

function format_athletics_event_time_presave($value, $post_id, $field) {
	if (get_post_type($post_id) !== 'athletics_events' || $field['name'] !== 'game_schedule_time') {
		return $value;
	}

	$post_title = get_the_title($post_id);
	[$success, $formatted_value] = standardize_time_format($value, 'g:i A');
	
	if ($success) {
		error_log("Athletics Events Time Format: Formatted time from '{$value}' to '{$formatted_value}' for post ID {$post_id} - '{$post_title}'");
	} else {
		error_log("Athletics Events Time Format: Keeping original time '{$value}' for post ID {$post_id} - '{$post_title}'");
	}
	
	return $formatted_value;
}

function format_athletics_event_time($post_id) {
	if (get_post_type($post_id) !== 'athletics_events') {
		return;
	}

	$value = get_field('game_schedule_time', $post_id);

	// If no time is set, return early
	if (empty($value)) {
		error_log("Athletics Events Time Update: No game time found for post ID {$post_id} - '{$post_title}'");
		return;
	}

	$post_title = get_the_title($post_id);
	[$success, $formatted_value] = standardize_time_format($value, 'g:i A');
	
	if ($success) {
		// Update time field
		update_field('game_schedule_time', $formatted_value, $post_id);

		error_log("Athletics Events Time Format: Formatted time from '{$value}' to '{$formatted_value}' for post ID {$post_id} - '{$post_title}'");
	} else {
		error_log("Athletics Events Time Format: Keeping original time '{$value}' for post ID {$post_id} - '{$post_title}'");
	}
}

function acp_format_athletics_event_time_presave($value, AC\Column $column)
{
	if ( $column instanceof ACA\ACF\Column && 
		in_array( $column->get_post_type(), ['athletics_events'] ) && 
		in_array( $column->get_meta_key(), ['game_schedule_time'] ) &&
		$value) {
			[$success, $formatted_value] = standardize_time_format($value, 'g:i A');
			return $formatted_value;
	} else {
		return $value;
	}

}


function set_athletics_event_date_time_field($post_id) {
	// Check if this is an 'athletics_events' post type
	if (get_post_type($post_id) !== 'athletics_events') {
		return;
	}

	// Get post title for logging
	$post_title = get_the_title($post_id);

	// Get the game schedule date from ACF
	$game_date = get_field('game_schedule_date', $post_id);
	
	// Get the game schedule time from ACF
	$game_time = get_field('game_schedule_time', $post_id);

	// If no date is set, clear date-time field and return early
	if (empty($game_date)) {
		delete_field('game_schedule_date_time', $post_id);
		error_log("Athletics Events Date-Time Update: No game date found for post ID {$post_id} - '{$post_title}'. Clearing date-time field.");
		return;
	}

	// Parse the date in m/d/Y format
	$date_obj = DateTime::createFromFormat('m/d/Y', $game_date);
	
	// If date parsing fails, clear date-time field and return early
	if (!$date_obj) {
		delete_field('game_schedule_date_time', $post_id);
		error_log("Athletics Events Date-Time Update: Failed to parse date '{$game_date}' for post ID {$post_id} - '{$post_title}'. Clearing date-time field.");
		return;
	}

	// Determine the time using strtotime
	$time_str = '12:00:00'; // Default to noon
	$time_parsing_status = 'default';
	
	if (!empty($game_time)) {
		// Attempt to parse the time using strtotime
		$parsed_time = strtotime($game_time, $date_obj->getTimestamp());
		
		if ($parsed_time !== false) {
			// If strtotime successfully parses the time, use it
			$time_obj = new DateTime();
			$time_obj->setTimestamp($parsed_time);
			$time_str = $time_obj->format('H:i:s');
			$time_parsing_status = 'parsed';
		} else {
			// Log failed time parsing
			error_log("Athletics Events Date-Time Update: Failed to parse time '{$game_time}' for post ID {$post_id} - '{$post_title}'. Defaulting to noon.");
			$time_parsing_status = 'failed';
		}
	} else {
		$time_parsing_status = 'empty';
		error_log("Athletics Events Date-Time Update: No game time found for post ID {$post_id} - '{$post_title}'. Defaulting to noon.");
	}

	// Combine date and time
	$full_datetime = $date_obj->format('Y-m-d') . ' ' . $time_str;
	
	// Update date-time field
	update_field('game_schedule_date_time', $full_datetime, $post_id);

	// Optional: Log successful update with parsing status
	error_log("Athletics Events Date-Time Update: Successfully updated post ID {$post_id} - '{$post_title}'. Date and time: {$full_datetime}. Time parsing status: {$time_parsing_status}");
}

function acp_set_athletics_event_date_time_field(AC\Column $column, $post_id, $value)
{
	// Does not require $value so unsetting a field also triggers recalculation
	if ( $column instanceof ACA\ACF\Column && 
		in_array( $column->get_post_type(), ['athletics_events'] ) && 
		in_array( $column->get_meta_key(), ['game_schedule_date', 'game_schedule_time'] )) {
			set_athletics_event_date_time_field($post_id);
	}
}
