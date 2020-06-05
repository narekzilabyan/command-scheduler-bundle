<?php

namespace JMose\CommandSchedulerBundle\Command;

use Cron\CronExpression;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use JMose\CommandSchedulerBundle\Entity\ScheduledCommand;
use Symfony\Component\Console\Event\ConsoleExceptionEvent;
use Website\DefaultBundle\EventListener\ExceptionListener;

/**
 * Class ExecuteCommand : This class is the entry point to execute all scheduled command
 *
 * @author  Julien Guyon <julienguyon@hotmail.com>
 * @package JMose\CommandSchedulerBundle\Command
 */
class ExecuteCommand extends ContainerAwareCommand
{

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    private $em;

    /**
     * @var string
     */
    private $logPath;

    /**
     * @var boolean
     */
    private $dumpMode;

    /**
     * @var integer
     */
    private $commandsVerbosity;

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setName('scheduler:execute')
            ->setDescription('Execute scheduled commands')
            ->addOption('dump', null, InputOption::VALUE_NONE, 'Display next execution')
            ->addOption('no-output', null, InputOption::VALUE_NONE, 'Disable output message from scheduler')
            ->setHelp('This class is the entry point to execute all scheduled command');
    }

    /**
     * Initialize parameters and services used in execute function
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->dumpMode = $input->getOption('dump');
        $this->logPath = $this->getContainer()->getParameter('jmose_command_scheduler.log_path');

	    // If logpath is not set to false, append the directory separator to it
	    if(false !== $this->logPath) {
            $this->logPath = rtrim($this->logPath, '/\\') . DIRECTORY_SEPARATOR ;
	    }

        // Store the original verbosity before apply the quiet parameter
        $this->commandsVerbosity = $output->getVerbosity();

        if( true === $input->getOption('no-output')){
            $output->setVerbosity( OutputInterface::VERBOSITY_QUIET );
        }

        $this->em = $this->getContainer()->get('doctrine')->getManager(
            $this->getContainer()->getParameter('jmose_command_scheduler.doctrine_manager')
        );
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @return int|null|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('<info>Start : ' . ($this->dumpMode ? 'Dump' : 'Execute') . ' all scheduled command</info>');

        // Before continue, we check that the output file is valid and writable (except for gaufrette)
        if (false !== $this->logPath && strpos($this->logPath, 'gaufrette:') !== 0 && false === is_writable($this->logPath)) {
            $output->writeln(
                '<error>'.$this->logPath.
                ' not found or not writable. You should override `log_path` in your config.yml'.'</error>'
            );

            return;
        }

        $commands = $this->em->getRepository('JMoseCommandSchedulerBundle:ScheduledCommand')->findEnabledCommand();

        $noneExecution = true;
        foreach ($commands as $command) {

            /** @var ScheduledCommand $command */
            $cron        = CronExpression::factory($command->getCronExpression());
            $nextRunDate = $cron->getNextRunDate($command->getLastExecution());
            $now         = new \DateTime();

            if ($command->isExecuteImmediately()) {
                $noneExecution = false;
                $output->writeln(
                    'Immediately execution asked for : <comment>' . $command->getCommand() . '</comment>'
                );

                if (!$input->getOption('dump')) {
                    $this->executeCommand($command, $output, $input);
                }
            } elseif ($nextRunDate < $now) {
                $noneExecution = false;
                $output->writeln(
                    'Command <comment>'.$command->getCommand().
                    '</comment> should be executed - last execution : <comment>'.
                    $command->getLastExecution()->format('d/m/Y H:i:s').'.</comment>'
                );

                if (!$input->getOption('dump')) {
                    $this->executeCommand($command, $output, $input);
                }
            }
        }

        if (true === $noneExecution) $output->writeln('Nothing to do.');
    }

    /**
     * @param ScheduledCommand $scheduledCommand
     * @param OutputInterface $output
     * @param InputInterface $input
     */
    private function executeCommand(ScheduledCommand $scheduledCommand, OutputInterface $output, InputInterface $input)
    {
        //reload command from database before every execution to avoid parallel execution
        $this->em->getConnection()->beginTransaction();
        try {
            $notLockedCommand = $this
                ->em
                ->getRepository('JMoseCommandSchedulerBundle:ScheduledCommand')
                ->getNotLockedCommand($scheduledCommand);
            //$notLockedCommand will be locked for avoiding parallel calls: http://dev.mysql.com/doc/refman/5.7/en/innodb-locking-reads.html
            if ($notLockedCommand === null) {
                throw new \Exception();
            }

            $scheduledCommand = $notLockedCommand;
            $scheduledCommand->setLastExecution(new \DateTime());
            $scheduledCommand->setLocked(false);
            $scheduledCommand = $this->em->merge($scheduledCommand);
            $this->em->flush();
            $this->em->getConnection()->commit();
        } catch (\Exception $e) {
            $this->em->getConnection()->rollBack();
            $output->writeln(
                sprintf(
                    '<error>Command %s is locked %s</error>',
                    $scheduledCommand->getCommand(),
                    (!empty($e->getMessage()) ? sprintf('(%s)', $e->getMessage()) : '')
                )
            );
            return;
        }
        try {
            $command = $this->getApplication()->find($scheduledCommand->getCommand());
        } catch (\InvalidArgumentException $e) {
            $scheduledCommand->setLastReturnCode(-1);
            $output->writeln('<error>Cannot find ' . $scheduledCommand->getCommand() . '</error>');

            return;
        }

        $input = new StringInput($scheduledCommand->getCommand().' '. $scheduledCommand->getArguments().' --env='.$input->getOption('env'));
        $command->mergeApplicationDefinition();
        $input->bind($command->getDefinition());
        
        // Disable interactive mode if the current command has no-interaction flag
        if (true === $input->hasParameterOption(array('--no-interaction', '-n'))) {
            $input->setInteractive(false);
        }

        // Use a StreamOutput or NullOutput to redirect write() and writeln() in a log file
        if (false === $this->logPath || empty($scheduledCommand->getLogFile())) {
            $logOutput = new NullOutput();
        }else{
            $logOutput = new StreamOutput(fopen(
                $this->logPath . $scheduledCommand->getLogFile(), 'a', false
            ),$this->commandsVerbosity );
        }

        // Execute command and get return code
        try {
            $output->writeln('<info>Execute</info> : <comment>' . $scheduledCommand->getCommand()
                . ' ' .$scheduledCommand->getArguments() . '</comment>');
            $result = $command->run($input, $logOutput);
        } catch (\Exception $e) {
            $logOutput->writeln($e->getMessage());
            $logOutput->writeln($e->getTraceAsString());
            $result = -1;

            // if(!empty($e->getMessage())) {
            //    if($scheduledCommand->getCommand() != 'website:update:uploadOutfitImagesToCloudinary' && $scheduledCommand->getCommand() != 'website:update:UpdateAmazonProducts' && $scheduledCommand->getCommand() != 'website:update:UpdateFBPublished' && $scheduledCommand->getCommand() != 'website:update:moveToCloudinary') {
            //        $logOutputMAIL = new StreamOutput(fopen(
            //            $this->logPath . 'mailLogger.log', 'a', false
            //        ), $this->commandsVerbosity);
            //        $logOutputMAIL->writeln('' . date('Y-m-d H:i:s') . '-----' . $scheduledCommand->getCommand() . ' ----- ' . $e->getMessage());
            //    }
            // }



        }




        if (false === $this->em->isOpen()) {
            $output->writeln('<comment>Entity manager closed by the last command.</comment>');
            $this->em = $this->em->create($this->em->getConnection(), $this->em->getConfiguration());
        }

        $scheduledCommand = $this->em->merge($scheduledCommand);
        $scheduledCommand->setLastReturnCode($result);
        $scheduledCommand->setLocked(false);
        $scheduledCommand->setExecuteImmediately(false);
        $this->em->flush();

        /*
         * This clear() is necessary to avoid conflict between commands and to be sure that none entity are managed
         * before entering in a new command
         */
        $this->em->clear();

        unset($command);
        gc_collect_cycles();
    }
}
