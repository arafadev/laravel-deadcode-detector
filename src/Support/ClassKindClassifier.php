<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Support;

use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\Class_;

/**
 * Classifies PHP classes using inheritance, interfaces, naming, optional DI bindings,
 * and path hints (fallback). Register {@see registerExtension()} for additional kinds.
 */
final class ClassKindClassifier
{
    public const KIND_CONTROLLER = 'controller';

    public const KIND_MODEL = 'model';

    public const KIND_COMMAND = 'command';

    public const KIND_JOB = 'job';

    public const KIND_MIDDLEWARE = 'middleware';

    public const KIND_REQUEST = 'request';

    public const KIND_RESOURCE = 'resource';

    public const KIND_POLICY = 'policy';

    public const KIND_NOTIFICATION = 'notification';

    public const KIND_MAIL = 'mail';

    public const KIND_EVENT = 'event';

    public const KIND_LISTENER = 'listener';

    public const KIND_OBSERVER = 'observer';

    public const KIND_RULE = 'rule';

    public const KIND_SERVICE = 'service';

    public const KIND_ACTION = 'action';

    public const KIND_ENUM = 'enum';

    public const KIND_CONTRACT = 'contract';

    /** Concrete class is registered via container bind/singleton second argument. */
    public const KIND_CONTAINER_BOUND = 'container_bound';

    public const KIND_UNKNOWN = 'unknown';

    /**
     * Kinds that indicate a dedicated Laravel role — exclude from generic “service” candidates.
     *
     * @var list<string>
     */
    public const SERVICE_CANDIDATE_BLOCKERS = [
        self::KIND_CONTROLLER,
        self::KIND_MODEL,
        self::KIND_COMMAND,
        self::KIND_JOB,
        self::KIND_MIDDLEWARE,
        self::KIND_REQUEST,
        self::KIND_RESOURCE,
        self::KIND_POLICY,
        self::KIND_NOTIFICATION,
        self::KIND_MAIL,
        self::KIND_EVENT,
        self::KIND_LISTENER,
        self::KIND_OBSERVER,
        self::KIND_RULE,
        self::KIND_ENUM,
    ];

    /** @var list<callable(Class_, ?string, string, ?ClassKindContext): list<string>>|null */
    private static ?array $extensions = null;

    /**
     * Register an extra classifier; callables return additional kind strings.
     *
     * @param callable(Class_, ?string, string, ?ClassKindContext): list<string> $classifier
     */
    public static function registerExtension(callable $classifier): void
    {
        self::$extensions ??= [];
        self::$extensions[] = $classifier;
    }

    /**
     * @return list<string> ordered signals (first match in {@see primaryKind()} wins)
     */
    public static function classify(
        Class_ $class,
        ?string $namespace,
        string $filePath,
        ?ClassKindContext $ctx = null,
    ): array {
        $kinds = [];
        $fqn   = self::fqn($class, $namespace);
        $short = $class->name !== null ? $class->name->name : '';

        foreach ($class->implements as $iface) {
            if (self::nameEndsWith($iface, 'ShouldQueue')) {
                $kinds[] = self::KIND_JOB;
            }
            if (self::nameEndsWith($iface, 'Middleware')) {
                $kinds[] = self::KIND_MIDDLEWARE;
            }
            if (self::nameEndsWith($iface, 'Rule') || self::nameEndsWith($iface, 'InvokableRule')) {
                $kinds[] = self::KIND_RULE;
            }
            if (self::nameEndsWith($iface, 'ValidationRule')) {
                $kinds[] = self::KIND_RULE;
            }
        }

        if ($class->extends !== null) {
            if (self::extendMatchesFrameworkBase($class->extends, 'Model')
                || self::extendMatchesFrameworkBase($class->extends, 'Authenticatable')
                || self::nameEndsWith($class->extends, 'Pivot')) {
                $kinds[] = self::KIND_MODEL;
            }
            if (self::extendMatchesFrameworkBase($class->extends, 'Controller')) {
                $kinds[] = self::KIND_CONTROLLER;
            }
            if (self::extendMatchesFrameworkBase($class->extends, 'Command')) {
                $kinds[] = self::KIND_COMMAND;
            }
            if (self::extendMatchesFrameworkBase($class->extends, 'Job') || self::nameEndsWith($class->extends, 'BroadcastableEvent')) {
                $kinds[] = self::KIND_JOB;
            }
            if (self::extendMatchesFrameworkBase($class->extends, 'Mailable')) {
                $kinds[] = self::KIND_MAIL;
            }
            if (self::extendMatchesFrameworkBase($class->extends, 'Notification')) {
                $kinds[] = self::KIND_NOTIFICATION;
            }
            if (self::extendMatchesFrameworkBase($class->extends, 'FormRequest')) {
                $kinds[] = self::KIND_REQUEST;
            }
            if (self::extendMatchesFrameworkBase($class->extends, 'JsonResource') || self::extendMatchesFrameworkBase($class->extends, 'ResourceCollection')) {
                $kinds[] = self::KIND_RESOURCE;
            }
            if (self::extendMatchesFrameworkBase($class->extends, 'Policy')) {
                $kinds[] = self::KIND_POLICY;
            }
            if (self::extendMatchesFrameworkBase($class->extends, 'Middleware')) {
                $kinds[] = self::KIND_MIDDLEWARE;
            }
            if (self::extendMatchesFrameworkBase($class->extends, 'Rule') || self::nameEndsWith($class->extends, 'InvokableRule')) {
                $kinds[] = self::KIND_RULE;
            }
        }

        if ($short !== '') {
            if (str_ends_with($short, 'Action')) {
                $kinds[] = self::KIND_ACTION;
            }
            if (str_ends_with($short, 'Service')) {
                $kinds[] = self::KIND_SERVICE;
            }
            if (str_ends_with($short, 'Observer')) {
                $kinds[] = self::KIND_OBSERVER;
            }
            if (str_ends_with($short, 'Listener')) {
                $kinds[] = self::KIND_LISTENER;
            }
            if (str_ends_with($short, 'Policy')) {
                $kinds[] = self::KIND_POLICY;
            }
            if ($short !== '' && $short !== 'Middleware' && str_ends_with($short, 'Middleware')) {
                $kinds[] = self::KIND_MIDDLEWARE;
            }
        }

        $fqnLower = strtolower($fqn);
        $pathNorm = strtolower(str_replace('\\', '/', $filePath));

        if (str_contains($fqnLower, '\\events\\') || str_contains($pathNorm, '/events/')) {
            $kinds[] = self::KIND_EVENT;
        }
        if (str_contains($pathNorm, '/listeners/')) {
            $kinds[] = self::KIND_LISTENER;
        }
        if (str_contains($pathNorm, '/notifications/')) {
            $kinds[] = self::KIND_NOTIFICATION;
        }
        if (str_contains($pathNorm, '/mail/')) {
            $kinds[] = self::KIND_MAIL;
        }
        if (str_contains($pathNorm, '/http/resources/')
            || str_contains($pathNorm, '/transformers/')) {
            $kinds[] = self::KIND_RESOURCE;
        }
        if (str_contains($pathNorm, '/rules/')) {
            $kinds[] = self::KIND_RULE;
        }
        if (str_contains($pathNorm, '/policies/')) {
            $kinds[] = self::KIND_POLICY;
        }
        if (str_contains($pathNorm, '/http/middleware/')) {
            $kinds[] = self::KIND_MIDDLEWARE;
        }
        if (str_contains($pathNorm, '/http/controllers/')) {
            $kinds[] = self::KIND_CONTROLLER;
        }
        if (str_contains($pathNorm, '/models/')) {
            $kinds[] = self::KIND_MODEL;
        }
        if (str_contains($pathNorm, '/contracts/')) {
            $kinds[] = self::KIND_CONTRACT;
        }
        if (str_contains($pathNorm, '/services/') || str_contains($pathNorm, '/repositories/')) {
            $kinds[] = self::KIND_SERVICE;
        }
        if (str_contains($pathNorm, '/actions/')) {
            $kinds[] = self::KIND_ACTION;
        }

        $norm = strtolower(ltrim($fqn, '\\'));
        if ($ctx !== null && $norm !== '' && isset($ctx->boundConcreteNorms[$norm])) {
            $kinds[] = self::KIND_CONTAINER_BOUND;
        }

        if (self::$extensions !== null) {
            foreach (self::$extensions as $ext) {
                foreach ($ext($class, $namespace, $filePath, $ctx) as $k) {
                    if (is_string($k) && $k !== '') {
                        $kinds[] = $k;
                    }
                }
            }
        }

        $kinds = array_values(array_unique(array_filter($kinds)));

        return $kinds !== [] ? $kinds : [self::KIND_UNKNOWN];
    }

    public static function hasKind(array $kinds, string $kind): bool
    {
        return in_array($kind, $kinds, true);
    }

    /**
     * @param list<string> $needles
     */
    public static function hasAnyKind(array $kinds, array $needles): bool
    {
        foreach ($needles as $n) {
            if (self::hasKind($kinds, $n)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pick the strongest framework kind for display / routing logic.
     *
     * @param list<string> $kinds
     */
    public static function primaryKind(array $kinds): string
    {
        $priority = [
            self::KIND_CONTROLLER,
            self::KIND_MODEL,
            self::KIND_COMMAND,
            self::KIND_JOB,
            self::KIND_MIDDLEWARE,
            self::KIND_REQUEST,
            self::KIND_RESOURCE,
            self::KIND_POLICY,
            self::KIND_NOTIFICATION,
            self::KIND_MAIL,
            self::KIND_EVENT,
            self::KIND_LISTENER,
            self::KIND_OBSERVER,
            self::KIND_RULE,
            self::KIND_ENUM,
            self::KIND_ACTION,
            self::KIND_SERVICE,
            self::KIND_CONTAINER_BOUND,
            self::KIND_CONTRACT,
            self::KIND_UNKNOWN,
        ];

        foreach ($priority as $p) {
            if (self::hasKind($kinds, $p)) {
                return $p;
            }
        }

        return self::KIND_UNKNOWN;
    }

    /**
     * Classes that look like app “services” (DI-registered, *Service, or Services/Repositories path)
     * but are not better described as another Laravel type.
     *
     * @param list<string> $kinds
     */
    public static function isServiceLikeCandidate(array $kinds, string $filePath): bool
    {
        if (self::hasAnyKind($kinds, self::SERVICE_CANDIDATE_BLOCKERS)) {
            return false;
        }

        if (self::hasKind($kinds, self::KIND_SERVICE) || self::hasKind($kinds, self::KIND_CONTAINER_BOUND)) {
            return true;
        }

        $pathNorm = strtolower(str_replace('\\', '/', $filePath));

        return str_contains($pathNorm, '/services/') || str_contains($pathNorm, '/repositories/');
    }

    /**
     * Policies: extends Policy / *Policy suffix / folder policies/.
     *
     * @param list<string> $kinds
     */
    public static function isPolicyCandidate(array $kinds, string $fqcn): bool
    {
        if (self::hasKind($kinds, self::KIND_POLICY)) {
            return true;
        }

        $base = function_exists('class_basename') ? class_basename($fqcn) : basename(str_replace('\\', '/', $fqcn));

        return str_ends_with($base, 'Policy');
    }

    /**
     * @param list<string> $kinds
     */
    public static function isObserverCandidate(array $kinds, string $filePath): bool
    {
        if (self::hasKind($kinds, self::KIND_OBSERVER)) {
            return true;
        }
        $pathNorm = strtolower(str_replace('\\', '/', $filePath));

        return str_contains($pathNorm, '/observers/');
    }

    /**
     * @param list<string> $kinds
     */
    public static function isActionCandidate(array $kinds, string $filePath): bool
    {
        if (self::hasAnyKind($kinds, self::SERVICE_CANDIDATE_BLOCKERS)) {
            return false;
        }

        if (self::hasKind($kinds, self::KIND_ACTION)) {
            return true;
        }

        $pathNorm = strtolower(str_replace('\\', '/', $filePath));

        return str_contains($pathNorm, '/actions/');
    }

    private static function fqn(Class_ $class, ?string $namespace): string
    {
        $n = $class->name !== null ? $class->name->name : '';

        return $namespace !== null && $namespace !== '' ? $namespace . '\\' . $n : $n;
    }

    private static function nameEndsWith(Name|FullyQualified $name, string $suffix): bool
    {
        if ($name instanceof Name) {
            return $name->getLast() === $suffix;
        }

        $s = $name->toString();

        return str_ends_with($s, '\\' . $suffix) || $s === $suffix;
    }

    /**
     * Matches parent short names like ArtisanCommand as well as \\...\\Command.
     */
    private static function extendMatchesFrameworkBase(Name|FullyQualified $name, string $suffix): bool
    {
        if ($name instanceof Name) {
            return str_ends_with($name->getLast(), $suffix);
        }

        $s = $name->toString();

        return str_ends_with($s, '\\' . $suffix) || $s === $suffix;
    }
}

