<?
/*
    ***** BEGIN LICENSE BLOCK *****
    
    This file is part of the Zotero Data Server.
    
    Copyright © 2010 Center for History and New Media
                     George Mason University, Fairfax, Virginia, USA
                     http://zotero.org
    
    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.
    
    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Affero General Public License for more details.
    
    You should have received a copy of the GNU Affero General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
    
    ***** END LICENSE BLOCK *****
*/

class Zotero_Tag {
	private $id;
	private $libraryID;
	private $key;
	private $tagDataID;
	private $name;
	private $type;
	private $dateAdded;
	private $dateModified;
	
	private $loaded;
	private $changed;
	private $previousData;
	
	private $linkedItemsLoaded;
	private $linkedItems = array();
	
	public function __construct() {
		$numArgs = func_num_args();
		if ($numArgs) {
			throw new Exception("Constructor doesn't take any parameters");
		}
	}
	
	
	public function __get($field) {
		if (($this->id || $this->key) && !$this->loaded) {
			$this->load(true);
		}
		
		if (!property_exists('Zotero_Tag', $field)) {
			throw new Exception("Zotero_Tag property '$field' doesn't exist");
		}
		return $this->$field;
	}
	
	
	public function __set($field, $value) {
		switch ($field) {
			case 'id':
			case 'libraryID':
			case 'key':
				if ($this->loaded) {
					throw new Exception("Cannot set $field after tag is already loaded");
				}
				$this->checkValue($field, $value);
				$this->$field = $value;
				return;
		}
		
		if ($this->id || $this->key) {
			if (!$this->loaded) {
				$this->load(true);
			}
		}
		else {
			$this->loaded = true;
		}
		
		$this->checkValue($field, $value);
		
		if ($this->$field != $value) {
			$this->prepFieldChange($field);
			$this->$field = $value;
		}
	}
	
	
	/**
	 * Check if tag exists in the database
	 *
	 * @return	bool			TRUE if the item exists, FALSE if not
	 */
	public function exists() {
		if (!$this->id) {
			trigger_error('$this->id not set');
		}
		
		$sql = "SELECT COUNT(*) FROM tags WHERE tagID=?";
		return !!Zotero_DB::valueQuery($sql, $this->id);
	}
	
	
	public function save($full=false) {
		if (!$this->libraryID) {
			trigger_error("Library ID must be set before saving", E_USER_ERROR);
		}
		
		Zotero_Tags::editCheck($this);
		
		if (!$this->changed) {
			Z_Core::debug("Tag $this->id has not changed");
			return false;
		}
		
		Zotero_DB::beginTransaction();
		
		try {
			$tagID = $this->id ? $this->id : Zotero_ID::get('tags');
			
			Z_Core::debug("Saving tag $tagID");
			
			$key = $this->key ? $this->key : $this->generateKey();
			$tagDataID = Zotero_Tags::getTagDataID($this->name, true);
			
			$fields = "tagDataID=?, `type`=?, dateAdded=?, dateModified=?,
				libraryID=?, `key`=?, serverDateModified=?, serverDateModifiedMS=?";
			$timestamp = Zotero_DB::getTransactionTimestamp();
			$timestampMS = Zotero_DB::getTransactionTimestampMS();
			$params = array(
				$tagDataID,
				$this->type ? $this->type : 0,
				$this->dateAdded ? $this->dateAdded : $timestamp,
				$this->dateModified ? $this->dateModified : $timestamp,
				$this->libraryID,
				$key,
				$timestamp,
				$timestampMS
			);
			
			$params = array_merge(array($tagID), $params, $params);
			
			$sql = "INSERT INTO tags SET tagID=?, $fields ON DUPLICATE KEY UPDATE $fields";
			$stmt = Zotero_DB::getStatement($sql, true);
			$insertID = Zotero_DB::queryFromStatement($stmt, $params);
			if (!$this->id) {
				if (!$insertID) {
					throw new Exception("Tag id not available after INSERT");
				}
				$tagID = $insertID;
				Zotero_Tags::cacheLibraryKeyID($this->libraryID, $key, $insertID);
			}
			
			// Linked items
			if ($full || !empty($this->changed['linkedItems'])) {
				$removed = array();
				$newids = array();
				$currentIDs = $this->getLinkedItems(true);
				if (!$currentIDs) {
					$currentIDs = array();
				}
				
				if ($full) {
					$sql = "SELECT itemID FROM itemTags WHERE tagID=?";
					$dbItemIDs = Zotero_DB::columnQuery($sql, $tagID);
					if ($dbItemIDs) {
						$removed = array_diff($dbItemIDs, $currentIDs);
						$newids = array_diff($currentIDs, $dbItemIDs);
					}
					else {
						$newids = $currentIDs;
					}
				}
				else {
					if ($this->previousData['linkedItems']) {
						$removed = array_diff(
							$this->previousData['linkedItems'], $currentIDs
						);
						$newids = array_diff(
							$currentIDs, $this->previousData['linkedItems']
						);
					}
					else {
						$newids = $currentIDs;
					}
				}
				
				if ($removed) {
					$sql = "DELETE FROM itemTags WHERE tagID=? AND itemID IN (";
					$q = array_fill(0, sizeOf($removed), '?');
					$sql .= implode(', ', $q) . ")";
					Zotero_DB::query($sql,
						array_merge(
							array($this->id), $removed)
						);
				}
				
				if ($newids) {
					$newids = array_values($newids);
					$sql = "INSERT INTO itemTags (tagID, itemID) VALUES ";
					$maxInsertGroups = 50;
					Zotero_DB::bulkInsert($sql, $newids, $maxInsertGroups, $tagID);
				}
				
				//Zotero.Notifier.trigger('add', 'collection-item', $this->id . '-' . $itemID);
			}
			
			Zotero_DB::commit();
		}
		catch (Exception $e) {
			Zotero_DB::rollback();
			throw ($e);
		}
		
		// If successful, set values in object
		if (!$this->id) {
			$this->id = $tagID;
		}
		if (!$this->key) {
			$this->key = $key;
		}
		
		return $this->id;
	}
	
	
	public function getLinkedItems($asIDs=false) {
		if (!$this->linkedItemsLoaded) {
			$this->loadLinkedItems();
		}
		
		if ($asIDs) {
			$itemIDs = array();
			foreach ($this->linkedItems as $linkedItem) {
				$itemIDs[] = $linkedItem->id;
			}
			return $itemIDs;
		}
		
		return $this->linkedItems;
	}
	
	
	public function setLinkedItems($itemIDs) {
		if (!$this->linkedItemsLoaded) {
			$this->loadLinkedItems();
		}
		
		if (!is_array($itemIDs))  {
			trigger_error('$itemIDs must be an array', E_USER_ERROR);
		}
		
		$currentIDs = $this->getLinkedItems(true);
		if (!$currentIDs) {
			$currentIDs = array();
		}
		$oldIDs = array(); // children being kept
		$newIDs = array(); // new children
		
		if (!$itemIDs) {
			if (!$currentIDs) {
				Z_Core::debug("No linked items added", 4);
				return false;
			}
		}
		else {
			foreach ($itemIDs as $itemID) {
				if (in_array($itemID, $currentIDs)) {
					Z_Core::debug("Item $itemID already has tag {$this->id}");
					$oldIDs[] = $itemID;
					continue;
				}
				
				$newIDs[] = $itemID;
			}
		}
		
		// Mark as changed if new or removed ids
		if ($newIDs || sizeOf($oldIDs) != sizeOf($currentIDs)) {
			$this->prepFieldChange('linkedItems');
		}
		else {
			Z_Core::debug('Linked items not changed', 4);
			return false;
		}
		
		$newIDs = array_merge($oldIDs, $newIDs);
		
		if ($newIDs) {
			$items = Zotero_Items::get($newIDs);
		}
		
		$this->linkedItems = !empty($items) ? $items : array();
		return true;
	}
	
	
	public function serialize() {
		$obj = array(
			'primary' => array(
				'tagID' => $this->id,
				'dateAdded' => $this->dateAdded,
				'dateModified' => $this->dateModified,
				'key' => $this->key
			),
			'name' => $this->name,
			'type' => $this->type,
			'linkedItems' => $this->getLinkedItems(true),
		);
		
		return $obj;
	}
	
	
	/**
	 * Converts a Zotero_Tag object to a SimpleXMLElement item
	 *
	 * @param	object				$item		Zotero_Tag object
	 * @return	SimpleXMLElement				Tag data as SimpleXML element
	 */
	public function toXML($syncMode=false) {
		if (!$this->loaded) {
			$this->load();
		}
		
		$xml = '<tag';
		/*if (!$syncMode) {
			$xml .= ' xmlns="' . Zotero_Atom::$nsZoteroTransfer . '"';
		}*/
		$xml .= '/>';
		$xml = new SimpleXMLElement($xml);
		
		$xml['libraryID'] = $this->libraryID;
		$xml['key'] = $this->key;
		$xml['name'] = $this->name;
		$xml['dateAdded'] = $this->dateAdded;
		$xml['dateModified'] = $this->dateModified;
		if ($this->type) {
			$xml['type'] = $this->type;
		}
		
		if ($syncMode) {
			$items = $this->getLinkedItems();
			if ($items) {
				$keys = array();
				foreach ($items as $item) {
					$keys[] = $item->key;
				}
				$xml->items = implode(' ', $keys);
			}
		}
		
		return $xml;
	}
	
	
	/**
	 * Converts a Zotero_Tag object to a SimpleXMLElement Atom object
	 *
	 * @param	object				$tag		Zotero_Tag object
	 * @param	string				$content
	 * @return	SimpleXMLElement					Tag data as SimpleXML element
	 */
	public function toAtom($content='none', $apiVersion=null) {
		$xml = new SimpleXMLElement(
			'<entry xmlns="' . Zotero_Atom::$nsAtom . '" '
			. 'xmlns:zapi="' . Zotero_Atom::$nsZoteroAPI . '" '
			. 'xmlns:zxfer="' . Zotero_Atom::$nsZoteroTransfer . '"/>'
		);
		
		$xml->title = $this->name;
		
		$author = $xml->addChild('author');
		$author->name = Zotero_Libraries::getName($this->libraryID);
		$author->uri = Zotero_URI::getLibraryURI($this->libraryID);
		
		$xml->id = Zotero_URI::getTagURI($this);
		
		$xml->published = Zotero_Date::sqlToISO8601($this->dateAdded);
		$xml->updated = Zotero_Date::sqlToISO8601($this->dateModified);
		
		$link = $xml->addChild("link");
		$link['rel'] = "self";
		$link['type'] = "application/atom+xml";
		$link['href'] = Zotero_Atom::getTagURI($this);
		
		$link = $xml->addChild('link');
		$link['rel'] = 'alternate';
		$link['type'] = 'text/html';
		$link['href'] = Zotero_URI::getTagURI($this);
		
		// Count user's linked items
		$itemIDs = $this->getLinkedItems();
		$xml->addChild(
			'zapi:numItems',
			sizeOf($itemIDs),
			Zotero_Atom::$nsZoteroAPI
		);
		
		if ($content == 'html') {
			$xml->content['type'] = 'xhtml';
			
			//$fullXML = Zotero_Tags::convertTagToXML($tag);
			$fullStr = "<div/>";
			$fullXML = new SimpleXMLElement($fullStr);
			$fullXML->addAttribute(
				"xmlns", Zotero_Atom::$nsXHTML
			);
			$fNode = dom_import_simplexml($xml->content);
			$subNode = dom_import_simplexml($fullXML);
			$importedNode = $fNode->ownerDocument->importNode($subNode, true);
			$fNode->appendChild($importedNode);
			
			//$arr = $tag->serialize();
			//require_once("views/zotero/tags.php")
		}
		// Not for public consumption
		else if ($content == 'full') {
			$xml->content['type'] = 'application/xml';
			$fullXML = $this->toXML();
			$fullXML->addAttribute(
				"xmlns", Zotero_Atom::$nsZoteroTransfer
			);
			$fNode = dom_import_simplexml($xml->content);
			$subNode = dom_import_simplexml($fullXML);
			$importedNode = $fNode->ownerDocument->importNode($subNode, true);
			$fNode->appendChild($importedNode);
		}
		
		return $xml;
	}
	
	
	private function load($allowFail=false) {
		Z_Core::debug("Loading data for tag $this->id");
		
		if (!$this->id && !$this->key) {
			throw new Exception("ID or key not set");
		}
		
		$sql = "SELECT tagID AS id, type, dateAdded, dateModified, libraryID, `key`, TD.*
					FROM tags T NATURAL JOIN tagData TD WHERE ";
		if ($this->id) {
			$sql .= "tagID=?";
			$stmt = Zotero_DB::getStatement($sql);
			$data = Zotero_DB::rowQueryFromStatement($stmt, $this->id);
		}
		else {
			$sql .= "libraryID=? AND `key`=?";
			$stmt = Zotero_DB::getStatement($sql);
			$data = Zotero_DB::rowQueryFromStatement($stmt, array($this->libraryID, $this->key));
		}
		
		$this->loaded = true;
		
		if (!$data) {
			return;
		}
		
		foreach ($data as $key=>$val) {
			$this->$key = $val;
		}
	}
	
	
	private function loadLinkedItems() {
		Z_Core::debug("Loading linked items for tag $this->id");
		
		if (!$this->id && !$this->key) {
			$this->linkedItemsLoaded = true;
			return;
		}
		
		if (!$this->loaded) {
			$this->load();
		}
		
		if (!$this->id) {
			$this->linkedItemsLoaded = true;
			return;
		}
		
		$sql = "SELECT itemID FROM itemTags WHERE tagID=?";
		$ids = Zotero_DB::columnQuery($sql, $this->id);
		
		$this->linkedItems = array();
		if ($ids) {
			$this->linkedItems = Zotero_Items::get($ids);
		}
		
		$this->linkedItemsLoaded = true;
	}
	
	
	private function checkValue($field, $value) {
		if (!property_exists($this, $field)) {
			trigger_error("Invalid property '$field'", E_USER_ERROR);
		}
		
		// Data validation
		switch ($field) {
			case 'id':
			case 'libraryID':
				if (!Zotero_Utilities::isPosInt($value)) {
					$this->invalidValueError($field, $value);
				}
				break;
			
			case 'key':
				if (!preg_match('/^[23456789ABCDEFGHIJKMNPQRSTUVWXTZ]{8}$/', $value)) {
					$this->invalidValueError($field, $value);
				}
				break;
			
			case 'dateAdded':
			case 'dateModified':
				if (!preg_match("/^[0-9]{4}\-[0-9]{2}\-[0-9]{2} ([0-1][0-9]|[2][0-3]):([0-5][0-9]):([0-5][0-9])$/", $value)) {
					$this->invalidValueError($field, $value);
				}
				break;
		}
	}
	
	
	private function prepFieldChange($field) {
		if (!$this->changed) {
			$this->changed = array();
		}
		$this->changed[$field] = true;
		
		// Save a copy of the data before changing
		// TODO: only save previous data if tag exists
		if ($this->id && $this->exists() && !$this->previousData) {
			$this->previousData = $this->serialize();
		}
	}

	
	
	private function generateKey() {
		trigger_error('Unimplemented', E_USER_ERROR);
	}
	
	
	private function invalidValueError($field, $value) {
		trigger_error("Invalid '$field' value '$value'", E_USER_ERROR);
	}
}
?>
