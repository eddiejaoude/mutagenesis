<?php
/**
 * Mutagenesis
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://github.com/padraic/mutateme/blob/rewrite/LICENSE
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to padraic@php.net so we can send you a copy immediately.
 *
 * @category   Mutagenesis
 * @package    Mutagenesis
 * @subpackage UnitTests
 * @copyright  Copyright (c) 2010 Pádraic Brady (http://blog.astrumfutura.com)
 * @license    http://github.com/padraic/mutateme/blob/rewrite/LICENSE New BSD License
 */

namespace Mutagenesis\Adapter;

require_once 'PHPUnit/TextUI/Command.php';

if (class_exists('PHP_CodeCoverage_Filter', true)) {
    \PHP_CodeCoverage_Filter::getInstance()->addFileToBlacklist(__FILE__, 'PHPUNIT');
}

class Phpunit extends AdapterAbstract
{

    /**
     * Runs the tests suite according to Runner set options and the execution
     * order of test case (if any). It then returns an array of two elements.
     * First element is a boolean result value indicating if tests passed or not.
     * Second element is an array containing the key "stdout" which stores the
     * output from the last test run.
     *
     * @param \Mutagenesis\Runner\RunnerAbstract $baseRunner
     * @param bool $useStdout
     * @param bool $firstRun
     * @param array $mutation
     * @param array $testCases
     * @return array
     */
    public function runTests(\Mutagenesis\Runner\Base $runner, $useStdout = false,
    $firstRun = false, array $mutation = array(), array $testCases = array())
    {
        $options = $runner->getOptions();
        $job = new \Mutagenesis\Utility\Job;
        if(!$useStdout) {
            array_unshift($options['clioptions'], '--stderr');
        }
        if (!in_array('--stop-on-failure', $options['clioptions'])) {
            array_unshift($options['clioptions'], '--stop-on-failure');
        }
        array_unshift($options['clioptions'], 'phpunit');
        if ($firstRun) {
            $options['clioptions'] = array_merge(
                $options['clioptions'],
                array('--log-junit', $options['cache'] . '/mutagenesis.xml'),
                explode(' ', $options['constraint'])
            );
        }
        if (count($testCases) > 0) { // tests cases always 0 on first run
            foreach ($testCases as $case) {
                $args = $options;
                $args['clioptions'][] = $case['class'];
                $args['clioptions'][] = $case['file'];
                $output = self::execute(
                    $job->generate(
                        $mutation,
                        $args,
                        $runner->getBootstrap()
                    ),
                    $runner->getTimeout()
                );
                if (!$this->processOutput($output['stdout'])) {
                    return array(false, $output);
                }
            }
        } else {
            $output = self::execute(
                $job->generate(
                    $mutation,
                    $options,
                    $runner->getBootstrap()
                ),
                $runner->getTimeout()
            );
            if (!$this->processOutput($output['stdout'])) {
                return array(false, $output);
            }
        }
        return array(true, $output);
    }

    /**
     * Execute the generated job which is to call the static main method.
     *
     * @param string $jobScript
     * @return string $output
     */
    public static function execute($jobScript, $timeout = 120)
    {
        $output = \Mutagenesis\Utility\Process::run($jobScript, $timeout);
        return $output;
    }

    /**
     * Uses an instance of PHPUnit_TextUI_Command to execute the PHPUnit
     * tests and simulates any Mutagenesis supported command line options suitable
     * for PHPUnit. At present, we merely dissect a generic 'options' string
     * equivelant to anything typed into a console after a normal 'phpunit'
     * command. The adapter captures the TextUI output for further processing.
     *
     * To prevent duplication of output from stdout, PHPUnit is hard
     * configured to write to stderrm(stdin is used in proc_open call)
     *
     * @param array $arguments Mutagenesis arguments to pass to PHPUnit
     * @return void
     */
    public static function main($arguments, $mutation = null, $bootstrap = null)
    {
        $arguments = unserialize($arguments);
        
        /**
         * Grab the Runkit extension utility and apply the mutation if needed
         */
        if (!is_null($mutation)) {
            $mutation = unserialize($mutation);
            if (!empty($mutation)) {
                if (!is_null($bootstrap)) {
                    require_once $bootstrap;
                }
                if(!in_array('runkit', get_loaded_extensions())) {
                    throw new \Exception(
                        'Runkit extension is not loaded. Unfortunately, runkit'
                        . ' is essential for Mutagenesis. Please see the manual or'
                        . ' README which explains how to install an updated runkit'
                        . ' extension suitable for Mutagenesis and PHP 5.3.'
                    );
                }
                $runkit = new \Mutagenesis\Utility\Runkit;
                $runkit->applyMutation($mutation);
            }
        }

        //var_dump($arguments); exit;

        /**
         * Switch working directory to tests and execute the test suite
         */
        $originalWorkingDirectory = getcwd();
        if (isset($arguments['tests'])) {
            chdir($arguments['tests']);
        }
        $command = new \PHPUnit_TextUI_Command;
        $command->run($arguments['clioptions'], false);
        chdir($originalWorkingDirectory);
    }

    /**
     * Parse the PHPUnit text result output to see if there were any failures.
     * In the context of mutation testing, a test failure is good (i.e. the
     * mutation was detected by the test suite).
     *
     * @param string $output
     * @return bool
     */
    public static function processOutput($output)
    {
        if (substr($output, 0, 21) == 'Your tests timed out.') { //TODO: Multiple instances
            return self::TIMED_OUT;
        }
        $lines = explode("\n", $output);
        $useful = array_slice($lines, 2);
        foreach ($useful as $line) {
            if ($line == "\n") {
                break;
            }
            if (preg_match("/.*[EF].*/", $line)) {
                return false;
            }
        }
        return true;
    } 
    
}
