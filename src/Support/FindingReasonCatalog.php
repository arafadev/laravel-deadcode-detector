<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Support;

/**
 * Default explanations when an analyzer does not supply a custom {@see DeadCodeResult::$reason}.
 */
final class FindingReasonCatalog
{
    public static function defaultExplanation(string $analyzerName, string $type): string
    {
        return match ($type) {
            'controller' => 'No reference from route files (or related scans) tied this controller to app entry points, and it was not seen as a hierarchy target elsewhere under the current scan paths.',
            'controller_method' => 'This action is not registered on any route parsed from scanned route files, and it was not treated as invoked from other scanned project code using controller-style calls.',
            'model' => 'This model class was not referenced from other scanned PHP (type hints, `new`, inheritance, etc.) under current analyzer rules.',
            'model_scope' => 'This query scope method was not found to be called from scanned code on the owning model.',
            'view' => 'No `view()`, `View::make`, Blade include/extends chain, or other scanned reference reached this template from PHP or other views.',
            'helper' => 'This helper symbol was not called from scanned PHP where calls could be resolved statically.',
            'route' => 'This named route was not referenced as a string literal or static route helper argument in scanned PHP.',
            'middleware' => 'This middleware class was not registered in kernel/bootstrap or referenced from scanned routes/controllers using resolvable class references.',
            'migration' => 'Migration static checks flagged an issue (or unused migration) based on scanned paths and naming rules.',
            'event' => 'This event was not dispatched or referenced in a way visible to the event analyzer under current scan paths.',
            'listener' => 'This listener was not registered for any event or referenced from provider/config code the listener analyzer sees.',
            'binding' => 'Container binding analysis found a potential mismatch, duplicate, or unreachable binding for this scan configuration.',
            'job' => 'No job dispatch, queue usage, or class reference in the dependency graph indicated this job is used (with current exclusions and scan roots).',
            'observer' => 'This observer was not registered for any model in scanned provider/config or observer registration tables.',
            'request' => 'This form request was not type-hinted on any scanned controller or closure entry point.',
            'resource' => 'This API resource/transformer was not referenced from scanned code via `new`, static calls, or similar patterns.',
            'policy' => 'This policy was not referenced from scanned routes, controllers, or authorization calls in a detectable way.',
            'action' => 'This action class was not referenced from scanned callers in a way the actions analyzer can prove.',
            'service' => 'This service class was not referenced from scanned code, container binding targets, or matching service conventions.',
            'command' => 'This console command was not registered with Artisan in scanned provider or console kernel files.',
            'notification' => 'This notification was not sent or referenced from scanned `notify()` / `Notification::` usage patterns.',
            'mailable' => 'This mailable was not referenced from scanned `Mail::` or mailable usage patterns.',
            'rule' => 'This validation rule was not instantiated or referenced from scanned code in a detectable way.',
            'enum' => 'This enum was not referenced from scanned PHP (cases, type hints, or value access) under current rules.',
            default => sprintf(
                'Flagged by analyzer "%s" (type "%s") under the configured scan paths and static rules; verify manually before removal.',
                $analyzerName,
                $type
            ),
        };
    }
}
