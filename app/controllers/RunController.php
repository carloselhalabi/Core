<?php

use Phalcon\Mvc\Controller;

class RunController extends Controller
{
	public function indexAction()
	{
		echo "Cannot run directly. Please access to run/display or run/api instead";
	}

	/**
	 * Executes an html request the outside. Display the HTML on screen
	 * 
	 * @author salvipascual
	 * @get String $subject, subject line of the email
	 * @get String $body, body of the email
	 * */
	public function displayAction()
	{
		$subject = $this->request->get("subject");
		$body = $this->request->get("body");

		$result = $this->renderResponse("html@apretaste.com", $subject, "HTML", $body, array(), "html");
		if($this->di->get('environment') == "sandbox") $result .= '<div style="color:white; background-color:red; font-weight:bold; display:inline-block; padding:5px; position:absolute; top:10px; right:10px; z-index:99">SANDBOX</div>';
		echo $result;
	}

	/**
	 * Executes an API request. Display the JSON on screen
	 * 
	 * @author salvipascual
	 * @get String $subject, subject line of the email
	 * @get String $body, body of the email
	 * */
	public function apiAction()
	{
		$subject = $this->request->get("subject");
		$body = $this->request->get("body");
		$email = $this->request->get("email");
		$attachments = $this->request->get("attachments");
		if(empty($email)) $email = "api@apretaste.com";

		// create attachment as an object
		$attach = array();
		if ( ! empty($attachments))
		{
			// save image into the filesystem
			$wwwroot = $this->di->get('path')['root'];
			$utils = new Utils();
			$filePath = "$wwwroot/temp/".$utils->generateRandomHash().".jpg";
			$content = file_get_contents($attachments);
			imagejpeg(imagecreatefromstring($content), $filePath);

			// optimize the image and grant full permits
			$utils->optimizeImage($filePath);
			chmod($filePath, 0777);

			// create new object
			$object = new stdClass();
			$object->path = $filePath;
			$object->type = image_type_to_mime_type(IMAGETYPE_JPEG);
			$attach = array($object);
		}

		// update last access time to current and set remarketing
		$connection = new Connection();
		$connection->deepQuery("
			START TRANSACTION;
			UPDATE person SET last_access=CURRENT_TIMESTAMP WHERE email='$email';
			UPDATE remarketing SET opened=CURRENT_TIMESTAMP WHERE opened IS NULL AND email='$email';
			COMMIT;");

		// some services cannot be called using the API
		$service = strtoupper(explode(" ", $subject)[0]);
		if ($service == 'EXCLUYEME')
		{
			die("You cannot execute service $service from the API");
		}

		$result = $this->renderResponse($email, $subject, "API", $body, $attach, "json");

		// allow JS clients to use the API
		header("Access-Control-Allow-Origin: *");
		echo $result;
	}

	/**
	 * Receives email from the MailGun webhook and send it to be parsed 
	 * 
	 * @author salvipascual
	 * @post Multiple Values
	 * */
	public function mailgunAction()
	{
		// do not allow fake income messages 
		if( ! isset($_POST['From'])) return;

		// filter email From and To 
		$pattern = "/(?:[a-z0-9!#$%&'*+=?^_`{|}~-]+(?:\.[a-z0-9!#$%&'*+=?^_`{|}~-]+)*|\"(?:[\x01-\x08\x0b\x0c\x0e-\x1f\x21\x23-\x5b\x5d-\x7f]|\\[\x01-\x09\x0b\x0c\x0e-\x7f])*\")@(?:(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]*[a-z0-9])?|\[(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?|[a-z0-9-]*[a-z0-9]:(?:[\x01-\x08\x0b\x0c\x0e-\x1f\x21-\x5a\x53-\x7f]|\\[\x01-\x09\x0b\x0c\x0e-\x7f])+)\])/";
		preg_match_all($pattern, $_POST['From'], $emailFrom);
		if(isset($_POST['To'])) preg_match_all($pattern, $_POST['To'], $toFrom);

		// get values to the variables
		$fromEmail = $emailFrom[0][0];
		$fromName = trim(explode("<", $_POST['From'])[0]);
		$toEmail = isset($toFrom[0][0]) ? trim($toFrom[0][0], " \t\n\r\0\x0B\"\',") : "";
		$subject = $_POST['subject'];
		$body = isset($_POST['body-plain']) ? $_POST['body-plain'] : "";
		$attachmentCount = isset($_POST['attachment-count']) ? $_POST['attachment-count'] : 0;
		$messageID = isset($_POST['Message-ID']) ? $_POST['Message-ID'] : null;
		
		// save the attached files and create the response array
		$attachments = array();
		for ($i=1; $i<=$attachmentCount; $i++)
		{
			$object = new stdClass();
			$object->name = $_FILES["attachment-$i"]["name"];
			$object->type = $_FILES["attachment-$i"]["type"];
			$object->content = base64_encode(file_get_contents($_FILES["attachment-$i"]["tmp_name"]));
			$object->path = "";
			$attachments[] = $object;
		}

		// save the webhook log
		$wwwroot = $this->di->get('path')['root'];
		$logger = new \Phalcon\Logger\Adapter\File("$wwwroot/logs/mailgun.log");
		$logger->log("From:$fromEmail, To:$toEmail, Subject:$subject\n".print_r($_POST, true)."\n\n");
		$logger->close();

		// execute the webbook
		$this->processEmail($fromEmail, $fromName, $toEmail, $subject, $body, $attachments, "mailgun", $messageID);
	}

	/**
	 * Process the requests coming by email, usually from webhooks
	 * 
	 * @author salvipascual
	 * @param String Email
	 * @param String
	 * @param String Email
	 * @param String
	 * @param String
	 * @param Array
	 * @param Enum mandrill,mailgun
	 * @param String
	 * @param String $messageID
	 * */
	private function processEmail($fromEmail, $fromName, $toEmail, $subject, $body, $attachments, $webhook, $messageID = null)
	{
		$connection = new Connection();
		$utils = new Utils();

		// do not continue procesing the email if the sender is not valid
		$status = $utils->deliveryStatus($fromEmail, 'in');
		if($status != 'ok') return;

		// remove double spaces and apostrophes from the subject
		// sorry apostrophes break the SQL code :-(
		$subject = trim(preg_replace('/\s{2,}/', " ", preg_replace('/\'|`/', "", $subject)));

		// save the email as received
		$connection->deepQuery("INSERT INTO delivery_received(user,mailbox,subject,attachments_count,webhook) VALUES ('$fromEmail','$toEmail','$subject','".count($attachments)."','$webhook')");

		// save to the webhook last usage, to alert if the web
		$connection->deepQuery("UPDATE task_status SET executed=CURRENT_TIMESTAMP WHERE task='$webhook'");

		// if there are attachments, download them all and create the files in the temp folder 
		$wwwroot = $this->di->get('path')['root'];
		foreach ($attachments as $attach)
		{
			$mimeTypePieces = explode("/", $attach->type);
			$fileType = $mimeTypePieces[0];
			$fileNameNoExtension = $utils->generateRandomHash();

			// convert images to jpg and save it to temporal
			if($fileType == "image")
			{
				$attach->type = image_type_to_mime_type(IMAGETYPE_JPEG);
				$filePath = "$wwwroot/temp/$fileNameNoExtension.jpg";
				imagejpeg(imagecreatefromstring(base64_decode($attach->content)), $filePath);
				$utils->optimizeImage($filePath);
			}
			else // save any other file to the temporals
			{
				$extension = $mimeTypePieces[1];
				$filePath = "$wwwroot/temp/$fileNameNoExtension.$extension";
				$ifp = fopen($filePath, "wb");
				fwrite($ifp, $attach->content);
				fclose($ifp);
			}

			// grant full access to the file
			chmod($filePath, 0777);
			$attach->path = $filePath;
		}

		// update the counter of emails received from that mailbox
		$today = date("Y-m-d H:i:s");
		$connection->deepQuery("UPDATE jumper SET received_count=received_count+1, last_usage='$today' WHERE email='$toEmail'");

		// save the webhook log
		$logger = new \Phalcon\Logger\Adapter\File("$wwwroot/logs/webhook.log");
		$logger->log("Webhook:$webhook, From:$fromEmail, To:$toEmail, Subject:$subject, Attachments:".count($attachments));
		$logger->close();

		// execute the query
		$this->renderResponse($fromEmail, $subject, $fromName, $body, $attachments, "email", $toEmail, $messageID);
	}

	/**
	 * Respond to a request based on the parameters passed
	 * 
	 * @author salvipascual
	 * @param String, email
	 * @param String
	 * @param String, email
	 * @param String
	 * @param Array of Objects {type,content,path}
	 * @param Enum: html,json,email
	 * @param String, email
	 * @param String $messageID
	 * */
	private function renderResponse($email, $subject, $sender="", $body="", $attachments=array(), $format="html", $source="", $messageID = null)
	{
		// get the time when the service started executing
		$execStartTime = date("Y-m-d H:i:s");

		// remove double spaces and apostrophes from the subject
		// sorry apostrophes break the SQL code :-( 
		$subject = trim(preg_replace('/\s{2,}/', " ", preg_replace('/\'|`/', "", $subject)));

		// get the name of the service based on the subject line
		$subjectPieces = explode(" ", $subject);
		$serviceName = strtolower($subjectPieces[0]);
		unset($subjectPieces[0]);

		// check the service requested actually exists
		$utils = new Utils();
		if( ! $utils->serviceExist($serviceName)) $serviceName = "ayuda";

		// include the service code
		$wwwroot = $this->di->get('path')['root'];
		include "$wwwroot/services/$serviceName/service.php";

		// check if a subservice is been invoked
		$subServiceName = "";
		if(isset($subjectPieces[1]) && ! preg_match('/\?|\(|\)|\\\|\/|\.|\$|\^|\{|\}|\||\!/', $subjectPieces[1]))
		{
			$serviceClassMethods = get_class_methods($serviceName);
			if(preg_grep("/^_{$subjectPieces[1]}$/i", $serviceClassMethods))
			{
				$subServiceName = strtolower($subjectPieces[1]);
				unset($subjectPieces[1]);
			}
		}

		// get the service query
		$query = implode(" ", $subjectPieces);

		// create a new Request object
		$request = new Request();
		$request->email = $email;
		$request->name = $sender;
		$request->subject = $subject;
		$request->body = $body;
		$request->attachments = $attachments;
		$request->service = $serviceName;
		$request->subservice = trim($subServiceName);
		$request->query = trim($query);

		// connect to the database
		$connection = new Connection();

		// get the path to the service
		$servicePath = $utils->getPathToService($serviceName);
		
		// get details of the service
		if($this->di->get('environment') == "sandbox")
		{
			// get details of the service from the XML file
			$xml = simplexml_load_file("$servicePath/config.xml");
			$serviceCreatorEmail = trim((String)$xml->creatorEmail);
			$serviceDescription = trim((String)$xml->serviceDescription);
			$serviceCategory = trim((String)$xml->serviceCategory);
			$serviceUsageText = trim((String)$xml->serviceUsage);
			$showAds = isset($xml->showAds) && $xml->showAds==0 ? 0 : 1;
			$serviceInsertionDate = date("Y/m/d H:m:s");
		}
		else
		{
			// get details of the service from the database
			$sql = "SELECT * FROM service WHERE name = '$serviceName'";
			$result = $connection->deepQuery($sql);

			$serviceCreatorEmail = $result[0]->creator_email;
			$serviceDescription = $result[0]->description;
			$serviceCategory = $result[0]->category;
			$serviceUsageText = $result[0]->usage_text;
			$serviceInsertionDate = $result[0]->insertion_date;
			$showAds = $result[0]->ads == 1; // @TODO run when deploying a service
		}

		// create a new service Object of the user type
		$userService = new $serviceName();
		$userService->serviceName = $serviceName;
		$userService->serviceDescription = $serviceDescription;
		$userService->creatorEmail = $serviceCreatorEmail;
		$userService->serviceCategory = $serviceCategory;
		$userService->serviceUsage = $serviceUsageText;
		$userService->insertionDate = $serviceInsertionDate;
		$userService->pathToService = $servicePath;
		$userService->showAds = $showAds;
		$userService->utils = $utils;

		// run the service and get a response
		if(empty($subServiceName))
		{
			$response = $userService->_main($request);
		}
		else
		{
			$subserviceFunction = "_$subServiceName";
			$response = $userService->$subserviceFunction($request);
		}

		// a service can return an array of Response or only one.
		// we always treat the response as an array
		$responses = is_array($response) ? $response : array($response);

		// clean the empty fields in the response  
		foreach($responses as $rs)
		{
			$rs->email = empty($rs->email) ? $email : $rs->email;
			$rs->subject = empty($rs->subject) ? "Respuesta del servicio $serviceName" : $rs->subject;
		}

		// create a new render
		$render = new Render();

		// render the template and echo on the screen
		if($format == "html")
		{
			$html = "";
			for ($i=0; $i<count($responses); $i++)
			{
				$html .= "<br/><center><small><b>To:</b> " . $responses[$i]->email . ". <b>Subject:</b> " . $responses[$i]->subject . "</small></center><br/>";
				$html .= $render->renderHTML($userService, $responses[$i]);
				if($i < count($responses)-1) $html .= "<br/><hr/><br/>";
			}

			$usage = nl2br(str_replace('{APRETASTE_EMAIL}', $utils->getValidEmailAddress(), $serviceUsageText));
			$html .= "<br/><hr><center><p><b>XML DEBUG</b></p><small>";
			$html .= "<p><b>Owner: </b>$serviceCreatorEmail</p>";
			$html .= "<p><b>Category: </b>$serviceCategory</p>";
			$html .= "<p><b>Description: </b>$serviceDescription</p>";
			$html .= "<p><b>Usage: </b><br/>$usage</p></small></center>";

			return $html;
		}

		// echo the json on the screen
		if($format == "json")
		{
			return $render->renderJSON($response);
		}

		// render the template email it to the user
		// only save stadistics for email requests
		if($format == "email")
		{
			// get the person, false if the person does not exist
			$person = $utils->getPerson($email);

			// if the person exist in Apretaste
			if ($person !== false)
			{
				// if the person is inactive and he/she is not trying to opt-out, re-subscribe him/her
				if( ! $person->active && $serviceName != "excluyeme") $utils->subscribeToEmailList($email);

				// update last access time to current and make person active
				$connection->deepQuery("UPDATE person SET active=1, last_access=CURRENT_TIMESTAMP WHERE email='$email'");
			}
			else // if the person accessed for the first time, insert him/her
			{
				$inviteSource = 'alone'; // alone if the user came by himself, no invitation
				$sql = "START TRANSACTION;"; // start the long query

				// check if the person was invited to Apretaste
				$invites = $connection->deepQuery("SELECT * FROM invitations WHERE email_invited='$email' AND used=0 ORDER BY invitation_time DESC");
				if(count($invites)>0)
				{
					// check how this user came to know Apretaste, for stadistics
					$inviteSource = $invites[0]->source;

					// give prizes to the invitations via service invitar
					// if more than one person invites X, they all get prizes
					foreach ($invites as $invite)
					{
						if($invite->source == "internal")
						{
							// assign tickets and credits
							$sql .= "INSERT INTO ticket (email, paid) VALUES ('{$invite->email_inviter}', 0);";
							$sql .= "UPDATE person SET credit=credit+0.25 WHERE email='{$invite->email_inviter}';";

							// email the invitor
							$newTicket = new Response();
							$newTicket->setResponseEmail($email);
							$newTicket->setEmailLayout("email_simple.tpl");
							$newTicket->setResponseSubject("Ha ganado un ticket para nuestra Rifa");
							$newTicket->createFromTemplate("invitationWonTicket.tpl", array("guest"=>$invite->email_invited));
							$newTicket->internal = true;
							$responses[] = $newTicket;
						}
					}

					// mark all opened invitations to that email as used
					$sql .= "UPDATE invitations SET used=1, used_time=CURRENT_TIMESTAMP WHERE email_invited='$email' AND used=0;";
				}

				// create a unique username and save the new person
				$username = $utils->usernameFromEmail($email);
				$sql .= "INSERT INTO person (email, username, last_access, source) VALUES ('$email', '$username', CURRENT_TIMESTAMP, '$inviteSource');";

				// save details of first visit
				$sql .= "INSERT INTO first_timers (email, source) VALUES ('$email', '$source');";

				// check list of sellers's emails 
				$promoters = $connection->deepQuery("SELECT email FROM jumper WHERE email='$source' AND promoter=1;");
				$prize = count($promoters)>0;
				if ($prize)
				{
					// add credit and tickets
					$sql .= "UPDATE person SET credit=credit+5, source='promoter' WHERE email='$email';";
					$sql .= "INSERT INTO ticket(email, paid) VALUES ";
					for ($i = 0; $i < 10; $i++) $sql .= "('$email', 0)".($i < 9 ? "," : ";");
				}

				// run the long query all at the same time
				$connection->deepQuery($sql."COMMIT;");

				// send the welcome email
				$welcome = new Response();
				$welcome->setResponseEmail($email);
				$welcome->setEmailLayout("email_simple.tpl");
				$welcome->setResponseSubject("Bienvenido a Apretaste!");
				$welcome->createFromTemplate("welcome.tpl", array("email"=>$email, "prize"=>$prize, "source"=>$source));
				$welcome->internal = true;
				$responses[] = $welcome;

				//  add to the email list in Mail Lite
				$utils->subscribeToEmailList($email);
			}

			// get params for the email and send the response emails
			$emailSender = new Email();
			foreach($responses as $rs)
			{
				if($rs->render) // ommit default Response()
				{
					// save impressions in the database
					$ads = $rs->getAds();
					if($userService->showAds && ! empty($ads))
					{
						$sql = "";
						if( ! empty($ads[0])) $sql .= "UPDATE ads SET impresions=impresions+1 WHERE id='{$ads[0]->id}';";
						if( ! empty($ads[1])) $sql .= "UPDATE ads SET impresions=impresions+1 WHERE id='{$ads[1]->id}';";
						$connection->deepQuery($sql);
					}

					// prepare the email variable
					$emailTo = $rs->email;
					$subject = $rs->subject;
					$images = $rs->images;
					$attachments = $rs->attachments;
					$body = $render->renderHTML($userService, $rs);

					// remove dangerous characters that may break the SQL code
					$subject = trim(preg_replace('/\'|`/', "", $subject));

					// send the response email
					$emailSender->sendEmail($emailTo, $subject, $body, $images, $attachments, $messageID);
				}
			}

			// saves the openning date if the person comes from remarketing
			$connection->deepQuery("UPDATE remarketing SET opened=CURRENT_TIMESTAMP WHERE opened IS NULL AND email='$email'");

			// calculate execution time when the service stopped executing
			$currentTime = new DateTime();
			$startedTime = new DateTime($execStartTime);
			$executionTime = $currentTime->diff($startedTime)->format('%H:%I:%S');

			// get the user email domainEmail
			$emailPieces = explode("@", $email);
			$domain = $emailPieces[1];

			// get the top and bottom Ads
			$ads = isset($responses[0]->ads) ? $responses[0]->ads : array();
			$adTop = isset($ads[0]) ? $ads[0]->id : "NULL";
			$adBottom = isset($ads[1]) ? $ads[1]->id : "NULL";

			// save the logs on the utilization table
			$safeQuery = $connection->escape($query);
			$sql = "INSERT INTO utilization	(service, subservice, query, requestor, request_time, response_time, domain, ad_top, ad_bottom) VALUES ('$serviceName','$subServiceName','$safeQuery','$email','$execStartTime','$executionTime','$domain',$adTop,$adBottom)";
			$connection->deepQuery($sql);

			// return positive answer to prove the email was quequed
			return true;
		}

		// false if no action could be taken
		return false;
	}
}
