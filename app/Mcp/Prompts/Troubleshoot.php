<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

class Troubleshoot extends Prompt
{
    protected string $description = 'Work out what went wrong with a server or site, and how to fix it in Swage.';

    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument('problem', 'What is wrong, such as "the last deploy of api.example.com failed".', required: true),
        ];
    }

    public function handle(Request $request): Response
    {
        return Response::text(<<<PROMPT
            Help me with this problem on my Swage panel: {$request->get('problem')}

            1. Find the server or site involved with list-servers or list-sites.
            2. Look at what Swage recorded: get-server, get-server-metrics, get-site, the failing deployment's output with get-deployment, or the failed task's output with get-task.
            3. Search the Swage documentation with search-docs and read the relevant page with read-doc.
            4. Explain the cause, quoting the lines of output that show it, then give the steps to fix it, naming the pages and buttons in Swage or the commands to run.

            You can only read. Never claim to have changed anything; say what I should do.
            PROMPT);
    }
}
