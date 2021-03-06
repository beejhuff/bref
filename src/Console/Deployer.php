<?php
declare(strict_types=1);

namespace Bref\Console;

use Joli\JoliNotif\Notification;
use Joli\JoliNotif\NotifierFactory;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

class Deployer
{
    /** @var Filesystem */
    private $fs;

    public function __construct()
    {
        $this->fs = new Filesystem;
    }

    /**
     * Invoke the function and return the output.
     */
    public function invoke(SymfonyStyle $io, string $function, ?string $data, bool $raw) : string
    {
        $progress = $this->createProgressBar($io, 7);

        $this->generateArchive($io, $progress);

        $progress->setMessage('Invoking the lambda');
        $progress->display();
        $progress->finish();

        $parameters = array_filter([
            '-f' => $function,
            '-d' => $data,
            '-raw' => $raw,
        ]);

        $p = join(' ', array_map(
            function ($value, $key) {
                if ($value === true) {
                    // Support for "flag" arguments
                    return $key;
                }
                return $key . ' ' . escapeshellarg($value);
            },
            $parameters,
            array_keys($parameters)
        ));

        $process = new Process('serverless invoke local ' . $p, '.bref/output');
        $process->setTimeout(null);
        $process->mustRun();
        return $process->getOutput();
    }

    public function deploy(SymfonyStyle $io) : void
    {
        $progress = $this->createProgressBar($io, 8);

        $this->generateArchive($io, $progress);

        $progress->setMessage('Uploading the lambda');
        $progress->display();
        $this->runLocally('serverless deploy');

        $progress->setMessage('Deployment success');
        $progress->finish();

        // Trigger a desktop notification
        $notifier = NotifierFactory::create();
        $notification = (new Notification)
            ->setTitle('Deployment success')
            ->setBody('Bref has deployed your application');
        $notifier->send($notification);
    }

    /**
     * @param ProgressBar $progress The progress bar will advance of 7 steps.
     * @throws \Exception
     */
    private function generateArchive(SymfonyStyle $io, ProgressBar $progress) : void
    {
        if (!$this->fs->exists('serverless.yml') || !$this->fs->exists('bref.php')) {
            throw new \Exception('The files `bref.php` and `serverless.yml` are required to deploy, run `bref init` to create them');
        }

        // Parse .bref.yml
        $projectConfig = [];
        if ($this->fs->exists('.bref.yml')) {
            $progress->setMessage('Reading `.bref.yml`');
            $progress->display();
            /*
             * TODO validate the content of the config, for example we should
             * error if there are unknown keys. Using the Symfony Config component
             * for that could make sense.
             */
            $projectConfig = Yaml::parse(file_get_contents('.bref.yml'));
        }
        $progress->advance();

        $progress->setMessage('Building the project in the `.bref/output` directory');
        $progress->display();
        /*
         * TODO Mirror the directory instead of recreating it from scratch every time
         * Blocked by https://github.com/symfony/symfony/pull/26399
         * In the meantime we destroy `.bref/output` completely every time which
         * is not efficient.
         */
        $this->fs->remove('.bref/output');
        $this->fs->mkdir('.bref/output');
        $filesToCopy = new Finder;
        $filesToCopy->in('.')
            ->depth(0)
            ->exclude('.bref')// avoid a recursive copy
            ->ignoreDotFiles(false);
        foreach ($filesToCopy as $fileToCopy) {
            if (is_file($fileToCopy->getPathname())) {
                $this->fs->copy($fileToCopy->getPathname(), '.bref/output/' . $fileToCopy->getFilename());
            } else {
                $this->fs->mirror($fileToCopy->getPathname(), '.bref/output/' . $fileToCopy->getFilename(), null, [
                    'copy_on_windows' => true, // Force to copy symlink content
                ]);
            }
        }
        $progress->advance();

        // Cache PHP's binary in `.bref/bin/php` to avoid downloading it
        // on every deploy.
        /*
         * TODO Allow choosing a PHP version instead of using directly the
         * constant `PHP_TARGET_VERSION`. That could be done using the `.bref.yml`
         * config file: there could be an option in that config, for example:
         * php:
         *     version: 7.2.2
         */
        $progress->setMessage('Downloading PHP in the `.bref/bin/` directory');
        $progress->display();
        if (!$this->fs->exists('.bref/bin/php/php-' . PHP_TARGET_VERSION . '.tar.gz')) {
            $this->fs->mkdir('.bref/bin/php');
            $defaultUrl = 'https://s3.amazonaws.com/bref-php/bin/php-' . PHP_TARGET_VERSION . '.tar.gz';
            /*
             * TODO This option allows to customize the PHP binary used. It should be documented
             * and probably moved to a dedicated option like:
             * php:
             *     url: 'https://s3.amazonaws.com/...'
             */
            $url = $projectConfig['php'] ?? $defaultUrl;
            (new Process("curl -sSL $url -o .bref/bin/php/php-" . PHP_TARGET_VERSION . ".tar.gz"))
                ->setTimeout(null)
                ->mustRun();
        }
        $progress->advance();

        $progress->setMessage('Installing the PHP binary');
        $progress->display();
        $this->fs->mkdir('.bref/output/.bref/bin');
        (new Process('tar -xzf .bref/bin/php/php-' . PHP_TARGET_VERSION . '.tar.gz -C .bref/output/.bref/bin'))
            ->mustRun();
        // Set correct permissions on the file
        $this->fs->chmod('.bref/output/.bref/bin', 0755);
        $progress->advance();

        $progress->setMessage('Installing `handler.js`');
        $progress->display();
        $this->fs->copy(__DIR__ . '/../../template/handler.js', '.bref/output/handler.js');
        $progress->advance();

        $progress->setMessage('Installing composer dependencies');
        $progress->display();
        $this->runLocally('composer install --no-dev --classmap-authoritative --no-scripts');
        $progress->advance();

        /*
         * TODO Edit the `serverless.yml` copy (in `.bref/output` to deploy these files:
         * - bref.php
         * - handler.js
         * - .bref/**
         */

        // Run build hooks defined in .bref.yml
        $progress->setMessage('Running build hooks');
        $progress->display();
        $buildHooks = $projectConfig['hooks']['build'] ?? [];
        foreach ($buildHooks as $buildHook) {
            $progress->setMessage('Running build hook: ' . $buildHook);
            $progress->display();
            $this->runLocally($buildHook);
        }
        $progress->advance();
    }

    private function runLocally(string $command) : void
    {
        $process = new Process($command, '.bref/output');
        $process->setTimeout(null);
        $process->mustRun();
    }

    /**
     * @param SymfonyStyle $io
     * @return ProgressBar
     */
    private function createProgressBar(SymfonyStyle $io, int $max) : ProgressBar
    {
        ProgressBar::setFormatDefinition('bref', "<comment>%message%</comment>\n %current%/%max% [%bar%] %elapsed:6s%\n");

        $progressBar = $io->createProgressBar($max);
        $progressBar->setFormat('bref');
        $progressBar->setBarCharacter('░');
        $progressBar->setEmptyBarCharacter(' ');

        $progressBar->start();

        return $progressBar;
    }
}
