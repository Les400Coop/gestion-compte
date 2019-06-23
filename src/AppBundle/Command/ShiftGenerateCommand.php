<?php
// src/AppBundle/Command/ShiftGenerateCommand.php
namespace AppBundle\Command;

use AppBundle\Entity\Shift;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class ShiftGenerateCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('app:shift:generate')
            ->setDescription('Generate shift from period')
            ->setHelp('This command allows you to generate shift using period')
            ->addArgument('date', InputArgument::REQUIRED, 'The date format yyyy-mm-dd')
            ->addOption('to','t',InputOption::VALUE_OPTIONAL,'Every day until this date','')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $from_given = $input->getArgument('date');
        $to_given = $input->getOption('to');
        $from = date_create_from_format('Y-m-d',$from_given);
        if (!$from || $from->format('Y-m-d') != $from_given){
            $output->writeln('<fg=red;> wrong date format. Use Y-m-d </>');
            return;
        }
        if ($to_given){
            $to = date_create_from_format('Y-m-d',$to_given);
            $output->writeln('<fg=yellow;>'.'Shift generation from <fg=cyan;>'.$from->format('d M Y').'</><fg=yellow;> to </><fg=cyan;>'.$to->format('d M Y').'</>');
        }else{
            $to = clone $from;
            $to->add(\DateInterval::createFromDateString('+1 Day'));
            $output->writeln('<fg=yellow;>'.'Shift generation for </><fg=cyan;>'.$from->format('d M Y').'</>');
        }
        $interval = \DateInterval::createFromDateString('1 day');
        $period = new \DatePeriod($from, $interval, $to);

        $count = 0;
        $count2 = 0;
	//pour test
	$count3 = 0;

        $reservedShifts = array();

        $router = $this->getContainer()->get('router');
	//fl $output->writeln('<br/>[ foreach new date ]');
        foreach ( $period as $date ) {
            $output->writeln('<fg=cyan;>'.$date->format('d M Y').'</>');
            ////////////////////////
            $dayOfWeek = $date->format('N') - 1; //0 = 1-1 (for Monday) through 6=7-1 (for Sunday)
            $em = $this->getContainer()->get('doctrine')->getManager();
            $mailer = $this->getContainer()->get('mailer');
            $periodRepository = $em->getRepository('AppBundle:Period');
            $qb = $periodRepository
                ->createQueryBuilder('p');
            $qb->where('p.dayOfWeek = :dow')
                ->setParameter('dow', $dayOfWeek)
                ->orderBy('p.start');
	    $periods = $qb->getQuery()->getResult();
	    //fl $output->writeln('<br/>[ -1- foreach period / date ]');
	    foreach ($periods as $period) {
		//fl $output->writeln('<br/>[ .1 period = start: '.$period->getStart()->format('H:i').' end: '.$period->getEnd()->format('H:i').']');
                $shift = new Shift();
                $start = date_create_from_format('Y-m-d H:i', $date->format('Y-m-d') . ' ' . $period->getStart()->format('H:i'));
                $shift->setStart($start);
                $end = date_create_from_format('Y-m-d H:i', $date->format('Y-m-d') . ' ' . $period->getEnd()->format('H:i'));
                $shift->setEnd($end);

		//fl $output->writeln('<br/>[ -2- foreach position in period / date ]');
                foreach ($period->getPositions() as $position) {

                    $lastStart = $this->lastCycleDate($start);
        	    //fl $output->writeln('<br/> [.2 lastStart = '.$lastStart->format('d M Y').' ');
                    $lastEnd = $this->lastCycleDate($end);
        	    //fl $output->writeln('; lastEnd = '.$lastEnd->format('d M Y').' ] <br/>');


                    $last_cycle_shifts = $em->getRepository('AppBundle:Shift')->findBy(array('start' => $lastStart, 'end' => $lastEnd, 'job' => $period->getJob(), 'formation' => $position->getFormation()));
                    $last_cycle_shifts =  array_filter($last_cycle_shifts, function($shift) {return $shift->getShifter();});
		    $last_cycle_shifters_array = array();
		    //fl $output->writeln('<br/> [ -3- foreach last_cycle_shift]');
		    foreach ($last_cycle_shifts as $last_cycle_shift){
			//fl  pour test :
                        //fl $shft= $last_cycle_shift->getShifter(); //clean keys
			//fl $output->writeln('<br/>[.3 last shifter : '.$shft.' ]');  
                        $last_cycle_shifters_array[] = $last_cycle_shift->getShifter(); //clean keys
                    }

                    $existing_shifts = $em->getRepository('AppBundle:Shift')->findBy(array('start' => $start, 'end' => $end, 'job' => $period->getJob(), 'formation' => $position->getFormation()));
		    $count2 += count($existing_shifts);
		    //fl $output->writeln('<br/>[ -3 for $i=0 $i <$position->getNbOfShifter= '.$position->getNbOfShifter().' -count($existing_shifts) = '.count($existing_shifts).']');
                    for ($i=0; $i<$position->getNbOfShifter()-count($existing_shifts); $i++){
                        $current_shift = clone $shift;
                        $current_shift->setJob($period->getJob());
			$current_shift->setFormation($position->getFormation());
			//fl $output->writeln('<br/>[ .3 clone shift ] $i='.$i.' $last_cycle_shifters_array='.count($last_cycle_shifters_array));
                        if ($last_cycle_shifters_array && $i < count($last_cycle_shifters_array)) {
			    $current_shift->setLastShifter($last_cycle_shifters_array[$i]);
			    //fl $output->writeln('<br/>[ajout $reservedShifts]');
			    $reservedShifts[] = $current_shift;
			    // pour voir
			    $count3++;
                        }
                        $em->persist($current_shift);
                        $count++;
                    }
                }
            }
            $em->flush();

            $shiftEmail = $this->getContainer()->getParameter('emails.shift');
            foreach ($reservedShifts as $shift){
                $mail = (new \Swift_Message('[ESPACE MEMBRES] Reprends ton créneau dans 28 jours'))
                    ->setFrom($shiftEmail['address'], $shiftEmail['from_name'])
                    ->setTo($shift->getLastShifter()->getEmail())
                    ->setBody(
                        $this->getContainer()->get('twig')->render(
                            'emails/shift_reserved.html.twig',
                            array('shift' => $shift,
                                'accept_url' => $router->generate('accept_reserved_shift',array('id' => $shift->getId(),'token'=> $shift->getTmpToken($shift->getlastShifter()->getId())),UrlGeneratorInterface::ABSOLUTE_URL),
                                'reject_url' => $router->generate('reject_reserved_shift',array('id' => $shift->getId(),'token'=> $shift->getTmpToken($shift->getlastShifter()->getId())),UrlGeneratorInterface::ABSOLUTE_URL),
                            )
                        ),
                        'text/html'
                    );
                $mailer->send($mail);
            }

        }
        $message = $count.' créneau'.(($count>1) ? 'x':'').' généré'.(($count>1) ? 's':'');
        $output->writeln('<fg=cyan;>>>></><fg=green;> '.$message.' </>');
        $message = $count2.' créneau'.(($count2>1) ? 'x':'').' existe'.(($count2>1) ? 'nt':'');
        $output->writeln('<fg=cyan;>>>></><fg=red;> '.$message.' déjà </>');
        $message = $count3.' reservedshifts';
        $output->writeln($message);
    }

    protected function lastCycleDate(\DateTime $date)
    {
	$lastCycleDate = clone($date);
	/* modification pour test -28 --> -14 : */
	/*$lastCycleDate->modify("-28 days");*/
	$lastCycleDate->modify("-28 days");


        return $lastCycleDate;
    }
}
