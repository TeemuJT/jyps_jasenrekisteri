<?php
// src/JYPS/RegisterBundle/Command/SendMemberCardExtraCommand.php

namespace JYPS\RegisterBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SendMemberCardExtraCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sendmembercard_extra')
            ->setDescription('Send membercards also to kunniajäsen and ikijäsen.');
    }
    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $repository = $this->getContainer()->get('doctrine')
            ->getRepository('JYPSRegisterBundle:Member');

        $query = $repository->createQueryBuilder('m')
            ->where('m.membership_end_date >= :current_date')
            ->setParameter('current_date', new \DateTime("now"))
            ->getQuery();

        $members = $query->getResult();

        foreach ($members as $member) {
            if ($member->getMemberType() == "Ainaisjäsen" && $member !== null) {
                echo $member->getFullName() . "\n";
            }
        }
    }

}
