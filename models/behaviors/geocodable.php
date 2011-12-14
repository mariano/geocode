<?php
App::import('Core', array('HttpSocket', 'Security'));

class GeocodableBehavior extends ModelBehavior {
	/**
	 * Allows to use custom finds in behavior
	 *
	 * @var array
	 */
	public $mapMethods = array('/\b_findNear\b/' => '_findNear');

	/**
	 * Behavior settings
	 *
	 * @var array
	 */
	public $settings;

	/**
	 * Default settings
	 *
	 * @var array
	 */
	public $default = array(
		'service' => 'google',
		'key' => null,
		'fields' => array(
			'hash' => false,
			'address',
			'latitude',
			'longitude',
			'address1',
			'address2',
			'city',
			'state',
			'zip',
			'country'
		),
		'addressFields' => array(
			'address1' => array('addr', 'address_1'),
			'address2' => array('addr2', 'address_2'),
			'city',
			'state',
			'zip' => array('zipcode', 'zip_code', 'postal_code'),
			'country'
		),
		'models' => array()
	);

	/**
	 * Service information
	 *
	 * @var array
	 */
	protected $services = array(
		'google' => array(
			'url' => 'http://maps.google.com/maps/geo?q=${address}&output=csv&key=${key}',
			'format' => '${address1} ${address2}, ${city}, ${zip} ${state}, ${country}',
			'pattern' => '/200,[^,]+,([^,]+),([^,\s]+)/',
			'matches' => array(
				'latitude' => 1,
				'longitude' => 2
			)
		),
		'yahoo' => array(
			'url' => 'http://api.local.yahoo.com/MapsService/V1/geocode?appid=${key}&location=${address}',
			'format' => '${address1} ${address2}, ${city}, ${zip} ${state}, ${country}',
			'pattern' => '/<Latitude>(.*?)<\/Latitude><Longitude>(.*?)<\/Longitude>/U',
			'matches' => array(
				'latitude' => 1,
				'longitude' => 2
			)
		)
	);

	/**
	 * HttpSocket instance
	 *
	 * @var object
	 */
	protected $socket;

	/**
	 * Units relative to 1 kilometer.
	 * k: kilometers, m: miles, f: feet, i: inches, n: nautical miles
	 *
	 * @var array
	 */
	protected $units = array('k' => 1, 'm' => 0.621371192, 'f' => 3280.8399, 'i' => 39370.0787, 'n' => 0.539956803);

	/**
	 * Setup behavior
	 *
	 * @param object $model Model
	 * @param array $settings Settings
	 */
	public function setup($model, $settings = array()) {
		$model->_findMethods['near'] = true;

		if (!isset($this->settings[$model->alias])) {
			$configured = Configure::read('Geocode');
			if (!empty($configured)) {
				foreach($this->default as $key => $value) {
					if (isset($configured[$key])) {
						$this->default[$key] = $configured[$key];
					}
				}
			}
			$this->settings[$model->alias] = $this->default;
		}

		if (!empty($settings['models'])) {
			foreach($settings['models'] as $field => $data) {
				unset($settings['models'][$field]);
				if (is_numeric($field) && !is_array($data)) {
					$field = $data;
					$data = array('model' => Inflector::classify($field));
				} else if (is_numeric($field) && !empty($data['field'])) {
					$field = $data['field'];
				}

				if (!is_array($data)) {
					$data = array('model' => $data);
				}

				if (empty($data['model'])) {
					continue;
				}

				if (empty($data['referenceField'])) {
					$modelName = $data['model'];
					if (strpos($data['model'], '.') !== false) {
						list($modelName, $childModelName) = explode('.', $data['model']);
					}

					$data['referenceField'] = Inflector::underscore($modelName) . '_id';
				}

				$settings['models'][$field] = array_merge(array('field' => 'name'), $data);
			}
		}

		$settings = Set::merge($this->settings[$model->alias], $settings);

		if (empty($this->services[strtolower($settings['service'])])) {
			trigger_error(sprintf(__('Geocode service %s not implemented', true), $settings['service']), E_USER_WARNING);
			return false;
		}

		if (!isset($this->socket)) {
			$this->socket = new HttpSocket();
		}

		foreach(array('fields', 'addressFields') as $parameter) {
			$fields = array();
			foreach($settings[$parameter] as $i => $field) {
				$fields[is_numeric($i) ? $field : $i] = ($parameter != 'fields' || $model->hasField($field) ? $field : false);
			}
			$settings[$parameter] = $fields;
		}

		$this->settings[$model->alias] = $settings;
	}

	/**
	 * Before save callback
	 *
	 * @param object $model Model using this behavior
	 * @return bool true if the operation should continue, false if it should abort
	 */
	public function beforeSave($model) {
		$settings = $this->settings[$model->alias];
		$latitudeField = $settings['fields']['latitude'];
		$longitudeField = $settings['fields']['longitude'];

		if (
			!empty($latitudeField) && !empty($longitudeField) &&
			!isset($model->data[$model->alias][$latitudeField]) && !isset($model->data[$model->alias][$longitudeField])
		) {
			$data = $model->data[$model->alias];
			if (!empty($settings['models'])) {
				$data = $this->_data($settings['models'], $data);
			}
			$geocode = $this->geocode($model, $data, false);
			if (!empty($geocode)) {
				$address = array();
				list($address[$latitudeField], $address[$longitudeField]) = $geocode;
				if (!empty($settings['fields']['address'])) {
					$address[$settings['fields']['address']] = $this->_address($settings, $data);
				}

				$model->data[$model->alias] = array_merge(
					$model->data[$model->alias],
					$address
				);

				$this->_addToWhitelist($model, array_keys($address));
			}
		}

		return parent::beforeSave($model);
	}

	/**
	 * Calculate geocode for given address, getting it from DB if already calculated
	 *
	 * @param object $model
	 * @param mixed $address Array with address info (address, city, etc.) or full address as string
	 * @param bool $save Set to true to save result in model, false otherwise
	 * @return mixed Array (latitude, longitude), or false if error
	 */
	public function geocode($model, $address, $save = true) {
		$settings = $this->settings[$model->alias];
		$fullAddress = $this->_address($settings, $address);
		if (empty($fullAddress)) {
			return false;
		}

		$data = array($model->alias => array());
		$conditions = array();

		if (!empty($settings['fields']['hash'])) {
			$hash = Security::hash($fullAddress);
			$conditions[$model->alias . '.' . $settings['fields']['hash']] = $hash;
			$data[$model->alias][$settings['fields']['hash']] = $hash;
		} else if (!empty($settings['fields']['address'])) {
			$conditions[$model->alias . '.' . $settings['fields']['address']] = $fullAddress;
		}

		if (!empty($settings['fields']['address'])) {
			$data[$model->alias][$settings['fields']['address']] = $fullAddress;
		}

		if (is_array($address)) {
			foreach(array_intersect_key($address, $settings['fields']) as $field => $value) {
				if (empty($settings['fields']['hash']) && empty($settings['fields']['address'])) {
					$conditions[$model->alias . '.' . $field] = $value;
				}
				$data[$model->alias][$field] = $value;
			}
		}

		if (empty($settings['fields']['latitude']) || empty($settings['fields']['longitude'])) {
			$conditions = null;
			$data = null;
		}

		$coordinates = false;
		if (!empty($conditions)) {
			$coordinates = $model->find('first', array(
				'conditions' => $conditions,
				'recursive' => -1,
				'fields' => array($settings['fields']['latitude'], $settings['fields']['longitude'])
			));
			if (!empty($coordinates)) {
				$coordinates = array(
					$coordinates[$model->alias][$settings['fields']['latitude']],
					$coordinates[$model->alias][$settings['fields']['longitude']],
				);
			}
		}

		if (empty($coordinates) && empty($settings['key'])) {
			trigger_error(__('Address not found in model and no API key was provided', true), E_USER_WARNING);
			return false;
		}

		if (empty($coordinates)) {
			$coordinates = $this->_fetchCoordinates($settings, $fullAddress);
		}

		if (!empty($coordinates)) {
			foreach($coordinates as $i => $coordinate) {
				$coordinates[$i] = floatval($coordinate);
			}
		}

		if ($save && !empty($coordinates) && !empty($data)) {
			$data[$model->alias][$settings['fields']['latitude']] = $coordinates[0];
			$data[$model->alias][$settings['fields']['longitude']] = $coordinates[1];

			if (!empty($data[$model->alias][$settings['fields']['state']])) {
				$model->create();
				$model->save($data);
			}
		}

		return $coordinates;
	}

	/**
	 * Find points near given point for already saved records
	 *
	 * @param object $model Model
	 * @param string $type Find type (first / all / etc.)
	 * @param mixed $origin A point (latitude, longitude), a full address string, or an array of address data
	 * @param float $distance Set to a maximum distance if you wish to limit results
	 * @param string $unit Unit (k: kilometers, m: miles, f: feet, i: inches, n: nautical miles)
	 * @param array $query Query settings (as given to normal find operations) to override
	 * @return mixed Results
	 */
	public function near($model, $type, $origin, $distance = null, $unit = 'k', $query = array()) {
		$query['origin'] = $origin;
		$query['distance'] = $distance;
		$query['unit'] = $unit;
		if ($type == 'count') {
			$query['operation'] = 'count';
		}
		$results = $model->find('near', $query);
			if(empty ($query['order'])) {
			//	usort($results, self::orderByDistance);
			}
		if ($type == 'first') {
			if (!empty($results[0])) {
				$result = $results[0];
			} else {
				$result = false;
			}
		} else {
			$result = $results;
		}

		return $result;
	}

	/**
	 * Calculate distance (in given unit) between two given points, each of them
	 * expressed as latitude, longitude. Uses the haversine formula.
	 *
	 * @param object $model Model
	 * @param mixed $origin Starting point (latitude, longitude), expressed in numeric degrees, a full address string, or array with address data
	 * @param mixed $destination Ending point (latitude, longitude), expressed in numeric degrees, a full address string, or array with address data
	 * @param string $unit Unit (k: kilometers, m: miles, f: feet, i: inches, n: nautical miles)
	 * @return float Distance expressed in given unit
	 */
	public function distance($model, $origin, $destination, $unit = 'k') {
		$unit = (!empty($unit) && array_key_exists(strtolower($unit), $this->units) ? $unit : 'k');
		$point1 = null;
		$point2 = null;

		foreach(array('point1'=>'origin', 'point2'=>'destination') as $var => $parameter) {
			$data = $$parameter;
			if (is_array($data) && count($data) == 2 && isset($data[0]) && isset($data[1]) && is_numeric($data[0]) && is_numeric($data[1])) {
				$$var = $data;
			} else {
				$$var = $this->geocode($model, $data);
			}
		}

		if (empty($point1) || empty($point2)) {
			return false;
		}

		$line = array(
			deg2rad($point2[0] - $point1[0]),
			deg2rad($point2[1] - $point1[1])
		);
		$angle = sin($line[0]/2) * sin($line[0]/2) + sin($line[1]/2) * sin($line[1]/2) * cos(deg2rad($point1[0])) * cos(deg2rad($point2[0]));
		$earthRadiusKm = 6378;
		return ($earthRadiusKm * 2 * atan2(sqrt($angle), sqrt(1 - $angle))) * $this->units[strtolower($unit)];
	}

	/**
	 * Give back needed condition / ordering clause to find points near given point
	 *
	 * @param object $model Model
	 * @param array $point Point (latitude, longitude), expressed in numeric degrees
	 * @param float $distance If specified, add condition to only match points within given distance
	 * @param string $unit Unit (k: kilometers, m: miles, f: feet, i: inches, n: nautical miles)
	 * @param string $direction Sorting direction (ASC / DESC)
	 * @return array Query parameters (conditions, order)
	 */
	public function distanceQuery($model, $point, $distance = null, $unit = 'k', $direction = 'ASC') {
		$unit = (!empty($unit) && array_key_exists(strtolower($unit), $this->units) ? $unit : 'k');
		$settings = $this->settings[$model->alias];
		foreach($point as $k => $v) {
			$point[$k] = floatval($v);
		}
		list($latitude, $longitude) = $point;
		list($latitudeField, $longitudeField) = array(
			$model->escapeField($settings['fields']['latitude']),
			$model->escapeField($settings['fields']['longitude']),
		);

		 $expression = 'SQRT(
			 POW((COS(RADIANS(' . $latitude . ')) * COS(RADIANS(' . $longitude . '))
			 - COS(RADIANS(' . $latitudeField . ')) * COS(RADIANS(' . $longitudeField . '))), 2) +
			 POW((COS(RADIANS(' . $latitude . ')) * SIN(RADIANS(' . $longitude . '))
			 - COS(RADIANS(' . $latitudeField . ')) * SIN(RADIANS(' . $longitudeField . '))), 2) +
			 POW((SIN(RADIANS(' . $latitude . '))
			 - SIN(RADIANS(' . $latitudeField . '))), 2)
		 )';

		$expression = str_replace("\n", ' ', $expression);
		$query = array(
			'order' => $expression . ' ' . $direction,
			'conditions' => array(
				'ROUND(' . $latitudeField . ', 4) !=' => round($latitude, 4),
				'ROUND(' . $longitudeField . ', 4) !=' => round($longitude, 4)
			)
		);

		if (!empty($distance)) {
			$earthRadiusKm = 6378;
			$ratio = $earthRadiusKm * $this->units[strtolower($unit)];
			$query['conditions'][] = '(' . $expression . ' * ' . $ratio . ') <= ' . $distance;
		}

		return $query;
	}

	/**
	 * Navigate result rows and calculate distance
	 *
	 * @param object $model Model
	 * @param array $result Result rows
	 * @param array $point Point (latitude, longitude), expressed in numeric degrees
	 * @param string $unit Unit (k: kilometers, m: miles, f: feet, i: inches, n: nautical miles)
	 * $param string $alias Location model alias
	 * @param string $latitudeField Name of latitude field
	 * @param string $longitudeField Name of longitude field
	 * @return array Modified results
	 */
	protected function _loadDistance($model, $result, $point, $unit, $alias, $latitudeField, $longitudeField) {
		if (!is_array($result)) {
			return $result;
		} else if (!empty($result[$alias])) {
			$result[$alias]['distance'] = $this->distance($model,
				array($result[$alias][$latitudeField], $result[$alias][$longitudeField]),
				$point,
				$unit
			);
		} else {
			foreach($result as $i => $row) {
				$result[$i] = $this->_loadDistance($model, $row, $point, $unit, $alias, $latitudeField, $longitudeField);
			}
		}

		return $result;
	}

	/**
	 * Query a service to get coordinates for given address
	 *
	 * @param array $settings Settings
	 * @param string $address Full address
	 * @return array Coordinates (latitude, longitude), expressed in numeric degrees
	 */
	protected function _fetchCoordinates($settings, $address) {
		$vars = array(
			'${key}' => $settings['key'],
			'${address}' => $address
		);
		$service = $this->services[$settings['service']];

		foreach($vars as $var => $value) {
			$vars[$var] = urlencode($value);
		}

		$url = str_replace(array_keys($vars), $vars, $service['url']);
		$result = $this->socket->get($url);

		if (empty($result) || !preg_match($service['pattern'], $result, $matches)) {
			return false;
		}

		$coordinates = array(
			$matches[$service['matches']['latitude']],
			$matches[$service['matches']['longitude']]
		);

		return $coordinates;
	}

	/**
	 * Build full address from given address
	 *
	 * @param array $settings Settings
	 * @param mixed $address If array, will look for normal address parameters (address, city, etc.)
	 * @return string Full address
	 */
	protected function _address($settings, $address) {
		if (is_array($address)) {
			$elements = array();
			foreach($settings['addressFields'] as $type => $fields) {
				$fields = array_merge(array($type => $type), (array) $fields);
				$elements['${' . $type . '}'] = str_replace(',', ' ', trim(current(array_intersect_key($address, array_flip($fields)))));
			}
			$nonEmpty = array_filter($elements);
			if (empty($nonEmpty)) {
				return null;
			}

			$address = trim(str_replace(array_keys($elements), $elements, $this->services[$settings['service']]['format']));
			$replacements = array(
				'/(\s)\s+/' => '\\1',
				'/\s+,(.+)/' => ',\\1',
				'/\s*,\s*,/' => ',',
				'/,\s*$/' => ''

			);
			foreach($replacements as $pattern => $replacement) {
				$address = preg_replace($pattern, $replacement, $address);
			}
			$address = preg_replace('/,\s*$/', '', $address);
		}

		return $address;
	}

	/**
	 * Adds missing address information based on specified settings.
	 * E.g: 'city' => array('model' => 'City', 'referenceField' => 'city_id', 'field' => 'name')
	 *
	 * @param array $models How to get missing info
	 * @param array $data Current model data
	 * @return array Modified model data
	 */
	protected function _data($models, $data) {
		foreach($models as $field => $model) {
			if (!empty($data[$field])) {
				continue;
			}

			$modelName = $model['model'];
			$childModelName = null;
			if (strpos($model['model'], '.') !== false) {
				list($modelName, $childModelName) = explode('.', $model['model']);
			}
			$varName = 'Model' . $modelName;
			if (empty($$varName)) {
				$$varName = ClassRegistry::init($modelName);
				if (empty($$varName)) {
					continue;
				}
			}

			if (empty($data[$model['referenceField']])) {
				continue;
			}

			$record = $$varName->find('first', array(
				'conditions' => array($$varName->alias . '.' . $$varName->primaryKey => $data[$model['referenceField']]),
				'contain' => !empty($childModelName) ? preg_replace('/^[^\.]+\.(.+)$/', '\\1', $model['model']) : false
			));
			if (empty($record)) {
				continue;
			}

			$data[$field] = $record[!empty($childModelName) ? $childModelName : $modelName][$model['field']];
		}

		return $data;
	}

/**
 * Custom find method to find the most recent job offers. By default 5 results are returned
 *
 * @param array $models Model instance
 * @method string $method The method call
 * @param string $state Either "before" or "after"
 * @param array $query Find parameters with keys "origin", "distance" (default: null) and "unit" (default: "k").
 * @param array $results
 * @return array
 * @see Model::find()
 */
	protected function _findNear($model, $method, $state, $query, $results = array()) {

		if ($state == 'before') {
			$query = $this->__prepareQuery($model, $query);
			if (isset($query['operation']) && $query['operation'] == 'count') {
				$query = $model->_findCount($state, $query, $results);
			}
			return $query;
		} elseif ($state == 'after') {

			if (empty($query['point'])) {
				return false;
			} else {
				if (isset($query['operation']) && $query['operation'] == 'count' && !empty($query['order'])) {
					unset($query['order']);
				}
			}

			if (isset($query['operation']) && $query['operation'] == 'count') {
				$results = $model->_findCount($state, $query, $results);
			} else {
				$results = $this->_loadDistance($model, $results, $query['point'], $query['unit'],
					$model->alias, $query['latitudeField'], $query['longitudeField']);
			}
			return $results;
		}
	}

/**
 * Add complementary information to the query
 *
 * @param array $query The query before updating
 * @return array The query updated
 * @see GeocodableBehavior::_findNear() for $query keys
 */
	private function __prepareQuery($model, $query) {
		$_defaults = array(
			'distance' => null,
			'unit' => 'k',
		);
		if(!empty($query['origin'])) {
			$origin = $query['origin'];
		} elseif (!empty($query['address'])) {
			$origin = $query['address'];
		} else {
			$origin = array((float) '0', (float) '0');
			$query['conditions'] = array('1=0');
		}
		$query = array_merge($_defaults, $query);

		$settings = $this->settings[$model->alias];
		list($query['latitudeField'], $query['longitudeField']) = array(
			$settings['fields']['latitude'],
			$settings['fields']['longitude'],
		);

		if (!empty($query['fields']) && is_array($query['fields'])) {
			$query['fields'] = array_merge($query['fields'], array(
				$query['latitudeField'],
				$query['longitudeField']
			));
		}

		$query['point'] = null;
		if (is_array($origin) && count($origin) == 2 && isset($origin[0]) && isset($origin[1]) && is_numeric($origin[0]) && is_numeric($origin[1])) {
			$query['point'] = $origin;
		} else {
			$query['point'] = $this->geocode($model, $origin);
		}
		if (empty($query['order'])) {
			unset($query['order']);
		}
		if (empty($query['conditions'])) {
			unset($query['conditions']);
		}

		$query = Set::merge(
			$this->distanceQuery($model, $query['point'], $query['distance'], $query['unit'], !empty($query['direction']) ? $query['direction'] : 'ASC'),
			array_diff_key($query, array('direction'=>true))
		);

		return $query;
	}
}