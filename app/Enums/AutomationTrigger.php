<?php

namespace App\Enums;

/**
 * What starts an automation; the value is stored in automations.trigger. `automations.trigger_value` narrows it
 * down: the status moved to, the person assigned, the tag added or the option a select field is set to (an option
 * belongs to exactly one field, so its id names both).
 */
enum AutomationTrigger: string
{
    case StatusChanged = 'status_changed';
    case AssigneeChanged = 'assignee_changed';
    case TagAdded = 'tag_added';
    case FieldSet = 'field_set';

    /**
     * The trigger that reacts to a history entry of this type, if any.
     */
    public static function forActivity(ActivityType $type): ?self
    {
        foreach (self::cases() as $trigger) {
            if ($trigger->activityType() === $type) {
                return $trigger;
            }
        }

        return null;
    }

    /**
     * The history entry this trigger reacts to.
     */
    public function activityType(): ActivityType
    {
        return match ($this) {
            self::StatusChanged => ActivityType::StatusChanged,
            self::AssigneeChanged => ActivityType::AssigneeChanged,
            self::TagAdded => ActivityType::TagsAdded,
            self::FieldSet => ActivityType::FieldChanged,
        };
    }

    /**
     * Whether the trigger can be left open ("any person"); the others need a status or tag.
     */
    public function valueIsOptional(): bool
    {
        return $this === self::AssigneeChanged;
    }

    public function label(): string
    {
        return match ($this) {
            self::StatusChanged => __('Status changes to'),
            self::AssigneeChanged => __('Assignee changes to'),
            self::TagAdded => __('Tag is added'),
            self::FieldSet => __('Field gets the value'),
        };
    }
}
