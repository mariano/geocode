<?php
class GeoAddress extends AppModel {
	/**
	 * Behaviors
	 *
	 * @var array
	 */
	public $actsAs = array('Geocode.Geocodable');
}
?>