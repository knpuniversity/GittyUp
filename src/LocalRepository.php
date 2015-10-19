<?php

namespace KnpU\GittyUp;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Helps take action and get information about a locally-cloned repository
 *
 * @author Ryan Weaver <ryan@knpuniversity.com>
 */
class LocalRepository
{
    private $repoPath;

    private $logger;

    public function __construct($repositoryPath)
    {
        $this->repoPath = $repositoryPath;
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Returns the name of the branch we're on right now
     *
     * @return null|string
     */
    public function getCurrentBranch()
    {
        $cmd = 'git branch';
        $process = $this->createProcess($cmd);
        $this->executeProcess($process);

        // very hacky way to find the current branch - will get a better Git command later
        // current branch is "* branch_name"
        $lines = explode("\n", $process->getOutput());
        foreach ($lines as $line) {
            if (strpos($line, '*') === 0) {
                // we found it!
                // skip the * and the space
                return substr($line, 2);
            }
        }

        // we are not currently on a tuts branch
        return null;
    }

    /**
     * Moves to the given branch, creating it if necessary
     *
     * @param string $branchName
     * @param bool $createIfNecessary
     * @throws \Exception
     */
    public function moveToBranch($branchName, $createIfNecessary = false)
    {
        if ($this->doesBranchExist($branchName)) {
            $cmd = sprintf('git checkout %s', $branchName);
        } else {
            if ($createIfNecessary) {
                $cmd = sprintf('git checkout -b %s', $branchName);
            } else {
                throw new \Exception(sprintf('Cannot move to %s, the branch does not exist!', $branchName));
            }
        }

        $process = $this->createProcess($cmd);
        $this->executeProcess($process);
    }

    public function createAndMoveToBranch($branchName, $fromBranch = null)
    {
        $cmd = sprintf('git checkout -b %s', escapeshellarg($branchName));
        if ($fromBranch) {
            $cmd .= sprintf(' %s', escapeshellarg($fromBranch));
        }

        $process = $this->createProcess($cmd);
        $this->executeProcess($process);
    }

    /**
     * Merge this branch into the current branch
     *
     * @param string $branchName
     * @throws \Exception
     */
    public function merge($branchName, $message = null, $allowFastForward = true)
    {
        $cmd = sprintf('git merge %s', $branchName);

        if ($message) {
            $cmd .= sprintf(' -m %s', escapeshellarg($message));
        }

        if (!$allowFastForward) {
            $cmd .= ' --no-ff';
        }

        $process = $this->createProcess($cmd);
        $this->executeProcess($process);
    }

    /**
     * @param string $branchName
     * @return bool
     */
    public function doesBranchExist($branchName)
    {
        $cmd = sprintf('git branch');

        $process = $this->createProcess($cmd);
        $this->executeProcess($process);

        // very hacky way to find the current branch - will get a better Git command later
        // current branch is "* branch_name"
        $lines = explode("\n", $process->getOutput());
        foreach ($lines as $line) {
            $branch = substr($line, strrpos($line, ' ') + 1);
            if ($branch == $branchName) {
                return true;
            }
        }

        return false;
    }

    /**
     * Allows you to read a file from a branch without switching to that branch
     *
     * @param string $path
     * @param string $branchName
     * @return string
     */
    public function readFileContentsFromBranch($path, $branchName)
    {
        if (!$this->doesBranchExist($branchName)) {
            return null;
        }

        $cmd = sprintf('git show %s:%s', $branchName, $path);

        $process = $this->createProcess($cmd);
        $this->executeProcess($process, false);

        // if the process was not successful, the file just doesn't exist
        return $process->isSuccessful() ? $process->getOutput() : '';
    }

    /**
     * Adds a file to git - includes adding a "deleted" file
     *
     * @param string $path
     * @param bool $force
     */
    public function addFile($path, $force = false)
    {
        $this->addFiles(array($path), $force);
    }

    /**
     * Adds files to git - includes adding "deleted" files
     *
     * @param array $paths
     * @param bool $force
     */
    public function addFiles(array $paths, $force = false)
    {
        $safePaths = array();
        foreach ($paths as $path) {
            $safePaths[] = escapeshellarg($path);
        }

        $cmd = 'git add --all';
        if ($force) {
            $cmd .= ' -f';
        }

        $cmd .= sprintf(' %s', implode(' ', $safePaths));

        $process = $this->createProcess($cmd);
        $this->executeProcess($process);
    }

    /**
     * git rm FILENAME
     *
     * @param string $path
     * @throws GitException
     */
    public function remove($path)
    {
        $safePath = escapeshellarg($path);

        $cmd = 'git rm';
        $cmd .= sprintf(' %s', $safePath);

        $process = $this->createProcess($cmd);
        $this->executeProcess($process);
    }

    public function commit($message)
    {
        $cmd = sprintf('git commit -m %s', escapeshellarg($message));

        $process = $this->createProcess($cmd);
        $this->executeProcess($process);
    }

    public function stash()
    {
        $cmd = 'git stash save "Temporary stash while saving tuts configuration"';

        $process = $this->createProcess($cmd);
        $this->executeProcess($process);
    }

    public function stashPop()
    {
        $cmd = 'git stash pop';

        $process = $this->createProcess($cmd);
        $this->executeProcess($process);
    }

    /**
     * Sets the description on a branch
     *
     * @param $branchName
     * @param $description
     */
    public function setBranchDescription($branchName, $description)
    {
        $cmd = sprintf('git config "branch.%s.description" %s', $branchName, escapeshellarg($description));

        $process = $this->createProcess($cmd);
        $this->executeProcess($process);
    }

    /**
     * Retrieves the raw git branch description
     *
     * @param $branchName
     * @return string
     */
    public function getBranchDescription($branchName)
    {
        $cmd = sprintf('git config --get "branch.%s.description"', $branchName);

        $process = $this->createProcess($cmd);
        $this->executeProcess($process, true, sprintf('Error reading "%s" branch description.', $branchName));

        return trim($process->getOutput());
    }

    public function getLastCommitSha($branchName = null)
    {
        return $this->getShaFromLog($branchName);
    }

    /**
     * Returns a sha for the given branch.
     *
     * It can be any number of commits back in history
     *
     * @param string $branchName
     * @param int $offset How many commits back (0 = last commit, -1 = commit before that)
     * @return string|bool
     * @throws \Exception
     */
    public function getShaFromLog($branchName = null, $offset = 0)
    {
        $cmd = sprintf(
            'git rev-parse %s^%s',
            $branchName ? $branchName : 'HEAD',
            intval($offset)
        );

        $process = $this->createProcess($cmd);
        $this->executeProcess($process);

        $sha = trim($process->getOutput());

        if (strlen($sha) != 40) {
            throw new \Exception(sprintf('Invalid commit sha: "%s"', $sha));
        }

        return $sha;
    }

    /**
     * @param string $sha1 Sha, branch name, tag etc
     * @param string $sha2 Sha, branch name, tag etc
     * @return array
     */
    public function getCommitShasBetween($sha1, $sha2)
    {
        $cmd = sprintf('git log %s..%s --pretty=format:%%H', escapeshellarg($sha1), escapeshellarg($sha2));

        $process = $this->createProcess($cmd);
        $this->executeProcess($process);

        if (!$process->getOutput()) {
            return array();
        }

        return explode("\n", trim($process->getOutput()));
    }

    public function getLastCommitShas($branchName, $limit = 10)
    {
        $cmd = sprintf('git log %s --pretty=format:%%H | head -n %s', escapeshellarg($branchName), $limit);

        $process = $this->createProcess($cmd);
        $this->executeProcess($process);

        if (!$process->getOutput()) {
            return array();
        }

        return explode("\n", trim($process->getOutput()));
    }

    public function cherryPick($sha)
    {
        $cmd = sprintf('git cherry-pick %s', $sha);

        $process = $this->createProcess($cmd);
        $this->executeProcess($process);
    }

    public function diff($start, $end = null)
    {
        $cmd = sprintf('git diff --binary %s', escapeshellarg($start));
        if ($end) {
            $cmd = sprintf('%s..%s', $cmd, escapeshellarg($end));
        }

        $process = $this->createProcess($cmd);
        $this->executeProcess($process);

        return $process->getOutput();
    }

    /**
     * Searches from headSha to untilSha looking for a sha whose message
     * matches the give needle.
     *
     * @param string $needle The item to search for in the commit message
     * @param string $headSha The most recent commit where to start the search
     * @param string $untilSha An older commit to stop searching
     * @return string|null
     */
    public function findShaByMessage($needle, $headSha, $untilSha)
    {
        $cmd = sprintf('git log %s..%s --pretty="format:%%H %%B"', escapeshellarg($untilSha), escapeshellarg($headSha));

        $process = $this->createProcess($cmd);
        $this->executeProcess($process);

        if (!$process->getOutput()) {
            return array();
        }

        $commits = explode("\n", trim($process->getOutput()));

        foreach ($commits as $commit) {
            $separatorPos = strpos($commit, ' ');
            $commitSha = substr($commit, 0, $separatorPos);
            $commitMessage = substr($commit, $separatorPos + 1);

            if (strpos($commitMessage, $needle) !== false) {
                return $commitSha;
            }
        }

        return null;
    }

    public function getCommitMessage($sha)
    {
        $cmd = sprintf('git log --format=%%B -n 1 %s', escapeshellarg($sha));

        $process = $this->createProcess($cmd);
        $this->executeProcess($process);

        return trim($process->getOutput());
    }


    public function deleteBranch($branchName, $force = false)
    {
        $cmd = sprintf(
            'git branch %s %s',
            $force ? '-D' : '-d',
            $branchName
        );

        $process = $this->createProcess($cmd);
        $this->executeProcess($process);
    }

    /**
     * Is the working directory totally clean
     *
     * @param null $path
     * @return bool
     */
    public function isWorkingDirectoryClean($path = null)
    {
        $cmd = 'git status';
        if ($path) {
            $cmd .= ' -- '.escapeshellarg($path);
        }
        $process = $this->createProcess($cmd);
        $process->run();

        return strpos($process->getOutput(), 'working directory clean') !== false;
    }

    /**
     * Checks to see if there are tracked or unstaged changes.
     *
     * Basically, are we totally clean or not (other than new files).
     *
     * @param null $path
     * @return bool
     */
    public function areThereUncommittedChanges($path = null)
    {
        // HEAD shows staged and unstaged changes
        $cmd = 'git diff HEAD --name-only';
        if ($path) {
            $cmd .= ' -- '.escapeshellarg($path);
        }
        $process = $this->createProcess($cmd);
        $this->executeProcess($process);

        if (!$diff = $process->getOutput()) {
            return false;
        }

        $diffs = explode("\n", $diff);

        return count($diffs) > 0;
    }

    /**
     * Are there (specifically) any staged changes
     *
     * @param null $path
     * @return bool
     */
    public function areThereStagedChanges($path = null)
    {
        $cmd = 'git diff --cached --name-only';
        if ($path) {
            $cmd .= ' -- '.escapeshellarg($path);
        }
        $process = $this->createProcess($cmd);
        $this->executeProcess($process);

        if (!$diff = $process->getOutput()) {
            return false;
        }

        $diffs = explode("\n", $diff);

        return count($diffs) > 0;
    }

    /**
     * @param string $diff
     * @return array|boolean False on failure, otherwise, an array of patched files
     */
    public function applyDiff($diff)
    {
        // apply the patch
        $cmd = sprintf('git apply');
        $process = $this->createProcess($cmd);
        $process->setStdin($diff);
        $this->executeProcess($process);

        // gives us details on the files that would be applied
        $cmd = sprintf('git apply --numstat');
        $process = $this->createProcess($cmd);
        $process->setStdin($diff);
        $this->executeProcess($process);

        // each line looks like: 1	0	bar.txt (tab separated)
        $lines = explode("\n", trim($process->getOutput()));
        $files = array();
        foreach ($lines as $line) {
            $parts = explode("\t", $line);
            if (count($parts) != 3) {
                throw new \Exception(sprintf('Cannot parse line: %s', $line));
            }

            $files[] = trim($parts[2]);
        }

        return $files;
    }

    /**
     * Is this file modified or new?
     *
     * @param string $filename
     * @return bool
     */
    public function isFileModified($filename)
    {
        if ($this->areThereUncommittedChanges($filename)) {
            return true;
        }

        // 3) It's not modified, but it could be a new file
        return file_exists($filename);
    }

    /**
     * Adds and commits the file
     *
     * @param string $path
     * @param string $message
     * @return bool Returns true if there was actually a commit that was made
     */
    public function addAndCommitFile($path, $message)
    {
        if (!$this->isFileModified($path)) {
            return false;
        }

        $this->addFile($path);
        $this->commit($message);

        return true;
    }

    /**
     * Simply run this command - should be used sparingly
     *
     * @param $cmd
     * @throws GitException
     */
    public function execute($cmd)
    {
        $process = $this->createProcess($cmd);
        $this->executeProcess($process);

        return $process->getOutput();
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
        if ($this->logger) {
            $this->logger->debug(sprintf('Executing: %s', $process->getCommandLine()));
        }

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
        $process->setWorkingDirectory($this->repoPath);

        return $process;
    }
}