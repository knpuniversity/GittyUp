<?php

namespace KnpU\GittyUp;

use Symfony\Component\Process\Process;

/**
 * Helps perform a few tasks on a repository that is not cloned locally.
 *
 * @author Ryan Weaver <ryan@knpuniversity.com>
 */
class RemoteRepository
{
    private $url;

    public function __construct($url)
    {
        $this->url = $url;
    }

    public function getLastCommitSha($branchName)
    {
        // "git ls-remote [url]" lists references in a remote repository.
        // "grep [branch]"       shows only the current branch of interest.
        // "cut -f1"             displays only the 1st column (the sha).
        $commands = [
            sprintf('git ls-remote %s', escapeshellarg($this->url)),
            // this is a little hacky, we get a list of all branches then grep loosely for our branch name
            // if you have 2 similar branch names, this will have problems
            sprintf('grep --max-count=1 %s', escapeshellarg($branchName)),
            'cut -f1'
        ];

        $process = $this->createProcess(implode(' | ', $commands));

        $this->executeProcess($process);

        $sha =  trim($process->getOutput());

        // in some cases, I see error output, but with a successful 0 code :(
        if (!$sha) {
            throw new GitException(sprintf('Could not determine last sha: '.$process->getErrorOutput()));
        }

        return $sha;
    }

    /**
     * Returns an array of all of the branches
     *
     * @return array
     */
    public function getAllBranches()
    {
        // "git ls-remote [url]" lists references in a remote repository.
        // "grep refs/head"      filters down to just branches
        // "cut -f2"             displays only the 2nd column (the refs/heads/BRANCH)
        $commands = [
            sprintf('git ls-remote %s', escapeshellarg($this->url)),
            'grep refs/heads',
            'cut -f2'
        ];

        $process = $this->createProcess(implode(' | ', $commands));

        $this->executeProcess($process);

        $output =  trim($process->getOutput());
        $lines = explode("\n", $output);
        $branches = array();
        foreach ($lines as $line) {
            // filter out the refs/heads starting point
            $branches[] = substr($line, 11);
        }

        return $branches;
    }

    /**
     * Executes a Process and throws a consistent exception
     *
     * @param Process $process
     * @param bool $throwExceptionOnFailure
     * @param null $extraMessage
     * @throws GitException
     */
    private function executeProcess(Process $process, $throwExceptionOnFailure = true, $extraMessage = null)
    {
        $process->run();

        if (!$process->isSuccessful() && $throwExceptionOnFailure) {
            // sometimes an unsuccessful command will still render output, instead of error output
            // this happens, for example, when merging and having a conflict
            $output = $process->getErrorOutput() ? $process->getErrorOutput() : $process->getOutput();

            $msg = sprintf('Error executing "%s": %s', $process->getCommandLine(), $output);

            if ($extraMessage) {
                $msg = $extraMessage.' '.$msg;
            }

            throw new GitException($msg);
        }
    }

    /**
     * @return Process
     */
    private function createProcess($cmd)
    {
        $process = new Process($cmd);

        return $process;
    }
}
