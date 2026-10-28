<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddAttachment;
use App\Mcp\Tools\AddComment;
use App\Mcp\Tools\CreateTask;
use App\Mcp\Tools\GetTask;
use App\Mcp\Tools\ListProjects;
use App\Mcp\Tools\ListTasks;
use App\Mcp\Tools\UpdateTask;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Sprint')]
#[Version('1.0.0')]
#[Instructions('Sprint is a task manager with projects, tasks, subtasks, tags and statuses. You act as the person who owns the access token and can only see and change what that person may. Start with list-projects to learn project ids, statuses, tags, members and custom fields. Use list-tasks to find tasks, get-task for details, create-task and update-task to write, add-comment to discuss and add-attachment to attach files: up to 1 MB it takes the file as Base64 in the call, which works from any client; for larger files (up to 20 MB) it returns a one-time upload URL, valid for 10 minutes and secret, that needs a client with a shell (HTTP PUT of the raw file, e.g. curl -T). Files cannot be downloaded through this server. Names of statuses, tags, custom fields and options are matched case-insensitively; people are identified by e-mail address. Deleting is not possible through this server.')]
class SprintServer extends Server
{
    protected array $tools = [
        ListProjects::class,
        ListTasks::class,
        GetTask::class,
        CreateTask::class,
        UpdateTask::class,
        AddComment::class,
        AddAttachment::class,
    ];
}
