<?php

namespace Symfony\Component\Console\Command;

use Aws\Ec2\Ec2Client;
use Hummelflug\Parser\SiegeOutputParser;
use Hummelflug\Result\ResultInterface;
use Hummelflug\Result\ResultSet;
use Hummelflug\Result\ResultSetInterface;
use Hummelflug\Result\Storage\Factory\StorageFactory;
use Hummelflug\Result\Storage\StorageInterface;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class AttackCommand extends Command
{
    /**
     * @var string
     */
    private $file;

    /**
     * @var string
     */
    private $url;

    /**
     * @var Ec2Client
     */
    private $client;

    private $configuration;

    private $defaultKeyPairName = 'hummelflug';
    private $defaultSecurityGroupName = 'hummelflug';

    private $keyPairName;
    private $securityGroupName;

    private $attackId;

    /**
     * @var \DateTime
     */
    private $start;

    /**
     * @var StorageInterface[]
     */
    private $storages = [];

    /**
     * @var string
     */
    private $mark;

    protected function configure()
    {
        $this
            ->setName('attack')
            ->setDescription('Let the swarm attack a target.')
            ->setHelp('Let the swarm attack a target.')
            ->addArgument(
                'URL',
                InputArgument::OPTIONAL,
                'URL of the target to attack.'
            )
            ->addOption(
                'concurrent',
                '-c',
                InputOption::VALUE_REQUIRED,
                'Number of concurrent users.',
                10
            )
            ->addOption(
                'time',
                '-t',
                InputOption::VALUE_REQUIRED,
                'TIMED testing where "m" is modifier S, M, or H' . PHP_EOL .
                'ex: --time=1H, one hour test.',
                '1M'
            )
            ->addOption(
                'file',
                '-f',
                InputOption::VALUE_REQUIRED,
                'FILE, select a specific URLS FILE.'
            )
            ->addOption(
                'mark',
                '-m',
                InputOption::VALUE_REQUIRED,
                'MARK, mark the results with a string.'
            )
            ->addOption(
                'internet',
                '-i',
                InputOption::VALUE_NONE,
                'INTERNET user simulation, hits URLs randomly.'
            );
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        if (is_null($input->getOption('file')) && is_null($input->getArgument('URL'))) {
            throw new InvalidArgumentException('Provide an URL or a file, please!');
        }

        if (!is_null($input->getOption('file'))) {
            $this->file = $input->getOption('file');

            if (!file_exists($this->file)) {
                throw new InvalidArgumentException('File ' . $this->file . ' does not exists.');
            }
        }

        if (!is_null($input->getArgument('URL'))) {
            $this->url = $input->getArgument('URL');
        }

        $this->configuration = parse_ini_file('config/config.ini', true);

        foreach ($this->configuration['storage'] as $storageConfiguration) {
            $this->storages[] = StorageFactory::create($storageConfiguration);
        }

        $this->keyPairName = $this->configuration['main']['keypair'];

        $this->client = new Ec2Client([
            'key' => $this->configuration['credentials']['AWSAccessKeyId'],
            'secret' => $this->configuration['credentials']['AWSSecretKey'],
            'region' => $this->configuration['main']['region'],
            'version' => '2016-11-15',
        ]);

        if (!$this->client instanceof Ec2Client) {
            throw new \Exception('Could not create client.');
        }

        $this->attackId = uniqid();

        $this->mark = (string) $input->getOption('mark');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $swarm = json_decode(file_get_contents('config/swarm.json'));

        $output->writeln('<info>Starting the attack.</info>');
        $this->start = new \DateTime();

        $pids = [];

        foreach ($swarm->instances as $instance) {

            $pid = pcntl_fork();

            if ($pid == -1) {
                throw new \Exception('Starting up bumblebee no ' . $instance . ' failed.');
            } else if ($pid) {
                $pids[$pid] = $instance;
            } else {
                $this->attack($instance, $input, $output);
                exit;
            }
        }

        while (!empty($pids)) {
            $pid = pcntl_wait($status);

            unset ($pids[$pid]);
        }

        $output->writeln('<info>The war is over.</info>');

        $results = $this->getResults();

        $this->storeResults($results);
        $this->displayResults($results, $input, $output);
    }

    private function attack($instanceId, InputInterface $input, OutputInterface $output)
    {
        $waiter = $this->client->getWaiter(
            'InstanceRunning',
            [
                'InstanceIds' => [$instanceId],
            ]
        );

        $waiter->promise();

        $descriptions = $this->client->describeInstances([
            'InstanceId' => $instanceId,
        ]);

        $attempts = 0;

        do {
            $waiter = $this->client->getWaiter(
                'InstanceRunning',
                [
                    'InstanceIds' => [$instanceId],
                ]
            );

            $waiter->promise();

            $descriptions = $this->client->describeInstances([
                'InstanceId' => $instanceId,
            ]);

            foreach ($descriptions->get('Reservations') as $reservation) {
                foreach ($reservation['Instances'] as $instance) {
                    if ($instance['InstanceId'] == $instanceId) {
                        if (array_key_exists('PublicIpAddress', $instance)) {
                            $ipAddress = $instance['PublicIpAddress'];
                        }
                    }
                }
            }

            $attempts++;
        } while (!isset($ipAddress) && $attempts < 3);

        if (!isset($ipAddress)) {
            throw new \Exception('Could not determine IP address.');
        }

        $waiter = $this->client->getWaiter(
            'InstanceStatusOk',
            [
                'InstanceIds' => [$instanceId],
            ]
        );

        $waiter->promise();

        $start = time();

        do {
            $waiter = $this->client->getWaiter(
                'InstanceStatusOk',
                [
                    'InstanceIds' => [$instanceId],
                ]
            );

            $waiter->promise();

            $con = @ssh2_connect($ipAddress);

            sleep(5);

            if (time() - $start > 600) {
                throw new \Exception('Operation timed out.');
            }
        } while ($con === false);

        $res = ssh2_auth_pubkey_file(
            $con,
            'ec2-user',
            '.ssh/' . $this->keyPairName . '.pub',
            '.ssh/' . $this->keyPairName
        );

        if (!is_null($this->file)) {
            ssh2_scp_send($con, $this->file, '/home/ec2-user/' . basename($this->file), 0644);
        }

        $command = $this->buildCommand($input);

        $output->writeln('<info>Bumblebee ' . $instanceId . ' starts attack.</info>');

        $stream = ssh2_exec($con, $command);
        stream_set_blocking($stream, true);
        $content = stream_get_contents($stream);

        file_put_contents('.' . $this->attackId . '.' . $instanceId . '.output', $content);

        $output->writeln('<info>Bumblebee ' . $instanceId . ' finished attack.</info>');

        $stream = ssh2_exec($con, 'rm /home/ec2-user/siege.log');
        stream_set_blocking($stream, true);
        $content = stream_get_contents($stream);
    }

    private function buildCommand(InputInterface $input)
    {
        $command = 'siege -q ';

        if ($input->hasOption('concurrent')) {
            $command .= '--concurrent=' . $input->getOption('concurrent') . ' ';
        }

        if (!is_null($input->getOption('time'))) {
            $command .= '--time=' . $input->getOption('time') . ' ';
        }

        if ($input->getOption('internet')) {
            $command .= '-i ';
        }

        if (!is_null($this->file)) {
            $command .= '-f ' . basename($this->file);
        }

        if (!is_null(($this->url))) {
            $command .= $this->url;
        }

        $command .= ' 2>&1';

        return $command;
    }

    /**
     * @return ResultSet
     */
    private function getResults()
    {
        $results = [];

        foreach (glob('.' . $this->attackId . '.*.output') as $filename) {
            $result = SiegeOutputParser::parse($filename);

            $result->setAttackId($this->attackId);
            $result->setInstanceId(explode('.', $filename)[2]);
            $result->setStart($this->start);
            $result->setMark($this->mark);

            $results[] = $result;

            unlink($filename);
        }

        $resultSet = new ResultSet($results, $this->attackId);

        $resultSet->setStart($this->start);
        $resultSet->setMark($this->mark);

        return $resultSet;
    }

    private function displayResults(ResultInterface $results, InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $io->section('Attack Summary');

        $io->table(
            [],
            [
                ['transactions', $results->getTransactions(),],
                ['elapsed time', number_format($results->getElapsedTime(), 2) . 's',],
                ['data transferred', number_format($results->getDataTransferred(), 2) . 'MB',],
                ['throughput', number_format($results->getThroughput(), 2) . 'MB/s',],
                ['transactions successful', $results->getTransactionsSuccessful(),],
                ['transactions failed', $results->getTransactionsFailed(),],
                ['availability', number_format($results->getAvailability() * 100, 2) . '%',],
                ['concurrency', number_format($results->getConcurrency(), 2),],
                ['average response time', $results->getResponseTimeAverage() . 's',],
                ['longest transaction', number_format($results->getLongestTransaction(), 2) . 's',],
                ['shortest transaction', number_format($results->getShortestTransaction(), 2) . 's',],
            ]
        );
    }

    private function storeResults(ResultSetInterface $results)
    {
        foreach ($this->storages as $storage) {
            $storage->store($results);
        }
    }
}