<?php
App::import('Behavior', 'Geocode.Geocodable');
App::import('Model', 'Geocode.GeoAddress');
App::import('Controller', 'Controller');

class TestGeocodableBehavior extends GeocodableBehavior {
	public function run($method) {
		$args = array_slice(func_get_args(), 1);
		return call_user_method_array($method, $this, $args);
	}
}

class TestAddress extends GeoAddress {
	public $useDbConfig = 'test_suite';
	public $actsAs = array('Geocode.Geocodable'=>array());
	public $useTable = 'geo_addresses';
}

class City extends AppModel {
	public $useDbConfig = 'test_suite';
	public $belongsTo = array('State');
}

class State extends AppModel {
	public $useDbConfig = 'test_suite';
	public $belongsTo = array('Country');
	public $hasMany = array('City');
}

class Country extends AppModel {
	public $useDbConfig = 'test_suite';
}

class TestExtendedAddress extends GeoAddress {
	public $useDbConfig = 'test_suite';
	public $belongsTo = array('City', 'State');
	public $actsAs = array('Geocode.Geocodable'=>array(
		'models' => array(
			'city' => 'City',
			'state' => 'State',
			'country' => 'State.Country'
		)
	));
	public $useTable = 'addresses';
}

class GeocodableBehaviorTest extends CakeTestCase {
	public $fixtures = array(
		'plugin.geocode.geo_address', 'plugin.geocode.address', 'plugin.geocode.city', 'plugin.geocode.state', 'plugin.geocode.country'

	);

	public function startTest($method) {
		$this->Address = new TestAddress();
		$this->Address->Behaviors->attach('Geocode.Geocodable', $this->Address->actsAs['Geocode.Geocodable']);

		$this->ExtendedAddress = new TestExtendedAddress();
		$this->ExtendedAddress->Behaviors->attach('Geocode.Geocodable', $this->Address->actsAs['Geocode.Geocodable']);

		$this->Geocodable = $this->Address->Behaviors->Geocodable;
		$this->TestGeocodable = new TestGeocodableBehavior();
		$this->configuredGeocode = Configure::read('Geocode');
	}

	public function endTest($method) {
		unset($this->Geocodable);
		unset($this->Address);
		unset($this->TestGeocodable);
		Configure::delete('Geocode');
		ClassRegistry::flush();
		if (!empty($this->configuredGeocode)) {
			Configure::write('Geocode', $this->configuredGeocode);
		}
	}

	public function testSettings() {
		$default = $this->Geocodable->default;
		$this->assertTrue(!empty($default));

		$this->Address->Behaviors->detach('Geocodable');
		Configure::write('Geocode.service', 'yahoo');
		$this->Address->Behaviors->attach('Geocodable', $this->Address->actsAs['Geocode.Geocodable']);
		$this->assertEqual($this->Geocodable->settings[$this->Address->alias]['service'], 'yahoo');

		$this->Address->Behaviors->detach('Geocodable');
		Configure::write('Geocode.service', 'nonexisting');
		$this->expectError();
		$this->Address->Behaviors->attach('Geocodable', array_diff_key($default, array('service'=>true)));
	}

	public function testAddress() {
		$result = $this->TestGeocodable->run('_address', $this->Geocodable->settings[$this->Address->alias], array(
			'address1' => '1209 La Brad Lane',
			'city' => 'Tampa',
			'state' => 'FL'
		));
		$expected = '1209 La Brad Lane, Tampa, FL';
		$this->assertEqual($result, $expected);

		$result = $this->TestGeocodable->run('_address', $this->Geocodable->settings[$this->Address->alias], array(
			'address1' => '1209 La Brad Lane',
			'address_2' => 'Suite 4',
			'city' => 'Tampa',
		));
		$expected = '1209 La Brad Lane Suite 4, Tampa';
		$this->assertEqual($result, $expected);

		$result = $this->TestGeocodable->run('_address', $this->Geocodable->settings[$this->Address->alias], array(
			'address1' => '1209 La Brad Lane',
			'city' => 'Tampa',
			'state' => 'FL',
			'country' => 'USA'
		));
		$expected = '1209 La Brad Lane, Tampa, FL, USA';
		$this->assertEqual($result, $expected);

		$result = $this->TestGeocodable->run('_address', $this->Geocodable->settings[$this->Address->alias], array(
			'addr' => '1209 La Brad Lane',
			'state' => 'FL',
		));
		$expected = '1209 La Brad Lane, FL';
		$this->assertEqual($result, $expected);

		$result = $this->TestGeocodable->run('_address', $this->Geocodable->settings[$this->Address->alias], array(
			'address1' => '14348 N Rome Ave',
			'city' => 'Tampa',
			'state' => 'Florida',
			'zip' => 33613
		));
		$expected = '14348 N Rome Ave, Tampa, 33613 Florida';
		$this->assertEqual($result, $expected);
	}

	public function testGeocodeNoApiKey() {
		$key = $this->Geocodable->settings[$this->Address->alias]['key'];
		$this->Geocodable->settings[$this->Address->alias]['key'] = null;

		$result = $this->Address->geocode(array(
			'address1' => '1209 La Brad Lane',
			'city' => 'Tampa',
			'state' => 'FL'
		));
		$expected = array(28.0792, -82.4735);
		$this->assertEqual($result, $expected);

		$this->expectError();
		$result = $this->Address->geocode(array(
			'address1' => '1600 Amphitheatre Parkway',
			'city' => 'Mountan View',
			'state' => 'CA',
			'zip' => 94043,
			'country' => 'United States of America'
		));
		$this->assertFalse($result);

		$this->Geocodable->settings[$this->Address->alias]['key'] = $key;
	}

	public function testGeocode() {
		if ($this->skipIf(empty($this->Geocodable->settings[$this->Address->alias]['key']), 'No service API Key provided')) {
			return;
		}

		$address = array(
			'address1' => '1600 Amphitheatre Parkway',
			'city' => 'Mountan View',
			'state' => 'CA',
			'zip' => 94043,
			'country' => 'United States of America'
		);
		$result = $this->Address->find('first', array(
			'conditions' => $address,
			'recursive' => -1,
			'fields' => array_keys($address)
		));
		$this->assertFalse($result);

		$result = $this->Address->geocode($address);
		$this->assertTrue(!empty($result));
		if (!empty($result)) {
			foreach($result as $i => $value) {
				$result[$i] = round($value, 1);
			}
			$expected = array(37.4, -122.1);
			$this->assertEqual($result, $expected);
		}

		$result = $this->Address->find('first', array(
			'conditions' => $address,
			'recursive' => -1,
			'fields' => array_merge(
				array_keys($address),
				array('latitude', 'longitude')
			)
		));
		$this->assertTrue(!empty($result[$this->Address->alias]));
		if (!empty($result)) {
			foreach(array('latitude', 'longitude') as $field) {
				$result[$this->Address->alias][$field] = round($result[$this->Address->alias][$field], 1);
			}
			$expected = array($this->Address->alias => array_merge(
				$address,
				array('latitude' => 37.4, 'longitude' => -122.1)
			));
			$this->assertEqual($result, $expected);
		}
	}

	public function testDistance() {
		$result = ceil($this->Address->distance(
			array(25.7953, -80.2789),
			array(9.9981, -84.2036)
		));
		$expected = 1807;
		$this->assertEqual($result, $expected);

		$result = ceil($this->Address->distance(
			array(25.7953, -80.2789),
			array(9.9981, -84.2036),
			'm'
		));
		$expected = 1123;
		$this->assertEqual($result, $expected);

		$origin = array(
			'address1' => '1209 La Brad Lane',
			'city' => 'Tampa',
			'state' => 'FL'
		);

		$result = round($this->Address->distance($origin, array(
			'address1' => '14348 N Rome Ave',
			'city' => 'Tampa',
			'state' => 'FL',
			'zip' => 33613
		), 'm'), 1);
		$expected = 0.2;
		$this->assertEqual($result, $expected);

		$result = round($this->Address->distance($origin, array(
			'address1' => '1180 Magdalene Hill',
			'state' => 'Florida',
			'country' => 'US'
		), 'm'), 1);
		$expected = 0.3;
		$this->assertEqual($result, $expected);

		$result = round($this->Address->distance($origin, '13216 Forest Hills Dr, Tampa, FL', 'm'), 1);
		$expected = 0.8;
		$this->assertEqual($result, $expected);

		$result = round($this->Address->distance($origin, array(
			'address1' => '9106 El Portal Dr',
			'city' => 'Tampa',
			'state' => 'Florida',
			'country' => 'US'
		), 'm'), 1);
		$expected = 3.3;
		$this->assertEqual($result, $expected);

		if (!$this->skipIf(empty($this->Geocodable->settings[$this->Address->alias]['key']), 'No service API Key provided for test')) {
			$result = round($this->Address->distance($origin, '3700 Rocinante Blvd, Tampa, FL', 'm'), 1);
			$expected = 9;
			$this->assertEqual($result, $expected);
		}
	}

	public function testNear() {
		$result = $this->Address->near('first', array(25.7953, -80.2789));
		$expected = 331;
		$this->assertTrue(!empty($result[$this->Address->alias]));
		$this->assertEqual(ceil($result[$this->Address->alias]['distance']), $expected);

		$result = $this->Address->near('all', array(25.7953, -80.2789));
		$expected = 331;
		$this->assertTrue(!empty($result[0]));
		$this->assertEqual(ceil($result[0][$this->Address->alias]['distance']), $expected);

		$result = $this->Address->near('all', '1209 La Brad Lane, Tampa, FL');
		$this->assertTrue(!empty($result));
		if (!empty($result)) {
			$result = Set::combine($result, '/' . $this->Address->alias . '/address', '/' . $this->Address->alias . '/distance');
			foreach($result as $key => $distance) {
				$result[$key] = round($distance, 3);
			}
			$expected = array(
				'14348 N Rome Ave, Tampa, 33613 FL' => 0.257,
				'1180 Magdalene Hill, Florida, US' => 0.499,
				'9106 El Portal Dr, Tampa, FL' => 5.331
			);
			$this->assertEqual($result, $expected);
		}

		$result = $this->Address->near('all', '1209 La Brad Lane, Tampa, FL', 1);
		$this->assertTrue(!empty($result));
		if (!empty($result)) {
			$result = Set::combine($result, '/' . $this->Address->alias . '/address', '/' . $this->Address->alias . '/distance');
			foreach($result as $key => $distance) {
				$result[$key] = round($distance, 3);
			}
			$expected = array(
				'14348 N Rome Ave, Tampa, 33613 FL' => 0.257,
				'1180 Magdalene Hill, Florida, US' => 0.499
			);
			$this->assertEqual($result, $expected);
		}

		$result = $this->Address->near('count', '1209 La Brad Lane, Tampa, FL', 1);
		$this->assertEqual($result, 2);

		$result = $this->Address->near('all', '1209 La Brad Lane, Tampa, FL', 0.5, 'm');
		$this->assertTrue(!empty($result));
		if (!empty($result)) {
			$result = Set::combine($result, '/' . $this->Address->alias . '/address', '/' . $this->Address->alias . '/distance');
			foreach($result as $key => $distance) {
				$result[$key] = round($distance, 3);
			}
			$expected = array(
				'14348 N Rome Ave, Tampa, 33613 FL' => 0.160,
				'1180 Magdalene Hill, Florida, US' => 0.310
			);
			$this->assertEqual($result, $expected);
		}
	}

	public function testSave() {
		$address = array(
			'address1' => '1600 Amphitheatre Parkway',
			'city' => 'Mountan View',
			'state' => 'CA',
			'zip' => 94043,
			'country' => 'United States of America'
		);
		$result = $this->Address->find('first', array(
			'conditions' => $address,
			'recursive' => -1,
			'fields' => array_keys($address)
		));
		$this->assertFalse($result);

		$this->Address->create();
		$saved = $this->Address->save($address);
		$this->assertTrue($saved !== false);

		$result = $this->Address->find('first', array(
			'conditions' => $address,
			'recursive' => -1,
			'fields' => array_merge(array_keys($address), array('latitude', 'longitude'))
		));
		$this->assertTrue(!empty($result));
		if (!empty($result)) {
			foreach(array('latitude', 'longitude') as $field) {
				$result[$this->Address->alias][$field] = round($result[$this->Address->alias][$field], 1);
			}

			$expected = array_merge($address, array(
				'latitude' => 37.4,
				'longitude' => -122.1
			));

			$this->assertEqual($result[$this->Address->alias], $expected);
		}
	}

	public function testExtendedSave() {
		$address = array(
			'address_1' => '1600 Amphitheatre Parkway',
			'city_id' => '951470f2-e770-102c-aa5d-00138fbbb402',
			'state_id' => '95147110-e770-102c-aa5d-00138fbbb402',
			'zip' => 94043
		);
		$result = $this->ExtendedAddress->find('first', array(
			'conditions' => $address,
			'recursive' => -1,
			'fields' => array_keys($address)
		));
		$this->assertFalse($result);

		$this->ExtendedAddress->create();
		$saved = $this->ExtendedAddress->save($address);
		$this->assertTrue($saved !== false);

		$result = $this->ExtendedAddress->find('first', array(
			'conditions' => $address,
			'recursive' => -1,
			'fields' => array_merge(array_keys($address), array('latitude', 'longitude'))
		));
		$this->assertTrue(!empty($result));
		if (!empty($result)) {
			foreach(array('latitude', 'longitude') as $field) {
				$result[$this->ExtendedAddress->alias][$field] = round($result[$this->ExtendedAddress->alias][$field], 1);
			}

			$expected = array_merge($address, array(
				'latitude' => 37.4,
				'longitude' => -122.1
			));

			$this->assertEqual($result[$this->ExtendedAddress->alias], $expected);
		}

		$this->ExtendedAddress->create();
		$saved = $this->ExtendedAddress->save(array(
			'address_1' => '1600 Amphitheatre Parkway',
			'city' => 'Mountan View',
			'state_id' => '95147110-e770-102c-aa5d-00138fbbb402',
			'zip' => 94043
		));
		$this->assertTrue($saved !== false);

		$result = $this->ExtendedAddress->find('first', array(
			'conditions' => $address,
			'recursive' => -1,
			'fields' => array_merge(array_keys($address), array('latitude', 'longitude'))
		));
		$this->assertTrue(!empty($result));
		if (!empty($result)) {
			foreach(array('latitude', 'longitude') as $field) {
				$result[$this->ExtendedAddress->alias][$field] = round($result[$this->ExtendedAddress->alias][$field], 1);
			}

			$expected = array_merge($address, array(
				'latitude' => 37.4,
				'longitude' => -122.1
			));

			$this->assertEqual($result[$this->ExtendedAddress->alias], $expected);
		}
	}

	public function testFind() {
		$result = $this->Address->find('near', array('address'=>array(25.7953, -80.2789)));
		$expected = 331;
		$this->assertTrue(!empty($result[0]));
		$this->assertEqual(ceil($result[0][$this->Address->alias]['distance']), $expected);

		$result = $this->Address->find('near', array('address'=>'1209 La Brad Lane, Tampa, FL'));
		$this->assertTrue(!empty($result));
		if (!empty($result)) {
			$result = Set::combine($result, '/' . $this->Address->alias . '/address', '/' . $this->Address->alias . '/distance');
			foreach($result as $key => $distance) {
				$result[$key] = round($distance, 3);
			}
			$expected = array(
				'14348 N Rome Ave, Tampa, 33613 FL' => 0.257,
				'1180 Magdalene Hill, Florida, US' => 0.499,
				'9106 El Portal Dr, Tampa, FL' => 5.331
			);
			$this->assertEqual($result, $expected);
		}

		$result = $this->Address->find('near', array('address'=>'1209 La Brad Lane, Tampa, FL', 'distance'=>1));
		$this->assertTrue(!empty($result));
		if (!empty($result)) {
			$result = Set::combine($result, '/' . $this->Address->alias . '/address', '/' . $this->Address->alias . '/distance');
			foreach($result as $key => $distance) {
				$result[$key] = round($distance, 3);
			}
			$expected = array(
				'14348 N Rome Ave, Tampa, 33613 FL' => 0.257,
				'1180 Magdalene Hill, Florida, US' => 0.499
			);
			$this->assertEqual($result, $expected);
		}

		$result = $this->Address->find('count', array('type'=>'near', 'address'=>'1209 La Brad Lane, Tampa, FL', 'distance'=>1));
		$this->assertEqual($result, 2);

		$result = $this->Address->find('near', array('address'=>'1209 La Brad Lane, Tampa, FL', 'distance'=>0.5, 'unit'=>'m'));
		$this->assertTrue(!empty($result));
		if (!empty($result)) {
			$result = Set::combine($result, '/' . $this->Address->alias . '/address', '/' . $this->Address->alias . '/distance');
			foreach($result as $key => $distance) {
				$result[$key] = round($distance, 3);
			}
			$expected = array(
				'14348 N Rome Ave, Tampa, 33613 FL' => 0.160,
				'1180 Magdalene Hill, Florida, US' => 0.310
			);
			$this->assertEqual($result, $expected);
		}
	}

	public function testPaginate() {
		$Controller = new Controller();
		$Controller->uses = array('TestAddress');
		$Controller->params['url'] = array();
		$Controller->constructClasses();

		$Controller->paginate = array('TestAddress' => array(
			'near', 'fields' => array('address'), 'limit' => 2,
			'address' => '1209 La Brad Lane, Tampa, FL'
		));
		$result = $Controller->paginate('TestAddress');
		$this->assertTrue(!empty($result));
		if (!empty($result)) {
			$result = Set::combine($result, '/' . $this->Address->alias . '/address', '/' . $this->Address->alias . '/distance');
			foreach($result as $key => $distance) {
				$result[$key] = round($distance, 3);
			}
			$expected = array(
				'14348 N Rome Ave, Tampa, 33613 FL' => 0.257,
				'1180 Magdalene Hill, Florida, US' => 0.499
			);
			$this->assertEqual($result, $expected);
		}

		$Controller->paginate = array('TestAddress' => array(
			'near', 'fields' => array('address'), 'limit' => 2,
			'address' => '1209 La Brad Lane, Tampa, FL',
			'page' => 2
		));
		$result = $Controller->paginate('TestAddress');
		$this->assertTrue(!empty($result));
		if (!empty($result)) {
			$result = Set::combine($result, '/' . $this->Address->alias . '/address', '/' . $this->Address->alias . '/distance');
			foreach($result as $key => $distance) {
				$result[$key] = round($distance, 3);
			}
			$expected = array(
				'9106 El Portal Dr, Tampa, FL' => 5.331
			);
			$this->assertEqual($result, $expected);
		}

		$Controller->paginate = array('TestAddress' => array(
			'near', 'fields' => array('address'), 'limit' => 2,
			'address' => '1209 La Brad Lane, Tampa, FL', 'unit' => 'm'
		));
		$result = $Controller->paginate('TestAddress');
		$this->assertTrue(!empty($result));
		if (!empty($result)) {
			$result = Set::combine($result, '/' . $this->Address->alias . '/address', '/' . $this->Address->alias . '/distance');
			foreach($result as $key => $distance) {
				$result[$key] = round($distance, 3);
			}
			$expected = array(
				'14348 N Rome Ave, Tampa, 33613 FL' => 0.160,
				'1180 Magdalene Hill, Florida, US' => 0.310
			);
			$this->assertEqual($result, $expected);
		}

		$Controller->paginate = array('TestAddress' => array(
			'near', 'fields' => array('address'), 'limit' => 2,
			'address' => '1209 La Brad Lane, Tampa, FL', 'unit' => 'm', 'distance' => 0.25
		));
		$result = $Controller->paginate('TestAddress');
		$this->assertTrue(!empty($result));
		if (!empty($result)) {
			$result = Set::combine($result, '/' . $this->Address->alias . '/address', '/' . $this->Address->alias . '/distance');
			foreach($result as $key => $distance) {
				$result[$key] = round($distance, 3);
			}
			$expected = array(
				'14348 N Rome Ave, Tampa, 33613 FL' => 0.160
			);
			$this->assertEqual($result, $expected);
		}
	}
}
?>
