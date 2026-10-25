<?php

namespace App\Http\Controllers;

use App\Enums\TaskStatus;
use App\Models\Database;
use App\Models\Server;
use App\Ssh\RemoteShell;
use App\Ssh\Script;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DatabaseExportController extends Controller
{
    /**
     * Download a gzipped dump of the database, streamed from the server as it is made, and note the export in the
     * server's activity log.
     */
    public function __invoke(Server $server, Database $database, RemoteShell $shell): StreamedResponse
    {
        abort_unless($database->isTransferable(), 404);

        $filename = $database->name.'-'.now()->format('Y-m-d-His').'.sql.gz';

        return response()->streamDownload(function () use ($server, $database, $shell): void {
            set_time_limit(0);

            $task = $server->tasks()->create(['name' => "Exporting database {$database->name}", 'status' => TaskStatus::Running]);
            $bytes = 0;

            $result = $shell->stream($server, Script::render('databases.export', ['database' => $database]), function (string $chunk) use (&$bytes): void {
                $bytes += strlen($chunk);

                echo $chunk;
                flush();
            }, timeout: 3600);

            $task->update([
                'status' => $result->successful() ? TaskStatus::Finished : TaskStatus::Failed,
                'exit_code' => $result->exitCode,
                'output' => trim($server->redactSecrets($result->output)."\n\n".($result->successful()
                    ? 'Downloaded '.number_format($bytes).' bytes, gzipped.'
                    : 'The export failed, so the downloaded file is incomplete.')),
            ]);
        }, $filename, [
            'Content-Type' => 'application/gzip',
            // nginx would otherwise hold the download back until the whole dump had been made.
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
