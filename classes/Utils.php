<?php

use Mailgun\Mailgun;

class Utils
{
	/**
	 * Returns a valid Apretaste email to send an email
	 *
	 * @author salvipascual
	 * @return String, email address
	 */
	public function getValidEmailAddress()
	{
		$connection = new Connection();
		$sql = "SELECT email FROM jumper WHERE status='SendReceive' OR status='SendOnly' ORDER BY sent_count ASC LIMIT 1";
		$result = $connection->deepQuery($sql);
		return $result[0]->email;
	}


	/**
	 * Format a link to be an Apretaste mailto
	 *
	 * @author salvipascual
	 * @param String , name of the service
	 * @param String , name of the subservice, if needed
	 * @param String , pharse to search, if needed
	 * @param String , body of the email, if necessary
	 * @return String, link to add to the href section
	 */
	public function getLinkToService($service, $subservice=false, $parameter=false, $body=false)
	{
		$link = "mailto:".$this->getValidEmailAddress()."?subject=".strtoupper($service);
		if ($subservice) $link .= " $subservice";
		if ($parameter) $link .= " $parameter";
		if ($body) $link .= "&body=$body";
		return $link;
	}


	/**
	 * Check if the service exists in the database
	 *
	 * @author salvipascual
	 * @param String, name of the service
	 * @return Boolean, true if service exist
	 * */
	public function serviceExist($serviceName)
	{
		$connection = new Connection();
		$res = $connection->deepQuery("SELECT name FROM service WHERE LOWER(name)=LOWER('$serviceName')");
		return count($res) > 0;
	}


	/**
	 * Check if the Person exists in the database
	 * 
	 * @author salvipascual
	 * @param String $personEmail, email of the person
	 * @return Boolean, true if Person exist
	 * */
	public function personExist($personEmail)
	{
		$connection = new Connection();
		$res = $connection->deepQuery("SELECT email FROM person WHERE LOWER(email)=LOWER('$personEmail')");
		return count($res) > 0;
	}


	/**
	 * Check if the Person was invited and is still pending 
	 *
	 * @author salvipascual
	 * @param String $personEmail, email of the person
	 * @return Boolean, true if Person invitation is pending
	 * */
	public function checkPendingInvitation($email)
	{
		$connection = new Connection();
		$res = $connection->deepQuery("SELECT * FROM invitations WHERE email_invited='$email' AND used=0");
		return count($res) > 0;
	}


	/**
	 * Get a person's profile
	 *
	 * @author salvipascual
	 * @return Array or false
	 * */
	public function getPerson($email)
	{
		// get the person
		$connection = new Connection();
		$person = $connection->deepQuery("SELECT * FROM person WHERE email = '$email'");

		// return false if there is no person with that email
		if (count($person)==0) return false;
		else $person = $person[0];

		// get number of tickets for the raffle adquired by the user
		$tickets = $connection->deepQuery("SELECT count(*) as tickets FROM ticket WHERE raffle_id is NULL AND email = '$email'");
		$tickets = $tickets[0]->tickets;

		// get the person's full name
		$fullName = "{$person->first_name} {$person->middle_name} {$person->last_name} {$person->mother_name}";
		$fullName = trim(preg_replace("/\s+/", " ", $fullName));

		// get the image of the person
		$di = \Phalcon\DI\FactoryDefault::getDefault();
		$wwwroot = $di->get('path')['root'];
		$image = "$wwwroot/public/profile/$email.png";

		if(file_exists($image))
		{
			$wwwpath = $di->get('path')['http'];
			$image = "$wwwpath/profile/$email.png";
		}
		else $image = NULL;

		// get the interests as an array
		$person->interests = $exploded = preg_split('@,@', $person->interests, NULL, PREG_SPLIT_NO_EMPTY);

		// add elements to the response
		$person->full_name = $fullName;
		$person->picture = $image;
		$person->raffle_tickets = $tickets;
		return $person;
	}


	/**
	 * Get the path to a service. 
	 * 
	 * @author salvipascual
	 * @param String $serviceName, name of the service to access
	 * @return String, path to the service, or false if the service do not exist
	 * */
	public function getPathToService($serviceName)
	{
		// get the path to service 
		$di = \Phalcon\DI\FactoryDefault::getDefault();
		$wwwroot = $di->get('path')['root'];
		$path = "$wwwroot/services/$serviceName";

		// check if the path exist and return it
		if(file_exists($path)) return $path;
		else return false;
	}


	/**
	 * Return the current Raffle or false if no Raffle was found
	 * 
	 * @author salvipascual
	 * @return Array or false
	 * */
	public function getCurrentRaffle()
	{
		// get the raffle
		$connection = new Connection();
		$raffle = $connection->deepQuery("SELECT * FROM raffle WHERE CURRENT_TIMESTAMP BETWEEN start_date AND end_date");

		// return false if there is no open raffle
		if (count($raffle)==0) return false;
		else $raffle = $raffle[0];

		// get number of tickets opened
		$openedTickets = $connection->deepQuery("SELECT count(*) as opened_tickets FROM ticket WHERE raffle_id is NULL");
		$openedTickets = $openedTickets[0]->opened_tickets;

		// get the image of the raffle
		$di = \Phalcon\DI\FactoryDefault::getDefault();
		$wwwroot = $di->get('path')['root'];
		$raffleImage = "$wwwroot/public/raffle/" . md5($raffle->raffle_id) . ".png";

		// add elements to the response
		$raffle->tickets = $openedTickets;
		$raffle->image = $raffleImage;

		return $raffle;
	}


	/**
	 * Generate a new random hash. Mostly to be used for temporals
	 *
	 * @author salvipascual
	 * @return String
	 */
	public function generateRandomHash()
	{
		$rand = rand(0, 1000000);
		$today = date('full');
		return md5($rand . $today);
	}


	/**
	 * Reduce image size and optimize the image quality
	 * 
	 * @TODO Find an faster image optimization solution
	 * @author salvipascual
	 * @param String $imagePath, path to the image
	 * */
	public function optimizeImage($imagePath, $width=false, $height=false)
	{
		\Tinify\setKey("XdzvHGYdXUpiWB_fWI2muKXgV3GZVXjq");

		// load and optimize image
		$source = \Tinify\fromFile($imagePath);

		// scale image based on width
		if($width && ! $height)
		{
			$source = $source->resize(array("method" => "scale", "width" => $width));
		}

		// scale image based on width
		if( ! $width && $height)
		{
			$source = $source->resize(array("method" => "scale", "height" => $height));
		}

		if($width && $height)
		{
			$source = $source->resize(array("method" => "cover", "width" => $width, "height" => $height));
		}

		// save the optimized file
		$source->toFile($imagePath);
	}


	/**
	 * Add a new subscriber to the email list in Mail Lite
	 * 
	 * @author salvipascual
	 * @param String email
	 * */
	public function subscribeToEmailList($email)
	{
		// get the path to the www folder
		$di = \Phalcon\DI\FactoryDefault::getDefault();
		$wwwroot = $di->get('path')['root'];

		// get the key from the config
		$mailerLiteKey = $di->get('config')['mailerlite']['key'];

		// adding the new subscriber to the list
		include_once "$wwwroot/lib/mailerlite-api-php-v1/ML_Subscribers.php";
		$ML_Subscribers = new ML_Subscribers($mailerLiteKey);
		$subscriber = array('email' => $email, 'resubscribe' => 1);
		$ML_Subscribers->setId("1266487")->add($subscriber);
	}


	/**
	 * Delete a subscriber from the email list in Mail Lite
	 * 
	 * @author salvipascual
	 * @param String email
	 * */
	public function unsubscribeFromEmailList($email)
	{
		// get the path to the www folder
		$di = \Phalcon\DI\FactoryDefault::getDefault();
		$wwwroot = $di->get('path')['root'];

		// get the key from the config
		$mailerLiteKey = $di->get('config')['mailerlite']['key'];

		// adding the new subscriber to the list
		include_once "$wwwroot/lib/mailerlite-api-php-v1/ML_Subscribers.php";
		$ML_Subscribers = new ML_Subscribers($mailerLiteKey);		
		$ML_Subscribers->setId("1266487")->remove($email);
	}


	/**
	 * Get the pieces of names from the full name
	 *
	 * @author hcarras
	 * @param String $name, full name
	 * @return Array [$firstName, $middleName, $lastName, $motherName]
	 * */
	public function fullNameToNamePieces($name)
	{
		$namePieces = explode(" ", $name);
		$newNamePieces = array();
		$tmp = "";

		foreach ($namePieces as $piece)
		{
			$tmp .= "$piece ";
		
			if(in_array(strtoupper($piece), array("DE","LA","Y","DEL")))
			{
				continue;
			}
			else
			{
				$newNamePieces[] = $tmp;
				$tmp = "";
			}
		}

		$firstName = "";
		$middleName = "";
		$lastName = "";
		$motherName = "";

		if(count($newNamePieces)>=4)
		{
			$firstName = $newNamePieces[0];
			$middleName = $newNamePieces[1];
			$lastName = $newNamePieces[2];
			$motherName = $newNamePieces[3];
		}

		if(count($newNamePieces)==3)
		{
			$firstName = $newNamePieces[0];
			$lastName = $newNamePieces[1];
			$motherName = $newNamePieces[2];
		}

		if(count($newNamePieces)==2)
		{
			$firstName = $newNamePieces[0];
			$lastName = $newNamePieces[1];
		}

		if(count($newNamePieces)==1)
		{
			$firstName = $newNamePieces[0];
		}

		return array($firstName, $middleName, $lastName, $motherName);
	}


	/**
	 * Checks if an email can be delivered to a certain mailbox
	 *
	 * @author salvipascual
	 * @param String $to, email address of the receiver
	 * @param Enum $direction, in or out, if we check an email received or sent
	 * @return String delivability: ok, hard-bounce, soft-bounce, spam, no-reply, loop, unknown
	 * */
	public function deliveryStatus($to, $direction="out")
	{
		// save the final response. If not ok, will return on the LogErrorAndReturn tag
		$response = '';

		// create a new connection object
		$connection = new Connection();

		// block people following the example email
		if($to == "su@amigo.cu") {$response = 'hard-bounce'; goto LogErrorAndReturn;}

		// check if the email is formatted properly
		if ( ! filter_var($to, FILTER_VALIDATE_EMAIL)) {$response = 'hard-bounce'; goto LogErrorAndReturn;}

		// block intents to email the deamons
		if(stripos($to,"mailer-daemon@")!==false || stripos($to,"communicationservice.nl")!==false) {$response = 'hard-bounce'; goto LogErrorAndReturn;}

		// block no reply emails
		if(stripos($to,"not-reply")!==false ||
			stripos($to,"notreply")!==false ||
			stripos($to,"No_Reply")!==false ||
			stripos($to,"Do_Not_Reply")!==false ||
			stripos($to,"no-reply")!==false ||
			stripos($to,"noreply")!==false ||
			stripos($to,"no-responder")!==false ||
			stripos($to,"noresponder")!==false
		) {$response = 'no-reply'; goto LogErrorAndReturn;}

		// block any previouly dropped email
		$res = $connection->deepQuery("SELECT email FROM delivery_dropped WHERE email='$to'");
		if( ! empty($res)) {$response = 'loop'; goto LogErrorAndReturn;}

		// block emails from apretaste to apretaste
		$mailboxes = $connection->deepQuery("SELECT email FROM jumper");
		foreach($mailboxes as $m) if($to == $m->email) {$response = 'loop'; goto LogErrorAndReturn;}

		// check for valid domain
		$mgClient = new Mailgun("pubkey-5ogiflzbnjrljiky49qxsiozqef5jxp7");
		$result = $mgClient->get("address/validate", array('address' => $to));
		if( ! $result->http_response_body->is_valid) {$response = 'hard-bounce'; goto LogErrorAndReturn;}

		// check NEW emails deeper (only for new people)
		if( ! $this->personExist($to))
		{
			$di = \Phalcon\DI\FactoryDefault::getDefault();
			$key = $di->get('config')['emailvalidator']['key'];
			$result = json_decode(@file_get_contents("https://api.email-validator.net/api/verify?EmailAddress=$to&APIKey=$key"));
			if($result)
			{
				// save all emails tested by the email validador to ensure no errors are happening
				$status = $result->status;
				$connection->deepQuery("INSERT INTO ___emailvalidator_checked_emails (email,status) VALUES ('$to','$status')");

				// get the result for each status code
				if($status == 114) {$response = 'unknown'; goto LogErrorAndReturn;} // usually national email
				if($status > 300 && $status < 399) {$response = 'soft-bounce'; goto LogErrorAndReturn;}
				if($status > 400 && $status < 499) {$response = 'hard-bounce'; goto LogErrorAndReturn;}
			}
			else
			{
				throw new Exception("Error connecting emailvalidator for user $to at ".date());
			}
		}

		// when no errors were found
		return 'ok';

		// log errors in the database before returning
		// and YES, I am using GOTO
		LogErrorAndReturn:
		$connection->deepQuery("INSERT INTO delivery_dropped(email,reason,description) VALUES ('$to','$response','$direction')");
		return $response;
	}
}
