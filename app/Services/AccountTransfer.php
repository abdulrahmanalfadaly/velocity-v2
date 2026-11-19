<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * Everything in an account as one .zip (data.json and the attached files), and back
 * again into an empty account, for example after moving Velocity to another server.
 * Every row gets a new id on the way in; links between rows, and pictures and links
 * inside notes, are pointed at the new ones. API keys and the GitHub token stay behind.
 */
class AccountTransfer
{
    public const FORMAT = 'velocity-export';

    public const VERSION = 1;

    /** The account's own settings that travel along (not its name, email or password). */
    private const USER_SETTINGS = ['timezone', 'planner_hours', 'planner_settings', 'auto_archive_days', 'focus_settings', 'wallpaper'];

    /** Limits on what an upload may unpack to. */
    private const MAX_ENTRIES = 20000;

    private const MAX_BYTES = 2 * 1024 * 1024 * 1024;

    /**
     * Every table, parents before children. "own": rows with the account's user_id, or
     * [parent table, column] for rows that hang off a parent. "pivot": links between two
     * tables. "refs": columns pointing at other rows. "later": columns pointing at rows
     * of the same table (set once all of them are in). "file": the folder its files go in.
     */
    private const TABLES = [
        'areas' => ['own' => 'user'],
        'goals' => ['own' => 'user'],
        'projects' => ['own' => 'user', 'refs' => ['area_id' => 'areas', 'goal_id' => 'goals']],
        'milestones' => ['own' => ['projects', 'project_id']],
        'goal_project' => ['pivot' => ['goal_id' => 'goals', 'project_id' => 'projects']],
        'tasks' => ['own' => 'user', 'refs' => ['project_id' => 'projects', 'goal_id' => 'goals'], 'later' => ['parent_id', 'repeated_from_id']],
        'task_steps' => ['own' => ['tasks', 'task_id']],
        'task_dependencies' => ['pivot' => ['task_id' => 'tasks', 'depends_on_id' => 'tasks']],
        'task_logs' => ['own' => 'user', 'refs' => ['task_id' => 'tasks']],
        'task_files' => ['own' => 'user', 'refs' => ['task_id' => 'tasks', 'log_id' => 'task_logs'], 'file' => 'task-files'],
        'task_reminders' => ['own' => 'user', 'refs' => ['task_id' => 'tasks']],
        'task_events' => ['own' => 'user', 'refs' => ['task_id' => 'tasks']],
        'task_templates' => ['own' => 'user', 'refs' => ['project_id' => 'projects']],
        'github_links' => ['own' => 'user', 'refs' => ['task_id' => 'tasks']],
        'project_files' => ['own' => 'user', 'refs' => ['project_id' => 'projects'], 'file' => 'project-files'],
        'events' => ['own' => 'user'],
        'notes' => ['own' => 'user', 'refs' => ['project_id' => 'projects', 'area_id' => 'areas', 'event_id' => 'events']],
        'note_files' => ['own' => 'user', 'refs' => ['note_id' => 'notes'], 'file' => 'note-files'],
        'note_project' => ['pivot' => ['note_id' => 'notes', 'project_id' => 'projects']],
        'note_task' => ['pivot' => ['note_id' => 'notes', 'task_id' => 'tasks']],
        'habits' => ['own' => 'user', 'refs' => ['project_id' => 'projects', 'area_id' => 'areas']],
        'habit_entries' => ['own' => ['habits', 'habit_id']],
        'habit_pauses' => ['own' => ['habits', 'habit_id']],
        'goal_habit' => ['pivot' => ['goal_id' => 'goals', 'habit_id' => 'habits']],
        'routines' => ['own' => 'user'],
        'habit_routine' => ['pivot' => ['routine_id' => 'routines', 'habit_id' => 'habits']],
        'note_links' => ['own' => ['notes', 'note_id'], 'poly' => ['linkable_type', 'linkable_id', ['note' => 'notes', 'goal' => 'goals', 'habit' => 'habits']]],
        'people' => ['own' => 'user'],
        'person_contacts' => ['own' => ['people', 'person_id']],
        'person_dates' => ['own' => ['people', 'person_id']],
        'person_memories' => ['own' => ['people', 'person_id']],
        'catch_ups' => ['own' => ['people', 'person_id']],
        'person_groups' => ['own' => 'user'],
        'person_group_members' => ['pivot' => ['person_group_id' => 'person_groups', 'person_id' => 'people']],
        'person_note' => ['pivot' => ['person_id' => 'people', 'note_id' => 'notes']],
        'person_project' => ['pivot' => ['person_id' => 'people', 'project_id' => 'projects']],
        'person_task' => ['pivot' => ['person_id' => 'people', 'task_id' => 'tasks']],
        'planner_blocks' => ['own' => 'user', 'refs' => ['task_id' => 'tasks', 'project_id' => 'projects']],
        'planner_moves' => ['own' => 'user', 'refs' => ['planner_block_id' => 'planner_blocks']],
        'focus_sessions' => ['own' => 'user', 'refs' => ['task_id' => 'tasks', 'project_id' => 'projects', 'goal_id' => 'goals', 'habit_id' => 'habits', 'note_id' => 'notes']],
        'weekly_reviews' => ['own' => 'user'],
        'weight_profiles' => ['own' => 'user'],
    ];

    /** An account with any of these (not deleted) already has things in it. */
    private const CONTENT = ['areas', 'goals', 'projects', 'tasks', 'events', 'notes', 'habits', 'routines', 'people', 'person_groups', 'planner_blocks', 'focus_sessions', 'task_templates'];

    /** @var array<string, array<string, bool>> column => nullable, per table */
    private array $columns = [];

    /** Nothing in it yet: the only kind of account an import goes into. */
    public function isEmpty(User $user): bool
    {
        foreach (self::CONTENT as $table) {
            $q = DB::table($table)->where('user_id', $user->id);
            if (isset($this->columnsOf($table)['deleted_at'])) {
                $q->whereNull('deleted_at');
            }
            if ($q->exists()) {
                return false;
            }
        }

        return true;
    }

    /** Writes the account to a .zip and returns where it is (a temporary file). */
    public function export(User $user): string
    {
        $path = tempnam(sys_get_temp_dir(), 'velocity-export-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $disk = Storage::disk('local');
        $ids = [];
        $tables = [];
        foreach (self::TABLES as $table => $spec) {
            $q = DB::table($table);
            if (($spec['own'] ?? null) === 'user') {
                $q->where('user_id', $user->id);
            } elseif (isset($spec['own'])) {
                [$parent, $column] = $spec['own'];
                $q->whereIn($column, $ids[$parent] ?? []);
            } else {
                foreach ($spec['pivot'] as $column => $ref) {
                    $q->whereIn($column, $ids[$ref] ?? []);
                }
            }
            $rows = $q->get()->map(fn ($r) => (array) $r)->all();
            if (isset($this->columnsOf($table)['id'])) {
                $ids[$table] = array_column($rows, 'id');
                usort($rows, fn ($a, $b) => $a['id'] <=> $b['id']);
            }
            foreach ($rows as &$row) {
                unset($row['user_id']);
                if (isset($spec['file'])) {
                    $entry = null;
                    if ($row['path'] && $disk->exists($row['path'])) {
                        $entry = "files/{$table}/{$row['id']}".self::extension($row['path']);
                        $zip->addFile($disk->path($row['path']), $entry);
                    }
                    $row['path'] = $entry;
                }
            }
            unset($row);
            $tables[$table] = $rows;
        }
        $settings = collect($user->getAttributes())->only(self::USER_SETTINGS)->all();
        if ($user->wallpaper_path && $disk->exists($user->wallpaper_path)) {
            $settings['wallpaper_file'] = 'files/wallpaper'.self::extension($user->wallpaper_path);
            $zip->addFile($disk->path($user->wallpaper_path), $settings['wallpaper_file']);
        }
        $zip->addFromString('data.json', json_encode([
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exportedAt' => now()->toIso8601ZuluString(),
            'user' => $settings,
            'tables' => $tables,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $zip->close();

        return $path;
    }

    /**
     * Brings a .zip made by export() into an empty account. All or nothing: on any
     * problem nothing is kept. Returns how many rows came in, by table.
     *
     * @return array<string, int>
     */
    public function import(User $user, string $zipPath): array
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw self::bad('That isn’t a Velocity export (.zip).');
        }
        try {
            $data = $this->read($zip);
            if (! $this->isEmpty($user)) {
                throw self::bad('This account already has things in it. Import only goes into a new, empty account.');
            }
            $written = [];
            try {
                return DB::transaction(function () use ($user, $zip, $data, &$written) {
                    return $this->bringIn($user, $zip, $data, $written);
                });
            } catch (\Throwable $e) {
                Storage::disk('local')->delete($written);
                throw $e;
            }
        } finally {
            $zip->close();
        }
    }

    /** data.json, after checking it is ours and the zip isn't too big to unpack. */
    private function read(ZipArchive $zip): array
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            throw self::bad('That file has too many parts to be a Velocity export.');
        }
        $bytes = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $bytes += (int) ($zip->statIndex($i)['size'] ?? 0);
        }
        if ($bytes > self::MAX_BYTES) {
            throw self::bad('That file is too big to bring in.');
        }
        $json = $zip->getFromName('data.json');
        $data = is_string($json) ? json_decode($json, true) : null;
        if (! is_array($data) || ($data['format'] ?? null) !== self::FORMAT || ! is_array($data['tables'] ?? null)) {
            throw self::bad('That isn’t a Velocity export (.zip).');
        }
        if ((int) ($data['version'] ?? 0) > self::VERSION) {
            throw self::bad('That export comes from a newer Velocity. Update this one first.');
        }

        return $data;
    }

    /** @param list<string> $written files saved so far (removed again if anything fails) */
    private function bringIn(User $user, ZipArchive $zip, array $data, array &$written): array
    {
        $map = [];
        $later = [];
        $counts = [];
        $disk = Storage::disk('local');
        if (! empty($data['tables']['weight_profiles'])) {
            DB::table('weight_profiles')->where('user_id', $user->id)->delete();
        }
        foreach (self::TABLES as $table => $spec) {
            $rows = $data['tables'][$table] ?? [];
            if (! is_array($rows)) {
                continue;
            }
            $columns = $this->columnsOf($table);
            $hasId = isset($columns['id']);
            $refs = ($spec['refs'] ?? []) + ($spec['pivot'] ?? []) + (isset($spec['own']) && is_array($spec['own']) ? [$spec['own'][1] => $spec['own'][0]] : []);
            $counts[$table] = 0;
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $old = isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : null;
                // Only this table's own columns, never its id or owner, and plain values.
                $values = array_map(
                    fn ($v) => is_array($v) || is_object($v) ? json_encode($v) : $v,
                    array_intersect_key($row, array_diff_key($columns, ['id' => 1, 'user_id' => 1])),
                );
                foreach ($refs as $column => $ref) {
                    if (! isset($values[$column])) {
                        continue;
                    }
                    $new = $map[$ref][(int) $values[$column]] ?? null;
                    if ($new === null && ! ($columns[$column] ?? false)) {
                        continue 2; // what it hangs off didn't come along
                    }
                    $values[$column] = $new;
                }
                if (isset($spec['poly'])) {
                    [$typeColumn, $idColumn, $types] = $spec['poly'];
                    $new = $map[$types[$values[$typeColumn] ?? ''] ?? ''][(int) ($values[$idColumn] ?? 0)] ?? null;
                    if ($new === null) {
                        continue;
                    }
                    $values[$idColumn] = $new;
                }
                foreach ($spec['later'] ?? [] as $column) {
                    if (! empty($values[$column]) && $old !== null) {
                        $later[$table][$column][$old] = (int) $values[$column];
                    }
                    $values[$column] = null;
                }
                if (isset($spec['file'])) {
                    $entry = (string) ($row['path'] ?? '');
                    $stream = preg_match('~^files/[a-z_]+/\d+(\.[a-z0-9]{1,10})?$~', $entry) ? $zip->getStream($entry) : false;
                    if (! $stream) {
                        continue; // a file row without its file is no use
                    }
                    $path = "{$spec['file']}/{$user->id}/".Str::uuid().self::extension($entry);
                    $disk->writeStream($path, $stream);
                    $written[] = $path;
                    $values['path'] = $path;
                }
                if (($spec['own'] ?? null) === 'user') {
                    $values['user_id'] = $user->id;
                }
                if ($hasId) {
                    $new = DB::table($table)->insertGetId($values);
                    if ($old !== null) {
                        $map[$table][$old] = $new;
                    }
                } else {
                    DB::table($table)->insertOrIgnore($values);
                }
                $counts[$table]++;
            }
        }
        // Rows pointing at others in the same table (a task's parent, the repeat it came from).
        foreach ($later as $table => $columns) {
            foreach ($columns as $column => $pairs) {
                foreach ($pairs as $old => $target) {
                    if (isset($map[$table][$old], $map[$table][$target])) {
                        DB::table($table)->where('id', $map[$table][$old])->update([$column => $map[$table][$target]]);
                    }
                }
            }
        }
        // Pictures and links inside notes point at the new ids.
        foreach (DB::table('notes')->where('user_id', $user->id)->whereNotNull('body')->get(['id', 'body']) as $note) {
            $body = self::relink((string) $note->body, $map);
            if ($body !== $note->body) {
                DB::table('notes')->where('id', $note->id)->update(['body' => $body]);
            }
        }
        $this->settings($user, $zip, (array) ($data['user'] ?? []), $written);

        return $counts;
    }

    /** The account's settings, and its wallpaper picture. */
    private function settings(User $user, ZipArchive $zip, array $settings, array &$written): void
    {
        $values = array_intersect_key($settings, array_flip(self::USER_SETTINGS));
        foreach (['planner_hours', 'planner_settings', 'focus_settings'] as $json) {
            if (isset($values[$json]) && is_string($values[$json])) {
                $values[$json] = json_decode($values[$json], true);
            }
        }
        $entry = (string) ($settings['wallpaper_file'] ?? '');
        $stream = preg_match('~^files/wallpaper(\.[a-z0-9]{1,10})?$~', $entry) ? $zip->getStream($entry) : false;
        if ($stream) {
            $path = "wallpapers/{$user->id}/".Str::uuid().self::extension($entry);
            Storage::disk('local')->writeStream($path, $stream);
            $written[] = $path;
            $values['wallpaper_path'] = $path;
        } elseif (($values['wallpaper'] ?? null) === 'own') {
            $values['wallpaper'] = null;
        }
        if (isset($values['timezone']) && ! in_array($values['timezone'], timezone_identifiers_list(), true)) {
            unset($values['timezone']);
        }
        $user->forceFill($values)->save();
    }

    /** Addresses inside a note's text (pictures, links to tasks, notes, projects…) pointed at the new ids. */
    public static function relink(string $html, array $map): string
    {
        $swap = fn (string $table, string $id) => (string) ($map[$table][(int) $id] ?? $id);

        return preg_replace_callback('~\b(src|href)="(/[^"]*)"~', function ($m) use ($swap) {
            $url = preg_replace_callback('~^/(notes/files|files|project-files|projects)/(\d+)~', fn ($x) => '/'.$x[1].'/'.$swap(
                ['notes/files' => 'note_files', 'files' => 'task_files', 'project-files' => 'project_files', 'projects' => 'projects'][$x[1]],
                $x[2],
            ), $m[2]);
            $url = preg_replace_callback('~([?&]|&amp;)(task|note|person|habit)=(\d+)~', fn ($x) => $x[1].$x[2].'='.$swap(
                ['task' => 'tasks', 'note' => 'notes', 'person' => 'people', 'habit' => 'habits'][$x[2]],
                $x[3],
            ), $url);

            return "{$m[1]}=\"{$url}\"";
        }, $html) ?? $html;
    }

    /** @return array<string, bool> column => nullable */
    private function columnsOf(string $table): array
    {
        return $this->columns[$table] ??= collect(Schema::getColumns($table))->mapWithKeys(fn ($c) => [$c['name'] => (bool) $c['nullable']])->all();
    }

    private static function extension(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return preg_match('/^[a-z0-9]{1,10}$/', $ext) ? ".{$ext}" : '';
    }

    private static function bad(string $message): ValidationException
    {
        return ValidationException::withMessages(['file' => $message]);
    }
}
