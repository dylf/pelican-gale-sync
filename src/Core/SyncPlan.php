<?php

namespace Dylf\GaleSync\Core;

/**
 * What a sync would do to a server, in the order it would do it.
 */
final readonly class SyncPlan
{
    /**
     * @param  list<PlanItem>  $items
     */
    public function __construct(public array $items) {}

    public function hasChanges(): bool
    {
        foreach ($this->items as $item) {
            if ($item->action->changes()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<PlanItem>
     */
    public function with(PlanAction ...$actions): array
    {
        return array_values(array_filter($this->items, fn (PlanItem $item) => in_array($item->action, $actions, true)));
    }

    /**
     * @return array<string, int> count per action
     */
    public function counts(): array
    {
        $counts = [];

        foreach (PlanAction::cases() as $action) {
            $counts[$action->value] = count($this->with($action));
        }

        return $counts;
    }
}
