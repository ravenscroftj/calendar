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
namespace OCA\Calendar;

use OCP\AppFramework\App;
use OCP\AppFramework\IAppContainer;
use OCP\Share;
use OCP\Util;

use Sabre\VObject\Splitter\ICalendar as ICalendarSplitter;
use OCA\Calendar\Sabre\Splitter\JCalendar as JCalendarSplitter;

class Application extends App {

	/**
	 * @var IBackendCollection
	 */
	protected $backends;


	/**
	 * @var Db\BackendFactory
	 */
	protected $backendFactory;


	/**
	 * @param array $params
	 */
	public function __construct($params = array()) {
		parent::__construct('calendar', $params);
		$container = $this->getContainer();

		$this->registerControllers($container);
		$this->registerBusinessLayers($container);
		$this->registerMappers($container);
		$this->registerFactories($container);
		$this->registerReaders($container);

		$container->registerService('BackendsWithoutSharing', function(IAppContainer $c) {
			$backends = $c->query('Backends');
			$backends->removeByProperty('backend', 'org.ownCloud.sharing');

			return $backends;
		});

		$container->registerParameter('settings', [
			'view' => [
				'configKey' => 'currentView',
				'options' => [
					'agendaDay',
					'agendaWeek',
					'month',
				],
				'default' => 'month',
			]
		]);

		$this->initBackendSystem();
		$this->registerBackends();
	}

	private function registerControllers(IAppContainer $container) {
		$container->registerService('BackendController', function(IAppContainer $c) {
			$request = $c->query('Request');
			$userSession = $c->getServer()->getUserSession();

			return new Controller\BackendController($c->getAppName(), $request, $userSession, $this->backends);
		});
		$container->registerService('CalendarController', function(IAppContainer $c) {
			$request = $c->query('Request');
			$userSession = $c->getServer()->getUserSession();
			$calendarManager = $c->query('CalendarRequestManager');
			$calendarFactory = $c->query('CalendarFactory');

			return new Controller\CalendarController($c->getAppName(), $request, $userSession, $calendarManager, $calendarFactory);
		});
		$container->registerService('ContactController', function(IAppContainer $c) {
			$request = $c->query('Request');
			$contacts = $c->getServer()->getContactsManager();
			$userSession = $c->getServer()->getUserSession();

			return new Controller\ContactController($c->getAppName(), $request, $userSession, $contacts);
		});
		$container->registerService('ObjectController', function(IAppContainer $c) {
			$request = $c->query('Request');
			$userSession = $c->getServer()->getUserSession();
			$calendars = $c->query('CalendarRequestManager');
			$objects = $c->query('ObjectRequestManager');
			$objectFactory = $c->query('ObjectFactory');

			return new Controller\ObjectController($c->getAppName(), $request, $userSession, $calendars, $objects, $objectFactory, Db\ObjectType::ALL);
		});
		$container->registerService('OCA\\Calendar\\Controller\\EventController', function(IAppContainer $c) {
			$request = $c->query('Request');
			$userSession = $c->getServer()->getUserSession();
			$calendars = $c->query('CalendarRequestManager');
			$objects = $c->query('ObjectRequestManager');
			$objectFactory = $c->query('ObjectFactory');

			return new Controller\ObjectController($c->getAppName(), $request, $userSession, $calendars, $objects, $objectFactory, Db\ObjectType::EVENT);
		});
		$container->registerService('OCA\\Calendar\\Controller\\JournalController', function(IAppContainer $c) {
			$request = $c->query('Request');
			$userSession = $c->getServer()->getUserSession();
			$calendars = $c->query('CalendarRequestManager');
			$objects = $c->query('ObjectRequestManager');
			$objectFactory = $c->query('ObjectFactory');

			return new Controller\ObjectController($c->getAppName(), $request, $userSession, $calendars, $objects, $objectFactory, Db\ObjectType::JOURNAL);
		});
		$container->registerService('OCA\\Calendar\\Controller\\TodoController', function(IAppContainer $c) {
			$request = $c->query('Request');
			$userSession = $c->getServer()->getUserSession();
			$calendars = $c->query('CalendarRequestManager');
			$objects = $c->query('ObjectRequestManager');
			$objectFactory = $c->query('ObjectFactory');

			return new Controller\ObjectController($c->getAppName(), $request, $userSession, $calendars, $objects, $objectFactory, Db\ObjectType::TODO);
		});
		$container->registerService('SettingsController', function(IAppContainer $c) {
			$request = $c->query('Request');
			$settings = $c->query('settings');
			$config = $c->getServer()->getConfig();
			$userSession = $c->getServer()->getUserSession();

			return new Controller\SettingsController($c->getAppName(), $request, $userSession, $config, $settings);
		});
		$container->registerService('SubscriptionController', function(IAppContainer $c) {
			$request = $c->query('Request');
			$subscriptions = $c->query('SubscriptionBusinessLayer');
			$userSession = $c->getServer()->getUserSession();
			$subscriptionFactory = $c->query('SubscriptionFactory');

			return new Controller\SubscriptionController($c->getAppName(), $request, $userSession, $subscriptions, $subscriptionFactory);
		});
		$container->registerService('TimezoneController', function(IAppContainer $c) {
			$request = $c->query('Request');
			$timezones = $c->query('TimezoneBusinessLayer');
			$userSession = $c->getServer()->getUserSession();

			return new Controller\TimezoneController($c->getAppName(), $request, $userSession, $timezones);
		});
		$container->registerService('ViewController', function(IAppContainer $c) {
			$request = $c->query('Request');
			$userSession = $c->getServer()->getUserSession();

			return new Controller\ViewController($c->getAppName(), $request, $userSession);
		});
	}

	private function registerBusinessLayers(IAppContainer $container) {
		$container->registerService('CalendarManager', function() {
			return new BusinessLayer\CalendarManager($this->backends);
		});
		$container->registerService('CalendarRequestManager', function() {
			return new BusinessLayer\CalendarRequestManager($this->backends);
		});
		$container->registerService('ObjectManager', function(IAppContainer $c) {
			$timezones = $c->query('TimezoneMapper');

			return function(ICalendar $calendar) use ($timezones) {
				return new BusinessLayer\ObjectManager($calendar, $timezones);
			};
		});
		$container->registerService('ObjectRequestManager', function(IAppContainer $c) {
			$timezones = $c->query('TimezoneMapper');

			return function(ICalendar $calendar) use ($timezones) {
				return new BusinessLayer\ObjectRequestManager($calendar, $timezones);
			};
		});
		$container->registerService('SubscriptionBusinessLayer', function(IAppContainer $c) {
			$mapper = $c->query('SubscriptionMapper');

			return new BusinessLayer\Subscription($mapper);
		});
		$container->registerService('TimezoneBusinessLayer', function(IAppContainer $c) {
			$mapper = $c->query('TimezoneMapper');

			return new BusinessLayer\Timezone($mapper);
		});
	}

	private function registerMappers(IAppContainer $container) {
		$container->registerService('TimezoneMapper', function() {
			return new Db\TimezoneMapper();
		});
		$container->registerService('SubscriptionMapper', function(IAppContainer $c) {
			$entityFactory = new Db\SubscriptionFactory();
			$collectionFactory = new Db\SubscriptionCollectionFactory($entityFactory, $c->getServer()->getLogger());

			return new Db\SubscriptionMapper($c->getServer()->getDatabaseConnection(), $entityFactory, $collectionFactory);
		});
	}

	private function registerFactories(IAppContainer $container) {
		$container->registerService('CalendarFactory', function(IAppContainer $c) {
			$timezoneMapper = $c->query('TimezoneMapper');

			return new Db\CalendarFactory($this->backends, $timezoneMapper);
		});
		$container->registerService('CalendarCollectionFactory', function(IAppContainer $c) {
			$factory = $c->query('CalendarFactory');
			$logger = $c->getServer()->getLogger();

			return new Db\CalendarCollectionFactory($factory, $logger);
		});
		$container->registerService('ObjectFactory', function() {
			return new Db\ObjectFactory();
		});
		$container->registerService('ObjectCollectionFactory', function(IAppContainer $c) {
			$factory = $c->query('ObjectFactory');
			$logger = $c->getServer()->getLogger();
			$iCal = function($data) {
				return new ICalendarSplitter($data);
			};
			$jCal = function($data) {
				return new JCalendarSplitter($data);
			};

			return new Db\ObjectCollectionFactory($factory, $logger, $iCal, $jCal);
		});
		$container->registerService('SubscriptionFactory', function() {
			return new Db\SubscriptionFactory();
		});
		$container->registerService('SubscriptionCollectionFactory', function(IAppContainer $c) {
			$factory = $c->query('SubscriptionFactory');
			$logger = $c->getServer()->getLogger();

			return new Db\SubscriptionCollectionFactory($factory, $logger);
		});
	}

	/**
	 * register reader classes
	 * @param IAppContainer $container
	 */
	private function registerReaders(IAppContainer $container) {
		$container->registerService('JSONCalendarReader', function(IAppContainer $c) {
			return function($request) use ($c) {
				$calendarFactory = $c->query('CalendarFactory');

				$reader = new Http\JSON\CalendarReader($request, $calendarFactory);
				$reader->getObject();
			};
		});

		$container->registerService('JSONSubscriptionReader', function(IAppContainer $c) {
			return function($request) use ($c) {
				$subscriptionFactory = $c->query('SubscriptionFactory');

				$reader = new Http\JSON\SubscriptionReader($request, $subscriptionFactory);
				$reader->getObject();
			};
		});
	}

	protected function initBackendSystem() {
		$this->backends = new Db\BackendCollection(
			function (IBackendCollection $backends) {
				$db = $this->getContainer()->getServer()->getDatabaseConnection();
				$logger = $this->getContainer()->getServer()->getLogger();
				$timezones = $this->getContainer()->query('TimezoneMapper');

				$entityFactory = new Db\CalendarFactory($backends, $timezones);
				$collectionFactory = new Db\CalendarCollectionFactory($entityFactory, $logger);

				return new Cache\Calendar\Cache($backends, $db, $entityFactory, $collectionFactory);
			},
			function (IBackendCollection $backends) {
				$logger = $this->getContainer()->getServer()->getLogger();

				return new Cache\Calendar\Scanner($backends, $logger);
			},
			function (IBackendCollection $backends) {
				return new Cache\Calendar\Updater($backends);
			},
			function (IBackendCollection $backends) {
				return new Cache\Calendar\Watcher($backends);
			}
		);

		$this->backendFactory = new Db\BackendFactory(
			function(ICalendar $calendar) {
				$db = $this->getContainer()->getServer()->getDatabaseConnection();

				$entityFactory = new Db\ObjectFactory();
				$collectionFactory = $this->getContainer()->query('ObjectCollectionFactory');

				return new Cache\Object\Cache($db, $calendar, $entityFactory, $collectionFactory);
			},
			function(ICalendar $calendar) {
				return new Cache\Object\Scanner($calendar);
			},
			function(ICalendar $calendar) {
				return new Cache\Object\Updater($calendar);
			},
			function(ICalendar $calendar) {
				return new Cache\Object\Watcher($calendar);
			}
		);
	}

	public function registerBackends() {
		// Local backend: Default database backend
		$this->backends->add(
			$this->backendFactory->createBackend(
				'org.ownCloud.local',
				function() {
					return new Backend\Local\Backend();
				},
				function(IBackend $backend) {
					$db = $this->getContainer()->getServer()->getDatabaseConnection();
					return new Backend\Local\Calendar($db, $backend);
				},
				function(ICalendar $calendar) {
					$db = $this->getContainer()->getServer()->getDatabaseConnection();
					return new Backend\Local\Object($db, $calendar);
				}
			)
		);

		// Contacts backend: show contact's birthdays and anniversaries
		if (class_exists('\\OCA\\Contacts\\App') && false) {
			$contacts = new \OCA\Contacts\App();
			$this->backends->add(
				$this->backendFactory->createBackend(
					'org.ownCloud.contact',
					function() use ($contacts) {
						return new Backend\Contact\Backend($contacts);
					},
					function(IBackend $backend) use ($contacts) {
						return new Backend\Contact\Calendar($contacts, $backend);
					},
					function(ICalendar $calendar) use ($contacts) {
						return new Backend\Contact\Object($contacts, $calendar);
					}
				)
			);
		}

		// Sharing backend: Enabling users to share calendars
		if (Share::isEnabled() && false) {
			$this->backends->add(
				$this->backendFactory->createBackend(
					'org.ownCloud.sharing',
					function () {
						return new Backend\Sharing\Backend();
					},
					function (IBackend $backend) {
						return new Backend\Sharing\Calendar($backend);
					},
					function (ICalendar $calendar) {
						return new Backend\Sharing\Object($calendar);
					}
				)
			);
		}

		// Webcal Backend: Show ICS files on the net
		if (function_exists('curl_init') && false) {
			$this->backends->add(
				$this->backendFactory->createBackend(
					'org.ownCloud.webcal',
					function () {
						$subscriptions = $this->getContainer()->query('SubscriptionController');
						return new Backend\WebCal\Backend($subscriptions);
					},
					function (IBackend $backend) {
						$subscriptions = $this->getContainer()->query('SubscriptionController');
						return new Backend\WebCal\Calendar($subscriptions, $backend);
					},
					function (ICalendar $calendar) {
						$subscriptions = $this->getContainer()->query('SubscriptionController');
						return new Backend\WebCal\Object($subscriptions, $calendar);
					}
				)
			);
		}
	}


	/**
	 * add navigation entry
	 */
	public function registerNavigation() {
		$appName = $this->getContainer()->getAppName();
		$server = $this->getContainer()->getServer();

		$server->getNavigationManager()->add(array(
			'id' => $appName,
			'order' => 10,
			'href' => $server->getURLGenerator()
					->linkToRoute('calendar.view.index'),
			'icon' => $server->getURLGenerator()
					->imagePath($appName, 'calendar.svg'),
			'name' => $server->getL10N($appName)->t('Calendar'),
		));
	}


	/**
	 * register a cron job
	 */
	public function registerCron() {
		/*
		$c = $container;
		//$c->addRegularTask('OCA\Calendar\Backgroundjob\Task', 'run');
		*/
	}


	/**
	 * connect to hooks
	 */
	public function registerHooks() {
		//Calendar-internal hooks
		Util::connectHook('OCA\Calendar', 'postCreateObject',
			'\OCA\Calendar\Util\HookUtility', 'createObject');
		Util::connectHook('OCA\Calendar', 'postUpdateObject',
			'\OCA\Calendar\Util\HookUtility', 'updateObject');
		Util::connectHook('OCA\Calendar', 'postDeleteObject',
			'\OCA\Calendar\Util\HookUtility', 'deleteObject');

		//Sharing hooks
		Util::connectHook('OCP\Share', 'post_shared',
			'\OCA\Calendar\Util\HookUtility', 'share');
		Util::connectHook('OCP\Share', 'post_unshare',
			'\OCA\Calendar\Util\HookUtility', 'unshare');

		//User hooks
		Util::connectHook('OC_User', 'post_createUser',
			'\OCA\Calendar\Util\HookUtility', 'createUser');
		Util::connectHook('OC_User', 'post_createUser',
			'\OCA\Calendar\Util\HookUtility', 'deleteUser');
	}


	/**
	 * register search and share provider
	 */
	public function registerProviders() {
		/* \OC_Search::registerProvider('\OCA\Calendar\SearchProvider'); */
		Share::registerBackend('calendar', '\OCA\Calendar\Share\Calendar');
		Share::registerBackend('event', '\OCA\Calendar\Share\Event');
	}
}
