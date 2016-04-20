<?php

namespace Magister6;

class Magister {

	//Variables
	public $url = '';			//Magister 6 url of school, found by selecting a school from $magister->findSchool('name')
	public $user = '';			//Magister 6 username provided by user
	public $pass = '';			//Magister 6 password provided by user
	public $cookieJar = '';		//Used to store file inside variable and destroy files to keep tmp directory empty
	public $magisterId = '';	//Magister 6 username provided by API server
	public $magisterParentId = '';
	public $stamnummer = '';
	public $studyId = '';		//Current study the student is following, needed for things like grades
	public $isLoggedIn = false; //Easy check if the user is logged in
	public $isParent = false;

	public $apiVersion = '';
	private $session_cookie_string = '';	// Magister API v1.24+ require session id set as as a cookie in login POSR request.

	//Request storage variables
	public $profile;
	public $group;

	private function curlget($url, $return_headers = false){
		$cookiefile = tempnam(sys_get_temp_dir(), uniqid());
		touch($cookiefile);

		if(!empty($this->cookieJar)){
			file_put_contents($cookiefile, $this->cookieJar);
		}

		$referer=parse_url($url);
		if($referer){
			$referer=$referer["scheme"]."://".$referer["host"];
		}

		$ch=curl_init();
		curl_setopt($ch,CURLOPT_URL,$url);
		curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,FALSE);
		curl_setopt($ch,CURLOPT_USERAGENT,"Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.6) Gecko/20070725 Firefox/2.0.0.6");
		curl_setopt($ch,CURLOPT_TIMEOUT,60);
		//curl_setopt($ch,CURLOPT_FOLLOWLOCATION,1);
		curl_setopt($ch,CURLOPT_COOKIEJAR,$cookiefile);
		curl_setopt($ch,CURLOPT_COOKIEFILE,$cookiefile);
		curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
		curl_setopt($ch,CURLOPT_REFERER,$referer);
		curl_setopt($ch,CURLOPT_HEADER,$return_headers);

		$result=curl_exec($ch);

		$this->cookieJar = file_get_contents($cookiefile);

		unlink($cookiefile);

		return $result;
	}

	private function curlpost($url, $post = null){
		$cookiefile = tempnam(sys_get_temp_dir(), uniqid());
		touch($cookiefile);

 		if(!empty($this->cookieJar)){
			file_put_contents($cookiefile, $this->cookieJar);
		}

		$post = json_encode($post, true);

		$ch=curl_init();
		curl_setopt($ch,CURLOPT_URL,$url);
		curl_setopt($ch,CURLOPT_USERAGENT,"Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.6) Gecko/20070725 Firefox/2.0.0.6");
		curl_setopt($ch,CURLOPT_TIMEOUT,5);
		//curl_setopt($ch,CURLOPT_FOLLOWLOCATION,1);
		curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
		curl_setopt($ch,CURLOPT_COOKIEJAR,$cookiefile);
		curl_setopt($ch,CURLOPT_COOKIEFILE,$cookiefile);
		curl_setopt($ch,CURLOPT_REFERER,$this->url);
		curl_setopt($ch,CURLOPT_POSTFIELDS,$post);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
		curl_setopt($ch, CURLOPT_VERBOSE, 1);
		curl_setopt($ch, CURLOPT_HEADER, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'charset=UTF-8'));

		// Since Magister v1.24+, we need to set a cookie in the POST request.
		curl_setopt($ch, CURLOPT_COOKIE, self::getSessionCookieString());

		$result=curl_exec($ch);

		if(curl_errno($ch))
		{
		    echo 'error:' . curl_error($ch);
		}

		$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		$header = substr($result, 0, $header_size);
		$body = substr($result, $header_size);

		curl_close($ch);

		$this->cookieJar = file_get_contents($cookiefile);

		unlink($cookiefile);

		return true;
	}


	/**
	 * Returns array of HTTP request headers.
	 *
	 * @param $response string HTTP response.
	 * @return array Returns array of HTTP request headers.
     */
	private function get_request_header($response) {
		$headers = [];

		list($headers_raw, $content) = explode("\r\n\r\n",$response, 2);

		foreach (explode("\r\n", $headers_raw) as $hdr) {
			if (strpos($hdr, ':')) {
				list($key, $value) = explode(":", $hdr, 2);
				$headers[$key] = trim($value);
			}
		}

		return $headers;
	}


	/**
	 * Returns a Magister session ID cookie string.
	 *
	 * @return string Magister session ID cookie string.
     */
	private function getSessionCookieString() {
		if (empty($this->session_cookie_string) && ($this->apiVersion[0] == 2 || ($this->apiVersion[0] == 1 && $this->apiVersion[1] >= 24))) {
			// Before we can log in, we first need to get a session id.
			// We can get it by performing a GET request.
			$currentSessionUrl = $this->url.'api/sessies/huidige';

			// Only get the headers of the request.
			$raw_headers = self::curlget($currentSessionUrl, true);

			// And process the raw headers into an associative array.
			$headers = self::get_request_header($raw_headers);

			// The session ID is stored in the header 'Set-Cookie'. We just
			// need to return the 'Set-Cookie' header if it exists.
			$this->session_cookie_string = isset($headers['Set-Cookie']) ? $headers['Set-Cookie'] : '';
		}

		return $this->session_cookie_string;
	}

	private function boolToString($bool){
		if($bool == true){
			return 'true';
		}else if($bool == false){
			return 'false';
		}else{
			return false;
		}
	}

	private function isParent() {
		$this->isParent = false;

		for($g = 0; $g < count($this->group); $g++) {
			if($this->group[$g]->Naam == 'Ouder') {
				$this->isParent = true;
			}
		}

		return $this->isParent;
	}

	function __construct($school = false, $user = false, $pass = false, $autoLogin = false, $stamnummer = false){
		if($school !== false){
			self::setSchool($school);
		}

		if($user !== false && $pass !== false){
			self::setCredentials($user, $pass);
		}

		if($stamnummer !== false) {
			self::setStamnummer($stamnummer);
		}

		// Set API version.
		$magister_info = self::getMagisterInfo();
		$this->apiVersion = explode(".", $magister_info->ApiVersie);

		if($autoLogin){
			self::login();
		}
	}

	function getMagisterInfo(){
		if(empty($this->url)){
			return false;
		}else{
			return json_decode(self::curlget($this->url.'api/versie'));
		}
	}

	function getUserInfo(){
		if(empty($this->profile)){
			return false;
		}else{
			return $this->profile;
		}
	}

	function getGroupInfo(){
		if(empty($this->group)){
			return false;
		}else{
			return $this->group;
		}
	}

	function getPrivilegeAccessTypes($privilege_name) {
		for($p = 0; $p < count($this->group[0]->Privileges); $p++) {
			if($this->group[0]->Privileges[$p]->Naam == $privilege_name) {
				return $this->group[0]->Privileges[$p]->AccessType;
			}
		}
	}

	function hasPrivilegeAccessType($privilege_name, $access_type) {
		return in_array($access_type, $this->getPrivilegeAccessTypes($privilege_name));
	}

	function findSchool($string){
		if(empty($string)){
			return false;
		}else{
			return json_decode(self::curlget("https://mijn.magister.net/api/schools?filter=$string"));
		}
	}

	function setSchool($url){
		if(empty($url)){
			return false;
		}else{
			//Url flexibility
			if(substr($url, -1, 1) !== "/"){
				$url = $url."/";
			}
			if(substr($url, 0, 7) !== "https://" && substr($url, 0, 6) !== "http://"){
				$url = "https://".$url;
			}

			$this->url = $url;
			return true;
		}
	}

	function setCredentials($user, $pass){
		if(empty($user) || empty($pass)){
			return false;
		}else{
			$this->user = $user;
			$this->pass = $pass;
			return true;
		}
	}

	function setStamnummer($stamnummer){
		if(empty($stamnummer)){
			return false;
		}else{
			$this->stamnummer = $stamnummer;
			return true;
		}
	}

	function login(){
		if(empty($this->user) || empty($this->pass) || empty($this->url)){
			return false;
		}else{
			if ($this->apiVersion[0] == 1 && $this->apiVersion[1] < 24) {
				$loginUrl = $this->url.'api/sessie';
			}
			else {
				$loginUrl = $this->url.'api/sessies';
			}

			$result = self::curlpost($loginUrl, array('Gebruikersnaam' => $this->user, 'Wachtwoord' => $this->pass, "IngelogdBlijven" => true, "GebruikersnaamOnthouden" => true));

			$accountUrl = $this->url.'api/account';
			$account = json_decode(self::curlget($accountUrl));

			if(!empty($account->Fouttype)){
				if($account->Fouttype == "OngeldigeSessieStatus"){
					throw new \Exception('Magister6.class.php: Ongeldige Sessie, check credentials.');
					break;
				}
			}

			$this->magisterId = $account->Persoon->Id;

			$this->profile = $account->Persoon;
			$this->group = $account->Groep;

 			$this->isLoggedIn = true;

			if($this->isParent() == true) {
				// store magister ID of parent account;
				$this->magisterParentId = $this->magisterId;

				$result = $this->getChildren();

				if($result->TotalCount > 0 && $this->stamnummer != null) {
					foreach($result->Items as $child) {
						if($child->Stamnummer == $this->stamnummer) {
							$this->magisterId = $child->Id;
						}
					}
				}
			}

			//get current study
			$result = json_decode(self::curlget($this->url.'api/personen/'.$this->magisterId.'/aanmeldingen?geenToekomstige=true&peildatum='.date("Y-m-d")));

			$now = new \DateTime();

			foreach($result->Items as $item){
				if(new \DateTime($item->Einde) > $now){
					$this->studyId = $item->Id;
				}
			}

			return true;
		}
	}

	function getChildren(){
		if(empty($this->magisterParentId) || empty($this->url) || $this->isLoggedIn == false){
			return false;
		}else{
			return json_decode(self::curlget($this->url.'api/personen/'.$this->magisterParentId.'/kinderen'));
		}
	}

	function getAppointments($datefrom, $dateto, $wijzigingen = false){
		if(empty($this->magisterId) || empty($this->url) || $this->isLoggedIn == false || empty($datefrom) || empty($dateto)){
			return false;
		}else{
			return json_decode(self::curlget($this->url.'api/personen/'.$this->magisterId.'/afspraken?tot='.$dateto.'&van='.$datefrom));
		}
	}

	function getHomework($datefrom, $dateto){
		if(empty($this->magisterId) || empty($this->url) || $this->isLoggedIn == false || empty($datefrom) || empty($dateto)){
			return false;
		}else{
			$data = json_decode(self::curlget($this->url.'api/personen/'.$this->magisterId.'/afspraken?tot='.$dateto.'&van='.$datefrom));
			$return;
			$return->Items = array();
			$count = 0;
			foreach($data as $items){
				if(is_array($items)){
					foreach($items as $item){
						if(!empty($item->Inhoud)){
							$return->Items[$count] = $item;
							$count++;
						}
					}
				}
			}
			$return->TotalCount = $count;
			$return->Links = array();

			return $return;
		}
	}

	function getSubjects(){
		if(empty($this->magisterId) || empty($this->url) || $this->isLoggedIn == false || empty($this->studyId)){
			return false;
		}else{
			$data = json_decode(self::curlget($this->url.'api/personen/'.$this->magisterId.'/aanmeldingen/'.$this->studyId.'/vakken'));
			return $data;
		}
	}

	function getTeacherInfo($afkorting){
		if(empty($this->magisterId) || empty($this->url) || $this->isLoggedIn == false || empty($afkorting)){
			return false;
		}else{
			$data = json_decode(self::curlget($this->url.'api/personen/'.$this->magisterId.'/contactpersonen?contactPersoonType=Docent&q='.$afkorting));
			return $data;
		}
	}

	function getContact($search){
		if(empty($this->magisterId) || empty($this->url) || $this->isLoggedIn == false || empty($search)){
			return false;
		}else{
			$data = json_decode(self::curlget($this->url.'api/personen/'.$this->magisterId.'/contactpersonen?contactPersoonType=Leerling&q='.$search));
			return $data;
		}
	}

	function getGrades($vak = false, $actievePerioden = true, $alleenBerekendeKolommen = false, $alleenPTAKolommen = false, $studyId = false){
    	$this->studyId = ($studyId == false ? $this->studyId : $studyId);

		if(empty($this->magisterId) || empty($this->url) || $this->isLoggedIn == false || empty($this->studyId)){
			return false;
		}else{
			$actievePerioden = self::boolToString($actievePerioden);
			$alleenBerekendeKolommen = self::boolToString($alleenBerekendeKolommen);
			$alleenPTAKolommen = self::boolToString($alleenPTAKolommen);
			$data = json_decode(self::curlget($this->url.'api/personen/'.$this->magisterId.'/aanmeldingen/'.$this->studyId.'/cijfers/cijferoverzichtvooraanmelding?actievePerioden='.$actievePerioden.'&alleenBerekendeKolommen='.$alleenBerekendeKolommen.'&alleenPTAKolommen='.$alleenPTAKolommen));
			if($vak == false){
				return $data;
			}else{
				$return->TotalCount = 0;
				$return->Links = array();
				$return->Items = array();
				foreach($data as $items){
					if(is_array($items)){
						$count = 0;
						foreach($items as $item){
							if($item->Vak->Afkorting == $vak){
								$return->Items[$count] = $item;
								$count++;
							}
						}
					}
				}
				return $return;
			}
		}
	}

	function getAanmeldingen($geenToekomstige = true, $peilDatum = false) {
		if(empty($this->magisterId) || empty($this->url) || $this->isLoggedIn == false){
			return false;
		}else{
			$peilDatum = $peilDatum == false ? date("Y-m-d") : $peilDatum;
			$data = json_decode(self::curlget($this->url.'api/personen/'.$this->magisterId.'/aanmeldingen?geenToekomstige='.$geenToekomstige.'&peildatum='.$peilDatum));
			return $data;
		}
	}

	function getCijferPerioden() {
		if(empty($this->magisterId) || empty($this->url) || $this->isLoggedIn == false || empty($this->studyId)){
			return false;
		}else{
			$data = json_decode(self::curlget($this->url.'api/personen/'.$this->magisterId.'/aanmeldingen/'.$this->studyId.'/cijfers/cijferperiodenvooraanmelding'));
			return $data;
		}
    	}

	function getExtraCijferKolomInfo($cijferKolomId) {
		if(empty($this->magisterId) || empty($this->url) || $this->isLoggedIn == false || empty($this->studyId)){
			return false;
		}else{
			$data = json_decode(self::curlget($this->url.'api/personen/'.$this->magisterId.'/aanmeldingen/'.$this->studyId.'/cijfers/extracijferkolominfo/'.$cijferKolomId));
			return $data;
		}
	}

	function getProfileImage(){
		if(empty($this->magisterId) || empty($this->url) || $this->isLoggedIn == false){
			return false;
		}else{
			return self::curlget($this->url.'api/personen/'.$this->magisterId.'/foto?width=340&height=420');
		}
	}

}
?>
