<?php
/**
 * @file
 * Provide region-based display
 *
 * The Landportal landbook
 *
 * Original work by: WESO (http://www.weso.es)
 * Drupal refactoring: Jules <jules@ker.bz>
 */

// Spits out regions data from landbook db
function landbook_regions($node=NULL) {
  global $databases, $language;
  $r = new Regions();
  $rdata = $r->get(array('language'=>$language->language));
  //dpm($rdata);
  return theme("regions", array(
    'meta'      => "lp:regions",
    'data'      => $rdata,
               ));
}

class Regions {
  private $c;
  private $cached;

  function __construct() {
    $this->c = new Memcached();
    $this->c->addServer('localhost', '11211');
    $this->cached = $this->c->get('landbook-regions');
  }

  public function get($options) {
    if ($this->cached[$options['language']]) {
      //drupal_set_message('landbook got cache');
      return $this->cached[$options['language']];
    }

    $database = new DataBaseHelper();
    $database->open();
    $regions = $database->query("continents", array($options['language']));
    $datasources = $database->query("datasources", array($options['language']));
    $countries = $database->query("countries_without_region", array($options['language']));
    $database->close();
    $result = $this->compose_data($regions, $datasources, $countries);

    $this->cached[$options['language']] = $regions;
    $this->c->set('landbook-regions', $this->cached);
    // dpm($this->cached);
    return $result;
  }

	private function compose_data($regions, $datasources, $countries) {
		$res_regions = $this->compose_regions($regions);
		$res_datasources = $this->compose_datasources($datasources);
		$res_countries = $this->compose_countries($countries);
		$result = array();
		$result["selectors"] = array(
			"regions" => $res_regions,
			"data-sources" => $res_datasources,
			"countries" => $res_countries
		);
		return $result;
	}

	private function compose_regions($regions) {
		$result = array();
		for ($i = 0; $i < count($regions); $i++) {
			$region = array(
				"un_code" => $regions[$i]["continent_un_code"],
				"id" => $regions[$i]["continent_id"],
				"name" => utf8_encode($regions[$i]["continent_name"])
			);
			array_push($result, $region);
		}
		$global_reg = array(
			"un_code" => 1,
			"name" => "Global"
		);
		array_push($result, $global_reg);
		return $result;
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
					"indicators" => array()
				);
			}
			$indicator = array(
				"id" => $data[$i]["ind_id"],
				"preferable_tendency" => $data[$i]["preferable_tendency"],
				"last_update" => $data[$i]["last_update"],
				"starred" => $data[$i]["starred"],
				"name" => utf8_encode($data[$i]["ind_name"]),
				"description" => utf8_encode($data[$i]["ind_description"])
			);
			array_push($result[$datasource_id]["indicators"], $indicator);
		}
		return array_values($result);
	}

	private function compose_countries($data) {
		$countries = array();
		for ($i = 0; $i < count($data); $i++) {
			$country = array(
				"id" => $data[$i]["id"],
				"faoURI" => utf8_encode($data[$i]["faoURI"]),
				"iso3" => $data[$i]["iso3"],
				"iso2" => $data[$i]["iso2"],
				"name" => utf8_encode($data[$i]["country_name"]),
			);
			array_push($countries, $country);
		}
		return $countries;
	}
}