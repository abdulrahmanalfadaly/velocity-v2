<?php

namespace App\Support;

use Laravel\Sanctum\PersonalAccessToken;

/**
 * What an access key may do: one choice per area (none, read, or read and write),
 * or full access. Stored as Sanctum abilities like "tasks:read", "tasks:write", "*".
 */
class ApiAccess
{
    /** area => [label, can it be written?] */
    public const AREAS = [
        'tasks' => ['Tasks & focus', true],
        'projects' => ['Projects & goals', true],
        'planner' => ['Planner', true],
        'notes' => ['Notes', true],
        'habits' => ['Habits', true],
        'people' => ['People', true],
        'review' => ['Review', false],
        'github' => ['GitHub', true],
    ];

    /**
     * The choices of the "New key" form → abilities. Write always includes read.
     *
     * @param  array<string, string>  $levels  area => none | read | write
     * @return list<string>
     */
    public static function abilities(bool $full, array $levels): array
    {
        if ($full) {
            return ['*'];
        }
        $out = [];
        foreach (self::AREAS as $area => [, $writable]) {
            $level = $levels[$area] ?? 'none';
            if ($level === 'read' || $level === 'write') {
                $out[] = "{$area}:read";
            }
            if ($level === 'write' && $writable) {
                $out[] = "{$area}:write";
            }
        }

        return $out;
    }

    /** "Full access", or "Tasks & focus: write · Notes: read". */
    public static function summary(array $abilities): string
    {
        if (in_array('*', $abilities, true)) {
            return 'Full access';
        }
        $parts = [];
        foreach (self::AREAS as $area => [$label]) {
            if (in_array("{$area}:write", $abilities, true)) {
                $parts[] = "{$label}: read & write";
            } elseif (in_array("{$area}:read", $abilities, true)) {
                $parts[] = "{$label}: read";
            }
        }

        return $parts ? implode(' · ', $parts) : 'No access';
    }

    /** "tasks:write" → "Tasks & focus: write". */
    public static function label(string $ability): string
    {
        [$area, $level] = array_pad(explode(':', $ability, 2), 2, 'read');

        return (self::AREAS[$area][0] ?? $area).": {$level}";
    }

    /** The name of the key making this request ("Mark"), or null on the screens. */
    public static function via(): ?string
    {
        $token = request()?->user()?->currentAccessToken();

        return $token instanceof PersonalAccessToken ? $token->name : null;
    }
}
