<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class DeployCommand extends Command
{
    protected $signature = 'deploy
        {--all : Redeploy all tracked files regardless of state}';

    protected $description = 'Deploy changed files to bigcats production server';

    private const REMOTE_HOST = 'bigcats';
    private const REMOTE_PATH = '~/api';
    private const STATE_FILE = '.deploy-state';

    private const EXCLUDED_PREFIXES = [
        'storage/',
        'public/storage/',
        '.omc/',
        '.claude/',
        '.idea/',
        '.git/',
        'vendor/',
    ];

    private const EXCLUDED_EXACT = [
        '.env',
        '.gitignore',
        '.deploy-state',
    ];

    private const RISKY_PATTERNS = [
        'composer.json',
        'composer.lock',
        'database/migrations/',
    ];

    private const SSH_OPTIONS = '-o ControlMaster=auto -o ControlPath=/tmp/deploy-%C -o ControlPersist=60';

    public function handle(): int
    {
        $lastSha = $this->readDeployState();

        if ($lastSha === null && !$this->option('all')) {
            $this->info('No deploy state found. Deploying all tracked files...');
        }

        $files = $this->getChangedFiles($lastSha);
        if ($files === null) {
            return 1;
        }

        $files = $this->filterFiles($files);

        if (empty($files)) {
            $this->info('Nothing to deploy.');
            return 0;
        }

        $riskyFiles = $this->findRiskyFiles($files);

        $this->info('Deploying ' . count($files) . ' files to ' . self::REMOTE_HOST . '...');
        $this->line('');

        $failed = $this->deployFiles($files);

        if (!empty($failed)) {
            $this->line('');
            $this->error(count($failed) . ' files failed to deploy:');
            foreach ($failed as $file) {
                $this->error("  $file");
            }
            $this->warn('Deploy state NOT updated. Run deploy again to retry.');
            return 1;
        }

        $this->line('');
        $this->runPostDeployActions($files);
        if (!empty($riskyFiles)) {
            $this->printRiskyFileCommands($riskyFiles, 'REMINDER — manual steps required:');
        }
        $this->updateDeployState(count($files));
        $this->info('Successfully deployed ' . count($files) . ' files to ' . self::REMOTE_HOST . '.');

        return 0;
    }

    private function readDeployState(): ?string
    {
        $path = base_path(self::STATE_FILE);

        if (!file_exists($path)) {
            return null;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (empty($lines)) {
            return null;
        }

        $sha = trim($lines[0]);

        $output = [];
        exec('git cat-file -t ' . escapeshellarg($sha) . ' 2>&1', $output, $exitCode);
        if ($exitCode !== 0) {
            $this->warn("Invalid SHA in .deploy-state: $sha");
            $this->warn('Use --all to redeploy everything.');
            return null;
        }

        return $sha;
    }

    private function getChangedFiles(?string $lastSha): ?array
    {
        if ($this->option('all') || $lastSha === null) {
            $output = [];
            exec('git ls-files', $output, $exitCode);
            if ($exitCode !== 0) {
                $this->error('Failed to list tracked files.');
                return null;
            }
            return array_values(array_filter($output));
        }

        $committed = [];
        exec('git diff --name-only ' . escapeshellarg($lastSha) . '..HEAD 2>&1', $committed, $exitCode);
        if ($exitCode !== 0) {
            $this->error('Failed to diff against last deploy SHA: ' . $lastSha);
            $this->error('The SHA may no longer exist (rebase/force-push). Use --all to redeploy everything.');
            return null;
        }

        $staged = [];
        exec('git diff --name-only --cached', $staged);

        $unstaged = [];
        exec('git diff --name-only', $unstaged);

        return array_values(array_unique(array_filter(array_merge($committed, $staged, $unstaged))));
    }

    private function filterFiles(array $files): array
    {
        $deleted = [];
        $filtered = [];

        foreach ($files as $file) {
            $file = trim($file);
            if ($file === '') {
                continue;
            }

            if (in_array($file, self::EXCLUDED_EXACT, true)) {
                continue;
            }

            $excluded = false;
            foreach (self::EXCLUDED_PREFIXES as $prefix) {
                if (str_starts_with($file, $prefix)) {
                    $excluded = true;
                    break;
                }
            }
            if ($excluded) {
                continue;
            }

            if (!file_exists(base_path($file))) {
                $deleted[] = $file;
                continue;
            }

            $filtered[] = $file;
        }

        if (!empty($deleted)) {
            $this->warn(count($deleted) . ' files deleted locally but may still exist on ' . self::REMOTE_HOST . ':');
            foreach ($deleted as $file) {
                $this->warn("  $file");
                $this->line('    ssh ' . self::REMOTE_HOST . ' "rm ' . self::REMOTE_PATH . '/' . $file . '"');
            }
            $this->line('');
        }

        return $filtered;
    }

    private function findRiskyFiles(array $files): array
    {
        $risky = [];
        foreach ($files as $file) {
            foreach (self::RISKY_PATTERNS as $pattern) {
                if ($file === $pattern || str_starts_with($file, $pattern)) {
                    $risky[] = $file;
                    break;
                }
            }
        }
        return $risky;
    }

    private function isPathSafe(string $path, bool $allowTilde = false): bool
    {
        $pattern = $allowTilde ? '/[^a-zA-Z0-9_\/\.\-~]/' : '/[^a-zA-Z0-9_\/\.\-]/';
        return !preg_match($pattern, $path);
    }

    private function deployFiles(array $files): array
    {
        $dirs = [];
        foreach ($files as $file) {
            $dir = dirname($file);
            if ($dir !== '.') {
                $dirs[self::REMOTE_PATH . '/' . $dir] = true;
            }
        }

        if (!empty($dirs)) {
            foreach (array_keys($dirs) as $dir) {
                if (!$this->isPathSafe($dir, allowTilde: true)) {
                    $this->error("Unsafe directory path, aborting: $dir");
                    return $files;
                }
            }
            $dirList = implode(' ', array_keys($dirs));
            $output = [];
            exec('ssh ' . self::SSH_OPTIONS . ' ' . self::REMOTE_HOST . ' "mkdir -p ' . $dirList . '" 2>&1', $output, $exitCode);
            if ($exitCode !== 0) {
                $this->error('Failed to create remote directories: ' . implode(' ', $output));
                return $files;
            }
        }

        $failed = [];
        foreach ($files as $file) {
            if (!$this->isPathSafe($file)) {
                $this->error("  SKIP  $file (unsafe characters in path)");
                $failed[] = $file;
                continue;
            }
            $localPath = base_path($file);
            $remoteDest = self::REMOTE_HOST . ':' . self::REMOTE_PATH . '/' . $file;

            $output = [];
            exec('scp ' . self::SSH_OPTIONS . ' ' . escapeshellarg($localPath) . ' ' . $remoteDest . ' 2>&1', $output, $exitCode);

            if ($exitCode !== 0) {
                $failed[] = $file;
                $this->error("  FAIL  $file");
            } else {
                $this->line("  OK    $file");
            }
        }

        return $failed;
    }

    private function runRemote(string $command): array
    {
        $output = [];
        exec('ssh ' . self::SSH_OPTIONS . ' ' . self::REMOTE_HOST . ' "cd ' . self::REMOTE_PATH . ' && ' . $command . '" 2>&1', $output, $exitCode);
        return [$exitCode, $output];
    }

    private function runPostDeployActions(array $files): void
    {
        $configChanged = false;
        $codeChanged = false;

        foreach ($files as $file) {
            if (str_starts_with($file, 'config/')) {
                $configChanged = true;
            }
            if (str_starts_with($file, 'app/') || str_starts_with($file, 'routes/') || str_starts_with($file, 'resources/')) {
                $codeChanged = true;
            }
        }

        if ($configChanged) {
            $this->info('Config files changed, running config:cache...');
            [$exitCode, $output] = $this->runRemote('php artisan config:cache');
            $this->info($exitCode === 0 ? 'Config cache refreshed.' : 'config:cache failed: ' . implode(' ', $output));
        }

        if ($codeChanged) {
            $this->info('Code files changed, running queue:restart...');
            [$exitCode, $output] = $this->runRemote('php artisan queue:restart');
            if ($exitCode === 0) {
                $this->info('Queue restart issued.');
                $this->warn('Warning: long-running jobs (AnalyzeNewsJob) will be terminated.');
            } else {
                $this->warn('queue:restart failed: ' . implode(' ', $output));
            }
        }
    }

    private function printRiskyFileCommands(array $riskyFiles, string $header): void
    {
        $hasComposer = false;
        $hasMigrations = false;

        foreach ($riskyFiles as $file) {
            if ($file === 'composer.json' || $file === 'composer.lock') {
                $hasComposer = true;
            }
            if (str_starts_with($file, 'database/migrations/')) {
                $hasMigrations = true;
            }
        }

        $this->warn($header);

        $sshPrefix = 'ssh ' . self::REMOTE_HOST . ' "cd ' . self::REMOTE_PATH . ' && ';

        if ($hasComposer) {
            $this->warn('  Composer files changed. Run:');
            $this->line('    ' . $sshPrefix . 'php /opt/cpanel/ea-wappspector/composer.phar install --no-dev"');
            $this->line('    ' . $sshPrefix . 'php artisan config:cache"');
        }

        if ($hasMigrations) {
            $this->warn('  Migration files changed. Run:');
            $this->line('    ' . $sshPrefix . 'php artisan migrate --force"');
        }

        $this->line('');
    }

    private function updateDeployState(int $fileCount): void
    {
        $output = [];
        exec('git rev-parse HEAD 2>&1', $output, $exitCode);
        if ($exitCode !== 0 || empty($output)) {
            $this->error('Failed to determine current git SHA. Deploy state not updated.');
            return;
        }
        $sha = trim($output[0]);
        $timestamp = date('c');

        file_put_contents(base_path(self::STATE_FILE), implode("\n", [
            $sha,
            $timestamp,
            "$fileCount files deployed",
        ]) . "\n");
    }
}
