<?php

namespace App\Http\Controllers;

use App\Models\Database;
use App\Models\Server;
use App\Servers\DatabaseRestoreUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DatabaseRestoreUploadController extends Controller
{
    /**
     * Start uploading a dump to restore the database from.
     */
    public function store(Request $request, Server $server, Database $database): JsonResponse
    {
        abort_unless($database->isTransferable(), 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/\.(sql|gz)$/iD'],
            'size' => ['required', 'integer', 'min:1', 'max:'.DatabaseRestoreUpload::MAX_BYTES],
        ], [
            'name.regex' => __('Choose a .sql or .sql.gz file.'),
            'size.max' => __('The file is larger than 1 GB.'),
        ]);

        $upload = DatabaseRestoreUpload::start($request->user(), $database, $validated['name'], $validated['size']);

        return response()->json(['id' => $upload->id, 'chunkSize' => DatabaseRestoreUpload::CHUNK_BYTES]);
    }

    /**
     * Add a chunk of the dump, sent as the raw request body with the offset it starts at. A chunk that does not start
     * where the file has arrived up to is refused with that position, so the browser can carry on from there.
     */
    public function update(Request $request, Server $server, Database $database, string $upload): JsonResponse
    {
        $upload = DatabaseRestoreUpload::find($upload, $request->user(), $database) ?? abort(404);

        $offset = filter_var($request->query('offset'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $length = (int) $request->header('Content-Length');

        abort_if($offset === false || $length < 1, 400);
        abort_if($length > DatabaseRestoreUpload::CHUNK_BYTES || $offset + $length > $upload->size(), 413);

        if (! $upload->append($offset, $request->getContent(asResource: true), $length)) {
            return response()->json(['received' => $upload->received()], 409);
        }

        return response()->json(['received' => $upload->received()]);
    }
}
