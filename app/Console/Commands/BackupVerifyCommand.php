<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class BackupVerifyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:verify 
                            {timestamp? : Specific backup timestamp to verify (optional)}
                            {--all : Verify all backups}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify backup integrity by checking checksums and file validity';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $backupDir = base_path('backups');
        $metadataDir = $backupDir.'/metadata';

        if (! is_dir($metadataDir)) {
            $this->error('Backup metadata directory not found: '.$metadataDir);

            return Command::FAILURE;
        }

        if ($this->option('all')) {
            return $this->verifyAll($metadataDir);
        }

        $timestamp = $this->argument('timestamp');

        if (! $timestamp) {
            // Find latest backup
            $files = glob($metadataDir.'/satflux.io_backup_*.json');
            if (empty($files)) {
                $this->error('No backups found.');

                return Command::FAILURE;
            }

            // Sort by modification time, newest first
            usort($files, function ($a, $b) {
                return filemtime($b) - filemtime($a);
            });

            $latestFile = $files[0];
            $timestamp = basename($latestFile, '.json');
            $this->info('No timestamp specified, verifying latest backup: '.$timestamp);
        }

        $metadataFile = $metadataDir.'/'.$timestamp.'.json';

        if (! file_exists($metadataFile)) {
            $this->error('Backup metadata not found: '.$metadataFile);

            return Command::FAILURE;
        }

        return $this->verifyBackup($metadataFile) ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Verify all backups.
     */
    protected function verifyAll(string $metadataDir): int
    {
        $files = glob($metadataDir.'/satflux.io_backup_*.json');

        if (empty($files)) {
            $this->error('No backups found.');

            return Command::FAILURE;
        }

        $this->info('Verifying '.count($files).' backups...');
        $this->newLine();

        $verified = 0;
        $failed = 0;

        foreach ($files as $file) {
            $timestamp = basename($file, '.json');
            $this->line('Verifying: '.$timestamp);

            if ($this->verifyBackup($file)) {
                $verified++;
                $this->info('  ✓ Verified');
            } else {
                $failed++;
                $this->error('  ✗ Failed');
            }
            $this->newLine();
        }

        $this->info("Verified: {$verified}, Failed: {$failed}");

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Verify a single backup.
     */
    protected function verifyBackup(string $metadataFile): bool
    {
        try {
            $metadata = json_decode(file_get_contents($metadataFile), true);

            if (! $metadata) {
                $this->error('  Invalid metadata file');

                return false;
            }

            $backupDir = base_path('backups');
            $allValid = true;

            // Verify database backup
            if (isset($metadata['database']['file'])) {
                $dbFile = $backupDir.'/database/'.$metadata['database']['file'];
                if (! $this->verifyFile($dbFile, $metadata['database']['checksum'] ?? null)) {
                    $this->error('  Database backup: FAILED');
                    $allValid = false;
                } else {
                    $this->line('  Database backup: OK');
                }
            }

            // Verify files backup
            if (isset($metadata['files']['file'])) {
                $filesFile = $backupDir.'/files/'.$metadata['files']['file'];
                if (! $this->verifyFile($filesFile, $metadata['files']['checksum'] ?? null)) {
                    $this->error('  Files backup: FAILED');
                    $allValid = false;
                } else {
                    $this->line('  Files backup: OK');
                }
            }

            // Verify Redis backup
            if (isset($metadata['redis']['file'])) {
                $redisFile = $backupDir.'/redis/'.$metadata['redis']['file'];
                if (! $this->verifyFile($redisFile, $metadata['redis']['checksum'] ?? null)) {
                    $this->error('  Redis backup: FAILED');
                    $allValid = false;
                } else {
                    $this->line('  Redis backup: OK');
                }
            }

            return $allValid;
        } catch (\Exception $e) {
            $this->error('  Error verifying backup: '.$e->getMessage());
            Log::error('Backup verification error', [
                'file' => $metadataFile,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Verify a single file.
     */
    protected function verifyFile(string $file, ?string $expectedChecksum): bool
    {
        if (! file_exists($file)) {
            $this->error('    File not found: '.basename($file));

            return false;
        }

        if (! is_readable($file)) {
            $this->error('    File not readable: '.basename($file));

            return false;
        }

        // Check if file is empty
        if (filesize($file) === 0) {
            $this->error('    File is empty: '.basename($file));

            return false;
        }

        // Verify gzip integrity if it's a compressed file
        if (str_ends_with($file, '.gz')) {
            $process = new Process(['gzip', '-t', $file]);
            $process->run();
            if (! $process->isSuccessful()) {
                $this->error('    File is corrupted (gzip): '.basename($file));

                return false;
            }
        }

        // Verify checksum if provided
        if ($expectedChecksum && $expectedChecksum !== 'unknown') {
            $actualChecksum = $this->calculateChecksum($file);
            if ($actualChecksum && $actualChecksum !== $expectedChecksum) {
                $this->error('    Checksum mismatch: '.basename($file));
                $this->line('      Expected: '.substr($expectedChecksum, 0, 16).'...');
                $this->line('      Actual:   '.substr($actualChecksum, 0, 16).'...');

                return false;
            }
        }

        return true;
    }

    /**
     * Calculate SHA256 checksum of a file.
     */
    protected function calculateChecksum(string $file): ?string
    {
        if (function_exists('hash_file')) {
            return hash_file('sha256', $file);
        }

        // Fallback to command line
        $process = new Process(['which', 'sha256sum']);
        $process->run();
        if ($process->isSuccessful()) {
            $process = new Process(['sha256sum', $file]);
            $process->run();
            if ($process->isSuccessful()) {
                return trim(explode(' ', $process->getOutput())[0]);
            }
        }

        $process = new Process(['which', 'shasum']);
        $process->run();
        if ($process->isSuccessful()) {
            $process = new Process(['shasum', '-a', '256', $file]);
            $process->run();
            if ($process->isSuccessful()) {
                return trim(explode(' ', $process->getOutput())[0]);
            }
        }

        return null;
    }
}
