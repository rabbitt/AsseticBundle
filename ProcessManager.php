<?php

/*
 * This file is part of the Symfony framework.
 *
 * (c) Carl P. Corliss <rabbitt@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Symfony\Bundle\AsseticBundle;

use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractProcessManager {
    // Default number of children to spawn
    const DEFAULT_CHILD_COUNT = 4;

    // The queue containing all runnable jobs
    private $queue;

    /**
     * Constructor
     */
    public function __construct($max_children = self::DEFAULT_CHILD_COUNT)
    {
        $this->queue = array();
        $this->max_children = $max_children > 0 ? $max_children : DEFAULT_CHILD_COUNT;
    }

    /**
     * Adds a runnable code block to the end of the run queue
     *
     * @param Closure $runner The code block to run
     */
    public function enqueue(\Closure $runner)
    {
        return array_push($this->queue, $runner);
    }

    /**
     * Pops a runnable code block off the front of the run queue
     */
    public function dequeue()
    {
        return array_shift($this->queue);
    }

    abstract public function run();
}

/**
 * A forking job queue manager. Runnable jobs are added to the queue,
 * and subsequently divvied up amongst all available workers equally.
 */
class EqualQueueWorkers extends AbstractProcessManager {
    /**
     * Splits the run queue into even buckets of jobs, one per child up
     * to the maximum allowable children and then starts each child and
     * it's run queue.
     *
     * @param Integer $max_children
     */
    public function run()
    {
        $children        = array();
        $jobs_per_bucket = abs(intval(ceil(count($this->queue) / $this->max_children)));
        $work_chunks     = array_chunk($this->queue, $jobs_per_bucket);

        $this->queue = array();

        // after splitting the jobs into even buckets for each child
        // spawn each child and give it it's bucket of jobs
        while ($jobs = array_shift($work_chunks)) {
            if ( ($pid = pcntl_fork()) == -1 ) {
                // error - try again later

                // XXX: if we're in a situation where we can't fork (e.g.,
                // when ulimit prevents us), we should probably detect that
                // and just quit instead of pointlessly retrying ad infinitum.
                array_push($work_chunks, $jobs);
            } elseif ($pid > 0) {
                // in parent - store child's pid
                $children[$pid] = $pid;
            } else {
                // in child - run the job and exit
                foreach ($jobs as $runner) { $runner(); }
                exit(0);
            }
        }

        // wait for any children still running to finish
        while (count($children) > 0) {
            // we've reached our max children, wait until one exits
            if ( ($pid = pcntl_wait($status)) > 0) {
                unset($children[$pid]);
            }
        }
    }
}
