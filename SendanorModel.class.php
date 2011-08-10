<?php

/* Original code from Niklas Närhinen <https://codesense.repositoryhosting.com/svn_public/codesense_sendanorclient/app/modules/Sendanor/models/SendanorModel.class.php> */

class SendanorModel {
	private $SENDANOR_URL = 'https://secure.sendanor.fi/~jheusala/ccd/ccd-server.cgi';
	
	private $messages = array();
	private $session_id = '';
	
	private function addMessage($msg) {
		//$this->getContext()->getModel('FlashMessage')->addMessage((string)$msg);
		$this->messages[] = (string)$msg;
	}
	
	public function getMessages() {
		return $this->messages;
	}
	
	public function clearMessages() {
		$this->messages = array();
	}
	
	public function setURL($url) {
		$this->SENDANOR_URL = (string)$url;
	}
	
	public function signup($username)
	{
		$options = array(
			'username' => $username
		);
		$xml = $this->createCommandXml('create account', $options);
		$responseXml = $this->request($xml);
		
		foreach($responseXml->message as $message) {
			if (isset($message['type']) && $message['type'] == 'error') {
				throw new Exception($message['subject']);
			}
			$this->addMessage((string)$message['subject']);
		}
	}
	
	public function activate($activationcode)
	{
		$options = array(
			'code' => $activationcode
		);
		$xml = $this->createCommandXml('activate account', $options);
		$responseXml = $this->request($xml);
		foreach($responseXml->message as $message) {
			if (isset($message['type']) && $message['type'] == 'error') {
				throw new Exception($message['subject']);
			}
			$this->addMessage((string)$message['subject']);
		}
	}
	public function login($username, $password)
	{
		$options = array(
			'username' => $username,
			'password' => $password
		);
		$xml = $this->createCommandXml("login", $options);
		try {
			$responseXml = $this->request($xml);
			foreach($responseXml->message as $message) {
				$this->addMessage((string)$message['subject']);
			}
			return true;
			/*
			$this->getContext()->getUser()->setAuthenticated(true);
			$this->getContext()->getUser()->setAttribute('Email', $username, 'sendanor');
			$this->getContext()->getUser()->setAttribute('ActiveCustomer', $this->getUserInfo($username), 'sendanor');
			*/
		}
		catch (NorRequestUnsuccessfullException $e) {
			throw new Exception($e->getMessage());
		}
	}
	
	public function logoff()
	{
		$xml = $this->createCommandXml("logout");
		$this->request($xml);
		$this->session_id = '';
		/*
		$this->getContext()->getUser()->setAuthenticated(false);
		$this->getContext()->getUser()->clearAttributes();
		*/
	}
	
	public function getUserInfo($email = null, $id = null)
	{
		if ($email) {
			$this->switchCustomer($email);
		}
		elseif ($id) {
			$this->switchCustomer(null, $id);
		}
		else {
			throw new Exception("Feature not supported");
			/*
			return $this->getContext()->getUser()->getAttribute('ActiveCustomer', 'sendanor');
			*/
		}
		$xml = $this->createCommandXml("show client");
		$responseXml = $this->request($xml);
		$ret = array();
		foreach ($responseXml->record[0]->record as $record) {
			$ret[(string)$record['name']] = (string)$record['value'];
		}
		return $ret;
	}
	
	public function switchCustomer($email, $id = null)
	{
		if ($email) {
			$xml = $this->createCommandXml("switch client", array('email' => $email));
			$this->request($xml);
		}
		elseif($id) {
			$xml = $this->createCommandXml("switch client", array('id' => $id));
			$this->request($xml);
		}
		else {
			throw new NorException("Anna joko sähköposti tai asiakasnumero");
		}
	}
	
	public function listDomains()
	{
		$xml = $this->createCommandXml("show dns zones");
		$response = $this->request($xml);
		return $this->parseRecord($response->record[0]);
	}
	
	public function listPriceTypes()
	{
		$xml = $this->createCommandXml("show price types");
		$response = $this->request($xml);
		return $this->parseRecord($response->record[0]);
	}
	
	public function listProducts()
	{
		$xml = $this->createCommandXml("show products");
		$response = $this->request($xml);
		return $this->parseRecord($response->record[0]);
	}
	
	public function listProductGroups()
	{
		$xml = $this->createCommandXml("show product groups");
		$response = $this->request($xml);
		return $this->parseRecord($response->record[0]);
	}
	
	public function getDomain($zone)
	{
		$xml = $this->createCommandXml("show dns", array('zone' => $zone));
		$response = $this->request($xml);
		//echo "<pre>";
		//echo htmlspecialchars($response->asXml());
		//exit;
		$ret = array();
		foreach($this->parseRecord($response->xpath("record[@name='dns.zone.rows.$zone']")) as $record) {
			$ret[$record['id']] = $record;
		}
		return $ret;
	}
	
	public function saveDomain($domain, array $records = array(), $new = array())
	{
		//Performance boost
		$existingrecords = $this->getDomain($domain);
		
		foreach ($records as $id => $record) {
			$record['id'] = $id;
			if (isset($existingrecords[$id])) {
				if (!empty($record['is_locally_changed'])) {
					$this->saveDomainRecord($domain, $record);
				}
				unset($existingrecords[$id]);
			}
		}
		if ($new) {
			foreach ($new as $record) {
				$this->addDomainRecord($domain, $record);
			}
		}
		foreach($existingrecords as $id => $record) {
			$this->removeDomainRecord($domain, $record);
		}
	}
	
	public function saveDomainRecord($domain, array $record)
	{
		$options = array(
			'zone' => $domain,
			'id' => $record['id'],
			'name' => $record['name'],
			'type' => $record['type'],
			'value' => $record['value']
		);
		$xml = $this->createCommandXml("set dns", $options);
		$response = $this->request($xml);
	}
	
	public function addDomainRecord($domain, array $record)
	{
		$options = array(
			'zone' => $domain,
			'name' => $record['name'],
			'type' => $record['type'],
			'value' => $record['value']
		);
		$xml = $this->createCommandXml("add dns", $options);
		$response = $this->request($xml);
	}
	
	public function removeDomainRecord($domain, array $record)
	{
		$options = array(
			'zone' => $domain,
			'id' => $record['id']
		);
		$xml = $this->createCommandXml("remove dns", $options);
		$response = $this->request($xml);
	}
	
	public function listNSRecordTypes()
	{
		return array(
			'A',
			'AAAA',
			'MX',
			'NS',
			'CNAME',
			'DNAME',
			'TXT'
		);
	}
	
	public function sendPasswordReminder($username)
	{
		$xml = $this->createCommandXml("send password change", array('username' => $username));
		$this->request($xml);
	}

	
	private function createCommandXml($command, array $options = array())
	{
		$xml = new SimpleXMLElement("<ccd type=\"request\"><command name=\"\" type=\"cli\"></command></ccd>");
		if ($this->session_id != '') {
			$xml['session_id'] = $this->session_id;
		}
		$xml->command[0]['name'] = $command;
		foreach ($options as $key => $value) {
			$option = $xml->command[0]->addChild('option');
			$option['value'] = $key . "=" . $value;
		}
		return $xml->asXml();
	}
	
	private function request($xml)
	{
		$context = stream_context_create(array( 
			'http' => array( 
		      	'method'  => 'POST', 
		      	'header'  => "Content-type: text/xml\r\n", 
		      	'content' => $xml, 
		      	'timeout' => 5, 
		    ), 
		)); 
		$ret = file_get_contents($this->SENDANOR_URL, false, $context);
		$xml = new SimpleXMLElement($ret);
		if (isset($xml['session_id'])) {
			$this->session_id = (string)$xml['session_id'];
		}
		else {
			$this->session_id = '';
		}
		$errorMsgs = $xml->xpath("message[@type='error']");
		if (count($errorMsgs)) {
			$msgs  = array();
			foreach($errorMsgs as $msg) {
				$msgs[] = (string)$msg['subject'];
			}
			throw new NorRequestUnsuccessfullException($msgs);
		}
		return $xml;
	}
	
	private function parseRecord($xml)
	{
 		//var_dump($xml);
		//exit;
		if (is_array($xml) && count($xml) != 0) {
			$xml = $xml[0];
		}
		elseif (is_array($xml)) {
			throw new NorException("Could not parse record");
		}
		//echo "<pre>";
		//echo htmlspecialchars($xml->asXml());
		//exit;
		switch ((string)$xml['type']) {
			case "array":
				$ret = array();
				foreach ($xml->children() as $node) {
					$ret[] = $this->parseRecord($node);
				}
				return $ret;
			case "object":
				$ret = array();
				foreach ($xml->children() as $node) {
					$nodeArray = $this->parseRecord($node);
					$ret[$nodeArray['key']] = $nodeArray['value']; 
				}
				return $ret;
			case "string":
				return array('key' => (string)$xml['name'], 'value' => (string)$xml['value']);
		}
	}
}

class NorException extends Exception  { }

class NorRequestUnsuccessfullException extends NorException {
	private $messages = array();
	public function __construct(array $msgs) {
		parent::__construct(implode("\n", $msgs));
		$this->messages = $msgs;
	}
	
	public function getMessages()
	{
		return $this->messages;
	}
}
?>
