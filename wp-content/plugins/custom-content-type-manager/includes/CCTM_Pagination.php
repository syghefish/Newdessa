<?php
/*-------------------------------------------------------------------------------
Pagination Library: Tokenized formatting to create pagination links.

USAGE:

There are 2 ways to identify page numbers during pagination. The most obvious one
is that we number each page: 1,2,3.  This corresponds to pagination links
like mypage.php?page=3 for example.

		include('Pagination.php');
		$p = new Pagination();
		$offset = $p->page_to_offset($_GET['page'], $_GET['rpp']);
		$p->set_offset($offset); //
		$p->set_results_per_page($_GET['rpp']);  // You can optionally expose this to the user.
		$p->set_extra('target="_self"'); // optional
		print $p->paginate(100); // 100 is the count of records

The other way to identify page numbers is via an offset of the records. This is
a bit less intuitive, but it is more flexible if you ever want to let the user
change the # of results shown per page. Imagine if someone bookmarked a URL
with ?page=3 on it, and then adjusted the # of records per page from 10 to 100.
The page would contain an entirely different set of records, whereas with the offset
method, e.g. ?offset=30, the page would at least start with the same records no matter
if the # of records per page changed.



See the CCTM_Pagination.conf.php file for more details and customization options.

Private functions reference internal publics; public functions do not.

*/

class CCTM_Pagination {
	public $Config;
	// Contains all placeholders passed to the outerTpl
	public $properties = array();

	// Formatting template chunks, normally set via set_tpls()
	public $firstTpl;
	public $lastTpl;
	public $prevTpl;
	public $nextTpl;
	public $currentPageTpl;
	public $pageTpl;
	public $outerTpl;

	//------------------------------------------------------------------------------
	/**
	 * When does this get called????
	 *
	 * @param unknown $tpl_set
	 * @param unknown $args    (optional)
	 * @return unknown
	 */
	function __call($tpl_set, $args='') {
		$this->set_tpls($tpl_set);
		return $this->paginate($args[0]);
	}

	/**
	 *
	 */
	function __construct() {

		$this->Config = new CCTM_Pagination_Configuration();

		$this->set_tpls();

		$this->set_base_url();
		$this->set_extra();
		$this->set_offset();
		$this->set_results_per_page();

	}

	/**
	 * Dynamic getter. Note that there is a PHP "feature" (not a bug) that prohibits
	 * $this->$x['y']; usage inside of magic functions. You must use a hard-coded value
	 * instead of a variable ('properties' instead of $x our case)
	 * 
	 * @param string $name
	 * @return mixed
	 */
	public function __get($name) {
		return $this->properties[$name];
	}
	
	/**
	 * Dynamic getter. Note that there is a PHP bug that prohibits $this->$x['y']; access.
	 *
	 * @param string $name
	 * @param mixed $val -- ought to always be a string.
	 */
	public function __set($name, $val) {
		$this->properties[$name] = $val;
	}


	/**
	 *
	 *
	 * @return string
	 */
	private function _parse_firstTpl() {
		if ($this->offset > 0) {
			return $this->parse($this->firstTpl, array('offset'=> '0', 'page_number'=> '1' ));
		} else {
			return '';
		}
	}


	/**
	 *
	 *
	 * @return string
	 */
	private function _parse_lastTpl() {
		$page_number = $this->page_count;
		$offset = $this->page_to_offset($page_number, $this->results_per_page);
		if ($this->current_page < $this->page_count) {
			return $this->parse($this->lastTpl, array('offset'=> $offset, 'page_number'=> $page_number));
		} else {
			return '';
		}
	}

	/**
	 *
	 *
	 * @return string
	 */
	private function _parse_pagination_links() {
		$output = '';
		for ( $page = $this->lowest_visible_page; $page <= $this->highest_visible_page; $page++ ) {
			$offset = $this->page_to_offset( $page, $this->results_per_page);

			if ( $page == $this->current_page ) {
				$output .= $this->parse
				(
					$this->currentPageTpl
					, array('offset'=> $offset, 'page_number'=> $page)
				);
			} else {
				$output .= $this->parse
				(
					$this->pageTpl
					, array('offset'=> $offset, 'page_number'=> $page)
				);
			}
		}
		return $output;
	}


	/**
	 *
	 *
	 * @return string
	 */
	private function _parse_nextTpl() {
		$page_number = $this->get_next_page( $this->current_page, $this->page_count );
		$offset = $this->page_to_offset( $page_number, $this->results_per_page );
		if ( $this->current_page < $this->page_count ) {
			return $this->parse($this->nextTpl, array('offset'=> $offset, 'page_number'=> $page_number));
		} else {
			return '';
		}
	}

	/**
	 *
	 *
	 * @return string
	 */
	private function _parse_prevTpl() {
		$page_number = $this->get_prev_page( $this->current_page, $this->page_count );
		$offset = $this->page_to_offset( $page_number, $this->results_per_page );
		if ($this->offset > 0) {
			return $this->parse( $this->prevTpl, array('offset'=> $offset, 'page_number'=> $page_number) );
		}
	}

	/**
	 *
	 *
	 * @param integer $current_pg
	 * @param integer $total_pgs_shown
	 * @param integer $total_pgs
	 * @return integer
	 */
	private function _get_highest_visible_page($current_pg, $total_pgs_shown, $total_pgs) {
		//if ($total_pgs_shown is even)
		$half = floor($total_pgs_shown / 2);

		$high_page = $current_pg + $half;
		$output = '';
		if ($high_page < $total_pgs_shown) {
			$output = $total_pgs_shown;
		} else {
			$output = $high_page;
		}
		if ($output > $total_pgs) {
			$output = $total_pgs;
		}
		return $output;
	}


	//------------------------------------------------------------------------------
	//! PUBLIC FUNCTIONS
	//------------------------------------------------------------------------------
	/**
	 * Calculates the lowest of the visible pages, keeping the current page floating
	 * in the center.
	 *
	 * @param integer $current_pg
	 * @param integer $pgs_visible
	 * @param integer $total_pgs
	 * @return integer
	 */
	public function get_lowest_visible_page($current_pg, $pgs_visible, $total_pgs) {
		//if ($pgs_visible is even, subtract the 1)
		$half = floor($pgs_visible / 2);
		$output = 1;
		$low_page = $current_pg - $half;
		if ($low_page < 1) {
			$output = 1;
		} else {
			$output = $low_page;
		}
		if ( $output > ($total_pgs - $pgs_visible) ) {
			$output = $total_pgs - $pgs_visible + 1;
		}
		if ($output < 1) {
			$output = 1;
		}
		return $output;
	}

	//------------------------------------------------------------------------------
	/**
	 *
	 *
	 * @param unknown $current_pg
	 * @param unknown $total_pgs
	 * @return unknown
	 */
	public function get_next_page($current_pg, $total_pgs) {
		$next_page = $current_pg + $this->Config->next_prev_jump_size;
		if ($next_page > $total_pgs) {
			return $total_pgs;
		} else {
			return $next_page;
		}
	}

	//------------------------------------------------------------------------------
	/**
	 *
	 *
	 * @param integer $current_pg
	 * @param integer $total_pgs
	 * @return integer
	 */
	public function get_prev_page($current_pg, $total_pgs) {
		$prev_page = $current_pg - $this->Config->next_prev_jump_size;
		if ($prev_page < 1) {
			return 1;
		} else {
			return $prev_page;
		}
	}

	//------------------------------------------------------------------------------
	/**
	 * convert an offset number to a page number
	 *
	 * @param integer $offset
	 * @param integer $results_per_page
	 * @return integer
	 */
	public function offset_to_page($offset, $results_per_page) {
		if (is_numeric($results_per_page) && $results_per_page > 0) {
			return (floor($offset / $results_per_page)) + 1;
		} else {
			return 1;
		}
	}

	//------------------------------------------------------------------------------
	/**
	 * Convert page number to an offset
	 *
	 * @param unknown $page
	 * @param unknown $results_per_page
	 * @return unknown
	 */
	public function page_to_offset($page, $results_per_page) {
		if (is_numeric($page) && $page > 1) {
			return ($page - 1) * $results_per_page;
		} else {
			return 0;
		}
	}

	//------------------------------------------------------------------------------
	/**
	 * Standard parsing function to replace [+placeholders+] with value
	 *
	 * @param string $tpl
	 * @param array $record
	 * @return string
	 */
	public function parse($tpl, $record) {

		foreach ($record as $key => $value) {
			$tpl = str_replace('[+'.$key.'+]', $value, $tpl);
		}
		return $tpl;
	}

	//------------------------------------------------------------------------------
	/**
	 * This is the primary interface for the whole library = Get the goods!
	 * INPUT: (int) the # of records you're paginating.
	 * OUTPUT: formatted links
	 *
	 * @param integer $record_count (optional)
	 * @return string	html used for pagination (formatted links)
	 */
	public function paginate($record_count='') {

		$record_count = (int) $record_count;

		// No point in doing pagination if there aren't enough records
		if ( empty($record_count)) {
			return '';
		};

		// Pagination is not necessary if you are on the first page and the record count
		// is less than the results per page.
		if ( ($record_count <= $this->results_per_page) && $this->offset == 0 ) {
			return ' ';
		}

		$this->page_count = ceil($record_count / $this->results_per_page);

		$this->current_page = $this->offset_to_page( $this->offset, $this->results_per_page );

		$this->lowest_visible_page
			= $this->get_lowest_visible_page
		(
			$this->current_page
			, $this->Config->number_of_pagination_links_displayed
			, $this->page_count
		);

		$this->highest_visible_page
			= $this->_get_highest_visible_page
		(
			$this->current_page
			, $this->Config->number_of_pagination_links_displayed
			, $this->page_count
		)
		;

		$this->first_record = $this->offset + 1;

		if ( $this->offset + $this->results_per_page >= $record_count) {
			$this->last_record = $record_count;
		}
		else {
			$this->last_record = $this->offset + $this->results_per_page;
		}

		// We need keys from config
		$this->properties['results_per_page_key'] = $this->Config->limit_key;
		$this->properties['offset_key'] = $this->Config->offset_key;
		$this->properties['record_count'] = $record_count;

		$this->properties['content'] = $this->_parse_firstTpl();
		$this->properties['content'] .= $this->_parse_prevTpl();
		$this->properties['content'] .= $this->_parse_pagination_links();
		$this->properties['content'] .= $this->_parse_nextTpl();
		$this->properties['content'] .= $this->_parse_lastTpl();
		$first_pass = $this->parse($this->outerTpl, $this->properties);
		
		return $this->parse($first_pass, $this->properties);
	}

	//------------------------------------------------------------------------------
	/**
	 * This is the base url used when creating all the links to all the pages.
	 * !!! Please use a clean URL!!! Don't pass this js or other xss crap.
	 * The base_url is intented to be manually set, not open to user input.
	 *
	 * @param string $base_url (optional)
	 */
	public function set_base_url($base_url='') {
		if ( $base_url == '') {
			$base_url = '?';
		}
		elseif ( !preg_match('/\?/', $base_url) ) {
			$base_url = $base_url . '?';
		}

		$this->properties['base_url'] = $base_url;
	}


	//------------------------------------------------------------------------------
	/**
	 * The extra bit is included in the generated anchor tags, e.g.
	 * <a href="[+base_url+]&[+offset_key+]=[+offset+]" [+extra+]>
	 * This is useful for pagination that has to occur on framed pages, e.g.
	 * 		set_extra('target="_self"');
	 *
	 * Or this is the place to put in JavaScript goodies.
	 * This is not intended to be set by users (only by you, the developer)
	 *
	 * @param string $extra (optional)
	 */
	public function set_extra($extra='') {
		$this->properties['extra'] = $extra;
	}

	//------------------------------------------------------------------------------
	/**
	 * Goes thru integer filter; this one IS expected to get its input from users
	 * or from the $_GET array, so using (int) type-casting is a heavy-handed filter.
	 *
	 * @param unknown $offset (optional)
	 */
	public function set_offset($offset='') {
		if ( $offset=='') {
			$this->properties['offset'] = 0;
		}
		elseif ( is_numeric($offset) && $offset >= 0 ) {
			$this->properties['offset'] = (int) $offset;
		}
		else {
			$this->properties['offset'] = 0;
		}
	}

	//------------------------------------------------------------------------------
	/**
	 * Set the number of results to show per page.
	 *
	 * @param integer $results_per_page (optional)
	 */
	public function set_results_per_page($results_per_page='') {
		if ( $results_per_page=='') {
			$this->properties['results_per_page'] = $this->Config->default_results_per_page;
		}
		elseif ( is_numeric($results_per_page) && $results_per_page >= 1 ) {
			$this->properties['results_per_page'] = (int) $results_per_page;
		}
		else {
			$this->properties['results_per_page'] = $this->Config->default_results_per_page;
		}
	}

	//------------------------------------------------------------------------------
	/**
	 *
	 *
	 * @param string $active_group (optional) specifies a group of tpls in the Config class.
	 */
	public function set_tpls($active_group='') {
		if (is_array($active_group)) {
			foreach($active_group as $k => $v) {
				$this->$k = $v;
			}
		}
		else {			
			if ($active_group=='') {
				$active_group = $this->Config->active_group;
			}
	
			$this->firstTpl   = $this->Config->tpls[$active_group]['firstTpl'];
			$this->lastTpl    = $this->Config->tpls[$active_group]['lastTpl'];
			$this->prevTpl    = $this->Config->tpls[$active_group]['prevTpl'];
			$this->nextTpl    = $this->Config->tpls[$active_group]['nextTpl'];
			$this->currentPageTpl  = $this->Config->tpls[$active_group]['currentPageTpl'];
			$this->pageTpl    = $this->Config->tpls[$active_group]['pageTpl'];
			$this->outerTpl   = $this->Config->tpls[$active_group]['outerTpl'];
		}
	}
}

/*EOF*/