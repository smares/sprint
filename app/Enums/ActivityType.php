<?php

namespace App\Enums;

/**
 * What an entry in a task's history records; the value is stored in task_activities.type.
 */
enum ActivityType: string
{
    case Created = 'created';
    case Duplicated = 'duplicated';
    case StatusChanged = 'status_changed';
    case AssigneeChanged = 'assignee_changed';
    case DueDateChanged = 'due_date_changed';
    case StartDateChanged = 'start_date_changed';
    case TitleChanged = 'title_changed';
    case DescriptionChanged = 'description_changed';
    case ParentChanged = 'parent_changed';
    case RecurrenceChanged = 'recurrence_changed';
    case RecurrenceCreated = 'recurrence_created';
    case RecurrenceEnded = 'recurrence_ended';
    case FieldChanged = 'field_changed';
    case AttachmentsAdded = 'attachments_added';
    case AttachmentsRemoved = 'attachments_removed';
    case TagsAdded = 'tags_added';
    case TagsRemoved = 'tags_removed';
    case CollaboratorsAdded = 'collaborators_added';
    case CollaboratorsRemoved = 'collaborators_removed';
    case BlockersAdded = 'blockers_added';
    case BlockersRemoved = 'blockers_removed';
    case BlockingAdded = 'blocking_added';
    case BlockingRemoved = 'blocking_removed';

    /**
     * The symbol of the entry in the history timeline.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Created => 'plus',
            self::Duplicated => 'document-duplicate',
            self::StatusChanged => 'arrow-path',
            self::AssigneeChanged, self::CollaboratorsAdded, self::CollaboratorsRemoved => 'user',
            self::DueDateChanged, self::StartDateChanged => 'calendar',
            self::TitleChanged, self::DescriptionChanged => 'pencil',
            self::ParentChanged => 'arrow-turn-down-right',
            self::RecurrenceChanged, self::RecurrenceCreated, self::RecurrenceEnded => 'arrow-path-rounded-square',
            self::FieldChanged => 'adjustments-horizontal',
            self::AttachmentsAdded, self::AttachmentsRemoved => 'paper-clip',
            self::TagsAdded, self::TagsRemoved => 'tag',
            self::BlockersAdded, self::BlockersRemoved, self::BlockingAdded, self::BlockingRemoved => 'lock-closed',
        };
    }

    /**
     * The sentence after the person's name, e.g. "changed the status from “Open” to “Done”".
     *
     * @param  array<string, mixed>  $data
     */
    public function sentence(array $data): string
    {
        $from = $data['from'] ?? '–';
        $to = $data['to'] ?? '–';
        $names = implode(', ', $data['names'] ?? []);

        return match ($this) {
            self::Created => __('created the task'),
            self::Duplicated => __('duplicated the task'),
            self::StatusChanged => __('changed the status from “:from” to “:to”', ['from' => $from, 'to' => $to]),
            self::AssigneeChanged => __('changed the assignee from :from to :to', ['from' => $from, 'to' => $to]),
            self::DueDateChanged => __('changed the due date from :from to :to', ['from' => $from, 'to' => $to]),
            self::StartDateChanged => __('changed the start date from :from to :to', ['from' => $from, 'to' => $to]),
            self::TitleChanged => __('changed the title from “:from” to “:to”', ['from' => $from, 'to' => $to]),
            self::DescriptionChanged => __('changed the description'),
            self::ParentChanged => ($data['to'] ?? null) === null
                ? __('detached the task from “:from”', ['from' => $from])
                : __('moved the task under “:to”', ['to' => $to]),
            self::RecurrenceChanged => $to === '–' ? __('removed the repetition') : __('set the repetition to “:to”', ['to' => $to]),
            self::RecurrenceCreated => __('created the next repetition for :to', ['to' => $to]),
            self::RecurrenceEnded => __('ended the repetition (end date reached)'),
            self::FieldChanged => __('changed :field from “:from” to “:to”', ['field' => $data['name'] ?? __('a field'), 'from' => $from, 'to' => $to]),
            self::AttachmentsAdded => __('attached :names', ['names' => $names]),
            self::AttachmentsRemoved => __('removed the attachment :names', ['names' => $names]),
            self::TagsAdded => __('added the tags :names', ['names' => $names]),
            self::TagsRemoved => __('removed the tags :names', ['names' => $names]),
            self::CollaboratorsAdded => __('added :names as collaborators', ['names' => $names]),
            self::CollaboratorsRemoved => __('removed :names as collaborators', ['names' => $names]),
            self::BlockersAdded => __('added :names as blockers', ['names' => $names]),
            self::BlockersRemoved => __('removed :names as blockers', ['names' => $names]),
            self::BlockingAdded => __('now blocks :names', ['names' => $names]),
            self::BlockingRemoved => __('no longer blocks :names', ['names' => $names]),
        };
    }
}
