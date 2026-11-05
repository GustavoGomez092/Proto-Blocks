<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Engine;

/**
 * Counts a fired rule (spec §3 "Hit tracking"). Implemented by Track C's
 * Storage\Hits; defined here so the engine does not depend on storage.
 */
interface HitRecorder
{
    public function record(string $group, string $ruleId): void;
}
