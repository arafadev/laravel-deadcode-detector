<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Support;

use Arafa\DeadcodeDetector\DTOs\DeadCodeResult;

/**
 * Safe, human-facing next steps for each finding. Nothing here should imply automatic deletion.
 */
final class FindingFixSuggestion
{
    public const ACTION_REVIEW = 'review';

    public const ACTION_DELETE = 'delete';

    public const ACTION_IGNORE = 'ignore';

    /**
     * Short signal for why the item was surfaced (type-aware, conservative wording: "scanned" where relevant).
     */
    public static function contextHint(DeadCodeResult $r): string
    {
        return match ($r->type) {
            'controller' => 'Not referenced in scanned routes.',
            'controller_method' => 'No route or scanned call chain references this action.',
            'model' => 'No references found in scanned PHP.',
            'model_scope' => 'No method calls found for this scope in scanned code.',
            'view' => 'No view() / include / extends path from scanned PHP or Blade reached this file.',
            'helper' => 'No calls found in scanned PHP.',
            'route' => 'This route name was not referenced in scanned code.',
            'middleware' => 'Not registered or referenced from scanned kernel / routes.',
            'migration' => 'Flagged by migration rules — verify before changing schema history.',
            'event' => 'No dispatch or reference found in scanned code.',
            'listener' => 'No event registration found in scanned providers or code.',
            'binding' => 'Container binding looks unused or inconsistent under current scan.',
            'job' => 'No dispatch or reference found in scanned code.',
            'observer' => 'Not registered for a model in scanned registration points.',
            'request' => 'Not type-hinted on scanned controllers or closures.',
            'resource' => 'Not referenced from scanned API / transformation code.',
            'policy' => 'Not referenced from scanned authorization paths.',
            'action' => 'Not referenced from scanned callers.',
            'service' => 'Not referenced from scanned services or bindings.',
            'command' => 'Not registered in scanned Artisan kernel / providers.',
            'notification' => 'Not referenced from scanned notification usage.',
            'mailable' => 'Not referenced from scanned mail usage.',
            'rule' => 'Not referenced from scanned validation code.',
            'enum' => 'Not referenced from scanned PHP.',
            default => 'Unused under current static scan — confirm outside scanned paths.',
        };
    }

    /**
     * Ordered list of safe actions (review always first; delete is conditional in wording only).
     *
     * @return list<array{action: string, label: string, detail: string}>
     */
    public static function suggestedActions(DeadCodeResult $r): array
    {
        $actions = [
            [
                'action'  => self::ACTION_REVIEW,
                'label'   => 'Review',
                'detail'  => 'Open the file and confirm it is unused (tests, packages, runtime config, or string-based references may be outside the scan).',
            ],
        ];

        $actions[] = self::deleteSuggestion($r);

        $actions[] = [
            'action'  => self::ACTION_IGNORE,
            'label'   => 'Ignore / exclude',
            'detail'  => 'If the code is intentionally kept, use config/deadcode.php → ignore (classes, folders, patterns), add // @deadcode-ignore in the file or Blade comment, or broaden exclude_paths — nothing is deleted automatically.',
        ];

        return $actions;
    }

    /**
     * One line for narrow terminals (console table column).
     */
    public static function actionsSummaryLine(DeadCodeResult $r): string
    {
        return match ($r->confidenceLevel) {
            DetectionConfidence::LOW => 'Review first; do not delete until usage is ruled out; exclude in config if intentional.',
            DetectionConfidence::MEDIUM => 'Review, trace dynamic usage, then delete only if still unused; or exclude in config.',
            default => $r->isSafeToDelete
                ? 'Review briefly, then remove if confirmed unused; or exclude in config if intentional.'
                : 'Review dependencies and runtime usage before removal; or exclude in config if intentional.',
        };
    }

    /**
     * @return array{
     *     context_hint: string,
     *     actions: list<array{action: string, label: string, detail: string}>
     * }
     */
    public static function payload(DeadCodeResult $r): array
    {
        return [
            'context_hint' => self::contextHint($r),
            'actions'      => self::suggestedActions($r),
        ];
    }

    /**
     * @return array{action: string, label: string, detail: string}
     */
    private static function deleteSuggestion(DeadCodeResult $r): array
    {
        return match ($r->confidenceLevel) {
            DetectionConfidence::LOW => [
                'action'  => self::ACTION_DELETE,
                'label'   => 'Delete (not recommended yet)',
                'detail'  => 'Static analysis is uncertain — verify thoroughly (including dynamic references) before removing anything.',
            ],
            DetectionConfidence::MEDIUM => [
                'action'  => self::ACTION_DELETE,
                'label'   => 'Delete after confirmation',
                'detail'  => 'If review shows no references, you may remove the file or member; commit separately for easy revert.',
            ],
            default => $r->isSafeToDelete
                ? [
                    'action'  => self::ACTION_DELETE,
                    'label'   => 'Delete after confirmation',
                    'detail'  => 'Higher-confidence unused signal — still confirm in your app, then remove manually if appropriate.',
                ]
                : [
                    'action'  => self::ACTION_DELETE,
                    'label'   => 'Delete cautiously',
                    'detail'  => 'Analyzer did not mark this as safe to delete — check side effects, contracts, and framework hooks before removal.',
                ],
        };
    }
}
