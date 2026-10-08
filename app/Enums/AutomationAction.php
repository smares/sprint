<?php

namespace App\Enums;

/**
 * What an automation does; the value is stored in the `type` of an entry in automations.actions.
 */
enum AutomationAction: string
{
    case SetAssignee = 'set_assignee';
    case SetStatus = 'set_status';
    case AddTag = 'add_tag';
    case ShiftDueDate = 'shift_due_date';
    case Comment = 'comment';
    case Notify = 'notify';

    public function label(): string
    {
        return match ($this) {
            self::SetAssignee => __('Set the assignee'),
            self::SetStatus => __('Set the status'),
            self::AddTag => __('Add a tag'),
            self::ShiftDueDate => __('Move the due date by days'),
            self::Comment => __('Write a comment'),
            self::Notify => __('Notify a person'),
        };
    }
}
