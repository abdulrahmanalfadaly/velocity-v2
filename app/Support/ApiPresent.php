<?php

namespace App\Support;

use App\Domain\Prioritization\Criteria;
use App\Domain\Prioritization\Rounding;
use App\Models\Goal;
use App\Models\Note;
use App\Models\Project;
use App\Models\Task;
use App\Services\RankedList;
use App\Services\TaskPresenter;

/** How the API shows records: camelCase keys, UTC ISO times, plain dates as YYYY-MM-DD. */
class ApiPresent
{
    private const PARTS = [Criteria::IMPORTANCE => 'importance', Criteria::URGENCY => 'urgency', Criteria::DEADLINE => 'deadline', Criteria::PRESSURE => 'workload'];

    /** A task with its place in the ranking (rank, score and the four part scores), when it has one. */
    public static function task(Task $task, ?RankedList $list = null): array
    {
        $k = $list?->rankOf($task->id);
        $s = $k ? $list->scored[$k - 1] : null;
        $parts = null;
        if ($s) {
            foreach (self::PARTS as $key => $name) {
                $parts[$name] = Rounding::halfUp($s->contributions[$key] ?? 0.0, 1);
            }
        }

        return TaskPresenter::base($task) + [
            'rank' => $k,
            'score' => $s?->displayScore(),
            'parts' => $parts,
            'url' => route('tasks.index', ['task' => $task->id]),
        ];
    }

    public static function project(Project $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'description' => $p->description,
            'dueDate' => $p->due_date?->toDateString(),
            'goal' => $p->goal ? ['id' => $p->goal->id, 'title' => $p->goal->title] : null,
            'area' => $p->area ? ['id' => $p->area->id, 'name' => $p->area->name] : null,
            'openTasks' => $p->open_tasks_count ?? null,
            'archived' => $p->archived_at !== null,
            'url' => route('projects.show', $p),
        ];
    }

    public static function goal(Goal $g): array
    {
        return [
            'id' => $g->id,
            'title' => $g->title,
            'why' => $g->why,
            'target' => $g->target,
            'color' => $g->color,
            'archived' => $g->archived_at !== null,
        ];
    }

    /** A note in a list; $full adds its words and links. */
    public static function note(Note $n, bool $full = false): array
    {
        $out = [
            'id' => $n->id,
            'title' => $n->displayTitle(),
            'preview' => $n->preview(),
            'pinned' => (bool) $n->pinned,
            'project' => $n->project ? ['id' => $n->project->id, 'name' => $n->project->name] : null,
            'journalDate' => $n->journal_date?->toDateString(),
            'updatedAt' => $n->updated_at?->toIso8601ZuluString(),
            'url' => route('notes.index', ['note' => $n->id]),
        ];
        if ($full) {
            $out += [
                'text' => (string) $n->body_text,
                'doc' => $n->doc(),
                'tasks' => $n->tasks->map(fn ($t) => ['id' => $t->id, 'title' => $t->title, 'status' => $t->status])->values(),
                'people' => $n->people->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values(),
                'createdAt' => $n->created_at?->toIso8601ZuluString(),
            ];
        }

        return $out;
    }
}
