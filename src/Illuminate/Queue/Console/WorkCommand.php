<?php

namespace Illuminate\Queue\Console;

use Carbon\Carbon;
use Illuminate\Queue\Worker;
use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

class WorkCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'queue:work';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process the next job on a queue';

    /**
     * The queue worker instance.
     *
     * @var \Illuminate\Queue\Worker
     */
    protected $worker;

    /**
     * Create a new queue listen command.
     *
     * @param  \Illuminate\Queue\Worker  $worker
     * @return void
     */
    public function __construct(Worker $worker)
    {
        parent::__construct();

        $this->worker = $worker;
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function fire()
    {
        if ($this->downForMaintenance() && ! $this->option('daemon')) {
            return $this->worker->sleep($this->option('sleep'));
        }

        // We'll listen to the processed and failed events so we can write information
        // to the console as jobs are processed, which will let the developer watch
        // which jobs are coming through a queue and be informed on its progress.
        $this->listenForEvents();

        $queue = $this->option('queue');

        $delay = $this->option('delay');

        // The memory limit is the amount of memory we will allow the script to occupy
        // before killing it and letting a process manager restart it for us, which
        // is to protect us against any memory leaks that will be in the scripts.
        $memory = $this->option('memory');

        $connection = $this->argument('connection');

        // We need to get the right queue for the connection which is set in the queue
        // configuration file for the application. We will pull it based on the set
        // connection being run for the queue operation currently being executed.
        $queue = $this->getQueue($connection);

        $response = $this->runWorker(
            $connection, $queue, $delay, $memory, $this->option('daemon')
        );
    }

    /**
     * Listen for the queue events in order to update the console output.
     *
     * @return void
     */
    protected function listenForEvents()
    {
        $this->laravel['events']->listen(JobProcessed::class, function ($event) {
            $this->writeOutput($event->job, false);
        });

        $this->laravel['events']->listen(JobFailed::class, function ($event) {
            $this->writeOutput($event->job, true);
        });
    }

    /**
     * Get the name of the queue connection to listen on.
     *
     * @param  string  $connection
     * @return string
     */
    protected function getQueue($connection)
    {
        if ($this->option('queue')) {
            return $this->option('queue');
        }

        if (is_null($connection)) {
            $connection = $this->laravel['config']['queue.default'];
        }

        return $this->laravel['config']->get(
            "queue.connections.{$connection}.queue", 'default'
        );
    }

    /**
     * Run the worker instance.
     *
     * @param  string  $connection
     * @param  string  $queue
     * @param  int  $delay
     * @param  int  $memory
     * @param  bool  $daemon
     * @return array
     */
    protected function runWorker($connection, $queue, $delay, $memory, $daemon = false)
    {
        $this->worker->setExceptionHandler(
            $this->laravel['Illuminate\Contracts\Debug\ExceptionHandler']
        );

        if ($daemon) {
            $this->worker->setCache($this->laravel['cache']->driver());

            return $this->worker->daemon(
                $connection, $queue, $delay, $memory, $this->option('timeout', 60),
                $this->option('sleep'), $this->option('tries')
            );
        }

        return $this->worker->runNextJob(
            $connection, $queue, $delay,
            $this->option('sleep'), $this->option('tries')
        );
    }

    /**
     * Write the status output for the queue worker.
     *
     * @param  \Illuminate\Contracts\Queue\Job  $job
     * @param  bool  $failed
     * @return void
     */
    protected function writeOutput(Job $job, $failed)
    {
        if ($failed) {
            $this->output->writeln('<error>['.Carbon::now()->format('Y-m-d H:i:s').'] Failed:</error> '.$job->resolveName());
        } else {
            $this->output->writeln('<info>['.Carbon::now()->format('Y-m-d H:i:s').'] Processed:</info> '.$job->resolveName());
        }
    }

    /**
     * Determine if the worker should run in maintenance mode.
     *
     * @return bool
     */
    protected function downForMaintenance()
    {
        if ($this->option('force')) {
            return false;
        }

        return $this->laravel->isDownForMaintenance();
    }

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['connection', InputArgument::OPTIONAL, 'The name of connection', null],
        ];
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return [
            ['queue', null, InputOption::VALUE_OPTIONAL, 'The queue to listen on'],

            ['daemon', null, InputOption::VALUE_NONE, 'Run the worker in daemon mode'],

            ['delay', null, InputOption::VALUE_OPTIONAL, 'Amount of time to delay failed jobs', 0],

            ['force', null, InputOption::VALUE_NONE, 'Force the worker to run even in maintenance mode'],

            ['memory', null, InputOption::VALUE_OPTIONAL, 'The memory limit in megabytes', 128],

            ['sleep', null, InputOption::VALUE_OPTIONAL, 'Number of seconds to sleep when no job is available', 3],

            ['timeout', null, InputOption::VALUE_OPTIONAL, 'The number of seconds a daemon child process can run', 60],

            ['tries', null, InputOption::VALUE_OPTIONAL, 'Number of times to attempt a job before logging it failed', 0],
        ];
    }
}
