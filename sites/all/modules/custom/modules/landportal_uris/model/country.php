<?php
require_once(dirname(__FILE__) .'/../database/database_helper.php');
require_once(dirname(__FILE__) .'/../cache/cache_helper.php');

/*
$a = new Country();
header('Content-Type: application/json');
echo json_encode($a->get(array(), 'AGO'));
*/

class Country {
	private $spiderIndicators = array('INDOECD1', 'INDIPFRI0', 'INDUNDP0');
	private $trafficLights = array('INDOECD1', 'INDOECD8', 'INDOECD10', 'INDOECD9', 'INDOECD11');
	private $tableIndicators = array('INDWB10', 'INDWB9', 'INDWB6', 'INDWB13', 'INDWB12', 'INDWB14', 'INDWB11');
	private $gaugeIndicators = array("INDFAOSTAT5", "INDFAOSTAT6" , "INDFAOSTAT7");

	public function get($options, $iso3) {
		$lang = $options->language;
		//$lang = "en";
		$api = $options->host;
		//$api = 'http://'. $_SERVER['HTTP_HOST'];

		$cache = new CacheHelper('country', array(
			$iso3,
			$lang,
		));
		//$cached = null;
		$cached = $cache->get();
		if ($cached !== null) {
			return $cached;
		} else {
			$database = new DataBaseHelper();
			$database->open();
			$safe_iso3 = $database->escape($iso3);
			$datasources = $database->query("datasources_by_country", array($lang, $safe_iso3));
			$info = $database->query("country", array($lang, $safe_iso3));

			if (!$info && function_exists("drupal_goto")) {
				drupal_goto("e404");
			}

			$countries = $database->query("countries_without_region", array($lang));
			$indicators_imploded = "'". implode("','", $this->spiderIndicators) ."','".  implode("','", $this->tableIndicators) ."','". implode("','", $this->gaugeIndicators) ."'";
			$chart_indicators = $database->query("indicators_by_ids", array($lang, $indicators_imploded));
			$charts = $database->query("country_chart_indicators", array($lang, $iso3, $indicators_imploded));
			$starred = $database->query("starred_indicators", array($lang));

			$traffic_data = $this->_compose_traffic($database, $this->trafficLights, $safe_iso3, $lang);

			$result = $this->compose_data($datasources, $info, $countries, $charts, $starred, $traffic_data, $chart_indicators);
			$database->close();
			$cache->store($result);
			return $result;
		}
	}


	private function compose_data($datasources, $info, $countries, $charts, $starred, $traffic_data, $chart_indicators) {
		$country_info = $this->compose_info($info);
		$result = array();
		$result["info"] = $country_info;
		$result["selectors"] = array(
			"data-sources" => $this->compose_datasources($datasources),
			"countries" => $this->compose_countries($countries)
		);
		$result["starred"] = $this->compose_starred($starred);
		$result["charts"] = $this->compose_charts($charts, $country_info, $chart_indicators);
		$result['charts']['trafficLights'] = array_values($traffic_data);
		$result["entity-id"] = $result["info"]["iso3"];
		return $result;
	}


	private function compose_starred($data) {
		$indicators = array();
		$topics = array();
		for ($i = 0; $i < count($data); $i++) {
			$topic_id = $data[$i]["topic_id"];
			if (!array_key_exists($topic_id, $topics)) {
				$topics[$topic_id] = array(
					"id" => $topic_id,
					//"name" => utf8_encode($data[$i]["topic_name"]),
					"indicators" => array()
				);
			}
			$indicator = array(
				"preferable_tendency" => $data[$i]["preferable_tendency"],
				"last_update" => $data[$i]["last_update"],
				"topic_id" => $topic_id,
				//"topic_name" => utf8_encode($data[$i]["topic_name"]),
				"starred" => $data[$i]["starred"],
				"description" => utf8_encode($data[$i]["description"]),
				"name" => utf8_encode($data[$i]["name"]),
				"id" => $data[$i]["id"]
			);
			array_push($indicators, $indicator);
			array_push($topics[$topic_id]["indicators"], $indicator);
		}
		return array(
			"topics" => array_values($topics),
			"indicators" => $indicators,
		);
	}


	private function compose_datasources($data) {
		$result = array();
		for ($i = 0; $i < count($data); $i++) {
			$datasource_id = $data[$i]["dat_id"];
			if (!array_key_exists($datasource_id, $result)) {
				$result[$datasource_id] = array(
					"id" => $datasource_id,
					"name" => utf8_encode($data[$i]["dat_name"]),
					"organization_id" => $data[$i]["organization_id"],
					"indicators" => array(),
					"with_data" => false
				);
			}
			$indicator = array(
				"id" => $data[$i]["ind_id"],
				"preferable_tendency" => $data[$i]["preferable_tendency"],
				"last_update" => $data[$i]["last_update"],
				"starred" => $data[$i]["starred"],
				"name" => utf8_encode($data[$i]["ind_name"]),
				"description" => utf8_encode($data[$i]["ind_description"]),
				"with_data" => $data[$i]["data"] > 0
			);
			if ($data[$i]["data"] > 0)
				$result[$datasource_id]["with_data"] = true;
			array_push($result[$datasource_id]["indicators"], $indicator);
		}
		return array_values($result);
	}

	private function compose_info($data) {
		$result = array(
			"id" => $data[0]["id"],
			"name" => utf8_encode($data[0]["country_name"]),
			"faoURI" => utf8_encode($data[0]["faoURI"]),
			"iso2" => $data[0]["iso2"],
			"iso3" => $data[0]["iso3"],
			'taxonomy_id' => $data[0]['taxonomy_id'],
			"region" => array(
				"un_code" => $data[0]["un_code"],
				"id" => $data[0]["region_id"],
				"name" => utf8_encode($data[0]["region_name"])
			)
		);
		return $result;
	}

	private function compose_countries($data) {
		$result = array();
		for ($i = 0; $i < count($data); $i++) {
			$country = array(
				"id" => $data[$i]["id"],
				"name" => utf8_encode($data[$i]["country_name"]),
				"faoURI" => utf8_encode($data[$i]["faoURI"]),
				"iso2" => $data[$i]["iso2"],
				"iso3" => $data[$i]["iso3"],
			);
			array_push($result, $country);
		}
		return $result;
	}

	private function compose_charts($observations, $country_info, $chart_indicators) {
		$spider_obs = $this->_create_spider_graph($country_info["name"], $chart_indicators);
		$table_obs = array(
			"observations"=>array()
		);
		$gauge_obs = array();
		for ($i = 0; $i < count($observations); $i++) {
			$indicator_id = $observations[$i]["ind_id"];
			$country = array(
				"faoURI" => utf8_encode($observations[$i]["faoURI"]),
				"iso2" => $observations[$i]["iso2"],
				"iso3" => $observations[$i]["iso3"],
				"name" => utf8_encode($observations[$i]["country_name"])
			);
			$indicator = array(
				"id" => $observations[$i]["ind_id"],
				"name" => utf8_encode($observations[$i]["ind_name"]),
				"description" => utf8_encode($observations[$i]["ind_description"]),
				"last_update" => $observations[$i]["ind_last_update"],
				"preferable_tendency" => $observations[$i]["ind_preferable_tendency"],
				"starred" => $observations[$i]["ind_starred"]
			);
			$observation = array(
				"country" => $country,
				"indicator" => $indicator,
				"ref_time" => array(
					"value" => $observations[$i]["ref_time_value"]
				),
				"value" => array(
					"value" => number_format((float)$observations[$i]["value"], 2, '.', '')
				)
			);
			if (in_array($indicator_id, $this->spiderIndicators)) {
				$spider_obs[$indicator_id] = $observation;
			} elseif (in_array($indicator_id, $this->tableIndicators)) {
				array_push($table_obs["observations"], $observation);
			} elseif (in_array($indicator_id, $this->gaugeIndicators)) {
				$complement = $observation;
				$complement["value"]["value"] = 100 - $complement["value"]["value"];
				$complement["country"]["name"] = "Other";
				array_push($gauge_obs, array(
					"observations" => array($complement, $observation),
					"index" => count($gauge_obs) + 1,
					"value" => $observation["value"]["value"],
					"indicator" => $indicator["name"]
				));
			}
		}
		return array(
			"spider" => array(
				"observations" => array_values($spider_obs),
			),
			"tableIndicators" => $table_obs,
			"gaugeIndicators" => $gauge_obs,
		);
	}

	/**
	 * The spider graph array has to be created in a different way, because we
	 * always need that the observation number is equal to the indicator number.
	 * The indicators without observation can be left empty except the indicator id,
	 * the country  name and the ref_time.
	 * @return array with the form indicator_id => observation_object
	 */
	private function _create_spider_graph($country_name, $chart_indicators) {
		$indicators = array();
		for ($i = 0; $i < count($chart_indicators); $i++) {
			$indicators[$chart_indicators[$i]["id"]] = $chart_indicators[$i]["name"];
		}
		$spider_graph = array();
		foreach($this->spiderIndicators as $ind_id) {
			$spider_graph[$ind_id] = array(
				"country" => array(
					"faoURI" => "",
					"iso2" => "",
					"iso3" => "",
					"name" => $country_name,
				),
				"indicator" => array(
					"id" => $ind_id,
					"name" => $indicators[$ind_id],
					"description" => "",
					"last_update" => "",
					"preferable_tendency" => "",
					"starred" => "",
				),
				"ref_time" => array(
					"value" => "null",
				),
				"value" => array(
					"value" => "",
				)
			);
		}
		return $spider_graph;
	}


	private function _compose_traffic($database, $trafficLights, $iso3, $lang) {
		$result = array();
		foreach ($trafficLights as $ind_id) {
			$pref_tendency = $database->query('pref_tendency_indicator', array($ind_id));
			$pref_tendency = isset($pref_tendency[0]) ? $pref_tendency[0]['preferable_tendency'] : null;
			$last_obs = $database->query('last_observation_not_null_by_country_and_indicator', array($iso3, $ind_id));
			$last_obs = isset($last_obs[0]) ? $last_obs[0]['value'] : null;
			$average = $database->query('indicator_average', array($ind_id));
			$average = isset($average[0]) ? $average[0]['avg'] : null;
			array_push($result, array(
				'indicator' => $ind = $database->query('indicator_name', array($lang, $ind_id)),
				'value' => $last_obs,
				'average' => $average,
				'light' => $this->_calculate_light($pref_tendency, $last_obs, $average)
			));
		}
		return $result;
	}


	private function _calculate_light($tendency, $value, $average) {
		if ($tendency == null || $value == null || $average == null) {
			return 'none';
		}
		if ($value > 0.5) {
			return "bad";
		} else if ($value == 0.5) {
			return "same";
		} else {
			return "good";
		}
	}

}
