<?php
include_once ('../vendor/gregwar/captcha/CaptchaBuilderInterface.php');
include_once ('../vendor/gregwar/captcha/PhraseBuilderInterface.php');
include_once ('../vendor/gregwar/captcha/CaptchaBuilder.php');
include_once ('../vendor/gregwar/captcha/PhraseBuilder.php');

use Phalcon\Mvc\Controller;
use Gregwar\Captcha\CaptchaBuilder;

/**
 * Invite user from the web
 *
 * @author kuma
 * @version 1.0
 */
class InvitarController extends Controller
{

	/**
	 * Invite from the web page
	 *
	 * @author kuma
	 * @version 1.0
	 */
	public function indexAction()
	{
		session_start();
		
		$this->view->wwwhttp = $this->di->get('path')['http'];
		$this->view->wwwroot = $this->di->get('path')['root'];
		$this->view->message = false;
		$this->view->message_code = 0;
		$this->view->guest = array(
			''
		);
		
		$this->view->email = '';
		$this->view->name = '';
		
		$db = new Connection();
		$utils = new Utils();
		$render = new Render();
		
		if ($this->request->isPost())
		{
			// proccess invitation
			
			$captcha = $this->request->getPost('captcha');
			$name = $this->request->getPost('name');
			$emailaddress = $this->request->getPost('email');
			$guest = $this->request->getPost('guest');
			$email = new Email();
			$service = new Service();
			$service->showAds = true;
			
			if (is_null($guest))
				$guest = array();
			
			$this->view->guest = $guest;
			$this->view->email = $emailaddress;
			$this->view->name = $name;
			
			if ($captcha == $_SESSION['phrase'])
			{
				$allok = true;
				foreach ($guest as $g)
				{
					$g = trim($g);
					
					// check if guest exists / if is user of AP
					if ($utils->personExist($g) === false)
					{
						$exists = $db->deepQuery("SELECT * FROM invitations WHERE email_invited = '$g';");
						if (isset($exists[0]))
						{
							$this->view->message_type = 'warning';
							$this->view->message_code = 3;
							$this->view->message = "La invitaci&oacute;n no se envi&oacute; porque tu amigo <b>$g</b> fue invitado anteriormente.";
							$allok = false;
							break;
						}
					}
					else
					{
						$this->view->message_type = 'warning';
						$this->view->message_code = 2;
						$this->view->message = "La invitaci&oacute;n no se envi&oacute; porque tu amigo <b>$g</b> ya usa Apretaste.";
						$allok = false;
						break;
					}
				}
				
				if ($allok)
				{
					foreach ($guest as $g)
					{
						$g = trim($g);
						
						// create the invitation for the user
						$response = new Response();
						$response->setResponseEmail($guest);
						$subject = "$name le ha invitado a usar Apretaste";
						$response->setResponseSubject($subject);
						$responseContent = array(
							"author" => $emailaddress
						);
						
						$response->createFromTemplate("invitation.tpl", $responseContent);
						$response->internal = true;
						
						$html = $render->renderHTML($service, $response);
						
						$email->sendEmail($g, $subject, $html);
						
						// save invitation
						$db->deepQuery("INSERT INTO invitations (email_inviter, email_invited) VALUES ('$emailaddress', '$g');");
					}
					
					// send notification to the inviter
					$response = new Response();
					$response->setResponseEmail($emailaddress);
					$subject = "Gracias por invitar a tus amigos para que usen Apretaste";
					$response->setResponseSubject($subject);
					
					$response->createFromTemplate('invitation_notification.tpl', array(
						'guests' => $guest,
						'name' => $name
					));
					
					$response->internal = true;
					
					$html = $render->renderHTML($service, $response);
					$email->sendEmail($emailaddress, $subject, $html);
					
					$this->view->message_type = 'success';
					$this->guest = array();
					$this->view->message = "Gracias por invitar a tus amigos. Enviamos emails de invitaci&oacute;n a cada uno de ellos y notificaci&oacute;n para ti. Ahora puedes invitar a otros.";
				}
			}
			else
			{
				$this->view->message_type = 'danger';
				$this->view->message_code = 1;
				$this->view->message = 'El texto escrito no coincide con la imagen';
			}
		}
		
		// show the form
		$this->view->title = "Invitar a tus amigos para que usen Apretaste";
		
	}

	/**
	 * Publish CAPTCHA image
	 *
	 * @author kuma
	 */
	public function captchaAction()
	{
		session_start();
		
		$builder = new CaptchaBuilder();
		$builder->build();
		
		$_SESSION['phrase'] = $builder->getPhrase();
		
		header('Content-type: image/jpeg');
		$builder->output();
		
		$this->view->disable();
	}
}