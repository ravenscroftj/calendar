<?php
/**
 * ownCloud - Calendar App
 *
 * @author Georg Ehrke
 * @copyright 2014 Georg Ehrke <oc.list@georgehrke.com>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OCA\Calendar\Backend;

use OC\AppFramework\Http;
use OCP\AppFramework\IAppContainer;
use OCP\Calendar\Backend;
use OCP\Calendar\BackendException;
use OCP\Calendar\ICalendar;
use OCP\Calendar\ICalendarCollection;
use OCP\Calendar\IObject;
use OCP\Calendar\IObjectCollection;
use OCP\Calendar\ISubscription;
use OCP\Calendar\ObjectType;
use OCP\Calendar\Permissions;
use OCP\Calendar\DoesNotExistException;
use OCP\Calendar\CorruptDataException;
use OCA\Calendar\Db\Calendar;
use OCA\Calendar\Db\CalendarCollection;
use OCA\Calendar\Db\Object;
use OCA\Calendar\Db\ObjectCollection;
use OCA\Calendar\Utility\RegexUtility;
use OCA\Calendar\Sabre\VObject\Reader;
use OCA\Calendar\Sabre\VObject\ParseException;
use OCA\Calendar\Sabre\VObject\Component\VCalendar;
use OCA\Calendar\Sabre\VObject\Splitter\ICalendar as ICalendarSplitter;

class WebCal extends Backend {

	/**
	 * instance of subscription businesslayer
	 * @var \OCA\Calendar\BusinessLayer\SubscriptionBusinessLayer
	 */
	private $subscriptions;


	/**
	 * Constructor
	 * @param IAppContainer $app
	 * @param array $parameters
	 */
	public function __construct(IAppContainer $app, array $parameters){
		parent::__construct($app, 'org.ownCloud.webcal');

		$this->subscriptions = $app->query('SubscriptionBusinessLayer');
	}


	/**
	 * returns whether or not a backend can be enabled
	 * @returns boolean
	 */
	public function canBeEnabled() {
		//The webcal backend requires curl
		return is_callable('curl_init');
	}


	/**
	 * get information about supported subscription-types
	 * @return array
	 */
	public function getSubscriptionTypes() {
		return array(
			array(
				'name' => strval(\OC::$server->getL10N('calendar')->t('WebCal')),
				'type' => $this->getBackendIdentifier(),
			),
		);
	}


	/**
	 * @param ISubscription $subscription
	 * @throws BackendException
	 * @return bool
	 */
	public function validateSubscription(ISubscription &$subscription) {
		if ($subscription->getType() !== $this->getBackendIdentifier()) {
			throw new BackendException('Subscription-type not supported');
		}

		$this->validateSubscriptionUrl($subscription);
		$this->sendTestRequest($subscription);

		return true;
	}


	/**
	 * returns information about a certain calendar
	 * @param string $calendarURI
	 * @param string $userId
	 * @return ICalendar
	 * @throws DoesNotExistException if uri does not exist
	 */
	public function findCalendar($calendarURI, $userId) {
		$subscription = $this->subscriptions->find($calendarURI, $userId);

		return $this->generateCalendar($subscription);
	}


	/**
	 * returns all calendars of the user $userId
	 * @param string $userId
	 * @param integer $limit
	 * @param integer $offset
	 * @return ICalendarCollection
	 * @throws DoesNotExistException if uri does not exist
	 */
	public function findCalendars($userId, $limit, $offset) {
		$subscriptions = $this->subscriptions->findAllByType(
			$userId,
			$this->getBackendIdentifier(),
			$limit,
			$offset
		);

		$calendars = new CalendarCollection();
		$subscriptions->iterate(function(ISubscription $subscription) use (&$calendars, $userId) {
			try {
				$calendar = $this->generateCalendar($subscription);
				$calendars->add($calendar);
			} catch (CorruptDataException $ex) {
				return;
			} catch (BackendException $ex) {
				return;
			}
		});

		return $calendars;
	}


	/**
	 * @param string $userId
	 * @param integer $limit
	 * @param integer $offset
	 * @return array
	 */
	public function getCalendarIdentifiers($userId, $limit, $offset) {
		$identifiers = array();

		$subscriptions = $this->subscriptions->findAllByType($userId, $this->getBackendIdentifier(), $limit, $offset);
		$subscriptions->iterate(function(ISubscription $subscription) use (&$identifiers) {
			$identifiers[] = strval($subscription->getId());
		});

		return $identifiers;
	}


	/**
	 * returns number of calendar
	 * @param string $userId
	 * @return integer
	 */
	public function countCalendars($userId) {
		return $this->subscriptions->countByType($userId, $this->getBackendIdentifier());
	}


	/**
	 * returns whether or not a calendar exists
	 * @param string $calendarURI
	 * @param string $userId
	 * @return boolean
	 */
	public function doesCalendarExist($calendarURI, $userId) {
		return $this->subscriptions->doesExistOfType($calendarURI, $this->getBackendIdentifier(), $userId);
	}


	/**
	 * returns information about the object (event/journal/todos) with the uid $objectURI in the calendar $calendarURI of the user $userId
	 * @param ICalendar $calendar
	 * @param string $objectURI
	 * @param integer $type
	 * @return IObject
	 * @throws DoesNotExistException if calendar does not exist
	 * @throws DoesNotExistException if object does not exist
	 */
	public function findObject(ICalendar &$calendar, $objectURI, $type=ObjectType::ALL) {
		$object = new Object();
		((($object)));
		throw new DoesNotExistException();
	}


	/**
	 * returns all objects in the calendar $calendarURI of the user $userId
	 * @param ICalendar $calendar
	 * @param integer $type
	 * @param integer $limit
	 * @param integer $offset
	 * @return IObjectCollection
	 * @throws DoesNotExistException if calendar does not exist
	 * @throws CorruptDataException if calendar data is corrupt
	 */
	public function findObjects(ICalendar &$calendar, $type=ObjectType::ALL, $limit, $offset) {
		try {
			$subscription = $this->subscription->findByType($calendar->getPrivateUri(), $this->getBackendIdentifier(), $calendar->getUserId());
		} catch(BusinessLayerException $ex) {
			throw new DoesNotExistException($ex->getMessage());
		}
		$curl = curl_init();
		$url = $subscription->getUrl();
		$data = null;

		$this->prepareRequest($curl, $url);
		$this->getRequestData($curl, $data);
		$this->validateRequest($curl);

		$objectCollection = new ObjectCollection();
		
		try {
			$splitter = new ICalendarSplitter($data);
			while($vobject = $splitter->getNext()) {
				$object = new Object();
				$object->fromVObject($vobject);
				$objectCollection->add($object);
			}
		} catch(ParseException $ex) {
			throw new CorruptDataException('Calendar-data is not valid!');
		}
		return $objectCollection;
	}


	/**
	 * @param ISubscription $subscription
	 * @return \OCP\Calendar\IEntity
	 * @throws CorruptDataException
	 */
	private function generateCalendar(ISubscription $subscription) {
		$curl = curl_init();
		$url = $subscription->getUrl();
		$data = null;

		$this->prepareRequest($curl, $url);
		$this->getRequestData($curl, $data);
		$this->validateRequest($curl);
		$this->stripOfObjectData($data);

		try {
			$vobject = Reader::read($data);

			//Is it an address-book instead of a calendar?
			if (!($vobject instanceof VCalendar)) {
				throw new ParseException();
			}

			$calendar = new Calendar();
			$calendar->fromVObject($vobject);
		} catch(ParseException $ex) {
			throw new CorruptDataException('Calendar-data is not valid!');
		}

		$calendar->setUserId($subscription->getUserId());
		$calendar->setOwnerId($subscription->getUserId());
		$calendar->setBackend($this->backendIdentifier);
		$calendar->setPrivateUri($subscription->getId());
		$calendar->setComponents(ObjectType::EVENT);
		$calendar->setEnabled(true);
		$calendar->setCruds(Permissions::READ);
		$calendar->setOrder(0);
		//TODO - use something better for ctag
		$calendar->setCtag(time());

		return $calendar;
	}


	/**
	 * prepare curl request
	 * @param resource $curl
	 * @param string $url
	 * @return resource $ch
	 */
	private function prepareRequest(&$curl, $url) {
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($curl, CURLOPT_HEADER, true);
	}


	/**
	 * @param resource $curl
	 * @throws BackendException
	 * @throws CorruptDataException
	 */
	private function validateRequest($curl) {
		$responseCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		$contentType = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);

		if (!$this->isSuccess($responseCode)) {
			if ($this->needsMove($responseCode)) {
				//TODO - update subscription with new url
			}

			if ($this->isClientSideError($responseCode)) {
				throw new BackendException('Client side error occurred', $responseCode);
			}

			if ($this->isServerSideError($responseCode)) {
				//file is temporarily not available
				throw new BackendException('Url temporarily not available');
			}
		}

		if (!$this->isContentTypeValid($contentType)) {
			throw new BackendException('URL doesn\'t contain valid calendar data', Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}


	/**
	 * validates a subscription's url
	 * @param ISubscription $subscription
	 * @throws \OCP\Calendar\BackendException
	 */
	private function validateSubscriptionUrl(ISubscription &$subscription) {
		$url = $subscription->getUrl();
		$parsed = parse_url($url);

		if (!$parsed) {
			throw new BackendException('URL not processable');
		}

		if (!isset($parsed['scheme'])) {
			//TODO - try to use https first
			$newUrl  = 'http://';
			$newUrl .= $url;

			$subscription->setUrl($newUrl);
			$parsed['scheme'] = 'http';
		}

		if ($parsed['scheme'] === 'webcal') {
			$newUrl = preg_replace("/^webcal:/i", "http:", $url);

			$subscription->setUrl($newUrl);
			$parsed['scheme'] = 'http';
		}

		if ($parsed['scheme'] !== 'http' && $parsed['scheme'] !== 'https') {
			throw new BackendException('Protocol not supported');
		}
	}


	/**
	 *
	 * @param ISubscription $subscription
	 * @throws \OCP\Calendar\BackendException
	 */
	private function sendTestRequest(ISubscription &$subscription) {
		$curl = curl_init();
		$url = $subscription->getUrl();
		$data = null;

		$this->prepareRequest($curl, $url);
		$this->getRequestData($curl, $data);
		$this->validateRequest($curl);

		try {
			$vobject = Reader::read($data);

			//Is it an address-book instead of a calendar?
			if (!($vobject instanceof VCalendar)) {
				throw new ParseException();
			}
		} catch(ParseException $ex) {
			throw new BackendException('Calendar-data is not valid!');
		}
	}


	/**
	 * @param resource $curl
	 * @param string &$data
	 */
	private function getRequestData($curl, &$data) {
		$allData = curl_exec($curl);

		$headerLength = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
		$data = substr($allData, $headerLength);
	}


	/**
	 * @param string &$data
	 */
	private function stripOfObjectData(&$data) {
		$data = preg_replace(RegexUtility::VEVENT, '', $data);
		$data = preg_replace(RegexUtility::VJOURNAL, '', $data);
		$data = preg_replace(RegexUtility::VTODO, '', $data);
		$data = preg_replace(RegexUtility::VFREEBUSY, '', $data);
	}


	/**
	 * @param string $contentType
	 * @return bool
	 */
	private function isContentTypeValid($contentType) {
		return (substr($contentType, 0, 13) === 'text/calendar');
	}


	/**
	 * @param int $responseCode
	 * @return bool
	 */
	private function isSuccess($responseCode) {
		return ($responseCode >= 200 && $responseCode <= 299);
	}


	/**
	 * @param int $responseCode
	 * @return bool
	 */
	private function needsMove($responseCode) {
		return ($responseCode === 301);
	}


	/**
	 * @param int $responseCode
	 * @return bool
	 */
	private function isClientSideError($responseCode) {
		return ($responseCode >= 400 && $responseCode <= 499);
	}


	/**
	 * @param int $responseCode
	 * @return bool
	 */
	private function isServerSideError($responseCode) {
		return ($responseCode >= 500 && $responseCode <= 599);
	}
}