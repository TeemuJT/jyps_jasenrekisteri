<?php
// src/JYPS/RegisterBundle/Controller/MemberController.php;
namespace JYPS\RegisterBundle\Controller;

use Endroid\QrCode\QrCode;
use JYPS\RegisterBundle\Entity\Intrest;
use JYPS\RegisterBundle\Entity\Member;
use JYPS\RegisterBundle\Entity\MemberFee;
use JYPS\RegisterBundle\Form\Type\MemberAddType;
use JYPS\RegisterBundle\Form\Type\MemberEditType;
use JYPS\RegisterBundle\Form\Type\MemberJoinType;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints\Email as EmailConstraint;

class MemberController extends Controller {
	public function indexAction() {
		$repository = $this->getDoctrine()
		                   ->getRepository('JYPSRegisterBundle:Member');

		$query = $repository->createQueryBuilder('m')
		                    ->where('m.membership_end_date >= :current_date')
		                    ->setParameter('current_date', new \DateTime("now"))
		                    ->orderBy('m.surname', 'ASC')
		                    ->getQuery();

		$members = $query->getResult();

		return $this->render('JYPSRegisterBundle:Member:show_members.html.twig', array('members' => $members));
	}

	public function showClosedAction() {
		$repository = $this->getDoctrine()
		                   ->getRepository('JYPSRegisterBundle:Member');

		$query = $repository->createQueryBuilder('m')
		                    ->where('m.membership_end_date <= :current_date')
		                    ->setParameter('current_date', new \DateTime("now"))
		                    ->orderBy('m.member_id', 'ASC')
		                    ->getQuery();

		$members = $query->getResult();

		return $this->render('JYPSRegisterBundle:Member:show_members_old.html.twig', array('members' => $members));
	}

	public function showAllAction($memberid) {

		$request = $this->get('request');

		if (is_null($memberid)) {
			$postData = $request->get('member');
			$memberid = $postData['memberid'];
		}

		$member = $this->getDoctrine()
		               ->getRepository('JYPSRegisterBundle:Member')
		               ->findOneBy(array('member_id' => $memberid));

		if (!$member) {
			throw $this->createNotFoundException(
				'No member found for memberid ' . $memberid
			);
		}

		$memberfees = $this->getDoctrine()
		                   ->getRepository('JYPSRegisterBundle:MemberFee')
		                   ->findBy(array('member_id' => $member->getId()),
			                   array('fee_period' => 'ASC'));

		$form = $this->createForm(new MemberEditType(), $member, array('action' => $this->generateUrl('member', array('memberid' => $member->getMemberId())),
		));

		if ($request->getMethod() == 'POST') {
			$em = $this->getDoctrine()->getEntityManager();

			$form->submit($request);

			if ($form->isValid()) {
				$em->flush();
				$fees = $request->get('Fees_to_be_marked');

				$childMember = $request->get('new_child');
				if ($childMember != NULL) {
					$this->addChildMember($member->getMemberId(), $childMember);
				}
				$removedChilds = $request->get('removed_childs');
				if ($removedChilds != "") {
					foreach ($removedChilds as $removedChild) {
						$this->removeChildMember($removedChild);
					}
				}

				$member_all_fees = $member->getMemberFees();

				if ($fees != "") {
					foreach ($member_all_fees as $member_fee) {
						if (in_array($member_fee->getId(), $fees)) {
							$markfee = $this->getDoctrine()
							                ->getRepository('JYPSRegisterBundle:MemberFee')
							                ->findOneBy(array('id' => $member_fee->getId()));
							$markfee->setPaid(True);
							$em->flush($markfee);
						} else {
							$markfee = $this->getDoctrine()
							                ->getRepository('JYPSRegisterBundle:MemberFee')
							                ->findOneBy(array('id' => $member_fee->getId()));
							$markfee->setPaid(False);
							$em->flush($markfee);
						}
					}
				} else {
					foreach ($member_all_fees as $member_fee) {
						$markfee = $this->getDoctrine()
						                ->getRepository('JYPSRegisterBundle:MemberFee')
						                ->findOneBy(array('id' => $member_fee->getId()));
						$markfee->setPaid(False);
						$em->flush($markfee);

					}
				}

				return $this->redirect($this->generateUrl('member', array('memberid' => $memberid)));
			}
		}
		return $this->render('JYPSRegisterBundle:Member:show_member.html.twig', array('member' => $member,
			'memberfees' => $memberfees,
			'form' => $form->createView(),
		));

	}
	public function addMemberAction() {
		$member = new Member();

		$all_confs = $this->getDoctrine()
		                  ->getManager()
		                  ->getRepository('JYPSRegisterBundle:IntrestConfig');

		$memberfee_confs = $this->getDoctrine()
		                        ->getManager()
		                        ->getRepository('JYPSRegisterBundle:MemberFeeConfig');

		$form = $this->createForm(new MemberAddType(), $member, array('action' => $this->generateUrl('join_internal_save'),
			'intrest_configs' => $all_confs,
			'memberfee_configs' => $memberfee_confs));

		return $this->render('JYPSRegisterBundle:Member:add_member.html.twig', array(
			'form' => $form->createView(),
		));
	}

	public function joinMemberAction(Request $request) {

		$member = new Member();

		$all_confs = $this->getDoctrine()
		                  ->getManager()
		                  ->getRepository('JYPSRegisterBundle:IntrestConfig');

		$memberfee_confs = $this->getDoctrine()
		                        ->getManager()
		                        ->getRepository('JYPSRegisterBundle:MemberFeeConfig');

		$form = $this->createForm(new MemberJoinType(), $member, array('action' => $this->generateUrl('join_save'),
			'intrest_configs' => $all_confs,
			'memberfee_configs' => $memberfee_confs));

		return $this->render('JYPSRegisterBundle:Member:join_member.html.twig', array(
			'form' => $form->createView(),
		));

	}

	private function generateMembershipCard(Member $member) {

		$base_image_path = $this->get('kernel')->locateResource('@JYPSRegisterBundle/Resources/public/images/JYPS_Jasenkortti.png');
		$base_image = imagecreatefrompng($base_image_path);
		$output_image = $this->get('kernel')->locateResource('@JYPSRegisterBundle/Resources/savedCards/') . 'MemberCard_' . $member->getMemberId() . '.png';

		/* member data to image */

		$black = imagecolorallocate($base_image, 0, 0, 0);
		$memberid = $member->getMemberId();
		$join_year = $member->getMembershipStartDate()->format('Y');
		$font = $this->get('kernel')->locateResource('@JYPSRegisterBundle/Resources/public/fonts/LucidaGrande.ttf');

		imagettftext($base_image, 38, 0, 190, 500, $black, $font, $member->getFullName());
		imagettftext($base_image, 38, 0, 390, 555, $black, $font, $memberid);
		imagettftext($base_image, 38, 0, 390, 610, $black, $font, $join_year);

		/*qr code to image & serialize json for qr code*/
		$member_data = array('member_id' => $member->getMemberId(),
			'join_year' => $member->getMembershipStartDate()->format('Y'),
			'name' => $member->getFullName());
		$member_qr_data = json_encode($member_data);

		$qrCode = new QrCode();
		$qrCode->setSize(380);
		$qrCode->setText($member_qr_data);
		$qrCode = $qrCode->get('png');
		$qr_image = imagecreatefromstring($qrCode);
		imagecopy($base_image, $qr_image, 550, 22, 0, 0, imagesx($qr_image), imagesy($qr_image));
		/*write image to disk */
		imagepng($base_image, $output_image);

		return $output_image;
	}

	private function sendJoinInfoEmail(Member $member, MemberFee $memberfee) {
		$intrest_names = array();
		if ($member->getIntrests()) {
			foreach ($member->getIntrests() as $intrest) {
				$intrest_config = $this->getDoctrine()
				                       ->getRepository('JYPSRegisterBundle:IntrestConfig')
				                       ->findOneBy(array('id' => $intrest->getIntrestId()));
				array_push($intrest_names, $intrest_config->getIntrestname());
			}
		}
		$member_age = date('Y') - $member->getBirthYear();
		$message = \Swift_Message::newInstance()
			->setSubject('Uusi JYPS-jäsen!')
			->setFrom('rekisteri@jyps.fi')
			->setTo(array('pj@jyps.fi', 'kaisa.m.peltonen@gmail.com', 'henna.breilin@toivakka.fi'))
			->setBody($this->renderView(
				'JYPSRegisterBundle:Member:join_member_infomail.txt.twig',
				array('member' => $member,
					'memberfee' => $memberfee,
					'intrests' => $intrest_names,
					'age' => $member_age)));
		$this->get('mailer')->send($message);
	}

	public function memberExtraAction() {
		return $this->render('JYPSRegisterBundle:Member:member_actions.html.twig');
	}

	public function sendMembershipCardAction(Request $request) {

		$memberid = $this->get('request')->request->get('memberid');

		$em = $this->getDoctrine()->getManager();

		$member = $this->getDoctrine()
		               ->getRepository('JYPSRegisterBundle:Member')
		               ->findOneBy(array('member_id' => $memberid));
		$membership_card = $this->generateMembershipCard($member);
		$message = \Swift_Message::newInstance()
			->setSubject('JYPS ry:n jäsenkorttisi')
			->setFrom('jasenrekisteri@jyps.fi')
			->setTo($member->getEmail())
			->attach(\Swift_Attachment::fromPath($membership_card))
			->setBody($this->renderView(
				'JYPSRegisterBundle:Member:membercard_resend.txt.twig', array()));

		$this->get('mailer')->send($message);

		$this->get('session')->getFlashBag()->add(
			'notice',
			'Jäsenkortti lähetty');

		return $this->redirect($this->generateUrl('member', array("memberid" => $memberid)));
	}
	public function sendCommunicationMailAction(Request $request) {

		$message = $request->get('message');
		$subject = $request->get('subject');
		$from_address = $request->get('from_address');
		$ui_date = $request->get('email_date_limit');

		if ($ui_date === "") {
			$ui_date = "1900-12-31";
		}
		$ok = 0;
		$nok = 0;
		$repository = $this->getDoctrine()
		                   ->getRepository('JYPSRegisterBundle:Member');

		$query = $repository->createQueryBuilder('m')
		                    ->where('m.membership_start_date >= :ui_date AND m.membership_end_date <= :current_date')
		                    ->setParameter('ui_date', $ui_date)
		                    ->setParameter('current_date', new \Datetime('now'))
		                    ->getQuery();
		$members = $query->getResult();

		foreach ($members as $member) {
			if ($member->getEmail() == "") {
				continue;
			}
			$emailConstraint = new EmailConstraint();
			$errors = "";
			$errors = $this->get('validator')->validateValue($member->getEmail(), $emailConstraint);
			if ($errors == "" && !is_null($member->getEmail()) && $member->getEmail() != "") {
				$ok++;
				$message = \Swift_Message::newInstance()
					->setSubject($subject)
					->setFrom($from_address)
					->setTo(array($member->getEmail()))
					->setBody($message);
				$this->get('mailer')->send($message);
			} else {
				$nok++;

			}
		}
		$this->get('session')->getFlashBag()->add(
			'notice',
			'Sähköpostit lähetetty, ok: ' . $ok . 'kpl, not ok:' . $nok . 'kpl');

		return $this->redirect($this->generateUrl('memberActions'));
	}

	public function sendMagazineLinkAction(Request $request) {
		$ok = 0;
		$nok = 0;
		$magazine_url = $request->get('magazine_url');
		$send_payment_info = $request->get('send_payment_info');

		$repository = $this->getDoctrine()
		                   ->getRepository('JYPSRegisterBundle:Member');

		$query = $repository->createQueryBuilder('m')
		                    ->where('m.membership_end_date >= :current_date AND m.magazine_preference = 1')
		                    ->setParameter('current_date', new \Datetime("now"))
		                    ->getQuery();

		$members = $query->getResult();

		foreach ($members as $member) {
			$errors = "";
			$emailConstraint = new EmailConstraint();
			$errors = $this->get('validator')->validateValue($member->getEmail(), $emailConstraint);
			if ($errors == "" && !is_null($member->getEmail()) && $member->getEmail() != "") {
				$ok++;
				/* check if the fee is paid for current year */
				if ($member->isMemberFeePaid(date('Y')) == True || $send_payment_info == '') {
					$magazine_template = 'JYPSRegisterBundle:Member:magazine_info.txt.twig';
				} else if ($send_payment_info == 'on' && $member->isMemberFeePaid(date('Y')) == False) {
					$magazine_template = 'JYPSRegisterBundle:Member:magazine_info_pay_notice.txt.twig';
				}

				$message = \Swift_Message::newInstance()
					->setSubject("JYPS Ry Jäsenlehti")
					->setFrom('jasenrekisteri@jyps.fi')
					->setTo(array($member->getEmail()))
					->setBody($this->renderView(
						$magazine_template, array('magazine_url' => $magazine_url)));

				$this->get('mailer')->send($message);
			} else {
				$nok++;
			}
		}
		$this->get('session')->getFlashBag()->add(
			'notice',
			'Sähköpostit lähetetty, ok: ' . $ok . 'kpl, virheellisiä: ' . $nok . 'kpl');

		return $this->redirect($this->generateUrl('memberActions'));

	}

	public function addressExcelAction() {
		$i = 0;
		$repository = $this->getDoctrine()
		                   ->getRepository('JYPSRegisterBundle:Member');

		$query = $repository->createQueryBuilder('m')
		                    ->where('m.membership_end_date >= :today_date AND m.magazine_preference = 0 AND m.parent IS NULL')
		                    ->setParameter('today_date', new \Datetime("now"))
		                    ->getQuery();
		$members = $query->getResult();

		$phpExcelObject = $this->get('phpexcel')->createPHPExcelObject();
		$phpExcelObject->getProperties()->setCreator("JYPS Ry Jäsenrekisteri")
		               ->setLastModifiedBy("JYPS Ry Jäsenrekisteri")
		               ->setTitle("Osoitteet")
		               ->setSubject("Osoitteet")
		               ->setDescription("Aktiivisten jäsenten osoitetiedot joiden lehden toimitustapa = paperi")
		               ->setKeywords("")
		               ->setCategory("");

		foreach ($members as $member) {
			$i++;
			$phpExcelObject->getActiveSheet()->setCellValueByColumnAndRow(0, $i, $member->getFirstName());
			$phpExcelObject->getActiveSheet()->setCellValueByColumnAndRow(1, $i, $member->getSurname());
			$phpExcelObject->getActiveSheet()->setCellValueByColumnAndRow(2, $i, $member->getStreetAddress());
			$phpExcelObject->getActiveSheet()->setCellValueByColumnAndRow(3, $i, $member->getPostalCode());
			$phpExcelObject->getActiveSheet()->setCellValueByColumnAndRow(4, $i, $member->getCity());

		}
		$phpExcelObject->getActiveSheet()->setTitle('Osoitteet');
		// Set active sheet index to the first sheet, so Excel opens this as the first sheet
		$phpExcelObject->setActiveSheetIndex(0);
		// create the writer
		$writer = $this->get('phpexcel')->createWriter($phpExcelObject, 'Excel5');
		// create the response
		$response = $this->get('phpexcel')->createStreamedResponse($writer);
		// adding headers
		$response->headers->set('Content-Type', 'text/vnd.ms-excel; charset=utf-8');
		$response->headers->set('Content-Disposition', 'attachment;filename=jyps_osoitteet.xls');
		$response->headers->set('Pragma', 'public');
		$response->headers->set('Cache-Control', 'maxage=1');

		return $response;

	}

	public function joinSaveAction(Request $request) {

		$member = new Member();

		$temp = $request->request->get('memberid');
		$firstnames = $request->get('familymember_firstname');

		if (isset($temp['intrests'])) {
			$intrests = $temp['intrests'];
		}

		//extra params for member
		$member->setMemberid($this->getNextMemberId());

		$member->setMembershipEndDate(new \DateTime("2038-12-31"));

		if (!empty($intrests)) {
			foreach ($intrests as $intrest) {
				$new_intrest = new Intrest();
				$new_intrest->setIntrestId($intrest);
				$new_intrest->setIntrest($member);
				$member->addIntrest($new_intrest);
			}
		}

		$form = $this->createForm(new MemberJoinType, $member);

		$form->handleRequest($request);

		if ($form->isValid()) {
			$membership_card = $this->generateMembershipCard($member);

			//create memberfee
			$memberfee = new MemberFee();
			$memberfee->setFeeAmountWithVat($member->getMemberType()->getMemberfeeAmount());
			$memberfee->setReferenceNumber(date("Y") . $member->getMemberId());
			$memberfee->setDueDate(new \DateTime("now"));
			$memberfee->setMemberFee($member);

			$em = $this->getDoctrine()->getManager();
			$em->persist($member);
			$em->persist($memberfee);
			$em->flush();

			$childMembers = $this->processChildMembers($request);
			if (!empty($childMembers)) {
				$this->createChildMembers($member, $childMembers);
			}

			$bankaccount = $this->getDoctrine()
			                    ->getRepository('JYPSRegisterBundle:SystemParameter')
			                    ->findOneBy(array('key' => 'BankAccount'));

			//Send mail here, if user exits confirmation page too fast no mail is sent.
			//1) List join
			if ($member->getEmail() != "") {
				if ($member->getMailingListYleinen() == True) {
					$message = \Swift_Message::newInstance()
						->setFrom($member->getEmail())
						->setTo('yleinen-join@jyps.info');
					$this->get('mailer')->send($message);
				}
				//2) information mail
				$message = \Swift_Message::newInstance()
					->setSubject('Tervetuloa JYPS Ry:n jäseneksi')
					->setFrom('jasenrekisteri@jyps.fi')
					->setTo($member->getEmail())
					->attach(\Swift_Attachment::fromPath($membership_card))
					->setBody($this->renderView(
						'JYPSRegisterBundle:Member:join_member_email_base.txt.twig',
						array('member' => $member,
							'memberfee' => $memberfee,
							'bankaccount' => $bankaccount,
							'virtualbarcode' => $memberfee->getVirtualBarcode($bankaccount))));

				$childs = $member->getChildren();

				//attach also all childmembers cards to mail
				foreach ($childs as $child) {
					$this->generateMembershipCard($child);
					$message->attach(\Swift_Attachment::fromPath($this->generateMembershipCard($child)));
				}
				$this->get('mailer')->send($message);
			}

			$this->sendJoinInfoEmail($member, $memberfee);

			return $this->redirect($this->generateUrl('join_complete'), 303);
		}
		return $this->render('JYPSRegisterBundle:Member:join_member_failed.html.twig');
	}

	public function joinCompleteAction() {
		return $this->render('JYPSRegisterBundle:Member:join_member_complete.html.twig');
	}

	public function joinSaveInternalAction(Request $request) {
		$member = new Member();

		$form = $this->createForm(new MemberAddType, $member);

		$form->handleRequest($request);

		if ($form->isValid()) {
			$temp = $request->request->get('memberid');
			$childMember = $request->get('new_child');

			if (isset($temp['intrests'])) {
				$intrests = $temp['intrests'];
			}
			//extra params for member
			$member->setMemberid($this->getNextMemberId());

			$member->setMembershipEndDate(new \DateTime("2038-12-31"));

			if (!empty($intrests)) {
				foreach ($intrests as $intrest) {
					$new_intrest = new Intrest();
					$new_intrest->setIntrestId($intrest);
					$new_intrest->setIntrest($member);
					$member->setIntrest($new_intrest);
				}
			}

			$membership_card = $this->generateMembershipCard($member);

			//create memberfee
			$memberfee = new MemberFee();
			$memberfee->setFeeAmountWithVat($member->getMemberType()->getMemberfeeAmount());
			$memberfee->setReferenceNumber(date("Y") . $member->getMemberId());
			$memberfee->setDueDate(new \DateTime("now"));
			$memberfee->setMemberFee($member);

			$memberFeeConfig = $this->getDoctrine()
			                        ->getRepository('JYPSRegisterBundle:MemberFeeConfig')
			                        ->findOneBy(array('member_type' => $member->getMemberType()));

			$send_mail_without_payment_info = False;

			if ($memberFeeConfig->getCampaignFee() == True) {
				$send_mail_without_payment_info = True;

				$realMemberFeeConfig = $this->getDoctrine()
				                            ->getRepository('JYPSRegisterBundle:MemberFeeConfig')
				                            ->findOneBy(array('member_type' => $memberFeeConfig->getRealMemberType()));

				$member->setMemberType($realMemberFeeConfig);
				$memberfee->setMemo("KAMPPIS");
			}
			if (isset($temp['mark_fee_paid'])) {
				if ($temp['mark_fee_paid'] == True) {
					$memberfee->setPaid(True);
				}
			}

			$em = $this->getDoctrine()->getManager();
			$em->persist($member);
			$em->persist($memberfee);
			$em->flush();

			if ($childMember != NULL) {
				$parent_member = $this->getDoctrine()
				                      ->getRepository('JYPSRegisterBundle:Member')
				                      ->findOneBy(array('member_id' => $childMember));

				$member->setParent($parent_member);
				//for child members fee is marked automatically paid, only parent gets the fee
				$memberfee->setPaid(True);
				$em->flush();
				$send_mail_without_payment_info = True;

			}
			$bankaccount = $this->getDoctrine()
			                    ->getRepository('JYPSRegisterBundle:SystemParameter')
			                    ->findOneBy(array('key' => 'BankAccount'));

			//Send mail here, if user exits confirmation page too fast no mail is sent.
			//1) List join
			if ($member->getEmail() != "") {
				if ($member->getMailingListYleinen() == True) {
					$message = \Swift_Message::newInstance()
						->setFrom($member->getEmail())
						->setTo('yleinen-join@jyps.info');
					$this->get('mailer')->send($message);
				}
				//2) information mail
				if ($send_mail_without_payment_info == False) {
					$message = \Swift_Message::newInstance()
						->setSubject('Tervetuloa JYPS Ry:n jäseneksi')
						->setFrom('jasenrekisteri@jyps.fi')
						->setTo($member->getEmail())
						->attach(\Swift_Attachment::fromPath($membership_card))
						->setBody($this->renderView(
							'JYPSRegisterBundle:Member:join_member_email_internal_base.txt.twig',
							array('member' => $member,
								'memberfee' => $memberfee,
								'bankaccount' => $bankaccount,
								'virtualbarcode' => $memberfee->getVirtualBarcode($bankaccount))));
				} else {
					$message = \Swift_Message::newInstance()
						->setSubject('Tervetuloa JYPS Ry:n jäseneksi')
						->setFrom('jasenrekisteri@jyps.fi')
						->setTo($member->getEmail())
						->attach(\Swift_Attachment::fromPath($membership_card))
						->setBody($this->renderView(
							'JYPSRegisterBundle:Member:join_member_email_internal_no_payment_info_base.txt.twig',
							array('member' => $member,
								'memberfee' => $memberfee,
								'bankaccount' => $bankaccount,
								'virtualbarcode' => $memberfee->getVirtualBarcode($bankaccount))));
				}

				$this->get('mailer')->send($message);
			}

			$this->sendJoinInfoEmail($member, $memberfee);

			$this->get('session')->getFlashBag()->add(
				'notice',
				'Jäsen lisätty');

			return $this->redirect($this->generateUrl('add_member'));

		}
		return $this->render('JYPSRegisterBundle:Member:join_member_failed.html.twig');
	}

	public function searchMembersAction() {
		$search_term = $this->get('request')->request->get('search_name');

		$repository = $this->getDoctrine()
		                   ->getRepository('JYPSRegisterBundle:Member');

		$query = $repository->createQueryBuilder('m')
		                    ->where('m.firstname LIKE :search_term OR m.surname LIKE :search_term OR m.city LIKE :search_term OR m.postal_code LIKE :search_term OR m.email LIKE :search_term')
		                    ->andWhere('m.membership_end_date > :current_date')
		                    ->setParameter('search_term', "%$search_term%")
		                    ->setParameter('current_date', new \DateTime("now"))
		                    ->getQuery();

		$members = $query->getResult();

		return $this->render('JYPSRegisterBundle:Member:show_members_search.html.twig', array('members' => $members));
	}

	public function searchOldMembersAction() {
		$search_term = $this->get('request')->request->get('search_name');

		$repository = $this->getDoctrine()
		                   ->getRepository('JYPSRegisterBundle:Member');

		$query = $repository->createQueryBuilder('m')
		                    ->where('m.firstname LIKE :search_term OR m.surname LIKE :search_term OR m.city LIKE :search_term OR m.postal_code LIKE :search_term OR m.email LIKE :search_term')
		                    ->andWhere('m.membership_end_date < :current_date')
		                    ->setParameter('search_term', "%$search_term%")
		                    ->setParameter('current_date', new \DateTime("now"))
		                    ->getQuery();

		$members = $query->getResult();

		return $this->render('JYPSRegisterBundle:Member:show_members_old.html.twig', array('members' => $members));
	}

	public function endMemberAction() {
		$memberid = $this->get('request')->request->get('memberid');

		$em = $this->getDoctrine()->getManager();

		$member = $this->getDoctrine()
		               ->getRepository('JYPSRegisterBundle:Member')
		               ->findOneBy(array('member_id' => $memberid));
		$enddate = new \DateTime("now");
		$member->setMembershipEndDate($enddate);
		$em->flush();

		return $this->redirect($this->generateUrl('all_members'));

	}
	public function memberStatisticsAction() {
		return $this->render('JYPSRegisterBundle:Member:member_statistics.html.twig');
	}

	public function restoreMemberAction() {
		$memberid = $this->get('request')->request->get('memberid');

		$em = $this->getDoctrine()->getManager();

		$member = $this->getDoctrine()
		               ->getRepository('JYPSRegisterBundle:Member')
		               ->findOneBy(array('member_id' => $memberid));
		$enddate = new \DateTime("2038-12-31");
		$member->setMembershipEndDate($enddate);
		$em->flush($member);

		return $this->redirect($this->generateUrl('showClosed'));

	}
	private function addChildMember($memberid, $childMemberId) {

		$em = $this->getDoctrine()->getManager();

		$member = $this->getDoctrine()
		               ->getRepository('JYPSRegisterBundle:Member')
		               ->findOneBy(array('member_id' => $memberid));

		$childMember = $this->getDoctrine()
		                    ->getRepository('JYPSRegisterBundle:Member')
		                    ->findOneBy(array('member_id' => $childMemberId));

		$childMember->setParent($member);

		$em->flush($childMember);
		return true;
	}
	private function removeChildMember($childMemberId) {

		$em = $this->getDoctrine()->getManager();

		$childMember = $this->getDoctrine()
		                    ->getRepository('JYPSRegisterBundle:Member')
		                    ->findOneBy(array('member_id' => $childMemberId));

		$childMember->setParent(NULL);
		$em->flush($childMember);
		return true;
	}
	private function getNextMemberId() {

		$repository = $this->getDoctrine()
		                   ->getRepository('JYPSRegisterBundle:Member');
		$query = $repository->createQueryBuilder('m')
		                    ->select('MAX(m.member_id) AS max_memberid')
		                    ->setMaxResults(1);
		$maxmemberid = $query->getQuery()->getResult();
		$temparr = $maxmemberid[0];
		$maxmemberid_real = $temparr['max_memberid'];

		$maxmemberid_real++;
		return $maxmemberid_real;
	}
	private function processChildMembers(Request $request) {
		$childMembers = [];
		$i = 0;

		$firstnames = $request->get('familymember_firstnames');
		$secondnames = $request->get('familymember_secondnames');
		$surnames = $request->get('familymember_surnames');
		$birthyears = $request->get('familymember_birthyears');
		$genders = $request->get('familymember_genders');
		$emails = $request->get('familymember_emails');
		$mail_lists = $request->get('familymember_mail_lists');
		$member_types = $request->get('familymember_types');

		foreach ($firstnames as $firstname) {
			$child['firstname'] = $firstnames[$i];
			$child['secondname'] = $secondnames[$i];
			$child['surname'] = $surnames[$i];
			$child['birthyear'] = $birthyears[$i];
			$child['gender'] = $genders[$i];
			$child['email'] = $emails[$i];
			$child['mail_list'] = $mail_lists[$i];
			$child['type'] = $member_types[$i];
			$i++;
			$childMembers[] = $child;
		}
		return $childMembers;
	}
	private function createChildMembers(Member $member, $childrens) {
		$em = $this->getDoctrine()->getManager();

		foreach ($childrens as $children) {
			$childMember = clone $member;
			$childMember->setFirstName($children['firstname']);
			$childMember->setSecondName($children['secondname']);
			$childMember->setSurName($children['surname']);
			$childMember->setBirthYear($children['birthyear']);
			$childMember->setGender($children['gender']);
			$childMember->setEmail($children['email']);
			if ($children['mail_list'] = 'Yes') {
				$childMember->setMailingListYleinen(1);
			} else {
				$childMember->setMailingListYleinen(0);
			}
			$childMember->setMemberId($this->getNextMemberId());
			$childMember->setParent($member);

			$memberFeeConfig = $this->getDoctrine()
			                        ->getRepository('JYPSRegisterBundle:MemberFeeConfig')
			                        ->findOneBy(array('id' => $children['type']));

			$childMember->setMemberType($memberFeeConfig);

			//create fee and mark as paid
			$this->createMemberFee($childMember, true);
			//join to yleinen list
			$this->sendYleinenJoinMail($childMember);
			$em->persist($childMember);
			$em->flush($childMember);
		}
		return true;
	}
	private function createMemberFee(Member $member, $markpaid) {

		$memberfee = new MemberFee();
		$memberfee->setFeeAmountWithVat($member->getMemberType()->getMemberfeeAmount());
		$memberfee->setReferenceNumber(date("Y") . $member->getMemberId());
		$memberfee->setDueDate(new \DateTime("now"));
		$memberfee->setPaid($markpaid);
		$memberfee->setMemberFee($member);

	}
	private function sendYleinenJoinMail(Member $member) {
		if ($member->getEmail() != "") {
			if ($member->getMailingListYleinen() == True) {
				$message = \Swift_Message::newInstance()
					->setFrom($member->getEmail())
					->setTo('yleinen-join@jyps.info');
				$this->get('mailer')->send($message);
			}
		}
	}
}
