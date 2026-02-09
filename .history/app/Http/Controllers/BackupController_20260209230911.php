<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Response;
use Symfony\Component\Process\Process;
use ZipArchive;

class BackupController extends Controller
{
    /**
     * Where backups are stored.
     * We'll store our own backups here for easy listing/download:
     * storage/app/backups
     */
    private string $backupStorageDir = 'backups';

    /**
     * Which folders should be backed up + restored.
     * Adjust these to match your image folders.
     */
    private array $pathsToBackup = [
        'public/admin',         // your IMAGE_URL/admin/category + /product
        // 'storage/app/public', // add this if you store uploads there
    ];

    // ✅ GET /list
    public function list()
    {
        $dir = storage_path('app/' . $this->backupStorageDir);
        if (!File::exists($dir)) File::makeDirectory($dir, 0755, true);

        $files = collect(File::files($dir))
            ->filter(fn($f) => str_ends_with($f->getFilename(), '.zip'))
            ->map(function ($f) {
                return [
                    'filename' => $f->getFilename(),
                    'size' => round($f->getSize() / 1024) . ' KB',
                    'createdAt' => date('Y-m-d H:i', $f->getMTime()),
                ];
            })
            ->sortByDesc('createdAt')
            ->values();

        return response()->json(['data' => $files]);
    }

    // ✅ POST /create
    // Uses Spatie backup command then copies the newest zip into storage/app/backups
    public function create()
    {
        try {
            // Run Spatie backup
            // Make sure you installed:
            // composer require spatie/laravel-backup
            // and configured config/backup.php
            \Artisan::call('backup:run', ['--only-db' => false]);

            // Spatie default output is usually:
            // storage/app/Laravel/*
            // We'll detect newest .zip and copy it to our backups dir.

            $spatieDir = storage_path('app/Laravel');
            if (!File::exists($spatieDir)) {
                return response()->json(['message' => 'Backup created but Spatie folder not found'], 500);
            }

            $latestZip = collect(File::files($spatieDir))
                ->filter(fn($f) => str_ends_with($f->getFilename(), '.zip'))
                ->sortByDesc(fn($f) => $f->getMTime())
                ->first();

            if (!$latestZip) {
                return response()->json(['message' => 'Backup created but zip not found'], 500);
            }

            $targetDir = storage_path('app/' . $this->backupStorageDir);
            if (!File::exists($targetDir)) File::makeDirectory($targetDir, 0755, true);

            $newName = 'backup-' . now()->format('Ymd-His') . '.zip';
            File::copy($latestZip->getRealPath(), $targetDir . '/' . $newName);

            return response()->json([
                'message' => 'Backup created',
                'file' => $newName,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Backup failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // ✅ GET /download/{file}
    public function download(string $file)
    {
        $path = storage_path('app/' . $this->backupStorageDir . '/' . $file);

        if (!File::exists($path)) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return Response::download($path);
    }

    // ✅ POST /restore
    public function restore(Request $request)
    {
        $request->validate([
            'backup' => 'required|file|mimes:zip|max:51200', // 50MB
        ]);

        $tmpZipPath = $request->file('backup')->getRealPath();
        $extractDir = storage_path('app/' . $this->backupStorageDir . '/tmp/extract-' . time());

        File::makeDirectory($extractDir, 0755, true);

        try {
            // 1) Extract zip
            $zip = new ZipArchive();
            if ($zip->open($tmpZipPath) !== true) {
                throw new \Exception("Invalid zip file");
            }
            $zip->extractTo($extractDir);
            $zip->close();

            // 2) Find SQL file inside the extracted contents
            // Spatie usually stores DB dump inside:
            // db-dumps/mysql-*.sql OR db-dumps/mysql-*.sql.gz
            $sqlFile = $this->findSqlFile($extractDir);

            if (!$sqlFile) {
                throw new \Exception("No .sql file found in backup zip (db dump missing)");
            }

            // 3) Restore DB
            $this->restoreDatabase($sqlFile);

            // 4) Restore files/folders (overwrite)
            foreach ($this->pathsToBackup as $relPath) {
                // In zip, it might include "public/admin/..." OR "storage/app/public/..."
                $from = $extractDir . '/' . $relPath;
                $to = base_path($relPath);

                if (!File::exists($from)) {
                    // If your zip structure differs, skip silently.
                    continue;
                }

                if (File::exists($to)) File::deleteDirectory($to);
                File::makeDirectory($to, 0755, true);
                File::copyDirectory($from, $to);
            }

            return response()->json(['message' => 'Restore completed']);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Restore failed',
                'error' => $e->getMessage()
            ], 500);
        } finally {
            if (File::exists($extractDir)) File::deleteDirectory($extractDir);
        }
    }

    private function findSqlFile(string $extractDir): ?string
    {
        $all = collect(File::allFiles($extractDir));

        // Prefer plain .sql
        $sql = $all->first(fn($f) => str_ends_with($f->getFilename(), '.sql'));
        if ($sql) return $sql->getRealPath();

        // If only .gz exists, you can add gz support later
        return null;
    }

    private function restoreDatabase(string $sqlPath): void
    {
        // Option A: Use mysql CLI if available (fast)
        if ($this->commandExists('mysql')) {
            $host = config('database.connections.mysql.host');
            $port = config('database.connections.mysql.port');
            $db   = config('database.connections.mysql.database');
            $user = config('database.connections.mysql.username');
            $pass = config('database.connections.mysql.password');

            $cmd = [
                'mysql',
                "--host={$host}",
                "--port={$port}",
                "--user={$user}",
                $pass ? "-p{$pass}" : null,
                $db
            ];
            $cmd = array_values(array_filter($cmd));

            $process = new Process($cmd);
            $process->setTimeout(600);
            $process->setInput(File::get($sqlPath));
            $process->run();

            if (!$process->isSuccessful()) {
                throw new \Exception("mysql restore failed: " . $process->getErrorOutput());
            }

            return;
        }

        // Option B: Fallback to Laravel DB import (slower; may fail for huge dumps)
        $sql = File::get($sqlPath);

        // Very simple split — works for many dumps but not all edge cases.
        // If your dump is huge, tell me and I’ll provide chunked streaming importer.
        DB::beginTransaction();
        try {
            // disable FK checks to avoid errors during import order
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');

            $statements = array_filter(array_map('trim', explode(";\n", $sql)));
            foreach ($statements as $stmt) {
                if ($stmt !== '') {
                    DB::unprepared($stmt . ';');
                }
            }

            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw new \Exception("DB import (no mysql CLI) failed: " . $e->getMessage());
        }
    }

    private function commandExists(string $cmd): bool
    {
        $process = new Process(['sh', '-lc', "command -v {$cmd} >/dev/null 2>&1 && echo yes || echo no"]);
        $process->run();
        return trim($process->getOutput()) === 'yes';
    }
}
