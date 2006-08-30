<?php

class retrospam_mgr {
	var $spam_words;
	var $comments_list;
	var $found_comments;

	function retrospam_mgr() {
		global $wpdb;

		$list = explode("\n", get_settings('moderation_keys') );
		$list = array_unique( $list );
		$this->spam_words = $list;

		$this->comment_list = $wpdb->get_results("SELECT comment_ID AS ID, comment_content AS text, comment_approved AS approved, comment_author_url AS url, comment_author_ip AS ip, comment_author_email AS email FROM $wpdb->comments ORDER BY comment_ID ASC");
	}	// End of class constructor

	function move_spam( $id_list ) {
		global $wpdb;
		$cnt = 0;
		$id_list = explode( ',', $id_list );

		foreach ( $id_list as $comment ) {
			if ( $wpdb->query("update $wpdb->comments set comment_approved = '0' where comment_ID = '$comment'") ) {
				$cnt++;
			}
		}
		echo "<div class='updated'><p>$cnt comment";
		if ($cnt != 1 ) echo "s";
		echo " moved to the moderation queue.</p></div>\n";
	}	// End function move_spam

	function find_spam() {
		$in_queue = 0;

		foreach( $this->comment_list as $comment ) {
			if( $comment->approved == 1 ) {
				foreach( $this->spam_words as $word ) {
					$word = trim($word);
					if ( empty( $word ) )
						continue;
					$fulltext = strtolower($comment->email.' '.$comment->url.' '.$comment->ip.' '.$comment->text);
					if( false !== strpos( $fulltext, strtolower($word) ) ) {
						$this->found_comments[] = $comment->ID;
						break;
					}
				}
			} else {
				$in_queue++;
			}
		}
		return array( 'found' => $this->found_comments, 'in_queue' => $in_queue );
	}	// End function find_spam

	function display_edit_form( $counters ) {
		$numfound = count($counters[found]);
		$numqueue = $counters[in_queue];

		$body = '<p>' . sprintf(__('Suspected spam comments: <strong>%s</strong>'), $numfound) . '</p>';

		if ( count($counters[found]) > 0 ) {
			$id_list = implode( ',', $counters[found] );
			$body .= '<p><a href="options-discussion.php?action=retrospam&amp;move=true&amp;ids='.$id_list.'">'. __('Move suspect comments to moderation queue &raquo;') . '</a></p>';

		}
		$head = '<div class="wrap"><h2>' . __('Check Comments Results:') . '</h2>';

		$foot .= '<p><a href="options-discussion.php">' . __('&laquo; Return to Discussion Options page.') . '</a></p></div>';

		return $head . $body . $foot;
	} 	// End function display_edit_form

}

class WP {
	var $public_query_vars = array('m', 'p', 'posts', 'w', 'cat', 'withcomments', 's', 'search', 'exact', 'sentence', 'debug', 'calendar', 'page', 'paged', 'more', 'tb', 'pb', 'author', 'order', 'orderby', 'year', 'monthnum', 'day', 'hour', 'minute', 'second', 'name', 'category_name', 'feed', 'author_name', 'static', 'pagename', 'page_id', 'error', 'comments_popup', 'attachment', 'attachment_id', 'subpost', 'subpost_id', 'preview', 'robots');

	var $private_query_vars = array('offset', 'posts_per_page', 'posts_per_archive_page', 'what_to_show', 'showposts', 'nopaging', 'post_type');
	var $extra_query_vars = array();

	var $query_vars;
	var $query_string;
	var $request;
	var $matched_rule;
	var $matched_query;
	var $did_permalink = false;
	
	function add_query_var($qv) {
		$this->public_query_vars[] = $qv;
	}

	function parse_request($extra_query_vars = '') {
		global $wp_rewrite;

		$this->query_vars = array();

		if ( is_array($extra_query_vars) )
			$this->extra_query_vars = & $extra_query_vars;
		else if (! empty($extra_query_vars))
			parse_str($extra_query_vars, $this->extra_query_vars);

		// Process PATH_INFO, REQUEST_URI, and 404 for permalinks.

		// Fetch the rewrite rules.
		$rewrite = $wp_rewrite->wp_rewrite_rules();

		if (! empty($rewrite)) {
			// If we match a rewrite rule, this will be cleared.
			$error = '404';
			$this->did_permalink = true;

			$pathinfo = $_SERVER['PATH_INFO'];
			$pathinfo_array = explode('?', $pathinfo);
			$pathinfo = $pathinfo_array[0];
			$req_uri = $_SERVER['REQUEST_URI'];
			$req_uri_array = explode('?', $req_uri);
			$req_uri = $req_uri_array[0];
			$self = $_SERVER['PHP_SELF'];
			$home_path = parse_url(get_settings('home'));
			$home_path = $home_path['path'];
			$home_path = trim($home_path, '/');

			// Trim path info from the end and the leading home path from the
			// front.  For path info requests, this leaves us with the requesting
			// filename, if any.  For 404 requests, this leaves us with the
			// requested permalink.
			$req_uri = str_replace($pathinfo, '', $req_uri);
			$req_uri = trim($req_uri, '/');
			$req_uri = preg_replace("|^$home_path|", '', $req_uri);
			$req_uri = trim($req_uri, '/');
			$pathinfo = trim($pathinfo, '/');
			$pathinfo = preg_replace("|^$home_path|", '', $pathinfo);
			$pathinfo = trim($pathinfo, '/');
			$self = trim($self, '/');
			$self = preg_replace("|^$home_path|", '', $self);
			$self = str_replace($home_path, '', $self);
			$self = trim($self, '/');

			// The requested permalink is in $pathinfo for path info requests and
			//  $req_uri for other requests.
			if ( ! empty($pathinfo) && !preg_match('|^.*' . $wp_rewrite->index . '$|', $pathinfo) ) {
				$request = $pathinfo;
			} else {
				// If the request uri is the index, blank it out so that we don't try to match it against a rule.
				if ( $req_uri == $wp_rewrite->index )
					$req_uri = '';
				$request = $req_uri;
			}

			$this->request = $request;

			// Look for matches.
			$request_match = $request;
			foreach ($rewrite as $match => $query) {
				// If the requesting file is the anchor of the match, prepend it
				// to the path info.
				if ((! empty($req_uri)) && (strpos($match, $req_uri) === 0) && ($req_uri != $request)) {
					$request_match = $req_uri . '/' . $request;
				}

				if (preg_match("!^$match!", $request_match, $matches) ||
					preg_match("!^$match!", urldecode($request_match), $matches)) {
					// Got a match.
					$this->matched_rule = $match;
					
					// Trim the query of everything up to the '?'.
					$query = preg_replace("!^.+\?!", '', $query);

					// Substitute the substring matches into the query.
					eval("\$query = \"$query\";");
					$this->matched_query = $query;

					// Parse the query.
					parse_str($query, $perma_query_vars);

					// If we're processing a 404 request, clear the error var
					// since we found something.
					if (isset($_GET['error']))
						unset($_GET['error']);

					if (isset($error))
						unset($error);

					break;
				}
			}

			// If req_uri is empty or if it is a request for ourself, unset error.
			if ( empty($request) || $req_uri == $self || strstr($_SERVER['PHP_SELF'], 'wp-admin/') ) {
				if (isset($_GET['error']))
					unset($_GET['error']);

				if (isset($error))
					unset($error);

				if ( isset($perma_query_vars) && strstr($_SERVER['PHP_SELF'], 'wp-admin/') )
					unset($perma_query_vars);

				$this->did_permalink = false;
			}
		}

		$this->public_query_vars = apply_filters('query_vars', $this->public_query_vars);

		for ($i=0; $i<count($this->public_query_vars); $i += 1) {
			$wpvar = $this->public_query_vars[$i];
			if (isset($this->extra_query_vars[$wpvar]))
				$this->query_vars[$wpvar] = $this->extra_query_vars[$wpvar];
			elseif (isset($GLOBALS[$wpvar]))
				$this->query_vars[$wpvar] = $GLOBALS[$wpvar];
			elseif (!empty($_POST[$wpvar]))
				$this->query_vars[$wpvar] = $_POST[$wpvar];
			elseif (!empty($_GET[$wpvar]))
				$this->query_vars[$wpvar] = $_GET[$wpvar];
			elseif (!empty($perma_query_vars[$wpvar]))
				$this->query_vars[$wpvar] = $perma_query_vars[$wpvar];
		}

		foreach ($this->private_query_vars as $var) {
			if (isset($GLOBALS[$var]) && '' != $GLOBALS[$var] && ! isset($this->extra_query_vars[$var]) )
				$this->query_vars[$var] = $GLOBALS[$var];
		}

		if ( isset($error) )
			$this->query_vars['error'] = $error;

		$this->query_vars = apply_filters('request', $this->query_vars);

		do_action('parse_request', array(&$this));
	}

	function send_headers() {
		@header('X-Pingback: '. get_bloginfo('pingback_url'));
		if ( is_user_logged_in() )
			nocache_headers();
		if ( !empty($this->query_vars['error']) && '404' == $this->query_vars['error'] ) {
			status_header( 404 );
			@header('Content-type: ' . get_option('html_type') . '; charset=' . get_option('blog_charset'));
		} else if ( empty($this->query_vars['feed']) ) {
			@header('Content-type: ' . get_option('html_type') . '; charset=' . get_option('blog_charset'));
		} else {
			// We're showing a feed, so WP is indeed the only thing that last changed
			if ( $this->query_vars['withcomments'] )
				$wp_last_modified = mysql2date('D, d M Y H:i:s', get_lastcommentmodified('GMT'), 0).' GMT';
			else 
				$wp_last_modified = mysql2date('D, d M Y H:i:s', get_lastpostmodified('GMT'), 0).' GMT';
			$wp_etag = '"' . md5($wp_last_modified) . '"';
			@header("Last-Modified: $wp_last_modified");
			@header("ETag: $wp_etag");

			// Support for Conditional GET
			if (isset($_SERVER['HTTP_IF_NONE_MATCH'])) 
				$client_etag = stripslashes(stripslashes($_SERVER['HTTP_IF_NONE_MATCH']));
			else $client_etag = false;

			$client_last_modified = trim( $_SERVER['HTTP_IF_MODIFIED_SINCE']);
			// If string is empty, return 0. If not, attempt to parse into a timestamp
			$client_modified_timestamp = $client_last_modified ? strtotime($client_last_modified) : 0;

			// Make a timestamp for our most recent modification...
			$wp_modified_timestamp = strtotime($wp_last_modified);

			if ( ($client_last_modified && $client_etag) ?
					 (($client_modified_timestamp >= $wp_modified_timestamp) && ($client_etag == $wp_etag)) :
					 (($client_modified_timestamp >= $wp_modified_timestamp) || ($client_etag == $wp_etag)) ) {
				status_header( 304 );
				exit;
			}
		}

		do_action('send_headers', array(&$this));
	}

	function build_query_string() {
		$this->query_string = '';
		foreach (array_keys($this->query_vars) as $wpvar) {
			if ( '' != $this->query_vars[$wpvar] ) {
				$this->query_string .= (strlen($this->query_string) < 1) ? '' : '&';
				$this->query_string .= $wpvar . '=' . rawurlencode($this->query_vars[$wpvar]);
			}
		}

		// query_string filter deprecated.  Use request filter instead.
		global $wp_filter;
		if ( isset($wp_filter['query_string']) ) {  // Don't bother filtering and parsing if no plugins are hooked in.
			$this->query_string = apply_filters('query_string', $this->query_string);
			parse_str($this->query_string, $this->query_vars);
		}
	}

	function register_globals() {
		global $wp_query;
		// Extract updated query vars back into global namespace.
		foreach ($wp_query->query_vars as $key => $value) {
			$GLOBALS[$key] = $value;
		}

		$GLOBALS['query_string'] = & $this->query_string;
		$GLOBALS['posts'] = & $wp_query->posts;
		$GLOBALS['post'] = & $wp_query->post;
		$GLOBALS['request'] = & $wp_query->request;

		if ( is_single() || is_page() ) {
			$GLOBALS['more'] = 1;
			$GLOBALS['single'] = 1;
		}
	}

	function init() {
		wp_get_current_user();
	}

	function query_posts() {
		$this->build_query_string();
		query_posts($this->query_vars);
 	}

	function handle_404() {
		global $wp_query;
		// Issue a 404 if a permalink request doesn't match any posts.  Don't
		// issue a 404 if one was already issued, if the request was a search,
		// or if the request was a regular query string request rather than a
		// permalink request.
		if ( (0 == count($wp_query->posts)) && !is_404() && !is_search() && ( $this->did_permalink || (!empty($_SERVER['QUERY_STRING']) && (false === strpos($_SERVER['REQUEST_URI'], '?'))) ) ) {
			$wp_query->set_404();
			status_header( 404 );
		}	elseif( is_404() != true ) {
			status_header( 200 );
		}
	}

	function main($query_args = '') {
		$this->init();
		$this->parse_request($query_args);
		$this->send_headers();
		$this->query_posts();
		$this->handle_404();
		$this->register_globals();
		do_action('wp', array(&$this));
	}

	function WP() {
		// Empty.
	}
}

class WP_Error {
	var $errors = array();
	var $error_data = array();

	function WP_Error($code = '', $message = '', $data = '') {
		if ( empty($code) )
			return;

		$this->errors[$code][] = $message;

		if ( ! empty($data) )
			$this->error_data[$code] = $data;
	}

	function get_error_codes() {
		if ( empty($this->errors) )
			return array();

		return array_keys($this->errors);
	}

	function get_error_code() {
		$codes = $this->get_error_codes();

		if ( empty($codes) )
			return '';

		return $codes[0];	
	}

	function get_error_messages($code = '') {
		// Return all messages if no code specified.
		if ( empty($code) ) {
			$all_messages = array();
			foreach ( $this->errors as $code => $messages )
				$all_messages = array_merge($all_messages, $messages);

			return $all_messages;
		}

		if ( isset($this->errors[$code]) )
			return $this->errors[$code];
		else
			return array();	
	}

	function get_error_message($code = '') {
		if ( empty($code) )
			$code = $this->get_error_code();
		$messages = $this->get_error_messages($code);
		if ( empty($messages) )
			return '';
		return $messages[0];
	}

	function get_error_data($code = '') {
		if ( empty($code) )
			$code = $this->get_error_code();

		if ( isset($this->error_data[$code]) )
			return $this->error_data[$code];
		return null;
	}

	function add($code, $message, $data = '') {
		$this->errors[$code][] = $message;
		if ( ! empty($data) )
			$this->error_data[$code] = $data;
	}

	function add_data($data, $code = '') {
		if ( empty($code) )
			$code = $this->get_error_code();

		$this->error_data[$code] = $data;
	}
}

function is_wp_error($thing) {
	if ( is_object($thing) && is_a($thing, 'WP_Error') )
		return true;
	return false;
}


// A class for displaying various tree-like structures. Extend the Walker class to use it, see examples at the bottom

class Walker {	
	var $tree_type;
	var $db_fields;
	
	//abstract callbacks
	function start_lvl($output) { return $output; }
	function end_lvl($output)   { return $output; }
	function start_el($output)  { return $output; }
	function end_el($output)    { return $output; }
	
	function walk($elements, $to_depth) {
		$args = array_slice(func_get_args(), 2); $parents = array(); $depth = 1; $previous_element = ''; $output = '';
	
		//padding at the end
		$last_element->post_parent = 0;
		$last_element->post_id = 0;
		$elements[] = $last_element;
	
		$id_field = $this->db_fields['id'];
		$parent_field = $this->db_fields['parent'];
	
		$flat = ($to_depth == -1) ? true : false;
	
		foreach ( $elements as $element ) {
			// If flat, start and end the element and skip the level checks.
			if ( $flat) {
				// Start the element.
				if ( $element->$id_field != 0 ) {
					$cb_args = array_merge( array($output, $element, $depth - 1), $args);
					$output = call_user_func_array(array(&$this, 'start_el'), $cb_args);
				}
	
				// End the element.
				if ( $element->$id_field != 0 ) {
					$cb_args = array_merge( array($output, $element, $depth - 1), $args);
					$output = call_user_func_array(array(&$this, 'end_el'), $cb_args);
				}
	
				continue;	
			}
	
			// Walk the tree.
			if ( !empty($previous_element) && ($element->$parent_field == $previous_element->$id_field) ) {
				// Previous element is my parent. Descend a level.
				array_unshift($parents, $previous_element);
				$depth++; //always do this so when we start the element further down, we know where we are
				if ( !$to_depth || ($depth < $to_depth) ) { //only descend if we're below $to_depth
					$cb_args = array_merge( array($output, $depth - 1), $args);
					$output = call_user_func_array(array(&$this, 'start_lvl'), $cb_args);
				}
			} else if ( $element->$parent_field == $previous_element->$parent_field) {
				// On the same level as previous element.
				if ( !$to_depth || ($depth <= $to_depth) ) {
					$cb_args = array_merge( array($output, $previous_element, $depth - 1), $args);
					$output = call_user_func_array(array(&$this, 'end_el'), $cb_args);
				}
			} else if ( $depth > 1 ) {
				// Ascend one or more levels.
				if ( !$to_depth || ($depth <= $to_depth) ) {
					$cb_args = array_merge( array($output, $previous_element, $depth - 1), $args);
					$output = call_user_func_array(array(&$this, 'end_el'), $cb_args);
				}
	
				while ( $parent = array_shift($parents) ) {
					$depth--;
					if ( !$to_depth || ($depth < $to_depth) ) {
						$cb_args = array_merge( array($output, $depth - 1), $args);
						$output = call_user_func_array(array(&$this, 'end_lvl'), $cb_args);
						$cb_args = array_merge( array($output, $parent, $depth - 1), $args);
						$output = call_user_func_array(array(&$this, 'end_el'), $cb_args);
					}
					if ( $element->$parent_field == $parents[0]->$id_field ) {
						break;
					}
				}
			} else if ( !empty($previous_element) ) {
				// Close off previous element.
				if ( !$to_depth || ($depth <= $to_depth) ) {
					$cb_args = array_merge( array($output, $previous_element, $depth - 1), $args);
					$output = call_user_func_array(array(&$this, 'end_el'), $cb_args);
				}
			}
	
			// Start the element.
			if ( !$to_depth || ($depth <= $to_depth) ) {
				if ( $element->$id_field != 0 ) {
					$cb_args = array_merge( array($output, $element, $depth - 1), $args);
					$output = call_user_func_array(array(&$this, 'start_el'), $cb_args);
				}
			}
	
			$previous_element = $element;
		}
		
		return $output;
	}
}

class Walker_Page extends Walker {
	var $tree_type = 'page';
	var $db_fields = array ('parent' => 'post_parent', 'id' => 'ID'); //TODO: decouple this
	
	function start_lvl($output, $depth) {
		$indent = str_repeat("\t", $depth);
		$output .= "$indent<ul>\n";
		return $output;
	}
	
	function end_lvl($output, $depth) {
		$indent = str_repeat("\t", $depth);
		$output .= "$indent</ul>\n";
		return $output;
	}
	
	function start_el($output, $page, $depth, $current_page, $show_date, $date_format) {
		if ( $depth )
			$indent = str_repeat("\t", $depth);

		$css_class = 'page_item';
		if ( $page->ID == $current_page )
			$css_class .= ' current_page_item';

		$output .= $indent . '<li class="' . $css_class . '"><a href="' . get_page_link($page->ID) . '" title="' . wp_specialchars($page->post_title, 1) . '">' . $page->post_title . '</a>';
	
		if ( !empty($show_date) ) {
			if ( 'modified' == $show_date )
				$time = $page->post_modified;
			else
				$time = $page->post_date;
	
			$output .= " " . mysql2date($date_format, $time);
		}

		return $output;
	}
	
	function end_el($output, $page, $depth) {
		$output .= "</li>\n";

		return $output;
	}

}

class Walker_PageDropdown extends Walker {
	var $tree_type = 'page';
	var $db_fields = array ('parent' => 'post_parent', 'id' => 'ID'); //TODO: decouple this

	function start_el($output, $page, $depth, $args) {
        $pad = str_repeat('&nbsp;', $depth * 3);

        $output .= "\t<option value=\"$page->ID\"";
        if ( $page->ID == $args['selected'] )
                $output .= ' selected="selected"';
        $output .= '>';
        $title = wp_specialchars($page->post_title);
        $output .= "$pad$title";
        $output .= "</option>\n";

        return $output;
	}
}

class Walker_Category extends Walker {
	var $tree_type = 'category';
	var $db_fields = array ('parent' => 'category_parent', 'id' => 'cat_ID'); //TODO: decouple this
	
	function start_lvl($output, $depth, $args) {
		if ( 'list' != $args['style'] )
			return $output;
	
		$indent = str_repeat("\t", $depth);
		$output .= "$indent<ul class='children'>\n";
		return $output;
	}
	
	function end_lvl($output, $depth, $args) {
		if ( 'list' != $args['style'] )
			return $output;
	
		$indent = str_repeat("\t", $depth);
		$output .= "$indent</ul>\n";
		return $output;
	}
	
	function start_el($output, $category, $depth, $args) {
		extract($args);
	
		$link = '<a href="' . get_category_link($category->cat_ID) . '" ';
		if ( $use_desc_for_title == 0 || empty($category->category_description) )
			$link .= 'title="'. sprintf(__("View all posts filed under %s"), wp_specialchars($category->cat_name, 1)) . '"';
		else
			$link .= 'title="' . wp_specialchars(apply_filters('category_description',$category->category_description,$category),1) . '"';
		$link .= '>';
		$link .= apply_filters('list_cats', $category->cat_name, $category).'</a>';
	
		if ( (! empty($feed_image)) || (! empty($feed)) ) {
			$link .= ' ';
	
			if ( empty($feed_image) )
				$link .= '(';
	
			$link .= '<a href="' . get_category_rss_link(0, $category->cat_ID, $category->category_nicename) . '"';
	
			if ( !empty($feed) ) {
				$title = ' title="' . $feed . '"';
				$alt = ' alt="' . $feed . '"';
				$name = $feed;
				$link .= $title;
			}
	
			$link .= '>';
	
			if ( !empty($feed_image) )
				$link .= "<img src='$feed_image' $alt$title" . ' />';
			else
				$link .= $name;
			$link .= '</a>';
			if (empty($feed_image))
				$link .= ')';
		}
	
		if ( $show_count )
			$link .= ' ('.intval($category->category_count).')';
	
		if ( $show_date ) {
			$link .= ' ' . gmdate('Y-m-d', $category->last_update_timestamp);
		}
	
		if ( 'list' == $args['style'] ) {
			$output .= "\t<li";
			if ( ($category->cat_ID == $current_category) && is_category() )
				$output .=  ' class="current-cat"';
			$output .= ">$link\n";
		} else {
			$output .= "\t$link<br />\n";
		}
	
		return $output;
	}
	
	function end_el($output, $page, $depth, $args) {
		if ( 'list' != $args['style'] )
			return $output;
	
		$output .= "</li>\n";
		return $output;
	}

}

class Walker_CategoryDropdown extends Walker {
	var $tree_type = 'category';
	var $db_fields = array ('parent' => 'category_parent', 'id' => 'cat_ID'); //TODO: decouple this
	
	function start_el($output, $category, $depth, $args) { 
		$pad = str_repeat('&nbsp;', $depth * 3);
		
		$cat_name = apply_filters('list_cats', $category->cat_name, $category);
		$output .= "\t<option value=\"".$category->cat_ID."\"";
		if ( $category->cat_ID == $args['selected'] )
			$output .= ' selected="selected"';
		$output .= '>';
		$output .= $cat_name;
		if ( $args['show_count'] )
			$output .= '&nbsp;&nbsp;('. $category->category_count .')';
		if ( $args['show_last_update'] ) {
			$format = 'Y-m-d';
			$output .= '&nbsp;&nbsp;' . gmdate($format, $category->last_update_timestamp);
		}
		$output .= "</option>\n";
		
		return $output;
	}
}

?>
