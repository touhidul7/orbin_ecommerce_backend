<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use ZipArchive;

class BackupController extends Controller
{
    private string $backupDisk = 'local';
    private string $backupDir = 'backups'; // storage/app/backups

    // ✅ what folders to backup (edit to match your app)
    private array $pathsToBackup = [
        // Common structure from your frontend:
        // IMAGE_URL/admin/category/...
        // IMAGE_URL/admin/product/...
        'public/admin',              // includes category/product folders
        // Add more if you have:
        // 'public/uploads',
        // 'public/admin/product_gallery',
    ];

    public function list()
    {
        $dir = storage_path("app/{$this->backupDir}");
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

    public function create()
    {
        $dir = storage_path("app/{$this->backupDir}");
        if (!File::exists($dir)) File::makeDirectory($dir, 0755, true);

        $stamp = now()->format('Ymd-His');
        $sqlPath = "{$dir}/db-{$stamp}.sql";
        $zipPath = "{$dir}/backup-{$stamp}.zip";

        // DB credentials from config
        $host = config('database.connections.mysql.host');
        $port = config('database.connections.mysql.port');
        $db   = config('database.connections.mysql.database');
        $user = config('database.connections.mysql.username');
        $pass = config('database.connections.mysql.password');

        try {
            // 1) Dump database
            $dumpCmd = [
                'mysqldump',
                "--host={$host}",
                "--port={$port}",
                "--user={$user}",
                // IMPORTANT: no space after -p
                $pass ? "-p{$pass}" : null,
                $db
            ];
            $dumpCmd = array_values(array_filter($dumpCmd));

            $process = new Process($dumpCmd);
            $process->setTimeout(300);

            $process->run();

            if (!$process->isSuccessful()) {
                throw new \Exception("mysqldump failed: " . $process->getErrorOutput());
            }

            File::put($sqlPath, $process->getOutput());

            // 2) Zip db.sql + folders
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \Exception("Could not create zip file");
            }

            // add db.sql
            $zip->addFile($sqlPath, 'db.sql');

            // add folders
            foreach ($this->pathsToBackup as $relPath) {
                $absPath = base_path($relPath);
                if (!File::exists($absPath)) continue;

                $this->zipDirectory($zip, $absPath, $relPath);
            }

            $zip->close();

            // cleanup sql
            if (File::exists($sqlPath)) File::delete($sqlPath);

            return response()->json([
                'message' => 'Backup created',
                'filename' => basename($zipPath),
            ]);

        } catch (\Throwable $e) {
            if (File::exists($sqlPath)) File::delete($sqlPath);
            return response()->json([
                'message' => 'Backup failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function download(string $file)
    {
        $path = storage_path("app/{$this->backupDir}/{$file}");
        if (!File::exists($path)) return response()->json(['message' => 'Not found'], 404);

        return Response::download($path);
    }

    public function restore(Request $request)
    {
        $request->validate([
            'backup' => 'required|file|mimes:zip|max:51200', // 50MB
        ]);

        $dir = storage_path("app/{$this->backupDir}");
        if (!File::exists($dir)) File::makeDirectory($dir, 0755, true);

        $tmpZip = $request->file('backup')->storeAs("{$this->backupDir}/tmp", 'restore.zip', $this->backupDisk);
        $tmpZipPath = storage_path("app/{$tmpZip}");

        $extractDir = storage_path("app/{$this->backupDir}/tmp/extract-" . time());
        File::makeDirectory($extractDir, 0755, true);

        // DB creds
        $host = config('database.connections.mysql.host');
        $port = config('database.connections.mysql.port');
        $db   = config('database.connections.mysql.database');
        $user = config('database.connections.mysql.username');
        $pass = config('database.connections.mysql.password');

        try {
            // 1) unzip
            $zip = new ZipArchive();
            if ($zip->open($tmpZipPath) !== true) {
                throw new \Exception("Invalid zip file");
            }
            $zip->extractTo($extractDir);
            $zip->close();

            $sqlFile = "{$extractDir}/db.sql";
            if (!File::exists($sqlFile)) {
                throw new \Exception("Invalid backup: db.sql missing");
            }

            // 2) Restore DB using mysql CLI
            // We'll read sql file content and pipe to mysql
            $sqlContent = File::get($sqlFile);

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
            $process->setTimeout(300);
            $process->setInput($sqlContent);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new \Exception("mysql restore failed: " . $process->getErrorOutput());
            }

            // 3) Restore folders (overwrite)
            // Our zip stores folders like "public/admin/..."
            foreach ($this->pathsToBackup as $relPath) {
                $from = "{$extractDir}/{$relPath}";
                $to   = base_path($relPath);

                if (!File::exists($from)) continue;

                // remove existing destination then copy
                if (File::exists($to)) File::deleteDirectory($to);
                File::makeDirectory($to, 0755, true);

                File::copyDirectory($from, $to);
            }

            return response()->json(['message' => 'Restore completed']);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Restore failed',
                'error' => $e->getMessage(),
            ], 500);
        } finally {
            // cleanup tmp
            if (File::exists($tmpZipPath)) File::delete($tmpZipPath);
            if (File::exists($extractDir)) File::deleteDirectory($extractDir);
        }
    }

    private function zipDirectory(ZipArchive $zip, string $absPath, string $baseInZip): void
    {
        $files = File::allFiles($absPath);
        foreach ($files as $file) {
            $filePath = $file->getRealPath();
            $relative = $baseInZip . '/' . ltrim(str_replace($absPath, '', $filePath), DIRECTORY_SEPARATOR);
            $relative = str_replace('\\', '/', $relative);
            $zip->addFile($filePath, $relative);
        }
    }
}
