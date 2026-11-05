<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Engine\Conditions;

/** Maps a `when.type` to its condition. One shared instance per type. */
final class Factory
{
    /** @var array<string, class-string<ConditionInterface>> */
    private const CLASSES = [
        'login' => Login::class,
        'role' => Role::class,
        'referrer' => Referrer::class,
        'agent' => Agent::class,
        'header' => Header::class,
        'cookie' => Cookie::class,
        'ip' => Ip::class,
        'language' => Language::class,
        'server' => Server::class,
        'filter' => Filter::class,
        '404' => NotFound::class,
    ];

    /** @var array<string, ConditionInterface> */
    private static array $instances = [];

    /** @throws \InvalidArgumentException for an unknown type */
    public static function for(string $type): ConditionInterface
    {
        if (!isset(self::CLASSES[$type])) {
            throw new \InvalidArgumentException('Unknown redirect condition type: ' . $type);
        }
        $class = self::CLASSES[$type];
        return self::$instances[$type] ??= new $class();
    }

    /** @return list<string> */
    public static function types(): array
    {
        return array_map('strval', array_keys(self::CLASSES));
    }
}
