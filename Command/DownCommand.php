<?php

namespace Symfony\Component\Console\Command;

use Aws\Ec2\Ec2Client;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DownCommand extends Command
{
    private $defaultKeyPairName = 'hummelflug';
    private $defaultSecurityGroupName = 'hummelflug';

    private $keyPairName;
    private $securityGroupName;

    /**
     * @var Ec2Client
     */
    private $client;
    private $configuration;

    protected function configure()
    {
        $this
            ->setName('down')
            ->setDescription('Let all the bumblebees fall asleep.')
            ->setHelp('Let all the bumblebees fall asleep.')
            ->addOption(
                'config',
                null,
                InputOption::VALUE_REQUIRED,
                'Provide the path to the config file, please!'
            )
            ->addOption(
                'swarm',
                null,
                InputOption::VALUE_REQUIRED,
                'Provide the path to the swarm file, please!'
            )
            ->addOption(
                'keypair',
                '-k',
                InputOption::VALUE_REQUIRED,
                'Provide a keypair name, please!',
                $this->defaultKeyPairName
            )
            ->addOption(
                'groupname',
                '-g',
                InputOption::VALUE_REQUIRED,
                'Provide a security group name, please!',
                $this->defaultSecurityGroupName
            )
            ->addOption(
                'AWSAccessKeyId',
                null,
                InputOption::VALUE_REQUIRED,
                'AWSAccessKeyId'
            )
            ->addOption(
                'AWSSecretKey',
                null,
                InputOption::VALUE_REQUIRED,
                'AWSSecretKey'
            );
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->keyPairName = $input->getOption('keypair');
        $this->securityGroupName = $input->getOption('groupname');

        if (!is_null($input->getOption('config'))) {
            $configFile = $input->getOption('config');
        } else {
            $configFile = __DIR__ . '/../config/config.ini';
        }

        if (!file_exists($configFile)) {
            throw new \Exception('Configuration file ' . $configFile . ' does not exists.');
        }

        $this->configuration = parse_ini_file($configFile, true);

        $awsKeyId = $input->getOption('AWSAccessKeyId');
        $awsSecretKey = $input->getOption('AWSSecretKey');

        $this->client = new Ec2Client([
            'credentials' => [
                'key' => $awsKeyId ?: $this->configuration['credentials']['AWSAccessKeyId'],
                'secret' => $awsSecretKey ?: $this->configuration['credentials']['AWSSecretKey'],
            ],
            'region' => $this->configuration['main']['region'],
            'version' => '2016-11-15',
        ]);

        if (!$this->client instanceof Ec2Client) {
            throw new \Exception('Could not create client.');
        }
    }


    public function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('<info>Shutting down the swarm.</info>');

        if (!is_null($input->getOption('swarm'))) {
            $swarmFile = $input->getOption('swarm');
        } else {
            $swarmFile = __DIR__ . '/../config/swarm.json';
        }

        if (!file_exists($swarmFile)) {
            throw new \Exception('Swarm file ' . $swarmFile . ' does not exists.');
        }

        $swarm = json_decode(file_get_contents($swarmFile));

        $this->client->stopInstances(
            [
                'InstanceIds' => $swarm->instances,
            ]
        );

        do {
            $instanceRunning = false;

            $waiter = $this->client->getWaiter(
                'InstanceStopped',
                [
                    'InstanceIds' => $swarm->instances,
                ]
            );

            $waiter->promise();

            $result = $this->client->describeInstances(
                [
                    'InstanceIds' => $swarm->instances,
                ]
            );

            foreach ($result['Reservations'] as $reservation) {
                foreach ($reservation['Instances'] as $instance) {
                    if (in_array($instance['State']['Name'], ['stopping', 'stopped'])) {
                        continue;
                    }

                    $output->writeln(
                        '<info>Waiting for bumblebee ' . $instance['InstanceId']
                        . '. (status: ' . $instance['State']['Name'] . ')</info>'
                    );

                    $instanceRunning = true;
                }
            }
        } while ($instanceRunning);

        $output->writeln('<info>Your swarm is enjoying its time off.</info>');
    }
}