<?php
/**
 * Copyright (c) 2013 Georg Ehrke <oc.list@georgehrke.com>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */
namespace OCA\Calendar\Db;

use \OCA\Calendar\AppFramework\Core\API;
use \OCA\Calendar\AppFramework\Db\Mapper;
use \OCA\Calendar\AppFramework\Db\DoesNotExistException;

use \OCA\Calendar\Db\Calendar;

class CalendarMapper extends Mapper {
	private $tableName;
	private $keyValueTableName;

	/**
	 * @param API $api: Instance of the API abstraction layer
	 */
	public function __construct($api, $tablename='clndr_calcache'){
		parent::__construct($api, $tablename);

		$this->tableName = '*PREFIX*' . $tablename;
	}

	/**
	 * Finds an item from user by it's uri
	 * @throws DoesNotExistException: if the item does not exist
	 * @return the item
	 */
	public function find($backend, $uri, $userId){
		$sql = 'SELECT * FROM `' . $this->tableName . '` WHERE `backend` = ? AND `uri` = ? AND `user_id` = ?';
		$row = $this->findOneQuery($sql, array($backend, $uri, $userId));
		return new Calendar($row);
	}

	/**
	 * Finds an item from user by it's uri
	 * @throws DoesNotExistException: if the item does not exist
	 * @return the item
	 */
	public function countFind($backend, $uri, $userId){
		$sql = 'SELECT COUNT(*) AS `count` FROM `' . $this->tableName . '` WHERE `backend` = ? AND `uri` = ? AND `user_id` = ?';
		$row = $this->findOneQuery($sql, array($backend, $uri, $userId));
		return $row['count'];
	}

	/**
	 * Finds all Items from user
	 * @return array containing all items
	 */
	public function findAll($userId, $limit, $offset){
		$sql = 'SELECT * FROM `'. $this->tableName . '` WHERE `user_id` = ?';
		return $this->findEntities($sql, array($userId), $limit, $offset);
	}
}