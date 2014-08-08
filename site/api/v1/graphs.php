<?php

define('FORCE_NO_RELATIVE', true);		// url_for() references need to be relative to the base path, not the js/ directory that this script is within

/**
 * Do not enable sessions for graph API calls.
 * This has some important implications: it means that we cannot assume that the user is logged in,
 * calls to `user_id()` will fail, etc.
 */
define('NO_SESSION', true);

require(__DIR__ . "/../../../inc/content_type/json.php");		// to allow for appropriate headers etc
require(__DIR__ . "/../../../inc/global.php");
require(__DIR__ . "/../../../inc/cache.php");

function api_v1_graphs($graph) {
	$start_time = microtime(true);

	$result = array();
	$result['success'] = true;

	/**
	 * Graph rendering goes like this:
	 * 0. check graph rendering permissions
	 * 1. get raw graph data (from a {@link GraphRenderer} through {@link construct_graph_renderer()})
	 * 2. apply deltas as necessary
	 * 3. add technicals as necessary
	 * 4. strip dates outside of the requested ?days parameter (e.g. from extra_days)
	 * 5. construct heading and links
	 * 6. construct subheading and revise last_updated
	 * 7. return data
	 * that is, deltas and technicals are done on the server-side; not the client-side.
	 */
	$renderer = construct_graph_renderer($graph['graph_type'], $graph['arg0'], $graph['arg0_resolved']);

	// 0. check graph rendering permissions
	if ($renderer->requiresUser()) {
		if (!isset($graph['user_id']) || !$graph['user_id']) {
			throw new GraphException("No user specified for authenticated graph");
		}
		if (!isset($graph['user_hash']) || !$graph['user_hash']) {
			throw new GraphException("No user hash specified for authenticated graph");
		}

		$user = get_user($graph['user_id']);
		if (!$user) {
			throw new GraphException("No such user found");
		}
		$expected_hash = compute_user_graph_hash($user);
		if ($graph['user_hash'] !== $expected_hash) {
			throw new GraphException("Mismatched user hash");
		}

		if ($renderer->requiresAdmin()) {
			if (!$user['is_admin']) {
				throw new GraphException("Graph requires administrator privileges");
			}
		}

		$renderer->setUser($user['id']);
	}

	if ($renderer->usesDays()) {
		// 0.5 limit 'days' parameter as necessary
		$get_permitted_days = get_permitted_days();
		if (!isset($get_permitted_days[$graph['days']])) {
			// exception TODO get rid of 'days' from get_permitted_days()
			if ($graph['days'] != 366) {
				throw new GraphException("Invalid days '" . $graph['days'] . "' for graph that requires days");
			}
		}
	}

	// 1. get raw graph data
	try {
		$data = $renderer->getData($graph['days']);
		$original_count = count($data['data']);

		$result['type'] = $renderer->getChartType();

		// 2. apply deltas as necessary
		$data['data'] = calculate_graph_deltas($graph, $data['data'], false /* ignore_first_row */);

		// if there is no data, bail out early
		if (count($data['data']) == 0) {
			$result['type'] = 'nodata';
		} else if ($renderer->canHaveTechnicals()) {
			// 3. add technicals as necessary
			// (only if there is at least one point of data, otherwise calculate_technicals() will throw an error)
			$technicals = calculate_technicals($graph, $data['data'], $data['columns'], false /* ignore_first_row */);
			$data['columns'] = $technicals['headings'];
			$data['data'] = $technicals['data'];
		}

		// 4. discard early data
		if ($renderer->usesDays()) {
			$data['data'] = discard_early_data($data['data'], $graph['days']);
			$after_discard_count = count($data['data']);
		}

		$result['columns'] = $data['columns'];
		$result['key'] = $data['key'];
		$result['data'] = $data['data'];

		// clean up columns
		foreach ($result['columns'] as $key => $value) {
			$result['columns'][$key]['technical'] = isset($result['columns'][$key]['technical']) && $result['columns'][$key]['technical'] ? true : false;
			if ($result['columns'][$key]['technical']) {
				if (!isset($result['columns'][$key]['type'])) {
					$result['columns'][$key]['type'] = 'number';
				}
			}
		}
	} catch (NoDataGraphException_AddAccountsAddresses $e) {
		$result['type'] = 'nodata';
		$result['text'] = ct("Either you have not specified any accounts or addresses, or these addresses and accounts have not yet been updated by :site_name.");
		$result['args'] = array(':site_name' => get_site_config('site_name'));
		$result['data'] = array();
		$data['last_updated'] = false;
		$data['add_accounts_addresses'] = true;
	} catch (NoDataGraphException_AddCurrencies $e) {
		$result['type'] = 'nodata';
		$result['text'] = ct("Either you have not enabled this currency, or your summaries for this currency have not yet been updated by :site_name.");
		$result['args'] = array(':site_name' => get_site_config('site_name'));
		$result['data'] = array();
		$data['last_updated'] = false;
		$data['add_more_currencies'] = true;
	}

	// 5. construct heading and links
	$result['heading'] = array(
		'label' => $renderer->getTitle(),
		'args' => $renderer->getTitleArgs(),
		'url' => $renderer->getURL(),
		'title' => $renderer->getLabel(),
	);

	if (isset($data['h1'])) {
		$result['h1'] = $data['h1'];
	}
	if (isset($data['h2'])) {
		$result['h2'] = $data['h2'];
	}
	if (isset($data['no_header'])) {
		$result['noHeader'] = $data['no_header'];
	}

	// 6. construct subheading and revise last_updated\
	if ($result['type'] != 'nodata' && $renderer->hasSubheading()) {
		$suffix = "";
		if ($graph['delta'] == 'percent') {
			$suffix .= '%';
		}
		if ($renderer->getCustomSubheading() !== false) {
			$result['subheading'] = number_format_html($renderer->getCustomSubheading(), 4, $suffix);
		} else {
			if ($result['type'] == 'piechart') {
				// sum up the first row and use that as a total
				if (count($data['data']) != 1) {
					throw new GraphException("Expected one row of data for a piechart, got " . count($data['data']));
				}
				$sum = 0;
				foreach ($data['data'] as $ignored => $row) {
					foreach ($row as $value) {
						$sum += $value;
					}
				}
				$result['subheading'] = number_format_html($sum, 4, $suffix);
			} else {
				$result['subheading'] = format_subheading_values_objects($graph, $data['data'], $data['columns']);
			}
		}
	}

	$result['lastUpdated'] = recent_format_html($data['last_updated']);
	$result['timestamp'] = iso_date();
	$result['classes'] = $renderer->getClasses();
	$result['graph_type'] = $graph['graph_type'];

	if (is_localhost()) {
		$result['_debug'] = $graph;
		if (isset($after_discard_count)) {
			$result['_debug']['data_discarded'] = $original_count - $after_discard_count;
		} else {
			$result['_debug']['data_not_discarded'] = true;
		}
	}

	// make sure that all 'number'-typed data is numeric
	foreach ($result['data'] as $i => $row) {
		foreach ($row as $key => $value) {
			$column = $result['columns'][$key];
			if ($column['type'] == 'number' || $column['type'] == 'percent') {
				$result['data'][$i][$key] = (double) $value;

				if (is_localhost()) {
					$result['_debug']['number_formatted'] = true;
				}
			}
		}
	}

	// make sure that all data rows are numeric arrays and not objects
	// i.e. reindex everything to be numeric arrays, so they aren't output as JSON objects
	foreach ($result['data'] as $i => $row) {
		$new_row = array_values($row);
		foreach ($row as $key => $value) {
			$new_row[$key] = $value;
		}
		$result['data'][$i] = $new_row;
	}

	// format any extra text from the result
	if (isset($data['add_more_currencies'])) {
		$result['extra'] = array(
			'classes' => 'add_accounts',
			'href' => url_for('wizard_currencies'),
			'label' => ct("Add more currencies"),
			'args' => array(),
		);
	}
	if (isset($data['add_accounts_addresses'])) {
		$result['extra'] = array(
			'classes' => 'add_accounts',
			'href' => url_for('wizard_accounts'),
			'label' => ct("Add accounts and addresses"),
			'args' => array(),
		);
	}

	// 7. calculate if the graph data may be out of date
	if ($renderer->requiresUser() && $renderer->getUser()) {
		$user = get_user($renderer->getUser());
		if ($user && $renderer->usesSummaries() && (!$user['has_added_account'] || !$user['is_first_report_sent'] || strtotime($user['last_account_change']) > strtotime($user['last_sum_job']))) {
			$result['outofdate'] = true;
		}
	}

	$end_time = microtime(true);
	$time_diff = ($end_time - $start_time) * 1000;
	$result['time'] = (double) number_format_autoprecision($time_diff, 1, '.', '');

	// 7. return data
	return json_encode($result);
}

require(__DIR__ . "/../../../layout/templates.php");
require(__DIR__ . "/../../../layout/graphs.php");

$graph_type = require_get("graph_type");

// load graph data, which is also used to construct the hash
$config = array(
	'days' => require_get("days", false),
	'delta' => require_get("delta", false),
	'arg0' => require_get('arg0', false),
	'arg0_resolved' => require_get('arg0_resolved', false),
	'user_id' => require_get('user_id', false),
	'user_hash' => require_get('user_hash', false),
	// in this interface, we only support rendering one technical on one graph
	// (although the technicals interface supports multiple)
	'technical_type' => require_get('technical_type', false),
	'technical_period' => require_get('technical_period', false),
);
if (!$config['days']) {
	$config['days'] = 45;	// default
}
$hash = substr(implode(',', $config), 0, 32);

// and then restructure as necessary away from hash
$config['graph_type'] = require_get('graph_type');
$config['hash'] = $hash;
if ($config['technical_type']) {
	$config['technicals'] = array(array('technical_type' => $config['technical_type'], 'technical_period' => $config['technical_period']));
}

$seconds = require_get("no_cache", false) ? 0 : 60;
allow_cache($seconds);		// allow local cache for up to 60 seconds
echo compile_cached('api/rates/' . $graph_type, $hash /* hash */, $seconds /* cached up to seconds */, 'api_v1_graphs', array($config));

performance_metrics_page_end();

/**
 * Helper function to mark strings that need to be translated on the client-side.
 */
function ct($s) {
	// do not do any translation here - we have to do it on the client side!
	return $s;
}