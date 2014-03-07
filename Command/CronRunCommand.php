<?php
/**
 * This file is part of the SymfonyCronBundle package.
 *
 * (c) Dries De Peuter <dries@nousefreak.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Cron\CronBundle\Command;

use Cron\Cron;
use Cron\CronBundle\Entity\CronJob;
use Cron\CronBundle\Entity\CronReport;
use Cron\Job\ShellJob;
use Cron\Resolver\ArrayResolver;
use Cron\Schedule\CrontabSchedule;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * @author Dries De Peuter <dries@nousefreak.be>
 */ 
class CronRunCommand extends ContainerAwareCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('cron:run')
            ->setDescription('Runs any currently schedule cron jobs')
            ->addArgument('job', InputArgument::OPTIONAL, 'Run only this job (if enabled)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force the current job.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $cron = new Cron();
        $cron->setExecutor($this->getContainer()->get('cron.executor'));
        if ($input->getArgument('job')) {
            $resolver = $this->getJobResolver($input->getArgument('job'), $input->hasOption('force'));
        } else {
            $resolver = $this->getContainer()->get('cron.resolver');
        }
        $cron->setResolver($resolver);

        $time = microtime(true);
        $dbReport = $cron->run();

        while($cron->isRunning()) {}

        $output->writeln('time: ' . (microtime(true) - $time));

        $em = $this->getContainer()->get('doctrine')->getManager();
        foreach ($dbReport->getReports() as $report) {
            $dbReport = new CronReport();
            $dbReport->setJob($report->getJob()->raw);
            $dbReport->setOutput(implode("\n", (array)$report->getOutput()));
            $dbReport->setExitCode($report->getJob()->getProcess()->getExitCode());
            $dbReport->setRunAt(\DateTime::createFromFormat('U.u', (string)$report->getStartTime()));
            $dbReport->setRunTime($report->getEndTime() - $report->getStartTime());
            $em->persist($dbReport);
        }
        $em->flush();
    }

    /**
     * @param string $jobName
     * @param bool $force
     * @return ArrayResolver
     * @throws \InvalidArgumentException
     */
    protected function getJobResolver($jobName, $force = false)
    {
        $dbJob = $this->queryJob($jobName);

        if (!$dbJob) {
            throw new \InvalidArgumentException('Unknown job.');
        }

        $finder = new PhpExecutableFinder();
        $phpExecutable = $finder->find();
        $rootDir = dirname($this->getContainer()->getParameter('kernel.root_dir'));

        $job = new ShellJob();
        $job->setCommand($phpExecutable . ' app/console ' . $dbJob->getCommand(), $rootDir);
        $job->setSchedule(new CrontabSchedule($dbJob->getSchedule()));
        $job->raw = $dbJob;

        $resolver = new ArrayResolver();
        $resolver->addJob($job);

        return $resolver;
    }

    /**
     * @param string $jobName
     * @return CronJob
     */
    protected function queryJob($jobName)
    {
        return $this->getContainer()->get('doctrine')->getRepository('CronCronBundle:CronJob')
            ->findOneBy(array(
                    'enabled' => 1,
                    'name' => $jobName,
                ));
    }
}